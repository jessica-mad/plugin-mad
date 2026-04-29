<?php
/**
 * Módulo: DB Monitor
 *
 * Monitor y Limpieza Segura de Base de Datos.
 * Diagnostica tablas, exporta de forma segura, limpia solo tablas permitidas
 * y restaura desde backups generados por el propio plugin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return new class( $core ?? null ) implements MAD_Suite_Module {

    private $core;
    private string $slug = 'db-monitor';

    // Lazy-loaded service instances
    private ?MAD_DBM_Analyzer       $analyzer = null;
    private ?MAD_DBM_ExportManager  $exporter = null;
    private ?MAD_DBM_CleanupManager $cleaner  = null;
    private ?MAD_DBM_RestoreManager $restorer = null;
    private ?MAD_DBM_AuditLogger    $audit    = null;

    public function __construct( $core ) {
        $this->core = $core;
        $this->load_classes();
    }

    // ── MAD_Suite_Module interface ────────────────────────────────────────────

    public function slug(): string  { return $this->slug; }
    public function title(): string { return __( 'Monitor y Limpieza de BD', 'mad-suite' ); }
    public function menu_label(): string { return __( 'DB Monitor', 'mad-suite' ); }
    public function menu_slug(): string  { return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug; }

    public function description(): string {
        return __( 'Diagnostica tablas pesadas, exporta con token temporal, limpia solo tablas permitidas y restaura backups seguros.', 'mad-suite' );
    }

    public function init(): void {
        $this->ensure_db_tables();
        $this->register_cron();

        // Download endpoint (public but token-protected)
        add_action( 'admin_post_mad_dbm_download',        [ $this, 'handle_download' ] );
        add_action( 'admin_post_nopriv_mad_dbm_download', [ $this, 'handle_download' ] );

        // Export via form POST (admin only)
        add_action( 'admin_post_mad_dbm_export_table', [ $this, 'handle_export_post' ] );

        // AJAX actions
        add_action( 'wp_ajax_mad_dbm_preview_cleanup',  [ $this, 'ajax_preview_cleanup' ] );
        add_action( 'wp_ajax_mad_dbm_cleanup',          [ $this, 'ajax_cleanup' ] );
        add_action( 'wp_ajax_mad_dbm_get_token',        [ $this, 'ajax_get_token' ] );
        add_action( 'wp_ajax_mad_dbm_send_email',       [ $this, 'ajax_send_email' ] );
        add_action( 'wp_ajax_mad_dbm_delete_export',    [ $this, 'ajax_delete_export' ] );
        add_action( 'wp_ajax_mad_dbm_restore_upload',   [ $this, 'ajax_restore_upload' ] );
        add_action( 'wp_ajax_mad_dbm_restore_execute',  [ $this, 'ajax_restore_execute' ] );
    }

    public function admin_init(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }

        $tabs = [
            'dashboard' => __( 'Resumen', 'mad-suite' ),
            'tables'    => __( 'Tablas', 'mad-suite' ),
            'exports'   => __( 'Exportaciones', 'mad-suite' ),
            'restore'   => __( 'Restaurar', 'mad-suite' ),
            'audit'     => __( 'Auditoría', 'mad-suite' ),
        ];

        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! array_key_exists( $current_tab, $tabs ) ) $current_tab = 'dashboard';

        // Prepare data for each tab
        $data = [ 'module' => $this, 'tabs' => $tabs, 'current_tab' => $current_tab ];

        switch ( $current_tab ) {
            case 'dashboard':
                $tables = $this->analyzer()->get_all_tables();
                $data['summary']          = $this->analyzer()->get_database_summary();
                $data['suspicious_tables'] = array_filter( $tables, fn( $t ) => $t['is_suspicious'] );
                break;

            case 'tables':
                $data['tables'] = $this->analyzer()->get_all_tables();
                break;

            case 'exports':
                $data['exports'] = $this->exporter()->get_all_exports();
                break;

            case 'restore':
                // No extra data needed
                break;

            case 'audit':
                $per_page = 50;
                $paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
                $data['logs']         = $this->audit()->get_logs( $per_page, $paged );
                $data['total_logs']   = $this->audit()->get_total();
                $data['current_page'] = $paged;
                $data['per_page']     = $per_page;
                break;
        }

        extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
        include __DIR__ . '/views/settings.php';
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, $this->menu_slug() ) === false ) return;

        $url = plugin_dir_url( __FILE__ );
        wp_enqueue_style(
            'mad-dbm-admin',
            $url . 'assets/css/admin.css',
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'mad-dbm-admin',
            $url . 'assets/js/admin.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );
        wp_localize_script( 'mad-dbm-admin', 'madDBM', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'mad_dbm_nonce' ),
        ] );
    }

    // ── Download endpoint ─────────────────────────────────────────────────────

    public function handle_download(): void {
        $token = isset( $_GET['mad_token'] ) ? sanitize_text_field( wp_unslash( $_GET['mad_token'] ) ) : '';
        if ( ! $token ) {
            wp_die( 'Token requerido.', 'Acceso denegado', [ 'response' => 403 ] );
        }
        $this->exporter()->serve_download( $token );
    }

    // ── Export via form POST ──────────────────────────────────────────────────

    public function handle_export_post(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'mad-suite' ) );
        }

        $table = isset( $_POST['mad_table'] ) ? sanitize_text_field( wp_unslash( $_POST['mad_table'] ) ) : '';
        if ( ! $table ) wp_die( 'Tabla requerida.' );

        check_admin_referer( 'mad_dbm_export_' . $table, 'mad_dbm_nonce' );

        if ( ! $this->analyzer()->table_exists( $table ) ) {
            wp_die( 'La tabla no existe en la base de datos.' );
        }

        $export = $this->exporter()->export_table( $table, 'manual' );

        if ( is_wp_error( $export ) ) {
            $url = $this->redirect_url( 'tables', 'error', $export->get_error_message() );
        } else {
            $url = $this->redirect_url( 'exports', 'success', 'Exportación creada: ' . $export['file_name'] );
        }

        wp_safe_redirect( $url );
        exit;
    }

    // ── AJAX: preview cleanup ─────────────────────────────────────────────────

    public function ajax_preview_cleanup(): void {
        $this->verify_ajax_nonce();
        $table  = $this->get_validated_table( 'mad_table' );
        $action = $this->get_clean_action( 'mad_action' );
        $days   = isset( $_POST['mad_days'] ) ? (int) $_POST['mad_days'] : 30;

        $result = $this->cleaner()->preview_cleanup( $table, $action, [ 'days' => $days ] );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    // ── AJAX: execute cleanup (with auto backup) ──────────────────────────────

    public function ajax_cleanup(): void {
        $this->verify_ajax_nonce();
        $table  = $this->get_validated_table( 'mad_table' );
        $action = $this->get_clean_action( 'mad_action' );
        $days   = isset( $_POST['mad_days'] ) ? (int) $_POST['mad_days'] : 30;

        $result = $this->cleaner()->execute_safe_cleanup( $table, $action, [ 'days' => $days ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    // ── AJAX: generate download token ─────────────────────────────────────────

    public function ajax_get_token(): void {
        $this->verify_ajax_nonce();
        $export_id = isset( $_POST['export_id'] ) ? (int) $_POST['export_id'] : 0;
        if ( ! $export_id ) wp_send_json_error( 'ID de exportación inválido.' );

        $export = $this->exporter()->get_export_by_id( $export_id );
        if ( ! $export || $export['status'] !== 'available' ) {
            wp_send_json_error( 'Exportación no disponible.' );
        }

        $token        = $this->exporter()->generate_download_token( $export_id );
        $download_url = add_query_arg( [
            'action'    => 'mad_dbm_download',
            'mad_token' => $token,
        ], admin_url( 'admin-post.php' ) );

        wp_send_json_success( [ 'download_url' => $download_url ] );
    }

    // ── AJAX: send email notification ─────────────────────────────────────────

    public function ajax_send_email(): void {
        $this->verify_ajax_nonce();
        $export_id = isset( $_POST['export_id'] ) ? (int) $_POST['export_id'] : 0;
        if ( ! $export_id ) wp_send_json_error( 'ID de exportación inválido.' );

        $sent = $this->exporter()->send_email_notification( $export_id );
        if ( $sent ) {
            wp_send_json_success( 'Email enviado.' );
        } else {
            wp_send_json_error( 'No se pudo enviar el email.' );
        }
    }

    // ── AJAX: delete export ───────────────────────────────────────────────────

    public function ajax_delete_export(): void {
        $this->verify_ajax_nonce();
        $export_id = isset( $_POST['export_id'] ) ? (int) $_POST['export_id'] : 0;
        if ( ! $export_id ) wp_send_json_error( 'ID inválido.' );

        $ok = $this->exporter()->delete_export( $export_id );
        if ( $ok ) wp_send_json_success();
        else       wp_send_json_error( 'No se pudo eliminar.' );
    }

    // ── AJAX: restore upload ──────────────────────────────────────────────────

    public function ajax_restore_upload(): void {
        $this->verify_ajax_nonce( 'mad_dbm_restore_nonce', 'mad_dbm_restore_upload' );

        if ( empty( $_FILES['mad_dbm_restore_file'] ) ) {
            wp_send_json_error( 'No se recibió el archivo.' );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $tmp_path = $this->restorer()->handle_upload( $_FILES['mad_dbm_restore_file'] );
        if ( is_wp_error( $tmp_path ) ) {
            wp_send_json_error( $tmp_path->get_error_message() );
        }

        $meta = $this->restorer()->parse_header( $tmp_path );
        if ( is_wp_error( $meta ) ) {
            @unlink( $tmp_path );
            wp_send_json_error( $meta->get_error_message() );
        }

        $table = $meta['table'] ?? '';
        if ( ! $this->restorer()->is_restorable( $table ) ) {
            @unlink( $tmp_path );
            wp_send_json_error( "La tabla '{$table}' no puede restaurarse desde este panel (protegida o no existe)." );
        }

        wp_send_json_success( [
            'tmp_path' => $tmp_path,
            'meta'     => $meta,
        ] );
    }

    // ── AJAX: restore execute ─────────────────────────────────────────────────

    public function ajax_restore_execute(): void {
        $this->verify_ajax_nonce();
        check_ajax_referer( 'mad_dbm_restore_execute', 'mad_dbm_restore_execute_nonce' );

        $tmp_path = isset( $_POST['mad_tmp_path'] ) ? sanitize_text_field( wp_unslash( $_POST['mad_tmp_path'] ) ) : '';
        $table    = isset( $_POST['mad_table'] ) ? sanitize_text_field( wp_unslash( $_POST['mad_table'] ) ) : '';

        if ( ! $tmp_path || ! $table ) {
            wp_send_json_error( 'Datos incompletos.' );
        }

        // Ensure the path is inside our exports directory
        $export_dir = $this->exporter()->get_export_dir();
        if ( is_wp_error( $export_dir ) || strpos( realpath( dirname( $tmp_path ) ), realpath( $export_dir ) ) !== 0 ) {
            wp_send_json_error( 'Ruta de archivo no permitida.' );
        }

        $result = $this->restorer()->execute_restore( $tmp_path, $table );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    // ── Cron ──────────────────────────────────────────────────────────────────

    private function register_cron(): void {
        add_action( 'mad_dbm_daily_cleanup', [ $this, 'run_daily_cron' ] );
        if ( ! wp_next_scheduled( 'mad_dbm_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'mad_dbm_daily_cleanup' );
        }
    }

    public function run_daily_cron(): void {
        $this->exporter()->cleanup_expired_exports();
        $this->exporter()->cleanup_orphaned_files();
    }

    // ── DB table creation ─────────────────────────────────────────────────────

    private function ensure_db_tables(): void {
        $version_key = 'mad_dbm_db_version';
        $current     = '1.0';
        if ( get_option( $version_key ) === $current ) return;

        $this->audit()->create_table();
        $this->exporter()->create_table();
        update_option( $version_key, $current );
    }

    // ── Service accessors (lazy init) ─────────────────────────────────────────

    private function audit(): MAD_DBM_AuditLogger {
        if ( ! $this->audit ) $this->audit = new MAD_DBM_AuditLogger();
        return $this->audit;
    }

    private function analyzer(): MAD_DBM_Analyzer {
        if ( ! $this->analyzer ) $this->analyzer = new MAD_DBM_Analyzer();
        return $this->analyzer;
    }

    private function exporter(): MAD_DBM_ExportManager {
        if ( ! $this->exporter ) $this->exporter = new MAD_DBM_ExportManager( $this->audit() );
        return $this->exporter;
    }

    private function cleaner(): MAD_DBM_CleanupManager {
        if ( ! $this->cleaner ) $this->cleaner = new MAD_DBM_CleanupManager(
            $this->analyzer(), $this->exporter(), $this->audit()
        );
        return $this->cleaner;
    }

    private function restorer(): MAD_DBM_RestoreManager {
        if ( ! $this->restorer ) $this->restorer = new MAD_DBM_RestoreManager( $this->analyzer(), $this->audit() );
        return $this->restorer;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function load_classes(): void {
        foreach ( [ 'class-audit-logger', 'class-db-analyzer', 'class-export-manager', 'class-cleanup-manager', 'class-restore-manager' ] as $file ) {
            require_once __DIR__ . '/includes/' . $file . '.php';
        }
    }

    /** Verify the generic AJAX nonce. Optionally also check a named referer nonce. */
    private function verify_ajax_nonce( string $nonce_field = 'mad_nonce', string $action = 'mad_dbm_nonce' ): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_send_json_error( 'Permisos insuficientes.' );
        }
        $nonce = isset( $_POST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            wp_send_json_error( 'Token de seguridad inválido.' );
        }
    }

    /** Get and validate a table name from POST (must exist in DB). */
    private function get_validated_table( string $key ): string {
        $table = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
        if ( ! $table ) {
            wp_send_json_error( 'Nombre de tabla requerido.' );
        }
        if ( ! $this->analyzer()->table_exists( $table ) ) {
            wp_send_json_error( 'La tabla no existe en la base de datos.' );
        }
        return $table;
    }

    /** Sanitize and allow-list the cleanup action name. */
    private function get_clean_action( string $key ): string {
        $allowed = [ 'clean_old', 'truncate', 'clean_old_completed', 'clean_expired_transients' ];
        $action  = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : 'clean_old';
        if ( ! in_array( $action, $allowed, true ) ) {
            wp_send_json_error( 'Acción no permitida.' );
        }
        return $action;
    }

    private function redirect_url( string $tab, string $notice, string $msg ): string {
        return add_query_arg( [
            'page'       => $this->menu_slug(),
            'tab'        => $tab,
            'mad_notice' => $notice,
            'mad_msg'    => rawurlencode( $msg ),
        ], admin_url( 'admin.php' ) );
    }
};
