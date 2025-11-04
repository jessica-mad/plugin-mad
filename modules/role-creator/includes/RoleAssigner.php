<?php
namespace MAD_Suite\Modules\RoleCreator;

use WP_Error;
use WP_User;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Gestiona la asignación y remoción de roles de usuarios
 */
class RoleAssigner
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
     * Asigna un rol a uno o múltiples usuarios
     *
     * @param array|int $user_ids
     * @param string    $role
     * @param bool      $remove_existing Si true, remueve roles existentes antes de asignar
     * @return array ['success' => int, 'errors' => array]
     */
    public function assign_role_to_users($user_ids, $role, $remove_existing = false)
    {
        if (! is_array($user_ids)) {
            $user_ids = [$user_ids];
        }

        // Verificar que el rol existe
        if (! RoleManager::instance()->role_exists($role)) {
            return [
                'success' => 0,
                'errors'  => [__('El rol especificado no existe.', 'mad-suite')],
            ];
        }

        $success = 0;
        $errors  = [];

        foreach ($user_ids as $user_id) {
            $user = get_user_by('id', $user_id);

            if (! $user instanceof WP_User) {
                $errors[] = sprintf(__('El usuario con ID %d no existe.', 'mad-suite'), $user_id);
                continue;
            }

            try {
                if ($remove_existing) {
                    // Remover todos los roles existentes
                    $existing_roles = $user->roles;
                    foreach ($existing_roles as $existing_role) {
                        $user->remove_role($existing_role);
                    }
                }

                // Asignar el nuevo rol
                $user->add_role($role);
                $success++;
            } catch (\Exception $e) {
                $errors[] = sprintf(__('Error al asignar rol al usuario %d: %s', 'mad-suite'), $user_id, $e->getMessage());
            }
        }

        return [
            'success' => $success,
            'errors'  => $errors,
        ];
    }

    /**
     * Remueve un rol de uno o múltiples usuarios
     *
     * @param array|int $user_ids
     * @param string    $role
     * @return array ['success' => int, 'errors' => array]
     */
    public function remove_role_from_users($user_ids, $role)
    {
        if (! is_array($user_ids)) {
            $user_ids = [$user_ids];
        }

        $success = 0;
        $errors  = [];

        foreach ($user_ids as $user_id) {
            $user = get_user_by('id', $user_id);

            if (! $user instanceof WP_User) {
                $errors[] = sprintf(__('El usuario con ID %d no existe.', 'mad-suite'), $user_id);
                continue;
            }

            try {
                $user->remove_role($role);
                $success++;
            } catch (\Exception $e) {
                $errors[] = sprintf(__('Error al remover rol del usuario %d: %s', 'mad-suite'), $user_id, $e->getMessage());
            }
        }

        return [
            'success' => $success,
            'errors'  => $errors,
        ];
    }

    /**
     * Evalúa y aplica todas las reglas automáticas activas
     *
     * @return array ['assigned' => int, 'rules_processed' => int, 'errors' => array]
     */
    public function apply_automatic_rules()
    {
        $rule_engine = RoleRule::instance();
        $results     = $rule_engine->evaluate_all_rules();

        $total_assigned     = 0;
        $rules_processed    = 0;
        $errors             = [];

        foreach ($results as $rule_id => $data) {
            $rule       = $data['rule'];
            $user_ids   = $data['users'];

            if (empty($user_ids)) {
                continue;
            }

            // Asignar el rol a los usuarios que cumplen la condición
            $result = $this->assign_role_to_users($user_ids, $rule['role'], false);

            $total_assigned  += $result['success'];
            $rules_processed++;

            if (! empty($result['errors'])) {
                $errors = array_merge($errors, $result['errors']);
            }
        }

        return [
            'assigned'        => $total_assigned,
            'rules_processed' => $rules_processed,
            'errors'          => $errors,
        ];
    }

    /**
     * Aplica una regla específica
     *
     * @param string $rule_id
     * @return array|WP_Error
     */
    public function apply_single_rule($rule_id)
    {
        $rule_engine = RoleRule::instance();
        $rule        = $rule_engine->get_rule($rule_id);

        if (! $rule) {
            return new WP_Error('rule_not_found', __('La regla no existe.', 'mad-suite'));
        }

        if (! isset($rule['active']) || ! $rule['active']) {
            return new WP_Error('rule_inactive', __('La regla está desactivada.', 'mad-suite'));
        }

        // Obtener usuarios que cumplen las condiciones
        $analyzer   = UserRoleAnalyzer::instance();
        $user_ids   = $analyzer->get_users_meeting_conditions($rule['conditions']);

        if (empty($user_ids)) {
            return [
                'assigned' => 0,
                'message'  => __('No hay usuarios que cumplan las condiciones de esta regla.', 'mad-suite'),
            ];
        }

        // Asignar el rol
        $result = $this->assign_role_to_users($user_ids, $rule['role'], false);

        return [
            'assigned' => $result['success'],
            'errors'   => $result['errors'],
            'message'  => sprintf(
                __('Se asignó el rol a %d usuario(s).', 'mad-suite'),
                $result['success']
            ),
        ];
    }

    /**
     * Obtiene usuarios que tienen un rol específico
     *
     * @param string $role
     * @return array
     */
    public function get_users_by_role($role)
    {
        $users = get_users([
            'role'   => $role,
            'fields' => ['ID', 'user_email', 'display_name'],
        ]);

        return $users;
    }

    /**
     * Verifica si un usuario tiene un rol específico
     *
     * @param int    $user_id
     * @param string $role
     * @return bool
     */
    public function user_has_role($user_id, $role)
    {
        $user = get_user_by('id', $user_id);

        if (! $user instanceof WP_User) {
            return false;
        }

        return in_array($role, $user->roles, true);
    }
}
