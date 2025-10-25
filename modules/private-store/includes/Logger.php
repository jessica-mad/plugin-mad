<?php
/**
 * Logger Class
 * 
 * Sistema de logs para el módulo Private Store
 * Crea logs legibles en la carpeta de uploads de WooCommerce
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

namespace MAD_Suite\Modules\PrivateStore;

if (!defined('ABSPATH')) {
    exit;
}

class Logger {
    
    private $module_name;
    private $log_dir;
    private $log_file;
    
    /**
     * Constructor
     */
    public function __construct($module_name = 'private-store') {
        $this->module_name = $module_name;
        $this->setup_log_directory();
    }
    
    /**
     * Configurar directorio de logs
     */
    private function setup_log_directory() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/mad-suite-logs/';
        
        // Crear directorio si no existe
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
            
            // Crear .htaccess para proteger logs
            $htaccess_content = "deny from all\n";
            file_put_contents($this->log_dir . '.htaccess', $htaccess_content);
            
            // Crear index.php vacío
            file_put_contents($this->log_dir . 'index.php', '<?php // Silence is golden');
        }
        
        // Archivo de log con fecha
        $date = current_time('Y-m-d');
        $this->log_file = $this->log_dir . $this->module_name . '-' . $date . '.log';
    }
    
    /**
     * Registrar mensaje de log
     */
    private function write($level, $message, $context = []) {
        if (!get_option('mads_ps_enable_logging', true)) {
            return;
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $user_id = get_current_user_id();
        $user_info = $user_id ? " [User: {$user_id}]" : '';
        
        // Formatear mensaje
        $log_message = sprintf(
            "[%s] [%s]%s %s\n",
            $timestamp,
            strtoupper($level),
            $user_info,
            $message
        );
        
        // Agregar contexto si existe
        if (!empty($context)) {
            $log_message .= "Context: " . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        
        $log_message .= str_repeat('-', 80) . "\n";
        
        // Escribir a archivo
        error_log($log_message, 3, $this->log_file);
    }
    
    /**
     * Log nivel INFO
     */
    public function info($message, $context = []) {
        $this->write('info', $message, $context);
    }
    
    /**
     * Log nivel WARNING
     */
    public function warning($message, $context = []) {
        $this->write('warning', $message, $context);
    }
    
    /**
     * Log nivel ERROR
     */
    public function error($message, $context = []) {
        $this->write('error', $message, $context);
    }
    
    /**
     * Log nivel DEBUG
     */
    public function debug($message, $context = []) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->write('debug', $message, $context);
        }
    }
    
    /**
     * Obtener URL del log actual
     */
    public function get_log_url() {
        $upload_dir = wp_upload_dir();
        $date = current_time('Y-m-d');
        return $upload_dir['baseurl'] . '/mad-suite-logs/' . $this->module_name . '-' . $date . '.log';
    }
    
    /**
     * Obtener path del log actual
     */
    public function get_log_path() {
        return $this->log_file;
    }
    
    /**
     * Leer últimas líneas del log
     */
    public function read_last_lines($lines = 100) {
        if (!file_exists($this->log_file)) {
            return '';
        }
        
        $file = new \SplFileObject($this->log_file, 'r');
        $file->seek(PHP_INT_MAX);
        $last_line = $file->key();
        $start_line = max(0, $last_line - $lines);
        
        $result = [];
        $file->seek($start_line);
        
        while (!$file->eof()) {
            $result[] = $file->current();
            $file->next();
        }
        
        return implode('', $result);
    }
    
    /**
     * Limpiar logs antiguos (más de 30 días)
     */
    public static function cleanup_old_logs() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/mad-suite-logs/';
        
        if (!is_dir($log_dir)) {
            return;
        }
        
        $files = glob($log_dir . '*.log');
        $thirty_days_ago = strtotime('-30 days');
        
        foreach ($files as $file) {
            if (filemtime($file) < $thirty_days_ago) {
                unlink($file);
            }
        }
    }
    
    /**
     * Obtener tamaño del log actual
     */
    public function get_log_size() {
        if (!file_exists($this->log_file)) {
            return 0;
        }
        
        $bytes = filesize($this->log_file);
        
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' bytes';
    }
    
    /**
     * Obtener listado de logs disponibles
     */
    public static function get_available_logs() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/mad-suite-logs/';
        
        if (!is_dir($log_dir)) {
            return [];
        }
        
        $files = glob($log_dir . 'private-store-*.log');
        rsort($files); // Más recientes primero
        
        $logs = [];
        foreach ($files as $file) {
            $filename = basename($file);
            $logs[] = [
                'filename' => $filename,
                'path' => $file,
                'url' => $upload_dir['baseurl'] . '/mad-suite-logs/' . $filename,
                'size' => filesize($file),
                'size_formatted' => self::format_file_size(filesize($file)),
                'modified' => filemtime($file),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'date_formatted' => self::format_date_relative(filemtime($file))
            ];
        }
        
        return $logs;
    }
    
    /**
     * Formatear tamaño de archivo
     */
    private static function format_file_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' bytes';
    }
    
    /**
     * Formatear fecha de forma relativa
     */
    private static function format_date_relative($timestamp) {
        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return __('Hace un momento', 'mad-suite');
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return sprintf(_n('Hace %s minuto', 'Hace %s minutos', $mins, 'mad-suite'), $mins);
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return sprintf(_n('Hace %s hora', 'Hace %s horas', $hours, 'mad-suite'), $hours);
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return sprintf(_n('Hace %s día', 'Hace %s días', $days, 'mad-suite'), $days);
        } else {
            return date_i18n(get_option('date_format'), $timestamp);
        }
    }
    
    /**
     * Descargar log como archivo
     */
    public function download_log() {
        if (!file_exists($this->log_file)) {
            return false;
        }
        
        $filename = basename($this->log_file);
        
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($this->log_file));
        
        readfile($this->log_file);
        exit;
    }
    
    /**
     * Limpiar log actual
     */
    public function clear_current_log() {
        if (file_exists($this->log_file)) {
            $result = unlink($this->log_file);
            
            if ($result) {
                $this->info('Log limpiado manualmente');
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtener estadísticas del log
     */
    public function get_log_stats() {
        if (!file_exists($this->log_file)) {
            return [
                'exists' => false,
                'size' => 0,
                'lines' => 0,
                'errors' => 0,
                'warnings' => 0,
                'info' => 0,
                'debug' => 0
            ];
        }
        
        $content = file_get_contents($this->log_file);
        $lines = explode("\n", $content);
        
        $stats = [
            'exists' => true,
            'size' => filesize($this->log_file),
            'size_formatted' => $this->get_log_size(),
            'lines' => count($lines),
            'errors' => substr_count($content, '[ERROR]'),
            'warnings' => substr_count($content, '[WARNING]'),
            'info' => substr_count($content, '[INFO]'),
            'debug' => substr_count($content, '[DEBUG]'),
            'modified' => filemtime($this->log_file),
            'modified_formatted' => date('Y-m-d H:i:s', filemtime($this->log_file))
        ];
        
        return $stats;
    }
    
    /**
     * Buscar en el log
     */
    public function search_log($search_term, $limit = 50) {
        if (!file_exists($this->log_file)) {
            return [];
        }
        
        $results = [];
        $file = new \SplFileObject($this->log_file, 'r');
        $line_number = 0;
        
        while (!$file->eof() && count($results) < $limit) {
            $line = $file->current();
            $line_number++;
            
            if (stripos($line, $search_term) !== false) {
                $results[] = [
                    'line_number' => $line_number,
                    'content' => trim($line)
                ];
            }
            
            $file->next();
        }
        
        return $results;
    }
    
    /**
     * Obtener entradas por nivel
     */
    public function get_entries_by_level($level = 'ERROR', $limit = 50) {
        if (!file_exists($this->log_file)) {
            return [];
        }
        
        $level = strtoupper($level);
        $results = [];
        $file = new \SplFileObject($this->log_file, 'r');
        $line_number = 0;
        
        while (!$file->eof() && count($results) < $limit) {
            $line = $file->current();
            $line_number++;
            
            if (strpos($line, "[{$level}]") !== false) {
                // Leer siguiente línea si es contexto JSON
                $file->next();
                $next_line = $file->current();
                $context = '';
                
                if (strpos($next_line, 'Context:') !== false) {
                    $context = trim($next_line);
                    $file->next();
                } else {
                    $file->seek($line_number); // Volver atrás
                }
                
                $results[] = [
                    'line_number' => $line_number,
                    'content' => trim($line),
                    'context' => $context
                ];
            }
            
            $file->next();
        }
        
        return $results;
    }
    
    /**
     * Exportar logs a diferentes formatos
     */
    public function export_log($format = 'txt') {
        if (!file_exists($this->log_file)) {
            return false;
        }
        
        switch ($format) {
            case 'json':
                return $this->export_log_json();
            case 'csv':
                return $this->export_log_csv();
            case 'html':
                return $this->export_log_html();
            default:
                return $this->log_file;
        }
    }
    
    /**
     * Exportar log como JSON
     */
    private function export_log_json() {
        $entries = [];
        $file = new \SplFileObject($this->log_file, 'r');
        
        while (!$file->eof()) {
            $line = $file->current();
            
            if (preg_match('/\[([\d\-: ]+)\] \[(\w+)\](?:\[User: (\d+)\])? (.+)/', $line, $matches)) {
                $entries[] = [
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'user_id' => isset($matches[3]) ? intval($matches[3]) : null,
                    'message' => trim($matches[4])
                ];
            }
            
            $file->next();
        }
        
        return json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Exportar log como CSV
     */
    private function export_log_csv() {
        $csv_file = str_replace('.log', '.csv', $this->log_file);
        $handle = fopen($csv_file, 'w');
        
        // Encabezados
        fputcsv($handle, ['Timestamp', 'Level', 'User ID', 'Message']);
        
        $file = new \SplFileObject($this->log_file, 'r');
        
        while (!$file->eof()) {
            $line = $file->current();
            
            if (preg_match('/\[([\d\-: ]+)\] \[(\w+)\](?:\[User: (\d+)\])? (.+)/', $line, $matches)) {
                fputcsv($handle, [
                    $matches[1],
                    $matches[2],
                    isset($matches[3]) ? $matches[3] : '',
                    trim($matches[4])
                ]);
            }
            
            $file->next();
        }
        
        fclose($handle);
        
        return $csv_file;
    }
    
    /**
     * Exportar log como HTML
     */
    private function export_log_html() {
        $html_file = str_replace('.log', '.html', $this->log_file);
        $content = file_get_contents($this->log_file);
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Private Store Log - ' . date('Y-m-d') . '</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
        .log-entry { margin: 10px 0; padding: 10px; background: #252526; border-radius: 4px; }
        .ERROR { border-left: 4px solid #f44336; }
        .WARNING { border-left: 4px solid #ff9800; }
        .INFO { border-left: 4px solid #2196f3; }
        .DEBUG { border-left: 4px solid #9e9e9e; }
        .timestamp { color: #6a9955; }
        .level { font-weight: bold; }
        .ERROR .level { color: #f44336; }
        .WARNING .level { color: #ff9800; }
        .INFO .level { color: #2196f3; }
        .DEBUG .level { color: #9e9e9e; }
    </style>
</head>
<body>
    <h1>Private Store Log - ' . date('Y-m-d') . '</h1>
    <pre>' . htmlspecialchars($content) . '</pre>
</body>
</html>';
        
        file_put_contents($html_file, $html);
        
        return $html_file;
    }
}