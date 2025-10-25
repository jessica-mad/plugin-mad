<?php
namespace MAD_Suite\Modules\RoleCreator;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class RoleManager
{
    private static $instance;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Devuelve los roles editables en WordPress.
     *
     * @return array
     */
    public function get_editable_roles()
    {
        if (! function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        return get_editable_roles();
    }

    /**
     * Comprueba si un rol existe.
     *
     * @param string $role
     * @return bool
     */
    public function role_exists($role)
    {
        $roles = $this->get_editable_roles();

        return isset($roles[$role]);
    }

    /**
     * Crea un rol con las capacidades proporcionadas.
     *
     * @param string       $role
     * @param string       $display_name
     * @param string|array $capabilities
     * @return true|WP_Error
     */
    public function create_role($role, $display_name, $capabilities)
    {
        if ($this->role_exists($role)) {
            return new WP_Error('mads_role_creator_role_exists', __('El rol indicado ya existe.', 'mad-suite'));
        }

        $caps = $this->prepare_capabilities($capabilities);

        $result = add_role($role, $display_name, $caps);

        if ($result instanceof \WP_Role) {
            return true;
        }

        return new WP_Error('mads_role_creator_role_failed', __('No se pudo crear el rol, revisa las capacidades ingresadas.', 'mad-suite'));
    }

    private function prepare_capabilities($capabilities)
    {
        if (is_string($capabilities)) {
            $capabilities = preg_split('/[|;,\n]+/', $capabilities);
        }

        $capabilities = array_filter(array_map('sanitize_key', (array) $capabilities));
        $capabilities = array_unique($capabilities);

        if (! in_array('read', $capabilities, true)) {
            array_unshift($capabilities, 'read');
        }

        $prepared = [];
        foreach ($capabilities as $cap) {
            $prepared[$cap] = true;
        }

        return $prepared;
    }
}