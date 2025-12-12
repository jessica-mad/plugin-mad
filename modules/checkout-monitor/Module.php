<?php
if ( ! defined('ABSPATH') ) exit;

return new class(MAD_Suite_Core::instance()) implements MAD_Suite_Module {

    /** @var MAD_Suite_Core */
    private $core;
    private $option_key;

    // Componentes del módulo
    private $hook_interceptor;
    private $execution_logger;
    private $error_catcher;
    private $browser_tracker;
    private $log_analyzer;
    private $database;

    public function __construct($core){
        $this->core = $core;
        $this->option_key = MAD_Suite_Core::option_key( $this->slug() );

        // Auto-load classes
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        // Namespace: MAD_Suite\CheckoutMonitor\...
        $prefix = 'MAD_Suite\\CheckoutMonitor\\';
        if ( strpos($class, $prefix) !== 0 ) return;

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', $relative) . '.php';

        if ( file_exists($file) ) {
            require_once $file;
        }
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'checkout-monitor'; }
    public function title(){ return __('Checkout Monitor','mad-suite'); }
    public function menu_label(){ return __('Checkout Monitor','mad-suite'); }
    public function menu_slug(){ return 'mad-'.$this->slug(); }

    public function description(){
        return __('Monitoriza todos los procesos del checkout de WooCommerce, detecta errores y analiza el comportamiento de plugins durante el proceso de compra.','mad-suite');
    }

    public function required_plugins(){
        return ['WooCommerce' => 'woocommerce/woocommerce.php'];
    }

    /* ==== Activación ==== */
    public function ensure_database_tables(){
        // Verificar si las tablas ya existen (cache en transient para no verificar cada vez)
        $tables_checked = get_transient('checkout_monitor_tables_checked');
        if ( $tables_checked ) {
            return; // Ya verificamos recientemente
        }

        $version_option = 'checkout_monitor_db_version';
        $current_version = get_option($version_option, '0');
        $required_version = '1.0';

        if ( version_compare($current_version, $required_version, '<') ) {
            $this->create_database_tables();
            update_option($version_option, $required_version);
        }

        // Cache por 1 hora para no verificar cada request
        set_transient('checkout_monitor_tables_checked', true, HOUR_IN_SECONDS);
    }

    private function create_database_tables(){
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Tabla principal de sesiones de checkout
        $table_sessions = $wpdb->prefix . 'checkout_monitor_sessions';
        $sql_sessions = "CREATE TABLE IF NOT EXISTS $table_sessions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            order_uid varchar(255) DEFAULT NULL,
            order_id bigint(20) DEFAULT NULL,
            status varchar(50) DEFAULT 'initiated',
            started_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            duration_ms int(11) DEFAULT NULL,
            payment_method varchar(100) DEFAULT NULL,
            total_amount decimal(10,2) DEFAULT NULL,
            has_errors tinyint(1) DEFAULT 0,
            error_count int(11) DEFAULT 0,
            hook_count int(11) DEFAULT 0,
            browser_data longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_id bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY order_id (order_id),
            KEY status (status),
            KEY has_errors (has_errors),
            KEY started_at (started_at)
        ) $charset_collate;";

        // Tabla de eventos (hooks ejecutados)
        $table_events = $wpdb->prefix . 'checkout_monitor_events';
        $sql_events = "CREATE TABLE IF NOT EXISTS $table_events (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            event_type varchar(50) NOT NULL,
            hook_name varchar(255) DEFAULT NULL,
            priority int(11) DEFAULT NULL,
            callback_name varchar(500) DEFAULT NULL,
            plugin_name varchar(255) DEFAULT NULL,
            file_path varchar(1000) DEFAULT NULL,
            line_number int(11) DEFAULT NULL,
            execution_time_ms decimal(10,4) DEFAULT NULL,
            memory_usage bigint(20) DEFAULT NULL,
            started_at datetime(6) NOT NULL,
            completed_at datetime(6) DEFAULT NULL,
            has_error tinyint(1) DEFAULT 0,
            error_message text DEFAULT NULL,
            error_trace longtext DEFAULT NULL,
            event_data longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY hook_name (hook_name),
            KEY has_error (has_error),
            KEY started_at (started_at)
        ) $charset_collate;";

        // Tabla de logs del servidor
        $table_logs = $wpdb->prefix . 'checkout_monitor_server_logs';
        $sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(255) DEFAULT NULL,
            log_type varchar(50) NOT NULL,
            log_source varchar(100) NOT NULL,
            log_file_path varchar(1000) DEFAULT NULL,
            file_size bigint(20) DEFAULT NULL,
            log_content longtext DEFAULT NULL,
            log_level varchar(20) DEFAULT NULL,
            timestamp datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY log_type (log_type),
            KEY log_source (log_source),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_sessions);
        dbDelta($sql_events);
        dbDelta($sql_logs);
    }

    /* ==== Hooks públicos ==== */
    public function init(){
        // Verificar WooCommerce
        if ( ! class_exists('WooCommerce') ) return;

        // Asegurar tablas en admin (solo una vez)
        if ( is_admin() ) {
            add_action('admin_init', [$this, 'ensure_database_tables'], 5);
        }

        // Inicializar Database y BrowserTracker siempre (para AJAX)
        $this->database = new \MAD_Suite\CheckoutMonitor\Database();
        $this->browser_tracker = new \MAD_Suite\CheckoutMonitor\Trackers\BrowserTracker($this->database);

        // AJAX para el dashboard
        add_action('wp_ajax_checkout_monitor_get_sessions', [$this, 'ajax_get_sessions']);
        add_action('wp_ajax_checkout_monitor_get_session_detail', [$this, 'ajax_get_session_detail']);
        add_action('wp_ajax_checkout_monitor_delete_old_logs', [$this, 'ajax_delete_old_logs']);
        add_action('wp_ajax_checkout_monitor_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_checkout_monitor_view_log', [$this, 'ajax_view_log']);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Inicializar tracking en checkout (tarde, cuando todo esté listo)
        add_action('template_redirect', [$this, 'maybe_init_trackers'], 20);

        // CRÍTICO: También en AJAX (el checkout se procesa vía AJAX)
        add_action('woocommerce_before_checkout_process', [$this, 'init_trackers'], 1);

        // Cron para limpieza
        add_action('checkout_monitor_cleanup', [$this, 'cleanup_old_logs']);
        if (!wp_next_scheduled('checkout_monitor_cleanup')) {
            wp_schedule_event(time(), 'daily', 'checkout_monitor_cleanup');
        }
    }

    public function maybe_init_trackers(){
        // Solo en checkout o AJAX de checkout
        if ( ! is_checkout() && ! $this->is_ajax_checkout() ) {
            return;
        }

        $this->init_trackers();
    }

    private function is_ajax_checkout(){
        return (
            (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && $_REQUEST['action'] === 'woocommerce_checkout') ||
            (isset($_REQUEST['wc-ajax']) && $_REQUEST['wc-ajax'] === 'checkout')
        );
    }

    public function init_trackers(){
        // Evitar inicializar dos veces
        if ( $this->execution_logger ) return;

        // Inicializar componentes de tracking (Database ya está inicializado)
        $this->execution_logger = new \MAD_Suite\CheckoutMonitor\ExecutionLogger($this->database);
        $this->hook_interceptor = new \MAD_Suite\CheckoutMonitor\Trackers\HookInterceptor($this->execution_logger);
        $this->error_catcher = new \MAD_Suite\CheckoutMonitor\Trackers\ErrorCatcher($this->execution_logger);
        $this->log_analyzer = new \MAD_Suite\CheckoutMonitor\Analyzers\LogAnalyzer($this->database);

        // Activar tracking
        $this->hook_interceptor->activate();
        $this->error_catcher->activate();
    }

    public function enqueue_frontend_scripts(){
        if ( is_checkout() ) {
            wp_enqueue_script(
                'checkout-monitor-tracker',
                plugin_dir_url(__FILE__) . 'assets/js/checkout-tracker.js',
                ['jquery'],
                '1.0.0',
                true
            );

            wp_localize_script('checkout-monitor-tracker', 'checkoutMonitor', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('checkout_monitor_track'),
                'sessionId' => $this->get_or_create_session_id(),
            ]);
        }
    }

    public function enqueue_admin_scripts($hook){
        if ( strpos($hook, $this->menu_slug()) === false ) return;

        wp_enqueue_style(
            'checkout-monitor-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin-dashboard.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'checkout-monitor-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin-dashboard.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('checkout-monitor-admin', 'checkoutMonitorAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('checkout_monitor_admin'),
        ]);
    }

    private function get_or_create_session_id(){
        if ( isset($_COOKIE['checkout_monitor_session']) ) {
            return sanitize_text_field($_COOKIE['checkout_monitor_session']);
        }

        $session_id = uniqid('cm_', true);

        // Solo setear cookie si los headers NO han sido enviados
        if ( !headers_sent() ) {
            @setcookie('checkout_monitor_session', $session_id, time() + 3600, '/', '', false, true);
        }

        return $session_id;
    }

    /* ==== Admin Init ==== */
    public function admin_init(){
        // Registrar ajustes si son necesarios
        register_setting($this->option_key, $this->option_key);
    }

    /* ==== Página de ajustes ==== */
    public function render_settings_page(){
        if ( !current_user_can(MAD_Suite_Core::CAPABILITY) ) {
            wp_die(__('No tienes permisos suficientes.', 'mad-suite'));
        }

        // Cargar el dashboard admin
        $dashboard = new \MAD_Suite\CheckoutMonitor\Admin\Dashboard($this->database);
        $dashboard->render();
    }

    /* ==== AJAX Handlers ==== */
    public function ajax_get_sessions(){
        check_ajax_referer('checkout_monitor_admin', 'nonce');

        if ( !current_user_can(MAD_Suite_Core::CAPABILITY) ) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
        $filters = isset($_POST['filters']) ? $_POST['filters'] : [];

        $dashboard = new \MAD_Suite\CheckoutMonitor\Admin\Dashboard($this->database);
        $data = $dashboard->get_sessions_data($page, $per_page, $filters);

        wp_send_json_success($data);
    }

    public function ajax_get_session_detail(){
        check_ajax_referer('checkout_monitor_admin', 'nonce');

        if ( !current_user_can(MAD_Suite_Core::CAPABILITY) ) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

        if ( empty($session_id) ) {
            wp_send_json_error(['message' => 'Session ID requerido']);
        }

        $dashboard = new \MAD_Suite\CheckoutMonitor\Admin\Dashboard($this->database);
        $data = $dashboard->get_session_detail($session_id);

        wp_send_json_success($data);
    }

    public function ajax_delete_old_logs(){
        check_ajax_referer('checkout_monitor_admin', 'nonce');

        if ( !current_user_can(MAD_Suite_Core::CAPABILITY) ) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }

        $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
        $deleted = $this->cleanup_old_logs($days);

        wp_send_json_success(['deleted' => $deleted]);
    }

    public function ajax_save_settings(){
        check_ajax_referer('checkout_monitor_admin', 'nonce');

        if ( !current_user_can(MAD_Suite_Core::CAPABILITY) ) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }

        $cleanup_days = isset($_POST['cleanup_days']) ? intval($_POST['cleanup_days']) : 30;

        // Validar rango
        if ( $cleanup_days < 1 ) $cleanup_days = 1;
        if ( $cleanup_days > 365 ) $cleanup_days = 365;

        update_option('checkout_monitor_cleanup_days', $cleanup_days);

        wp_send_json_success([
            'message' => __('Configuración guardada correctamente', 'mad-suite'),
            'cleanup_days' => $cleanup_days
        ]);
    }

    public function ajax_view_log(){
        check_ajax_referer('checkout_monitor_admin', 'nonce');

        if ( !current_user_can(MAD_Suite_Core::CAPABILITY) ) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
        }

        $log_path = isset($_POST['log_path']) ? sanitize_text_field($_POST['log_path']) : '';

        if ( empty($log_path) ) {
            wp_send_json_error(['message' => 'Ruta de log no especificada']);
        }

        // Verificar que el archivo existe y es legible
        if ( !file_exists($log_path) || !is_readable($log_path) ) {
            wp_send_json_error(['message' => 'No se puede leer el archivo de log']);
        }

        // Verificar que el archivo está en una ubicación permitida
        $allowed_paths = [
            WP_CONTENT_DIR,
            ABSPATH . 'wp-admin',
        ];

        if ( defined('WC_LOG_DIR') ) {
            $allowed_paths[] = WC_LOG_DIR;
        }

        $is_allowed = false;
        foreach ( $allowed_paths as $allowed_path ) {
            if ( strpos(realpath($log_path), realpath($allowed_path)) === 0 ) {
                $is_allowed = true;
                break;
            }
        }

        if ( !$is_allowed ) {
            wp_send_json_error(['message' => 'Acceso denegado a este archivo']);
        }

        // Leer el archivo (últimas 1000 líneas para evitar sobrecarga)
        $lines = [];
        $file = new \SplFileObject($log_path);
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key() + 1;

        // Leer últimas 1000 líneas
        $start_line = max(0, $total_lines - 1000);
        $file->seek($start_line);

        while ( !$file->eof() ) {
            $line = $file->fgets();
            if ( $line !== false ) {
                $lines[] = $line;
            }
        }

        wp_send_json_success([
            'file_path' => $log_path,
            'file_name' => basename($log_path),
            'file_size' => filesize($log_path),
            'total_lines' => $total_lines,
            'showing_lines' => count($lines),
            'content' => implode('', $lines),
        ]);
    }

    public function cleanup_old_logs($days = 30){
        global $wpdb;

        $date_limit = date('Y-m-d H:i:s', strtotime("-$days days"));

        $sessions_table = $wpdb->prefix . 'checkout_monitor_sessions';
        $events_table = $wpdb->prefix . 'checkout_monitor_events';
        $logs_table = $wpdb->prefix . 'checkout_monitor_server_logs';

        // Obtener session_ids a eliminar
        $session_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT session_id FROM $sessions_table WHERE created_at < %s",
            $date_limit
        ));

        if ( empty($session_ids) ) return 0;

        $placeholders = implode(',', array_fill(0, count($session_ids), '%s'));

        // Eliminar eventos
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $events_table WHERE session_id IN ($placeholders)",
            ...$session_ids
        ));

        // Eliminar logs
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $logs_table WHERE session_id IN ($placeholders)",
            ...$session_ids
        ));

        // Eliminar sesiones
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $sessions_table WHERE created_at < %s",
            $date_limit
        ));

        return $deleted;
    }
};
