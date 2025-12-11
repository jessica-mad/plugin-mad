<?php
/**
 * Multi-Catalog Sync - Debug Helper
 *
 * Coloca este archivo en: wp-content/plugins/plugin-mad/debug-mcs.php
 * Accede via: tu-sitio.com/wp-content/plugins/plugin-mad/debug-mcs.php
 */

// Security check
$secret_key = 'mcs-debug-2024'; // Cambia esto por algo único
if (!isset($_GET['key']) || $_GET['key'] !== $secret_key) {
    die('Access denied. Add ?key=mcs-debug-2024 to URL');
}

require_once('../../../wp-load.php');

if (!current_user_can('manage_options')) {
    die('You need admin permissions');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Multi-Catalog Sync - Debug</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; background: #f0f0f1; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { color: #1d2327; margin-top: 0; }
        h2 { color: #2271b1; border-bottom: 2px solid #2271b1; padding-bottom: 10px; margin-top: 30px; }
        .status { padding: 15px; border-radius: 4px; margin: 10px 0; }
        .status.success { background: #d7f0db; border-left: 4px solid #1e8e3e; }
        .status.error { background: #fce8e6; border-left: 4px solid #d63638; }
        .status.warning { background: #fcf3cf; border-left: 4px solid #dba617; }
        .status.info { background: #e5f5fa; border-left: 4px solid #2271b1; }
        pre { background: #f6f7f7; padding: 15px; border-radius: 4px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f0f0f1; font-weight: 600; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 500; }
        .badge.yes { background: #d7f0db; color: #1e8e3e; }
        .badge.no { background: #fce8e6; color: #d63638; }
        .code { background: #f6f7f7; padding: 2px 6px; border-radius: 3px; font-family: monospace; font-size: 13px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Multi-Catalog Sync - Debug Tool</h1>
    <p>Esta herramienta te ayudará a diagnosticar problemas de configuración y sincronización.</p>

    <?php
    // Get settings
    $option_key = 'madsuite_multi-catalog-sync_settings';
    $settings = get_option($option_key, []);

    echo '<h2>1. Configuración General</h2>';

    // Check if module is enabled
    $enabled_modules = get_option('madsuite_enabled_modules', []);
    $is_enabled = in_array('multi-catalog-sync', $enabled_modules);

    if ($is_enabled) {
        echo '<div class="status success">✅ Módulo habilitado</div>';
    } else {
        echo '<div class="status error">❌ Módulo NO habilitado. Ve a MAD Plugins y habilítalo.</div>';
    }

    echo '<table>';
    echo '<tr><th>Setting</th><th>Value</th><th>Status</th></tr>';

    // Default brand
    $brand = isset($settings['default_brand']) ? $settings['default_brand'] : '';
    echo '<tr>';
    echo '<td>Marca predeterminada</td>';
    echo '<td>' . ($brand ? esc_html($brand) : '<em>No configurada</em>') . '</td>';
    echo '<td>' . ($brand ? '<span class="badge yes">OK</span>' : '<span class="badge no">Falta</span>') . '</td>';
    echo '</tr>';

    echo '</table>';

    // Google Merchant Center
    echo '<h2>2. Google Merchant Center</h2>';

    $google_enabled = !empty($settings['google_enabled']);
    $merchant_id = isset($settings['google_merchant_id']) ? $settings['google_merchant_id'] : '';
    $service_json = isset($settings['google_service_account_json']) ? $settings['google_service_account_json'] : '';

    if ($google_enabled) {
        echo '<div class="status success">✅ Google Merchant Center habilitado</div>';
    } else {
        echo '<div class="status warning">⚠️ Google Merchant Center NO habilitado</div>';
    }

    echo '<table>';
    echo '<tr><th>Config</th><th>Status</th><th>Details</th></tr>';

    // Merchant ID
    echo '<tr>';
    echo '<td>Merchant ID</td>';
    if ($merchant_id) {
        echo '<td><span class="badge yes">OK</span></td>';
        echo '<td>' . esc_html($merchant_id) . '</td>';
    } else {
        echo '<td><span class="badge no">Falta</span></td>';
        echo '<td>No configurado</td>';
    }
    echo '</tr>';

    // Service Account JSON
    echo '<tr>';
    echo '<td>Service Account JSON</td>';
    if ($service_json) {
        $json_data = json_decode($service_json, true);
        if ($json_data) {
            echo '<td><span class="badge yes">OK</span></td>';
            echo '<td>';
            echo 'Email: <span class="code">' . esc_html($json_data['client_email'] ?? 'N/A') . '</span><br>';
            echo 'Project: <span class="code">' . esc_html($json_data['project_id'] ?? 'N/A') . '</span>';
            echo '</td>';
        } else {
            echo '<td><span class="badge no">Error</span></td>';
            echo '<td>JSON inválido</td>';
        }
    } else {
        echo '<td><span class="badge no">Falta</span></td>';
        echo '<td>No configurado</td>';
    }
    echo '</tr>';

    echo '</table>';

    // Test connection
    if ($google_enabled && $merchant_id && $service_json) {
        echo '<h3>🔌 Test de Conexión</h3>';
        echo '<div class="status info">Intentando conectar con Google Merchant Center...</div>';

        try {
            require_once(dirname(__FILE__) . '/modules/multi-catalog-sync/includes/Destinations/GoogleMerchantCenter.php');

            $google = new \MAD_Suite\MultiCatalogSync\Destinations\GoogleMerchantCenter($settings);

            if ($google->is_connected()) {
                echo '<div class="status success">✅ Conexión exitosa con Google Merchant Center</div>';
            } else {
                echo '<div class="status error">❌ No se pudo conectar. Verifica las credenciales.</div>';
            }
        } catch (Exception $e) {
            echo '<div class="status error">❌ Error: ' . esc_html($e->getMessage()) . '</div>';
        }
    }

    // Products
    echo '<h2>3. Productos</h2>';

    $products = wc_get_products([
        'status' => 'publish',
        'limit' => 5,
    ]);

    echo '<p>Mostrando primeros 5 productos:</p>';
    echo '<table>';
    echo '<tr><th>Producto</th><th>Categorías</th><th>Categoría Google</th><th>Marca</th><th>Sync Habilitado</th></tr>';

    foreach ($products as $product) {
        echo '<tr>';
        echo '<td>' . esc_html($product->get_name()) . ' <small>(ID: ' . $product->get_id() . ')</small></td>';

        // Categories
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        $cat_names = array_map(function($cat) { return $cat->name; }, $categories);
        echo '<td>' . implode(', ', $cat_names) . '</td>';

        // Google category
        $has_google_cat = false;
        foreach ($categories as $cat) {
            $google_cat = get_term_meta($cat->term_id, '_mcs_google_category', true);
            if ($google_cat) {
                echo '<td><span class="badge yes">✓</span> ' . esc_html($google_cat) . '</td>';
                $has_google_cat = true;
                break;
            }
        }
        if (!$has_google_cat) {
            echo '<td><span class="badge no">✗</span> Sin categoría Google</td>';
        }

        // Brand
        $custom_brand = get_post_meta($product->get_id(), '_mcs_custom_brand', true);
        $brand_to_use = $custom_brand ? $custom_brand : ($settings['default_brand'] ?? 'N/A');
        echo '<td>' . esc_html($brand_to_use) . '</td>';

        // Sync enabled
        $sync_enabled = get_post_meta($product->get_id(), '_mcs_sync_enabled', true);
        $is_sync_enabled = ($sync_enabled === '' || $sync_enabled === '1');
        echo '<td>' . ($is_sync_enabled ? '<span class="badge yes">Sí</span>' : '<span class="badge no">No</span>') . '</td>';

        echo '</tr>';
    }
    echo '</table>';

    // Categories
    echo '<h2>4. Categorías con Google Category</h2>';

    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
    ]);

    $categories_with_google = 0;
    echo '<table>';
    echo '<tr><th>Categoría WC</th><th>Google Category</th><th>Google ID</th></tr>';

    foreach ($categories as $cat) {
        $google_cat = get_term_meta($cat->term_id, '_mcs_google_category', true);
        $google_cat_id = get_term_meta($cat->term_id, '_mcs_google_category_id', true);

        if ($google_cat || $google_cat_id) {
            $categories_with_google++;
            echo '<tr>';
            echo '<td>' . esc_html($cat->name) . '</td>';
            echo '<td>' . ($google_cat ? esc_html($google_cat) : '<em>N/A</em>') . '</td>';
            echo '<td>' . ($google_cat_id ? esc_html($google_cat_id) : '<em>N/A</em>') . '</td>';
            echo '</tr>';
        }
    }
    echo '</table>';

    if ($categories_with_google === 0) {
        echo '<div class="status error">❌ Ninguna categoría tiene asignada una categoría de Google. Debes configurar esto antes de sincronizar.</div>';
    } else {
        echo '<div class="status success">✅ ' . $categories_with_google . ' categoría(s) configurada(s)</div>';
    }

    // Sync stats
    echo '<h2>5. Estadísticas de Sincronización</h2>';

    $counts = get_option('mcs_destination_counts', []);
    $errors = get_option('mcs_sync_errors', []);
    $last_sync = get_option('mcs_last_full_sync', 0);

    echo '<table>';
    echo '<tr><th>Métrica</th><th>Valor</th></tr>';
    echo '<tr><td>Última sincronización</td><td>' . ($last_sync ? human_time_diff($last_sync, current_time('timestamp')) . ' atrás' : 'Nunca') . '</td></tr>';
    echo '<tr><td>Productos en Google</td><td>' . (isset($counts['google']['items']) ? $counts['google']['items'] : 0) . '</td></tr>';
    echo '<tr><td>Errores totales</td><td>' . count($errors) . '</td></tr>';
    echo '</table>';

    if (!empty($errors)) {
        echo '<h3>Errores Recientes</h3>';
        echo '<table>';
        echo '<tr><th>Producto</th><th>Destino</th><th>Error</th><th>Tiempo</th></tr>';
        foreach (array_slice($errors, -10) as $error) {
            echo '<tr>';
            echo '<td>' . esc_html($error['product_name'] ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($error['destination'] ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($error['message'] ?? 'N/A') . '</td>';
            echo '<td>' . (isset($error['timestamp']) ? human_time_diff($error['timestamp'], current_time('timestamp')) . ' atrás' : 'N/A') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    // Logs
    echo '<h2>6. Logs Recientes</h2>';
    $log_dir = WC_LOG_DIR;
    $log_files = glob($log_dir . 'multi-catalog-sync-*.log');

    if ($log_files) {
        rsort($log_files); // Most recent first
        $latest_log = $log_files[0];
        echo '<p>Archivo de log más reciente: <span class="code">' . basename($latest_log) . '</span></p>';

        $log_content = file_get_contents($latest_log);
        $log_lines = explode("\n", $log_content);
        $recent_lines = array_slice($log_lines, -50); // Last 50 lines

        echo '<pre>' . esc_html(implode("\n", $recent_lines)) . '</pre>';
    } else {
        echo '<div class="status info">No hay logs todavía. Se crearán después de la primera sincronización.</div>';
    }

    // Action buttons
    echo '<h2>7. Acciones Rápidas</h2>';
    echo '<p>';
    echo '<a href="' . admin_url('admin.php?page=mad-multi-catalog-sync') . '" class="button button-primary">Ir al Dashboard</a> ';
    echo '<a href="' . admin_url('admin.php?page=wc-status&tab=logs') . '" class="button">Ver Logs WooCommerce</a> ';
    echo '<a href="https://merchants.google.com/" target="_blank" class="button">Abrir Google Merchant Center</a>';
    echo '</p>';
    ?>

</div>
</body>
</html>
