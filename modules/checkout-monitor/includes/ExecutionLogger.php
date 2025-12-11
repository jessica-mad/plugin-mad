<?php
namespace MAD_Suite\CheckoutMonitor;

if ( ! defined('ABSPATH') ) exit;

class ExecutionLogger {

    private $database;
    private $current_session_id;
    private $current_events = [];

    public function __construct(Database $database){
        $this->database = $database;
        $this->init_session();
    }

    private function init_session(){
        // Obtener o crear session_id
        $this->current_session_id = $this->get_or_create_session_id();

        // Verificar si ya existe la sesión
        $existing_session = $this->database->get_session_by_id($this->current_session_id);

        if ( !$existing_session ) {
            // Crear nueva sesión
            $browser_data = $this->get_browser_data();

            $this->database->create_session([
                'session_id' => $this->current_session_id,
                'status' => 'initiated',
                'browser_data' => json_encode($browser_data),
                'ip_address' => $this->get_client_ip(),
                'user_id' => get_current_user_id(),
            ]);
        }
    }

    private function get_or_create_session_id(){
        // Primero intentar obtener de WooCommerce
        if ( function_exists('WC') && WC()->session ) {
            $wc_session = WC()->session->get_customer_id();
            if ( $wc_session ) {
                return 'wc_' . $wc_session;
            }
        }

        // Si no hay sesión de WC, usar cookie
        if ( isset($_COOKIE['checkout_monitor_session']) ) {
            return sanitize_text_field($_COOKIE['checkout_monitor_session']);
        }

        // Crear nuevo ID
        $session_id = uniqid('cm_', true);
        setcookie('checkout_monitor_session', $session_id, time() + 3600, '/');
        return $session_id;
    }

    public function get_session_id(){
        return $this->current_session_id;
    }

    private function get_browser_data(){
        return [
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
            'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
            'accept_language' => isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '',
            'accept_encoding' => isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '',
        ];
    }

