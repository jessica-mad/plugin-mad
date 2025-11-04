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
     * @param array $rule_data ['name', 'role', 'conditions', 'active', 'source_role', 'replace_source_role']
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

        // Verificar que el rol destino existe
        if (! RoleManager::instance()->role_exists($rule_data['role'])) {
            return new WP_Error('invalid_role', __('El rol seleccionado no existe.', 'mad-suite'));
        }

        // Validar rol de origen (si está especificado)
        $source_role = isset($rule_data['source_role']) && ! empty($rule_data['source_role'])
            ? sanitize_key($rule_data['source_role'])
            : null;

        if ($source_role && ! RoleManager::instance()->role_exists($source_role)) {
            return new WP_Error('invalid_source_role', __('El rol de origen seleccionado no existe.', 'mad-suite'));
        }

        // Validar que el rol de origen sea diferente al rol destino
        if ($source_role && $source_role === $rule_data['role']) {
            return new WP_Error('same_roles', __('El rol de origen y el rol destino no pueden ser iguales.', 'mad-suite'));
        }

        // Validar condiciones
        $conditions = isset($rule_data['conditions']) ? $rule_data['conditions'] : [];
        $min_spent  = isset($conditions['min_spent']) ? (float) $conditions['min_spent'] : 0;
        $max_spent  = isset($conditions['max_spent']) ? (float) $conditions['max_spent'] : 0;
        $min_orders = isset($conditions['min_orders']) ? (int) $conditions['min_orders'] : 0;
        $max_orders = isset($conditions['max_orders']) ? (int) $conditions['max_orders'] : 0;

        if ($min_spent <= 0 && $min_orders <= 0) {
            return new WP_Error('invalid_conditions', __('Debes especificar al menos una condición mínima válida (monto o cantidad de pedidos).', 'mad-suite'));
        }

        // Validar rangos (máximo debe ser mayor que mínimo si está especificado)
        if ($max_spent > 0 && $max_spent < $min_spent) {
            return new WP_Error('invalid_range', __('El monto máximo debe ser mayor que el monto mínimo.', 'mad-suite'));
        }
        if ($max_orders > 0 && $max_orders < $min_orders) {
            return new WP_Error('invalid_range', __('La cantidad máxima de pedidos debe ser mayor que la mínima.', 'mad-suite'));
        }

        // Crear la regla
        $rule = [
            'id'                  => $this->generate_rule_id(),
            'name'                => sanitize_text_field($rule_data['name']),
            'role'                => sanitize_key($rule_data['role']),
            'source_role'         => $source_role,
            'replace_source_role' => isset($rule_data['replace_source_role']) ? (bool) $rule_data['replace_source_role'] : false,
            'conditions'          => [
                'min_spent'  => $min_spent,
                'max_spent'  => $max_spent,
                'min_orders' => $min_orders,
                'max_orders' => $max_orders,
                'operator'   => isset($conditions['operator']) ? strtoupper($conditions['operator']) : 'AND',
            ],
            'active'              => isset($rule_data['active']) ? (bool) $rule_data['active'] : true,
            'created_at'          => current_time('timestamp'),
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

                if (isset($rule_data['source_role'])) {
                    $source_role = ! empty($rule_data['source_role']) ? sanitize_key($rule_data['source_role']) : null;
                    if ($source_role && ! RoleManager::instance()->role_exists($source_role)) {
                        return new WP_Error('invalid_source_role', __('El rol de origen seleccionado no existe.', 'mad-suite'));
                    }
                    $rules[$index]['source_role'] = $source_role;
                }

                if (isset($rule_data['replace_source_role'])) {
                    $rules[$index]['replace_source_role'] = (bool) $rule_data['replace_source_role'];
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
     * Obtiene la cadena de transformación de roles (secuencia)
     * Útil para mostrar el flujo: customer → vip1 → vip2 → vip3
     *
     * @return array Array de cadenas de transformación
     */
    public function get_transformation_chains()
    {
        $all_rules = $this->get_all_rules();
        $chains = [];

        // Construir un mapa de transformaciones
        foreach ($all_rules as $rule) {
            $source = isset($rule['source_role']) ? $rule['source_role'] : null;
            $target = $rule['role'];

            if ($source) {
                $chains[] = [
                    'from'  => $source,
                    'to'    => $target,
                    'rule'  => $rule,
                ];
            }
        }

        return $chains;
    }

    /**
     * Verifica si existe una transformación circular (A → B → A)
     *
     * @param string $from_role
     * @param string $to_role
     * @return bool
     */
    public function has_circular_dependency($from_role, $to_role)
    {
        $all_rules = $this->get_all_rules();
        $visited = [];

        return $this->check_circular_recursive($from_role, $to_role, $all_rules, $visited);
    }

    /**
     * Verifica recursivamente si existe dependencia circular
     *
     * @param string $current
     * @param string $target
     * @param array  $rules
     * @param array  $visited
     * @return bool
     */
    private function check_circular_recursive($current, $target, $rules, &$visited)
    {
        if ($current === $target) {
            return true;
        }

        if (in_array($current, $visited, true)) {
            return false;
        }

        $visited[] = $current;

        // Buscar reglas donde el rol actual sea el destino
        foreach ($rules as $rule) {
            if ($rule['role'] === $current && isset($rule['source_role'])) {
                if ($this->check_circular_recursive($rule['source_role'], $target, $rules, $visited)) {
                    return true;
                }
            }
        }

        return false;
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
