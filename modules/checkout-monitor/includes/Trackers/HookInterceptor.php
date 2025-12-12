<?php
namespace MAD_Suite\CheckoutMonitor\Trackers;

use MAD_Suite\CheckoutMonitor\ExecutionLogger;

if ( ! defined('ABSPATH') ) exit;

class HookInterceptor {

    private $logger;
    private $monitored_hooks = [];
    private $current_events = [];

    // Hooks críticos de WooCommerce Checkout
    private $critical_hooks = [
        // Inicio del checkout
        'woocommerce_before_checkout_form',
        'woocommerce_checkout_before_customer_details',
        'woocommerce_checkout_billing',
        'woocommerce_checkout_shipping',
        'woocommerce_checkout_after_customer_details',
        'woocommerce_checkout_before_order_review',
        'woocommerce_checkout_order_review',
        'woocommerce_checkout_after_order_review',
        'woocommerce_after_checkout_form',

        // Procesamiento del checkout
        'woocommerce_checkout_process',
        'woocommerce_after_checkout_validation',
        'woocommerce_checkout_order_processed',
        'woocommerce_checkout_order_created',
        'woocommerce_new_order',
        'woocommerce_checkout_update_order_meta',
        'woocommerce_checkout_update_order_review',

        // Payment
        'woocommerce_before_pay_action',
        'woocommerce_checkout_before_payment',
        'woocommerce_review_order_before_payment',
        'woocommerce_review_order_after_payment',
        'woocommerce_checkout_after_payment',

        // Order creation hooks
        'woocommerce_create_order',
        'woocommerce_before_order_object_save',
        'woocommerce_after_order_object_save',

        // Meta data hooks
        'woocommerce_checkout_create_order',
        'woocommerce_checkout_create_order_line_item',
        'woocommerce_new_order_item',

        // Status hooks
        'woocommerce_order_status_pending',
        'woocommerce_order_status_processing',
        'woocommerce_order_status_completed',
        'woocommerce_order_status_failed',

        // Payment processed
        'woocommerce_payment_complete',
        'woocommerce_payment_complete_order_status',
        'woocommerce_pre_payment_complete',

        // AJAX hooks
        'woocommerce_checkout_posted_data',
        'woocommerce_ajax_checkout_process',

        // Errors
        'woocommerce_add_error',
        'woocommerce_add_notice',

        // Session
        'woocommerce_set_cart_cookies',
        'woocommerce_load_cart_from_session',

        // Otros hooks importantes
        'wp_insert_post',
        'save_post',
        'wp_insert_post_data',
    ];

    public function __construct(ExecutionLogger $logger){
        $this->logger = $logger;
    }

    public function activate(){
        // SOLO monitorear hooks críticos específicos (sin interceptar todos)
        // Versión pasiva que no interfiere con la ejecución

        // Hook principal de creación de order
        add_action('woocommerce_checkout_order_created', [$this, 'log_order_created'], 10, 2);
        add_action('woocommerce_new_order', [$this, 'log_new_order'], 10, 1);

        // Hooks de errores y notices - CAPTURAR TODO
        add_action('woocommerce_add_error', [$this, 'log_checkout_error'], 10, 1);
        add_filter('woocommerce_add_notice', [$this, 'log_checkout_notice'], 10, 2);

        // NUEVO: Capturar cuando se agregan notices de forma directa
        add_action('woocommerce_before_checkout_process', [$this, 'log_checkout_start'], 10, 0);

        // Hook de validación (aquí es donde se agregan errores de campos)
        add_action('woocommerce_after_checkout_validation', [$this, 'log_validation_errors'], 10, 2);

        // Hook de proceso completado
        add_action('woocommerce_checkout_order_processed', [$this, 'log_order_processed'], 10, 3);

        // NUEVO: Hooks de pago y errores de pago
        add_action('woocommerce_payment_complete', [$this, 'log_payment_complete'], 10, 1);
        add_action('woocommerce_payment_complete_order_status', [$this, 'log_payment_status'], 10, 3);

        // NUEVO: Capturar errores que se envían al cliente
        add_filter('woocommerce_checkout_posted_data', [$this, 'log_posted_data'], 10, 1);
    }

