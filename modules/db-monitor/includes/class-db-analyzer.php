<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_DBM_Analyzer {

    private array $thresholds;

    public function __construct( array $thresholds = [] ) {
        $this->thresholds = wp_parse_args( $thresholds, [
            'size_mb'   => 50,
            'row_count' => 100000,
        ] );
    }

    /**
     * Tables that can NEVER be exported or imported — contain user credentials and identity data.
     * This restriction is absolute and cannot be overridden by any admin action.
     */
    private function get_never_exportable_suffixes(): array {
        return [ 'users', 'usermeta' ];
    }

    /** Full table names that can never be exported or imported (with DB prefix). */
    public function get_never_exportable_table_names(): array {
        global $wpdb;
        return array_map( fn( $s ) => $wpdb->prefix . $s, $this->get_never_exportable_suffixes() );
    }

    /**
     * Returns false for tables containing user credentials — these can NEVER be exported or imported.
     */
    public function is_table_exportable( string $table ): bool {
        return ! in_array( $table, $this->get_never_exportable_table_names(), true );
    }

    /** Tables that can NEVER be truncated or deleted from this panel */
    private function get_protected_suffixes(): array {
        return [
            'users', 'usermeta', 'options', 'posts', 'postmeta',
            'comments', 'commentmeta', 'terms', 'term_taxonomy',
            'term_relationships', 'termmeta', 'links',
            'woocommerce_order_items', 'woocommerce_order_itemmeta',
            'wc_orders', 'wc_order_addresses', 'wc_order_operational_data',
            'wc_orders_meta',
            'woocommerce_tax_rates', 'woocommerce_tax_rate_locations',
            'woocommerce_shipping_zones', 'woocommerce_shipping_zone_locations',
            'woocommerce_shipping_zone_methods',
            'mad_dbm_audit', 'mad_dbm_exports',
        ];
    }

    /**
     * Cleanable table whitelist. key = full table name (with prefix).
     */
    public function get_cleanable_config(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return [
            $p . 'woocommerce_sessions' => [
                'label'        => 'WooCommerce Sessions',
                'description'  => 'Sesiones activas de WooCommerce. Las antiguas pueden eliminarse.',
                'date_column'  => 'session_expiry',
                'date_type'    => 'unix',
                'actions'      => [ 'clean_old', 'truncate' ],
                'default_days' => 30,
                'action_label' => 'Sesiones antiguas',
            ],
            $p . 'actionscheduler_logs' => [
                'label'        => 'Action Scheduler Logs',
                'description'  => 'Logs del planificador de tareas.',
                'date_column'  => 'log_date_gmt',
                'date_type'    => 'datetime',
                'actions'      => [ 'clean_old', 'truncate' ],
                'default_days' => 30,
                'action_label' => 'Logs antiguos',
            ],
            $p . 'actionscheduler_actions' => [
                'label'        => 'Action Scheduler Actions',
                'description'  => 'Solo se eliminan acciones completadas/fallidas/canceladas.',
                'date_column'  => 'scheduled_date_gmt',
                'date_type'    => 'datetime',
                'actions'      => [ 'clean_old_completed' ],
                'default_days' => 30,
                'status_filter'=> [ 'complete', 'failed', 'canceled' ],
                'action_label' => 'Acciones completadas/fallidas',
            ],
            $p . 'wc_log' => [
                'label'        => 'WooCommerce Log',
                'description'  => 'Registro de eventos de WooCommerce.',
                'date_column'  => 'timestamp',
                'date_type'    => 'datetime',
                'actions'      => [ 'clean_old', 'truncate' ],
                'default_days' => 30,
                'action_label' => 'Logs antiguos',
            ],
            $p . 'woocommerce_log' => [
                'label'        => 'WooCommerce Log (alt)',
                'description'  => 'Registro de eventos de WooCommerce.',
                'date_column'  => 'timestamp',
                'date_type'    => 'datetime',
                'actions'      => [ 'clean_old', 'truncate' ],
                'default_days' => 30,
                'action_label' => 'Logs antiguos',
            ],
            $p . 'cartbounty' => [
                'label'        => 'CartBounty',
                'description'  => 'Carritos abandonados registrados por CartBounty.',
                'date_column'  => 'time',
                'date_type'    => 'datetime',
                'actions'      => [ 'clean_old', 'truncate' ],
                'default_days' => 60,
                'action_label' => 'Carritos antiguos',
            ],
            $p . 'cartbounty_pro' => [
                'label'        => 'CartBounty Pro',
                'description'  => 'Carritos abandonados registrados por CartBounty Pro.',
                'date_column'  => 'time',
                'date_type'    => 'datetime',
                'actions'      => [ 'clean_old', 'truncate' ],
                'default_days' => 60,
                'action_label' => 'Carritos antiguos',
            ],
        ];
    }

    /** Returns all tables sorted by size desc, with computed flags. */
    public function get_all_tables(): array {
        global $wpdb;

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
            DB_NAME
        ), ARRAY_A );

        if ( ! $rows ) return [];

        $protected  = $this->get_protected_table_names();
        $cleanable  = $this->get_cleanable_config();
        $suspicious = $this->get_suspicious_patterns();

        $tables = [];
        foreach ( $rows as $row ) {
            $name      = $row['name'];
            $total_mb  = round( $row['total_bytes'] / 1048576, 3 );
            $data_mb   = round( $row['data_bytes'] / 1048576, 3 );
            $index_mb  = round( $row['index_bytes'] / 1048576, 3 );
            $row_count = (int) $row['rows'];

            $is_protected = in_array( $name, $protected, true );
            $is_cleanable = isset( $cleanable[ $name ] );
            $flags        = $this->detect_suspicious( $name, $row_count, $total_mb, $suspicious );

            $tables[] = [
                'name'          => $name,
                'rows'          => $row_count,
                'total_mb'      => $total_mb,
                'data_mb'       => $data_mb,
                'index_mb'      => $index_mb,
                'engine'        => $row['engine'],
                'updated_at'    => $row['updated_at'],
                'is_protected'  => $is_protected,
                'is_cleanable'  => $is_cleanable,
                'suspect_flags' => $flags,
                'is_suspicious' => ! empty( $flags ),
            ];
        }

        return $tables;
    }

    /**
     * Calculate summary from an already-loaded tables array (avoids double query).
     * If $tables is empty, queries fresh data.
     */
    public function get_database_summary( array $tables = [] ): array {
        if ( empty( $tables ) ) {
            $tables = $this->get_all_tables();
        }

        $total_mb   = array_sum( array_column( $tables, 'total_mb' ) );
        $suspicious = array_filter( $tables, fn( $t ) => $t['is_suspicious'] );
        $heaviest   = $tables[0] ?? null;

        return [
            'table_count'      => count( $tables ),
            'total_mb'         => round( $total_mb, 2 ),
            'heaviest_table'   => $heaviest ? $heaviest['name'] : '—',
            'heaviest_mb'      => $heaviest ? $heaviest['total_mb'] : 0,
            'suspicious_count' => count( $suspicious ),
        ];
    }

    public function is_table_protected( string $table ): bool {
        return in_array( $table, $this->get_protected_table_names(), true );
    }

    public function is_table_cleanable( string $table ): bool {
        return isset( $this->get_cleanable_config()[ $table ] );
    }

    public function get_cleanable_table_config( string $table ): ?array {
        return $this->get_cleanable_config()[ $table ] ?? null;
    }

    /** Validates table actually exists in DB (prevents injection via crafted names). */
    public function table_exists( string $table ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ) ) > 0;
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function get_protected_table_names(): array {
        global $wpdb;
        return array_map( fn( $s ) => $wpdb->prefix . $s, $this->get_protected_suffixes() );
    }

    private function get_suspicious_patterns(): array {
        return [
            'size_mb'       => $this->thresholds['size_mb'],
            'row_count'     => $this->thresholds['row_count'],
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
                $flags[] = sprintf( 'Patrón: %s', esc_html( $pat ) );
                break;
            }
        }
        return $flags;
    }
}
