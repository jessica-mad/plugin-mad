<?php
/**
 * Private Store Module
 * 
 * Gestiona una tienda privada con usuarios VIP, descuentos especiales
 * y productos exclusivos.
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

namespace MAD_Suite\Modules\PrivateStore;

if (!defined('ABSPATH')) {
    exit;
}

class Module {
    
    private static $instance = null;
    private $module_path;
    private $module_url;
    private $logger;
    
    /**
     * Singleton instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->module_path = trailingslashit( plugin_dir_path(__FILE__) );
$this->module_url  = trailingslashit( plugin_dir_url(__FILE__) );

        
        // Cargar dependencias primero
        $this->load_dependencies();
        
        // Inicializar logger
        $this->logger = new Logger('private-store');
        
        // Inicializar hooks
        $this->init_hooks();
        
        $this->logger->info('Módulo Private Store inicializado');
    }
    
    /**
     * Cargar dependencias
     */
    private function load_dependencies() {
        require_once $this->module_path . 'includes/Logger.php';
        require_once $this->module_path . 'includes/UserRole.php';
        require_once $this->module_path . 'includes/ProductVisibility.php';
        require_once $this->module_path . 'includes/PricingEngine.php';
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        // Frontend hooks
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        
        // Inicializar componentes
        UserRole::instance();
        ProductVisibility::instance();
        PricingEngine::instance();
        
        // Dashboard del cliente
        add_filter('woocommerce_account_menu_items', [$this, 'add_dashboard_menu_item'], 40);
        add_action('init', [$this, 'add_dashboard_endpoint']);
        add_action('woocommerce_account_private-store_endpoint', [$this, 'render_private_store_page']);
        
        // AJAX handlers
        add_action('wp_ajax_mads_ps_save_discount', [$this, 'ajax_save_discount']);
        add_action('wp_ajax_mads_ps_delete_discount', [$this, 'ajax_delete_discount']);
        add_action('wp_ajax_mads_ps_save_general_settings', [$this, 'ajax_save_general_settings']);
        add_action('wp_ajax_mads_ps_clear_all_discounts', [$this, 'ajax_clear_all_discounts']);
        add_action('wp_ajax_mads_ps_reset_settings', [$this, 'ajax_reset_settings']);
        add_action('wp_ajax_mads_ps_download_log', [$this, 'ajax_download_log']);
        add_action('wp_ajax_mads_ps_clear_log', [$this, 'ajax_clear_log']);
    }
    
    /**
     * Agregar menú en admin
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mad-suite',
            __('Tienda Privada', 'mad-suite'),
            __('Tienda Privada', 'mad-suite'),
            'manage_woocommerce',
            'mad-suite-private-store',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Cargar assets del admin
     */
    public function enqueue_admin_assets($hook) {
        if ('mad-suite_page_mad-suite-private-store' !== $hook) {
            return;
        }
        
        // CSS Principal
        wp_enqueue_style(
            'mads-private-store-admin',
            $this->module_url . 'assets/admin.css',
            [],
            MAD_SUITE_VERSION
        );
        
        // JS Principal
        wp_enqueue_script(
            'mads-private-store-admin',
            $this->module_url . 'assets/admin.js',
            ['jquery', 'wp-util'],
            MAD_SUITE_VERSION,
            true
        );
        
        // JS para Users Tab
        wp_enqueue_script(
    'mads-private-store-users-tab',
    $this->module_url . 'assets/users-tab.js',
    ['jquery'],  // QUITA LA DEPENDENCIA de mads-private-store-admin
    time(),      // FUERZA A NO USAR CACHE
    true
);
        
        // IMPORTANTE: Localizar DESPUÉS de registrar los scripts
        wp_localize_script('mads-private-store-users-tab', 'madsPrivateStore', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('mads_private_store'),
    'strings' => [
        'confirmDelete' => __('¿Estás seguro de eliminar este descuento?', 'mad-suite'),
        'saved' => __('Guardado correctamente', 'mad-suite'),
        'error' => __('Error al guardar', 'mad-suite')
    ]
]);
    }
    
    /**
     * Cargar assets del frontend
     */
    public function enqueue_frontend_assets() {
        if (!$this->is_private_store_page()) {
            return;
        }
        
        wp_enqueue_style(
            'mads-private-store-front',
            $this->module_url . 'assets/frontend.css',
            [],
            MAD_SUITE_VERSION
        );
    }
    
    /**
     * Agregar item al menú de Mi Cuenta
     */
    public function add_dashboard_menu_item($items) {
        if (!current_user_can('private_store_access')) {
            return $items;
        }
        
        $role_name = get_option('mads_ps_role_name', __('Tienda VIP', 'mad-suite'));
        
        $new_items = [];
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            
            // Insertar después de "Dashboard"
            if ($key === 'dashboard') {
                $new_items['private-store'] = $role_name;
            }
        }
        
        return $new_items;
    }
    
    /**
     * Agregar endpoint personalizado
     */
    public function add_dashboard_endpoint() {
        add_rewrite_endpoint('private-store', EP_ROOT | EP_PAGES);
    }
    
    /**
     * Renderizar página de tienda privada
     */
    public function render_private_store_page() {
        if (!current_user_can('private_store_access')) {
            wp_die(__('No tienes acceso a esta sección', 'mad-suite'));
        }
        
        $this->logger->info('Usuario ' . get_current_user_id() . ' accedió a tienda privada');
        
        // Redirigir a la tienda con parámetro especial
        $shop_url = add_query_arg('private_store', '1', wc_get_page_permalink('shop'));
        wp_redirect($shop_url);
        exit;
    }
    
    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        
        include $this->module_path . 'views/settings.php';
    }
    
    /**
     * AJAX: Guardar descuento
     */
    public function ajax_save_discount() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'mad-suite')]);
        }
        
        $discount_id = isset($_POST['discount_id']) ? intval($_POST['discount_id']) : 0;
        $discount_data = [
            'type' => sanitize_text_field($_POST['type']),
            'target' => sanitize_text_field($_POST['target']),
            'amount' => floatval($_POST['amount']),
            'amount_type' => sanitize_text_field($_POST['amount_type'])
        ];
        
        $discounts = get_option('mads_ps_discounts', []);
        
        if ($discount_id > 0) {
            $discounts[$discount_id] = $discount_data;
            $this->logger->info("Descuento actualizado: ID {$discount_id}", $discount_data);
        } else {
            $discounts[] = $discount_data;
            $this->logger->info("Nuevo descuento creado", $discount_data);
        }
        
        update_option('mads_ps_discounts', $discounts);
        
        wp_send_json_success([
            'message' => __('Descuento guardado correctamente', 'mad-suite'),
            'discounts' => $discounts
        ]);
    }
    
    /**
     * AJAX: Eliminar descuento
     */
    public function ajax_delete_discount() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'mad-suite')]);
        }
        
        $discount_id = intval($_POST['discount_id']);
        $discounts = get_option('mads_ps_discounts', []);
        
        if (isset($discounts[$discount_id])) {
            $this->logger->info("Descuento eliminado: ID {$discount_id}", $discounts[$discount_id]);
            unset($discounts[$discount_id]);
            update_option('mads_ps_discounts', array_values($discounts));
            
            wp_send_json_success([
                'message' => __('Descuento eliminado', 'mad-suite')
            ]);
        }
        
        wp_send_json_error(['message' => __('Descuento no encontrado', 'mad-suite')]);
    }
    
    /**
     * AJAX: Guardar configuración general
     */
    public function ajax_save_general_settings() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'mad-suite')]);
        }
        
        $settings = [
            'role_name' => sanitize_text_field($_POST['role_name']),
            'redirect_after_login' => isset($_POST['redirect_after_login']) ? 1 : 0,
            'show_vip_badge' => isset($_POST['show_vip_badge']) ? 1 : 0,
            'enable_logging' => isset($_POST['enable_logging']) ? 1 : 0,
            'custom_css' => wp_strip_all_tags($_POST['custom_css'])
        ];
        
        foreach ($settings as $key => $value) {
            update_option('mads_ps_' . $key, $value);
        }
        
        // Actualizar nombre del rol
        if (!empty($settings['role_name'])) {
            UserRole::instance()->update_role_name($settings['role_name']);
        }
        
        $this->logger->info('Configuración general actualizada', $settings);
        
        wp_send_json_success([
            'message' => __('Configuración guardada correctamente', 'mad-suite')
        ]);
    }
    
    /**
     * AJAX: Limpiar todos los descuentos
     */
    public function ajax_clear_all_discounts() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'mad-suite')]);
        }
        
        $discounts = get_option('mads_ps_discounts', []);
        $count = count($discounts);
        
        delete_option('mads_ps_discounts');
        
        $this->logger->warning("Todos los descuentos eliminados", [
            'count' => $count,
            'user_id' => get_current_user_id()
        ]);
        
        wp_send_json_success([
            'message' => sprintf(__('%d descuentos eliminados correctamente', 'mad-suite'), $count)
        ]);
    }
    
    /**
     * AJAX: Restablecer configuración
     */
    public function ajax_reset_settings() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'mad-suite')]);
        }
        
        $options = [
            'mads_ps_role_name',
            'mads_ps_redirect_after_login',
            'mads_ps_show_vip_badge',
            'mads_ps_enable_logging',
            'mads_ps_custom_css'
        ];
        
        foreach ($options as $option) {
            delete_option($option);
        }
        
        $this->logger->warning("Configuración restablecida a valores predeterminados", [
            'user_id' => get_current_user_id()
        ]);
        
        wp_send_json_success([
            'message' => __('Configuración restablecida correctamente', 'mad-suite')
        ]);
    }
    
    /**
     * AJAX: Descargar log
     */
    public function ajax_download_log() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permisos insuficientes', 'mad-suite'));
        }
        
        $logger = new Logger('private-store');
        $logger->download_log();
    }
    
    /**
     * AJAX: Limpiar log actual
     */
    public function ajax_clear_log() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'mad-suite')]);
        }
        
        $logger = new Logger('private-store');
        $result = $logger->clear_current_log();
        
        if ($result) {
            wp_send_json_success([
                'message' => __('Log limpiado correctamente', 'mad-suite')
            ]);
        } else {
            wp_send_json_error([
                'message' => __('No se pudo limpiar el log', 'mad-suite')
            ]);
        }
    }
    
    /**
     * Verificar si estamos en página de tienda privada
     */
    private function is_private_store_page() {
        return isset($_GET['private_store']) || get_query_var('private-store');
    }
    
    /**
     * Get module path
     */
    public function get_path($file = '') {
        return $this->module_path . $file;
    }
    
    /**
     * Get module URL
     */
    public function get_url($file = '') {
        return $this->module_url . $file;
    }
}

// Inicializar módulo
Module::instance();