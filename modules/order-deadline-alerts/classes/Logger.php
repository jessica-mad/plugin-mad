<?php
/**
 * Logger para el módulo Order Deadline Alerts
 *
 * @package MAD_Suite
 * @subpackage Order_Deadline_Alerts
 */

if (!defined('ABSPATH')) {
    exit;
}

class MAD_Order_Deadline_Alerts_Logger {
    private $log_dir;
    private $log_file;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/mad-order-deadline-alerts-logs';

        // Crear directorio si no existe
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);

            // Proteger directorio con .htaccess
            $htaccess_file = $this->log_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, "Deny from all\n");
            }
        }

        // Archivo de log diario
        $this->log_file = $this->log_dir . '/log-' . date('Y-m-d') . '.log';
    }

    /**
     * Registrar un mensaje en el log
     *
     * @param string $message Mensaje a registrar
     * @param string $level Nivel del log (INFO, WARNING, ERROR)
     */
    public function log(string $message, string $level = 'INFO'): void {
        $timestamp = current_time('mysql');
        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            $timestamp,
            $level,
            $message
        );

        error_log($log_entry, 3, $this->log_file);
    }

    /**
     * Registrar un mensaje informativo
     */
    public function info(string $message): void {
        $this->log($message, 'INFO');
    }

    /**
     * Registrar una advertencia
     */
    public function warning(string $message): void {
        $this->log($message, 'WARNING');
    }

    /**
     * Registrar un error
     */
    public function error(string $message): void {
        $this->log($message, 'ERROR');
    }

    /**
     * Obtener la ruta del directorio de logs
     */
    public function get_log_dir(): string {
        return $this->log_dir;
    }

    /**
     * Obtener la ruta del archivo de log actual
     */
    public function get_log_file(): string {
        return $this->log_file;
    }

    /**
     * Limpiar logs antiguos (más de 30 días)
     */
    public function cleanup_old_logs(): void {
        $files = glob($this->log_dir . '/log-*.log');
        $thirty_days_ago = strtotime('-30 days');

        foreach ($files as $file) {
            if (filemtime($file) < $thirty_days_ago) {
                unlink($file);
            }
        }
    }
}
