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
        // Interceptar todos los critical hooks
        foreach ( $this->critical_hooks as $hook ) {
            $this->monitor_hook($hook);
        }

        // Interceptar hooks dinámicos de order creation
        add_action('all', [$this, 'intercept_all_hooks'], -9999);
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

    /* ==== Track specific checkout steps ==== */
    public function track_checkout_initiated(){
        // Este método se puede llamar desde el frontend JS
        $this->logger->update_session_status('processing');
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
