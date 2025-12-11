<?php
namespace MAD_Suite\CheckoutMonitor\Trackers;

use MAD_Suite\CheckoutMonitor\ExecutionLogger;

if ( ! defined('ABSPATH') ) exit;

class ErrorCatcher {

    private $logger;
    private $previous_error_handler;
    private $previous_exception_handler;
    private $is_active = false;

    public function __construct(ExecutionLogger $logger){
        $this->logger = $logger;
    }

    public function activate(){
        if ( $this->is_active ) return;

        // VERSIÓN SIMPLIFICADA: Solo hooks de WooCommerce, no interceptar PHP errors
        // Los error handlers globales son demasiado invasivos y pueden romper el checkout

        // Solo capturar errores de WooCommerce
        $this->hook_woocommerce_errors();

        $this->is_active = true;
    }

    public function deactivate(){
        if ( !$this->is_active ) return;

        // Restaurar handlers anteriores
        if ( $this->previous_error_handler ) {
            set_error_handler($this->previous_error_handler);
        } else {
            restore_error_handler();
        }

        if ( $this->previous_exception_handler ) {
            set_exception_handler($this->previous_exception_handler);
        } else {
            restore_exception_handler();
        }

        $this->is_active = false;
    }

    public function handle_error($errno, $errstr, $errfile, $errline){
        // Capturar el error para logging
        $error_data = [
            'type' => $this->get_error_type_string($errno),
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'severity' => $errno,
        ];

        // Solo loggear errores relevantes
        if ( $this->should_log_error($errno, $errfile) ) {
            $this->logger->log_error($error_data);
        }

        // Llamar al error handler anterior si existe
        if ( $this->previous_error_handler && is_callable($this->previous_error_handler) ) {
            return call_user_func($this->previous_error_handler, $errno, $errstr, $errfile, $errline);
        }

        // No interrumpir la ejecución normal
        return false;
    }

    public function handle_exception($exception){
        // Capturar la excepción
        $error_data = [
            'type' => 'Exception',
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'severity' => E_ERROR,
        ];

        $this->logger->log_error($error_data);

        // Llamar al exception handler anterior si existe
        if ( $this->previous_exception_handler && is_callable($this->previous_exception_handler) ) {
            return call_user_func($this->previous_exception_handler, $exception);
        }

        // Re-throw la excepción para que WP la maneje
        throw $exception;
    }

    public function handle_shutdown(){
        $error = error_get_last();

        if ( $error === null ) {
            return;
        }

        // Solo capturar fatal errors
        if ( in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]) ) {
            $error_data = [
                'type' => $this->get_error_type_string($error['type']),
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'severity' => $error['type'],
                'fatal' => true,
            ];

            if ( $this->should_log_error($error['type'], $error['file']) ) {
                $this->logger->log_error($error_data);
                $this->logger->fail_session();
            }
        }
    }

    private function should_log_error($errno, $file){
        // No loggear notices a menos que sean en plugins relevantes
        if ( in_array($errno, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED]) ) {
            // Solo loggear si es de WooCommerce o plugins de pago
            if ( !$this->is_relevant_file($file) ) {
                return false;
            }
        }

        return true;
    }

    private function is_relevant_file($file){
        $relevant_paths = [
            'woocommerce',
            'mailchimp',
            'redsys',
            'stripe',
            'paypal',
            'checkout',
            'payment',
        ];

        foreach ( $relevant_paths as $path ) {
            if ( stripos($file, $path) !== false ) {
                return true;
            }
        }

        return false;
    }

    private function get_error_type_string($type){
        $error_types = [
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_PARSE             => 'E_PARSE',
            E_NOTICE            => 'E_NOTICE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_CORE_WARNING      => 'E_CORE_WARNING',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_STRICT            => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
        ];

        return isset($error_types[$type]) ? $error_types[$type] : 'UNKNOWN';
    }

    /* ==== Hook into WooCommerce errors ==== */
    public function hook_woocommerce_errors(){
        add_action('woocommerce_add_error', [$this, 'capture_wc_error'], 10, 2);
        add_action('woocommerce_add_notice', [$this, 'capture_wc_notice'], 10, 2);
    }

    public function capture_wc_error($message, $notice_type = 'error'){
        if ( $notice_type === 'error' ) {
            $this->logger->log_error([
                'type' => 'WooCommerce Error',
                'message' => $message,
                'source' => 'woocommerce_add_error',
            ]);
        }
    }

    public function capture_wc_notice($message, $notice_type = 'notice'){
        if ( $notice_type === 'error' ) {
            $this->logger->log_error([
                'type' => 'WooCommerce Notice Error',
                'message' => $message,
                'source' => 'woocommerce_add_notice',
            ]);
        }
    }
}