    private function get_client_ip(){
        $ip = '';
        if ( isset($_SERVER['HTTP_CLIENT_IP']) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( isset($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif ( isset($_SERVER['REMOTE_ADDR']) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }

    /* ==== Logging de Eventos ==== */
    public function log_hook_start($hook_name, $callback, $priority = 10){
        $callback_info = $this->parse_callback($callback);

        $event_data = [
            'session_id' => $this->current_session_id,
            'event_type' => 'hook',
            'hook_name' => $hook_name,
            'priority' => $priority,
            'callback_name' => $callback_info['name'],
            'plugin_name' => $callback_info['plugin'],
            'file_path' => $callback_info['file'],
            'line_number' => $callback_info['line'],
            'started_at' => $this->get_microtime_mysql(),
            'memory_usage' => memory_get_usage(true),
        ];

        $event_id = $this->database->create_event($event_data);

        // Incrementar contador de hooks
        $this->database->increment_hook_count($this->current_session_id);

        return $event_id;
    }

    public function log_hook_end($event_id, $result = null, $error = null){
        $started = microtime(true);
        $memory = memory_get_usage(true);

        $update_data = [
            'completed_at' => $this->get_microtime_mysql(),
            'memory_usage' => $memory,
        ];

        if ( $error ) {
            $update_data['has_error'] = 1;
            $update_data['error_message'] = $error['message'];
            $update_data['error_trace'] = json_encode($error['trace']);

            // Incrementar contador de errores
            $this->database->increment_error_count($this->current_session_id);
        }

        if ( $result !== null ) {
            $update_data['event_data'] = json_encode(['result' => $this->sanitize_result($result)]);
        }

        $this->database->update_event($event_id, $update_data);

        // Calcular execution time
        $this->calculate_execution_time($event_id);
    }

    private function calculate_execution_time($event_id){
        global $wpdb;
        $events_table = $wpdb->prefix . 'checkout_monitor_events';

        $wpdb->query($wpdb->prepare(
            "UPDATE $events_table
            SET execution_time_ms = TIMESTAMPDIFF(MICROSECOND, started_at, completed_at) / 1000
            WHERE id = %d AND completed_at IS NOT NULL",
            $event_id
        ));
    }

    public function log_error($error_data){
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        $event_data = [
            'session_id' => $this->current_session_id,
            'event_type' => 'error',
            'has_error' => 1,
            'error_message' => isset($error_data['message']) ? $error_data['message'] : 'Unknown error',
            'error_trace' => json_encode($backtrace),
            'started_at' => $this->get_microtime_mysql(),
            'completed_at' => $this->get_microtime_mysql(),
            'event_data' => json_encode($error_data),
        ];

        if ( isset($error_data['file']) ) {
            $event_data['file_path'] = $error_data['file'];
        }

        if ( isset($error_data['line']) ) {
            $event_data['line_number'] = $error_data['line'];
        }

        $this->database->create_event($event_data);
        $this->database->increment_error_count($this->current_session_id);
    }

    /* ==== Helpers ==== */
    private function parse_callback($callback){
        $info = [
            'name' => 'unknown',
            'plugin' => null,
            'file' => null,
            'line' => null,
        ];

        if ( is_string($callback) ) {
            $info['name'] = $callback;
        } elseif ( is_array($callback) ) {
            if ( is_object($callback[0]) ) {
                $info['name'] = get_class($callback[0]) . '::' . $callback[1];
            } else {
                $info['name'] = $callback[0] . '::' . $callback[1];
            }
        } elseif ( is_object($callback) ) {
            if ( $callback instanceof \Closure ) {
                $info['name'] = 'Closure';
                try {
                    $reflection = new \ReflectionFunction($callback);
                    $info['file'] = $reflection->getFileName();
                    $info['line'] = $reflection->getStartLine();
                } catch ( \Exception $e ) {
                    // Ignore reflection errors
                }
            } else {
                $info['name'] = get_class($callback);
            }
        }

        // Detectar plugin
        if ( $info['file'] ) {
            $info['plugin'] = $this->detect_plugin_from_file($info['file']);
        } elseif ( is_array($callback) && is_object($callback[0]) ) {
            try {
                $reflection = new \ReflectionClass($callback[0]);
                $info['file'] = $reflection->getFileName();
                $info['line'] = $reflection->getMethod($callback[1])->getStartLine();
                $info['plugin'] = $this->detect_plugin_from_file($info['file']);
            } catch ( \Exception $e ) {
                // Ignore reflection errors
            }
        } elseif ( is_string($callback) && function_exists($callback) ) {
            try {
                $reflection = new \ReflectionFunction($callback);
                $info['file'] = $reflection->getFileName();
                $info['line'] = $reflection->getStartLine();
                $info['plugin'] = $this->detect_plugin_from_file($info['file']);
            } catch ( \Exception $e ) {
                // Ignore reflection errors
            }
        }

        return $info;
    }

    private function detect_plugin_from_file($file){
        if ( !$file ) return null;

        $plugins_dir = WP_PLUGIN_DIR;
        $mu_plugins_dir = WPMU_PLUGIN_DIR;

        if ( strpos($file, $plugins_dir) === 0 ) {
            $relative = str_replace($plugins_dir . '/', '', $file);
            $parts = explode('/', $relative);
            return $parts[0];
        }

        if ( strpos($file, $mu_plugins_dir) === 0 ) {
            return 'mu-plugins';
        }

        if ( strpos($file, get_template_directory()) === 0 ) {
            return 'theme-' . get_template();
        }

        return null;
    }

    private function get_microtime_mysql(){
        $microtime = microtime(true);
        $datetime = \DateTime::createFromFormat('U.u', sprintf('%.6F', $microtime));
        if ( $datetime === false ) {
            // Fallback si falla la creación con microsegundos
            return current_time('mysql', true);
        }
        return $datetime->format('Y-m-d H:i:s.u');
    }

    private function sanitize_result($result){
        if ( is_scalar($result) ) {
            return $result;
        }

        if ( is_array($result) ) {
            return array_map([$this, 'sanitize_result'], array_slice($result, 0, 10));
        }

        if ( is_object($result) ) {
            if ( method_exists($result, '__toString') ) {
                return (string) $result;
            }
            return get_class($result);
        }

        return gettype($result);
    }

    /* ==== Session Management ==== */
    public function update_order_info($order_id, $order_uid = null){
        if ( !function_exists('wc_get_order') ) return;

        $order = wc_get_order($order_id);

        if ( !$order ) return;

        $this->database->update_session($this->current_session_id, [
            'order_id' => $order_id,
            'order_uid' => $order_uid ?: $order->get_order_key(),
            'payment_method' => $order->get_payment_method(),
            'total_amount' => $order->get_total(),
        ]);
    }

    public function complete_session($status = 'completed'){
        $this->database->complete_session($this->current_session_id, $status);
    }

    public function fail_session(){
        $this->database->update_session($this->current_session_id, [
            'status' => 'failed',
            'completed_at' => current_time('mysql', true),
        ]);
    }
}
