<?php
/**
 * Tab: Horarios
 *
 * @var array  $settings
 * @var string $option_key
 */

if (!defined('ABSPATH')) exit;

$days = [
    'monday' => __('Lunes', 'mad-suite'),
    'tuesday' => __('Martes', 'mad-suite'),
    'wednesday' => __('Miércoles', 'mad-suite'),
    'thursday' => __('Jueves', 'mad-suite'),
    'friday' => __('Viernes', 'mad-suite'),
    'saturday' => __('Sábado', 'mad-suite'),
    'sunday' => __('Domingo', 'mad-suite'),
];

// Obtener zonas horarias disponibles
$timezones = timezone_identifiers_list();
$timezone_options = [];
foreach ($timezones as $tz) {
    $timezone_options[$tz] = str_replace('_', ' ', $tz);
}
?>

<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="enable_schedule">
                    <?php _e('Activar horario', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <!-- Hidden field para detectar cuando el checkbox no está marcado -->
                <input type="hidden" name="<?php echo esc_attr($option_key); ?>[enable_schedule]" value="0">
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr($option_key); ?>[enable_schedule]"
                           id="enable_schedule"
                           value="1"
                           <?php checked($settings['enable_schedule'], 1); ?>>
                    <?php _e('Usar horario específico para la protección', 'mad-suite'); ?>
                </label>
                <p class="description">
                    <?php _e('Si está activado, la protección solo estará activa durante el horario configurado. Fuera de este horario, el sitio estará accesible sin contraseña.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <tr class="schedule-field">
            <th scope="row">
                <label for="schedule_timezone">
                    <?php _e('Zona horaria', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <select name="<?php echo esc_attr($option_key); ?>[schedule_timezone]"
                        id="schedule_timezone"
                        class="regular-text">
                    <?php foreach ($timezone_options as $tz_value => $tz_label): ?>
                        <option value="<?php echo esc_attr($tz_value); ?>"
                                <?php selected($settings['schedule_timezone'], $tz_value); ?>>
                            <?php echo esc_html($tz_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php _e('Zona horaria a la que se aplicará el horario de protección.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <tr class="schedule-field">
            <th scope="row">
                <?php _e('Tipo de horario', 'mad-suite'); ?>
            </th>
            <td>
                <label style="display: block; margin-bottom: 10px;">
                    <input type="radio"
                           name="<?php echo esc_attr($option_key); ?>[schedule_type]"
                           value="recurring"
                           <?php checked($settings['schedule_type'], 'recurring'); ?>>
                    <?php _e('Horario recurrente (días de la semana)', 'mad-suite'); ?>
                </label>
                <label style="display: block;">
                    <input type="radio"
                           name="<?php echo esc_attr($option_key); ?>[schedule_type]"
                           value="specific"
                           <?php checked($settings['schedule_type'], 'specific'); ?>>
                    <?php _e('Horario específico (fechas concretas)', 'mad-suite'); ?>
                </label>
            </td>
        </tr>

        <!-- Horario recurrente -->
        <tr class="schedule-field schedule-recurring">
            <th scope="row">
                <?php _e('Días de la semana', 'mad-suite'); ?>
            </th>
            <td>
                <!-- Hidden field para detectar cuando el array está vacío -->
                <input type="hidden" name="<?php echo esc_attr($option_key); ?>[_schedule_days_present]" value="1">
                <fieldset>
                    <legend class="screen-reader-text">
                        <span><?php _e('Días de la semana', 'mad-suite'); ?></span>
                    </legend>
                    <?php foreach ($days as $day_key => $day_label): ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox"
                                   name="<?php echo esc_attr($option_key); ?>[schedule_days][]"
                                   value="<?php echo esc_attr($day_key); ?>"
                                   <?php checked(in_array($day_key, $settings['schedule_days'])); ?>>
                            <?php echo esc_html($day_label); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        <?php _e('Selecciona los días en los que la protección estará activa.', 'mad-suite'); ?>
                    </p>
                </fieldset>
            </td>
        </tr>

        <!-- Horario específico -->
        <tr class="schedule-field schedule-specific">
            <th scope="row">
                <label for="schedule_date_start">
                    <?php _e('Fecha de inicio', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <input type="date"
                       name="<?php echo esc_attr($option_key); ?>[schedule_date_start]"
                       id="schedule_date_start"
                       value="<?php echo esc_attr($settings['schedule_date_start']); ?>"
                       class="regular-text">
                <p class="description">
                    <?php _e('Fecha a partir de la cual se activará la protección.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <tr class="schedule-field schedule-specific">
            <th scope="row">
                <label for="schedule_date_end">
                    <?php _e('Fecha de fin', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <input type="date"
                       name="<?php echo esc_attr($option_key); ?>[schedule_date_end]"
                       id="schedule_date_end"
                       value="<?php echo esc_attr($settings['schedule_date_end']); ?>"
                       class="regular-text">
                <p class="description">
                    <?php _e('Fecha hasta la cual estará activa la protección.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <!-- Horas (común para ambos tipos) -->
        <tr class="schedule-field">
            <th scope="row">
                <label for="schedule_start">
                    <?php _e('Hora de inicio', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <input type="time"
                       name="<?php echo esc_attr($option_key); ?>[schedule_start]"
                       id="schedule_start"
                       value="<?php echo esc_attr($settings['schedule_start']); ?>"
                       class="regular-text">
                <p class="description">
                    <?php _e('Hora a partir de la cual se activará la protección.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <tr class="schedule-field">
            <th scope="row">
                <label for="schedule_end">
                    <?php _e('Hora de fin', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <input type="time"
                       name="<?php echo esc_attr($option_key); ?>[schedule_end]"
                       id="schedule_end"
                       value="<?php echo esc_attr($settings['schedule_end']); ?>"
                       class="regular-text">
                <p class="description">
                    <?php _e('Hora hasta la cual estará activa la protección.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>
    </tbody>
</table>

<div class="mads-schedule-info">
    <h4><?php _e('📅 Ejemplos de uso', 'mad-suite'); ?></h4>

    <div style="margin-bottom: 20px;">
        <strong><?php _e('Horario recurrente:', 'mad-suite'); ?></strong>
        <p><?php _e('Si configuras: Lunes a Viernes de 9:00 AM a 6:00 PM', 'mad-suite'); ?></p>
        <p>
            <strong><?php _e('La protección estará activa:', 'mad-suite'); ?></strong>
            <?php _e('De lunes a viernes de 9:00 AM a 6:00 PM', 'mad-suite'); ?>
        </p>
        <p>
            <strong><?php _e('El sitio estará accesible sin contraseña:', 'mad-suite'); ?></strong>
            <?php _e('Los fines de semana y fuera del horario (antes de las 9:00 AM y después de las 6:00 PM)', 'mad-suite'); ?>
        </p>
    </div>

    <div>
        <strong><?php _e('Horario específico:', 'mad-suite'); ?></strong>
        <p><?php _e('Si configuras: Del 2025-01-01 al 2025-01-15 de 10:00 AM a 8:00 PM', 'mad-suite'); ?></p>
        <p>
            <strong><?php _e('La protección estará activa:', 'mad-suite'); ?></strong>
            <?php _e('Desde el 1 de enero de 2025 a las 10:00 AM hasta el 15 de enero de 2025 a las 8:00 PM', 'mad-suite'); ?>
        </p>
        <p>
            <strong><?php _e('El sitio estará accesible sin contraseña:', 'mad-suite'); ?></strong>
            <?php _e('Antes del 1 de enero y después del 15 de enero', 'mad-suite'); ?>
        </p>
    </div>
</div>

<script>
    jQuery(document).ready(function($) {
        // Toggle de campos de horario
        function toggleScheduleFields() {
            if ($('#enable_schedule').is(':checked')) {
                $('.schedule-field').show();
                updateScheduleType();
            } else {
                $('.schedule-field').hide();
            }
        }

        // Toggle entre horario recurrente y específico
        function updateScheduleType() {
            var type = $('input[name="<?php echo esc_js($option_key); ?>[schedule_type]"]:checked').val();

            if (type === 'recurring') {
                $('.schedule-recurring').show();
                $('.schedule-specific').hide();
            } else {
                $('.schedule-recurring').hide();
                $('.schedule-specific').show();
            }
        }

        // Inicializar
        toggleScheduleFields();

        // Eventos
        $('#enable_schedule').on('change', toggleScheduleFields);
        $('input[name="<?php echo esc_js($option_key); ?>[schedule_type]"]').on('change', updateScheduleType);
    });
</script>

<style>
    .mads-schedule-info {
        background: #f7fcf7;
        border-left: 4px solid #00a32a;
        padding: 15px 20px;
        margin-top: 20px;
        border-radius: 4px;
    }

    .mads-schedule-info h4 {
        margin-top: 0;
        margin-bottom: 15px;
    }

    .mads-schedule-info p {
        margin: 5px 0;
        line-height: 1.6;
    }
</style>
