<?php
namespace MAD_Suite\Modules\RoleCreator;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Analiza datos de WooCommerce para determinar características de usuarios
 */
class UserRoleAnalyzer
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
     * Obtiene el total gastado por un usuario en WooCommerce
     *
     * @param int $user_id
     * @return float
     */
    public function get_user_total_spent($user_id)
    {
        if (! function_exists('wc_get_customer_total_spent')) {
            return 0.0;
        }

        return (float) wc_get_customer_total_spent($user_id);
    }

    /**
     * Obtiene la cantidad de pedidos completados de un usuario
     *
     * @param int $user_id
     * @return int
     */
    public function get_user_order_count($user_id)
    {
        if (! function_exists('wc_get_customer_order_count')) {
            return 0;
        }

        return (int) wc_get_customer_order_count($user_id);
    }

    /**
     * Verifica si un usuario cumple con condiciones específicas
     *
     * @param int   $user_id
     * @param array $conditions ['min_spent' => float, 'max_spent' => float, 'min_orders' => int, 'max_orders' => int, 'operator' => 'AND'|'OR']
     * @return bool
     */
    public function user_meets_conditions($user_id, $conditions)
    {
        if (empty($conditions)) {
            return false;
        }

        $min_spent  = isset($conditions['min_spent']) ? (float) $conditions['min_spent'] : 0;
        $max_spent  = isset($conditions['max_spent']) ? (float) $conditions['max_spent'] : 0;
        $min_orders = isset($conditions['min_orders']) ? (int) $conditions['min_orders'] : 0;
        $max_orders = isset($conditions['max_orders']) ? (int) $conditions['max_orders'] : 0;
        $operator   = isset($conditions['operator']) ? strtoupper($conditions['operator']) : 'AND';

        // Si ambas condiciones mínimas son 0, no tiene sentido evaluarlas
        if ($min_spent <= 0 && $min_orders <= 0) {
            return false;
        }

        $meets_spent  = true;
        $meets_orders = true;

        // Evaluar condición de gasto (rango o solo mínimo)
        if ($min_spent > 0 || $max_spent > 0) {
            $total_spent = $this->get_user_total_spent($user_id);

            if ($min_spent > 0 && $max_spent > 0) {
                // Rango: entre mínimo y máximo
                $meets_spent = $total_spent >= $min_spent && $total_spent <= $max_spent;
            } elseif ($min_spent > 0) {
                // Solo mínimo: mayor o igual
                $meets_spent = $total_spent >= $min_spent;
            } elseif ($max_spent > 0) {
                // Solo máximo: menor o igual
                $meets_spent = $total_spent <= $max_spent;
            }
        }

        // Evaluar condición de pedidos (rango o solo mínimo)
        if ($min_orders > 0 || $max_orders > 0) {
            $order_count = $this->get_user_order_count($user_id);

            if ($min_orders > 0 && $max_orders > 0) {
                // Rango: entre mínimo y máximo
                $meets_orders = $order_count >= $min_orders && $order_count <= $max_orders;
            } elseif ($min_orders > 0) {
                // Solo mínimo: mayor o igual
                $meets_orders = $order_count >= $min_orders;
            } elseif ($max_orders > 0) {
                // Solo máximo: menor o igual
                $meets_orders = $order_count <= $max_orders;
            }
        }

        // Aplicar operador lógico
        if ($operator === 'OR') {
            return $meets_spent || $meets_orders;
        }

        return $meets_spent && $meets_orders;
    }

    /**
     * Obtiene todos los usuarios que cumplen con las condiciones especificadas
     *
     * @param array $conditions
     * @return array Array de user IDs
     */
    public function get_users_meeting_conditions($conditions)
    {
        if (empty($conditions)) {
            return [];
        }

        $users = get_users([
            'fields' => 'ID',
            'number' => -1, // Sin límite
        ]);

        $matching_users = [];

        foreach ($users as $user_id) {
            if ($this->user_meets_conditions($user_id, $conditions)) {
                $matching_users[] = $user_id;
            }
        }

        return $matching_users;
    }

    /**
     * Obtiene estadísticas de un usuario para mostrar en la UI
     *
     * @param int $user_id
     * @return array
     */
    public function get_user_stats($user_id)
    {
        return [
            'total_spent'  => $this->get_user_total_spent($user_id),
            'order_count'  => $this->get_user_order_count($user_id),
            'user_id'      => $user_id,
        ];
    }

    /**
     * Previsualiza cuántos usuarios cumplirían con una regla antes de crearla
     *
     * @param array       $conditions ['min_spent' => float, 'min_orders' => int, 'operator' => 'AND'|'OR']
     * @param string|null $source_role Rol de origen requerido (opcional)
     * @param int         $limit Límite de usuarios de ejemplo a retornar
     * @return array ['total' => int, 'eligible' => int, 'sample_users' => array]
     */
    public function preview_rule_impact($conditions, $source_role = null, $limit = 10)
    {
        // Obtener todos los usuarios que cumplen las condiciones
        $matching_users = $this->get_users_meeting_conditions($conditions);
        $total_matching = count($matching_users);

        // Si no hay rol de origen, todos los que cumplen son elegibles
        if (empty($source_role)) {
            $eligible_users = $matching_users;
        } else {
            // Filtrar por rol de origen
            $eligible_users = [];
            $assigner = RoleAssigner::instance();

            foreach ($matching_users as $user_id) {
                if ($assigner->user_has_role($user_id, $source_role)) {
                    $eligible_users[] = $user_id;
                }
            }
        }

        $total_eligible = count($eligible_users);

        // Obtener muestra de usuarios con sus datos
        $sample_users = [];
        $sample_ids = array_slice($eligible_users, 0, $limit);

        foreach ($sample_ids as $user_id) {
            $user = get_user_by('id', $user_id);
            if ($user) {
                $stats = $this->get_user_stats($user_id);
                $sample_users[] = [
                    'id'           => $user_id,
                    'display_name' => $user->display_name,
                    'email'        => $user->user_email,
                    'roles'        => $user->roles,
                    'total_spent'  => $stats['total_spent'],
                    'order_count'  => $stats['order_count'],
                ];
            }
        }

        return [
            'total'        => $total_matching,
            'eligible'     => $total_eligible,
            'sample_users' => $sample_users,
            'has_filter'   => ! empty($source_role),
            'source_role'  => $source_role,
        ];
    }
}
