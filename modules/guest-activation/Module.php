<?php
/**
 * Módulo: Guest Activation
 *
 * Permite a usuarios que compraron como invitados activar su cuenta y recuperar sus pedidos.
 * Incluye bloqueo de registro, shortcode de activación, botón en "Mis pedidos" y panel de administración.
 */

if (!defined('ABSPATH')) exit;

return new class($core ?? null) implements MAD_Suite_Module {
    private $core;
    private $slug = 'guest-activation';
    private $logger;

    public function __construct($core) {
        $this->core = $core;
        require_once __DIR__ . '/classes/Logger.php';
        $this->logger = new MAD_Guest_Activation_Logger();
    }

    public function slug() {
        return $this->slug;
    }

    public function title() {
        return __('Activación de Invitados', 'mad-suite');
    }

    public function menu_label() {
        return __('Guest Activation', 'mad-suite');
    }

    public function menu_slug() {
        return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug;
    }

    public function description() {
        return __('Permite a usuarios que compraron como invitados activar su cuenta y recuperar sus pedidos.', 'mad-suite');
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
        // Shortcode de activación
        add_shortcode('mad_guest_activation', [$this, 'render_activation_shortcode']);

        // Hook de bloqueo de registro
        add_action('register_post', [$this, 'block_guest_registration'], 10, 3);

        // Botón en "Mis pedidos"
        add_action('woocommerce_account_dashboard', [$this, 'add_find_orders_button']);

        // AJAX handlers
        add_action('wp_ajax_mad_guest_activation_submit', [$this, 'ajax_handle_activation_submit']);
        add_action('wp_ajax_nopriv_mad_guest_activation_submit', [$this, 'ajax_handle_activation_submit']);

        add_action('wp_ajax_mad_guest_activation_create_account', [$this, 'ajax_handle_create_account']);
        add_action('wp_ajax_nopriv_mad_guest_activation_create_account', [$this, 'ajax_handle_create_account']);

        add_action('wp_ajax_mad_find_previous_orders', [$this, 'ajax_find_previous_orders']);

        // Mostrar pedidos en perfil de usuario
        add_action('show_user_profile', [$this, 'display_user_orders_in_profile']);
        add_action('edit_user_profile', [$this, 'display_user_orders_in_profile']);

        // Cargar estilos y scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
    }

    /**
     * Inicialización del admin
     */
    public function admin_init() {
        $option_key = MAD_Suite_Core::option_key($this->slug());
        register_setting($this->menu_slug(), $option_key, [$this, 'sanitize_settings']);

        // Cargar estilos del admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Handler para guardar configuración
        add_action('admin_post_mads_guest_activation_save', [$this, 'handle_save_settings']);

        // AJAX handler para limpiar logs
        add_action('wp_ajax_mad_guest_activation_clear_logs', [$this, 'ajax_clear_logs']);
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
            'messages' => __('Mensajes', 'mad-suite'),
            'emails' => __('Emails', 'mad-suite'),
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
    private function get_settings() {
        $defaults = [
            // Configuración general
            'activation_page_id' => 0,
            'token_expiration_hours' => 24,

            // Textos personalizables (ES)
            'block_message_es' => __('Ya tienes pedidos registrados con este email. Por favor, activa tu cuenta para acceder a ellos.', 'mad-suite'),
            'email_found_message_es' => __('Te hemos enviado un email con un enlace para activar tu cuenta.', 'mad-suite'),
            'email_not_found_message_es' => __('No se encontraron compras asociadas a este email.', 'mad-suite'),
            'button_text_es' => __('Buscar pedidos previos', 'mad-suite'),
            'orders_found_message_es' => __('Se han encontrado y asociado {count} pedido(s) a tu cuenta.', 'mad-suite'),
            'no_orders_message_es' => __('Ya tienes todos tus pedidos asociados.', 'mad-suite'),

            // Textos personalizables (EN)
            'block_message_en' => 'You already have orders registered with this email. Please activate your account to access them.',
            'email_found_message_en' => 'We have sent you an email with a link to activate your account.',
            'email_not_found_message_en' => 'No purchases found associated with this email.',
            'button_text_en' => 'Find previous orders',
            'orders_found_message_en' => '{count} order(s) found and associated with your account.',
            'no_orders_message_en' => 'You already have all your orders associated.',

            // Email con token (ES)
            'token_email_subject_es' => __('Activa tu cuenta - {site_name}', 'mad-suite'),
            'token_email_body_es' => __('Hola,\n\nHas solicitado activar tu cuenta. Haz clic en el siguiente enlace para establecer tu contraseña:\n\n{activation_link}\n\nEste enlace expirará en {expiration_hours} horas.\n\nGracias,\nEquipo de {site_name}', 'mad-suite'),

            // Email con token (EN)
            'token_email_subject_en' => 'Activate your account - {site_name}',
            'token_email_body_en' => 'Hello,\n\nYou have requested to activate your account. Click the following link to set your password:\n\n{activation_link}\n\nThis link will expire in {expiration_hours} hours.\n\nThank you,\n{site_name} Team',

            // Email de confirmación (ES)
            'confirmation_email_subject_es' => __('Cuenta activada - {site_name}', 'mad-suite'),
            'confirmation_email_body_es' => __('¡Hola!\n\nTu cuenta ha sido activada exitosamente. Ya puedes acceder a todos tus pedidos.\n\nGracias,\nEquipo de {site_name}', 'mad-suite'),

            // Email de confirmación (EN)
            'confirmation_email_subject_en' => 'Account activated - {site_name}',
            'confirmation_email_body_en' => 'Hello!\n\nYour account has been successfully activated. You can now access all your orders.\n\nThank you,\n{site_name} Team',

            // WPML
            'enable_wpml' => 0,
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
        if (isset($input['activation_page_id'])) {
            $sanitized['activation_page_id'] = absint($input['activation_page_id']);
        }
        if (isset($input['token_expiration_hours'])) {
            $sanitized['token_expiration_hours'] = absint($input['token_expiration_hours']);
        }

        // Textos (ES)
        if (isset($input['block_message_es'])) {
            $sanitized['block_message_es'] = sanitize_textarea_field($input['block_message_es']);
        }
        if (isset($input['email_found_message_es'])) {
            $sanitized['email_found_message_es'] = sanitize_textarea_field($input['email_found_message_es']);
        }
        if (isset($input['email_not_found_message_es'])) {
            $sanitized['email_not_found_message_es'] = sanitize_textarea_field($input['email_not_found_message_es']);
        }
        if (isset($input['button_text_es'])) {
            $sanitized['button_text_es'] = sanitize_text_field($input['button_text_es']);
        }
        if (isset($input['orders_found_message_es'])) {
            $sanitized['orders_found_message_es'] = sanitize_textarea_field($input['orders_found_message_es']);
        }
        if (isset($input['no_orders_message_es'])) {
            $sanitized['no_orders_message_es'] = sanitize_textarea_field($input['no_orders_message_es']);
        }

        // Textos (EN)
        if (isset($input['block_message_en'])) {
            $sanitized['block_message_en'] = sanitize_textarea_field($input['block_message_en']);
        }
        if (isset($input['email_found_message_en'])) {
            $sanitized['email_found_message_en'] = sanitize_textarea_field($input['email_found_message_en']);
        }
        if (isset($input['email_not_found_message_en'])) {
            $sanitized['email_not_found_message_en'] = sanitize_textarea_field($input['email_not_found_message_en']);
        }
        if (isset($input['button_text_en'])) {
            $sanitized['button_text_en'] = sanitize_text_field($input['button_text_en']);
        }
        if (isset($input['orders_found_message_en'])) {
            $sanitized['orders_found_message_en'] = sanitize_textarea_field($input['orders_found_message_en']);
        }
        if (isset($input['no_orders_message_en'])) {
            $sanitized['no_orders_message_en'] = sanitize_textarea_field($input['no_orders_message_en']);
        }

        // Emails con token (ES)
        if (isset($input['token_email_subject_es'])) {
            $sanitized['token_email_subject_es'] = sanitize_text_field($input['token_email_subject_es']);
        }
        if (isset($input['token_email_body_es'])) {
            $sanitized['token_email_body_es'] = sanitize_textarea_field($input['token_email_body_es']);
        }

        // Emails con token (EN)
        if (isset($input['token_email_subject_en'])) {
            $sanitized['token_email_subject_en'] = sanitize_text_field($input['token_email_subject_en']);
        }
        if (isset($input['token_email_body_en'])) {
            $sanitized['token_email_body_en'] = sanitize_textarea_field($input['token_email_body_en']);
        }

        // Emails de confirmación (ES)
        if (isset($input['confirmation_email_subject_es'])) {
            $sanitized['confirmation_email_subject_es'] = sanitize_text_field($input['confirmation_email_subject_es']);
        }
        if (isset($input['confirmation_email_body_es'])) {
            $sanitized['confirmation_email_body_es'] = sanitize_textarea_field($input['confirmation_email_body_es']);
        }

        // Emails de confirmación (EN)
        if (isset($input['confirmation_email_subject_en'])) {
            $sanitized['confirmation_email_subject_en'] = sanitize_text_field($input['confirmation_email_subject_en']);
        }
        if (isset($input['confirmation_email_body_en'])) {
            $sanitized['confirmation_email_body_en'] = sanitize_textarea_field($input['confirmation_email_body_en']);
        }

        // WPML
        if (isset($input['enable_wpml']) || array_key_exists('enable_wpml', $input)) {
            $sanitized['enable_wpml'] = !empty($input['enable_wpml']) && $input['enable_wpml'] == '1' ? 1 : 0;
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

        check_admin_referer('mads_guest_activation_save', 'mads_guest_activation_nonce');

        $option_key = MAD_Suite_Core::option_key($this->slug());
        $existing_settings = $this->get_settings();
        $input = $_POST[$option_key] ?? [];
        $sanitized = $this->sanitize_settings($input);
        $merged_settings = array_merge($existing_settings, $sanitized);

        update_option($option_key, $merged_settings);

        $redirect_url = add_query_arg([
            'page' => $this->menu_slug(),
            'updated' => 'true',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Bloquear registro para invitados con órdenes
     */
    public function block_guest_registration($sanitized_user_login, $user_email, $errors) {
        // Verificar si hay órdenes como invitado con este email
        $guest_orders = $this->get_guest_orders($user_email);

        if (!empty($guest_orders)) {
            $settings = $this->get_settings();
            $message = $this->get_text_for_current_language('block_message');

            $errors->add('guest_has_orders', $message);

            // Log del intento de registro bloqueado
            $this->logger->log(sprintf(
                'Registro bloqueado para email %s (tiene %d pedidos como invitado)',
                $user_email,
                count($guest_orders)
            ));

            // Redirigir a la página de activación si está configurada
            if (!empty($settings['activation_page_id'])) {
                $activation_url = get_permalink($settings['activation_page_id']);
                if ($activation_url) {
                    add_filter('registration_errors', function($errors) use ($activation_url) {
                        if ($errors->has_errors()) {
                            wp_safe_redirect($activation_url . '?registration_blocked=1');
                            exit;
                        }
                        return $errors;
                    });
                }
            }
        }
    }

    /**
     * Obtener órdenes de invitado por email
     */
    private function get_guest_orders($email) {
        $args = [
            'customer' => $email,
            'limit' => -1,
            'return' => 'ids',
        ];

        $orders = wc_get_orders($args);
        $guest_orders = [];

        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_customer_id() == 0) {
                $guest_orders[] = $order_id;
            }
        }

        return $guest_orders;
    }

    /**
     * Shortcode de activación
     */
    public function render_activation_shortcode($atts) {
        $atts = shortcode_atts([
            'recaptcha_site_key' => '',
        ], $atts, 'mad_guest_activation');

        // Verificar si hay un token en la URL
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        ob_start();

        if ($token) {
            // Mostrar formulario para establecer contraseña
            $token_data = $this->validate_token($token);

            if ($token_data) {
                $this->render_view('token-form', [
                    'token' => $token,
                    'email' => $token_data['email'],
                    'settings' => $this->get_settings(),
                ]);
            } else {
                echo '<div class="mad-guest-activation-error">';
                echo '<p>' . esc_html__('El enlace de activación ha expirado o no es válido.', 'mad-suite') . '</p>';
                echo '</div>';
            }
        } else {
            // Mostrar formulario de solicitud de activación
            $this->render_view('activation-form', [
                'settings' => $this->get_settings(),
                'recaptcha_site_key' => $atts['recaptcha_site_key'],
            ]);
        }

        return ob_get_clean();
    }

    /**
     * AJAX: Manejar solicitud de activación
     */
    public function ajax_handle_activation_submit() {
        check_ajax_referer('mad_guest_activation_nonce', 'nonce');

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => __('Email inválido.', 'mad-suite')]);
        }

        // Buscar órdenes como invitado
        $guest_orders = $this->get_guest_orders($email);

        if (empty($guest_orders)) {
            // No se encontraron órdenes
            $message = $this->get_text_for_current_language('email_not_found_message');

            $this->logger->log(sprintf(
                'Activación solicitada para email %s - No se encontraron pedidos',
                $email
            ));

            wp_send_json_success([
                'found' => false,
                'message' => $message,
            ]);
        }

        // Generar token
        $token = $this->generate_activation_token($email);

        // Enviar email
        $this->send_activation_email($email, $token);

        // Log
        $this->logger->log(sprintf(
            'Token de activación generado para email %s (%d pedidos encontrados)',
            $email,
            count($guest_orders)
        ));

        $message = $this->get_text_for_current_language('email_found_message');

        wp_send_json_success([
            'found' => true,
            'message' => $message,
        ]);
    }

    /**
     * AJAX: Crear cuenta desde token
     */
    public function ajax_handle_create_account() {
        check_ajax_referer('mad_guest_activation_create_nonce', 'nonce');

        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if (empty($token) || empty($password)) {
            wp_send_json_error(['message' => __('Datos incompletos.', 'mad-suite')]);
        }

        // Validar token
        $token_data = $this->validate_token($token);

        if (!$token_data) {
            wp_send_json_error(['message' => __('Token inválido o expirado.', 'mad-suite')]);
        }

        $email = $token_data['email'];

        // Verificar que el usuario no existe
        if (email_exists($email)) {
            wp_send_json_error(['message' => __('Ya existe una cuenta con este email.', 'mad-suite')]);
        }

        // Crear usuario
        $username = $this->generate_username_from_email($email);
        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }

        // Atribuir órdenes al nuevo usuario
        $orders_count = $this->assign_orders_to_user($email, $user_id);

        // Eliminar token
        $this->delete_token($token);

        // Enviar email de confirmación
        $this->send_confirmation_email($email);

        // Log
        $this->logger->log(sprintf(
            'Cuenta activada exitosamente para email %s (Usuario ID: %d, %d pedidos atribuidos)',
            $email,
            $user_id,
            $orders_count
        ));

        // Autenticar automáticamente al usuario
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        wp_send_json_success([
            'message' => __('Cuenta creada exitosamente. Redirigiendo...', 'mad-suite'),
            'redirect_url' => wc_get_account_endpoint_url('orders'),
        ]);
    }

    /**
     * AJAX: Buscar pedidos previos desde "Mis pedidos"
     */
    public function ajax_find_previous_orders() {
        check_ajax_referer('mad_find_orders_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Debes iniciar sesión.', 'mad-suite')]);
        }

        $user = wp_get_current_user();
        $email = $user->user_email;

        // Buscar órdenes no atribuidas con este email
        $unassigned_orders = $this->get_guest_orders($email);

        if (empty($unassigned_orders)) {
            $message = $this->get_text_for_current_language('no_orders_message');

            $this->logger->log(sprintf(
                'Búsqueda de pedidos previos por usuario %s - No se encontraron pedidos sin atribuir',
                $email
            ));

            wp_send_json_success([
                'found' => false,
                'message' => $message,
            ]);
        }

        // Atribuir órdenes
        $orders_count = $this->assign_orders_to_user($email, $user->ID);

        $message = $this->get_text_for_current_language('orders_found_message');
        $message = str_replace('{count}', $orders_count, $message);

        $this->logger->log(sprintf(
            'Pedidos previos atribuidos a usuario %s (%d pedidos)',
            $email,
            $orders_count
        ));

        wp_send_json_success([
            'found' => true,
            'message' => $message,
        ]);
    }

    /**
     * Agregar botón en "Mis pedidos"
     */
    public function add_find_orders_button() {
        if (!is_user_logged_in()) {
            return;
        }

        $button_text = $this->get_text_for_current_language('button_text');

        ?>
        <div class="mad-find-orders-container">
            <button type="button" id="mad-find-orders-btn" class="button">
                <?php echo esc_html($button_text); ?>
            </button>
            <div id="mad-find-orders-message" style="margin-top: 10px;"></div>
        </div>
        <?php
    }

    /**
     * Generar token de activación
     */
    private function generate_activation_token($email) {
        $settings = $this->get_settings();
        $token = wp_generate_password(32, false);
        $token_hash = hash('sha256', $token);

        $token_data = [
            'email' => $email,
            'created_at' => time(),
            'expires_at' => time() + ($settings['token_expiration_hours'] * 3600),
        ];

        set_transient('mad_guest_activation_token_' . $token_hash, $token_data, $settings['token_expiration_hours'] * 3600);

        return $token;
    }

    /**
     * Validar token
     */
    private function validate_token($token) {
        $token_hash = hash('sha256', $token);
        $token_data = get_transient('mad_guest_activation_token_' . $token_hash);

        if (!$token_data) {
            return false;
        }

        if (time() > $token_data['expires_at']) {
            delete_transient('mad_guest_activation_token_' . $token_hash);
            return false;
        }

        return $token_data;
    }

    /**
     * Eliminar token
     */
    private function delete_token($token) {
        $token_hash = hash('sha256', $token);
        delete_transient('mad_guest_activation_token_' . $token_hash);
    }

    /**
     * Enviar email de activación con token
     */
    private function send_activation_email($email, $token) {
        $settings = $this->get_settings();
        $site_name = get_bloginfo('name');
        $activation_url = get_permalink($settings['activation_page_id']);
        $activation_link = add_query_arg('token', $token, $activation_url);

        $subject = $this->get_text_for_current_language('token_email_subject');
        $body = $this->get_text_for_current_language('token_email_body');

        // Reemplazar placeholders
        $subject = str_replace('{site_name}', $site_name, $subject);
        $body = str_replace(
            ['{activation_link}', '{expiration_hours}', '{site_name}'],
            [$activation_link, $settings['token_expiration_hours'], $site_name],
            $body
        );

        // Usar sistema de emails de WooCommerce
        $mailer = WC()->mailer();
        $message = $mailer->wrap_message($subject, $body);

        $mailer->send($email, $subject, $message);
    }

    /**
     * Enviar email de confirmación
     */
    private function send_confirmation_email($email) {
        $site_name = get_bloginfo('name');

        $subject = $this->get_text_for_current_language('confirmation_email_subject');
        $body = $this->get_text_for_current_language('confirmation_email_body');

        // Reemplazar placeholders
        $subject = str_replace('{site_name}', $site_name, $subject);
        $body = str_replace('{site_name}', $site_name, $body);

        // Usar sistema de emails de WooCommerce
        $mailer = WC()->mailer();
        $message = $mailer->wrap_message($subject, $body);

        $mailer->send($email, $subject, $message);
    }

    /**
     * Atribuir órdenes a usuario
     */
    private function assign_orders_to_user($email, $user_id) {
        $guest_orders = $this->get_guest_orders($email);
        $count = 0;

        foreach ($guest_orders as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                // Guardar metadata indicando que fue un pedido de invitado
                $order->update_meta_data('_original_customer_id', 0);
                $order->update_meta_data('_was_guest_order', 'yes');
                $order->update_meta_data('_assigned_date', current_time('mysql'));

                // Asignar al usuario
                $order->set_customer_id($user_id);
                $order->save();
                $count++;
            }
        }

        return $count;
    }

    /**
     * Generar username desde email
     */
    private function generate_username_from_email($email) {
        $username = sanitize_user(current(explode('@', $email)), true);

        // Si el username ya existe, agregar número
        if (username_exists($username)) {
            $i = 1;
            while (username_exists($username . $i)) {
                $i++;
            }
            $username = $username . $i;
        }

        return $username;
    }

    /**
     * Obtener texto según idioma actual
     */
    private function get_text_for_current_language($key) {
        $settings = $this->get_settings();

        // Si WPML no está habilitado, usar versión ES
        if (empty($settings['enable_wpml'])) {
            return $settings[$key . '_es'] ?? '';
        }

        // Verificar si la URL contiene /en/
        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        $is_english = (strpos($current_url, '/en/') !== false);

        if ($is_english && !empty($settings[$key . '_en'])) {
            return $settings[$key . '_en'];
        }

        return $settings[$key . '_es'] ?? '';
    }

    /**
     * Mostrar pedidos en perfil de usuario
     */
    public function display_user_orders_in_profile($user) {
        // Solo mostrar si WooCommerce está activo
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Solo mostrar si el usuario actual tiene permisos
        if (!current_user_can('edit_users')) {
            return;
        }

        $user_id = $user->ID;
        $user_email = $user->user_email;

        // Obtener todos los pedidos del usuario
        $orders_data = $this->get_user_orders_with_metadata($user_id, $user_email);

        if (empty($orders_data)) {
            return;
        }

        // Renderizar vista
        $this->render_view('user-orders-table', [
            'user' => $user,
            'orders_data' => $orders_data,
            'total_orders' => count($orders_data),
        ]);
    }

    /**
     * Obtener pedidos del usuario con metadatos
     */
    private function get_user_orders_with_metadata($user_id, $user_email) {
        $orders_data = [];

        // Obtener todos los pedidos del usuario por ID
        $args = [
            'customer_id' => $user_id,
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $orders = wc_get_orders($args);

        foreach ($orders as $order) {
            $order_data = $this->extract_order_data($order);
            $order_data['was_guest'] = $this->was_guest_order($order, $user_id);
            $orders_data[] = $order_data;
        }

        // También buscar pedidos que tengan el mismo email pero sin customer_id (fueron de invitado y no se asignaron)
        $guest_args = [
            'billing_email' => $user_email,
            'customer_id' => 0,
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        $guest_orders = wc_get_orders($guest_args);

        foreach ($guest_orders as $order) {
            $order_data = $this->extract_order_data($order);
            $order_data['was_guest'] = true;
            $order_data['not_assigned'] = true;
            $orders_data[] = $order_data;
        }

        // Ordenar por fecha descendente
        usort($orders_data, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $orders_data;
    }

    /**
     * Extraer datos relevantes de un pedido
     */
    private function extract_order_data($order) {
        return [
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'date' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'status' => $order->get_status(),
            'status_name' => wc_get_order_status_name($order->get_status()),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'items_count' => $order->get_item_count(),
            'edit_url' => get_edit_post_link($order->get_id()),
            'customer_id' => $order->get_customer_id(),
        ];
    }

    /**
     * Verificar si un pedido fue originalmente de invitado
     */
    private function was_guest_order($order, $current_user_id) {
        // Si el pedido actualmente no tiene customer_id, es de invitado
        if ($order->get_customer_id() == 0) {
            return true;
        }

        // Verificar metadata específica
        $was_guest = $order->get_meta('_was_guest_order', true);
        if ($was_guest === 'yes') {
            return true;
        }

        // Si el customer_id es diferente al usuario actual, podría ser que fue reasignado
        // Verificar si hay metadata que indique que fue de invitado
        $original_customer_id = $order->get_meta('_original_customer_id', true);

        // Si existe metadata y era 0, fue de invitado
        if ($original_customer_id !== '' && $original_customer_id == 0) {
            return true;
        }

        // Si el pedido tiene customer_id pero queremos marcar los que fueron de invitado,
        // verificamos la fecha de creación del usuario vs la fecha del pedido
        $user = get_user_by('id', $current_user_id);
        if ($user) {
            $user_registered = strtotime($user->user_registered);
            $order_date = $order->get_date_created()->getTimestamp();

            // Si el pedido es anterior al registro del usuario, probablemente fue de invitado
            if ($order_date < $user_registered) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cargar scripts del frontend
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_style(
            'mad-guest-activation-frontend',
            plugin_dir_url(__FILE__) . 'assets/css/frontend.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'mad-guest-activation-frontend',
            plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('mad-guest-activation-frontend', 'madGuestActivation', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'activation_nonce' => wp_create_nonce('mad_guest_activation_nonce'),
            'create_nonce' => wp_create_nonce('mad_guest_activation_create_nonce'),
            'find_orders_nonce' => wp_create_nonce('mad_find_orders_nonce'),
        ]);
    }

    /**
     * Cargar scripts del admin
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, $this->menu_slug()) === false) {
            return;
        }

        wp_enqueue_style(
            'mad-guest-activation-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'mad-guest-activation-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('mad-guest-activation-admin', 'madGuestActivationAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'clear_logs_nonce' => wp_create_nonce('mad_guest_activation_clear_logs'),
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
     * Obtener enlace a logs
     */
    public function get_logs_url() {
        return $this->logger->get_logs_url();
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
     * AJAX: Limpiar logs antiguos
     */
    public function ajax_clear_logs() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mad-suite')]);
        }

        check_ajax_referer('mad_guest_activation_clear_logs', 'nonce');

        $days = isset($_POST['days']) ? absint($_POST['days']) : 30;

        $this->logger->cleanup_old_logs($days);

        wp_send_json_success([
            'message' => sprintf(__('Logs anteriores a %d días eliminados correctamente.', 'mad-suite'), $days)
        ]);
    }
};
