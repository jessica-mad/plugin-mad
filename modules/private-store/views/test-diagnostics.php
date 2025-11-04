<?php
/**
 * Vista: Test & Diagnóstico del Private Shop
 */

defined('ABSPATH') || exit;

// Obtener datos del sistema
$rules = get_option('mad_private_shop_rules', []);
$rule_coupons = get_option('mad_private_shop_rule_coupons', []);
$current_user = wp_get_current_user();

?>

<div class="wrap">
    <h1>🧪 Test & Diagnóstico - Private Shop</h1>

    <!-- Tabs de navegación -->
    <nav class="nav-tab-wrapper" style="margin: 20px 0;">
        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop'], admin_url('admin.php')); ?>"
           class="nav-tab">
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
           class="nav-tab nav-tab-active">
            🧪 Test & Diagnóstico
        </a>
    </nav>

    <!-- Estado del Sistema -->
    <div class="card" style="max-width: 100%; margin-bottom: 20px;">
        <h2>📊 Estado del Sistema</h2>
        <table class="widefat">
            <tr>
                <td style="width: 40%;"><strong>Total de Reglas:</strong></td>
                <td><?php echo count($rules); ?></td>
            </tr>
            <tr>
                <td><strong>Reglas Activas:</strong></td>
                <td>
                    <?php
                    $active_rules = array_filter($rules, function($r) { return isset($r['enabled']) && $r['enabled']; });
                    echo count($active_rules);
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong>Total de Cupones Generados:</strong></td>
                <td>
                    <?php
                    $total_coupons = 0;
                    foreach ($rule_coupons as $data) {
                        if (isset($data['user_coupons'])) {
                            $total_coupons += count($data['user_coupons']);
                        }
                    }
                    echo $total_coupons;
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong>Usuario Actual:</strong></td>
                <td><?php echo $current_user->user_login . ' (ID: ' . $current_user->ID . ')'; ?></td>
            </tr>
            <tr>
                <td><strong>Roles del Usuario:</strong></td>
                <td><?php echo implode(', ', $current_user->roles); ?></td>
            </tr>
        </table>
    </div>

    <!-- Test de Usuario -->
    <div class="card" style="max-width: 100%; margin-bottom: 20px;">
        <h2>👤 Test: ¿Qué regla aplica para un usuario?</h2>
        <p>Verifica si un usuario tiene reglas aplicables y cuál sería su cupón.</p>

        <form id="test-user-form" onsubmit="testUser(event)">
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="number" id="test-user-id" placeholder="ID del usuario" style="width: 200px;" required>
                <button type="submit" class="button button-primary">🔍 Probar Usuario</button>
            </div>
        </form>

        <div id="test-user-result" style="display: none; margin-top: 20px;"></div>
    </div>

    <!-- Test de Producto -->
    <div class="card" style="max-width: 100%; margin-bottom: 20px;">
        <h2>📦 Test: ¿Un producto tiene descuento?</h2>
        <p>Verifica si un producto específico tiene descuento aplicable para el usuario actual.</p>

        <form id="test-product-form" onsubmit="testProduct(event)">
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="number" id="test-product-id" placeholder="ID del producto" style="width: 200px;" required>
                <button type="submit" class="button button-primary">🔍 Probar Producto</button>
            </div>
        </form>

        <div id="test-product-result" style="display: none; margin-top: 20px;"></div>
    </div>

    <!-- Simular Login -->
    <div class="card" style="max-width: 100%; margin-bottom: 20px;">
        <h2>🔐 Simular Login de Usuario</h2>
        <p>Simula el evento de login para generar el cupón de un usuario (si no tiene uno).</p>

        <form id="simulate-login-form" onsubmit="simulateLogin(event)">
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="number" id="simulate-user-id" placeholder="ID del usuario" style="width: 200px;" required>
                <button type="submit" class="button button-primary">🔐 Simular Login</button>
            </div>
        </form>

        <div id="simulate-login-result" style="display: none; margin-top: 20px;"></div>
    </div>

    <!-- Diagnóstico Completo -->
    <div class="card" style="max-width: 100%; margin-bottom: 20px;">
        <h2>🔬 Diagnóstico Completo del Sistema</h2>
        <p>Ejecuta un diagnóstico completo del sistema para detectar problemas comunes.</p>

        <button type="button" class="button button-primary" onclick="runFullDiagnostic()">
            🔬 Ejecutar Diagnóstico Completo
        </button>

        <div id="diagnostic-result" style="display: none; margin-top: 20px;"></div>
    </div>

    <!-- Lista de Reglas Activas -->
    <div class="card" style="max-width: 100%;">
        <h2>📋 Detalle de Reglas Activas</h2>
        <?php if (empty($active_rules)): ?>
            <p style="color: #999;">No hay reglas activas en este momento.</p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Descuento</th>
                        <th>Aplica a</th>
                        <th>Roles Requeridos</th>
                        <th>Fechas</th>
                        <th>Cupones Generados</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($active_rules as $rule): ?>
                        <tr>
                            <td><strong><?php echo esc_html($rule['name']); ?></strong></td>
                            <td>
                                <?php
                                if ($rule['discount_type'] === 'percentage') {
                                    echo $rule['discount_value'] . '%';
                                } else {
                                    echo wc_price($rule['discount_value']);
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $apply_labels = [
                                    'products' => 'Productos',
                                    'categories' => 'Categorías',
                                    'tags' => 'Etiquetas'
                                ];
                                echo $apply_labels[$rule['apply_to']] ?? $rule['apply_to'];
                                echo ' (' . count($rule['target_ids']) . ')';
                                ?>
                            </td>
                            <td><?php echo implode(', ', $rule['roles']); ?></td>
                            <td style="font-size: 12px;">
                                <?php if (!empty($rule['date_from']) || !empty($rule['date_to'])): ?>
                                    <?php if (!empty($rule['date_from'])): ?>
                                        Desde: <?php echo date('d/m/Y', strtotime($rule['date_from'])); ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($rule['date_to'])): ?>
                                        Hasta: <?php echo date('d/m/Y', strtotime($rule['date_to'])); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Sin límite
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $count = 0;
                                if (isset($rule_coupons[$rule['id']]['user_coupons'])) {
                                    $count = count($rule_coupons[$rule['id']]['user_coupons']);
                                }
                                echo $count;
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
function showResult(elementId, html, type = 'info') {
    const el = document.getElementById(elementId);
    el.style.display = 'block';
    el.style.padding = '15px';
    el.style.borderRadius = '4px';
    el.style.borderLeft = '4px solid';

    if (type === 'success') {
        el.style.background = '#d4edda';
        el.style.borderColor = '#28a745';
    } else if (type === 'error') {
        el.style.background = '#f8d7da';
        el.style.borderColor = '#dc3545';
    } else if (type === 'warning') {
        el.style.background = '#fff3cd';
        el.style.borderColor = '#ffc107';
    } else {
        el.style.background = '#d1ecf1';
        el.style.borderColor = '#0dcaf0';
    }

    el.innerHTML = html;
}

