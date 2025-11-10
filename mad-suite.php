<?php
/**
 * Plugin Name: MAD Suite
 * Description: Suite modular de utilidades para WooCommerce (countdown, incentivos, notas de producto).
 * Version: 0.1.0
 * Author: Tu Nombre
 * Text Domain: mad-suite
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined('ABSPATH') ) exit;

final class MAD_Suite_Core {
    const CAPABILITY     = 'manage_options';
    const MENU_SLUG_ROOT = 'mad-suite';
    const ENABLED_MODULES_OPTION = 'madsuite_enabled_modules';

    private static $instance = null;
    private $modules = [];
    private $all_modules_info = []; // Info de todos los módulos (habilitados o no)

    public static function instance(){
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct(){
        add_action('init', [$this,'init_modules_early'], 1);
        add_action('admin_menu', [$this,'register_root_menu']);
        add_action('admin_init', [$this,'admin_init']);
        add_action('plugins_loaded', [$this,'load_textdomain']);
        add_action('admin_post_mads_toggle_module', [$this, 'handle_toggle_module']);
    }

    public function load_textdomain(){
        load_plugin_textdomain('mad-suite', false, dirname(plugin_basename(__FILE__)).'/languages');
    }

    public function register_root_menu(){
        add_menu_page(
            __('MAD Plugins','mad-suite'),
            __('MAD Plugins','mad-suite'),
            self::CAPABILITY,
            self::MENU_SLUG_ROOT,
            [$this,'render_root_page'],
            'dashicons-admin-generic',
            57
        );
    }

    public function render_root_page(){
        if ( !current_user_can(self::CAPABILITY) ) {
            wp_die(__('No tienes permisos suficientes.', 'mad-suite'));
        }
        include plugin_dir_path(__FILE__) . 'views/modules-manager.php';
    }

    public function init_modules_early(){
        $dir = plugin_dir_path(__FILE__) . 'modules';
        if ( ! is_dir($dir) ) return;

        $enabled_modules = $this->get_enabled_modules();

        foreach ( glob($dir.'/*/Module.php') as $file ){
            $core = $this; // disponible dentro del módulo si usa "return new class($core) ..."
            $module = include $file;

            if ( is_object($module) && ($module instanceof MAD_Suite_Module) ){
                $slug = $module->slug();

                // Guardar información del módulo
                $this->all_modules_info[$slug] = [
                    'instance' => $module,
                    'slug' => $slug,
                    'title' => $module->title(),
                    'description' => method_exists($module, 'description') ? $module->description() : '',
                    'required_plugins' => method_exists($module, 'required_plugins') ? $module->required_plugins() : [],
                    'enabled' => in_array($slug, $enabled_modules),
                ];

                // Solo inicializar si está habilitado
                if ( in_array($slug, $enabled_modules) ){
                    $this->modules[$slug] = $module;
                    if ( method_exists($module, 'init') ) $module->init();

                    // Submenú de ajustes (si existe)
                    add_action('admin_menu', function() use ($module){
                        add_submenu_page(
                            self::MENU_SLUG_ROOT,
                            $module->title(),
                            $module->menu_label(),
                            self::CAPABILITY,
                            $module->menu_slug(),
                            [$module,'render_settings_page']
                        );
                    });
                }
            }
        }
    }

    public function admin_init(){
        foreach ( $this->modules as $m ){
            if ( method_exists($m,'admin_init') ) $m->admin_init();
        }
    }

    /* ===== Helpers globales de opciones ===== */
    public static function option_key( $module_slug ){
        return 'madsuite_'. sanitize_key($module_slug) .'_settings';
    }

    /* ===== Gestión de módulos habilitados ===== */
    public function get_enabled_modules(){
        $enabled = get_option(self::ENABLED_MODULES_OPTION, []);
        // Si es la primera vez, todos están deshabilitados por defecto
        if ( $enabled === false || $enabled === [] ) {
            return [];
        }
        return is_array($enabled) ? $enabled : [];
    }

    public function get_all_modules_info(){
        return $this->all_modules_info;
    }

    public function is_module_enabled($slug){
        $enabled = $this->get_enabled_modules();
        return in_array($slug, $enabled);
    }

    public function enable_module($slug){
        $enabled = $this->get_enabled_modules();
        if ( !in_array($slug, $enabled) ) {
            $enabled[] = $slug;
            update_option(self::ENABLED_MODULES_OPTION, $enabled);
        }
    }

    public function disable_module($slug){
        $enabled = $this->get_enabled_modules();
        $enabled = array_diff($enabled, [$slug]);
        update_option(self::ENABLED_MODULES_OPTION, array_values($enabled));
    }

    public function handle_toggle_module(){
        if ( !current_user_can(self::CAPABILITY) ) {
            wp_die(__('No tienes permisos suficientes.', 'mad-suite'));
        }

        check_admin_referer('mads_toggle_module', 'mads_nonce');

        $module_slug = isset($_POST['module_slug']) ? sanitize_key($_POST['module_slug']) : '';
        $action = isset($_POST['module_action']) ? sanitize_key($_POST['module_action']) : '';

        if ( !$module_slug ) {
            wp_die(__('Módulo inválido.', 'mad-suite'));
        }

        if ( $action === 'enable' ) {
            $this->enable_module($module_slug);
        } elseif ( $action === 'disable' ) {
            $this->disable_module($module_slug);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_ROOT . '&updated=1'));
        exit;
    }
}

/* ===== Interfaz (contrato) que cada módulo debe cumplir ===== */
interface MAD_Suite_Module {
    public function slug();                 // string identificador corto, p.ej. "product-notes"
    public function title();                // Título de la página
    public function menu_label();           // Texto del submenú
    public function menu_slug();            // slug único del submenú
    public function init();                 // Hooks públicos (front y/o admin)
    public function admin_init();           // Registro de ajustes, campos, etc.
    public function render_settings_page(); // Dibuja la página de ajustes

    /* ===== Métodos opcionales (no obligatorios) ===== */
    // public function description();       // Descripción del módulo (opcional)
    // public function required_plugins();  // Array de plugins requeridos: ['Plugin Name' => 'plugin/file.php'] (opcional)
}

// Bootstrap
MAD_Suite_Core::instance();
