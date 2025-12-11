<?php
namespace MAD_Suite\CheckoutMonitor\Trackers;

use MAD_Suite\CheckoutMonitor\Database;

if ( ! defined('ABSPATH') ) exit;

class BrowserTracker {

    private $database;

    public function __construct(Database $database){
        $this->database = $database;

        // AJAX endpoint para recibir datos del navegador
        add_action('wp_ajax_checkout_monitor_track_browser', [$this, 'ajax_track_browser']);
        add_action('wp_ajax_nopriv_checkout_monitor_track_browser', [$this, 'ajax_track_browser']);
    }

    public function ajax_track_browser(){
        check_ajax_referer('checkout_monitor_track', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $browser_data = isset($_POST['browser_data']) ? $_POST['browser_data'] : [];

        if ( empty($session_id) ) {
            wp_send_json_error(['message' => 'Session ID required']);
        }

        // Validar y sanitizar datos del navegador
        $sanitized_data = $this->sanitize_browser_data($browser_data);

        // Actualizar sesión con datos del navegador
        $this->database->update_session($session_id, [
            'browser_data' => json_encode($sanitized_data),
        ]);

        wp_send_json_success(['message' => 'Browser data saved']);
    }

    private function sanitize_browser_data($data){
        $sanitized = [];

        // Basic info
        $sanitized['user_agent'] = isset($data['user_agent']) ? sanitize_text_field($data['user_agent']) : '';
        $sanitized['platform'] = isset($data['platform']) ? sanitize_text_field($data['platform']) : '';
        $sanitized['language'] = isset($data['language']) ? sanitize_text_field($data['language']) : '';
        $sanitized['languages'] = isset($data['languages']) ? array_map('sanitize_text_field', (array) $data['languages']) : [];

        // Screen info
        $sanitized['screen_width'] = isset($data['screen_width']) ? intval($data['screen_width']) : 0;
        $sanitized['screen_height'] = isset($data['screen_height']) ? intval($data['screen_height']) : 0;
        $sanitized['screen_color_depth'] = isset($data['screen_color_depth']) ? intval($data['screen_color_depth']) : 0;
        $sanitized['viewport_width'] = isset($data['viewport_width']) ? intval($data['viewport_width']) : 0;
        $sanitized['viewport_height'] = isset($data['viewport_height']) ? intval($data['viewport_height']) : 0;
        $sanitized['device_pixel_ratio'] = isset($data['device_pixel_ratio']) ? floatval($data['device_pixel_ratio']) : 1;

        // Device detection
        $sanitized['is_mobile'] = isset($data['is_mobile']) ? (bool) $data['is_mobile'] : false;
        $sanitized['is_tablet'] = isset($data['is_tablet']) ? (bool) $data['is_tablet'] : false;
        $sanitized['is_desktop'] = isset($data['is_desktop']) ? (bool) $data['is_desktop'] : false;
        $sanitized['device_type'] = isset($data['device_type']) ? sanitize_text_field($data['device_type']) : 'unknown';

        // Browser capabilities
        $sanitized['cookies_enabled'] = isset($data['cookies_enabled']) ? (bool) $data['cookies_enabled'] : false;
        $sanitized['local_storage'] = isset($data['local_storage']) ? (bool) $data['local_storage'] : false;
        $sanitized['session_storage'] = isset($data['session_storage']) ? (bool) $data['session_storage'] : false;

        // Performance timing
        if ( isset($data['performance']) && is_array($data['performance']) ) {
            $sanitized['performance'] = [
                'navigation_start' => isset($data['performance']['navigation_start']) ? intval($data['performance']['navigation_start']) : 0,
                'dom_complete' => isset($data['performance']['dom_complete']) ? intval($data['performance']['dom_complete']) : 0,
                'load_event_end' => isset($data['performance']['load_event_end']) ? intval($data['performance']['load_event_end']) : 0,
                'page_load_time' => isset($data['performance']['page_load_time']) ? intval($data['performance']['page_load_time']) : 0,
            ];
        }

        // Connection info
        if ( isset($data['connection']) && is_array($data['connection']) ) {
            $sanitized['connection'] = [
                'effective_type' => isset($data['connection']['effective_type']) ? sanitize_text_field($data['connection']['effective_type']) : '',
                'downlink' => isset($data['connection']['downlink']) ? floatval($data['connection']['downlink']) : 0,
                'rtt' => isset($data['connection']['rtt']) ? intval($data['connection']['rtt']) : 0,
                'save_data' => isset($data['connection']['save_data']) ? (bool) $data['connection']['save_data'] : false,
            ];
        }

        // Timezone
        $sanitized['timezone'] = isset($data['timezone']) ? sanitize_text_field($data['timezone']) : '';
        $sanitized['timezone_offset'] = isset($data['timezone_offset']) ? intval($data['timezone_offset']) : 0;

        // Referrer
        $sanitized['referrer'] = isset($data['referrer']) ? esc_url_raw($data['referrer']) : '';

        // Page URL
        $sanitized['page_url'] = isset($data['page_url']) ? esc_url_raw($data['page_url']) : '';

        // JavaScript errors (si las hay)
        if ( isset($data['js_errors']) && is_array($data['js_errors']) ) {
            $sanitized['js_errors'] = array_map(function($error) {
                return [
                    'message' => isset($error['message']) ? sanitize_text_field($error['message']) : '',
                    'source' => isset($error['source']) ? esc_url_raw($error['source']) : '',
                    'lineno' => isset($error['lineno']) ? intval($error['lineno']) : 0,
                    'colno' => isset($error['colno']) ? intval($error['colno']) : 0,
                    'timestamp' => isset($error['timestamp']) ? intval($error['timestamp']) : 0,
                ];
            }, array_slice($data['js_errors'], 0, 10)); // Limitar a 10 errores
        }

        return $sanitized;
    }

    public function detect_device_type($user_agent){
        $mobile_agents = [
            'android', 'iphone', 'ipad', 'ipod', 'blackberry', 'windows phone',
            'mobile', 'tablet', 'kindle', 'silk', 'opera mini'
        ];

        $user_agent_lower = strtolower($user_agent);

        foreach ( $mobile_agents as $agent ) {
            if ( strpos($user_agent_lower, $agent) !== false ) {
                if ( in_array($agent, ['ipad', 'tablet', 'kindle']) ) {
                    return 'tablet';
                }
                return 'mobile';
            }
        }

        return 'desktop';
    }
}
