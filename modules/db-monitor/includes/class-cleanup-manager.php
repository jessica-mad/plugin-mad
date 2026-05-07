<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_DBM_CleanupManager {

    private MAD_DBM_Analyzer $analyzer;
    private MAD_DBM_ExportManager $exporter;
    private MAD_DBM_AuditLogger $audit;

    public function __construct(
        MAD_DBM_Analyzer $analyzer,
        MAD_DBM_ExportManager $exporter,
        MAD_DBM_AuditLogger $audit
    ) {
        $this->analyzer = $analyzer;
        $this->exporter = $exporter;
        $this->audit    = $audit;
    }

    /**
     * Main safe cleanup flow.
     *
     * Flow:
     *  1. Validate permissions & nonce (caller's responsibility before calling this).
     *  2. Validate table is cleanable.
     *  3. Create automatic backup.
     *  4. Verify backup.
     *  5. Log backup.
     *  6. Execute cleanup.
     *  7. Log result.
     *  8. Return summary array or WP_Error.
     *
     * @param string $table         Full table name with prefix.
     * @param string $action        'clean_old' | 'truncate' | 'clean_expired_transients'
     *                              | 'clean_wc_sessions' | 'clean_as_logs'
     *                              | 'clean_as_actions' | 'clean_cartbounty'
     * @param array  $params        Extra params: days (int), status_filter (array), etc.
     * @param int    $retention_days How long to keep the automatic backup.
     */
    public function execute_safe_cleanup( string $table, string $action, array $params = [], int $retention_days = 30 ) {
        // Step 2: Validate table is cleanable
        if ( ! $this->analyzer->is_table_cleanable( $table ) ) {
            return new WP_Error( 'not_cleanable', "La tabla '{$table}' no está en la lista blanca de limpieza." );
        }

        if ( ! $this->analyzer->table_exists( $table ) ) {
            return new WP_Error( 'table_not_found', "La tabla '{$table}' no existe en la base de datos." );
        }

        // Step 3: Create automatic backup
        $backup_type = 'auto_before_' . $action;
        $export      = $this->exporter->export_table( $table, $backup_type, $retention_days );

        if ( is_wp_error( $export ) ) {
            $this->audit->log( $table, 'backup_failed', $export->get_error_message(), 'error' );
            return new WP_Error(
                'backup_failed',
                'No se pudo crear la copia de seguridad automática. La limpieza fue cancelada. Error: '
                . $export->get_error_message()
            );
        }

        // Step 4: Verify backup file exists and has content
        if ( ! file_exists( $export['file_path'] ) || filesize( $export['file_path'] ) === 0 ) {
            $this->audit->log( $table, 'backup_verify_failed', "Archivo: {$export['file_name']}", 'error' );
            return new WP_Error( 'backup_verify_failed', 'El archivo de backup creado está vacío o no existe. La limpieza fue cancelada.' );
        }

        // Step 5: Log backup
        $this->audit->log(
            $table,
            'auto_backup',
            "Backup automático antes de '{$action}'. Archivo: {$export['file_name']} ({$export['file_size']} bytes)",
            'success'
        );

        // Step 6: Execute cleanup
        $result = $this->dispatch_action( $table, $action, $params );

        // Step 7: Log result
        if ( is_wp_error( $result ) ) {
            $this->audit->log( $table, $action, $result->get_error_message(), 'error' );
            return $result;
        }

        $this->audit->log(
            $table,
            $action,
            isset( $result['deleted'] ) ? "Registros eliminados: {$result['deleted']}" : 'OK',
            'success'
        );

        // Step 8: Return summary
        return array_merge( $result, [
            'backup_file' => $export['file_name'],
            'backup_id'   => $export['id'],
        ] );
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    private function dispatch_action( string $table, string $action, array $params ) {
        switch ( $action ) {
            case 'clean_old':
                return $this->clean_old_records( $table, $params );

            case 'truncate':
                return $this->truncate_table( $table );

            case 'clean_old_completed':
                return $this->clean_old_completed_actions( $table, $params );

            case 'clean_expired_transients':
                return $this->clean_expired_transients();

            default:
                return new WP_Error( 'unknown_action', "Acción desconocida: {$action}" );
        }
    }

    // ── Actual cleanup operations ─────────────────────────────────────────────

    /**
     * Delete rows older than $days days based on the config date_column.
     */
    private function clean_old_records( string $table, array $params ) {
        global $wpdb;

        $config = $this->analyzer->get_cleanable_table_config( $table );
        if ( ! $config ) {
            return new WP_Error( 'no_config', "Sin configuración para tabla {$table}." );
        }

        $days        = isset( $params['days'] ) ? (int) $params['days'] : (int) ( $config['default_days'] ?? 30 );
        $date_column = $config['date_column'] ?? null;
        $date_type   = $config['date_type'] ?? 'datetime';

        if ( ! $date_column ) {
            return new WP_Error( 'no_date_column', "La tabla {$table} no tiene columna de fecha configurada." );
        }

        if ( $date_type === 'unix' ) {
            $cutoff = time() - $days * DAY_IN_SECONDS;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE `{$date_column}` < %d",
                $cutoff
            ) );
        } else {
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE `{$date_column}` < %s",
                $cutoff
            ) );
        }

        if ( $deleted === false ) {
            return new WP_Error( 'delete_failed', $wpdb->last_error );
        }

        return [ 'deleted' => (int) $deleted, 'days' => $days ];
    }

    /**
     * Truncate table (only whitelisted tables can reach this point).
     */
    private function truncate_table( string $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query( "TRUNCATE TABLE `{$table}`" );
        if ( $result === false ) {
            return new WP_Error( 'truncate_failed', $wpdb->last_error );
        }
        return [ 'truncated' => true ];
    }

    /**
     * Delete old actionscheduler_actions with completed/failed/canceled status.
     */
    private function clean_old_completed_actions( string $table, array $params ) {
        global $wpdb;

        $config        = $this->analyzer->get_cleanable_table_config( $table );
        $days          = isset( $params['days'] ) ? (int) $params['days'] : (int) ( $config['default_days'] ?? 30 );
        $cutoff        = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $status_filter = $config['status_filter'] ?? [ 'complete', 'failed', 'canceled' ];

        $placeholders = implode( ', ', array_fill( 0, count( $status_filter ), '%s' ) );
        $query_args   = array_merge( [ $cutoff ], $status_filter );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE scheduled_date_gmt < %s AND status IN ({$placeholders})",
            ...$query_args
        ) );

        if ( $deleted === false ) {
            return new WP_Error( 'delete_failed', $wpdb->last_error );
        }

        return [ 'deleted' => (int) $deleted, 'days' => $days, 'statuses' => $status_filter ];
    }

    /**
     * Delete expired WordPress transients.
     * This does NOT require a table backup since transients are already expired.
     */
    public function clean_expired_transients(): array {
        global $wpdb;

        $now     = time();
        $deleted = 0;

        // Delete expired transients from options table
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $deleted += (int) $wpdb->query( $wpdb->prepare(
            "DELETE a, b FROM {$wpdb->options} a
             JOIN {$wpdb->options} b ON b.option_name = REPLACE(a.option_name, '_transient_timeout_', '_transient_')
             WHERE a.option_name LIKE '_transient_timeout_%%' AND a.option_value < %d",
            $now
        ) );

        // Delete expired site transients (multisite)
        if ( is_multisite() ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE a, b FROM {$wpdb->sitemeta} a
                 JOIN {$wpdb->sitemeta} b ON b.meta_key = REPLACE(a.meta_key, '_site_transient_timeout_', '_site_transient_')
                 WHERE a.meta_key LIKE '_site_transient_timeout_%%' AND a.meta_value < %d",
                $now
            ) );
        }

        return [ 'deleted' => $deleted ];
    }

    /**
     * Preview: count how many rows would be deleted without deleting them.
     */
    public function preview_cleanup( string $table, string $action, array $params ): array {
        global $wpdb;

        if ( ! $this->analyzer->is_table_cleanable( $table ) ) {
            return [ 'error' => "Tabla no permitida: {$table}" ];
        }

        $config = $this->analyzer->get_cleanable_table_config( $table );
        if ( ! $config ) return [ 'rows_to_delete' => 0 ];

        if ( $action === 'truncate' ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
            return [ 'rows_to_delete' => $count, 'action' => 'truncate' ];
        }

        $days        = isset( $params['days'] ) ? (int) $params['days'] : (int) ( $config['default_days'] ?? 30 );
        $date_column = $config['date_column'] ?? null;
        $date_type   = $config['date_type'] ?? 'datetime';

        if ( ! $date_column ) return [ 'rows_to_delete' => 0 ];

        if ( $date_type === 'unix' ) {
            $cutoff = time() - $days * DAY_IN_SECONDS;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$date_column}` < %d",
                $cutoff
            ) );
        } else {
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$date_column}` < %s",
                $cutoff
            ) );
        }

        return [ 'rows_to_delete' => $count, 'days' => $days, 'action' => $action ];
    }
}
