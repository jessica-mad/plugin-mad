<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_DBM_AuditLogger {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'mad_dbm_audit';
    }

    public function create_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            user_login varchar(60) NOT NULL DEFAULT '',
            ip_address varchar(45) NOT NULL DEFAULT '',
            table_name varchar(255) NOT NULL DEFAULT '',
            action varchar(50) NOT NULL DEFAULT '',
            details text DEFAULT NULL,
            result varchar(20) NOT NULL DEFAULT 'success',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY table_name (table_name(100))
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function log( string $table_name, string $action, string $details = '', string $result = 'success' ): void {
        global $wpdb;
        $user    = wp_get_current_user();
        $user_id = $user ? $user->ID : 0;
        $login   = $user ? $user->user_login : 'system';
        $ip      = $this->get_ip();

        $wpdb->insert( $this->table, [
            'user_id'    => $user_id,
            'user_login' => $login,
            'ip_address' => $ip,
            'table_name' => $table_name,
            'action'     => $action,
            'details'    => $details,
            'result'     => $result,
            'created_at' => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
    }

    public function get_logs( int $per_page = 50, int $page = 1 ): array {
        global $wpdb;
        $offset = ( $page - 1 ) * $per_page;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A ) ?: [];
    }

    public function get_total(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
    }

    private function get_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                $ip = explode( ',', $ip )[0];
                return trim( $ip );
            }
        }
        return '0.0.0.0';
    }
}
