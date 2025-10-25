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

    private static $instance = null;
    private $modules = [];

    public static function instance(){
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct(){
        add_action('init', [$this,'init_modules_early'], 1);
        add_action('admin_menu', [$this,'register_root_menu']);
        add_action('admin_init', [$this,'admin_init']);
        add_action('plugins_loaded', [$this,'load_textdomain']);
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
        echo '<div class="wrap"><h1>'.esc_html__('MAD Plugins','mad-suite').'</h1>';
        echo '<p>'.esc_html__('Selecciona un submódulo en el menú de la izquierda.','mad-suite').'</p></div>';
    }

    public function init_modules_early(){
        $dir = plugin_dir_path(__FILE__) . 'modules';
        if ( ! is_dir($dir) ) return;
        foreach ( glob($dir.'/*/Module.php') as $file ){
            $core = $this; // disponible dentro del módulo si usa "return new class($core) ..."
            $module = include $file;
            if ( is_object($module) && ($module instanceof MAD_Suite_Module) ){
                $this->modules[$module->slug()] = $module;
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

    public function admin_init(){
        foreach ( $this->modules as $m ){
            if ( method_exists($m,'admin_init') ) $m->admin_init();
        }
    }

    /* ===== Helpers globales de opciones ===== */
    public static function option_key( $module_slug ){
        return 'madsuite_'. sanitize_key($module_slug) .'_settings';
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
}

// Bootstrap
MAD_Suite_Core::instance();
