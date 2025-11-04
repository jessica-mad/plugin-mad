<?php
/**
 * Vista: Visor de Logs del Private Shop
 */

defined('ABSPATH') || exit;

// Obtener directorio de logs
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/mad-suite-logs';
$log_file = $log_dir . '/private-shop-' . date('Y-m-d') . '.log';

// Leer logs
$logs_content = '';
$logs_exist = file_exists($log_file);

if ($logs_exist) {
    $logs_content = file_get_contents($log_file);
    $log_lines = array_filter(explode("\n", $logs_content));
    $log_lines = array_reverse($log_lines); // Más recientes primero
} else {
    $log_lines = [];
}

// Filtros
$filter_level = isset($_GET['filter_level']) ? $_GET['filter_level'] : 'all';
$filter_search = isset($_GET['filter_search']) ? $_GET['filter_search'] : '';

// Aplicar filtros
if ($filter_level !== 'all' || !empty($filter_search)) {
    $log_lines = array_filter($log_lines, function($line) use ($filter_level, $filter_search) {
        if ($filter_level !== 'all' && strpos($line, '[' . strtoupper($filter_level) . ']') === false) {
            return false;
        }
        if (!empty($filter_search) && stripos($line, $filter_search) === false) {
            return false;
        }
        return true;
    });
}

?>

<div class="wrap">
    <h1>🔍 Visor de Logs - Private Shop</h1>

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
           class="nav-tab nav-tab-active">
            🔍 Logs de Debug
        </a>
        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'test'], admin_url('admin.php')); ?>"
           class="nav-tab">
            🧪 Test & Diagnóstico
        </a>
    </nav>

    <!-- Información del archivo de log -->
    <div class="notice notice-info" style="margin: 20px 0;">
        <p>
            📁 <strong>Archivo de log:</strong> <code><?php echo esc_html($log_file); ?></code><br>
            <?php if ($logs_exist): ?>
                ✓ Tamaño: <?php echo size_format(filesize($log_file)); ?> |
                Última modificación: <?php echo date('Y-m-d H:i:s', filemtime($log_file)); ?> |
                Total líneas: <?php echo count($log_lines); ?>
            <?php else: ?>
                ⚠️ No hay logs de hoy aún
            <?php endif; ?>
        </p>
    </div>

    <!-- Filtros -->
    <div class="card" style="max-width: 100%; padding: 15px; margin-bottom: 20px;">
        <form method="get" action="">
            <input type="hidden" name="page" value="mad-private-shop">
            <input type="hidden" name="action" value="logs">

            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <div>
                    <label><strong>Nivel:</strong></label>
                    <select name="filter_level" style="margin-left: 10px;">
                        <option value="all" <?php selected($filter_level, 'all'); ?>>Todos</option>
                        <option value="success" <?php selected($filter_level, 'success'); ?>>✓ SUCCESS</option>
                        <option value="info" <?php selected($filter_level, 'info'); ?>>ℹ INFO</option>
                        <option value="debug" <?php selected($filter_level, 'debug'); ?>>🔍 DEBUG</option>
                        <option value="warning" <?php selected($filter_level, 'warning'); ?>>⚠ WARNING</option>
                        <option value="error" <?php selected($filter_level, 'error'); ?>>❌ ERROR</option>
                    </select>
                </div>

                <div style="flex: 1;">
                    <label><strong>Buscar:</strong></label>
                    <input type="text" name="filter_search" value="<?php echo esc_attr($filter_search); ?>"
                           placeholder="Buscar en logs..." style="width: 300px; margin-left: 10px;">
                </div>

                <div>
                    <button type="submit" class="button">🔍 Filtrar</button>
                    <a href="<?php echo add_query_arg(['page' => 'mad-private-shop', 'action' => 'logs'], admin_url('admin.php')); ?>"
                       class="button">🔄 Limpiar filtros</a>
                    <button type="button" class="button" onclick="location.reload()">♻️ Recargar</button>
                </div>
            </div>
        </form>
    </div>

    <?php if (!$logs_exist): ?>
        <!-- Estado vacío -->
        <div class="card" style="max-width: 100%; text-align: center; padding: 60px 20px;">
            <div style="font-size: 64px; margin-bottom: 20px;">📋</div>
            <h2>No hay logs disponibles para hoy</h2>
            <p style="color: #666;">
                Los logs se generarán automáticamente cuando ocurran eventos en el sistema.
            </p>
        </div>
    <?php else: ?>
        <!-- Logs -->
        <div class="card" style="max-width: 100%; padding: 0;">
            <div style="background: #1e1e1e; color: #d4d4d4; font-family: 'Courier New', monospace; font-size: 13px; padding: 20px; max-height: 600px; overflow-y: auto;">
                <?php if (empty($log_lines)): ?>
                    <div style="color: #888; text-align: center; padding: 40px;">
                        No hay logs que coincidan con los filtros seleccionados
                    </div>
                <?php else: ?>
                    <?php foreach ($log_lines as $line):
                        // Colorear según nivel
                        $color = '#d4d4d4';
                        if (strpos($line, '[SUCCESS]') !== false) {
                            $color = '#4CAF50';
                        } elseif (strpos($line, '[ERROR]') !== false) {
                            $color = '#f44336';
                        } elseif (strpos($line, '[WARNING]') !== false) {
                            $color = '#ff9800';
                        } elseif (strpos($line, '[DEBUG]') !== false) {
                            $color = '#2196F3';
                        } elseif (strpos($line, '[INFO]') !== false) {
                            $color = '#9C27B0';
                        }

                        // Resaltar búsqueda
                        if (!empty($filter_search)) {
                            $line = str_ireplace($filter_search, '<mark style="background: #ffeb3b; color: #000;">' . $filter_search . '</mark>', $line);
                        }
                    ?>
                        <div style="color: <?php echo $color; ?>; padding: 3px 0; border-bottom: 1px solid #333; word-wrap: break-word;">
                            <?php echo $line; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Acciones -->
        <div style="margin-top: 20px; display: flex; gap: 10px;">
            <a href="<?php echo esc_url($upload_dir['baseurl'] . '/mad-suite-logs/private-shop-' . date('Y-m-d') . '.log'); ?>"
               class="button" target="_blank" download>
                📥 Descargar Log Completo
            </a>
            <button type="button" class="button button-link-delete" onclick="clearTodayLogs()">
                🗑️ Limpiar Logs de Hoy
            </button>
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
mark {
    padding: 2px 4px;
    border-radius: 2px;
}
</style>

<script>
function clearTodayLogs() {
    if (!confirm('¿Estás seguro de que quieres limpiar todos los logs de hoy?\n\nEsta acción no se puede deshacer.')) {
        return;
    }

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mad_clear_today_logs&nonce=<?php echo wp_create_nonce('mad_clear_logs'); ?>'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('✓ Logs limpiados correctamente');
            location.reload();
        } else {
            alert('❌ Error al limpiar logs: ' + data.message);
        }
    });
}
</script>
