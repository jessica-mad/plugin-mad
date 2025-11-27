<?php
/**
 * Test simple de inclusión
 */

defined('ABSPATH') || exit;

echo '<div class="wrap">';
echo '<h1>Test de Inclusión de coupons-list.php</h1>';

// Capturar cualquier output o error
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    echo '<p>Intentando incluir coupons-list.php...</p>';

    // Guardar referencia al archivo
    $file = __DIR__ . '/coupons-list.php';
    echo '<p>Archivo: ' . $file . '</p>';
    echo '<p>Existe: ' . (file_exists($file) ? 'SÍ' : 'NO') . '</p>';
    echo '<p>Legible: ' . (is_readable($file) ? 'SÍ' : 'NO') . '</p>';

    if (file_exists($file) && is_readable($file)) {
        echo '<hr>';
        echo '<h2>Output del archivo:</h2>';
        include $file;
        echo '<hr>';
        echo '<p style="color: green; font-weight: bold;">✓ El archivo se incluyó sin errores fatales</p>';
    }

} catch (Exception $e) {
    echo '<div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545;">';
    echo '<h2>Error Capturado:</h2>';
    echo '<p><strong>Mensaje:</strong> ' . esc_html($e->getMessage()) . '</p>';
    echo '<p><strong>Archivo:</strong> ' . esc_html($e->getFile()) . '</p>';
    echo '<p><strong>Línea:</strong> ' . $e->getLine() . '</p>';
    echo '<pre>' . esc_html($e->getTraceAsString()) . '</pre>';
    echo '</div>';
} catch (Error $e) {
    echo '<div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545;">';
    echo '<h2>Error Fatal Capturado:</h2>';
    echo '<p><strong>Mensaje:</strong> ' . esc_html($e->getMessage()) . '</p>';
    echo '<p><strong>Archivo:</strong> ' . esc_html($e->getFile()) . '</p>';
    echo '<p><strong>Línea:</strong> ' . $e->getLine() . '</p>';
    echo '<pre>' . esc_html($e->getTraceAsString()) . '</pre>';
    echo '</div>';
}

$output = ob_get_clean();
echo $output;

// Mostrar errores de PHP si los hay
$errors = error_get_last();
if ($errors) {
    echo '<div style="background: #fff3cd; padding: 15px; border-left: 4px solid #856404;">';
    echo '<h2>Último Error PHP:</h2>';
    echo '<pre>' . print_r($errors, true) . '</pre>';
    echo '</div>';
}

echo '</div>';
