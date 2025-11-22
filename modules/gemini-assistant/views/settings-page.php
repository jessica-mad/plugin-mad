<?php
/**
 * Vista: Página de Configuración
 *
 * Configuración del módulo Gemini Assistant.
 *
 * @var array $settings Configuración actual
 * @var object $module Instancia del módulo
 */

if (!defined('ABSPATH')) exit;

$option_key = MAD_Suite_Core::option_key($module->slug());
$models = MAD_Gemini_API::get_available_models();
?>

<div class="mad-gemini-settings">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="mads_gemini_save_settings">
        <?php wp_nonce_field('mads_gemini_save_settings', 'mads_gemini_nonce'); ?>

        <table class="form-table">
            <tbody>
                <!-- API Key -->
                <tr>
                    <th scope="row">
                        <label for="api_key"><?php _e('API Key de Gemini', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="password"
                               id="api_key"
                               name="<?php echo esc_attr($option_key); ?>[api_key]"
                               class="regular-text"
                               value="<?php echo !empty($settings['api_key']) ? '••••••••••••••••' : ''; ?>"
                               placeholder="<?php esc_attr_e('Ingresa tu API Key', 'mad-suite'); ?>">
                        <p class="description">
                            <?php _e('Obtén tu API Key desde', 'mad-suite'); ?>
                            <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>
                        </p>
                        <p>
                            <button type="button" id="test-connection-btn" class="button" <?php echo empty($settings['api_key']) ? 'disabled' : ''; ?>>
                                <?php _e('Probar Conexión', 'mad-suite'); ?>
                            </button>
                            <span id="test-result"></span>
                        </p>
                    </td>
                </tr>

                <!-- Modelo -->
                <tr>
                    <th scope="row">
                        <label for="model"><?php _e('Modelo', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <select id="model" name="<?php echo esc_attr($option_key); ?>[model]">
                            <?php foreach ($models as $model_key => $model_label): ?>
                                <option value="<?php echo esc_attr($model_key); ?>"
                                        <?php selected($settings['model'], $model_key); ?>>
                                    <?php echo esc_html($model_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php _e('Gemini 2.5 Flash es el modelo recomendado para la mayoría de casos.', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Temperature -->
                <tr>
                    <th scope="row">
                        <label for="temperature"><?php _e('Temperatura', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="temperature"
                               name="<?php echo esc_attr($option_key); ?>[temperature]"
                               value="<?php echo esc_attr($settings['temperature']); ?>"
                               min="0"
                               max="2"
                               step="0.1"
                               class="small-text">
                        <span id="temperature-value"><?php echo esc_html($settings['temperature']); ?></span>
                        <p class="description">
                            <?php _e('Controla la creatividad (0 = preciso, 2 = muy creativo). Recomendado: 0.7', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Max Tokens -->
                <tr>
                    <th scope="row">
                        <label for="max_tokens"><?php _e('Tokens Máximos', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="max_tokens"
                               name="<?php echo esc_attr($option_key); ?>[max_tokens]"
                               value="<?php echo esc_attr($settings['max_tokens']); ?>"
                               min="256"
                               max="32768"
                               step="256"
                               class="small-text">
                        <p class="description">
                            <?php _e('Longitud máxima de las respuestas. Recomendado: 8192', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Top P -->
                <tr>
                    <th scope="row">
                        <label for="top_p"><?php _e('Top P', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="top_p"
                               name="<?php echo esc_attr($option_key); ?>[top_p]"
                               value="<?php echo esc_attr($settings['top_p']); ?>"
                               min="0"
                               max="1"
                               step="0.05"
                               class="small-text">
                        <p class="description">
                            <?php _e('Controla la diversidad de las respuestas. Recomendado: 0.95', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Top K -->
                <tr>
                    <th scope="row">
                        <label for="top_k"><?php _e('Top K', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="top_k"
                               name="<?php echo esc_attr($option_key); ?>[top_k]"
                               value="<?php echo esc_attr($settings['top_k']); ?>"
                               min="1"
                               max="100"
                               step="1"
                               class="small-text">
                        <p class="description">
                            <?php _e('Número de tokens considerados en cada paso. Recomendado: 40', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <hr>

        <h2><?php _e('Información y Recursos', 'mad-suite'); ?></h2>

        <div class="mad-gemini-info-cards">
            <div class="info-card">
                <h3>
                    <span class="dashicons dashicons-info"></span>
                    <?php _e('Acerca de Gemini', 'mad-suite'); ?>
                </h3>
                <p>
                    <?php _e('Gemini es el modelo de IA más capaz de Google, diseñado para entender y generar contenido multimodal (texto, imágenes, código, etc.).', 'mad-suite'); ?>
                </p>
                <p>
                    <a href="https://ai.google.dev/gemini-api/docs" target="_blank" class="button">
                        <?php _e('Ver Documentación', 'mad-suite'); ?>
                    </a>
                </p>
            </div>

            <div class="info-card">
                <h3>
                    <span class="dashicons dashicons-media-document"></span>
                    <?php _e('Formatos Soportados', 'mad-suite'); ?>
                </h3>
                <ul>
                    <li><strong><?php _e('Imágenes:', 'mad-suite'); ?></strong> JPG, PNG, WebP, GIF, HEIC</li>
                    <li><strong><?php _e('Documentos:', 'mad-suite'); ?></strong> PDF, TXT</li>
                    <li><strong><?php _e('Tamaño máximo:', 'mad-suite'); ?></strong> 20MB por archivo</li>
                </ul>
            </div>

            <div class="info-card">
                <h3>
                    <span class="dashicons dashicons-privacy"></span>
                    <?php _e('Privacidad y Seguridad', 'mad-suite'); ?>
                </h3>
                <p>
                    <?php _e('Tu API Key se almacena de forma encriptada en la base de datos. Las conversaciones se guardan localmente en tu WordPress.', 'mad-suite'); ?>
                </p>
                <p class="description">
                    <?php _e('Revisa los términos de servicio de Google AI para conocer cómo se procesan tus datos.', 'mad-suite'); ?>
                </p>
            </div>
        </div>

        <p class="submit">
            <input type="submit" class="button button-primary" value="<?php esc_attr_e('Guardar Cambios', 'mad-suite'); ?>">
        </p>
    </form>
</div>

<script>
// Actualizar valor de temperature en tiempo real
jQuery(document).ready(function($) {
    $('#temperature').on('input', function() {
        $('#temperature-value').text($(this).val());
    });
});
</script>