    private function monitor_hook($hook_name, $priority = -9999){
        // Añadir callback ANTES de cualquier otro
        add_action($hook_name, function() use ($hook_name) {
            $this->before_hook_execution($hook_name);
        }, $priority);

        // Añadir callback DESPUÉS de todos los demás
        add_action($hook_name, function() use ($hook_name) {
            $this->after_hook_execution($hook_name);
        }, 999999);

        $this->monitored_hooks[$hook_name] = true;
    }

    public function intercept_all_hooks($hook_name){
        // Solo interceptar hooks relacionados con checkout
        if ( !$this->should_monitor_hook($hook_name) ) {
            return;
        }

        // Evitar duplicados
        if ( isset($this->monitored_hooks[$hook_name]) ) {
            return;
        }

        $this->monitor_hook($hook_name);
    }

    private function should_monitor_hook($hook_name){
        // Hooks que contienen estas palabras clave
        $keywords = [
            'checkout',
            'order',
            'payment',
            'woocommerce',
            'cart',
        ];

        foreach ( $keywords as $keyword ) {
            if ( strpos($hook_name, $keyword) !== false ) {
                return true;
            }
        }

        return false;
    }

    private function before_hook_execution($hook_name){
        global $wp_filter;

        if ( !isset($wp_filter[$hook_name]) ) {
            return;
        }

        // Registrar inicio del hook
        $this->log_hook_callbacks($hook_name, $wp_filter[$hook_name]);
    }

    private function after_hook_execution($hook_name){
        // Aquí podríamos registrar el fin del hook si es necesario
        // Por ahora, el tracking individual de callbacks es suficiente
    }

    private function log_hook_callbacks($hook_name, $hook_object){
        // Obtener todos los callbacks registrados para este hook
        $callbacks = $hook_object->callbacks;

        foreach ( $callbacks as $priority => $callbacks_at_priority ) {
            foreach ( $callbacks_at_priority as $callback_data ) {
                $this->track_callback_execution($hook_name, $callback_data['function'], $priority);
            }
        }
    }

    private function track_callback_execution($hook_name, $callback, $priority){
        // Crear un wrapper que trackee la ejecución
        $event_id = $this->logger->log_hook_start($hook_name, $callback, $priority);

        // Guardamos el event_id para poder actualizarlo después
        $callback_key = $this->get_callback_key($hook_name, $callback, $priority);
        $this->current_events[$callback_key] = [
            'event_id' => $event_id,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
        ];

        // Añadir un callback que se ejecute DESPUÉS de este callback específico
        // Esto es complicado porque necesitamos saber cuándo termina el callback individual
        // Por ahora, vamos a usar un approach diferente: ejecutar el tracking al final del hook
    }

    private function get_callback_key($hook_name, $callback, $priority){
        if ( is_string($callback) ) {
            return $hook_name . '_' . $callback . '_' . $priority;
        } elseif ( is_array($callback) ) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            return $hook_name . '_' . $class . '::' . $callback[1] . '_' . $priority;
        } elseif ( is_object($callback) ) {
            return $hook_name . '_' . spl_object_hash($callback) . '_' . $priority;
        }

