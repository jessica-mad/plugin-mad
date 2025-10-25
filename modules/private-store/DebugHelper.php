<?php
/**
 * Helper de Debugging para Private Shop
 * 
 * Añade herramientas de diagnóstico al admin
 * Para usar: Incluir en Module.php o crear como archivo separado
 */

namespace MADSuite\Modules\PrivateShop;

class DebugHelper {
    
    /**
     * Añade meta box de debugging en la página de configuración
     */
    public static function render_debug_panel() {
        global $wpdb;
        
        ?>
        <div class="card" style="max-width: 100%; margin-top: 20px; border-left: 4px solid #ff9800;">
            <h2>🛠️ Panel de Debugging</h2>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                
                <!-- Info del sistema -->
                <div>
                    <h3>📊 Estado del Sistema</h3>
                    <table class="widefat">
                        <tr>
                            <td><strong>WordPress:</strong></td>
                            <td><?php echo get_bloginfo('version'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>WooCommerce:</strong></td>
                            <td><?php echo defined('WC_VERSION') ? WC_VERSION : 'No instalado'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>PHP:</strong></td>
                            <td><?php echo PHP_VERSION; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Memoria PHP:</strong></td>
                            <td><?php echo ini_get('memory_limit'); ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Estadísticas de descuentos -->
                <div>
                    <h3>💰 Estadísticas de Descuentos</h3>
                    <?php
                    $discounts = get_option('mad_private_shop_discounts', []);
                    $total_products = count(wc_get_products(['limit' => -1]));
                    $products_with_discount = count($discounts);
                    
                    if (!empty($discounts)) {
                        $avg_discount = array_sum($discounts) / count($discounts);
                        $max_discount = max($discounts);
                        $min_discount = min(array_filter($discounts));
                    }
                    ?>
                    <table class="widefat">
                        <tr>
                            <td><strong>Total productos:</strong></td>
                            <td><?php echo $total_products; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Con descuento:</strong></td>
                            <td><?php echo $products_with_discount; ?></td>
                        </tr>
                        <tr>
                            <td><strong>% con descuento:</strong></td>
                            <td><?php echo $total_products > 0 ? round(($products_with_discount / $total_products) * 100, 2) : 0; ?>%</td>
                        </tr>
                        <?php if (!empty($discounts)): ?>
                        <tr>
                            <td><strong>Descuento promedio:</strong></td>
                            <td><?php echo round($avg_discount, 2); ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>Descuento máximo:</strong></td>
                            <td><?php echo $max_discount; ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>Descuento mínimo:</strong></td>
                            <td><?php echo $min_discount; ?>%</td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                
                <!-- Roles configurados -->
                <div>
                    <h3>👥 Roles Activos</h3>
                    <?php
                    $selected_roles = get_option('mad_private_shop_roles', []);
                    $all_roles = wp_roles()->roles;
                    ?>
                    <table class="widefat">
                        <?php foreach ($all_roles as $role_key => $role_data): ?>
                        <tr>
                            <td>
                                <?php if (in_array($role_key, $selected_roles)): ?>
                                    <span style="color: green;">✓</span>
                                <?php else: ?>
                                    <span style="color: #ccc;">✗</span>
                                <?php endif; ?>
                                <?php echo esc_html($role_data['name']); ?>
                            </td>
                            <td style="text-align: right;">
                                <?php 
                                $count = count(get_users(['role' => $role_key]));
                                echo $count . ' usuario' . ($count != 1 ? 's' : '');
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            
            <!-- Acciones de debugging -->
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h3>🔧 Acciones</h3>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="button" onclick="testUserCanSeeDiscounts()">
                        🧪 Test: ¿Usuario puede ver descuentos?
                    </button>
                    <button type="button" class="button" onclick="testProductDiscount()">
                        🔍 Test: Verificar descuento de producto
                    </button>
                    <button type="button" class="button" onclick="clearWooCommerceCache()">
                        🗑️ Limpiar cache de WooCommerce
                    </button>
                    <button type="button" class="button" onclick="viewRawDiscounts()">
                        📄 Ver JSON de descuentos
                    </button>
                </div>
                <div id="debug-results" style="margin-top: 15px; padding: 10px; background: #f5f5f5; border-radius: 4px; font-family: monospace; display: none;"></div>
            </div>
            
            <!-- Top 10 productos con mayor descuento -->
            <?php if (!empty($discounts)): ?>
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h3>🏆 Top 10 Productos con Mayor Descuento</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th style="text-align: right;">Descuento</th>
                            <th style="text-align: right;">Precio Original</th>
                            <th style="text-align: right;">Precio Final</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        arsort($discounts);
                        $top_discounts = array_slice($discounts, 0, 10, true);
                        foreach ($top_discounts as $product_id => $discount) {
                            $product = wc_get_product($product_id);
                            if ($product) {
                                $original_price = $product->get_regular_price();
                                $final_price = $original_price * (1 - ($discount / 100));
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo get_edit_post_link($product_id); ?>" target="_blank">
                                    <?php echo $product->get_name(); ?>
                                </a>
                            </td>
                            <td style="text-align: right;">
                                <strong style="color: #4CAF50;"><?php echo $discount; ?>%</strong>
                            </td>
                            <td style="text-align: right;">
                                <?php echo wc_price($original_price); ?>
                            </td>
                            <td style="text-align: right;">
                                <?php echo wc_price($final_price); ?>
                            </td>
                        </tr>
                        <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
        function showDebugResult(message, type = 'info') {
            const resultDiv = document.getElementById('debug-results');
            resultDiv.style.display = 'block';
            resultDiv.style.borderLeft = type === 'success' ? '4px solid #4CAF50' : 
                                        type === 'error' ? '4px solid #f44336' : 
                                        '4px solid #2196F3';
            resultDiv.innerHTML = message;
        }
        
        function testUserCanSeeDiscounts() {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mad_test_user_discount'
            })
            .then(r => r.json())
            .then(data => {
                let message = '<strong>Test de Usuario:</strong><br>';
                message += '✓ Usuario actual: ' + data.user + '<br>';
                message += '✓ Roles: ' + data.roles.join(', ') + '<br>';
                message += '✓ Puede ver descuentos: ' + (data.can_see ? '<span style="color: green;">SÍ</span>' : '<span style="color: red;">NO</span>');
                showDebugResult(message, data.can_see ? 'success' : 'error');
            });
        }
        
        function testProductDiscount() {
            const productId = prompt('Ingresa el ID del producto a probar:');
            if (!productId) return;
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mad_test_product_discount&product_id=' + productId
            })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    showDebugResult('<strong>Error:</strong> ' + data.error, 'error');
                    return;
                }
                let message = '<strong>Test de Producto #' + productId + ':</strong><br>';
                message += '✓ Nombre: ' + data.name + '<br>';
                message += '✓ Precio original: ' + data.original_price + '<br>';
                message += '✓ Descuento configurado: ' + data.discount + '%<br>';
                message += '✓ Precio final: ' + data.final_price;
                showDebugResult(message, 'success');
            });
        }
        
        function clearWooCommerceCache() {
            if (!confirm('¿Limpiar cache de WooCommerce?\n\nEsto forzará la recarga de todos los datos de productos.')) {
                return;
            }
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mad_clear_wc_cache'
            })
            .then(r => r.json())
            .then(data => {
                showDebugResult('<strong>✓ Cache limpiado</strong><br>Productos actualizados: ' + data.count, 'success');
            });
        }
        
        function viewRawDiscounts() {
            const discounts = <?php echo json_encode(get_option('mad_private_shop_discounts', [])); ?>;
            const formatted = JSON.stringify(discounts, null, 2);
            showDebugResult('<pre>' + formatted + '</pre>', 'info');
        }
        </script>
        <?php
    }
    
    /**
     * Registra los endpoints AJAX para debugging
     */
    public static function register_ajax_handlers() {
        add_action('wp_ajax_mad_test_user_discount', [__CLASS__, 'ajax_test_user']);
        add_action('wp_ajax_mad_test_product_discount', [__CLASS__, 'ajax_test_product']);
        add_action('wp_ajax_mad_clear_wc_cache', [__CLASS__, 'ajax_clear_cache']);
    }
    
    public static function ajax_test_user() {
        $user = wp_get_current_user();
        $allowed_roles = get_option('mad_private_shop_roles', []);
        
        $can_see = false;
        foreach ($allowed_roles as $role) {
            if (in_array($role, $user->roles)) {
                $can_see = true;
                break;
            }
        }
        
        wp_send_json([
            'user' => $user->display_name,
            'roles' => $user->roles,
            'can_see' => $can_see
        ]);
    }
    
    public static function ajax_test_product() {
        $product_id = intval($_POST['product_id']);
        $product = wc_get_product($product_id);
        
        if (!$product) {
            wp_send_json(['error' => 'Producto no encontrado']);
        }
        
        $discounts = get_option('mad_private_shop_discounts', []);
        $discount = isset($discounts[$product_id]) ? $discounts[$product_id] : 0;
        $original_price = $product->get_regular_price();
        $final_price = $original_price * (1 - ($discount / 100));
        
        wp_send_json([
            'name' => $product->get_name(),
            'original_price' => wc_price($original_price),
            'discount' => $discount,
            'final_price' => wc_price($final_price)
        ]);
    }
    
    public static function ajax_clear_cache() {
        $products = wc_get_products(['limit' => -1]);
        $count = 0;
        
        foreach ($products as $product) {
            wc_delete_product_transients($product->get_id());
            $count++;
        }
        
        wp_send_json(['count' => $count]);
    }
}

// Registrar handlers AJAX
DebugHelper::register_ajax_handlers();