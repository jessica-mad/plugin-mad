<?php
/**
 * Logger para el módulo Guest Activation
 *
 * Maneja el registro de actividades en archivos de log
 */

if (!defined('ABSPATH')) exit;

class MAD_Guest_Activation_Logger {
    private $log_dir;
    private $log_file;

    public function __construct() {
        // Usar la carpeta de uploads de WooCommerce
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/mad-guest-activation-logs';

        // Crear directorio si no existe
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);

            // Crear archivo .htaccess para proteger los logs
            $htaccess_file = $this->log_dir . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                file_put_contents($htaccess_file, 'deny from all');
            }
        }

        // Archivo de log por día
        $this->log_file = $this->log_dir . '/guest-activation-' . date('Y-m-d') . '.log';
    }

    /**
     * Registrar un evento en el log
     */
    public function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = sprintf("[%s] %s\n", $timestamp, $message);

        // Agregar al archivo de log
        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
    }

    /**
     * Obtener todos los archivos de log
     */
    public function get_log_files() {
        if (!is_dir($this->log_dir)) {
            return [];
        }

        $files = glob($this->log_dir . '/guest-activation-*.log');

        // Ordenar por fecha descendente
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $files;
    }

    /**
     * Leer un archivo de log
     */
    public function read_log($file_path) {
        if (!file_exists($file_path)) {
            return '';
        }

        return file_get_contents($file_path);
    }

    /**
     * Obtener URL para ver los logs
     */
    public function get_logs_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/mad-guest-activation-logs/';
    }

    /**
     * Limpiar logs antiguos (más de 30 días)
     */
    public function cleanup_old_logs($days = 30) {
        $files = $this->get_log_files();
        $cutoff_time = time() - ($days * 24 * 60 * 60);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                unlink($file);
            }
        }
    }

    /**
     * Obtener estadísticas del log actual
     */
    public function get_stats() {
        if (!file_exists($this->log_file)) {
            return [
                'total_entries' => 0,
                'file_size' => 0,
            ];
        }

        $content = file_get_contents($this->log_file);
        $lines = explode("\n", trim($content));

        return [
            'total_entries' => count($lines),
            'file_size' => filesize($this->log_file),
        ];
    }
}
