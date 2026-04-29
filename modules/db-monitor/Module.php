<?php
/**
 * Módulo: DB Monitor
 *
 * Monitor y Limpieza Segura de Base de Datos.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return new class( $core ?? null ) implements MAD_Suite_Module {

    private $core;
    private string $slug = 'db-monitor';

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

    public function slug(): string       { return $this->slug; }
    public function title(): string      { return __( 'Monitor y Limpieza de BD', 'mad-suite' ); }
    public function menu_label(): string { return __( 'DB Monitor', 'mad-suite' ); }
    public function menu_slug(): string  { return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug; }

    public function description(): string {
        return __( 'Diagnostica tablas pesadas, exporta con token temporal, limpia solo tablas permitidas y restaura backups seguros.', 'mad-suite' );
    }

    public function init(): void {
        $this->ensure_db_tables();
        $this->register_cron();

        // Token-protected download (accessible without login — token IS the auth)
        add_action( 'admin_post_mad_dbm_download',        [ $this, 'handle_download' ] );
        add_action( 'admin_post_nopriv_mad_dbm_download', [ $this, 'handle_download' ] );

        // Settings save
        add_action( 'admin_post_mad_dbm_save_settings', [ $this, 'handle_save_settings' ] );

        // AJAX — admin only (wp_ajax_* requires login)
        add_action( 'wp_ajax_mad_dbm_export_ajax',      [ $this, 'ajax_export_table' ] );
        add_action( 'wp_ajax_mad_dbm_preview_cleanup',  [ $this, 'ajax_preview_cleanup' ] );
        add_action( 'wp_ajax_mad_dbm_cleanup',          [ $this, 'ajax_cleanup' ] );
        add_action( 'wp_ajax_mad_dbm_clean_transients', [ $this, 'ajax_clean_transients' ] );
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
            'settings'  => __( 'Configuración', 'mad-suite' ),
        ];

        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! array_key_exists( $current_tab, $tabs ) ) $current_tab = 'dashboard';

        $data = [ 'module' => $this, 'tabs' => $tabs, 'current_tab' => $current_tab ];

        switch ( $current_tab ) {
            case 'dashboard':
                // Load tables once and share with summary to avoid double query
                $tables                    = $this->analyzer()->get_all_tables();
                $data['summary']           = $this->analyzer()->get_database_summary( $tables );
                $data['suspicious_tables'] = array_values( array_filter( $tables, fn( $t ) => $t['is_suspicious'] ) );
                break;

            case 'tables':
                $data['tables']           = $this->analyzer()->get_all_tables();
                $data['cleanable_config'] = $this->analyzer()->get_cleanable_config();
                break;

            case 'exports':
                $data['exports'] = $this->exporter()->get_all_exports();
                break;

            case 'restore':
                break;

            case 'audit':
                $per_page             = 50;
                $paged                = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
                $data['logs']         = $this->audit()->get_logs( $per_page, $paged );
                $data['total_logs']   = $this->audit()->get_total();
                $data['current_page'] = $paged;
                $data['per_page']     = $per_page;
                break;

            case 'settings':
                $data['settings'] = $this->get_settings();
                break;
        }

        extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
        include __DIR__ . '/views/settings.php';
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, $this->menu_slug() ) === false ) return;

        $url = plugin_dir_url( __FILE__ );
        wp_enqueue_style( 'mad-dbm-admin', $url . 'assets/css/admin.css', [], '1.1.0' );
        wp_enqueue_script( 'mad-dbm-admin', $url . 'assets/js/admin.js', [ 'jquery' ], '1.1.0', true );
        wp_localize_script( 'mad-dbm-admin', 'madDBM', [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'mad_dbm_nonce' ),
            'token_ttl'   => 300,
            'i18n'        => [
                'exporting'       => __( 'Exportando… esto puede tardar en tablas grandes.', 'mad-suite' ),
                'export_ok'       => __( 'Exportación completada.', 'mad-suite' ),
                'export_fail'     => __( 'Error en la exportación.', 'mad-suite' ),
                'copied'          => __( 'Enlace copiado al portapapeles.', 'mad-suite' ),
                'email_ok'        => __( 'Email enviado correctamente al administrador.', 'mad-suite' ),
                'email_fail'      => __( 'No se pudo enviar el email.', 'mad-suite' ),
                'confirm_delete'  => __( '¿Eliminar este archivo de exportación? No se puede deshacer.', 'mad-suite' ),
                'confirm_email'   => __( 'Se enviará un enlace temporal (5 min) al email del administrador. ¿Continuar?', 'mad-suite' ),
                'link_expired'    => __( 'ENLACE EXPIRADO', 'mad-suite' ),
                'expired_msg'     => __( 'El enlace ha expirado. Genera una nueva exportación o solicita otro enlace.', 'mad-suite' ),
                'conn_error'      => __( 'Error de conexión.', 'mad-suite' ),
                'transients_ok'   => __( 'Transients expirados eliminados:', 'mad-suite' ),
                'searching'       => __( 'Buscar tabla…', 'mad-suite' ),
            ],
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

    // ── Settings save ─────────────────────────────────────────────────────────

    public function handle_save_settings(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos.', 'mad-suite' ) );
        }
        check_admin_referer( 'mad_dbm_save_settings', 'mad_dbm_settings_nonce' );

        $input = $_POST['mad_dbm_settings'] ?? [];
        $saved = [
            'retention_days'        => max( 1, (int) ( $input['retention_days'] ?? 30 ) ),
            'suspicious_size_mb'    => max( 1, (int) ( $input['suspicious_size_mb'] ?? 50 ) ),
            'suspicious_row_count'  => max( 1, (int) ( $input['suspicious_row_count'] ?? 100000 ) ),
        ];

        update_option( MAD_Suite_Core::option_key( $this->slug ), $saved );

        wp_safe_redirect( add_query_arg( [
            'page'       => $this->menu_slug(),
            'tab'        => 'settings',
            'mad_notice' => 'success',
            'mad_msg'    => rawurlencode( 'Configuración guardada.' ),
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── AJAX: export table ────────────────────────────────────────────────────

    public function ajax_export_table(): void {
        $this->verify_ajax_nonce();
        $table = $this->get_validated_table( 'mad_table' );

        if ( ! $this->analyzer()->is_table_exportable( $table ) ) {
            wp_send_json_error( "La tabla '{$table}' contiene credenciales de usuario y no puede exportarse." );
        }

        $settings = $this->get_settings();

        $export = $this->exporter()->export_table( $table, 'manual', (int) $settings['retention_days'] );

        if ( is_wp_error( $export ) ) {
            wp_send_json_error( $export->get_error_message() );
        }
        wp_send_json_success( [
            'file_name' => $export['file_name'],
            'file_size' => $export['file_size'],
            'export_id' => $export['id'],
        ] );
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

    // ── AJAX: execute cleanup ─────────────────────────────────────────────────

    public function ajax_cleanup(): void {
        $this->verify_ajax_nonce();
        $table    = $this->get_validated_table( 'mad_table' );
        $action   = $this->get_clean_action( 'mad_action' );
        $days     = isset( $_POST['mad_days'] ) ? (int) $_POST['mad_days'] : 30;
        $settings = $this->get_settings();

        $result = $this->cleaner()->execute_safe_cleanup(
            $table, $action, [ 'days' => $days ], (int) $settings['retention_days']
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    // ── AJAX: clean expired transients (no backup needed — data is already expired) ──

    public function ajax_clean_transients(): void {
        $this->verify_ajax_nonce();
        $result = $this->cleaner()->clean_expired_transients();
        $this->audit()->log( 'wp_options', 'clean_expired_transients', "Registros eliminados: {$result['deleted']}", 'success' );
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

    // ── AJAX: send email ──────────────────────────────────────────────────────

    public function ajax_send_email(): void {
        $this->verify_ajax_nonce();
        $export_id = isset( $_POST['export_id'] ) ? (int) $_POST['export_id'] : 0;
        if ( ! $export_id ) wp_send_json_error( 'ID de exportación inválido.' );

        $sent = $this->exporter()->send_email_notification( $export_id );
        if ( $sent ) wp_send_json_success();
        else         wp_send_json_error( 'No se pudo enviar el email.' );
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

        $table      = $meta['table'] ?? '';
        $restorable = $this->restorer()->is_restorable( $table );
        if ( is_wp_error( $restorable ) ) {
            @unlink( $tmp_path );
            wp_send_json_error( $restorable->get_error_message() );
        }

        // Store the actual path server-side; return only a short-lived token to the client
        $upload_token = bin2hex( random_bytes( 16 ) );
        set_transient( 'mad_dbm_upload_' . $upload_token, $tmp_path, 600 ); // 10 min window

        wp_send_json_success( [
            'upload_token' => $upload_token,
            'meta'         => $meta,
        ] );
    }

    // ── AJAX: restore execute ─────────────────────────────────────────────────

    public function ajax_restore_execute(): void {
        $this->verify_ajax_nonce();
        check_ajax_referer( 'mad_dbm_restore_execute', 'mad_dbm_restore_execute_nonce' );

        $upload_token = isset( $_POST['mad_upload_token'] ) ? sanitize_text_field( wp_unslash( $_POST['mad_upload_token'] ) ) : '';
        $table        = isset( $_POST['mad_table'] ) ? sanitize_text_field( wp_unslash( $_POST['mad_table'] ) ) : '';

        if ( ! $upload_token || ! $table ) {
            wp_send_json_error( 'Datos incompletos.' );
        }

        // Absolute restriction enforced again at execution time — never trust only the upload step.
        if ( ! $this->analyzer()->is_table_exportable( $table ) ) {
            wp_send_json_error( "La tabla '{$table}' contiene credenciales de usuario y no puede importarse." );
        }

        // Retrieve path from server-side transient (never trusts client path)
        $tmp_path = get_transient( 'mad_dbm_upload_' . $upload_token );
        if ( ! $tmp_path ) {
            wp_send_json_error( 'La sesión de restauración ha expirado. Sube el archivo de nuevo.' );
        }
        delete_transient( 'mad_dbm_upload_' . $upload_token );

        // Validate path is inside the expected directory
        $export_dir = $this->exporter()->get_export_dir();
        if ( is_wp_error( $export_dir ) ) {
            wp_send_json_error( 'Error accediendo al directorio de exportaciones.' );
        }

        $real_tmp = realpath( $tmp_path );
        $real_dir = realpath( trailingslashit( $export_dir ) . 'tmp' );

        if ( ! $real_tmp || ! $real_dir || strpos( $real_tmp, $real_dir ) !== 0 ) {
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
        $current     = '1.1';
        if ( get_option( $version_key ) === $current ) return;

        $this->audit()->create_table();
        $this->exporter()->create_table();
        update_option( $version_key, $current );
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    private function get_settings(): array {
        $saved = get_option( MAD_Suite_Core::option_key( $this->slug ), [] );
        return wp_parse_args( $saved, [
            'retention_days'       => 30,
            'suspicious_size_mb'   => 50,
            'suspicious_row_count' => 100000,
        ] );
    }

    // ── Service accessors (lazy init) ─────────────────────────────────────────

    private function audit(): MAD_DBM_AuditLogger {
        if ( ! $this->audit ) $this->audit = new MAD_DBM_AuditLogger();
        return $this->audit;
    }

    private function analyzer(): MAD_DBM_Analyzer {
        if ( ! $this->analyzer ) {
            $s              = $this->get_settings();
            $this->analyzer = new MAD_DBM_Analyzer( [
                'size_mb'   => (int) $s['suspicious_size_mb'],
                'row_count' => (int) $s['suspicious_row_count'],
            ] );
        }
        return $this->analyzer;
    }

    private function exporter(): MAD_DBM_ExportManager {
        if ( ! $this->exporter ) $this->exporter = new MAD_DBM_ExportManager( $this->audit(), $this->analyzer() );
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

    // ── Private helpers ───────────────────────────────────────────────────────

    private function load_classes(): void {
        foreach ( [ 'class-audit-logger', 'class-db-analyzer', 'class-export-manager', 'class-cleanup-manager', 'class-restore-manager' ] as $f ) {
            require_once __DIR__ . '/includes/' . $f . '.php';
        }
    }

    /** Checks capability and verifies the generic nonce. Sends JSON error and exits on failure. */
    private function verify_ajax_nonce( string $field = 'mad_nonce', string $action = 'mad_dbm_nonce' ): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_send_json_error( 'Permisos insuficientes.' );
        }
        $nonce = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            wp_send_json_error( 'Token de seguridad inválido.' );
        }
    }

    /** Gets and validates a table name from POST: must exist in DB. */
    private function get_validated_table( string $key ): string {
        $table = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
        if ( ! $table ) wp_send_json_error( 'Nombre de tabla requerido.' );
        if ( ! $this->analyzer()->table_exists( $table ) ) wp_send_json_error( 'La tabla no existe en la base de datos.' );
        return $table;
    }

    /** Allow-lists the cleanup action name. */
    private function get_clean_action( string $key ): string {
        $allowed = [ 'clean_old', 'truncate', 'clean_old_completed', 'clean_expired_transients' ];
        $action  = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : 'clean_old';
        if ( ! in_array( $action, $allowed, true ) ) wp_send_json_error( 'Acción no permitida.' );
        return $action;
    }
};
