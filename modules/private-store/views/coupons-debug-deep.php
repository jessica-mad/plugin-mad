<?php
/**
 * Diagnóstico profundo - Simula exactamente coupons-list.php con manejo de errores
 */

defined('ABSPATH') || exit;

// Aumentar tiempo de ejecución
set_time_limit(300);

echo '<div class="wrap">';
echo '<h1>🔍 Diagnóstico Profundo - Simulando coupons-list.php</h1>';

if (!function_exists('WC')) {
    echo '<div class="notice notice-error"><p>WooCommerce no está activo</p></div>';
    echo '</div>';
    return;
}

echo '<div style="background: #fff; padding: 20px; border: 1px solid #ccc; margin: 20px 0;">';

try {
    echo '<h2>📋 Paso 1: Obtener Datos</h2>';
    $rules = get_option('mad_private_shop_rules', []);
    $rule_coupons = get_option('mad_private_shop_rule_coupons', []);

    echo 'Reglas: ' . count($rules) . '<br>';
    echo 'Rule Coupons: ' . count($rule_coupons) . '<br>';
    echo '✅ Datos obtenidos<br><br>';

    echo '<h2>🎫 Paso 2: Procesar Cupones (Con Detección de Errores)</h2>';

    $all_coupons = [];
    $errors = [];
    $processed = 0;
    $skipped = 0;

    if (!empty($rule_coupons) && is_array($rule_coupons)) {
        foreach ($rule_coupons as $rule_id => $data) {
            echo '<div style="background: #f0f0f0; padding: 10px; margin: 10px 0;">';
            echo '<strong>Procesando regla: ' . esc_html($rule_id) . '</strong><br>';

            // Verificar que la regla existe
            if (!isset($rules[$rule_id])) {
                echo '⚠️ Regla eliminada - SKIP<br>';
                $skipped++;
                echo '</div>';
                continue;
            }

            $rule = $rules[$rule_id];
            echo 'Regla: ' . esc_html($rule['name']) . '<br>';

            // Verificar user_coupons
            if (!isset($data['user_coupons']) || !is_array($data['user_coupons'])) {
                echo '⚠️ No hay user_coupons - SKIP<br>';
                echo '</div>';
                continue;
            }

            echo 'Cupones de usuarios: ' . count($data['user_coupons']) . '<br>';

            // Procesar cada cupón de usuario
            foreach ($data['user_coupons'] as $user_id => $coupon_code) {
                $processed++;
                echo '<div style="margin-left: 20px; padding: 5px; background: #fff;">';
                echo '🎫 <strong>' . esc_html($coupon_code) . '</strong> (User ID: ' . $user_id . ')<br>';

                try {
                    // Verificar usuario
                    $user = get_userdata($user_id);
                    if (!$user) {
                        echo '❌ ERROR: Usuario no existe - SKIP<br>';
                        $errors[] = "Usuario $user_id no existe para cupón $coupon_code";
                        $skipped++;
                        echo '</div>';
                        continue;
                    }
                    echo '✓ Usuario: ' . esc_html($user->display_name) . '<br>';

                    // Buscar cupón
                    $coupon_id = wc_get_coupon_id_by_code($coupon_code);
                    if (!$coupon_id) {
                        echo '⚠️ Cupón no encontrado en WC - SKIP<br>';
                        $skipped++;
                        echo '</div>';
                        continue;
                    }
                    echo '✓ Cupón ID: ' . $coupon_id . '<br>';

                    // Cargar objeto WC_Coupon
                    $coupon = new WC_Coupon($coupon_id);
                    if (!$coupon->get_id()) {
                        echo '❌ ERROR: No se pudo cargar WC_Coupon<br>';
                        $errors[] = "No se pudo cargar WC_Coupon para ID $coupon_id";
                        $skipped++;
                        echo '</div>';
                        continue;
                    }

                    $usage_count = $coupon->get_usage_count();
                    echo '✓ Usage count: ' . $usage_count . '<br>';

                    // Obtener órdenes (ESTO PUEDE SER LENTO)
                    echo '🔄 Buscando órdenes... ';
                    $total_value = 0;
                    $orders_count = 0;

                    if (function_exists('wc_get_orders')) {
                        try {
                            $orders = wc_get_orders([
                                'limit' => -1,
                                'coupon' => $coupon_code,
                                'status' => ['completed', 'processing']
                            ]);

                            $orders_count = count($orders);

                            foreach ($orders as $order) {
                                $total_value += $order->get_total();
                            }

                            echo 'OK (' . $orders_count . ' órdenes, total: ' . wc_price($total_value) . ')<br>';
                        } catch (Exception $e) {
                            echo '❌ ERROR en wc_get_orders: ' . esc_html($e->getMessage()) . '<br>';
                            $errors[] = "Error en wc_get_orders para $coupon_code: " . $e->getMessage();
                        }
                    }

                    // Agregar a array
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

                    echo '✅ Cupón procesado correctamente<br>';

                } catch (Exception $e) {
                    echo '❌ EXCEPCIÓN: ' . esc_html($e->getMessage()) . '<br>';
                    $errors[] = "Excepción al procesar $coupon_code: " . $e->getMessage();
                    $skipped++;
                }

                echo '</div>';
            }

            echo '</div>';
        }
    }

    echo '<h2>📊 Paso 3: Resultados</h2>';
    echo '<div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745;">';
    echo '<strong>Total procesados:</strong> ' . $processed . '<br>';
    echo '<strong>Cupones válidos:</strong> ' . count($all_coupons) . '<br>';
    echo '<strong>Cupones saltados:</strong> ' . $skipped . '<br>';
    echo '<strong>Errores encontrados:</strong> ' . count($errors) . '<br>';
    echo '</div>';

    if (!empty($errors)) {
        echo '<h2>⚠️ Errores Detectados</h2>';
        echo '<div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545;">';
        foreach ($errors as $error) {
            echo '• ' . esc_html($error) . '<br>';
        }
        echo '</div>';
    }

    echo '<h2>✅ Paso 4: Calcular Estadísticas</h2>';
    $stats = [
        'total_generated' => count($all_coupons),
        'total_used' => 0,
        'total_value' => 0,
        'total_usage' => 0,
    ];

    if (!empty($all_coupons)) {
        $stats['total_used'] = count(array_filter($all_coupons, function($c) {
            return isset($c['usage_count']) && $c['usage_count'] > 0;
        }));

        $total_values = array_column($all_coupons, 'total_value');
        $stats['total_value'] = !empty($total_values) ? array_sum($total_values) : 0;

        $usage_counts = array_column($all_coupons, 'usage_count');
        $stats['total_usage'] = !empty($usage_counts) ? array_sum($usage_counts) : 0;
    }

    echo '<div style="background: #d1ecf1; padding: 15px; border-left: 4px solid #0c5460;">';
    echo '<strong>Cupones generados:</strong> ' . $stats['total_generated'] . '<br>';
    echo '<strong>Cupones usados:</strong> ' . $stats['total_used'] . '<br>';
    echo '<strong>Usos totales:</strong> ' . $stats['total_usage'] . '<br>';
    echo '<strong>Valor total:</strong> ' . wc_price($stats['total_value']) . '<br>';
    echo '</div>';

    echo '<h2>🎉 Diagnóstico Completado</h2>';
    echo '<p><strong>El procesamiento funcionó correctamente.</strong></p>';

    if (empty($errors)) {
        echo '<div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745;">';
        echo '✅ <strong>No se encontraron errores.</strong><br>';
        echo 'El problema puede ser:<br>';
        echo '1. Límite de memoria PHP<br>';
        echo '2. Timeout de PHP<br>';
        echo '3. Algún warning/notice que causa error 500 en producción<br>';
        echo '4. Caché de navegador/servidor<br>';
        echo '</div>';
    }

} catch (Exception $e) {
    echo '<div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545;">';
    echo '<h2>❌ ERROR FATAL</h2>';
    echo '<p><strong>Mensaje:</strong> ' . esc_html($e->getMessage()) . '</p>';
    echo '<p><strong>Archivo:</strong> ' . esc_html($e->getFile()) . '</p>';
    echo '<p><strong>Línea:</strong> ' . $e->getLine() . '</p>';
    echo '<pre>' . esc_html($e->getTraceAsString()) . '</pre>';
    echo '</div>';
}

echo '</div>'; // .wrap
echo '<div>';

// Información de memoria
echo '<h2>💾 Información de Memoria</h2>';
echo '<div style="background: #fff3cd; padding: 15px; border-left: 4px solid #856404;">';
echo '<strong>Memoria usada:</strong> ' . size_format(memory_get_usage(true)) . '<br>';
echo '<strong>Memoria pico:</strong> ' . size_format(memory_get_peak_usage(true)) . '<br>';
echo '<strong>Límite PHP:</strong> ' . ini_get('memory_limit') . '<br>';
echo '<strong>Tiempo máximo ejecución:</strong> ' . ini_get('max_execution_time') . 's<br>';
echo '</div>';

echo '</div>';