        return $hook_name . '_unknown_' . $priority;
    }

    /* ==== Métodos pasivos de logging (NO interfieren) ==== */

    /**
     * Detecta desde qué archivo/plugin se está ejecutando el hook actual
     */
    private function detect_caller_from_backtrace(){
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);

        // Buscar el primer archivo que NO sea parte de nuestro plugin o WordPress core
        foreach ( $backtrace as $trace ) {
            if ( !isset($trace['file']) ) continue;

            $file = $trace['file'];

            // Ignorar nuestro propio plugin
            if ( strpos($file, 'checkout-monitor') !== false ) continue;

            // Ignorar WordPress core
            if ( strpos($file, 'wp-includes') !== false ) continue;
            if ( strpos($file, 'wp-admin') !== false ) continue;

            // Detectar si es un plugin
            if ( strpos($file, WP_PLUGIN_DIR) === 0 ) {
                $relative = str_replace(WP_PLUGIN_DIR . '/', '', $file);
                $parts = explode('/', $relative);
                $plugin_slug = $parts[0];

                $callback_info = [
                    'file' => $file,
                    'line' => isset($trace['line']) ? $trace['line'] : null,
                    'plugin' => $plugin_slug,
                    'function' => isset($trace['function']) ? $trace['function'] : null,
                    'class' => isset($trace['class']) ? $trace['class'] : null,
                ];

                // Construir nombre del callback
                if ( $callback_info['class'] ) {
                    $callback_info['name'] = $callback_info['class'] . '::' . $callback_info['function'];
                } elseif ( $callback_info['function'] ) {
                    $callback_info['name'] = $callback_info['function'];
                } else {
                    $callback_info['name'] = $plugin_slug;
                }

                return $callback_info;
            }

            // Detectar si es un tema
            if ( strpos($file, get_template_directory()) === 0 || strpos($file, get_stylesheet_directory()) === 0 ) {
                return [
                    'file' => $file,
                    'line' => isset($trace['line']) ? $trace['line'] : null,
                    'plugin' => 'theme-' . get_template(),
                    'function' => isset($trace['function']) ? $trace['function'] : null,
                    'class' => isset($trace['class']) ? $trace['class'] : null,
                    'name' => isset($trace['function']) ? $trace['function'] : 'Theme',
                ];
            }
        }

        // Si no encontramos nada específico, buscar WooCommerce
        foreach ( $backtrace as $trace ) {
            if ( isset($trace['class']) && strpos($trace['class'], 'WC_') === 0 ) {
                return [
                    'file' => isset($trace['file']) ? $trace['file'] : null,
                    'line' => isset($trace['line']) ? $trace['line'] : null,
                    'plugin' => 'woocommerce',
                    'function' => isset($trace['function']) ? $trace['function'] : null,
                    'class' => $trace['class'],
                    'name' => $trace['class'] . '::' . (isset($trace['function']) ? $trace['function'] : 'unknown'),
                ];
            }
        }

        // Fallback
        return [
            'file' => null,
            'line' => null,
            'plugin' => 'unknown',
            'function' => null,
            'class' => null,
            'name' => 'Unknown',
        ];
    }

    public function log_order_created($order, $data){
        try {
            $order_id = is_object($order) ? $order->get_id() : $order;

            // Detectar quién está ejecutando este hook
            $caller = $this->detect_caller_from_backtrace();

            $event_id = $this->logger->log_hook_start('woocommerce_checkout_order_created', $caller, 10);

            $this->logger->update_order_info($order_id);

            $this->logger->log_hook_end($event_id);
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_order_created: ' . $e->getMessage());
        }
    }

    public function log_new_order($order_id){
        try {
            // Detectar quién está ejecutando este hook
            $caller = $this->detect_caller_from_backtrace();

            $event_id = $this->logger->log_hook_start('woocommerce_new_order', $caller, 10);
            $this->logger->log_hook_end($event_id);
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_new_order: ' . $e->getMessage());
        }
    }

    public function log_checkout_error($error_message){
        try {
            $caller = $this->detect_caller_from_backtrace();

            // Capturar información adicional sobre los campos del checkout
            $checkout_data = [];
            $missing_fields = [];
            $filled_fields = [];

            // Analizar los datos POST para identificar qué campos están vacíos
            if ( isset($_POST) && !empty($_POST) ) {
            // Campos obligatorios comunes de WooCommerce
            $required_fields = [
                'billing_first_name' => 'Nombre (facturación)',
                'billing_last_name' => 'Apellido (facturación)',
                'billing_email' => 'Email',
                'billing_phone' => 'Teléfono',
                'billing_address_1' => 'Dirección',
                'billing_city' => 'Ciudad',
                'billing_postcode' => 'Código postal',
                'billing_country' => 'País',
                'billing_state' => 'Provincia/Estado',
                'shipping_first_name' => 'Nombre (envío)',
                'shipping_last_name' => 'Apellido (envío)',
                'shipping_address_1' => 'Dirección (envío)',
                'shipping_city' => 'Ciudad (envío)',
                'shipping_postcode' => 'Código postal (envío)',
                'shipping_country' => 'País (envío)',
                'shipping_state' => 'Provincia/Estado (envío)',
            ];

            foreach ( $required_fields as $field => $label ) {
                $value = isset($_POST[$field]) ? trim($_POST[$field]) : '';

                if ( empty($value) ) {
                    $missing_fields[$field] = $label;
                } else {
                    // Solo guardar que está lleno, no el valor (privacidad)
                    $filled_fields[] = $label;
                }
            }

            // Capturar campos personalizados que puedan estar vacíos
            foreach ( $_POST as $key => $value ) {
                if ( strpos($key, 'billing_') === 0 || strpos($key, 'shipping_') === 0 ) {
                    if ( !isset($required_fields[$key]) && empty(trim($value)) ) {
                        $missing_fields[$key] = $key;
                    }
                }
            }

            // Información general del checkout (sin datos sensibles)
            $checkout_data = [
                'payment_method' => isset($_POST['payment_method']) ? $_POST['payment_method'] : 'unknown',
                'ship_to_different_address' => isset($_POST['ship_to_different_address']) ? 'yes' : 'no',
                'total_post_fields' => count($_POST),
            ];
        }

            // Crear evento de error con información detallada
            $error_data = [
                'message' => $error_message,
                'type' => 'checkout_error',
                'source' => 'woocommerce_add_error',
                'caller_plugin' => $caller['plugin'],
                'caller_file' => $caller['file'],
                'caller_line' => $caller['line'],
                'caller_function' => isset($caller['function']) ? $caller['function'] : null,
                'missing_fields' => $missing_fields,
                'filled_fields_count' => count($filled_fields),
                'checkout_data' => $checkout_data,
            ];

            $this->logger->log_error($error_data);
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_checkout_error: ' . $e->getMessage());
        }
    }

    public function log_checkout_notice($message, $notice_type = 'notice'){
        try {
            // Registrar TODOS los notices (error, notice, success)
            // Esto nos ayuda a capturar errores que se escapan
            $caller = $this->detect_caller_from_backtrace();

            $notice_data = [
                'message' => $message,
                'type' => 'wc_notice_' . $notice_type,
                'notice_type' => $notice_type,
                'source' => 'woocommerce_add_notice',
                'caller_plugin' => $caller['plugin'],
                'caller_file' => $caller['file'],
                'caller_line' => $caller['line'],
            ];

            // Si es error, registrar como error
            if ( $notice_type === 'error' ) {
                $this->logger->log_error($notice_data);
            } else {
                // Si es notice o success, registrar como evento normal
                $event_id = $this->logger->log_hook_start('woocommerce_add_notice', $caller, 10);
                $this->logger->log_hook_end($event_id, $notice_data);
            }
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_checkout_notice: ' . $e->getMessage());
        }

        // SIEMPRE retornar el mensaje sin modificar (es un filter)
        return $message;
    }

    public function log_checkout_start(){
        try {
            $caller = $this->detect_caller_from_backtrace();

            $checkout_data = [
                'payment_method' => isset($_POST['payment_method']) ? $_POST['payment_method'] : 'unknown',
                'terms' => isset($_POST['terms']) ? 'accepted' : 'NOT_ACCEPTED',
                'total_fields' => isset($_POST) ? count($_POST) : 0,
            ];

            $event_id = $this->logger->log_hook_start('woocommerce_before_checkout_process', $caller, 10);
            $this->logger->log_hook_end($event_id, $checkout_data);
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_checkout_start: ' . $e->getMessage());
        }
    }

    public function log_payment_complete($order_id){
        try {
            $caller = $this->detect_caller_from_backtrace();
            $event_id = $this->logger->log_hook_start('woocommerce_payment_complete', $caller, 10);
            $this->logger->update_order_info($order_id);
            $this->logger->log_hook_end($event_id);
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_payment_complete: ' . $e->getMessage());
        }
    }

    public function log_payment_status($status, $order_id, $order){
        try {
            $caller = $this->detect_caller_from_backtrace();
            $event_id = $this->logger->log_hook_start('woocommerce_payment_complete_order_status', $caller, 10);
            $this->logger->log_hook_end($event_id, ['status' => $status, 'order_id' => $order_id]);
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_payment_status: ' . $e->getMessage());
        }
    }

    public function log_posted_data($data){
        try {
            // Solo logging, no modificar datos
            $caller = $this->detect_caller_from_backtrace();

            $summary = [
                'payment_method' => isset($data['payment_method']) ? $data['payment_method'] : 'unknown',
                'terms_accepted' => isset($data['terms']) && $data['terms'] == '1' ? 'YES' : 'NO',
                'billing_email' => isset($data['billing_email']) ? $data['billing_email'] : 'not provided',
                'total_fields' => count($data),
            ];

            $event_id = $this->logger->log_hook_start('woocommerce_checkout_posted_data', $caller, 10);
            $this->logger->log_hook_end($event_id, $summary);
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_posted_data: ' . $e->getMessage());
        }

        // SIEMPRE retornar data sin modificar
        return $data;
    }

    public function log_validation_errors($data, $errors){
        try {
            $caller = $this->detect_caller_from_backtrace();

            // SIEMPRE registrar que la validación se ejecutó (tenga o no errores)
        if ( is_wp_error($errors) && $errors->has_errors() ) {
            // HAY ERRORES - registrar detalladamente
            $error_codes = $errors->get_error_codes();
            $all_errors = [];

            foreach ( $error_codes as $code ) {
                $messages = $errors->get_error_messages($code);
                foreach ( $messages as $message ) {
                    $all_errors[] = [
                        'code' => $code,
                        'message' => $message,
                    ];
                }
            }

            // Registrar cada error de validación
            foreach ( $all_errors as $error ) {
                $this->log_checkout_error($error['message'] . ' (código: ' . $error['code'] . ')');
            }

            // También crear un evento específico con TODOS los errores de validación
            $validation_summary = [
                'message' => 'Errores de validación del checkout (' . count($all_errors) . ' errores)',
                'type' => 'validation_errors',
                'source' => 'woocommerce_after_checkout_validation',
                'caller_plugin' => $caller['plugin'],
                'caller_file' => $caller['file'],
                'caller_line' => $caller['line'],
                'validation_errors' => $all_errors,
                'error_count' => count($all_errors),
            ];

            // Agregar información de campos si hay datos POST
            if ( isset($_POST) && !empty($_POST) ) {
                $validation_summary['posted_data_summary'] = [
                    'payment_method' => isset($_POST['payment_method']) ? $_POST['payment_method'] : 'unknown',
                    'total_fields' => count($_POST),
                    'has_billing_email' => isset($_POST['billing_email']) && !empty($_POST['billing_email']),
                    'has_billing_phone' => isset($_POST['billing_phone']) && !empty($_POST['billing_phone']),
                ];
            }

            $this->logger->log_error($validation_summary);
        } else {
            // NO HAY ERRORES - la validación pasó exitosamente
            // Registrar de todas formas para saber que se validó
            $event_id = $this->logger->log_hook_start('woocommerce_after_checkout_validation', $caller, 10);

            // Registrar información sobre la validación exitosa
            $success_data = [
                'validation_passed' => true,
                'error_count' => 0,
            ];

            // Agregar información de campos validados
            if ( isset($_POST) && !empty($_POST) ) {
                $success_data['posted_data_summary'] = [
                    'payment_method' => isset($_POST['payment_method']) ? $_POST['payment_method'] : 'unknown',
                    'total_fields' => count($_POST),
                    'has_billing_email' => isset($_POST['billing_email']) && !empty($_POST['billing_email']),
                    'has_billing_phone' => isset($_POST['billing_phone']) && !empty($_POST['billing_phone']),
                    'billing_email_value' => isset($_POST['billing_email']) ? $_POST['billing_email'] : '',
                ];
            }

            $this->logger->log_hook_end($event_id, $success_data);
        }
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_validation_errors: ' . $e->getMessage());
        }
    }

    public function log_order_processed($order_id, $posted_data, $order){
        try {
            // Detectar quién está ejecutando este hook
            $caller = $this->detect_caller_from_backtrace();

            $event_id = $this->logger->log_hook_start('woocommerce_checkout_order_processed', $caller, 10);

            $this->logger->complete_session('completed');

            $this->logger->log_hook_end($event_id);
        } catch ( \Exception $e ) {
            // Silenciar errores para no romper el checkout
            error_log('Checkout Monitor - Error log_order_processed: ' . $e->getMessage());
        }
    }

    /* ==== Track specific checkout steps ==== */
    public function track_checkout_initiated(){
        // Este método se puede llamar desde el frontend JS
        // La sesión ya está siendo trackeada por el logger
    }

    public function track_checkout_completed($order_id){
        $this->logger->update_order_info($order_id);
        $this->logger->complete_session('completed');
    }

    public function track_checkout_failed($error){
        $this->logger->log_error([
            'message' => is_string($error) ? $error : 'Checkout failed',
            'type' => 'checkout_failure',
            'data' => $error,
        ]);
        $this->logger->fail_session();
    }
}
