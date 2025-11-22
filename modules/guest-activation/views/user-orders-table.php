<?php
/**
 * Vista de tabla de pedidos en perfil de usuario
 */

if (!defined('ABSPATH')) exit;
?>

<h2><?php esc_html_e('Historial de Pedidos WooCommerce', 'mad-suite'); ?></h2>

<div class="mad-user-orders-section">
    <div class="mad-orders-summary">
        <p>
            <strong><?php esc_html_e('Total de pedidos:', 'mad-suite'); ?></strong>
            <?php echo esc_html($total_orders); ?>
        </p>
        <p class="description">
            <?php esc_html_e('Esta tabla muestra todos los pedidos asociados a este usuario, incluyendo aquellos realizados como invitado antes de crear la cuenta.', 'mad-suite'); ?>
        </p>
    </div>

    <table class="widefat mad-orders-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Pedido #', 'mad-suite'); ?></th>
                <th><?php esc_html_e('Fecha', 'mad-suite'); ?></th>
                <th><?php esc_html_e('Estado', 'mad-suite'); ?></th>
                <th><?php esc_html_e('Total', 'mad-suite'); ?></th>
                <th><?php esc_html_e('Items', 'mad-suite'); ?></th>
                <th><?php esc_html_e('Tipo', 'mad-suite'); ?></th>
                <th><?php esc_html_e('Acciones', 'mad-suite'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders_data as $order): ?>
                <tr class="<?php echo $order['was_guest'] ? 'mad-guest-order' : 'mad-account-order'; ?>">
                    <!-- Número de pedido -->
                    <td>
                        <strong>#<?php echo esc_html($order['number']); ?></strong>
                    </td>

                    <!-- Fecha -->
                    <td>
                        <?php
                        $date = new DateTime($order['date']);
                        echo esc_html($date->format('d/m/Y H:i'));
                        ?>
                    </td>

                    <!-- Estado -->
                    <td>
                        <span class="mad-order-status mad-status-<?php echo esc_attr($order['status']); ?>">
                            <?php echo esc_html($order['status_name']); ?>
                        </span>
                    </td>

                    <!-- Total -->
                    <td>
                        <strong>
                            <?php echo wc_price($order['total'], ['currency' => $order['currency']]); ?>
                        </strong>
                    </td>

                    <!-- Items -->
                    <td>
                        <?php echo esc_html($order['items_count']); ?>
                        <?php echo _n('item', 'items', $order['items_count'], 'mad-suite'); ?>
                    </td>

                    <!-- Tipo de pedido -->
                    <td>
                        <?php if (isset($order['not_assigned']) && $order['not_assigned']): ?>
                            <span class="mad-order-type mad-type-unassigned" title="<?php esc_attr_e('Este pedido tiene el mismo email pero no está asignado al usuario', 'mad-suite'); ?>">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e('Sin asignar', 'mad-suite'); ?>
                            </span>
                        <?php elseif ($order['was_guest']): ?>
                            <span class="mad-order-type mad-type-guest" title="<?php esc_attr_e('Pedido realizado como invitado', 'mad-suite'); ?>">
                                <span class="dashicons dashicons-admin-users"></span>
                                <?php esc_html_e('Invitado', 'mad-suite'); ?>
                            </span>
                        <?php else: ?>
                            <span class="mad-order-type mad-type-account" title="<?php esc_attr_e('Pedido realizado con cuenta', 'mad-suite'); ?>">
                                <span class="dashicons dashicons-businessman"></span>
                                <?php esc_html_e('Con cuenta', 'mad-suite'); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Acciones -->
                    <td>
                        <a href="<?php echo esc_url($order['edit_url']); ?>" class="button button-small">
                            <?php esc_html_e('Ver pedido', 'mad-suite'); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Leyenda -->
    <div class="mad-orders-legend">
        <h4><?php esc_html_e('Leyenda:', 'mad-suite'); ?></h4>
        <ul>
            <li>
                <span class="mad-order-type mad-type-account">
                    <span class="dashicons dashicons-businessman"></span>
                    <?php esc_html_e('Con cuenta', 'mad-suite'); ?>
                </span>
                - <?php esc_html_e('Pedido realizado con cuenta de usuario', 'mad-suite'); ?>
            </li>
            <li>
                <span class="mad-order-type mad-type-guest">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php esc_html_e('Invitado', 'mad-suite'); ?>
                </span>
                - <?php esc_html_e('Pedido realizado como invitado y luego asignado', 'mad-suite'); ?>
            </li>
            <li>
                <span class="mad-order-type mad-type-unassigned">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e('Sin asignar', 'mad-suite'); ?>
                </span>
                - <?php esc_html_e('Pedido de invitado pendiente de asignar', 'mad-suite'); ?>
            </li>
        </ul>
    </div>
</div>

<style>
.mad-user-orders-section {
    margin-top: 20px;
    background: #fff;
    padding: 20px;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.mad-orders-summary {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e5e5e5;
}

.mad-orders-summary p {
    margin: 5px 0;
}

.mad-orders-table {
    margin-top: 15px;
    border: 1px solid #c3c4c7;
}

.mad-orders-table thead th {
    background: #f6f7f7;
    font-weight: 600;
    padding: 10px;
    border-bottom: 1px solid #c3c4c7;
}

.mad-orders-table tbody td {
    padding: 12px 10px;
    border-bottom: 1px solid #e5e5e5;
    vertical-align: middle;
}

.mad-orders-table tbody tr:hover {
    background: #f9f9f9;
}

/* Tipo de pedido */
.mad-guest-order {
    background: #fff9e6;
}

.mad-order-type {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
}

.mad-order-type .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.mad-type-account {
    background: #e7f5fe;
    color: #0073aa;
    border: 1px solid #b3d9f0;
}

.mad-type-guest {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

.mad-type-unassigned {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Estado del pedido */
.mad-order-status {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.mad-status-completed {
    background: #d4edda;
    color: #155724;
}

.mad-status-processing {
    background: #d1ecf1;
    color: #0c5460;
}

.mad-status-pending {
    background: #fff3cd;
    color: #856404;
}

.mad-status-on-hold {
    background: #f8d7da;
    color: #721c24;
}

.mad-status-cancelled,
.mad-status-refunded,
.mad-status-failed {
    background: #f5c6cb;
    color: #721c24;
}

/* Leyenda */
.mad-orders-legend {
    margin-top: 20px;
    padding: 15px;
    background: #f0f0f1;
    border-radius: 4px;
}

.mad-orders-legend h4 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 14px;
}

.mad-orders-legend ul {
    margin: 0;
    padding-left: 20px;
}

.mad-orders-legend li {
    margin-bottom: 8px;
    font-size: 13px;
}

/* Responsive */
@media (max-width: 782px) {
    .mad-orders-table {
        font-size: 12px;
    }

    .mad-orders-table thead th,
    .mad-orders-table tbody td {
        padding: 8px 5px;
    }

    .mad-order-type {
        font-size: 11px;
        padding: 3px 6px;
    }
}
</style>
