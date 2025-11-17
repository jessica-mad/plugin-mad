<?php
/**
 * Módulo de Alertas de Límite de Hora para Pedidos
 *
 * Permite configurar alertas que se muestran en el carrito cuando hay un límite
 * de tiempo para recibir el pedido en un día específico.
 *
 * @package MAD_Suite
 * @subpackage Order_Deadline_Alerts
 */

if (!defined('ABSPATH')) {
    exit;
}

return new class($core) implements MAD_Suite_Module {
    private $core;
    private $logger;

    public function __construct($core) {
        $this->core = $core;

        // Cargar el logger
        require_once __DIR__ . '/classes/Logger.php';
        $this->logger = new MAD_Order_Deadline_Alerts_Logger();
    }

    public function slug(): string {
        return 'order-deadline-alerts';
    }

    public function title(): string {
        return __('Alertas de Límite de Pedidos', 'mad-suite');
    }

    public function description(): string {
        return __('Configura alertas con countdown que se muestran en el carrito cuando hay un límite de tiempo para recibir pedidos.', 'mad-suite');
    }

    public function menu_label(): string {
        return __('Alertas de Pedidos', 'mad-suite');
    }

    public function menu_slug(): string {
        return 'mad-suite-order-deadline-alerts';
    }

    public function init(): void {
        // Cargar scripts y estilos del frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);

        // Shortcode para mostrar la alerta
        add_shortcode('mad_order_deadline_alert', [$this, 'render_alert_shortcode']);

        // Hook para mostrar debajo del carrito (WooCommerce)
        add_action('woocommerce_after_cart_totals', [$this, 'render_cart_alert']);

        // AJAX para obtener la alerta activa
        add_action('wp_ajax_mads_oda_get_active_alert', [$this, 'ajax_get_active_alert']);
        add_action('wp_ajax_nopriv_mads_oda_get_active_alert', [$this, 'ajax_get_active_alert']);
    }

    public function admin_init(): void {
        // Registrar settings
        $option_key = MAD_Suite_Core::option_key($this->slug());
        register_setting(
            $option_key . '_group',
            $option_key,
            [$this, 'sanitize_settings']
        );

        // Cargar scripts y estilos del admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Handler para guardar configuración
        add_action('admin_post_mads_oda_save_settings', [$this, 'handle_save_settings']);

        // AJAX handlers para gestión de alertas
        add_action('wp_ajax_mads_oda_save_alert', [$this, 'ajax_save_alert']);
        add_action('wp_ajax_mads_oda_delete_alert', [$this, 'ajax_delete_alert']);
        add_action('wp_ajax_mads_oda_toggle_alert', [$this, 'ajax_toggle_alert']);
    }

    public function render_settings_page(): void {
        $settings = $this->get_settings();
        include __DIR__ . '/views/settings.php';
    }

    /**
     * Obtener configuración del módulo
     */
    private function get_settings(): array {
        $defaults = [
            'enabled' => 0,
            'countdown_format' => 'hh:mm:ss',
            'alerts' => [],
            'excluded_dates' => [],
            'enable_wpml' => 0,
        ];

        $option_key = MAD_Suite_Core::option_key($this->slug());
        $settings = get_option($option_key, []);

        return wp_parse_args($settings, $defaults);
    }

    /**
     * Sanitizar configuración
     */
    public function sanitize_settings($input): array {
        $sanitized = [];

        if (isset($input['enabled'])) {
            $sanitized['enabled'] = absint($input['enabled']);
        }

        if (isset($input['countdown_format'])) {
            $sanitized['countdown_format'] = in_array($input['countdown_format'], ['hh:mm', 'hh:mm:ss'])
                ? $input['countdown_format']
                : 'hh:mm:ss';
        }

        if (isset($input['enable_wpml'])) {
            $sanitized['enable_wpml'] = absint($input['enable_wpml']);
        }

        // Las alertas se guardan vía AJAX, no aquí
        if (isset($input['alerts'])) {
            $sanitized['alerts'] = $input['alerts'];
        }

        if (isset($input['excluded_dates'])) {
            $sanitized['excluded_dates'] = array_map('sanitize_text_field', $input['excluded_dates']);
        }

        return $sanitized;
    }

    /**
     * Handler para guardar configuración general
     */
    public function handle_save_settings(): void {
        check_admin_referer('mads_oda_save_settings', 'mads_oda_nonce');

        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_die(__('No tienes permisos para realizar esta acción.', 'mad-suite'));
        }

        $option_key = MAD_Suite_Core::option_key($this->slug());
        $existing_settings = $this->get_settings();
        $input = $_POST[$option_key] ?? [];

        $sanitized = $this->sanitize_settings($input);
        $merged_settings = array_merge($existing_settings, $sanitized);

        update_option($option_key, $merged_settings);

        $this->logger->log('Configuración general guardada correctamente');

        wp_redirect(add_query_arg(['page' => $this->menu_slug(), 'updated' => 'true'], admin_url('admin.php')));
        exit;
    }

    /**
     * AJAX: Guardar o actualizar una alerta
     */
    public function ajax_save_alert(): void {
        check_ajax_referer('mads_oda_nonce', 'nonce');

        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'mad-suite')]);
        }

        $alert_id = sanitize_text_field($_POST['alert_id'] ?? '');
        $alert_data = [
            'id' => $alert_id ?: uniqid('alert_', true),
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'enabled' => isset($_POST['enabled']) ? (bool)$_POST['enabled'] : true,
            'days' => array_map('absint', $_POST['days'] ?? []),
            'deadline_time' => sanitize_text_field($_POST['deadline_time'] ?? ''),
            'message_es' => wp_kses_post($_POST['message_es'] ?? ''),
            'message_en' => wp_kses_post($_POST['message_en'] ?? ''),
            'delivery_day_offset' => absint($_POST['delivery_day_offset'] ?? 1),
        ];

        // Validación
        if (empty($alert_data['name']) || empty($alert_data['days']) || empty($alert_data['deadline_time'])) {
            wp_send_json_error(['message' => __('Faltan campos requeridos.', 'mad-suite')]);
        }

        $settings = $this->get_settings();
        $alerts = $settings['alerts'] ?? [];

        // Actualizar o agregar
        $found = false;
        foreach ($alerts as $index => $alert) {
            if ($alert['id'] === $alert_data['id']) {
                $alerts[$index] = $alert_data;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $alerts[] = $alert_data;
        }

        $settings['alerts'] = $alerts;
        $option_key = MAD_Suite_Core::option_key($this->slug());
        update_option($option_key, $settings);

        $this->logger->log('Alerta guardada: ' . $alert_data['name']);

        wp_send_json_success([
            'message' => __('Alerta guardada correctamente.', 'mad-suite'),
            'alert' => $alert_data,
        ]);
    }

    /**
     * AJAX: Eliminar una alerta
     */
    public function ajax_delete_alert(): void {
        check_ajax_referer('mads_oda_nonce', 'nonce');

        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'mad-suite')]);
        }

        $alert_id = sanitize_text_field($_POST['alert_id'] ?? '');
        if (empty($alert_id)) {
            wp_send_json_error(['message' => __('ID de alerta no válido.', 'mad-suite')]);
        }

        $settings = $this->get_settings();
        $alerts = $settings['alerts'] ?? [];

        $alerts = array_filter($alerts, function($alert) use ($alert_id) {
            return $alert['id'] !== $alert_id;
        });

        $settings['alerts'] = array_values($alerts);
        $option_key = MAD_Suite_Core::option_key($this->slug());
        update_option($option_key, $settings);

        $this->logger->log('Alerta eliminada: ' . $alert_id);

        wp_send_json_success(['message' => __('Alerta eliminada correctamente.', 'mad-suite')]);
    }

    /**
     * AJAX: Activar/desactivar una alerta
     */
    public function ajax_toggle_alert(): void {
        check_ajax_referer('mads_oda_nonce', 'nonce');

        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_send_json_error(['message' => __('No tienes permisos.', 'mad-suite')]);
        }

        $alert_id = sanitize_text_field($_POST['alert_id'] ?? '');
        if (empty($alert_id)) {
            wp_send_json_error(['message' => __('ID de alerta no válido.', 'mad-suite')]);
        }

        $settings = $this->get_settings();
        $alerts = $settings['alerts'] ?? [];

        foreach ($alerts as $index => $alert) {
            if ($alert['id'] === $alert_id) {
                $alerts[$index]['enabled'] = !($alert['enabled'] ?? true);
                break;
            }
        }

        $settings['alerts'] = $alerts;
        $option_key = MAD_Suite_Core::option_key($this->slug());
        update_option($option_key, $settings);

        $this->logger->log('Alerta alternada: ' . $alert_id);

        wp_send_json_success(['message' => __('Estado de alerta actualizado.', 'mad-suite')]);
    }

    /**
     * AJAX: Obtener alerta activa para el momento actual
     */
    public function ajax_get_active_alert(): void {
        $settings = $this->get_settings();

        if (!$settings['enabled']) {
            wp_send_json_success(['active' => false]);
        }

        $active_alert = $this->get_current_active_alert();

        if ($active_alert) {
            wp_send_json_success([
                'active' => true,
                'alert' => $active_alert,
                'countdown_format' => $settings['countdown_format'],
            ]);
        } else {
            wp_send_json_success(['active' => false]);
        }
    }

    /**
     * Obtener alerta activa para el momento actual
     */
    private function get_current_active_alert(): ?array {
        $settings = $this->get_settings();
        $alerts = $settings['alerts'] ?? [];

        // Zona horaria de Madrid
        $timezone = new DateTimeZone('Europe/Madrid');
        $now = new DateTime('now', $timezone);
        $current_date = $now->format('Y-m-d');

        // Verificar si la fecha actual está excluida
        if (in_array($current_date, $settings['excluded_dates'] ?? [])) {
            return null;
        }

        $current_day = (int)$now->format('N'); // 1 (lunes) a 7 (domingo)

        foreach ($alerts as $alert) {
            // Verificar si la alerta está habilitada
            if (!($alert['enabled'] ?? true)) {
                continue;
            }

            // Verificar si hoy es uno de los días configurados
            if (!in_array($current_day, $alert['days'] ?? [])) {
                continue;
            }

            // Crear objeto DateTime para la hora límite
            $deadline = clone $now;
            $time_parts = explode(':', $alert['deadline_time']);
            $deadline->setTime((int)$time_parts[0], (int)$time_parts[1], 0);

            // Si aún no hemos pasado la hora límite, esta alerta está activa
            if ($now <= $deadline) {
                // Calcular día de entrega
                $delivery_date = clone $now;
                $offset = (int)($alert['delivery_day_offset'] ?? 1);
                $delivery_date->modify("+{$offset} day");

                // Obtener mensaje según idioma
                $message = $this->get_alert_message($alert);

                return [
                    'id' => $alert['id'],
                    'message' => $message,
                    'deadline' => $deadline->format('c'), // ISO 8601
                    'delivery_date' => $delivery_date->format('Y-m-d'),
                ];
            }
        }

        return null;
    }

    /**
     * Obtener mensaje de alerta según idioma actual
     */
    private function get_alert_message(array $alert): string {
        $settings = $this->get_settings();

        if (empty($settings['enable_wpml'])) {
            return $alert['message_es'] ?? '';
        }

        // Detectar idioma desde la URL
        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        $is_english = (strpos($current_url, '/en/') !== false);

        if ($is_english && !empty($alert['message_en'])) {
            return $alert['message_en'];
        }

        return $alert['message_es'] ?? '';
    }

    /**
     * Renderizar alerta en el carrito
     */
    public function render_cart_alert(): void {
        $settings = $this->get_settings();

        if (!$settings['enabled']) {
            return;
        }

        echo '<div id="mad-order-deadline-alert-container"></div>';
    }

    /**
     * Shortcode para mostrar la alerta
     */
    public function render_alert_shortcode($atts): string {
        $settings = $this->get_settings();

        if (!$settings['enabled']) {
            return '';
        }

        return '<div id="mad-order-deadline-alert-container"></div>';
    }

    /**
     * Cargar scripts del frontend
     */
    public function enqueue_frontend_scripts(): void {
        $settings = $this->get_settings();

        if (!$settings['enabled']) {
            return;
        }

        wp_enqueue_style(
            'mads-oda-frontend',
            plugin_dir_url(__FILE__) . 'assets/css/frontend.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'mads-oda-frontend',
            plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('mads-oda-frontend', 'madsOdaL10n', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'countdownFormat' => $settings['countdown_format'],
        ]);
    }

    /**
     * Cargar scripts del admin
     */
    public function enqueue_admin_scripts($hook): void {
        if (strpos($hook, $this->menu_slug()) === false) {
            return;
        }

        wp_enqueue_style(
            'mads-oda-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'mads-oda-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            ['jquery', 'jquery-ui-sortable'],
            '1.0.0',
            true
        );

        wp_localize_script('mads-oda-admin', 'madsOdaAdminL10n', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mads_oda_nonce'),
            'strings' => [
                'confirmDelete' => __('¿Estás seguro de que quieres eliminar esta alerta?', 'mad-suite'),
                'errorSaving' => __('Error al guardar la alerta.', 'mad-suite'),
                'errorDeleting' => __('Error al eliminar la alerta.', 'mad-suite'),
                'requiredFields' => __('Por favor, completa todos los campos requeridos.', 'mad-suite'),
            ],
        ]);
    }
};
