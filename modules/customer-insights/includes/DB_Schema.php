<?php
/**
 * Esquema de las tablas propias del módulo. Se recalculan por completo
 * (TRUNCATE + reinsertar) en cada corrida del cron o del botón "Recalcular
 * ahora" — son una caché de lectura, no se editan a mano.
 *
 * @package MAD_Suite/Customer_Insights
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_CI_DB_Schema {

    // Bump para disparar dbDelta si cambia el esquema.
    private const TABLE_VERSION = '1.2';
    private const VERSION_OPTION = 'mad_ci_table_version';

    public static function table_customers(): string {
        global $wpdb;
        return $wpdb->prefix . 'mad_ci_customers';
    }

    public static function table_monthly(): string {
        global $wpdb;
        return $wpdb->prefix . 'mad_ci_monthly';
    }

    public static function table_cohorts(): string {
        global $wpdb;
        return $wpdb->prefix . 'mad_ci_cohorts';
    }

    public static function table_product_repurchase(): string {
        global $wpdb;
        return $wpdb->prefix . 'mad_ci_product_repurchase';
    }

    /** Tabla intermedia (scratch): datos planos por pedido, escritos en lotes pequeños antes de agregarlos. */
    public static function table_raw_orders(): string {
        global $wpdb;
        return $wpdb->prefix . 'mad_ci_raw_orders';
    }

    public static function maybe_create_tables(): void {
        if ( get_option( self::VERSION_OPTION ) === self::TABLE_VERSION ) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $tables = [
            self::table_customers() => "CREATE TABLE " . self::table_customers() . " (
                id                        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                customer_email            varchar(191)  NOT NULL,
                user_id                   bigint(20) unsigned NOT NULL DEFAULT 0,
                is_professional           tinyint(1)    NOT NULL DEFAULT 0,
                first_order_date          datetime      NOT NULL,
                last_order_date           datetime      NOT NULL,
                order_count               int unsigned  NOT NULL DEFAULT 0,
                lifetime_value            decimal(15,2) NOT NULL DEFAULT 0,
                avg_order_value           decimal(15,2) NOT NULL DEFAULT 0,
                avg_days_between_orders   decimal(10,2) DEFAULT NULL,
                days_to_second_order      decimal(10,2) DEFAULT NULL,
                rfm_recency_score         tinyint unsigned NOT NULL DEFAULT 0,
                rfm_frequency_score       tinyint unsigned NOT NULL DEFAULT 0,
                rfm_monetary_score        tinyint unsigned NOT NULL DEFAULT 0,
                rfm_segment               varchar(30)   NOT NULL DEFAULT '',
                updated_at                datetime      NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY   customer_email (customer_email(191)),
                KEY first_order_date (first_order_date),
                KEY is_professional (is_professional)
            ) $charset;",

            self::table_monthly() => "CREATE TABLE " . self::table_monthly() . " (
                id                          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                month                       date          NOT NULL,
                segment                     varchar(20)   NOT NULL DEFAULT 'all',
                new_customers               int unsigned  NOT NULL DEFAULT 0,
                repeat_customers            int unsigned  NOT NULL DEFAULT 0,
                new_revenue                 decimal(15,2) NOT NULL DEFAULT 0,
                repeat_revenue              decimal(15,2) NOT NULL DEFAULT 0,
                avg_login_to_order_seconds  bigint unsigned DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY   month_segment (month, segment)
            ) $charset;",

            self::table_cohorts() => "CREATE TABLE " . self::table_cohorts() . " (
                id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                cohort_month   date          NOT NULL,
                period_offset  smallint unsigned NOT NULL,
                segment        varchar(20)   NOT NULL DEFAULT 'all',
                cohort_size    int unsigned  NOT NULL DEFAULT 0,
                retained_count int unsigned  NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY   cohort_period_segment (cohort_month, period_offset, segment)
            ) $charset;",

            self::table_product_repurchase() => "CREATE TABLE " . self::table_product_repurchase() . " (
                id                   bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                product_id           bigint(20) unsigned NOT NULL,
                segment              varchar(20)   NOT NULL DEFAULT 'all',
                buyers_count         int unsigned  NOT NULL DEFAULT 0,
                repeat_buyers_count  int unsigned  NOT NULL DEFAULT 0,
                repeat_orders_count  int unsigned  NOT NULL DEFAULT 0,
                repurchase_rate      decimal(5,2)  NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY   product_segment (product_id, segment)
            ) $charset;",

            self::table_raw_orders() => "CREATE TABLE " . self::table_raw_orders() . " (
                order_id               bigint(20) unsigned NOT NULL,
                customer_email         varchar(191)  NOT NULL,
                user_id                bigint(20) unsigned NOT NULL DEFAULT 0,
                order_date              datetime     NOT NULL,
                total                  decimal(15,2) NOT NULL DEFAULT 0,
                product_ids            longtext      NOT NULL,
                login_to_order_seconds bigint unsigned DEFAULT NULL,
                PRIMARY KEY  (order_id),
                KEY customer_email (customer_email(191))
            ) $charset;",
        ];

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        // dbDelta() no avisa si una tabla concreta falló (lo que pasó acá: un
        // prefijo de índice de 191 sobre una columna varchar(190) hizo que
        // MySQL rechazara el CREATE TABLE, en silencio). Se verifica cada
        // tabla y, si falta alguna, no se marca la versión como aplicada —
        // así se reintenta solo en la próxima carga en vez de quedar
        // "asumida como lista" para siempre.
        $missing = [];
        foreach ( array_keys( $tables ) as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                $missing[] = $table;
            }
        }

        if ( ! empty( $missing ) ) {
            error_log( '[MAD Customer Insights] No se pudieron crear estas tablas: ' . implode( ', ', $missing ) . ( $wpdb->last_error ? ' — último error SQL: ' . $wpdb->last_error : '' ) );
            return;
        }

        update_option( self::VERSION_OPTION, self::TABLE_VERSION );
    }
}
