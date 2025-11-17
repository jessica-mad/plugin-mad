<?php
/**
 * Vista de configuración del módulo Order Deadline Alerts
 *
 * @package MAD_Suite
 * @subpackage Order_Deadline_Alerts
 */

if (!defined('ABSPATH')) {
    exit;
}

$option_key = MAD_Suite_Core::option_key($this->slug());
$settings = $this->get_settings();
?>

<div class="wrap mads-oda-settings">
    <h1><?php echo esc_html($this->title()); ?></h1>

    <?php if (isset($_GET['updated'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Configuración guardada correctamente.', 'mad-suite'); ?></p>
        </div>
    <?php endif; ?>

    <div class="mads-oda-container">
        <!-- Configuración General -->
        <div class="mads-oda-section">
            <h2><?php _e('Configuración General', 'mad-suite'); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mads-oda-general-form">
                <input type="hidden" name="action" value="mads_oda_save_settings">
                <?php wp_nonce_field('mads_oda_save_settings', 'mads_oda_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="enabled"><?php _e('Activar Módulo', 'mad-suite'); ?></label>
                        </th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr($option_key); ?>[enabled]" value="0">
                            <input type="checkbox"
                                   id="enabled"
                                   name="<?php echo esc_attr($option_key); ?>[enabled]"
                                   value="1"
                                   <?php checked($settings['enabled'], 1); ?>>
                            <p class="description">
                                <?php _e('Activa o desactiva la visualización de alertas en el frontend.', 'mad-suite'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="countdown_format"><?php _e('Formato de Countdown', 'mad-suite'); ?></label>
                        </th>
                        <td>
                            <select id="countdown_format" name="<?php echo esc_attr($option_key); ?>[countdown_format]">
                                <option value="hh:mm" <?php selected($settings['countdown_format'], 'hh:mm'); ?>>
                                    <?php _e('HH:MM (Horas y Minutos)', 'mad-suite'); ?>
                                </option>
                                <option value="hh:mm:ss" <?php selected($settings['countdown_format'], 'hh:mm:ss'); ?>>
                                    <?php _e('HH:MM:SS (Horas, Minutos y Segundos)', 'mad-suite'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php _e('Formato de visualización del countdown.', 'mad-suite'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="enable_wpml"><?php _e('Soporte Multiidioma', 'mad-suite'); ?></label>
                        </th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr($option_key); ?>[enable_wpml]" value="0">
                            <input type="checkbox"
                                   id="enable_wpml"
                                   name="<?php echo esc_attr($option_key); ?>[enable_wpml]"
                                   value="1"
                                   <?php checked($settings['enable_wpml'], 1); ?>>
                            <p class="description">
                                <?php _e('Detecta el idioma desde la URL (/en/) para mostrar mensajes en inglés o español.', 'mad-suite'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Guardar Configuración General', 'mad-suite')); ?>
            </form>
        </div>

        <!-- Gestión de Alertas -->
        <div class="mads-oda-section">
            <h2><?php _e('Gestión de Alertas', 'mad-suite'); ?></h2>
            <p class="description">
                <?php _e('Configura alertas que se mostrarán en las fichas de producto cuando hay un límite de tiempo para recibir el pedido en un día específico. Todas las horas son en zona horaria de Madrid.', 'mad-suite'); ?>
            </p>

            <button type="button" class="button button-primary" id="mads-oda-add-alert">
                <span class="dashicons dashicons-plus-alt"></span>
                <?php _e('Agregar Nueva Alerta', 'mad-suite'); ?>
            </button>

            <div id="mads-oda-alerts-list" class="mads-oda-alerts-list">
                <?php if (!empty($settings['alerts'])): ?>
                    <?php foreach ($settings['alerts'] as $alert): ?>
                        <?php include __DIR__ . '/alert-item.php'; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="mads-oda-no-alerts">
                        <?php _e('No hay alertas configuradas. Haz clic en "Agregar Nueva Alerta" para comenzar.', 'mad-suite'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Fechas Excluidas -->
        <div class="mads-oda-section">
            <h2><?php _e('Fechas Excluidas', 'mad-suite'); ?></h2>
            <p class="description">
                <?php _e('Agrega fechas específicas en las que NO se mostrarán las alertas (ej: festivos, cierres especiales).', 'mad-suite'); ?>
            </p>

            <div id="mads-oda-excluded-dates">
                <input type="date" id="mads-oda-new-excluded-date" class="regular-text">
                <button type="button" class="button" id="mads-oda-add-excluded-date">
                    <?php _e('Agregar Fecha', 'mad-suite'); ?>
                </button>

                <div id="mads-oda-excluded-dates-list" class="mads-oda-excluded-dates-list">
                    <?php if (!empty($settings['excluded_dates'])): ?>
                        <?php foreach ($settings['excluded_dates'] as $date): ?>
                            <span class="mads-oda-excluded-date-tag" data-date="<?php echo esc_attr($date); ?>">
                                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($date))); ?>
                                <button type="button" class="mads-oda-remove-excluded-date" data-date="<?php echo esc_attr($date); ?>">
                                    <span class="dashicons dashicons-no-alt"></span>
                                </button>
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <button type="button" class="button button-primary" id="mads-oda-save-excluded-dates" style="margin-top: 15px;">
                <?php _e('Guardar Fechas Excluidas', 'mad-suite'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Template para nueva alerta -->
<script type="text/template" id="mads-oda-alert-template">
    <div class="mads-oda-alert-item" data-alert-id="">
        <div class="mads-oda-alert-header">
            <h3 class="mads-oda-alert-title">
                <span class="mads-oda-alert-name"><?php _e('Nueva Alerta', 'mad-suite'); ?></span>
                <span class="mads-oda-alert-status mads-oda-status-enabled">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php _e('Activa', 'mad-suite'); ?>
                </span>
            </h3>
            <div class="mads-oda-alert-actions">
                <button type="button" class="button button-small mads-oda-toggle-alert" title="<?php esc_attr_e('Activar/Desactivar', 'mad-suite'); ?>">
                    <span class="dashicons dashicons-controls-pause"></span>
                </button>
                <button type="button" class="button button-small mads-oda-edit-alert" title="<?php esc_attr_e('Editar', 'mad-suite'); ?>">
                    <span class="dashicons dashicons-edit"></span>
                </button>
                <button type="button" class="button button-small button-link-delete mads-oda-delete-alert" title="<?php esc_attr_e('Eliminar', 'mad-suite'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
        </div>

        <div class="mads-oda-alert-content">
            <div class="mads-oda-alert-form">
                <table class="form-table">
                    <tr>
                        <th><label><?php _e('Nombre de la Alerta', 'mad-suite'); ?> *</label></th>
                        <td>
                            <input type="text" class="regular-text mads-oda-alert-field" name="name" placeholder="<?php esc_attr_e('Ej: Entrega Martes', 'mad-suite'); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Días de la Semana', 'mad-suite'); ?> *</label></th>
                        <td class="mads-oda-days-checkboxes">
                            <label><input type="checkbox" name="days" value="1"> <?php _e('Lun', 'mad-suite'); ?></label>
                            <label><input type="checkbox" name="days" value="2"> <?php _e('Mar', 'mad-suite'); ?></label>
                            <label><input type="checkbox" name="days" value="3"> <?php _e('Mié', 'mad-suite'); ?></label>
                            <label><input type="checkbox" name="days" value="4"> <?php _e('Jue', 'mad-suite'); ?></label>
                            <label><input type="checkbox" name="days" value="5"> <?php _e('Vie', 'mad-suite'); ?></label>
                            <label><input type="checkbox" name="days" value="6"> <?php _e('Sáb', 'mad-suite'); ?></label>
                            <label><input type="checkbox" name="days" value="7"> <?php _e('Dom', 'mad-suite'); ?></label>
                            <p class="description"><?php _e('Selecciona los días en los que esta alerta estará activa.', 'mad-suite'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Hora Límite (Madrid)', 'mad-suite'); ?> *</label></th>
                        <td>
                            <input type="time" class="regular-text mads-oda-alert-field" name="deadline_time" required>
                            <p class="description"><?php _e('Hora límite hasta la que se mostrará la alerta.', 'mad-suite'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Días de Diferencia para Entrega', 'mad-suite'); ?></label></th>
                        <td>
                            <input type="number" min="0" max="7" class="small-text mads-oda-alert-field" name="delivery_day_offset" value="1">
                            <p class="description"><?php _e('Número de días después del pedido para la entrega (por defecto 1 = mañana).', 'mad-suite'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Mensaje (Español)', 'mad-suite'); ?> *</label></th>
                        <td>
                            <textarea class="large-text mads-oda-alert-field" name="message_es" rows="3" placeholder="<?php esc_attr_e('Ej: ¡Pide antes de las {time} para recibir tu pedido {delivery_date}!', 'mad-suite'); ?>" required></textarea>
                            <p class="description">
                                <?php _e('Variables disponibles: {time} (hora límite), {countdown} (contador regresivo), {delivery_date} (fecha de entrega).', 'mad-suite'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Mensaje (Inglés)', 'mad-suite'); ?></label></th>
                        <td>
                            <textarea class="large-text mads-oda-alert-field" name="message_en" rows="3" placeholder="<?php esc_attr_e('Ex: Order before {time} to receive your order on {delivery_date}!', 'mad-suite'); ?>"></textarea>
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
                    <strong><?php _e('Días:', 'mad-suite'); ?></strong> <span class="mads-oda-info-days"></span>
                </div>
                <div class="mads-oda-alert-info">
                    <strong><?php _e('Hora límite:', 'mad-suite'); ?></strong> <span class="mads-oda-info-time"></span>
                </div>
                <div class="mads-oda-alert-info">
                    <strong><?php _e('Mensaje:', 'mad-suite'); ?></strong> <span class="mads-oda-info-message"></span>
                </div>
            </div>
        </div>
    </div>
</script>
