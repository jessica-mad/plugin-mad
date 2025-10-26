<?php
/**
 * Vista: Lista de Cupones Generados
 */

defined('ABSPATH') || exit;

// Mensajes
if (isset($_GET['regenerated'])) {
    echo '<div class="notice notice-success is-dismissible"><p><strong>✓ Cupón regenerado correctamente</strong></p></div>';
}
if (isset($_GET['deleted_coupon'])) {
    echo '<div class="notice notice-success is-dismissible"><p><strong>✓ Cupón eliminado correctamente</strong></p></div>';
}

// Obtener datos
$rules = get_option('mad_private_shop_rules', []);
$rule_coupons = get_option('mad_private_shop_rule_coupons', []);

// Filtros
$filter_rule = isset($_GET['filter_rule']) ? sanitize_text_field($_GET['filter_rule']) : '';

// Recopilar todos los cupones
$all_coupons = [];
foreach ($rule_coupons as $rule_id => $data) {
    if (!isset($rules[$rule_id])) {
        continue; // Regla eliminada
    }
    
    $rule = $rules[$rule_id];
    
    if (!empty($filter_rule) && $filter_rule !== $rule_id) {
        continue; // Filtro aplicado
    }
    
    if (isset($data['user_coupons']) && is_array($data['user_coupons'])) {
        foreach ($data['user_coupons'] as $user_id => $coupon_code) {
            $user = get_userdata($user_id);
            if (!$user) {
                continue;
            }
            
            // Buscar cupón en WooCommerce
            $coupon_post = get_page_by_title($coupon_code, OBJECT, 'shop_coupon');
            if (!$coupon_post) {
                continue; // Cupón eliminado
            }
            
            $coupon_id = $coupon_post->ID;
            
            try {
                $coupon = new WC_Coupon($coupon_id);
                if (!$coupon->get_id()) {
                    continue;
                }
                
                $usage_count = $coupon->get_usage_count();
                
                // Calcular valor de compras
                $orders = wc_get_orders([
                    'limit' => -1,
                    'coupon' => $coupon_code,
                    'status' => ['completed', 'processing']
                ]);
                
                $total_value = 0;
                foreach ($orders as $order) {
                    $total_value += $order->get_total();
                }
                
                $all_coupons[] = [
                    'coupon_id' => $coupon_id,
                    'coupon_code' => $coupon_code,
                    'user_id' => $user_id,
                    'user_name' => $user->display_name,
                    'user_email' => $user->user_email,
                    'rule_id' => $rule_id,
                    'rule_name' => $rule['name'],
                    'rule_discount' => $rule['discount_value'] . ($rule['discount_type'] === 'percentage' ? '%' : '€'),
                    'usage_count' => $usage_count,
                    'total_value' => $total_value,
                    'created' => get_post_meta($coupon_id, '_mad_ps_created', true),
                ];
            } catch (Exception $e) {
                continue; // Error al cargar cupón
            }
        }
    }
}

// Calcular estadísticas globales
$stats = [
    'total_generated' => count($all_coupons),
    'total_used' => count(array_filter($all_coupons, function($c) { return $c['usage_count'] > 0; })),
    'total_value' => array_sum(array_column($all_coupons, 'total_value')),
    'total_usage' => array_sum(array_column($all_coupons, 'usage_count')),
];
?>

