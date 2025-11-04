<?php
if (! defined('ABSPATH')) {
    exit;
}

use MAD_Suite\Modules\RoleCreator\Logger;

$logger = Logger::instance();
$logs = $logger->get_logs(100); // Últimos 100 logs
$stats = $logger->get_stats();

// Filtro por nivel si está definido
$filter_level = isset($_GET['filter_level']) ? sanitize_key($_GET['filter_level']) : '';
if (! empty($filter_level)) {
    $logs = $logger->get_logs(0, $filter_level);
}

$level_colors = [
    'info'    => '#2271b1',
    'success' => '#46b450',
    'warning' => '#dba617',
    'error'   => '#dc3232',
    'debug'   => '#666',
];

$level_icons = [
    'info'    => 'dashicons-info',
    'success' => 'dashicons-yes-alt',
    'warning' => 'dashicons-warning',
    'error'   => 'dashicons-dismiss',
    'debug'   => 'dashicons-search',
];
?>

<div class="mad-role-creator__logs">
    <div class="card">
        <h2><?php esc_html_e('Registro de Actividad', 'mad-suite'); ?></h2>
        <p class="description">
            <?php esc_html_e('Visualiza todos los eventos de asignación de roles y sincronización con Mailchimp.', 'mad-suite'); ?>
        </p>

        <!-- Estadísticas -->
        <div style="display: flex; gap: 15px; margin: 20px 0; flex-wrap: wrap;">
            <div style="background: #f0f0f1; padding: 15px; border-radius: 5px; flex: 1; min-width: 150px;">
                <strong style="font-size: 24px;"><?php echo esc_html($stats['total']); ?></strong>
                <div style="color: #666; font-size: 12px; margin-top: 5px;"><?php esc_html_e('Total de eventos', 'mad-suite'); ?></div>
            </div>
            <div style="background: <?php echo esc_attr($level_colors['success']); ?>15; padding: 15px; border-radius: 5px; flex: 1; min-width: 150px;">
                <strong style="font-size: 24px; color: <?php echo esc_attr($level_colors['success']); ?>;"><?php echo esc_html($stats['success']); ?></strong>
                <div style="color: #666; font-size: 12px; margin-top: 5px;"><?php esc_html_e('Éxitos', 'mad-suite'); ?></div>
            </div>
            <div style="background: <?php echo esc_attr($level_colors['error']); ?>15; padding: 15px; border-radius: 5px; flex: 1; min-width: 150px;">
                <strong style="font-size: 24px; color: <?php echo esc_attr($level_colors['error']); ?>;"><?php echo esc_html($stats['error']); ?></strong>
                <div style="color: #666; font-size: 12px; margin-top: 5px;"><?php esc_html_e('Errores', 'mad-suite'); ?></div>
            </div>
            <div style="background: <?php echo esc_attr($level_colors['warning']); ?>15; padding: 15px; border-radius: 5px; flex: 1; min-width: 150px;">
                <strong style="font-size: 24px; color: <?php echo esc_attr($level_colors['warning']); ?>;"><?php echo esc_html($stats['warning']); ?></strong>
                <div style="color: #666; font-size: 12px; margin-top: 5px;"><?php esc_html_e('Advertencias', 'mad-suite'); ?></div>
            </div>
        </div>

        <!-- Controles -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php
                $base_url = remove_query_arg(['filter_level'], $_SERVER['REQUEST_URI']);
                $levels = [
                    ''        => __('Todos', 'mad-suite'),
                    'success' => __('Éxitos', 'mad-suite'),
                    'error'   => __('Errores', 'mad-suite'),
                    'warning' => __('Advertencias', 'mad-suite'),
                    'info'    => __('Info', 'mad-suite'),
                    'debug'   => __('Debug', 'mad-suite'),
                ];

                foreach ($levels as $level => $label) :
                    $url = $level ? add_query_arg(['filter_level' => $level], $base_url) : $base_url;
                    $is_active = ($filter_level === $level) || ($filter_level === '' && $level === '');
                ?>
                    <a href="<?php echo esc_url($url); ?>"
                       class="button <?php echo $is_active ? 'button-primary' : 'button-secondary'; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div>
                <?php
                $clear_url = wp_nonce_url(
                    admin_url('admin-post.php?action=mads_role_creator_clear_logs'),
                    'mads_role_creator_clear_logs',
                    'mads_role_creator_nonce'
                );
                ?>
                <a href="<?php echo esc_url($clear_url); ?>"
                   class="button button-link-delete"
                   onclick="return confirm('<?php esc_attr_e('¿Estás seguro de limpiar todos los logs?', 'mad-suite'); ?>');">
                    <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                    <?php esc_html_e('Limpiar Logs', 'mad-suite'); ?>
                </a>
            </div>
        </div>

        <!-- Lista de Logs -->
        <?php if (empty($logs)) : ?>
            <div style="padding: 40px; text-align: center; background: #f9f9f9; border-radius: 5px;">
                <p style="margin: 0; color: #666;">
                    <span class="dashicons dashicons-info" style="font-size: 48px; opacity: 0.3;"></span><br>
                    <?php esc_html_e('No hay eventos registrados aún.', 'mad-suite'); ?>
                </p>
            </div>
        <?php else : ?>
            <div style="max-height: 600px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px;">
                <table class="widefat" style="margin: 0;">
                    <thead>
                        <tr>
                            <th style="width: 80px;"><?php esc_html_e('Nivel', 'mad-suite'); ?></th>
                            <th style="width: 150px;"><?php esc_html_e('Fecha/Hora', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('Mensaje', 'mad-suite'); ?></th>
                            <th style="width: 50px;"><?php esc_html_e('Info', 'mad-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $index => $log) : ?>
                            <?php
                            $level = isset($log['level']) ? $log['level'] : 'info';
                            $color = isset($level_colors[$level]) ? $level_colors[$level] : '#666';
                            $icon = isset($level_icons[$level]) ? $level_icons[$level] : 'dashicons-info';
                            $timestamp = isset($log['timestamp']) ? $log['timestamp'] : '';
                            $message = isset($log['message']) ? $log['message'] : '';
                            $context = isset($log['context']) ? $log['context'] : [];
                            ?>
                            <tr style="background: <?php echo $index % 2 === 0 ? '#ffffff' : '#f9f9f9'; ?>;">
                                <td>
                                    <span style="display: inline-block; padding: 4px 8px; background: <?php echo esc_attr($color); ?>15; color: <?php echo esc_attr($color); ?>; border-radius: 3px; font-size: 11px; font-weight: bold;">
                                        <span class="dashicons <?php echo esc_attr($icon); ?>" style="font-size: 14px; vertical-align: middle; margin-right: 2px;"></span>
                                        <?php echo esc_html(strtoupper($level)); ?>
                                    </span>
                                </td>
                                <td style="font-family: monospace; font-size: 12px; color: #666;">
                                    <?php
                                    if ($timestamp) {
                                        echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($timestamp)));
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo esc_html($message); ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (! empty($context)) : ?>
                                        <button type="button" class="button button-small toggle-context-btn" data-log-index="<?php echo esc_attr($index); ?>">
                                            <span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (! empty($context)) : ?>
                                <tr id="log-context-<?php echo esc_attr($index); ?>" style="display: none; background: <?php echo esc_attr($color); ?>08;">
                                    <td colspan="4" style="padding: 15px;">
                                        <strong><?php esc_html_e('Contexto:', 'mad-suite'); ?></strong>
                                        <pre style="background: #fff; padding: 10px; border-radius: 3px; overflow-x: auto; margin: 10px 0 0 0;"><?php echo esc_html(print_r($context, true)); ?></pre>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p style="margin-top: 15px; color: #666; font-size: 12px;">
                <?php
                printf(
                    esc_html__('Mostrando %d eventos. Los logs se actualizan en tiempo real.', 'mad-suite'),
                    count($logs)
                );
                ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('.toggle-context-btn').on('click', function() {
        var logIndex = $(this).data('log-index');
        var $contextRow = $('#log-context-' + logIndex);
        $contextRow.toggle();

        var $icon = $(this).find('.dashicons');
        if ($contextRow.is(':visible')) {
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });
});
</script>
