<?php
/**
 * Vista: Lista de Reglas de Descuento
 */

defined('ABSPATH') || exit;

$rules = get_option('mad_private_shop_rules', []);

// Mensajes
if (isset($_GET['saved'])) {
    echo '<div class="notice notice-success is-dismissible"><p><strong>✓ Regla guardada correctamente</strong></p></div>';
}
if (isset($_GET['deleted'])) {
    echo '<div class="notice notice-success is-dismissible"><p><strong>✓ Regla eliminada correctamente</strong></p></div>';
}
?>

<div class="wrap">
    <h1>
        🔒 Private Shop - Reglas de Descuento
        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'new'], admin_url('admin.php')); ?>" class="page-title-action">
            ➕ Nueva Regla
        </a>
    </h1>
    
    <!-- Tabs de navegación -->
    <nav class="nav-tab-wrapper" style="margin: 20px 0;">
        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop'], admin_url('admin.php')); ?>"
           class="nav-tab nav-tab-active">
            📋 Reglas de Descuento
        </a>
        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'coupons'], admin_url('admin.php')); ?>"
           class="nav-tab">
            🎫 Cupones Generados
        </a>
        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'logs'], admin_url('admin.php')); ?>"
           class="nav-tab">
            🔍 Logs de Debug
        </a>
        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'test'], admin_url('admin.php')); ?>"
           class="nav-tab">
            🧪 Test & Diagnóstico
        </a>
    </nav>
    
    <!-- Link a logs -->
    <div class="notice notice-info" style="margin: 20px 0;">
        <p>
            📋 <strong>Logs del sistema:</strong> 
            <a href="<?php echo esc_url($this->get_log_url()); ?>" target="_blank">Ver log de hoy</a>
            <span style="color: #666; margin-left: 10px;">(Descarga el archivo para ver el contenido completo)</span>
        </p>
    </div>
    
    <?php if (empty($rules)): ?>
        <!-- Estado vacío -->
        <div class="card" style="max-width: 100%; margin-top: 20px; text-align: center; padding: 60px 20px;">
            <div style="font-size: 64px; margin-bottom: 20px;">🎯</div>
            <h2>No hay reglas de descuento configuradas</h2>
            <p style="color: #666; margin-bottom: 30px;">
                Crea tu primera regla de descuento para empezar a ofrecer precios especiales<br>
                a tus clientes según categorías, etiquetas o productos específicos.
            </p>
            <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'new'], admin_url('admin.php')); ?>" class="button button-primary button-large">
                ➕ Crear Primera Regla
            </a>
        </div>
    <?php else: ?>
        <!-- Tabla de reglas -->
        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">Estado</th>
                        <th>Nombre de la Regla</th>
                        <th style="width: 120px;">Descuento</th>
                        <th style="width: 120px;">Aplica a</th>
                        <th style="width: 150px;">Roles</th>
                        <th style="width: 100px;">Prioridad</th>
                        <th style="width: 100px;">Prefijo</th>
                        <th style="width: 150px;">Fechas</th>
                        <th style="width: 150px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rules as $rule): 
                        $is_enabled = isset($rule['enabled']) && $rule['enabled'];
                        $toggle_url = wp_nonce_url(
                            add_query_arg([
                                'action' => 'toggle_private_shop_rule',
                                'rule_id' => $rule['id']
                            ], admin_url('admin-post.php')),
                            'toggle_rule',
                            'nonce'
                        );
                        $delete_url = wp_nonce_url(
                            add_query_arg([
                                'action' => 'delete_private_shop_rule',
                                'rule_id' => $rule['id']
                            ], admin_url('admin-post.php')),
                            'delete_rule',
                            'nonce'
                        );
                        $edit_url = add_query_arg([
                            'page' => 'mad-private-shop',
                            'action' => 'edit',
                            'rule_id' => $rule['id']
                        ], admin_url('admin.php'));
                        
                        // NUEVO: Obtener prefijo del cupón
                        $coupon_prefix = isset($rule['coupon_config']['prefix']) ? $rule['coupon_config']['prefix'] : 'ps';
                    ?>
                        <tr class="<?php echo !$is_enabled ? 'inactive' : ''; ?>">
                            <td style="text-align: center;">
                                <a href="<?php echo esc_url($toggle_url); ?>" title="<?php echo $is_enabled ? 'Desactivar' : 'Activar'; ?>">
                                    <?php if ($is_enabled): ?>
                                        <span style="color: green; font-size: 20px;">●</span>
                                    <?php else: ?>
                                        <span style="color: #ccc; font-size: 20px;">○</span>
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td>
                                <strong><?php echo esc_html($rule['name']); ?></strong>
                                <?php if (!$is_enabled): ?>
                                    <span style="color: #999; font-size: 12px;">(Inactiva)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rule['discount_type'] === 'percentage'): ?>
                                    <strong style="color: #2196F3;"><?php echo $rule['discount_value']; ?>%</strong>
                                <?php else: ?>
                                    <strong style="color: #4CAF50;"><?php echo wc_price($rule['discount_value']); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $apply_labels = [
                                    'products' => '📦 Productos',
                                    'categories' => '📁 Categorías',
                                    'tags' => '🏷️ Etiquetas'
                                ];
                                echo isset($apply_labels[$rule['apply_to']]) ? $apply_labels[$rule['apply_to']] : $rule['apply_to'];
                                ?>
                                <div style="color: #666; font-size: 12px;">
                                    <?php echo count($rule['target_ids']); ?> seleccionados
                                </div>
                            </td>
                            <td>
                                <?php
                                if (!empty($rule['roles'])) {
                                    $role_names = array_map(function($role_key) {
                                        $role = get_role($role_key);
                                        return $role ? ucfirst($role_key) : $role_key;
                                    }, $rule['roles']);
                                    echo esc_html(implode(', ', array_slice($role_names, 0, 2)));
                                    if (count($role_names) > 2) {
                                        echo ' +' . (count($role_names) - 2);
                                    }
                                } else {
                                    echo '<span style="color: #999;">Todos</span>';
                                }
                                ?>
                            </td>
                            <td style="text-align: center;">
                                <?php echo isset($rule['priority']) ? $rule['priority'] : 10; ?>
                            </td>
                            <td>
                                <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">
                                    <?php echo esc_html($coupon_prefix); ?>_*
                                </code>
                            </td>
                            <td style="font-size: 12px;">
                                <?php if (!empty($rule['date_from']) || !empty($rule['date_to'])): ?>
                                    <?php if (!empty($rule['date_from'])): ?>
                                        Desde: <?php echo date('d/m/Y', strtotime($rule['date_from'])); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($rule['date_to'])): ?>
                                        Hasta: <?php echo date('d/m/Y', strtotime($rule['date_to'])); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #999;">Siempre activa</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                                    ✏️ Editar
                                </a>
                                <a href="<?php echo esc_url($delete_url); ?>" 
                                   class="button button-small button-link-delete"
                                   onclick="return confirm('¿Eliminar esta regla?\n\nSe eliminarán también todos los cupones generados.\n\nNombre: <?php echo esc_js($rule['name']); ?>');">
                                    🗑️
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Resumen estadístico -->
        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <h2>📊 Resumen</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div style="padding: 15px; background: #e3f2fd; border-radius: 4px;">
                    <div style="font-size: 32px; font-weight: bold; color: #1976d2;">
                        <?php echo count($rules); ?>
                    </div>
                    <div style="color: #666;">Total de reglas</div>
                </div>
                <div style="padding: 15px; background: #e8f5e9; border-radius: 4px;">
                    <div style="font-size: 32px; font-weight: bold; color: #388e3c;">
                        <?php echo count(array_filter($rules, function($r) { return isset($r['enabled']) && $r['enabled']; })); ?>
                    </div>
                    <div style="color: #666;">Reglas activas</div>
                </div>
                <div style="padding: 15px; background: #fff3e0; border-radius: 4px;">
                    <div style="font-size: 32px; font-weight: bold; color: #f57c00;">
                        <?php 
                        $total_targets = array_sum(array_map(function($r) { 
                            return count($r['target_ids']); 
                        }, $rules));
                        echo $total_targets;
                        ?>
                    </div>
                    <div style="color: #666;">Items con descuento</div>
                </div>
                <div style="padding: 15px; background: #f3e5f5; border-radius: 4px;">
                    <div style="font-size: 32px; font-weight: bold; color: #7b1fa2;">
                        <?php 
                        // NUEVO: Contar cupones generados
                        $rule_coupons = get_option('mad_private_shop_rule_coupons', []);
                        $total_coupons = 0;
                        foreach ($rule_coupons as $data) {
                            if (isset($data['user_coupons'])) {
                                $total_coupons += count($data['user_coupons']);
                            }
                        }
                        echo $total_coupons;
                        ?>
                    </div>
                    <div style="color: #666;">
                        Cupones generados
                        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'coupons'], admin_url('admin.php')); ?>" 
                           style="margin-left: 5px;">Ver →</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.wp-list-table tr.inactive {
    opacity: 0.6;
}
.button-link-delete {
    color: #a00;
}
.button-link-delete:hover {
    color: #dc3232;
}
</style>