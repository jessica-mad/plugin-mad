<?php
/**
 * Vista: Visualizador de Logs
 * Muestra los logs de validación de cupones
 */

if (!defined('ABSPATH')) exit;

// Verificar permisos
if (!current_user_can('manage_options')) {
    wp_die('No tienes permisos para acceder a esta página');
}

$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/mad-suite-logs';
$log_files = [];

if (is_dir($log_dir)) {
    $files = scandir($log_dir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'log') {
            $log_files[] = $file;
        }
    }
    rsort($log_files); // Más reciente primero
}

$selected_file = isset($_GET['log_file']) ? sanitize_file_name($_GET['log_file']) : ($log_files[0] ?? null);
$log_content = '';

if ($selected_file && file_exists($log_dir . '/' . $selected_file)) {
    $log_content = file_get_contents($log_dir . '/' . $selected_file);
}
?>

<div class="wrap">
    <h1>📋 Logs de Validación de Cupones</h1>

    <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">

        <?php if (empty($log_files)): ?>
            <div class="notice notice-warning">
                <p><strong>⚠️ No hay archivos de log disponibles</strong></p>
                <p>Los logs se crearán automáticamente cuando se validen cupones.</p>
                <p>Ubicación: <code><?php echo esc_html($log_dir); ?></code></p>
            </div>
        <?php else: ?>

            <!-- Selector de archivo -->
            <div style="margin-bottom: 20px;">
                <label for="log_file_selector" style="font-weight: bold; display: block; margin-bottom: 8px;">
                    📁 Seleccionar archivo de log:
                </label>
                <select id="log_file_selector" style="width: 300px; padding: 6px;">
                    <?php foreach ($log_files as $file): ?>
                        <option value="<?php echo esc_attr($file); ?>" <?php selected($selected_file, $file); ?>>
                            <?php echo esc_html($file); ?>
                            (<?php echo size_format(filesize($log_dir . '/' . $file)); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button" onclick="location.reload()">🔄 Recargar</button>
            </div>

            <script>
                document.getElementById('log_file_selector').addEventListener('change', function() {
                    var file = this.value;
                    var url = new URL(window.location.href);
                    url.searchParams.set('log_file', file);
                    window.location.href = url.toString();
                });
            </script>

            <!-- Información del archivo -->
            <div style="background: #f0f0f1; padding: 12px; margin-bottom: 15px; border-radius: 4px;">
                <strong>📄 Archivo:</strong> <?php echo esc_html($selected_file); ?><br>
                <strong>📊 Tamaño:</strong> <?php echo size_format(filesize($log_dir . '/' . $selected_file)); ?><br>
                <strong>🕐 Última modificación:</strong> <?php echo date('Y-m-d H:i:s', filemtime($log_dir . '/' . $selected_file)); ?><br>
                <strong>📝 Líneas:</strong> <?php echo count(explode("\n", $log_content)); ?>
            </div>

            <!-- Controles -->
            <div style="margin-bottom: 15px;">
                <a href="<?php echo admin_url('admin.php?page=mad-private-shop&action=logs'); ?>"
                   class="button">
                    🔄 Actualizar
                </a>
                <button type="button" class="button" onclick="clearLogs()">
                    🗑️ Limpiar logs antiguos (>7 días)
                </button>
            </div>

            <script>
                function clearLogs() {
                    if (confirm('¿Estás segura de que quieres eliminar los logs con más de 7 días de antigüedad?')) {
                        // Implementar limpieza vía AJAX si es necesario
                        alert('Función de limpieza pendiente de implementar');
                    }
                }
            </script>

            <!-- Contenido del log -->
            <div style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.5;">
                <?php if (empty($log_content)): ?>
                    <div style="color: #888;">El archivo está vacío</div>
                <?php else: ?>
                    <?php
                    $lines = explode("\n", $log_content);
                    $lines = array_reverse($lines); // Mostrar más reciente primero

                    foreach ($lines as $line) {
                        if (empty(trim($line))) continue;

                        // Colorear según tipo de log
                        $color = '#d4d4d4';
                        if (strpos($line, 'ERROR') !== false) {
                            $color = '#f48771';
                        } elseif (strpos($line, 'SUCCESS') !== false) {
                            $color = '#89d185';
                        } elseif (strpos($line, 'validate_coupon_schedule') !== false) {
                            $color = '#6fc6ff';
                        } elseif (strpos($line, 'is_admin: true') !== false) {
                            $color = '#ffd700';
                        }

                        echo '<div style="color: ' . $color . '; padding: 2px 0;">' . esc_html($line) . '</div>';
                    }
                    ?>
                <?php endif; ?>
            </div>

            <!-- Leyenda de colores -->
            <div style="margin-top: 15px; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                <strong>Leyenda:</strong>
                <span style="color: #f48771; margin-left: 10px;">■ ERROR</span>
                <span style="color: #89d185; margin-left: 10px;">■ SUCCESS</span>
                <span style="color: #6fc6ff; margin-left: 10px;">■ Validación</span>
                <span style="color: #ffd700; margin-left: 10px;">■ Admin</span>
            </div>

        <?php endif; ?>

        <!-- Botón volver -->
        <div style="margin-top: 20px;">
            <a href="<?php echo admin_url('admin.php?page=mad-private-shop'); ?>" class="button">
                ← Volver a Private Shop
            </a>
        </div>
    </div>
</div>
