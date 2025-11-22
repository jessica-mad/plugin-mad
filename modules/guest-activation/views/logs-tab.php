<?php
/**
 * Vista del tab de Logs
 */

if (!defined('ABSPATH')) exit;

$log_files = $module->get_log_files();
$selected_log = isset($_GET['log_file']) ? sanitize_text_field($_GET['log_file']) : '';

// Si no hay archivo seleccionado pero hay logs disponibles, seleccionar el más reciente
if (empty($selected_log) && !empty($log_files)) {
    $selected_log = basename($log_files[0]);
}

$log_content = '';
if (!empty($selected_log)) {
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/mad-guest-activation-logs';
    $log_path = $log_dir . '/' . $selected_log;

    if (file_exists($log_path)) {
        $log_content = $module->read_log_file($log_path);
    }
}
?>

<div class="mad-logs-viewer">
    <h2><?php esc_html_e('Visor de Logs', 'mad-suite'); ?></h2>

    <?php if (empty($log_files)): ?>
        <div class="notice notice-info inline">
            <p><?php esc_html_e('No hay archivos de log disponibles aún.', 'mad-suite'); ?></p>
            <p><?php esc_html_e('Los logs se generarán automáticamente cuando se realicen activaciones de cuentas.', 'mad-suite'); ?></p>
        </div>
    <?php else: ?>
        <div class="mad-logs-controls">
            <div class="mad-log-selector">
                <label for="log-file-select">
                    <strong><?php esc_html_e('Seleccionar archivo de log:', 'mad-suite'); ?></strong>
                </label>
                <select id="log-file-select" class="regular-text">
                    <?php foreach ($log_files as $file): ?>
                        <?php
                        $filename = basename($file);
                        $file_size = size_format(filesize($file));
                        $file_date = date('Y-m-d H:i:s', filemtime($file));
                        ?>
                        <option value="<?php echo esc_attr($filename); ?>"
                                <?php selected($selected_log, $filename); ?>>
                            <?php echo esc_html($filename); ?> (<?php echo esc_html($file_size); ?> - <?php echo esc_html($file_date); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button" id="refresh-log-btn">
                    <?php esc_html_e('Actualizar', 'mad-suite'); ?>
                </button>
            </div>

            <div class="mad-log-actions">
                <button type="button" class="button" id="clear-logs-btn">
                    <?php esc_html_e('Limpiar logs antiguos', 'mad-suite'); ?>
                </button>
                <span class="description">
                    <?php esc_html_e('(Elimina logs de más de 30 días)', 'mad-suite'); ?>
                </span>
            </div>
        </div>

        <div class="mad-log-stats">
            <div class="mad-stat-box">
                <strong><?php esc_html_e('Total de archivos:', 'mad-suite'); ?></strong>
                <span><?php echo count($log_files); ?></span>
            </div>
            <?php if (!empty($selected_log) && !empty($log_content)): ?>
                <?php
                $lines = explode("\n", trim($log_content));
                $total_lines = count($lines);
                ?>
                <div class="mad-stat-box">
                    <strong><?php esc_html_e('Líneas en este archivo:', 'mad-suite'); ?></strong>
                    <span><?php echo $total_lines; ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="mad-log-content-wrapper">
            <div class="mad-log-header">
                <strong><?php esc_html_e('Contenido del log:', 'mad-suite'); ?></strong>
                <span class="mad-log-filename"><?php echo esc_html($selected_log); ?></span>
            </div>
            <div class="mad-log-content" id="log-content-display">
                <?php if (!empty($log_content)): ?>
                    <pre><?php echo esc_html($log_content); ?></pre>
                <?php else: ?>
                    <p class="no-content"><?php esc_html_e('El archivo de log está vacío.', 'mad-suite'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="mad-log-help">
            <h3><?php esc_html_e('Información sobre los logs', 'mad-suite'); ?></h3>
            <p><?php esc_html_e('Los logs registran las siguientes actividades:', 'mad-suite'); ?></p>
            <ul>
                <li><?php esc_html_e('Intentos de activación de cuentas (exitosos y fallidos)', 'mad-suite'); ?></li>
                <li><?php esc_html_e('Tokens generados y su expiración', 'mad-suite'); ?></li>
                <li><?php esc_html_e('Órdenes atribuidas a usuarios', 'mad-suite'); ?></li>
                <li><?php esc_html_e('Búsquedas de pedidos previos desde "Mis pedidos"', 'mad-suite'); ?></li>
                <li><?php esc_html_e('Registros bloqueados por tener pedidos como invitado', 'mad-suite'); ?></li>
            </ul>
            <p>
                <strong><?php esc_html_e('Ubicación de los logs:', 'mad-suite'); ?></strong>
                <?php
                $upload_dir = wp_upload_dir();
                echo '<code>' . esc_html($upload_dir['basedir'] . '/mad-guest-activation-logs/') . '</code>';
                ?>
            </p>
        </div>
    <?php endif; ?>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Cambiar archivo de log
    $('#log-file-select').on('change', function() {
        var selectedLog = $(this).val();
        var currentUrl = window.location.href.split('&log_file=')[0];
        window.location.href = currentUrl + '&log_file=' + encodeURIComponent(selectedLog);
    });

    // Actualizar log
    $('#refresh-log-btn').on('click', function() {
        location.reload();
    });

    // Limpiar logs antiguos
    $('#clear-logs-btn').on('click', function() {
        if (!confirm('<?php echo esc_js(__('¿Estás seguro de que deseas eliminar los logs antiguos (más de 30 días)?', 'mad-suite')); ?>')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Limpiando...', 'mad-suite')); ?>');

        $.ajax({
            url: madGuestActivationAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_guest_activation_clear_logs',
                nonce: madGuestActivationAdmin.clear_logs_nonce,
                days: 30
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message || '<?php echo esc_js(__('Error al limpiar logs.', 'mad-suite')); ?>');
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Limpiar logs antiguos', 'mad-suite')); ?>');
                }
            },
            error: function() {
                alert('<?php echo esc_js(__('Error de conexión.', 'mad-suite')); ?>');
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Limpiar logs antiguos', 'mad-suite')); ?>');
            }
        });
    });
});
</script>
