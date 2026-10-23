<?php
/**
 * Capa de lectura para el dashboard — consultas de solo lectura contra
 * las tablas ya precalculadas (nunca contra wc_get_orders() en vivo).
 *
 * @package MAD_Suite/Customer_Insights
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_CI_Dashboard_Data {

    /**
     * Definición de cada segmento RFM — debe coincidir con las reglas de
     * MAD_CI_Stats_Calculator::rfm_segment_label(). Se usa para mostrar la
     * explicación de cada uno en el dashboard.
     */
    public static function rfm_segment_definitions(): array {
        return [
            __( 'Campeones', 'mad-suite' ) => __( 'Compraron hace poco (recencia alta) y compran seguido (frecuencia alta). Los mejores clientes — vale la pena cuidarlos, no recuperarlos.', 'mad-suite' ),
            __( 'Nuevos prometedores', 'mad-suite' ) => __( 'Compraron hace poco pero todavía no acumulan muchos pedidos. Candidatos a fidelizar antes de que se enfríen.', 'mad-suite' ),
            __( 'Regulares', 'mad-suite' ) => __( 'Ni recencia ni frecuencia están en los extremos — compran con cierta normalidad, sin destacar ni preocupar.', 'mad-suite' ),
            __( 'En riesgo', 'mad-suite' ) => __( 'Antes compraban seguido (frecuencia alta) pero hace tiempo que no vuelven (recencia baja). Todavía recuperables — son los primeros candidatos a una campaña de reactivación.', 'mad-suite' ),
            __( 'Perdidos', 'mad-suite' ) => __( 'Ni compran seguido ni lo hacen hace poco — recencia y frecuencia bajas. Los más fríos: recuperarlos cuesta más, pero es la lista para una campaña de "te echamos de menos".', 'mad-suite' ),
        ];
    }

    /**
     * KPIs de cliente — son promedios/totales históricos (de toda la vida
     * del cliente), no acotados al rango de fechas del dashboard: la tasa
     * de recompra, el CLV o la frecuencia de un cliente no cambian según
     * qué mes estés mirando.
     */
    public function get_customer_kpis(): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();

        $row = $wpdb->get_row( "
            SELECT
                COUNT(*)                                        AS total_customers,
                SUM(CASE WHEN order_count > 1 THEN 1 ELSE 0 END) AS repeat_customers,
                AVG(lifetime_value)                              AS avg_ltv,
                AVG(avg_order_value)                             AS avg_aov,
                AVG(avg_days_between_orders)                     AS avg_frequency_days,
                AVG(days_to_second_order)                        AS avg_days_to_second_order
            FROM {$table}
        ", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $total  = (int) ( $row['total_customers'] ?? 0 );
        $repeat = (int) ( $row['repeat_customers'] ?? 0 );

        return [
            'total_customers'          => $total,
            'repeat_customers'         => $repeat,
            'repeat_rate'              => $total > 0 ? round( $repeat / $total * 100, 1 ) : 0.0,
            'avg_ltv'                  => round( (float) ( $row['avg_ltv'] ?? 0 ), 2 ),
            'avg_aov'                  => round( (float) ( $row['avg_aov'] ?? 0 ), 2 ),
            'avg_frequency_days'       => null !== $row['avg_frequency_days'] ? round( (float) $row['avg_frequency_days'], 1 ) : null,
            'avg_days_to_second_order' => null !== $row['avg_days_to_second_order'] ? round( (float) $row['avg_days_to_second_order'], 1 ) : null,
        ];
    }

    /** Promedio de login→compra, ponderado por nº de pedidos, dentro del rango de fechas. */
    public function get_login_to_purchase_avg( string $from, string $to ): ?float {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_monthly();

        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT
                SUM(avg_login_to_order_seconds * (new_customers + repeat_customers)) AS weighted_sum,
                SUM(CASE WHEN avg_login_to_order_seconds IS NOT NULL THEN (new_customers + repeat_customers) ELSE 0 END) AS weight
            FROM {$table}
            WHERE month BETWEEN %s AND %s
        ", $from, $to ), ARRAY_A );

        if ( empty( $row['weight'] ) ) return null;
        return round( ( (float) $row['weighted_sum'] ) / ( (int) $row['weight'] ) / DAY_IN_SECONDS, 2 ); // en días
    }

    /** Serie mensual (nuevos vs. recurrentes) dentro del rango de fechas. */
    public function get_monthly_series( string $from, string $to ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_monthly();

        return $wpdb->get_results( $wpdb->prepare( "
            SELECT month, new_customers, repeat_customers, new_revenue, repeat_revenue
            FROM {$table}
            WHERE month BETWEEN %s AND %s
            ORDER BY month ASC
        ", $from, $to ), ARRAY_A );
    }

    /**
     * Igual que get_monthly_series() pero agrupada en bloques más grandes
     * (trimestre, semestre, año) para poder comparar muchos años sin que la
     * gráfica se vuelva ilegible. La tabla base sigue siendo mensual — esto
     * solo suma meses dentro de cada bloque, no cambia nada en el cálculo.
     *
     * @param string $granularity 'month' | 'quarter' | 'semester' | 'year'.
     * @return array<int, array{period:string, label:string, new_customers:int, repeat_customers:int, new_revenue:float, repeat_revenue:float}>
     */
    public function get_period_series( string $from, string $to, string $granularity ): array {
        $monthly = $this->get_monthly_series( $from, $to );
        return self::bucket_by_granularity( $monthly, $granularity );
    }

    /** @param array<int,array<string,mixed>> $monthly_rows Como los que devuelve get_monthly_series(). */
    public static function bucket_by_granularity( array $monthly_rows, string $granularity ): array {
        if ( 'month' === $granularity ) {
            return array_map( static function ( $row ) {
                $ts = strtotime( $row['month'] );
                return [
                    'period'            => substr( $row['month'], 0, 7 ),
                    'label'             => wp_date( 'M Y', $ts ),
                    'new_customers'     => (int) $row['new_customers'],
                    'repeat_customers'  => (int) $row['repeat_customers'],
                    'new_revenue'       => (float) $row['new_revenue'],
                    'repeat_revenue'    => (float) $row['repeat_revenue'],
                ];
            }, $monthly_rows );
        }

        $buckets = [];
        foreach ( $monthly_rows as $row ) {
            [ $y, $m ] = array_map( 'intval', explode( '-', substr( $row['month'], 0, 7 ) ) );
            $key = self::period_key( $y, $m, $granularity );

            if ( ! isset( $buckets[ $key ] ) ) {
                $buckets[ $key ] = [
                    'period'           => $key,
                    'label'            => self::period_label( $y, $m, $granularity ),
                    'new_customers'    => 0,
                    'repeat_customers' => 0,
                    'new_revenue'      => 0.0,
                    'repeat_revenue'   => 0.0,
                ];
            }

            $buckets[ $key ]['new_customers']    += (int) $row['new_customers'];
            $buckets[ $key ]['repeat_customers'] += (int) $row['repeat_customers'];
            $buckets[ $key ]['new_revenue']      += (float) $row['new_revenue'];
            $buckets[ $key ]['repeat_revenue']   += (float) $row['repeat_revenue'];
        }

        ksort( $buckets );
        return array_values( $buckets );
    }

    private static function period_key( int $y, int $m, string $granularity ): string {
        switch ( $granularity ) {
            case 'quarter':  return sprintf( '%d-Q%d', $y, intdiv( $m - 1, 3 ) + 1 );
            case 'semester': return sprintf( '%d-S%d', $y, intdiv( $m - 1, 6 ) + 1 );
            case 'year':     return (string) $y;
            default:         return sprintf( '%04d-%02d', $y, $m );
        }
    }

    private static function period_label( int $y, int $m, string $granularity ): string {
        switch ( $granularity ) {
            /* translators: 1: número de trimestre (1-4), 2: año */
            case 'quarter':  return sprintf( __( 'T%1$d %2$d', 'mad-suite' ), intdiv( $m - 1, 3 ) + 1, $y );
            /* translators: 1: número de semestre (1-2), 2: año */
            case 'semester': return sprintf( __( 'S%1$d %2$d', 'mad-suite' ), intdiv( $m - 1, 6 ) + 1, $y );
            case 'year':     return (string) $y;
            default:         return wp_date( 'M Y', mktime( 0, 0, 0, $m, 1, $y ) );
        }
    }

    /**
     * Grid de retención por cohortes: filas = mes de cohorte, columnas =
     * meses transcurridos desde la primera compra (0, 1, 2…).
     *
     * @return array{cohorts: array<string,array>, max_period: int}
     */
    public function get_cohort_grid( int $max_cohorts = 12, int $max_periods = 6 ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_cohorts();

        $cohort_months = $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT cohort_month FROM {$table}
            ORDER BY cohort_month DESC
            LIMIT %d
        ", $max_cohorts ) );

        if ( empty( $cohort_months ) ) return [ 'cohorts' => [], 'max_period' => 0 ];

        $placeholders = implode( ',', array_fill( 0, count( $cohort_months ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT cohort_month, period_offset, cohort_size, retained_count
            FROM {$table}
            WHERE period_offset <= %d AND cohort_month IN ({$placeholders})
            ORDER BY cohort_month ASC, period_offset ASC
        ", array_merge( [ $max_periods ], $cohort_months ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $cohorts = [];
        foreach ( $rows as $row ) {
            $month = $row['cohort_month'];
            if ( ! isset( $cohorts[ $month ] ) ) {
                $cohorts[ $month ] = [ 'size' => (int) $row['cohort_size'], 'periods' => [] ];
            }
            $size = (int) $row['cohort_size'];
            $cohorts[ $month ]['periods'][ (int) $row['period_offset'] ] = [
                'retained' => (int) $row['retained_count'],
                'pct'      => $size > 0 ? round( $row['retained_count'] / $size * 100, 1 ) : 0.0,
            ];
        }
        ksort( $cohorts );

        return [ 'cohorts' => $cohorts, 'max_period' => $max_periods ];
    }

    /** @return array<string,int> rfm_segment => nº de clientes. */
    public function get_rfm_breakdown(): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();

        $rows = $wpdb->get_results( "
            SELECT rfm_segment, COUNT(*) AS total
            FROM {$table}
            GROUP BY rfm_segment
            ORDER BY total DESC
        ", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $out = [];
        foreach ( $rows as $row ) {
            $out[ $row['rfm_segment'] ] = (int) $row['total'];
        }
        return $out;
    }

    /**
     * Listado completo de clientes únicos (uno por email), opcionalmente
     * filtrado por segmento RFM — es la tabla "aparte" para ver quién
     * compró, cuándo y cuánto vale, con enlace a sus pedidos reales en
     * WooCommerce (ahí se ve qué compró, con todo el detalle de cada
     * pedido — no se duplica esa información en una tabla propia).
     */
    public function get_customers_list( string $segment = '', int $limit = 300 ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();

        if ( '' !== $segment ) {
            return $wpdb->get_results( $wpdb->prepare( "
                SELECT customer_email, user_id, order_count, lifetime_value, avg_order_value,
                       first_order_date, last_order_date, rfm_segment
                FROM {$table}
                WHERE rfm_segment = %s
                ORDER BY lifetime_value DESC
                LIMIT %d
            ", $segment, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        return $wpdb->get_results( $wpdb->prepare( "
            SELECT customer_email, user_id, order_count, lifetime_value, avg_order_value,
                   first_order_date, last_order_date, rfm_segment
            FROM {$table}
            ORDER BY lifetime_value DESC
            LIMIT %d
        ", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Cuántos clientes únicos hay en total, sin el límite de get_customers_list(). */
    public function count_customers( string $segment = '' ): int {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();

        if ( '' !== $segment ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE rfm_segment = %s", $segment ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * URL al listado de pedidos de WooCommerce filtrado por email de un
     * cliente (ahí se ve, con todo el detalle, cuándo compró y qué compró
     * en cada pedido) — compatible con HPOS y con el CPT clásico.
     */
    public static function orders_list_url( string $email ): string {
        $search = rawurlencode( $email );
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            return admin_url( 'admin.php?page=wc-orders&s=' . $search );
        }
        return admin_url( 'edit.php?post_type=shop_order&s=' . $search );
    }

    /** Clientes con 2+ pedidos cuya ausencia ya duplica su propio intervalo habitual de compra. */
    public function get_at_risk_customers( int $limit = 20 ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();

        return $wpdb->get_results( $wpdb->prepare( "
            SELECT customer_email, user_id, order_count, lifetime_value, last_order_date,
                   avg_days_between_orders,
                   DATEDIFF(NOW(), last_order_date) AS days_since_last_order
            FROM {$table}
            WHERE order_count > 1
              AND avg_days_between_orders IS NOT NULL
              AND DATEDIFF(NOW(), last_order_date) > (avg_days_between_orders * 2)
            ORDER BY lifetime_value DESC
            LIMIT %d
        ", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Productos con mayor tasa de recompra (con un mínimo de compradores
     * para que no sea ruido). Para cada producto:
     * - buyers_count: cuántos clientes distintos lo compraron alguna vez.
     * - repeat_buyers_count: de esos, cuántos lo compraron en 2+ pedidos distintos.
     * - repurchase_rate: % de compradores que repitieron (repeat_buyers_count / buyers_count).
     * - total_purchases: nº total de veces que se compró (compradores únicos + sus recompras).
     * - avg_purchases_per_buyer: en promedio, cuántas veces lo compra quien lo compra.
     * - share_repeat_purchases: % de todas esas compras que fueron una recompra (no la primera vez).
     */
    public function get_top_repurchased_products( int $limit = 15, int $min_buyers = 5 ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_product_repurchase();

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT product_id, buyers_count, repeat_buyers_count, repeat_orders_count, repurchase_rate
            FROM {$table}
            WHERE buyers_count >= %d
            ORDER BY repurchase_rate DESC, buyers_count DESC
            LIMIT %d
        ", $min_buyers, $limit ), ARRAY_A );

        foreach ( $rows as &$row ) {
            $row['product_name']    = get_the_title( $row['product_id'] ) ?: ( '#' . $row['product_id'] );
            $total_purchases        = (int) $row['buyers_count'] + (int) $row['repeat_orders_count'];
            $row['total_purchases'] = $total_purchases;
            $row['avg_purchases_per_buyer'] = $row['buyers_count'] > 0
                ? round( $total_purchases / $row['buyers_count'], 2 )
                : 0.0;
            $row['share_repeat_purchases'] = $total_purchases > 0
                ? round( $row['repeat_orders_count'] / $total_purchases * 100, 1 )
                : 0.0;
        }
        unset( $row );

        return $rows;
    }

    public function get_last_recalculated(): ?int {
        $ts = get_option( 'mad_ci_last_recalculated' );
        return $ts ? (int) $ts : null;
    }
}
