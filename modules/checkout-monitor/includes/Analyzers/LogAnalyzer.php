<?php
namespace MAD_Suite\CheckoutMonitor\Analyzers;

use MAD_Suite\CheckoutMonitor\Database;

if ( ! defined('ABSPATH') ) exit;

class LogAnalyzer {

    private $database;
    private $log_paths = [];

    public function __construct(Database $database){
        $this->database = $database;
        $this->discover_log_paths();
    }

    private function discover_log_paths(){
        // WooCommerce logs
        $wc_log_dir = WC_LOG_DIR;
        if ( is_dir($wc_log_dir) ) {
            $this->log_paths['woocommerce'] = $wc_log_dir;
        }

        // WordPress debug log
        if ( defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) {
            if ( is_string(WP_DEBUG_LOG) ) {
                $this->log_paths['wordpress_debug'] = WP_DEBUG_LOG;
            } else {
                $this->log_paths['wordpress_debug'] = WP_CONTENT_DIR . '/debug.log';
            }
        }

        // Apache/Nginx logs (si son accesibles)
        $possible_server_logs = [
            '/var/log/apache2/error.log',
            '/var/log/httpd/error_log',
            '/var/log/nginx/error.log',
            '/home/*/logs/*.log', // Hosting compartido
        ];

        foreach ( $possible_server_logs as $log_path ) {
            if ( file_exists($log_path) && is_readable($log_path) ) {
                $this->log_paths['server'] = $log_path;
                break;
            }
        }
    }

    public function analyze_logs_for_session($session_id, $timestamp_start, $timestamp_end = null){
        if ( !$timestamp_end ) {
            $timestamp_end = time();
        }

        $logs_found = [];

        // Analizar logs de WooCommerce
        $wc_logs = $this->analyze_woocommerce_logs($timestamp_start, $timestamp_end);
        if ( !empty($wc_logs) ) {
            $logs_found['woocommerce'] = $wc_logs;
            $this->store_logs($session_id, 'woocommerce', $wc_logs);
        }

        // Analizar WordPress debug log
        $wp_logs = $this->analyze_wordpress_debug_log($timestamp_start, $timestamp_end);
        if ( !empty($wp_logs) ) {
            $logs_found['wordpress'] = $wp_logs;
            $this->store_logs($session_id, 'wordpress', $wp_logs);
        }

        // Analizar logs del servidor
        $server_logs = $this->analyze_server_logs($timestamp_start, $timestamp_end);
        if ( !empty($server_logs) ) {
            $logs_found['server'] = $server_logs;
            $this->store_logs($session_id, 'server', $server_logs);
        }

        // Analizar logs de plugins específicos (Mailchimp, etc)
        $plugin_logs = $this->analyze_plugin_logs($timestamp_start, $timestamp_end);
        if ( !empty($plugin_logs) ) {
            $logs_found['plugins'] = $plugin_logs;
            $this->store_logs($session_id, 'plugins', $plugin_logs);
        }

        return $logs_found;
    }

    private function analyze_woocommerce_logs($timestamp_start, $timestamp_end){
        if ( !isset($this->log_paths['woocommerce']) ) {
            return [];
        }

        $log_dir = $this->log_paths['woocommerce'];
        $logs = [];

        // Buscar archivos de log en el directorio
        $log_files = glob($log_dir . '/*.log');

        foreach ( $log_files as $log_file ) {
            // Solo analizar logs modificados en el rango de tiempo
            $file_mtime = filemtime($log_file);
            if ( $file_mtime < $timestamp_start - 3600 ) { // 1 hora de margen
                continue;
            }

            $log_entries = $this->parse_woocommerce_log($log_file, $timestamp_start, $timestamp_end);
            if ( !empty($log_entries) ) {
                $logs[basename($log_file)] = [
                    'file_path' => $log_file,
                    'file_size' => filesize($log_file),
                    'entries' => $log_entries,
                ];
            }
        }

        return $logs;
    }

