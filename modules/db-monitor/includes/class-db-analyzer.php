<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_DBM_Analyzer {

    /** Tables that can NEVER be truncated or deleted from this panel */
    private function get_protected_suffixes(): array {
        return [
            'users', 'usermeta', 'options', 'posts', 'postmeta',
            'comments', 'commentmeta', 'terms', 'term_taxonomy',
            'term_relationships', 'termmeta', 'links',
            // WooCommerce orders (classic + HPOS)
            'woocommerce_order_items', 'woocommerce_order_itemmeta',
            'wc_orders', 'wc_order_addresses', 'wc_order_operational_data',
            'wc_orders_meta',
            // WooCommerce tax / shipping
            'woocommerce_tax_rates', 'woocommerce_tax_rate_locations',
            'woocommerce_shipping_zones', 'woocommerce_shipping_zone_locations',
            'woocommerce_shipping_zone_methods',
            // Internal module tables
            'mad_dbm_audit', 'mad_dbm_exports',
        ];
    }

    /**
     * Cleanable table whitelist: key = suffix after prefix, value = config array.
     */
    public function get_cleanable_config(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return [
            $p . 'woocommerce_sessions' => [
                'label'       => 'WooCommerce Sessions',
                'description' => 'Sesiones activas de WooCommerce. Las antiguas pueden eliminarse.',
                'date_column' => 'session_expiry',
                'date_type'   => 'unix',
                'actions'     => [ 'clean_old', 'truncate' ],
                'default_days' => 30,
            ],
            $p . 'actionscheduler_logs' => [
                'label'       => 'Action Scheduler Logs',
                'description' => 'Logs del planificador de tareas. Crecen rápido en tiendas activas.',
                'date_column' => 'log_date_gmt',
                'date_type'   => 'datetime',
                'actions'     => [ 'clean_old', 'truncate' ],
                'default_days' => 30,
            ],
            $p . 'actionscheduler_actions' => [
                'label'       => 'Action Scheduler Actions',
                'description' => 'Acciones completadas/fallidas del planificador. Las históricas pueden limpiarse.',
                'date_column' => 'scheduled_date_gmt',
                'date_type'   => 'datetime',
                'actions'     => [ 'clean_old_completed' ],
                'default_days' => 30,
                'status_filter' => [ 'complete', 'failed', 'canceled' ],
            ],
            $p . 'wc_log' => [
                'label'       => 'WooCommerce Log (wc_log)',
                'description' => 'Registro de eventos de WooCommerce.',
                'date_column' => 'timestamp',
                'date_type'   => 'datetime',
                'actions'     => [ 'clean_old', 'truncate' ],
                'default_days' => 30,
            ],
            $p . 'woocommerce_log' => [
                'label'       => 'WooCommerce Log (woocommerce_log)',
                'description' => 'Registro de eventos de WooCommerce (nombre alternativo).',
                'date_column' => 'timestamp',
                'date_type'   => 'datetime',
                'actions'     => [ 'clean_old', 'truncate' ],
                'default_days' => 30,
            ],
            $p . 'cartbounty' => [
                'label'       => 'CartBounty',
                'description' => 'Carritos abandonados registrados por CartBounty.',
                'date_column' => 'time',
                'date_type'   => 'datetime',
                'actions'     => [ 'clean_old', 'truncate' ],
                'default_days' => 60,
            ],
            $p . 'cartbounty_pro' => [
                'label'       => 'CartBounty Pro',
                'description' => 'Carritos abandonados registrados por CartBounty Pro.',
                'date_column' => 'time',
                'date_type'   => 'datetime',
                'actions'     => [ 'clean_old', 'truncate' ],
                'default_days' => 60,
            ],
        ];
    }

    /** Returns all existing tables in the database with size/row info */
    public function get_all_tables(): array {
        global $wpdb;

        $db_name = DB_NAME;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                TABLE_NAME        AS name,
                TABLE_ROWS        AS rows,
                DATA_LENGTH       AS data_bytes,
                INDEX_LENGTH      AS index_bytes,
                (DATA_LENGTH + INDEX_LENGTH) AS total_bytes,
                ENGINE            AS engine,
                UPDATE_TIME       AS updated_at
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = %s
             ORDER BY total_bytes DESC",
            $db_name
        ), ARRAY_A );

        if ( ! $rows ) return [];

        $protected   = $this->get_protected_table_names();
        $cleanable   = $this->get_cleanable_config();
        $suspicious  = $this->get_suspicious_patterns();

        $tables = [];
        foreach ( $rows as $row ) {
            $name       = $row['name'];
            $total_mb   = round( $row['total_bytes'] / 1048576, 3 );
            $data_mb    = round( $row['data_bytes'] / 1048576, 3 );
            $index_mb   = round( $row['index_bytes'] / 1048576, 3 );
            $row_count  = (int) $row['rows'];

            $is_protected  = in_array( $name, $protected, true );
            $is_cleanable  = isset( $cleanable[ $name ] );
            $suspect_flags = $this->detect_suspicious( $name, $row_count, $total_mb, $suspicious );

            $tables[] = [
                'name'         => $name,
                'rows'         => $row_count,
                'total_mb'     => $total_mb,
                'data_mb'      => $data_mb,
                'index_mb'     => $index_mb,
                'engine'       => $row['engine'],
                'updated_at'   => $row['updated_at'],
                'is_protected' => $is_protected,
                'is_cleanable' => $is_cleanable,
                'suspect_flags'=> $suspect_flags,
                'is_suspicious'=> ! empty( $suspect_flags ),
            ];
        }

        return $tables;
    }

    public function get_database_summary(): array {
        $tables     = $this->get_all_tables();
        $total_mb   = array_sum( array_column( $tables, 'total_mb' ) );
        $suspicious = array_filter( $tables, fn( $t ) => $t['is_suspicious'] );
        $heaviest   = $tables[0] ?? null;

        return [
            'table_count'     => count( $tables ),
            'total_mb'        => round( $total_mb, 2 ),
            'heaviest_table'  => $heaviest ? $heaviest['name'] : '—',
            'heaviest_mb'     => $heaviest ? $heaviest['total_mb'] : 0,
            'suspicious_count'=> count( $suspicious ),
        ];
    }

    public function is_table_protected( string $table ): bool {
        return in_array( $table, $this->get_protected_table_names(), true );
    }

    public function is_table_cleanable( string $table ): bool {
        $config = $this->get_cleanable_config();
        return isset( $config[ $table ] );
    }

    public function get_cleanable_table_config( string $table ): ?array {
        $config = $this->get_cleanable_config();
        return $config[ $table ] ?? null;
    }

    /** Validate table exists in DB (prevent injection via crafted names) */
    public function table_exists( string $table ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ) );
        return (int) $result > 0;
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function get_protected_table_names(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return array_map( fn( $s ) => $p . $s, $this->get_protected_suffixes() );
    }

    private function get_suspicious_patterns(): array {
        return [
            'size_mb'    => 50,   // tables over 50 MB
            'row_count'  => 100000, // tables with 100k+ rows
            'name_patterns' => [
                'actionscheduler_logs', 'actionscheduler_actions',
                'woocommerce_sessions', 'wc_sessions',
                'cartbounty', 'cartbounty_pro',
                '_log', '_logs', '_tracking', '_analytics',
                '_stats', '_cache', '_tmp', '_temp',
            ],
        ];
    }

    private function detect_suspicious( string $name, int $rows, float $mb, array $patterns ): array {
        $flags = [];
        if ( $mb >= $patterns['size_mb'] ) {
            $flags[] = sprintf( 'Tamaño elevado (%.1f MB)', $mb );
        }
        if ( $rows >= $patterns['row_count'] ) {
            $flags[] = sprintf( 'Muchos registros (%s)', number_format( $rows ) );
        }
        foreach ( $patterns['name_patterns'] as $pat ) {
            if ( str_contains( $name, $pat ) ) {
                $flags[] = sprintf( 'Nombre sospechoso (%s)', esc_html( $pat ) );
                break;
            }
        }
        return $flags;
    }
}
