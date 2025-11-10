<?php
/**
 * Tab: Configuración General
 *
 * @var array  $settings
 * @var string $option_key
 */

if (!defined('ABSPATH')) exit;
?>

<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="enabled">
                    <?php _e('Activar protección', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <!-- Hidden field para detectar cuando el checkbox no está marcado -->
                <input type="hidden" name="<?php echo esc_attr($option_key); ?>[enabled]" value="0">
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr($option_key); ?>[enabled]"
                           id="enabled"
                           value="1"
                           <?php checked($settings['enabled'], 1); ?>>
                    <?php _e('Activar el sistema de protección por contraseña', 'mad-suite'); ?>
                </label>
                <p class="description">
                    <?php _e('Cuando está activado, los visitantes deberán ingresar la contraseña para acceder al sitio.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="password">
                    <?php _e('Contraseña de acceso', 'mad-suite'); ?> <span class="required">*</span>
                </label>
            </th>
            <td>
                <input type="text"
                       name="<?php echo esc_attr($option_key); ?>[password]"
                       id="password"
                       value="<?php echo esc_attr($settings['password']); ?>"
                       class="regular-text"
                       required>
                <p class="description">
                    <?php _e('Esta es la contraseña que los usuarios deberán ingresar para acceder al sitio.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="session_duration">
                    <?php _e('Duración de la sesión', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <input type="number"
                       name="<?php echo esc_attr($option_key); ?>[session_duration]"
                       id="session_duration"
                       value="<?php echo esc_attr($settings['session_duration']); ?>"
                       min="1"
                       max="168"
                       class="small-text">
                <span><?php _e('horas', 'mad-suite'); ?></span>
                <p class="description">
                    <?php _e('Tiempo que la sesión permanecerá activa después de ingresar la contraseña correcta. (1-168 horas)', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="redirect_url">
                    <?php _e('Página de login', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <?php
                wp_dropdown_pages([
                    'name' => $option_key . '[redirect_url_page]',
                    'id' => 'redirect_url_page',
                    'selected' => url_to_postid($settings['redirect_url']),
                    'show_option_none' => __('-- Selecciona una página --', 'mad-suite'),
                    'option_none_value' => '',
                ]);
                ?>
                <p class="description">
                    <?php _e('Página donde se mostrará el formulario de contraseña. Los usuarios serán redirigidos a esta página cuando intenten acceder al sitio.', 'mad-suite'); ?>
                    <br>
                    <?php _e('Recuerda agregar el shortcode <code>[password_access_form]</code> en esta página.', 'mad-suite'); ?>
                </p>

                <input type="hidden"
                       name="<?php echo esc_attr($option_key); ?>[redirect_url]"
                       id="redirect_url"
                       value="<?php echo esc_attr($settings['redirect_url']); ?>">

                <script>
                    jQuery(document).ready(function($) {
                        $('#redirect_url_page').on('change', function() {
                            var pageId = $(this).val();
                            if (pageId) {
                                // Hacer una petición AJAX para obtener el permalink
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'get_page_permalink',
                                        page_id: pageId
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            $('#redirect_url').val(response.data.permalink);
                                        }
                                    }
                                });
                            } else {
                                $('#redirect_url').val('');
                            }
                        });

                        // Trigger change al cargar para asegurar que el campo hidden tenga el valor correcto
                        $('#redirect_url_page').trigger('change');
                    });
                </script>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <?php _e('Mensajes personalizados', 'mad-suite'); ?>
            </th>
            <td>
                <!-- Hidden field para detectar cuando el checkbox no está marcado -->
                <input type="hidden" name="<?php echo esc_attr($option_key); ?>[enable_wpml]" value="0">
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr($option_key); ?>[enable_wpml]"
                           id="enable_wpml"
                           value="1"
                           <?php checked($settings['enable_wpml'], 1); ?>>
                    <?php _e('Activar soporte multiidioma (WPML)', 'mad-suite'); ?>
                </label>
                <p class="description">
                    <?php _e('Detecta automáticamente el idioma según la URL (/en/ para inglés).', 'mad-suite'); ?>
                </p>

                <div style="margin-top: 15px;">
                    <label for="custom_message" style="display: block; margin-bottom: 5px;">
                        <strong><?php _e('Mensaje en español:', 'mad-suite'); ?></strong>
                    </label>
                    <textarea name="<?php echo esc_attr($option_key); ?>[custom_message]"
                              id="custom_message"
                              rows="2"
                              class="large-text"><?php echo esc_textarea($settings['custom_message']); ?></textarea>
                </div>

                <div style="margin-top: 10px;" id="message_en_wrapper">
                    <label for="custom_message_en" style="display: block; margin-bottom: 5px;">
                        <strong><?php _e('Mensaje en inglés:', 'mad-suite'); ?></strong>
                    </label>
                    <textarea name="<?php echo esc_attr($option_key); ?>[custom_message_en]"
                              id="custom_message_en"
                              rows="2"
                              class="large-text"><?php echo esc_textarea($settings['custom_message_en']); ?></textarea>
                </div>
            </td>
        </tr>
    </tbody>
</table>

<script>
    jQuery(document).ready(function($) {
        // Toggle de mensajes en inglés
        function toggleEnglishMessage() {
            if ($('#enable_wpml').is(':checked')) {
                $('#message_en_wrapper').show();
            } else {
                $('#message_en_wrapper').hide();
            }
        }

        toggleEnglishMessage();
        $('#enable_wpml').on('change', toggleEnglishMessage);
    });
</script>
