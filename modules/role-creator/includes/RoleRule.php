<?php
namespace MAD_Suite\Modules\RoleCreator;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Gestiona reglas de asignación automática de roles
 */
class RoleRule
{
    private static $instance;
    private const OPTION_KEY = 'madsuite_role_creator_rules';

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Obtiene todas las reglas guardadas
     *
     * @return array
     */
    public function get_all_rules()
    {
        $rules = get_option(self::OPTION_KEY, []);

        return is_array($rules) ? $rules : [];
    }

    /**
     * Obtiene una regla por su ID
     *
     * @param string $rule_id
     * @return array|null
     */
    public function get_rule($rule_id)
    {
        $rules = $this->get_all_rules();

        foreach ($rules as $rule) {
            if (isset($rule['id']) && $rule['id'] === $rule_id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Crea una nueva regla
     *
     * @param array $rule_data ['name', 'role', 'conditions', 'active']
     * @return true|WP_Error
     */
    public function create_rule($rule_data)
    {
        // Validar datos requeridos
        if (empty($rule_data['name'])) {
            return new WP_Error('missing_name', __('El nombre de la regla es obligatorio.', 'mad-suite'));
        }

        if (empty($rule_data['role'])) {
            return new WP_Error('missing_role', __('Debes seleccionar un rol para asignar.', 'mad-suite'));
        }

        // Verificar que el rol existe
        if (! RoleManager::instance()->role_exists($rule_data['role'])) {
            return new WP_Error('invalid_role', __('El rol seleccionado no existe.', 'mad-suite'));
        }

        // Validar condiciones
        $conditions = isset($rule_data['conditions']) ? $rule_data['conditions'] : [];
        $min_spent  = isset($conditions['min_spent']) ? (float) $conditions['min_spent'] : 0;
        $min_orders = isset($conditions['min_orders']) ? (int) $conditions['min_orders'] : 0;

        if ($min_spent <= 0 && $min_orders <= 0) {
            return new WP_Error('invalid_conditions', __('Debes especificar al menos una condición válida (monto o cantidad de pedidos).', 'mad-suite'));
        }

        // Crear la regla
        $rule = [
            'id'         => $this->generate_rule_id(),
            'name'       => sanitize_text_field($rule_data['name']),
            'role'       => sanitize_key($rule_data['role']),
            'conditions' => [
                'min_spent'  => $min_spent,
                'min_orders' => $min_orders,
                'operator'   => isset($conditions['operator']) ? strtoupper($conditions['operator']) : 'AND',
            ],
            'active'     => isset($rule_data['active']) ? (bool) $rule_data['active'] : true,
            'created_at' => current_time('timestamp'),
        ];

        // Guardar
        $rules   = $this->get_all_rules();
        $rules[] = $rule;

        update_option(self::OPTION_KEY, $rules);

        return true;
    }

    /**
     * Actualiza una regla existente
     *
     * @param string $rule_id
     * @param array  $rule_data
     * @return true|WP_Error
     */
    public function update_rule($rule_id, $rule_data)
    {
        $rules = $this->get_all_rules();
        $found = false;

        foreach ($rules as $index => $rule) {
            if (isset($rule['id']) && $rule['id'] === $rule_id) {
                // Validaciones similares a create_rule
                if (isset($rule_data['name'])) {
                    $rules[$index]['name'] = sanitize_text_field($rule_data['name']);
                }

                if (isset($rule_data['role'])) {
                    if (! RoleManager::instance()->role_exists($rule_data['role'])) {
                        return new WP_Error('invalid_role', __('El rol seleccionado no existe.', 'mad-suite'));
                    }
                    $rules[$index]['role'] = sanitize_key($rule_data['role']);
                }

                if (isset($rule_data['conditions'])) {
                    $rules[$index]['conditions'] = $rule_data['conditions'];
                }

                if (isset($rule_data['active'])) {
                    $rules[$index]['active'] = (bool) $rule_data['active'];
                }

                $found = true;
                break;
            }
        }

        if (! $found) {
            return new WP_Error('rule_not_found', __('La regla no existe.', 'mad-suite'));
        }

        update_option(self::OPTION_KEY, $rules);

        return true;
    }

    /**
     * Elimina una regla
     *
     * @param string $rule_id
     * @return true|WP_Error
     */
    public function delete_rule($rule_id)
    {
        $rules = $this->get_all_rules();
        $new_rules = [];

        foreach ($rules as $rule) {
            if (! isset($rule['id']) || $rule['id'] !== $rule_id) {
                $new_rules[] = $rule;
            }
        }

        if (count($new_rules) === count($rules)) {
            return new WP_Error('rule_not_found', __('La regla no existe.', 'mad-suite'));
        }

        update_option(self::OPTION_KEY, $new_rules);

        return true;
    }

    /**
     * Alterna el estado activo de una regla
     *
     * @param string $rule_id
     * @return true|WP_Error
     */
    public function toggle_rule_status($rule_id)
    {
        $rule = $this->get_rule($rule_id);

        if (! $rule) {
            return new WP_Error('rule_not_found', __('La regla no existe.', 'mad-suite'));
        }

        $new_status = ! (isset($rule['active']) && $rule['active']);

        return $this->update_rule($rule_id, ['active' => $new_status]);
    }

    /**
     * Obtiene solo las reglas activas
     *
     * @return array
     */
    public function get_active_rules()
    {
        $all_rules = $this->get_all_rules();
        $active_rules = [];

        foreach ($all_rules as $rule) {
            if (isset($rule['active']) && $rule['active']) {
                $active_rules[] = $rule;
            }
        }

        return $active_rules;
    }

    /**
     * Evalúa todas las reglas activas y devuelve los usuarios que deben ser asignados a roles
     *
     * @return array ['rule_id' => [user_ids], ...]
     */
    public function evaluate_all_rules()
    {
        $active_rules = $this->get_active_rules();
        $analyzer     = UserRoleAnalyzer::instance();
        $results      = [];

        foreach ($active_rules as $rule) {
            $matching_users = $analyzer->get_users_meeting_conditions($rule['conditions']);

            if (! empty($matching_users)) {
                $results[$rule['id']] = [
                    'rule'  => $rule,
                    'users' => $matching_users,
                ];
            }
        }

        return $results;
    }

    /**
     * Genera un ID único para una regla
     *
     * @return string
     */
    private function generate_rule_id()
    {
        return 'rule_' . uniqid() . '_' . wp_generate_password(8, false);
    }
}
