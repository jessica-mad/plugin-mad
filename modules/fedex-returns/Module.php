<?php
/**
 * Módulo: FedEx Returns Integration
 *
 * Permite crear devoluciones en FedEx directamente desde pedidos de WooCommerce,
 * incluyendo la factura de devolución como borrador.
 */

if (!defined('ABSPATH')) exit;

return new class($core ?? null) implements MAD_Suite_Module {
    private $core;
    private $slug = 'fedex-returns';
    private $logger;
    private $fedex_api;
    private $return_manager;
    private $invoice_handler;

    public function __construct($core) {
        $this->core = $core;

        // Cargar clases auxiliares
        require_once __DIR__ . '/classes/Logger.php';
        require_once __DIR__ . '/classes/FedExAPI.php';
        require_once __DIR__ . '/classes/ReturnManager.php';
        require_once __DIR__ . '/classes/InvoiceHandler.php';

        $this->logger = new MAD_FedEx_Returns_Logger();
        $this->fedex_api = new MAD_FedEx_API($this->get_settings(), $this->logger);
        $this->return_manager = new MAD_FedEx_Return_Manager($this->fedex_api, $this->logger);
        $this->invoice_handler = new MAD_FedEx_Invoice_Handler($this->logger);
    }

    public function slug() {
        return $this->slug;
    }

    public function title() {
        return __('FedEx Returns', 'mad-suite');
    }

    public function menu_label() {
        return __('FedEx Returns', 'mad-suite');
    }

    public function menu_slug() {
        return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug;
    }

    public function description() {
        return __('Integración con FedEx para crear devoluciones directamente desde pedidos de WooCommerce.', 'mad-suite');
    }

    public function required_plugins() {
        return [
            'WooCommerce' => 'woocommerce/woocommerce.php'
        ];
    }

    /**
     * Inicialización del módulo
     */
    public function init() {
        // Agregar metabox en pedidos
        add_action('add_meta_boxes', [$this, 'add_order_metabox']);

        // AJAX handlers
        add_action('wp_ajax_mad_fedex_create_return', [$this, 'ajax_create_return']);
        add_action('wp_ajax_mad_fedex_check_return_status', [$this, 'ajax_check_return_status']);
        add_action('wp_ajax_mad_fedex_test_connection', [$this, 'ajax_test_connection']);

        // Agregar columna en lista de pedidos
        add_filter('manage_edit-shop_order_columns', [$this, 'add_order_column'], 20);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_order_column'], 10, 2);

        // Agregar acciones rápidas en pedidos
        add_filter('woocommerce_admin_order_actions', [$this, 'add_order_action'], 100, 2);
    }

    /**
     * Inicialización del admin
     */
    public function admin_init() {
        $option_key = MAD_Suite_Core::option_key($this->slug());
        register_setting($this->menu_slug(), $option_key, [$this, 'sanitize_settings']);

        // Cargar estilos y scripts del admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Handler para guardar configuración
        add_action('admin_post_mads_fedex_returns_save', [$this, 'handle_save_settings']);

        // AJAX handler para limpiar logs
        add_action('wp_ajax_mad_fedex_clear_logs', [$this, 'ajax_clear_logs']);

        // AJAX handler para leer logs
        add_action('wp_ajax_mad_fedex_read_log', [$this, 'ajax_read_log']);
    }

    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_die(__('No tienes permisos suficientes.', 'mad-suite'));
        }

        $settings = $this->get_settings();
        $tabs = [
            'general' => __('Configuración General', 'mad-suite'),
            'api' => __('Credenciales FedEx', 'mad-suite'),
            'defaults' => __('Valores por Defecto', 'mad-suite'),
            'logs' => __('Logs', 'mad-suite'),
        ];

        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        $this->render_view('settings', [
            'tabs' => $tabs,
            'current_tab' => $current_tab,
            'settings' => $settings,
            'module' => $this,
        ]);
    }

    /**
     * Obtener configuración actual
     */
    public function get_settings() {
        $defaults = [
            // Configuración general
            'enable_auto_invoice' => 1,
            'allow_partial_returns' => 1,
            'require_return_reason' => 1,

            // Credenciales FedEx API
            'fedex_api_key' => '',
            'fedex_api_secret' => '',
            'fedex_account_number' => '',
            'fedex_meter_number' => '',
            'fedex_environment' => 'test', // test o production

            // Valores por defecto
            'default_service_type' => 'FEDEX_GROUND',
            'default_packaging_type' => 'YOUR_PACKAGING',
            'default_weight_unit' => 'KG',
            'default_dimension_unit' => 'CM',

            // Información del remitente (para devoluciones)
            'sender_name' => '',
            'sender_company' => '',
            'sender_phone' => '',
            'sender_address_line1' => '',
            'sender_address_line2' => '',
            'sender_city' => '',
            'sender_state' => '',
            'sender_postal_code' => '',
            'sender_country' => '',

            // Opciones de factura
            'invoice_logo_url' => '',
            'invoice_footer_text' => '',

            // Logs
            'enable_logging' => 1,
            'log_api_requests' => 1,
        ];

        $option_key = MAD_Suite_Core::option_key($this->slug());
        $settings = get_option($option_key, []);

        return wp_parse_args($settings, $defaults);
    }

    /**
     * Sanitizar configuración
     */
    public function sanitize_settings($input) {
        $sanitized = [];

        // Configuración general
        if (isset($input['enable_auto_invoice'])) {
            $sanitized['enable_auto_invoice'] = !empty($input['enable_auto_invoice']) ? 1 : 0;
        }
        if (isset($input['allow_partial_returns'])) {
            $sanitized['allow_partial_returns'] = !empty($input['allow_partial_returns']) ? 1 : 0;
        }
        if (isset($input['require_return_reason'])) {
            $sanitized['require_return_reason'] = !empty($input['require_return_reason']) ? 1 : 0;
        }

        // Credenciales FedEx
        if (isset($input['fedex_api_key'])) {
            $sanitized['fedex_api_key'] = sanitize_text_field($input['fedex_api_key']);
        }
        if (isset($input['fedex_api_secret'])) {
            $sanitized['fedex_api_secret'] = sanitize_text_field($input['fedex_api_secret']);
        }
        if (isset($input['fedex_account_number'])) {
            $sanitized['fedex_account_number'] = sanitize_text_field($input['fedex_account_number']);
        }
        if (isset($input['fedex_meter_number'])) {
            $sanitized['fedex_meter_number'] = sanitize_text_field($input['fedex_meter_number']);
        }
        if (isset($input['fedex_environment'])) {
            $sanitized['fedex_environment'] = in_array($input['fedex_environment'], ['test', 'production'])
                ? $input['fedex_environment']
                : 'test';
        }

        // Valores por defecto
        if (isset($input['default_service_type'])) {
            $sanitized['default_service_type'] = sanitize_text_field($input['default_service_type']);
        }
        if (isset($input['default_packaging_type'])) {
            $sanitized['default_packaging_type'] = sanitize_text_field($input['default_packaging_type']);
        }
        if (isset($input['default_weight_unit'])) {
            $sanitized['default_weight_unit'] = sanitize_text_field($input['default_weight_unit']);
        }
        if (isset($input['default_dimension_unit'])) {
            $sanitized['default_dimension_unit'] = sanitize_text_field($input['default_dimension_unit']);
        }

        // Información del remitente
        $sender_fields = [
            'sender_name', 'sender_company', 'sender_phone',
            'sender_address_line1', 'sender_address_line2',
            'sender_city', 'sender_state', 'sender_postal_code', 'sender_country'
        ];
        foreach ($sender_fields as $field) {
            if (isset($input[$field])) {
                $sanitized[$field] = sanitize_text_field($input[$field]);
            }
        }

        // Opciones de factura
        if (isset($input['invoice_logo_url'])) {
            $sanitized['invoice_logo_url'] = esc_url_raw($input['invoice_logo_url']);
        }
        if (isset($input['invoice_footer_text'])) {
            $sanitized['invoice_footer_text'] = sanitize_textarea_field($input['invoice_footer_text']);
        }

        // Logs
        if (isset($input['enable_logging'])) {
            $sanitized['enable_logging'] = !empty($input['enable_logging']) ? 1 : 0;
        }
        if (isset($input['log_api_requests'])) {
            $sanitized['log_api_requests'] = !empty($input['log_api_requests']) ? 1 : 0;
        }

        return $sanitized;
    }

    /**
     * Handler para guardar configuración
     */
    public function handle_save_settings() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_die(__('No tienes permisos suficientes.', 'mad-suite'));
        }

        check_admin_referer('mads_fedex_returns_save', 'mads_fedex_returns_nonce');

        $option_key = MAD_Suite_Core::option_key($this->slug());
        $existing_settings = $this->get_settings();
        $input = $_POST[$option_key] ?? [];
        $sanitized = $this->sanitize_settings($input);
        $merged_settings = array_merge($existing_settings, $sanitized);

        update_option($option_key, $merged_settings);

        // Log de cambio de configuración
        $this->logger->log('Configuración actualizada por usuario ' . wp_get_current_user()->user_login);

        $redirect_url = add_query_arg([
            'page' => $this->menu_slug(),
            'updated' => 'true',
            'tab' => $_POST['current_tab'] ?? 'general',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Agregar metabox en pedidos
     */
    public function add_order_metabox() {
        add_meta_box(
            'mad_fedex_returns',
            __('FedEx Returns', 'mad-suite'),
            [$this, 'render_order_metabox'],
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Renderizar metabox en pedidos
     */
    public function render_order_metabox($post) {
        $order = wc_get_order($post->ID);
        if (!$order) {
            return;
        }

        $return_data = $this->return_manager->get_order_return_data($order->get_id());
        $has_return = !empty($return_data);

        $this->render_view('order-metabox', [
            'order' => $order,
            'return_data' => $return_data,
            'has_return' => $has_return,
            'settings' => $this->get_settings(),
        ]);
    }

    /**
     * AJAX: Crear devolución en FedEx
     */
    public function ajax_create_return() {
        check_ajax_referer('mad_fedex_create_return', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mad-suite')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $return_items = isset($_POST['return_items']) ? json_decode(stripslashes($_POST['return_items']), true) : [];
        $return_reason = isset($_POST['return_reason']) ? sanitize_textarea_field($_POST['return_reason']) : '';
        $weight = isset($_POST['weight']) ? floatval($_POST['weight']) : 0;
        $dimensions = isset($_POST['dimensions']) ? json_decode(stripslashes($_POST['dimensions']), true) : [];

        if (!$order_id) {
            wp_send_json_error(['message' => __('ID de pedido inválido.', 'mad-suite')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Pedido no encontrado.', 'mad-suite')]);
        }

        // Validar que hay items para devolver
        if (empty($return_items)) {
            wp_send_json_error(['message' => __('Debes seleccionar al menos un producto para devolver.', 'mad-suite')]);
        }

        // Crear factura de devolución si está habilitado
        $invoice_url = '';
        $settings = $this->get_settings();
        if ($settings['enable_auto_invoice']) {
            $invoice_result = $this->invoice_handler->create_return_invoice($order, $return_items, $return_reason);
            if (!is_wp_error($invoice_result)) {
                $invoice_url = $invoice_result['url'];
            } else {
                $this->logger->log(sprintf(
                    'Error al crear factura de devolución para pedido #%d: %s',
                    $order_id,
                    $invoice_result->get_error_message()
                ));
            }
        }

        // Crear devolución en FedEx
        $result = $this->return_manager->create_return(
            $order,
            $return_items,
            $return_reason,
            $weight,
            $dimensions,
            $invoice_url
        );

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message()
            ]);
        }

        // Log exitoso
        $this->logger->log(sprintf(
            'Devolución creada exitosamente para pedido #%d - Tracking: %s',
            $order_id,
            $result['tracking_number'] ?? 'N/A'
        ));

        wp_send_json_success([
            'message' => __('Devolución creada exitosamente en FedEx.', 'mad-suite'),
            'data' => $result
        ]);
    }

    /**
     * AJAX: Verificar estado de devolución
     */
    public function ajax_check_return_status() {
        check_ajax_referer('mad_fedex_check_status', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mad-suite')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(['message' => __('ID de pedido inválido.', 'mad-suite')]);
        }

        $return_data = $this->return_manager->get_order_return_data($order_id);
        if (!$return_data) {
            wp_send_json_error(['message' => __('No hay devolución registrada para este pedido.', 'mad-suite')]);
        }

        $tracking_number = $return_data['tracking_number'] ?? '';
        if (!$tracking_number) {
            wp_send_json_error(['message' => __('No se encontró número de seguimiento.', 'mad-suite')]);
        }

        // Consultar estado en FedEx
        $status = $this->fedex_api->track_shipment($tracking_number);

        if (is_wp_error($status)) {
            wp_send_json_error(['message' => $status->get_error_message()]);
        }

        wp_send_json_success([
            'status' => $status
        ]);
    }

    /**
     * AJAX: Probar conexión con FedEx
     */
    public function ajax_test_connection() {
        check_ajax_referer('mad_fedex_test_connection', 'nonce');

        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mad-suite')]);
        }

        $result = $this->fedex_api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message()
            ]);
        }

        wp_send_json_success([
            'message' => __('Conexión exitosa con FedEx API.', 'mad-suite'),
            'data' => $result
        ]);
    }

    /**
     * AJAX: Limpiar logs antiguos
     */
    public function ajax_clear_logs() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mad-suite')]);
        }

        check_ajax_referer('mad_fedex_clear_logs', 'nonce');

        $days = isset($_POST['days']) ? absint($_POST['days']) : 30;

        $this->logger->cleanup_old_logs($days);

        wp_send_json_success([
            'message' => sprintf(__('Logs anteriores a %d días eliminados correctamente.', 'mad-suite'), $days)
        ]);
    }

    /**
     * Agregar columna en lista de pedidos
     */
    public function add_order_column($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_status') {
                $new_columns['fedex_return'] = __('FedEx Return', 'mad-suite');
            }
        }
        return $new_columns;
    }

    /**
     * Renderizar columna en lista de pedidos
     */
    public function render_order_column($column, $post_id) {
        if ($column === 'fedex_return') {
            $return_data = $this->return_manager->get_order_return_data($post_id);
            if ($return_data) {
                $tracking = $return_data['tracking_number'] ?? __('N/A', 'mad-suite');
                $status = $return_data['status'] ?? __('Pendiente', 'mad-suite');
                echo '<span class="fedex-return-status">';
                echo '<strong>' . esc_html($status) . '</strong><br>';
                echo '<small>' . esc_html($tracking) . '</small>';
                echo '</span>';
            } else {
                echo '<span class="dashicons dashicons-minus"></span>';
            }
        }
    }

    /**
     * Agregar acción rápida en pedidos
     */
    public function add_order_action($actions, $order) {
        $return_data = $this->return_manager->get_order_return_data($order->get_id());

        if (!$return_data) {
            $actions['fedex_create_return'] = [
                'url' => '#',
                'name' => __('Crear devolución FedEx', 'mad-suite'),
                'action' => 'fedex_create_return',
            ];
        }

        return $actions;
    }

    /**
     * Cargar scripts y estilos del admin
     */
    public function enqueue_admin_scripts($hook) {
        $valid_hooks = [
            'post.php',
            'post-new.php',
            'edit.php',
        ];

        $is_settings_page = strpos($hook, $this->menu_slug()) !== false;
        $is_order_page = in_array($hook, $valid_hooks) &&
                         isset($_GET['post_type']) &&
                         $_GET['post_type'] === 'shop_order';

        if (!$is_settings_page && !$is_order_page && $hook !== 'shop_order') {
            return;
        }

        // Estilos
        wp_enqueue_style(
            'mad-fedex-returns-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            '1.0.0'
        );

        // Scripts
        wp_enqueue_script(
            'mad-fedex-returns-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            ['jquery'],
            '1.0.0',
            true
        );

        // Localizar script
        wp_localize_script('mad-fedex-returns-admin', 'madFedExReturns', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'create_return_nonce' => wp_create_nonce('mad_fedex_create_return'),
            'check_status_nonce' => wp_create_nonce('mad_fedex_check_status'),
            'test_connection_nonce' => wp_create_nonce('mad_fedex_test_connection'),
            'clear_logs_nonce' => wp_create_nonce('mad_fedex_clear_logs'),
            'strings' => [
                'confirm_create' => __('¿Estás seguro de que deseas crear una devolución en FedEx?', 'mad-suite'),
                'processing' => __('Procesando...', 'mad-suite'),
                'error' => __('Error', 'mad-suite'),
                'success' => __('Éxito', 'mad-suite'),
            ]
        ]);
    }

    /**
     * Renderizar una vista
     */
    private function render_view($view_name, $data = []) {
        $view_file = __DIR__ . '/views/' . $view_name . '.php';
        if (!file_exists($view_file)) {
            wp_die(sprintf(__('La vista %s no existe.', 'mad-suite'), $view_name));
        }
        extract($data, EXTR_SKIP);
        include $view_file;
    }

    /**
     * Obtener archivos de log
     */
    public function get_log_files() {
        return $this->logger->get_log_files();
    }

    /**
     * Leer contenido de un archivo de log
     */
    public function read_log_file($file_path) {
        return $this->logger->read_log($file_path);
    }

    /**
     * AJAX: Leer archivo de log
     */
    public function ajax_read_log() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mad-suite')]);
        }

        check_ajax_referer('mad_fedex_clear_logs', 'nonce');

        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';

        if (empty($file_path)) {
            wp_send_json_error(['message' => __('Ruta de archivo no especificada.', 'mad-suite')]);
        }

        $content = $this->logger->read_log($file_path);

        if ($content === false) {
            wp_send_json_error(['message' => __('No se pudo leer el archivo de log.', 'mad-suite')]);
        }

        wp_send_json_success([
            'content' => $content
        ]);
    }
};