function testUser(event) {
    event.preventDefault();
    const userId = document.getElementById('test-user-id').value;

    showResult('test-user-result', '⏳ Probando usuario...', 'info');

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mad_test_user&user_id=' + userId + '&nonce=<?php echo wp_create_nonce('mad_test'); ?>'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let html = '<h3>✓ Resultado del Test</h3>';
            html += '<table class="widefat"><tbody>';
            html += '<tr><td><strong>Usuario:</strong></td><td>' + data.data.user_login + ' (ID: ' + data.data.user_id + ')</td></tr>';
            html += '<tr><td><strong>Roles:</strong></td><td>' + data.data.roles.join(', ') + '</td></tr>';

            if (data.data.has_rule) {
                html += '<tr><td><strong>¿Tiene regla?</strong></td><td style="color: green;">✓ SÍ</td></tr>';
                html += '<tr><td><strong>Regla aplicable:</strong></td><td>' + data.data.rule_name + '</td></tr>';
                html += '<tr><td><strong>Descuento:</strong></td><td>' + data.data.discount + '</td></tr>';
            } else {
                html += '<tr><td><strong>¿Tiene regla?</strong></td><td style="color: red;">✗ NO</td></tr>';
                html += '<tr><td><strong>Motivo:</strong></td><td>' + data.data.reason + '</td></tr>';
            }

            if (data.data.has_coupon) {
                html += '<tr><td><strong>¿Tiene cupón?</strong></td><td style="color: green;">✓ SÍ</td></tr>';
                html += '<tr><td><strong>Código del cupón:</strong></td><td><code>' + data.data.coupon_code + '</code></td></tr>';
            } else {
                html += '<tr><td><strong>¿Tiene cupón?</strong></td><td style="color: orange;">✗ NO</td></tr>';
            }

            html += '</tbody></table>';
            showResult('test-user-result', html, 'success');
        } else {
            showResult('test-user-result', '<strong>❌ Error:</strong> ' + data.data, 'error');
        }
    });
}

