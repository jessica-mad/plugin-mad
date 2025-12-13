<?php
/**
 * Módulo: Countdown
 *
 * Módulo para mostrar cuentas regresivas en productos o páginas.
 */

if (!defined('ABSPATH')) exit;

return new class($core ?? null) implements MAD_Suite_Module {
    private $core;
    private $slug = 'countdown';

    public function __construct($core) {
        $this->core = $core;
    }

    public function slug() {
        return $this->slug;
    }

    public function title() {
        return __('Countdown', 'mad-suite');
    }

    public function menu_label() {
        return __('Countdown', 'mad-suite');
    }

    public function menu_slug() {
        return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug;
    }

    public function description() {
        return __('Muestra cuentas regresivas personalizables en productos o páginas.', 'mad-suite');
    }

    public function required_plugins() {
        return [];
    }

    /**
     * Inicialización del módulo
     */
    public function init() {
        // Shortcode para countdown
        add_shortcode('mad_countdown', [$this, 'render_countdown_shortcode']);

        // Cargar scripts en frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
    }

    /**
     * Inicialización del admin
     */
    public function admin_init() {
        $option_key = MAD_Suite_Core::option_key($this->slug());
        register_setting($this->menu_slug(), $option_key, [$this, 'sanitize_settings']);
    }

    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_die(__('No tienes permisos suficientes.', 'mad-suite'));
        }

        include __DIR__ . '/views/settings.php';
    }

    /**
     * Shortcode para mostrar countdown
     * Uso: [mad_countdown cutoff="2024-12-31 23:59:59" template="Quedan {{hh}} horas y {{mm}} minutos"]
     */
    public function render_countdown_shortcode($atts) {
        $atts = shortcode_atts([
            'cutoff' => '',
            'template' => __('Quedan {{hh}} horas y {{mm}} minutos', 'mad-suite'),
        ], $atts, 'mad_countdown');

        if (empty($atts['cutoff'])) {
            return '<p>' . __('Error: Debes especificar una fecha límite (cutoff).', 'mad-suite') . '</p>';
        }

        $cutoff_timestamp = strtotime($atts['cutoff']);
        if ($cutoff_timestamp === false) {
            return '<p>' . __('Error: Fecha inválida.', 'mad-suite') . '</p>';
        }

        $now_timestamp = current_time('timestamp');

        // Si ya pasó el tiempo, no mostrar nada
        if ($cutoff_timestamp <= $now_timestamp) {
            return '';
        }

        $html = sprintf(
            '<div class="mad-countdown" data-cut="%d" data-now="%d" data-template="%s"></div>',
            esc_attr($cutoff_timestamp),
            esc_attr($now_timestamp),
            esc_attr($atts['template'])
        );

        return $html;
    }

    /**
     * Cargar scripts en frontend
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_script(
            'mad-countdown-front',
            plugin_dir_url(__FILE__) . 'assets/front.js',
            ['jquery'],
            '1.0.0',
            true
        );
    }

    /**
     * Sanitizar configuración
     */
    public function sanitize_settings($input) {
        $sanitized = [];

        if (isset($input['enabled'])) {
            $sanitized['enabled'] = (bool) $input['enabled'];
        }

        return $sanitized;
    }
};
