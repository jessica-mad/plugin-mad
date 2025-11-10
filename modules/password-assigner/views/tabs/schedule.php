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
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr($option_key); ?>[enable_schedule]"
                           id="enable_schedule"
                           value="1"
                           <?php checked($settings['enable_schedule'], 1); ?>>
                    <?php _e('Usar horario específico para la protección', 'mad-suite'); ?>
                </label>
                <p class="description">
                    <?php _e('Si está activado, la protección solo estará activa durante los días y horas configurados. Fuera de este horario, el sitio estará accesible sin contraseña.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <?php _e('Días de la semana', 'mad-suite'); ?>
            </th>
            <td>
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

        <tr>
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

        <tr>
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
    <h4><?php _e('📅 Ejemplo de uso', 'mad-suite'); ?></h4>
    <p>
        <?php _e('Si configuras:', 'mad-suite'); ?>
    </p>
    <ul>
        <li><?php _e('Días: Lunes a Viernes', 'mad-suite'); ?></li>
        <li><?php _e('Hora de inicio: 09:00', 'mad-suite'); ?></li>
        <li><?php _e('Hora de fin: 18:00', 'mad-suite'); ?></li>
    </ul>
    <p>
        <strong><?php _e('La protección estará activa:', 'mad-suite'); ?></strong>
        <?php _e('De lunes a viernes de 9:00 AM a 6:00 PM', 'mad-suite'); ?>
    </p>
    <p>
        <strong><?php _e('El sitio estará accesible sin contraseña:', 'mad-suite'); ?></strong>
        <?php _e('Los fines de semana y fuera del horario configurado (antes de las 9:00 AM y después de las 6:00 PM)', 'mad-suite'); ?>
    </p>
</div>