    private function parse_woocommerce_log($log_file, $timestamp_start, $timestamp_end){
        if ( !is_readable($log_file) ) {
            return [];
        }

        $entries = [];
        $handle = fopen($log_file, 'r');

        if ( !$handle ) {
            return [];
        }

        while ( ($line = fgets($handle)) !== false ) {
            // Formato típico de WC: 2025-12-11T10:21:01+00:00 LEVEL [source] message
            if ( preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})\s+(\w+)\s+(.+)$/', $line, $matches) ) {
                $log_timestamp = strtotime($matches[1]);

                if ( $log_timestamp >= $timestamp_start && $log_timestamp <= $timestamp_end ) {
                    $entries[] = [
                        'timestamp' => $matches[1],
                        'level' => $matches[2],
                        'message' => $matches[3],
                        'raw' => $line,
                    ];
                }
            }
        }

        fclose($handle);

        return $entries;
    }

    private function analyze_wordpress_debug_log($timestamp_start, $timestamp_end){
        if ( !isset($this->log_paths['wordpress_debug']) ) {
            return [];
        }

        $log_file = $this->log_paths['wordpress_debug'];

        if ( !file_exists($log_file) || !is_readable($log_file) ) {
            return [];
        }

        return [
            'debug.log' => [
                'file_path' => $log_file,
                'file_size' => filesize($log_file),
                'entries' => $this->parse_generic_php_log($log_file, $timestamp_start, $timestamp_end),
            ],
        ];
    }

    private function analyze_server_logs($timestamp_start, $timestamp_end){
        if ( !isset($this->log_paths['server']) ) {
            return [];
        }

        $log_file = $this->log_paths['server'];

        if ( !file_exists($log_file) || !is_readable($log_file) ) {
            return [];
        }

        return [
            'server_error.log' => [
                'file_path' => $log_file,
                'file_size' => filesize($log_file),
                'entries' => $this->parse_server_error_log($log_file, $timestamp_start, $timestamp_end),
            ],
        ];
    }

    private function analyze_plugin_logs($timestamp_start, $timestamp_end){
        $plugin_logs = [];

        // Mailchimp for WooCommerce
        $mailchimp_log_dir = WP_CONTENT_DIR . '/uploads/mailchimp-woocommerce-logs';
        if ( is_dir($mailchimp_log_dir) ) {
            $mailchimp_logs = glob($mailchimp_log_dir . '/*.log');
            foreach ( $mailchimp_logs as $log_file ) {
                $file_mtime = filemtime($log_file);
                if ( $file_mtime >= $timestamp_start - 3600 ) {
                    $plugin_logs['mailchimp/' . basename($log_file)] = [
                        'file_path' => $log_file,
                        'file_size' => filesize($log_file),
                        'entries' => $this->parse_generic_php_log($log_file, $timestamp_start, $timestamp_end),
                    ];
                }
            }
        }

        return $plugin_logs;
    }

    private function parse_generic_php_log($log_file, $timestamp_start, $timestamp_end){
        if ( !is_readable($log_file) ) {
            return [];
        }

        $entries = [];
        $handle = fopen($log_file, 'r');

        if ( !$handle ) {
            return [];
        }

        $current_entry = null;

        while ( ($line = fgets($handle)) !== false ) {
            // Formato PHP: [11-Dec-2025 10:21:01 UTC] PHP Warning: ...
            if ( preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}[^\]]*)\]\s+(.+)$/', $line, $matches) ) {
                // Si hay una entrada anterior, guardarla
                if ( $current_entry ) {
                    $entries[] = $current_entry;
                }

                $log_timestamp = strtotime($matches[1]);
                $current_entry = [
                    'timestamp' => $matches[1],
                    'message' => $matches[2],
                    'raw' => $line,
                    'in_range' => ($log_timestamp >= $timestamp_start && $log_timestamp <= $timestamp_end),
                ];
            } elseif ( $current_entry ) {
                // Línea de continuación (stack trace, etc)
                $current_entry['raw'] .= $line;
                $current_entry['message'] .= "\n" . trim($line);
            }
        }

        // Guardar la última entrada
        if ( $current_entry ) {
            $entries[] = $current_entry;
        }

        fclose($handle);

        // Filtrar solo las que están en el rango
        return array_filter($entries, function($entry) {
            return $entry['in_range'];
        });
    }

    private function parse_server_error_log($log_file, $timestamp_start, $timestamp_end){
        if ( !is_readable($log_file) ) {
            return [];
        }

        $entries = [];
        $handle = fopen($log_file, 'r');

        if ( !$handle ) {
            return [];
        }

        while ( ($line = fgets($handle)) !== false ) {
            // Formato Apache: [Thu Dec 11 11:21:03.263192 2025] [proxy_fcgi:error] ...
            if ( preg_match('/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+(.+)$/', $line, $matches) ) {
                $log_timestamp = strtotime($matches[1]);

                if ( $log_timestamp >= $timestamp_start && $log_timestamp <= $timestamp_end ) {
                    $entries[] = [
                        'timestamp' => $matches[1],
                        'level' => $matches[2],
                        'message' => $matches[3],
                        'raw' => $line,
                    ];
                }
            }
        }

        fclose($handle);

        return $entries;
    }

    private function store_logs($session_id, $source, $logs){
        foreach ( $logs as $log_name => $log_data ) {
            foreach ( $log_data['entries'] as $entry ) {
                $this->database->create_server_log([
                    'session_id' => $session_id,
                    'log_type' => $this->determine_log_type($entry['message']),
                    'log_source' => $source . '/' . $log_name,
                    'log_file_path' => $log_data['file_path'],
                    'file_size' => $log_data['file_size'],
                    'log_content' => $entry['raw'],
                    'log_level' => isset($entry['level']) ? $entry['level'] : 'unknown',
                    'timestamp' => isset($entry['timestamp']) ? date('Y-m-d H:i:s', strtotime($entry['timestamp'])) : current_time('mysql'),
                ]);
            }
        }
    }

    private function determine_log_type($message){
        $message_lower = strtolower($message);

        if ( strpos($message_lower, 'fatal') !== false ) return 'fatal';
        if ( strpos($message_lower, 'error') !== false ) return 'error';
        if ( strpos($message_lower, 'warning') !== false ) return 'warning';
        if ( strpos($message_lower, 'notice') !== false ) return 'notice';
        if ( strpos($message_lower, 'debug') !== false ) return 'debug';

        return 'info';
    }

    public function get_all_log_files(){
        $all_logs = [];

        // WooCommerce logs
        if ( isset($this->log_paths['woocommerce']) && is_dir($this->log_paths['woocommerce']) ) {
            $wc_logs = glob($this->log_paths['woocommerce'] . '/*.log');
            foreach ( $wc_logs as $log ) {
                $all_logs[] = [
                    'source' => 'WooCommerce',
                    'file' => basename($log),
                    'path' => $log,
                    'size' => filesize($log),
                    'modified' => filemtime($log),
                ];
            }
        }

        // WordPress debug
        if ( isset($this->log_paths['wordpress_debug']) && file_exists($this->log_paths['wordpress_debug']) ) {
            $all_logs[] = [
                'source' => 'WordPress',
                'file' => 'debug.log',
                'path' => $this->log_paths['wordpress_debug'],
                'size' => filesize($this->log_paths['wordpress_debug']),
                'modified' => filemtime($this->log_paths['wordpress_debug']),
            ];
        }

        // Server logs
        if ( isset($this->log_paths['server']) && file_exists($this->log_paths['server']) ) {
            $all_logs[] = [
                'source' => 'Server',
                'file' => basename($this->log_paths['server']),
                'path' => $this->log_paths['server'],
                'size' => filesize($this->log_paths['server']),
                'modified' => filemtime($this->log_paths['server']),
            ];
        }

        return $all_logs;
    }
}
