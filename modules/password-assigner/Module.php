<?php
/**
 * Módulo: Password Assigner
 *
 * Sistema de protección por contraseña para el front-end del sitio.
 * Permite bloquear el acceso al sitio web completo hasta que se ingrese
 * una contraseña correcta. Incluye opciones de horario y configuración avanzada.
 */

if (!defined('ABSPATH')) exit;

return new class($core ?? null) implements MAD_Suite_Module {
    private $core;
    private $slug = 'password-assigner';

    public function __construct($core) {
        $this->core = $core;
    }

    public function slug() {
        return $this->slug;
    }

    public function title() {
        return __('Asignador de Contraseña', 'mad-suite');
    }

    public function menu_label() {
        return __('Protección Web', 'mad-suite');
    }

    public function menu_slug() {
        return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug;
    }

    /**
     * Descripción del módulo (opcional)
     */
    public function description() {
        return __('Sistema de protección por contraseña para el front-end del sitio. Bloquea el acceso a la web hasta ingresar una contraseña correcta, con opciones de horario.', 'mad-suite');
    }

    /**
     * Plugins requeridos (opcional)
     */
    public function required_plugins() {
        return []; // No requiere plugins adicionales
    }

    /**
     * Inicialización del módulo (front-end y admin)
     */
    public function init() {
        // Registrar shortcode para el formulario de contraseña
        add_shortcode('password_access_form', [$this, 'render_password_form_shortcode']);

        // Hook para bloquear el acceso al front-end
        add_action('template_redirect', [$this, 'check_site_access'], 1);

        // Cargar estilos en el front-end
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);

        // Handler para procesar el formulario de contraseña
        add_action('template_redirect', [$this, 'handle_password_submission']);

        // Acción de logout
        add_action('template_redirect', [$this, 'handle_logout']);
    }

    /**
     * Inicialización del admin
     */
    public function admin_init() {
        $option_key = MAD_Suite_Core::option_key($this->slug());

        // Registrar ajustes
        register_setting($this->menu_slug(), $option_key, [$this, 'sanitize_settings']);

        // Cargar estilos y scripts del admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Handler para guardar configuración
        add_action('admin_post_mads_password_assigner_save', [$this, 'handle_save_settings']);

        // AJAX handler para obtener permalink de página
        add_action('wp_ajax_get_page_permalink', [$this, 'ajax_get_page_permalink']);
    }

    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        $this->ensure_capability();

        $settings = $this->get_settings();
        $tabs = [
            'general' => __('Configuración General', 'mad-suite'),
            'schedule' => __('Horarios', 'mad-suite'),
            'advanced' => __('Avanzado', 'mad-suite'),
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
    private function get_settings() {
        $defaults = [
            'enabled' => 0,
            'password' => '',
            'session_duration' => 24, // horas

            // Multiidioma WPML
            'enable_wpml' => 0,
            'custom_message' => __('Por favor, ingresa la contraseña para acceder al sitio.', 'mad-suite'),
            'custom_message_en' => 'Please enter the password to access the site.',

            // Horarios
            'enable_schedule' => 0,
            'schedule_type' => 'recurring', // 'recurring' o 'specific'
            'schedule_start' => '09:00',
            'schedule_end' => '18:00',
            'schedule_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'schedule_timezone' => 'America/New_York',
            'schedule_date_start' => '',
            'schedule_date_end' => '',

            // URLs y páginas
            'redirect_url' => '',
            'exclude_admin' => 1,
            'exclude_urls' => '',
            'exclude_pages' => [], // IDs de páginas a excluir

            // IPs en whitelist
            'whitelist_ips' => '',
            'enable_whitelist' => 0,
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

        // General
        $sanitized['enabled'] = isset($input['enabled']) ? 1 : 0;
        $sanitized['password'] = sanitize_text_field($input['password'] ?? '');
        $sanitized['session_duration'] = absint($input['session_duration'] ?? 24);

        // Multiidioma WPML
        $sanitized['enable_wpml'] = isset($input['enable_wpml']) ? 1 : 0;
        $sanitized['custom_message'] = sanitize_textarea_field($input['custom_message'] ?? '');
        $sanitized['custom_message_en'] = sanitize_textarea_field($input['custom_message_en'] ?? '');

        // Horarios
        $sanitized['enable_schedule'] = isset($input['enable_schedule']) ? 1 : 0;
        $sanitized['schedule_type'] = sanitize_key($input['schedule_type'] ?? 'recurring');
        $sanitized['schedule_start'] = sanitize_text_field($input['schedule_start'] ?? '09:00');
        $sanitized['schedule_end'] = sanitize_text_field($input['schedule_end'] ?? '18:00');
        $sanitized['schedule_days'] = isset($input['schedule_days']) && is_array($input['schedule_days'])
            ? array_map('sanitize_key', $input['schedule_days'])
            : [];
        $sanitized['schedule_timezone'] = sanitize_text_field($input['schedule_timezone'] ?? 'America/New_York');
        $sanitized['schedule_date_start'] = sanitize_text_field($input['schedule_date_start'] ?? '');
        $sanitized['schedule_date_end'] = sanitize_text_field($input['schedule_date_end'] ?? '');

        // URLs y páginas
        $sanitized['redirect_url'] = esc_url_raw($input['redirect_url'] ?? '');
        $sanitized['exclude_admin'] = isset($input['exclude_admin']) ? 1 : 0;
        $sanitized['exclude_urls'] = sanitize_textarea_field($input['exclude_urls'] ?? '');
        $sanitized['exclude_pages'] = isset($input['exclude_pages']) && is_array($input['exclude_pages'])
            ? array_map('absint', $input['exclude_pages'])
            : [];

        // IPs en whitelist
        $sanitized['enable_whitelist'] = isset($input['enable_whitelist']) ? 1 : 0;
        $sanitized['whitelist_ips'] = sanitize_textarea_field($input['whitelist_ips'] ?? '');

        return $sanitized;
    }

    /**
     * Handler para guardar configuración
     */
    public function handle_save_settings() {
        $this->ensure_capability();
        check_admin_referer('mads_password_assigner_save', 'mads_password_assigner_nonce');

        $option_key = MAD_Suite_Core::option_key($this->slug());
        $input = $_POST[$option_key] ?? [];
        $sanitized = $this->sanitize_settings($input);

        update_option($option_key, $sanitized);

        $redirect_url = add_query_arg([
            'page' => $this->menu_slug(),
            'tab' => sanitize_key($_POST['current_tab'] ?? 'general'),
            'updated' => 'true',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Verificar si el sitio debe estar protegido en este momento
     */
    private function should_protect_site() {
        // Bypass de emergencia: si la constante está definida, nunca proteger
        if (defined('MADS_PASSWORD_DISABLE') && MADS_PASSWORD_DISABLE === true) {
            return false;
        }

        $settings = $this->get_settings();

        // Si no está habilitado, no proteger
        if (empty($settings['enabled'])) {
            return false;
        }

        // Si no hay contraseña configurada, no proteger
        if (empty($settings['password'])) {
            return false;
        }

        // Si el horario está habilitado, verificar si estamos dentro del horario
        if (!empty($settings['enable_schedule'])) {
            return $this->is_within_schedule($settings);
        }

        // Por defecto, proteger
        return true;
    }

    /**
     * Verificar si estamos dentro del horario configurado
     */
    private function is_within_schedule($settings) {
        $timezone = new \DateTimeZone($settings['schedule_timezone']);
        $now = new \DateTime('now', $timezone);

        // Verificar tipo de horario
        if ($settings['schedule_type'] === 'specific') {
            // Horario específico (fechas)
            return $this->is_within_specific_schedule($settings, $now);
        } else {
            // Horario recurrente (días de la semana)
            return $this->is_within_recurring_schedule($settings, $now);
        }
    }

    /**
     * Verificar horario recurrente (días de la semana)
     */
    private function is_within_recurring_schedule($settings, $now) {
        $current_day = strtolower($now->format('l'));
        $current_time = $now->format('H:i');

        // Verificar si hoy está en los días configurados
        if (!in_array($current_day, $settings['schedule_days'])) {
            return false;
        }

        // Verificar si estamos dentro del rango de horas
        $start_time = $settings['schedule_start'];
        $end_time = $settings['schedule_end'];

        if ($current_time >= $start_time && $current_time <= $end_time) {
            return true;
        }

        return false;
    }

    /**
     * Verificar horario específico (fechas)
     */
    private function is_within_specific_schedule($settings, $now) {
        if (empty($settings['schedule_date_start']) || empty($settings['schedule_date_end'])) {
            return false;
        }

        $timezone = new \DateTimeZone($settings['schedule_timezone']);
        $start_datetime = new \DateTime($settings['schedule_date_start'] . ' ' . $settings['schedule_start'], $timezone);
        $end_datetime = new \DateTime($settings['schedule_date_end'] . ' ' . $settings['schedule_end'], $timezone);

        if ($now >= $start_datetime && $now <= $end_datetime) {
            return true;
        }

        return false;
    }

    /**
     * Verificar si el usuario tiene acceso
     */
    private function user_has_access() {
        // Verificar sesión
        if (!session_id()) {
            session_start();
        }

        $settings = $this->get_settings();
        $session_key = 'mads_password_access_granted';
        $session_time_key = 'mads_password_access_time';

        // Verificar si existe la sesión y no ha expirado
        if (isset($_SESSION[$session_key]) && $_SESSION[$session_key] === true) {
            $access_time = $_SESSION[$session_time_key] ?? 0;
            $session_duration = $settings['session_duration'] * 3600; // convertir horas a segundos

            if ((time() - $access_time) < $session_duration) {
                return true;
            } else {
                // Sesión expirada
                unset($_SESSION[$session_key]);
                unset($_SESSION[$session_time_key]);
            }
        }

        return false;
    }

    /**
     * Otorgar acceso al usuario
     */
    private function grant_access() {
        if (!session_id()) {
            session_start();
        }

        $_SESSION['mads_password_access_granted'] = true;
        $_SESSION['mads_password_access_time'] = time();
    }

    /**
     * Verificar si la IP actual está en whitelist
     */
    private function is_ip_whitelisted() {
        $settings = $this->get_settings();

        // Si la whitelist no está habilitada, no verificar
        if (empty($settings['enable_whitelist'])) {
            return false;
        }

        // Si no hay IPs configuradas, no permitir
        if (empty($settings['whitelist_ips'])) {
            return false;
        }

        $current_ip = $this->get_client_ip();
        $whitelist = array_filter(array_map('trim', explode("\n", $settings['whitelist_ips'])));

        foreach ($whitelist as $allowed_ip) {
            // Soporte para rangos CIDR
            if (strpos($allowed_ip, '/') !== false) {
                if ($this->ip_in_range($current_ip, $allowed_ip)) {
                    return true;
                }
            } else {
                // Comparación directa
                if ($current_ip === $allowed_ip) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Obtener la IP del cliente
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Si hay múltiples IPs, tomar la primera
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Verificar si una IP está en un rango CIDR
     */
    private function ip_in_range($ip, $range) {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        list($subnet, $bits) = explode('/', $range);
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet_long &= $mask;

        return ($ip_long & $mask) === $subnet_long;
    }

    /**
     * Verificar si la URL actual debe ser excluida
     */
    private function is_excluded_url($current_url) {
        $settings = $this->get_settings();

        // Si no hay URLs excluidas, no excluir nada
        if (empty($settings['exclude_urls'])) {
            return false;
        }

        $excluded_urls = array_filter(array_map('trim', explode("\n", $settings['exclude_urls'])));

        foreach ($excluded_urls as $excluded) {
            if (strpos($current_url, $excluded) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si la página actual debe ser excluida
     */
    private function is_excluded_page() {
        $settings = $this->get_settings();

        // Si no hay páginas excluidas, no excluir
        if (empty($settings['exclude_pages'])) {
            return false;
        }

        // Obtener el ID de la página actual
        $current_page_id = get_the_ID();

        if (!$current_page_id) {
            return false;
        }

        return in_array($current_page_id, $settings['exclude_pages']);
    }

    /**
     * Bloquear acceso al sitio si es necesario
     */
    public function check_site_access() {
        // No bloquear en el admin
        if (is_admin()) {
            return;
        }

        $settings = $this->get_settings();

        // Excluir administradores si está configurado
        if (!empty($settings['exclude_admin']) && current_user_can('manage_options')) {
            return;
        }

        // Verificar si la IP está en whitelist
        if ($this->is_ip_whitelisted()) {
            return;
        }

        // Verificar si debemos proteger el sitio
        if (!$this->should_protect_site()) {
            return;
        }

        // Verificar si el usuario ya tiene acceso
        if ($this->user_has_access()) {
            return;
        }

        // Obtener URL actual
        $current_url = $_SERVER['REQUEST_URI'] ?? '';

        // Excluir URLs específicas
        if ($this->is_excluded_url($current_url)) {
            return;
        }

        // Excluir páginas específicas
        if ($this->is_excluded_page()) {
            return;
        }

        // Excluir la página de login para evitar bucles infinitos
        if (!empty($settings['redirect_url'])) {
            $redirect_page_id = url_to_postid($settings['redirect_url']);
            if ($redirect_page_id && is_page($redirect_page_id)) {
                return;
            }
        }

        // Redirigir a la página de login
        if (!empty($settings['redirect_url'])) {
            wp_safe_redirect($settings['redirect_url']);
            exit;
        } else {
            // Si no hay página configurada, mostrar mensaje simple
            $message = $this->get_message_for_current_language($settings);
            wp_die(
                esc_html($message),
                esc_html__('Acceso Restringido', 'mad-suite'),
                ['response' => 403]
            );
        }
    }

    /**
     * Obtener mensaje según el idioma actual (WPML)
     */
    private function get_message_for_current_language($settings) {
        // Si WPML no está habilitado, usar mensaje por defecto
        if (empty($settings['enable_wpml'])) {
            return $settings['custom_message'];
        }

        // Verificar si la URL contiene /en/
        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        $is_english = (strpos($current_url, '/en/') !== false);

        if ($is_english && !empty($settings['custom_message_en'])) {
            return $settings['custom_message_en'];
        }

        return $settings['custom_message'];
    }

    /**
     * Handler para procesar el formulario de contraseña
     */
    public function handle_password_submission() {
        if (!isset($_POST['mads_password_submit'])) {
            return;
        }

        check_admin_referer('mads_password_form', 'mads_password_nonce');

        $settings = $this->get_settings();
        $submitted_password = $_POST['mads_password'] ?? '';

        if ($submitted_password === $settings['password']) {
            // Contraseña correcta
            $this->grant_access();

            // Redirigir al home
            wp_safe_redirect(home_url('/'));
            exit;
        } else {
            // Contraseña incorrecta - redirigir con error
            $current_url = $_SERVER['REQUEST_URI'] ?? '';
            $redirect_url = add_query_arg('password_error', '1', $current_url);
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Handler para cerrar sesión
     */
    public function handle_logout() {
        if (!isset($_GET['mads_password_logout'])) {
            return;
        }

        if (!session_id()) {
            session_start();
        }

        unset($_SESSION['mads_password_access_granted']);
        unset($_SESSION['mads_password_access_time']);

        $redirect_url = remove_query_arg('mads_password_logout', home_url('/'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Shortcode para el formulario de contraseña
     */
    public function render_password_form_shortcode($atts) {
        $atts = shortcode_atts([
            'message' => '',
        ], $atts, 'password_access_form');

        ob_start();
        $this->render_view('password-form', [
            'settings' => $this->get_settings(),
            'custom_message' => $atts['message'],
            'error' => isset($_GET['password_error']),
        ]);
        return ob_get_clean();
    }

    /**
     * Cargar scripts del frontend
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_style(
            'mads-password-assigner-frontend',
            plugin_dir_url(__FILE__) . 'assets/css/frontend.css',
            [],
            '1.0.0'
        );
    }

    /**
     * Cargar scripts del admin
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, $this->menu_slug()) === false) {
            return;
        }

        wp_enqueue_style(
            'mads-password-assigner-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'mads-password-assigner-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            ['jquery'],
            '1.0.0',
            true
        );
    }

    /**
     * Verificar permisos
     */
    private function ensure_capability() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_die(__('No tienes permisos suficientes para acceder a esta página.', 'mad-suite'));
        }
    }

    /**
     * AJAX handler para obtener el permalink de una página
     */
    public function ajax_get_page_permalink() {
        $this->ensure_capability();

        $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;

        if (!$page_id) {
            wp_send_json_error(['message' => __('ID de página inválido.', 'mad-suite')]);
        }

        $permalink = get_permalink($page_id);

        if (!$permalink) {
            wp_send_json_error(['message' => __('No se pudo obtener el permalink.', 'mad-suite')]);
        }

        wp_send_json_success(['permalink' => $permalink]);
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
};