<div class="wrap">
    <h1>
        🎫 Cupones Generados
    </h1>
    
    <!-- Tabs de navegación -->
    <nav class="nav-tab-wrapper" style="margin: 20px 0;">
        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop'], admin_url('admin.php')); ?>" 
           class="nav-tab">
            📋 Reglas de Descuento
        </a>
        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'coupons'], admin_url('admin.php')); ?>" 
           class="nav-tab nav-tab-active">
            🎫 Cupones Generados
        </a>
    </nav>
    
    <!-- Estadísticas -->
    <div class="card" style="max-width: 100%; margin-top: 20px;">
        <h2 style="padding: 15px; margin: 0; border-bottom: 1px solid #ddd;">📊 Estadísticas Globales</h2>
        <div style="padding: 20px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
                <div style="padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; color: white;">
                    <div style="font-size: 42px; font-weight: bold;">
                        <?php echo $stats['total_generated']; ?>
                    </div>
                    <div style="opacity: 0.9; font-size: 14px;">Cupones generados</div>
                </div>
                <div style="padding: 20px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 8px; color: white;">
                    <div style="font-size: 42px; font-weight: bold;">
                        <?php echo $stats['total_used']; ?>
                    </div>
                    <div style="opacity: 0.9; font-size: 14px;">Cupones usados</div>
                </div>
                <div style="padding: 20px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 8px; color: white;">
                    <div style="font-size: 42px; font-weight: bold;">
                        <?php echo $stats['total_usage']; ?>
                    </div>
                    <div style="opacity: 0.9; font-size: 14px;">Usos totales</div>
                </div>
                <div style="padding: 20px; background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border-radius: 8px; color: white;">
                    <div style="font-size: 42px; font-weight: bold;">
                        <?php echo wc_price($stats['total_value']); ?>
                    </div>
                    <div style="opacity: 0.9; font-size: 14px;">Valor de compras</div>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (empty($all_coupons)): ?>
        <!-- Estado vacío -->
        <div class="card" style="max-width: 100%; margin-top: 20px; text-align: center; padding: 60px 20px;">
            <div style="font-size: 64px; margin-bottom: 20px;">🎫</div>
            <h2>No hay cupones generados</h2>
            <p style="color: #666; margin-bottom: 30px;">
                Los cupones se generan automáticamente cuando los usuarios hacen login.<br>
                Asegúrate de tener reglas activas y usuarios con los roles configurados.
            </p>
            <a href="<?php echo add_query_arg(['page' => 'mad-private-shop'], admin_url('admin.php')); ?>" class="button button-primary button-large">
                ← Ver Reglas de Descuento
            </a>
        </div>
    <?php else: ?>
        
        <!-- Filtros -->
        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <div style="padding: 15px; border-bottom: 1px solid #ddd;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="mad-private-shop">
                    <input type="hidden" name="action" value="coupons">
                    
                    <label for="filter_rule" style="margin-right: 10px;">
                        <strong>Filtrar por regla:</strong>
                    </label>
                    <select name="filter_rule" id="filter_rule" style="min-width: 200px;">
                        <option value="">Todas las reglas</option>
                        <?php foreach ($rules as $rule): ?>
                            <option value="<?php echo esc_attr($rule['id']); ?>" <?php selected($filter_rule, $rule['id']); ?>>
                                <?php echo esc_html($rule['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="button" style="margin-left: 10px;">
                        🔍 Filtrar
                    </button>
                    
                    <?php if ($filter_rule): ?>
                        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'coupons'], admin_url('admin.php')); ?>" 
                           class="button" style="margin-left: 5px;">
                            ✕ Limpiar
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Tabla de cupones -->
        <div class="card" style="max-width: 100%; margin-top: 20px; overflow-x: auto;">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 180px;">Cupón</th>
                        <th>Usuario</th>
                        <th style="width: 200px;">Regla</th>
                        <th style="width: 100px;">Descuento</th>
                        <th style="width: 80px; text-align: center;">Usado</th>
                        <th style="width: 120px; text-align: right;">Valor Compras</th>
                        <th style="width: 150px;">Creado</th>
                        <th style="width: 200px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_coupons as $coupon_data): 
                        $regenerate_url = wp_nonce_url(
                            add_query_arg([
                                'action' => 'regenerate_user_coupon',
                                'user_id' => $coupon_data['user_id']
                            ], admin_url('admin-post.php')),
                            'regenerate_coupon',
                            'nonce'
                        );
                        
                        $delete_url = wp_nonce_url(
                            add_query_arg([
                                'action' => 'delete_user_coupon',
                                'user_id' => $coupon_data['user_id']
                            ], admin_url('admin-post.php')),
                            'delete_coupon',
                            'nonce'
                        );
                        
                        $wc_coupon_url = admin_url('post.php?post=' . $coupon_data['coupon_id'] . '&action=edit');
                    ?>
                        <tr>
                            <td>
                                <code style="background: #f0f0f0; padding: 4px 8px; border-radius: 3px; font-size: 13px;">
                                    <?php echo esc_html($coupon_data['coupon_code']); ?>
                                </code>
                            </td>
                            <td>
                                <strong><?php echo esc_html($coupon_data['user_name']); ?></strong>
                                <div style="color: #666; font-size: 12px;">
                                    <?php echo esc_html($coupon_data['user_email']); ?>
                                </div>
                                <div style="color: #999; font-size: 11px;">
                                    ID: <?php echo $coupon_data['user_id']; ?>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo esc_html($coupon_data['rule_name']); ?></strong>
                                <div style="color: #666; font-size: 12px;">
                                    ID: <?php echo esc_html($coupon_data['rule_id']); ?>
                                </div>
                            </td>
                            <td>
                                <strong style="color: #2196F3;">
                                    <?php echo esc_html($coupon_data['rule_discount']); ?>
                                </strong>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($coupon_data['usage_count'] > 0): ?>
                                    <span style="background: #4CAF50; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                        <?php echo $coupon_data['usage_count']; ?>×
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999;">0×</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <?php if ($coupon_data['total_value'] > 0): ?>
                                    <strong><?php echo wc_price($coupon_data['total_value']); ?></strong>
                                <?php else: ?>
                                    <span style="color: #999;">0€</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: #666;">
                                <?php 
                                if ($coupon_data['created']) {
                                    echo date('d/m/Y H:i', strtotime($coupon_data['created']));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($wc_coupon_url); ?>" 
                                   class="button button-small" 
                                   target="_blank"
                                   title="Ver en WooCommerce">
                                    👁️ Ver
                                </a>
                                <a href="<?php echo esc_url($regenerate_url); ?>" 
                                   class="button button-small"
                                   title="Regenerar cupón"
                                   onclick="return confirm('¿Regenerar este cupón?\n\nSe eliminará el actual y se creará uno nuevo.');">
                                    🔄 Regenerar
                                </a>
                                <a href="<?php echo esc_url($delete_url); ?>" 
                                   class="button button-small button-link-delete"
                                   title="Eliminar cupón"
                                   onclick="return confirm('¿Eliminar este cupón?\n\nEl usuario perderá el descuento.');">
                                    🗑️
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Información adicional -->
        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <div style="padding: 15px;">
                <h3 style="margin-top: 0;">ℹ️ Información sobre cupones</h3>
                <ul style="margin: 0; padding-left: 20px;">
                    <li><strong>Generación automática:</strong> Los cupones se crean cuando un usuario con el rol configurado hace login.</li>
                    <li><strong>Reutilizables:</strong> Cada usuario puede usar su cupón múltiples veces (ilimitado).</li>
                    <li><strong>Personales:</strong> Solo el usuario asignado puede usar el cupón (restringido por email).</li>
                    <li><strong>Sincronización:</strong> Al editar una regla, todos sus cupones se actualizan automáticamente.</li>
                    <li><strong>Eliminación:</strong> Al desactivar o eliminar una regla, se eliminan todos sus cupones.</li>
                </ul>
            </div>
        </div>
        
        <!-- Resumen por regla -->
        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <h2 style="padding: 15px; margin: 0; border-bottom: 1px solid #ddd;">📊 Resumen por Regla</h2>
            <div style="padding: 20px;">
                <table class="wp-list-table widefat" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Regla</th>
                            <th style="width: 120px; text-align: center;">Cupones</th>
                            <th style="width: 120px; text-align: center;">Usados</th>
                            <th style="width: 120px; text-align: center;">Usos Totales</th>
                            <th style="width: 150px; text-align: right;">Valor Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Agrupar por regla
                        $by_rule = [];
                        foreach ($all_coupons as $coupon) {
                            $rule_id = $coupon['rule_id'];
                            if (!isset($by_rule[$rule_id])) {
                                $by_rule[$rule_id] = [
                                    'name' => $coupon['rule_name'],
                                    'discount' => $coupon['rule_discount'],
                                    'count' => 0,
                                    'used' => 0,
                                    'usage' => 0,
                                    'value' => 0,
                                ];
                            }
                            $by_rule[$rule_id]['count']++;
                            if ($coupon['usage_count'] > 0) {
                                $by_rule[$rule_id]['used']++;
                            }
                            $by_rule[$rule_id]['usage'] += $coupon['usage_count'];
                            $by_rule[$rule_id]['value'] += $coupon['total_value'];
                        }
                        
                        foreach ($by_rule as $rule_id => $data):
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($data['name']); ?></strong>
                                    <div style="color: #666; font-size: 12px;">
                                        Descuento: <?php echo esc_html($data['discount']); ?>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <strong><?php echo $data['count']; ?></strong>
                                </td>
                                <td style="text-align: center;">
                                    <strong style="color: <?php echo $data['used'] > 0 ? '#4CAF50' : '#999'; ?>">
                                        <?php echo $data['used']; ?>
                                    </strong>
                                </td>
                                <td style="text-align: center;">
                                    <?php echo $data['usage']; ?>×
                                </td>
                                <td style="text-align: right;">
                                    <strong><?php echo wc_price($data['value']); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    <?php endif; ?>
</div>

<style>
.button-link-delete {
    color: #a00;
}
.button-link-delete:hover {
    color: #dc3232;
}
</style>