function testProduct(event) {
    event.preventDefault();
    const productId = document.getElementById('test-product-id').value;

    showResult('test-product-result', '⏳ Probando producto...', 'info');

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mad_test_product&product_id=' + productId + '&nonce=<?php echo wp_create_nonce('mad_test'); ?>'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let html = '<h3>✓ Resultado del Test</h3>';
            html += '<table class="widefat"><tbody>';
            html += '<tr><td><strong>Producto:</strong></td><td>' + data.data.product_name + ' (ID: ' + data.data.product_id + ')</td></tr>';
            html += '<tr><td><strong>Usuario actual:</strong></td><td>' + data.data.current_user + '</td></tr>';

            if (data.data.has_discount) {
                html += '<tr><td><strong>¿Tiene descuento?</strong></td><td style="color: green;">✓ SÍ</td></tr>';
                html += '<tr><td><strong>Regla aplicable:</strong></td><td>' + data.data.rule_name + '</td></tr>';
                html += '<tr><td><strong>Descuento:</strong></td><td>' + data.data.discount + '</td></tr>';
                html += '<tr><td><strong>Precio original:</strong></td><td>' + data.data.original_price + '</td></tr>';
                html += '<tr><td><strong>Precio con descuento:</strong></td><td><strong style="color: green;">' + data.data.discounted_price + '</strong></td></tr>';
            } else {
                html += '<tr><td><strong>¿Tiene descuento?</strong></td><td style="color: red;">✗ NO</td></tr>';
                html += '<tr><td><strong>Motivo:</strong></td><td>' + data.data.reason + '</td></tr>';
            }

            html += '</tbody></table>';
            showResult('test-product-result', html, data.data.has_discount ? 'success' : 'warning');
        } else {
            showResult('test-product-result', '<strong>❌ Error:</strong> ' + data.data, 'error');
        }
    });
}

function simulateLogin(event) {
    event.preventDefault();
    const userId = document.getElementById('simulate-user-id').value;

    showResult('simulate-login-result', '⏳ Simulando login...', 'info');

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mad_simulate_login&user_id=' + userId + '&nonce=<?php echo wp_create_nonce('mad_test'); ?>'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let html = '<h3>✓ Login Simulado</h3>';
            html += '<p>' + data.data.message + '</p>';
            if (data.data.coupon_created) {
                html += '<p><strong>Código del cupón:</strong> <code>' + data.data.coupon_code + '</code></p>';
            }
            showResult('simulate-login-result', html, 'success');
        } else {
            showResult('simulate-login-result', '<strong>❌ Error:</strong> ' + data.data, 'error');
        }
    });
}

function runFullDiagnostic() {
    showResult('diagnostic-result', '⏳ Ejecutando diagnóstico completo...', 'info');

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mad_full_diagnostic&nonce=<?php echo wp_create_nonce('mad_test'); ?>'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            let html = '<h3>🔬 Diagnóstico Completo</h3>';
            html += '<div style="margin-top: 15px;">';

            data.data.checks.forEach(check => {
                let icon = check.status === 'ok' ? '✓' : check.status === 'warning' ? '⚠' : '✗';
                let color = check.status === 'ok' ? 'green' : check.status === 'warning' ? 'orange' : 'red';
                html += '<div style="padding: 10px; margin-bottom: 10px; border-left: 4px solid ' + color + '; background: #f5f5f5;">';
                html += '<strong style="color: ' + color + ';">' + icon + ' ' + check.name + '</strong><br>';
                html += check.message;
                html += '</div>';
            });

            html += '</div>';
            showResult('diagnostic-result', html, 'success');
        } else {
            showResult('diagnostic-result', '<strong>❌ Error:</strong> ' + data.data, 'error');
        }
    });
}
</script>
