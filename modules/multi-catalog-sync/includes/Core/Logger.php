<?php
namespace MAD_Suite\MultiCatalogSync\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * Logger
 * Handles logging for Multi-Catalog Sync operations
 */
class Logger {

    private $logger;
    private $source = 'multi-catalog-sync';
    private $log_dir;
    private $log_file;

    public function __construct(){
        // WooCommerce logger (if available)
        if (function_exists('wc_get_logger')) {
            $this->logger = wc_get_logger();
        }

        // Simple file logger in uploads
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/mcs-logs';
        $this->log_file = $this->log_dir . '/sync-' . date('Y-m-d') . '.log';

        // Create log directory if it doesn't exist
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);

            // Add .htaccess for security (block direct access)
            $htaccess = $this->log_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }

            // Add index.php to prevent directory listing
            $index = $this->log_dir . '/index.php';
            if (!file_exists($index)) {
                file_put_contents($index, "<?php\n// Silence is golden\n");
            }
        }
    }

    /**
     * Log an info message
     */
    public function info($message, $context = []){
        $this->log('info', $message, $context);
    }

    /**
     * Log a warning message
     */
    public function warning($message, $context = []){
        $this->log('warning', $message, $context);
    }

    /**
     * Log an error message
     */
    public function error($message, $context = []){
        $this->log('error', $message, $context);
    }

    /**
     * Log a debug message
     */
    public function debug($message, $context = []){
        $this->log('debug', $message, $context);
    }

    /**
     * Generic log method
     */
    private function log($level, $message, $context = []){
        // Log to WooCommerce logger
        if ($this->logger) {
            $this->logger->log($level, $message, array_merge([
                'source' => $this->source,
            ], $context));
        }

        // Log to simple file in uploads
        $this->file_log($level, $message, $context);

        // Also log to PHP error log in development
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[MCS:%s] %s', strtoupper($level), $message));
        }
    }

    /**
     * Log to simple file
     */
    private function file_log($level, $message, $context = []){
        $timestamp = date('Y-m-d H:i:s');
        $level_str = strtoupper($level);

        // Format message
        $log_message = sprintf(
            "[%s] [%s] %s",
            $timestamp,
            $level_str,
            $message
        );

        // Add context if provided
        if (!empty($context)) {
            $log_message .= ' | Context: ' . json_encode($context);
        }

        $log_message .= "\n";

        // Write to file
        @file_put_contents($this->log_file, $log_message, FILE_APPEND);

        // Rotate logs if file is too large (> 5MB)
        if (file_exists($this->log_file) && filesize($this->log_file) > 5 * 1024 * 1024) {
            $this->rotate_logs();
        }
    }

    /**
     * Rotate logs (keep last 10 days)
     */
    private function rotate_logs(){
        $files = glob($this->log_dir . '/sync-*.log');

        // Sort by modification time, oldest first
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        // Keep only last 10 files
        $to_delete = count($files) - 10;
        if ($to_delete > 0) {
            for ($i = 0; $i < $to_delete; $i++) {
                @unlink($files[$i]);
            }
        }
    }

    /**
     * Log sync start
     */
    public function log_sync_start($destination, $product_count){
        $this->info(sprintf(
            'Starting sync to %s for %d products',
            $destination,
            $product_count
        ), [
            'destination' => $destination,
            'product_count' => $product_count,
        ]);
    }

    /**
     * Log sync complete
     */
    public function log_sync_complete($destination, $success_count, $error_count, $duration){
        $this->info(sprintf(
            'Sync to %s completed: %d successful, %d errors, %.2f seconds',
            $destination,
            $success_count,
            $error_count,
            $duration
        ), [
            'destination' => $destination,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'duration' => $duration,
        ]);
    }

    /**
     * Log product sync error
     */
    public function log_product_error($product_id, $destination, $error_message){
        $this->error(sprintf(
            'Product %d failed to sync to %s: %s',
            $product_id,
            $destination,
            $error_message
        ), [
            'product_id' => $product_id,
            'destination' => $destination,
            'error' => $error_message,
        ]);

        // Store error in database for dashboard
        $this->store_error($product_id, $destination, $error_message);
    }

    /**
     * Log API request
     */
    public function log_api_request($destination, $method, $endpoint, $data = null){
        $this->debug(sprintf(
            'API Request to %s: %s %s',
            $destination,
            $method,
            $endpoint
        ), [
            'destination' => $destination,
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
        ]);
    }

    /**
     * Log API response
     */
    public function log_api_response($destination, $status_code, $response_body = null){
        $level = ($status_code >= 200 && $status_code < 300) ? 'debug' : 'error';

        $this->log($level, sprintf(
            'API Response from %s: HTTP %d',
            $destination,
            $status_code
        ), [
            'destination' => $destination,
            'status_code' => $status_code,
            'response' => $response_body,
        ]);
    }

    /**
     * Store error in database for dashboard display
     */
    private function store_error($product_id, $destination, $error_message){
        $product = wc_get_product($product_id);
        if (!$product) return;

        $errors = get_option('mcs_sync_errors', []);

        $error_key = $product_id . '_' . $destination;
        $errors[$error_key] = [
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'destination' => $destination,
            'message' => $error_message,
            'timestamp' => current_time('timestamp'),
        ];

        // Keep only last 100 errors
        if (count($errors) > 100) {
            $errors = array_slice($errors, -100, 100, true);
        }

        update_option('mcs_sync_errors', $errors);
    }

    /**
     * Clear errors for a product
     */
    public function clear_product_errors($product_id){
        $errors = get_option('mcs_sync_errors', []);

        foreach ($errors as $key => $error) {
            if ($error['product_id'] == $product_id) {
                unset($errors[$key]);
            }
        }

        update_option('mcs_sync_errors', $errors);
    }

    /**
     * Clear all errors
     */
    public function clear_all_errors(){
        delete_option('mcs_sync_errors');
    }

    /**
     * Get log file path (WooCommerce)
     */
    public function get_log_file_path(){
        return WC_LOG_DIR . 'multi-catalog-sync-' . date('Y-m-d') . '-' . wp_hash('multi-catalog-sync') . '.log';
    }

    /**
     * Get simple log file path (uploads)
     */
    public function get_simple_log_path(){
        return $this->log_file;
    }

    /**
     * Get simple log directory URL
     */
    public function get_log_dir_url(){
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/mcs-logs';
    }

    /**
     * Get list of all log files
     */
    public function get_log_files(){
        $files = glob($this->log_dir . '/sync-*.log');

        // Sort by modification time, newest first
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'path' => $file,
                'name' => basename($file),
                'size' => filesize($file),
                'modified' => filemtime($file),
            ];
        }

        return $result;
    }

    /**
     * Read log file content
     */
    public function read_log($filename = null, $lines = 100){
        if (!$filename) {
            $filename = basename($this->log_file);
        }

        $filepath = $this->log_dir . '/' . $filename;

        if (!file_exists($filepath)) {
            return '';
        }

        // Read last N lines
        $file = file($filepath);
        $total_lines = count($file);
        $start = max(0, $total_lines - $lines);

        return implode('', array_slice($file, $start));
    }
}
