<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_DBM_ExportManager {

    private string $exports_table;
    private MAD_DBM_AuditLogger $audit;
    private const TOKEN_TTL = 300; // 5 minutes

    public function __construct( MAD_DBM_AuditLogger $audit ) {
        global $wpdb;
        $this->exports_table = $wpdb->prefix . 'mad_dbm_exports';
        $this->audit         = $audit;
    }

    public function create_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->exports_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            table_name varchar(255) NOT NULL DEFAULT '',
            file_name varchar(255) NOT NULL DEFAULT '',
            file_path varchar(500) NOT NULL DEFAULT '',
            file_size bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            action_type varchar(50) NOT NULL DEFAULT 'manual',
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'available',
            created_at datetime NOT NULL,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY table_name (table_name(100)),
            KEY expires_at (expires_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ── Export ────────────────────────────────────────────────────────────────

    /**
     * Export a table to .sql.gz using streaming writes (memory-safe for large tables).
     * Returns export record array on success, WP_Error on failure.
     *
     * @param string $table          Full table name (with prefix).
     * @param string $action_type    'manual' | 'auto_before_clean' | etc.
     * @param int    $retention_days Days to keep the file before cron removes it.
     */
    public function export_table( string $table, string $action_type = 'manual', int $retention_days = 30 ) {
        $export_dir = $this->get_export_dir();
        if ( is_wp_error( $export_dir ) ) return $export_dir;

        $this->ensure_dir_protected( $export_dir );

        $user    = wp_get_current_user();
        $user_id = $user ? $user->ID : 0;
        $login   = $user ? $user->user_login : 'system';

        $safe_table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
        $timestamp  = current_time( 'YmdHis' );
        $file_name  = "dbm_{$safe_table}_{$timestamp}.sql.gz";
        $file_path  = trailingslashit( $export_dir ) . $file_name;

        // Open gz file for streaming — no large string built in memory
        $gz = gzopen( $file_path, 'wb9' );
        if ( ! $gz ) {
            return new WP_Error( 'gz_open_failed', 'No se pudo crear el archivo comprimido.' );
        }

        $ok = $this->write_sql_to_gz( $gz, $table, $action_type, $login, $user_id );
        gzclose( $gz );

        if ( is_wp_error( $ok ) ) {
            @unlink( $file_path );
            return $ok;
        }

        if ( ! file_exists( $file_path ) || filesize( $file_path ) === 0 ) {
            return new WP_Error( 'export_empty', 'El archivo exportado está vacío.' );
        }

        $expires_at = $retention_days > 0
            ? gmdate( 'Y-m-d H:i:s', time() + $retention_days * DAY_IN_SECONDS )
            : null;

        global $wpdb;
        $wpdb->insert( $this->exports_table, [
            'table_name'  => $table,
            'file_name'   => $file_name,
            'file_path'   => $file_path,
            'file_size'   => filesize( $file_path ),
            'action_type' => $action_type,
            'user_id'     => $user_id,
            'status'      => 'available',
            'created_at'  => current_time( 'mysql' ),
            'expires_at'  => $expires_at,
        ], [ '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ] );

        $export_id = (int) $wpdb->insert_id;
        if ( ! $export_id ) {
            @unlink( $file_path );
            return new WP_Error( 'db_insert_failed', 'No se pudo registrar la exportación.' );
        }

        $this->audit->log( $table, 'export', "Archivo: {$file_name}, Tipo: {$action_type}", 'success' );

        return $this->get_export_by_id( $export_id );
    }

    // ── Token / download ─────────────────────────────────────────────────────

    /** Generate a 5-minute single-use download token. */
    public function generate_download_token( int $export_id ): string {
        $token = bin2hex( random_bytes( 32 ) );
        set_transient( 'mad_dbm_token_' . $token, [
            'export_id'  => $export_id,
            'expires_at' => time() + self::TOKEN_TTL,
            'used'       => false,
        ], self::TOKEN_TTL );
        return $token;
    }

    /** Validate token — returns export record merged with token metadata, or null. */
    public function validate_download_token( string $token ): ?array {
        $data = get_transient( 'mad_dbm_token_' . sanitize_text_field( $token ) );
        if ( ! $data || $data['used'] ) return null;
        if ( time() > $data['expires_at'] ) {
            delete_transient( 'mad_dbm_token_' . $token );
            return null;
        }
        $export = $this->get_export_by_id( (int) $data['export_id'] );
        if ( ! $export || $export['status'] !== 'available' ) return null;
        return array_merge( $export, [ '_token' => $token, '_expires_at' => $data['expires_at'] ] );
    }

    /** Mark token as used (prevents second download with same token). */
    public function invalidate_token( string $token ): void {
        $data = get_transient( 'mad_dbm_token_' . $token );
        if ( $data ) {
            $data['used'] = true;
            set_transient( 'mad_dbm_token_' . $token, $data, self::TOKEN_TTL );
        }
    }

    /** Stream .sql.gz to browser and exit. */
    public function serve_download( string $token ): void {
        $export = $this->validate_download_token( $token );
        if ( ! $export ) {
            wp_die( 'El enlace de descarga ha expirado o no es válido.', 'Enlace inválido', [ 'response' => 403 ] );
        }

        $file_path = $export['file_path'];
        if ( ! file_exists( $file_path ) ) {
            wp_die( 'El archivo de exportación no se encontró.', 'Archivo no encontrado', [ 'response' => 404 ] );
        }

        $this->invalidate_token( $token );
        $this->audit->log( $export['table_name'], 'download', 'Token: ' . substr( $token, 0, 8 ) . '…', 'success' );

        header( 'Content-Type: application/gzip' );
        header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'Cache-Control: no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );

        while ( ob_get_level() ) ob_end_clean();
        flush();
        readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /** Send admin email with a temporary link (never attaches the file). */
    public function send_email_notification( int $export_id ): bool {
        $export = $this->get_export_by_id( $export_id );
        if ( ! $export ) return false;

        $token        = $this->generate_download_token( $export_id );
        $download_url = add_query_arg( [
            'action'    => 'mad_dbm_download',
            'mad_token' => $token,
        ], admin_url( 'admin-post.php' ) );

        $admin_email = get_option( 'admin_email' );
        $site_name   = get_bloginfo( 'name' );
        $table       = $export['table_name'];
        $file_mb     = round( $export['file_size'] / 1048576, 2 );
        $ttl_min     = self::TOKEN_TTL / 60;

        $subject = "[{$site_name}] Exportación disponible: {$table}";
        $body    = "Se ha generado una exportación de la tabla `{$table}` ({$file_mb} MB).\n\n"
                 . "Haz clic en el siguiente enlace para descargar el archivo (caduca en {$ttl_min} minutos):\n\n"
                 . $download_url . "\n\n"
                 . "IMPORTANTE: Este enlace es de un solo uso y expira en {$ttl_min} minutos.\n"
                 . "Si ya expiró, genera una nueva exportación desde el panel.\n\n"
                 . "El archivo NO está adjunto a este email por seguridad.\n\n"
                 . "— MAD DB Monitor";

        return wp_mail( $admin_email, $subject, $body );
    }

    // ── Listing / retrieval ───────────────────────────────────────────────────

    /** Returns only non-deleted exports, newest first. */
    public function get_all_exports(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            "SELECT * FROM {$this->exports_table} WHERE status != 'deleted' ORDER BY created_at DESC",
            ARRAY_A
        ) ?: [];
    }

    public function get_export_by_id( int $id ): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->exports_table} WHERE id = %d",
            $id
        ), ARRAY_A );
        return $row ?: null;
    }

    // ── Cleanup ───────────────────────────────────────────────────────────────

    public function delete_export( int $id ): bool {
        global $wpdb;
        $export = $this->get_export_by_id( $id );
        if ( ! $export ) return false;

        if ( file_exists( $export['file_path'] ) ) {
            @unlink( $export['file_path'] );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update( $this->exports_table, [ 'status' => 'deleted' ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
        $this->audit->log( $export['table_name'], 'delete_export', "Archivo: {$export['file_name']}", 'success' );
        return true;
    }

    /** Cron: mark expired exports and delete their files. */
    public function cleanup_expired_exports(): void {
        global $wpdb;
        $now = current_time( 'mysql' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $expired = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->exports_table} WHERE status = 'available' AND expires_at IS NOT NULL AND expires_at < %s",
            $now
        ), ARRAY_A );

        foreach ( (array) $expired as $export ) {
            if ( file_exists( $export['file_path'] ) ) {
                @unlink( $export['file_path'] );
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->update( $this->exports_table, [ 'status' => 'expired' ], [ 'id' => $export['id'] ], [ '%s' ], [ '%d' ] );
        }
    }

    /** Cron: remove .sql.gz files on disk that have no DB record. */
    public function cleanup_orphaned_files(): void {
        $dir = $this->get_export_dir();
        if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) return;

        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $known = $wpdb->get_col( "SELECT file_name FROM {$this->exports_table}" );

        foreach ( glob( trailingslashit( $dir ) . '*.sql.gz' ) as $file ) {
            if ( ! in_array( basename( $file ), $known, true ) ) {
                @unlink( $file );
            }
        }
    }

    // ── Directory / protection ────────────────────────────────────────────────

    public function get_export_dir() {
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'upload_dir', $upload['error'] );
        }
        $dir = trailingslashit( $upload['basedir'] ) . 'mad-db-exports';
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'mkdir_failed', 'No se pudo crear la carpeta de exportaciones.' );
        }
        return $dir;
    }

    private function ensure_dir_protected( string $dir ): void {
        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
            );
        }
        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // Silence is golden.' );
        }
    }

    // ── SQL generation (streaming — no large strings in memory) ──────────────

    /**
     * Write the full SQL dump directly into an open gz resource, in batches.
     * Returns true on success, WP_Error on failure.
     *
     * @param resource $gz       Open gzfile handle (gzopen).
     * @param string   $table    Full table name.
     */
    private function write_sql_to_gz( $gz, string $table, string $action_type, string $login, int $user_id ) {
        global $wpdb;

        if ( ! $this->table_exists_in_db( $table ) ) {
            return new WP_Error( 'table_not_found', "La tabla {$table} no existe." );
        }

        // Row count (one cheap query before streaming)
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

        // ── Header ──
        $header  = "-- MAD-DB-EXPORT\n";
        $header .= "-- Table: {$table}\n";
        $header .= "-- Date: " . current_time( 'Y-m-d H:i:s' ) . "\n";
        $header .= "-- Action: {$action_type}\n";
        $header .= "-- User: {$login} ({$user_id})\n";
        $header .= "-- Rows: {$row_count}\n";
        $header .= "-- Plugin-Version: 1.0.0\n";
        $header .= "-- WordPress-Version: " . get_bloginfo( 'version' ) . "\n\n";
        $header .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $header .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
        gzwrite( $gz, $header );

        // ── CREATE TABLE ──
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $create_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create_row ) {
            return new WP_Error( 'show_create_failed', "No se pudo obtener CREATE TABLE para {$table}." );
        }
        gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" );
        gzwrite( $gz, $create_row[1] . ";\n\n" );

        // ── Data in batches (avoids PHP memory exhaustion) ──
        $batch  = 500;
        $offset = 0;

        while ( true ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch, $offset ),
                ARRAY_A
            );
            if ( empty( $rows ) ) break;

            $columns     = '`' . implode( '`, `', array_keys( $rows[0] ) ) . '`';
            $values_list = [];

            foreach ( $rows as $row ) {
                $vals = [];
                foreach ( $row as $val ) {
                    $vals[] = is_null( $val ) ? 'NULL' : "'" . esc_sql( $val ) . "'";
                }
                $values_list[] = '(' . implode( ', ', $vals ) . ')';
            }

            gzwrite( $gz, "INSERT INTO `{$table}` ({$columns}) VALUES\n"
                        . implode( ",\n", $values_list ) . ";\n" );

            $offset += $batch;
            // Yield control back periodically to avoid max_execution_time on huge tables
            if ( $offset % 5000 === 0 ) {
                @set_time_limit( 60 );
            }
        }

        gzwrite( $gz, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
        return true;
    }

    private function table_exists_in_db( string $table ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ) ) > 0;
    }
}
