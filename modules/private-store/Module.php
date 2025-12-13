<?php
/**
 * Módulo: Private Store (Wrapper)
 *
 * Wrapper para el módulo legacy de Private Store que lo hace compatible
 * con el sistema de activación/desactivación de MAD Suite.
 */

if (!defined('ABSPATH')) exit;

return new class($core ?? null) implements MAD_Suite_Module {
    private $core;
    private $slug = 'private-store';
    private $legacy_module;

    public function __construct($core) {
        $this->core = $core;
    }

    public function slug() {
        return $this->slug;
    }

    public function title() {
        return __('Private Store', 'mad-suite');
    }

    public function menu_label() {
        return __('Private Store', 'mad-suite');
    }

    public function menu_slug() {
        return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug;
    }

    public function description() {
        return __('Sistema de cupones automáticos por reglas y gestión de tienda privada con control de visibilidad y precios por usuario.', 'mad-suite');
    }

    public function required_plugins() {
        return [
            'WooCommerce' => 'woocommerce/woocommerce.php'
        ];
    }

    /**
     * Inicialización del módulo
     * Solo se llama si el módulo está habilitado
     */
    public function init() {
        // Cargar el módulo legacy
        require_once __DIR__ . '/LegacyModule.php';

        // Inicializar el módulo legacy
        // Este módulo usa singleton, así que solo lo instanciamos
        $this->legacy_module = \MADSuite\Modules\PrivateShop\Module::instance();
    }

    /**
     * Inicialización del admin
     */
    public function admin_init() {
        // El módulo legacy maneja su propia inicialización de admin
        // a través de los hooks que registra en init_hooks()
    }

    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_die(__('No tienes permisos suficientes.', 'mad-suite'));
        }

        // El módulo legacy tiene su propia página de admin
        // que se renderiza a través de sus propios hooks
        // Esta página solo redirige a la configuración del módulo legacy
        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->title()) . '</h1>';
        echo '<p>' . esc_html__('Este módulo maneja su propia configuración a través del sistema legacy.', 'mad-suite') . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=mad-suite-private-store')) . '" class="button button-primary">' . esc_html__('Ir a Configuración', 'mad-suite') . '</a></p>';
        echo '</div>';
    }
};
