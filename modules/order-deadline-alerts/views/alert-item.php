<?php
/**
 * Template de item de alerta
 *
 * @package MAD_Suite
 * @subpackage Order_Deadline_Alerts
 */

if (!defined('ABSPATH')) {
    exit;
}

$alert_enabled = $alert['enabled'] ?? true;
$days_labels = [
    1 => __('Lun', 'mad-suite'),
    2 => __('Mar', 'mad-suite'),
    3 => __('Mié', 'mad-suite'),
    4 => __('Jue', 'mad-suite'),
    5 => __('Vie', 'mad-suite'),
    6 => __('Sáb', 'mad-suite'),
    7 => __('Dom', 'mad-suite'),
];

$selected_days = array_map(function($day) use ($days_labels) {
    return $days_labels[$day] ?? '';
}, $alert['days'] ?? []);
?>

<div class="mads-oda-alert-item <?php echo $alert_enabled ? 'mads-oda-alert-enabled' : 'mads-oda-alert-disabled'; ?>"
     data-alert-id="<?php echo esc_attr($alert['id']); ?>">

    <div class="mads-oda-alert-header">
        <h3 class="mads-oda-alert-title">
            <span class="mads-oda-alert-name"><?php echo esc_html($alert['name']); ?></span>
            <span class="mads-oda-alert-status <?php echo $alert_enabled ? 'mads-oda-status-enabled' : 'mads-oda-status-disabled'; ?>">
                <span class="dashicons <?php echo $alert_enabled ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>"></span>
                <?php echo $alert_enabled ? __('Activa', 'mad-suite') : __('Inactiva', 'mad-suite'); ?>
            </span>
        </h3>
        <div class="mads-oda-alert-actions">
            <button type="button" class="button button-small mads-oda-toggle-alert"
                    title="<?php esc_attr_e('Activar/Desactivar', 'mad-suite'); ?>">
                <span class="dashicons dashicons-controls-pause"></span>
            </button>
            <button type="button" class="button button-small mads-oda-edit-alert"
                    title="<?php esc_attr_e('Editar', 'mad-suite'); ?>">
                <span class="dashicons dashicons-edit"></span>
            </button>
            <button type="button" class="button button-small button-link-delete mads-oda-delete-alert"
                    title="<?php esc_attr_e('Eliminar', 'mad-suite'); ?>">
                <span class="dashicons dashicons-trash"></span>
            </button>
        </div>
    </div>

    <div class="mads-oda-alert-content">
        <div class="mads-oda-alert-form" style="display: none;">
            <table class="form-table">
                <tr>
                    <th><label><?php _e('Nombre de la Alerta', 'mad-suite'); ?> *</label></th>
                    <td>
                        <input type="text" class="regular-text mads-oda-alert-field" name="name"
                               value="<?php echo esc_attr($alert['name']); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Días de la Semana', 'mad-suite'); ?> *</label></th>
                    <td class="mads-oda-days-checkboxes">
                        <?php foreach ($days_labels as $day_num => $day_label): ?>
                            <label>
                                <input type="checkbox" name="days" value="<?php echo esc_attr($day_num); ?>"
                                       <?php checked(in_array($day_num, $alert['days'] ?? [])); ?>>
                                <?php echo esc_html($day_label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description"><?php _e('Selecciona los días en los que esta alerta estará activa.', 'mad-suite'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Hora de Inicio (Madrid)', 'mad-suite'); ?> *</label></th>
                    <td>
                        <input type="time" class="regular-text mads-oda-alert-field" name="start_time"
                               value="<?php echo esc_attr($alert['start_time'] ?? '00:00'); ?>" required>
                        <p class="description"><?php _e('Hora desde la que se empezará a mostrar la alerta.', 'mad-suite'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Hora Límite (Madrid)', 'mad-suite'); ?> *</label></th>
                    <td>
                        <input type="time" class="regular-text mads-oda-alert-field" name="deadline_time"
                               value="<?php echo esc_attr($alert['deadline_time']); ?>" required>
                        <p class="description"><?php _e('Hora límite hasta la que se mostrará la alerta.', 'mad-suite'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Días de Diferencia para Entrega', 'mad-suite'); ?></label></th>
                    <td>
                        <input type="number" min="0" max="7" class="small-text mads-oda-alert-field"
                               name="delivery_day_offset" value="<?php echo esc_attr($alert['delivery_day_offset'] ?? 1); ?>">
                        <p class="description"><?php _e('Número de días después del pedido para la entrega (por defecto 1 = mañana).', 'mad-suite'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Mensaje (Español)', 'mad-suite'); ?> *</label></th>
                    <td>
                        <textarea class="large-text mads-oda-alert-field" name="message_es" rows="3" required><?php echo esc_textarea($alert['message_es']); ?></textarea>
                        <p class="description">
                            <?php _e('Variables disponibles: {time} (hora límite), {countdown} (contador regresivo), {delivery_date} (fecha de entrega).', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('Mensaje (Inglés)', 'mad-suite'); ?></label></th>
                    <td>
                        <textarea class="large-text mads-oda-alert-field" name="message_en" rows="3"><?php echo esc_textarea($alert['message_en'] ?? ''); ?></textarea>
                        <p class="description">
                            <?php _e('Mensaje alternativo en inglés (opcional si tienes multiidioma activado).', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <div class="mads-oda-alert-form-actions">
                <button type="button" class="button button-primary mads-oda-save-alert-btn">
                    <?php _e('Guardar Alerta', 'mad-suite'); ?>
                </button>
                <button type="button" class="button mads-oda-cancel-edit-btn">
                    <?php _e('Cancelar', 'mad-suite'); ?>
                </button>
            </div>
        </div>

        <div class="mads-oda-alert-summary">
            <div class="mads-oda-alert-info">
                <strong><?php _e('Días:', 'mad-suite'); ?></strong>
                <span class="mads-oda-info-days"><?php echo esc_html(implode(', ', $selected_days)); ?></span>
            </div>
            <div class="mads-oda-alert-info">
                <strong><?php _e('Horario:', 'mad-suite'); ?></strong>
                <span class="mads-oda-info-time"><?php echo esc_html(($alert['start_time'] ?? '00:00') . ' - ' . $alert['deadline_time']); ?></span>
            </div>
            <div class="mads-oda-alert-info">
                <strong><?php _e('Mensaje:', 'mad-suite'); ?></strong>
                <span class="mads-oda-info-message"><?php echo esc_html(wp_trim_words($alert['message_es'], 15)); ?></span>
            </div>
        </div>
    </div>
</div>
