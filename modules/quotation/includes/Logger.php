<?php
/**
 * Logger para el módulo Quotation
 *
 * @package MAD_Suite
 * @subpackage Quotation
 */

namespace MADSuite\Modules\Quotation;

if ( ! defined('ABSPATH') ) exit;

class Logger {

    private $module_name;
    private $log_dir;
    private $log_file;

    public function __construct( $module_name = 'quotation' ) {
        $this->module_name = $module_name;
        $this->setup_log_directory();
    }

    private function setup_log_directory() {
        $upload_dir   = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/mad-suite-logs/';

        if ( ! file_exists( $this->log_dir ) ) {
            wp_mkdir_p( $this->log_dir );
            file_put_contents( $this->log_dir . '.htaccess', "deny from all\n" );
            file_put_contents( $this->log_dir . 'index.php', '<?php // Silence is golden' );
        }

        $date            = current_time('Y-m-d');
        $this->log_file  = $this->log_dir . $this->module_name . '-' . $date . '.log';
    }

    private function write( $level, $message, $context = [] ) {
        $timestamp  = current_time('Y-m-d H:i:s');
        $user_id    = get_current_user_id();
        $user_info  = $user_id ? " [User: {$user_id}]" : '';

        $log_message = sprintf( "[%s] [%s]%s %s\n", $timestamp, strtoupper($level), $user_info, $message );

        if ( ! empty( $context ) ) {
            $log_message .= 'Context: ' . json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
        }

        $log_message .= str_repeat( '-', 80 ) . "\n";
        error_log( $log_message, 3, $this->log_file );
    }

    public function info( $message, $context = [] )    { $this->write('info',    $message, $context); }
    public function warning( $message, $context = [] ) { $this->write('warning', $message, $context); }
    public function error( $message, $context = [] )   { $this->write('error',   $message, $context); }

    public function debug( $message, $context = [] ) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            $this->write('debug', $message, $context);
        }
    }
}
