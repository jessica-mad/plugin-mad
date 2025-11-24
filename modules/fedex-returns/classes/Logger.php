<?php
/**
 * Logger para el módulo FedEx Returns
 */

if (!defined('ABSPATH')) exit;

class MAD_FedEx_Returns_Logger {
    private $log_dir;
    private $log_file;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/mad-fedex-returns-logs';

        // Crear directorio si no existe
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);

            // Proteger el directorio con .htaccess
            $htaccess_file = $this->log_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, "deny from all\n");
            }
        }

        // Archivo de log del día actual
        $this->log_file = $this->log_dir . '/fedex-returns-' . date('Y-m-d') . '.log';
    }

    /**
     * Registrar mensaje en el log
     */
    public function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);

        error_log($log_entry, 3, $this->log_file);
    }

    /**
     * Log de error
     */
    public function error($message) {
        $this->log($message, 'ERROR');
    }

    /**
     * Log de advertencia
     */
    public function warning($message) {
        $this->log($message, 'WARNING');
    }

    /**
     * Log de debug
     */
    public function debug($message) {
        $this->log($message, 'DEBUG');
    }

    /**
     * Log de request/response de API
     */
    public function log_api_call($endpoint, $request_data, $response_data, $success = true) {
        $level = $success ? 'API' : 'API_ERROR';
        $message = sprintf(
            "Endpoint: %s\nRequest: %s\nResponse: %s",
            $endpoint,
            json_encode($request_data, JSON_PRETTY_PRINT),
            json_encode($response_data, JSON_PRETTY_PRINT)
        );
        $this->log($message, $level);
    }

    /**
     * Obtener archivos de log
     */
    public function get_log_files() {
        $files = glob($this->log_dir . '/fedex-returns-*.log');
        if (!$files) {
            return [];
        }

        // Ordenar por fecha descendente
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $log_files = [];
        foreach ($files as $file) {
            $log_files[] = [
                'path' => $file,
                'name' => basename($file),
                'size' => filesize($file),
                'modified' => filemtime($file),
            ];
        }

        return $log_files;
    }

    /**
     * Leer contenido de un archivo de log
     */
    public function read_log($file_path) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        // Verificar que el archivo está en el directorio correcto (seguridad)
        if (strpos(realpath($file_path), realpath($this->log_dir)) !== 0) {
            return false;
        }

        return file_get_contents($file_path);
    }

    /**
     * Limpiar logs antiguos
     */
    public function cleanup_old_logs($days = 30) {
        $files = glob($this->log_dir . '/fedex-returns-*.log');
        if (!$files) {
            return 0;
        }

        $cutoff_time = time() - ($days * 24 * 60 * 60);
        $deleted = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Obtener URL de logs
     */
    public function get_logs_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/mad-fedex-returns-logs';
    }
}
