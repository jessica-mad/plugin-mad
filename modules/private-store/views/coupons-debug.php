<?php
/**
 * Script de diagnóstico para identificar el error en coupons-list
 */

defined('ABSPATH') || exit;

echo '<div class="wrap">';
echo '<h1>🔍 Diagnóstico del Gestor de Cupones</h1>';

// Test 1: Verificar WooCommerce
echo '<h2>1. WooCommerce</h2>';
if (function_exists('WC')) {
    echo '✅ WooCommerce está activo<br>';
    if (defined('WC_VERSION')) {
        echo '📦 Versión: ' . WC_VERSION . '<br>';
    }
} else {
    echo '❌ WooCommerce NO está activo<br>';
}

// Test 2: Verificar WordPress version
echo '<h2>2. WordPress</h2>';
global $wp_version;
echo '📦 Versión: ' . $wp_version . '<br>';
if (version_compare($wp_version, '6.2', '>=')) {
    echo '⚠️ <strong>WordPress 6.2+: get_page_by_title() está DEPRECADA</strong><br>';
} else {
    echo '✅ WordPress < 6.2: get_page_by_title() disponible<br>';
}

// Test 3: Verificar datos
echo '<h2>3. Datos</h2>';
$rules = get_option('mad_private_shop_rules', []);
$rule_coupons = get_option('mad_private_shop_rule_coupons', []);

echo 'Reglas encontradas: ' . count($rules) . '<br>';
echo 'Mappings de cupones: ' . count($rule_coupons) . '<br>';

// Test 4: Intentar obtener un cupón (si existe)
echo '<h2>4. Test de Cupón</h2>';
if (!empty($rule_coupons)) {
    foreach ($rule_coupons as $rule_id => $data) {
        if (!empty($data['user_coupons'])) {
            $test_coupon_code = reset($data['user_coupons']);
            echo 'Probando cupón: <strong>' . esc_html($test_coupon_code) . '</strong><br>';

            // Método ANTIGUO (deprecado)
            echo '<strong>Método deprecado (get_page_by_title):</strong> ';
            try {
                $coupon_post = get_page_by_title($test_coupon_code, OBJECT, 'shop_coupon');
                if ($coupon_post) {
                    echo '✅ Funciona (ID: ' . $coupon_post->ID . ')<br>';
                } else {
                    echo '❌ No encontrado<br>';
                }
            } catch (Exception $e) {
                echo '❌ ERROR: ' . esc_html($e->getMessage()) . '<br>';
            }

            // Método NUEVO (recomendado)
            echo '<strong>Método recomendado (wc_get_coupon_id_by_code):</strong> ';
            try {
                $coupon_id = wc_get_coupon_id_by_code($test_coupon_code);
                if ($coupon_id) {
                    echo '✅ Funciona (ID: ' . $coupon_id . ')<br>';
                } else {
                    echo '❌ No encontrado<br>';
                }
            } catch (Exception $e) {
                echo '❌ ERROR: ' . esc_html($e->getMessage()) . '<br>';
            }

            // Test crear objeto WC_Coupon
            echo '<strong>Test WC_Coupon:</strong> ';
            try {
                $coupon_id = wc_get_coupon_id_by_code($test_coupon_code);
                if ($coupon_id) {
                    $coupon = new WC_Coupon($coupon_id);
                    if ($coupon->get_id()) {
                        echo '✅ Cupón cargado correctamente<br>';
                        echo '  - Tipo: ' . $coupon->get_discount_type() . '<br>';
                        echo '  - Cantidad: ' . $coupon->get_amount() . '<br>';
                    } else {
                        echo '❌ Cupón no válido<br>';
                    }
                }
            } catch (Exception $e) {
                echo '❌ ERROR: ' . esc_html($e->getMessage()) . '<br>';
            }

            break; // Solo probar el primer cupón
        }
    }
} else {
    echo 'No hay cupones para probar<br>';
}

// Test 5: Verificar funciones WC
echo '<h2>5. Funciones WooCommerce</h2>';
$functions_to_check = [
    'wc_get_coupon_id_by_code',
    'wc_get_orders',
    'wc_price',
];

foreach ($functions_to_check as $func) {
    if (function_exists($func)) {
        echo '✅ ' . $func . '()<br>';
    } else {
        echo '❌ ' . $func . '() NO DISPONIBLE<br>';
    }
}

echo '</div>';
