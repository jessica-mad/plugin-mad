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

    /**
     * Elimina un rol (con precaución)
     *
     * @param string $role
     * @return true|WP_Error
     */
    public function delete_role($role)
    {
        if (! $this->role_exists($role)) {
            return new WP_Error('mads_role_creator_role_not_exists', __('El rol no existe.', 'mad-suite'));
        }

        // Proteger roles críticos de WordPress
        $protected_roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager'];
        if (in_array($role, $protected_roles, true)) {
            return new WP_Error('mads_role_creator_protected_role', __('No se pueden eliminar roles protegidos del sistema.', 'mad-suite'));
        }

        remove_role($role);

        return true;
    }

    /**
     * Obtiene la cantidad de usuarios con un rol específico
     *
     * @param string $role
     * @return int
     */
    public function get_role_user_count($role)
    {
        $users = get_users([
            'role'   => $role,
            'fields' => 'ID',
        ]);

        return count($users);
    }

    /**
     * Obtiene información detallada de un rol
     *
     * @param string $role_slug
     * @return array|null
     */
    public function get_role_details($role_slug)
    {
        $roles = $this->get_editable_roles();

        if (! isset($roles[$role_slug])) {
            return null;
        }

        $role_data = $roles[$role_slug];
        $user_count = $this->get_role_user_count($role_slug);

        return [
            'slug'         => $role_slug,
            'name'         => $role_data['name'],
            'capabilities' => $role_data['capabilities'],
            'user_count'   => $user_count,
        ];
    }

    /**
     * Actualiza las capacidades de un rol existente
     *
     * @param string       $role
     * @param string|array $capabilities
     * @return true|WP_Error
     */
    public function update_role_capabilities($role, $capabilities)
    {
        if (! $this->role_exists($role)) {
            return new WP_Error('mads_role_creator_role_not_exists', __('El rol no existe.', 'mad-suite'));
        }

        $caps = $this->prepare_capabilities($capabilities);
        $role_obj = get_role($role);

        if (! $role_obj) {
            return new WP_Error('mads_role_creator_role_failed', __('No se pudo obtener el rol.', 'mad-suite'));
        }

        // Remover capacidades existentes
        foreach (array_keys($role_obj->capabilities) as $cap) {
            $role_obj->remove_cap($cap);
        }

        // Agregar nuevas capacidades
        foreach ($caps as $cap => $grant) {
            $role_obj->add_cap($cap, $grant);
        }

        return true;
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