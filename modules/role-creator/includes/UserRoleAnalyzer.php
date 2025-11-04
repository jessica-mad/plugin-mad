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
     * @param array $conditions ['min_spent' => float, 'min_orders' => int, 'operator' => 'AND'|'OR']
     * @return bool
     */
    public function user_meets_conditions($user_id, $conditions)
    {
        if (empty($conditions)) {
            return false;
        }

        $min_spent  = isset($conditions['min_spent']) ? (float) $conditions['min_spent'] : 0;
        $min_orders = isset($conditions['min_orders']) ? (int) $conditions['min_orders'] : 0;
        $operator   = isset($conditions['operator']) ? strtoupper($conditions['operator']) : 'AND';

        // Si ambas condiciones son 0, no tiene sentido evaluarlas
        if ($min_spent <= 0 && $min_orders <= 0) {
            return false;
        }

        $meets_spent  = true;
        $meets_orders = true;

        // Evaluar condición de gasto mínimo
        if ($min_spent > 0) {
            $total_spent  = $this->get_user_total_spent($user_id);
            $meets_spent  = $total_spent >= $min_spent;
        }

        // Evaluar condición de pedidos mínimos
        if ($min_orders > 0) {
            $order_count  = $this->get_user_order_count($user_id);
            $meets_orders = $order_count >= $min_orders;
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
}
