<?php
/**
 * Logger for Pre-Refund Workflow
 *
 * Handles logging of refund workflow actions.
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

class MAD_Refund_Logger {

    /**
     * Log directory path
     *
     * @var string
     */
    private $log_dir;

    /**
     * Log file path
     *
     * @var string
     */
    private $log_file;

    /**
     * Use WooCommerce logger if available
     *
     * @var bool
     */
    private $use_wc_logger = true;

    /**
     * Constructor
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/mad-refund-logs';

        // Create log directory if needed
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);

            // Protect with .htaccess
            file_put_contents($this->log_dir . '/.htaccess', 'deny from all');

            // Add index.php for additional security
            file_put_contents($this->log_dir . '/index.php', '<?php // Silence is golden');
        }

        $this->log_file = $this->log_dir . '/log-' . date('Y-m-d') . '.log';
    }

    /**
     * Log a message
     *
     * @param string $message Log message
     * @param string $level Log level (INFO, WARNING, ERROR, DEBUG)
     * @param array $context Additional context data
     */
    public function log($message, $level = 'INFO', $context = []) {
        // Try WooCommerce logger first
        if ($this->use_wc_logger && function_exists('wc_get_logger')) {
            $wc_logger = wc_get_logger();
            $wc_level = $this->map_log_level($level);

            $wc_logger->log($wc_level, $message, array_merge(
                ['source' => 'mad-refund-workflow'],
                $context
            ));
        }

        // Also log to our own file for detailed tracking
        $this->write_log_entry($message, $level, $context);
    }

    /**
     * Log an info message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function info($message, $context = []) {
        $this->log($message, 'INFO', $context);
    }

    /**
     * Log a warning message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function warning($message, $context = []) {
        $this->log($message, 'WARNING', $context);
    }

    /**
     * Log an error message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function error($message, $context = []) {
        $this->log($message, 'ERROR', $context);
    }

    /**
     * Log a debug message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function debug($message, $context = []) {
        // Only log debug in WP_DEBUG mode
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $this->log($message, 'DEBUG', $context);
    }

    /**
     * Write log entry to file
     *
     * @param string $message Log message
     * @param string $level Log level
     * @param array $context Additional context
     */
    private function write_log_entry($message, $level, $context = []) {
        $timestamp = current_time('Y-m-d H:i:s');
        $user_id = get_current_user_id();

        $entry = sprintf(
            "[%s] [%s] [User: %d] %s",
            $timestamp,
            strtoupper($level),
            $user_id,
            $message
        );

        if (!empty($context)) {
            $entry .= ' | Context: ' . wp_json_encode($context);
        }

        $entry .= "\n";

        // Write to file
        error_log($entry, 3, $this->log_file);
    }

    /**
     * Map log level to WooCommerce format
     *
     * @param string $level Our log level
     * @return string WooCommerce log level
     */
    private function map_log_level($level) {
        $map = [
            'DEBUG' => 'debug',
            'INFO' => 'info',
            'NOTICE' => 'notice',
            'WARNING' => 'warning',
            'ERROR' => 'error',
            'CRITICAL' => 'critical',
            'ALERT' => 'alert',
            'EMERGENCY' => 'emergency',
        ];

        return $map[strtoupper($level)] ?? 'info';
    }

    /**
     * Log refund data saved event
     *
     * @param int $order_id Order ID
     * @param array $refund_data Refund data saved
     */
    public function log_refund_saved($order_id, $refund_data) {
        $this->info(sprintf(
            'Pre-refund data saved for order #%d',
            $order_id
        ), [
            'order_id' => $order_id,
            'items_count' => count($refund_data['items'] ?? []),
            'total' => $refund_data['total'] ?? 0,
            'include_shipping' => $refund_data['include_shipping'] ?? false,
        ]);
    }

    /**
     * Log PDF generation event
     *
     * @param int $order_id Order ID
     * @param string $action PDF action (download/view)
     */
    public function log_pdf_generated($order_id, $action = 'download') {
        $this->info(sprintf(
            'Pre-refund PDF generated for order #%d (%s)',
            $order_id,
            $action
        ), [
            'order_id' => $order_id,
            'action' => $action,
        ]);
    }

    /**
     * Log email sent event
     *
     * @param int $order_id Order ID
     * @param string $recipient Email recipient
     */
    public function log_email_sent($order_id, $recipient) {
        $this->info(sprintf(
            'Pre-refund notification email sent for order #%d to %s',
            $order_id,
            $recipient
        ), [
            'order_id' => $order_id,
            'recipient' => $recipient,
        ]);
    }

    /**
     * Log status change event
     *
     * @param int $order_id Order ID
     * @param string $from_status Previous status
     * @param string $to_status New status
     */
    public function log_status_change($order_id, $from_status, $to_status) {
        $this->info(sprintf(
            'Order #%d status changed from %s to %s',
            $order_id,
            $from_status,
            $to_status
        ), [
            'order_id' => $order_id,
            'from_status' => $from_status,
            'to_status' => $to_status,
        ]);
    }

    /**
     * Get log file path
     *
     * @return string Log file path
     */
    public function get_log_file() {
        return $this->log_file;
    }

    /**
     * Get log directory path
     *
     * @return string Log directory path
     */
    public function get_log_dir() {
        return $this->log_dir;
    }

    /**
     * Get recent log entries
     *
     * @param int $lines Number of lines to retrieve
     * @return array Log entries
     */
    public function get_recent_entries($lines = 100) {
        if (!file_exists($this->log_file)) {
            return [];
        }

        $file = new SplFileObject($this->log_file, 'r');
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();

        $start = max(0, $total_lines - $lines);
        $entries = [];

        $file->seek($start);
        while (!$file->eof()) {
            $line = $file->fgets();
            if (!empty(trim($line))) {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /**
     * Clear old log files
     *
     * @param int $days_to_keep Days of logs to keep
     * @return int Number of files deleted
     */
    public function clear_old_logs($days_to_keep = 30) {
        $deleted = 0;
        $cutoff = strtotime("-{$days_to_keep} days");

        $files = glob($this->log_dir . '/log-*.log');
        foreach ($files as $file) {
            // Extract date from filename
            preg_match('/log-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches);
            if (!empty($matches[1])) {
                $file_date = strtotime($matches[1]);
                if ($file_date < $cutoff) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        if ($deleted > 0) {
            $this->info(sprintf('Cleared %d old log files', $deleted));
        }

        return $deleted;
    }
}
