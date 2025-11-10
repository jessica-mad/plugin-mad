<?php
/**
 * Tab: Configuración Avanzada
 *
 * @var array  $settings
 * @var string $option_key
 */

if (!defined('ABSPATH')) exit;

// Obtener todas las páginas para el selector
$pages = get_pages(['sort_column' => 'post_title']);
?>

<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="exclude_admin">
                    <?php _e('Excluir administradores', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <label>
                    <input type="checkbox"
                           name="<?php echo esc_attr($option_key); ?>[exclude_admin]"
                           id="exclude_admin"
                           value="1"
                           <?php checked($settings['exclude_admin'], 1); ?>>
                    <?php _e('Permitir acceso sin contraseña a usuarios con capacidad de gestión (administradores)', 'mad-suite'); ?>
                </label>
                <p class="description">
                    <?php _e('Si está activado, los administradores del sitio podrán acceder sin ingresar la contraseña.', 'mad-suite'); ?>
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <?php _e('IPs en whitelist', 'mad-suite'); ?>
            </th>
            <td>
                <label style="margin-bottom: 10px; display: block;">
                    <input type="checkbox"
                           name="<?php echo esc_attr($option_key); ?>[enable_whitelist]"
                           id="enable_whitelist"
                           value="1"
                           <?php checked($settings['enable_whitelist'], 1); ?>>
                    <?php _e('Activar whitelist de IPs para pruebas', 'mad-suite'); ?>
                </label>
                <p class="description">
                    <?php _e('Las IPs en whitelist podrán acceder al sitio sin ingresar contraseña. Útil para realizar pruebas.', 'mad-suite'); ?>
                </p>

                <div id="whitelist_wrapper" style="margin-top: 15px;">
                    <textarea name="<?php echo esc_attr($option_key); ?>[whitelist_ips]"
                              id="whitelist_ips"
                              rows="5"
                              class="large-text code"><?php echo esc_textarea($settings['whitelist_ips']); ?></textarea>
                    <p class="description">
                        <?php _e('Lista de IPs permitidas (una por línea). Soporta IPs individuales y rangos CIDR.', 'mad-suite'); ?>
                        <br>
                        <strong><?php _e('Ejemplos:', 'mad-suite'); ?></strong>
                        <br>
                        <code>192.168.1.100</code> - <?php _e('IP individual', 'mad-suite'); ?>
                        <br>
                        <code>192.168.1.0/24</code> - <?php _e('Rango de red (192.168.1.1 a 192.168.1.254)', 'mad-suite'); ?>
                        <br>
                        <code>10.0.0.0/8</code> - <?php _e('Red completa', 'mad-suite'); ?>
                    </p>

                    <div style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                        <strong><?php _e('Tu IP actual:', 'mad-suite'); ?></strong>
                        <code><?php echo esc_html($_SERVER['REMOTE_ADDR'] ?? 'No disponible'); ?></code>
                        <button type="button" id="add_current_ip" class="button button-secondary" style="margin-left: 10px;">
                            <?php _e('Agregar mi IP', 'mad-suite'); ?>
                        </button>
                    </div>
                </div>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <?php _e('Páginas excluidas', 'mad-suite'); ?>
            </th>
            <td>
                <fieldset>
                    <legend class="screen-reader-text">
                        <span><?php _e('Páginas excluidas', 'mad-suite'); ?></span>
                    </legend>

                    <?php if (!empty($pages)): ?>
                        <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;">
                            <?php foreach ($pages as $page): ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr($option_key); ?>[exclude_pages][]"
                                           value="<?php echo esc_attr($page->ID); ?>"
                                           <?php checked(in_array($page->ID, $settings['exclude_pages'])); ?>>
                                    <?php echo esc_html($page->post_title); ?>
                                    <small style="color: #666;">(ID: <?php echo esc_html($page->ID); ?>)</small>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p><?php _e('No se encontraron páginas.', 'mad-suite'); ?></p>
                    <?php endif; ?>

                    <p class="description" style="margin-top: 10px;">
                        <?php _e('Selecciona las páginas que no estarán protegidas por contraseña.', 'mad-suite'); ?>
                    </p>
                </fieldset>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="exclude_urls">
                    <?php _e('URLs excluidas', 'mad-suite'); ?>
                </label>
            </th>
            <td>
                <textarea name="<?php echo esc_attr($option_key); ?>[exclude_urls]"
                          id="exclude_urls"
                          rows="5"
                          class="large-text code"><?php echo esc_textarea($settings['exclude_urls']); ?></textarea>
                <p class="description">
                    <?php _e('Lista de URLs que no estarán protegidas (una por línea). Puedes usar rutas parciales.', 'mad-suite'); ?>
                    <br>
                    <strong><?php _e('Ejemplos:', 'mad-suite'); ?></strong>
                    <br>
                    <code>/contacto</code> - <?php _e('Excluir la página de contacto', 'mad-suite'); ?>
                    <br>
                    <code>/wp-json/</code> - <?php _e('Excluir todas las rutas de la API REST', 'mad-suite'); ?>
                    <br>
                    <code>/feed</code> - <?php _e('Excluir el feed RSS', 'mad-suite'); ?>
                </p>
            </td>
        </tr>
    </tbody>
</table>

<div class="mads-advanced-info">
    <h4><?php _e('⚙️ Información técnica', 'mad-suite'); ?></h4>
    <ul>
        <li>
            <strong><?php _e('Sesiones PHP:', 'mad-suite'); ?></strong>
            <?php _e('Este módulo utiliza sesiones de PHP para recordar que el usuario ya ingresó la contraseña. La sesión expirará después del tiempo configurado en "Duración de la sesión".', 'mad-suite'); ?>
        </li>
        <li>
            <strong><?php _e('Páginas del administrador:', 'mad-suite'); ?></strong>
            <?php _e('Las páginas del panel de administración (/wp-admin/) nunca están protegidas por este sistema.', 'mad-suite'); ?>
        </li>
        <li>
            <strong><?php _e('Detección de IP:', 'mad-suite'); ?></strong>
            <?php _e('El sistema detecta la IP real del visitante incluso detrás de proxies (Cloudflare, load balancers, etc.).', 'mad-suite'); ?>
        </li>
        <li>
            <strong><?php _e('Cerrar sesión:', 'mad-suite'); ?></strong>
            <?php _e('Los usuarios pueden cerrar su sesión agregando <code>?mads_password_logout=1</code> a cualquier URL del sitio.', 'mad-suite'); ?>
        </li>
        <li>
            <strong><?php _e('Prioridad:', 'mad-suite'); ?></strong>
            <?php _e('Este módulo se ejecuta en el hook <code>template_redirect</code> con prioridad 1, antes que la mayoría de plugins.', 'mad-suite'); ?>
        </li>
    </ul>
</div>

<div class="mads-warning-box">
    <h4><?php _e('⚠️ Advertencias importantes', 'mad-suite'); ?></h4>
    <ul>
        <li><?php _e('Este sistema no es una solución de seguridad robusta. Es solo una protección básica por contraseña.', 'mad-suite'); ?></li>
        <li><?php _e('No uses este sistema para proteger información sensible o confidencial.', 'mad-suite'); ?></li>
        <li><?php _e('Las búsquedas de motores de búsqueda (Google, Bing) no podrán indexar tu sitio mientras la protección esté activa.', 'mad-suite'); ?></li>
        <li><?php _e('Asegúrate de configurar correctamente la página de login para evitar bucles de redirección infinitos.', 'mad-suite'); ?></li>
        <li><?php _e('Las IPs en whitelist deben usarse solo para pruebas. No son un método de autenticación seguro.', 'mad-suite'); ?></li>
    </ul>
</div>

<script>
    jQuery(document).ready(function($) {
        // Toggle de whitelist
        function toggleWhitelist() {
            if ($('#enable_whitelist').is(':checked')) {
                $('#whitelist_wrapper').show();
            } else {
                $('#whitelist_wrapper').hide();
            }
        }

        toggleWhitelist();
        $('#enable_whitelist').on('change', toggleWhitelist);

        // Agregar IP actual
        $('#add_current_ip').on('click', function() {
            var currentIp = '<?php echo esc_js($_SERVER['REMOTE_ADDR'] ?? ''); ?>';
            var currentValue = $('#whitelist_ips').val();

            // Verificar si la IP ya está en la lista
            if (currentValue.indexOf(currentIp) === -1) {
                if (currentValue) {
                    $('#whitelist_ips').val(currentValue + '\n' + currentIp);
                } else {
                    $('#whitelist_ips').val(currentIp);
                }
                alert('<?php _e('IP agregada correctamente.', 'mad-suite'); ?>');
            } else {
                alert('<?php _e('Esta IP ya está en la lista.', 'mad-suite'); ?>');
            }
        });
    });
</script>

<style>
    .mads-advanced-info {
        background: #fff;
        border-left: 4px solid #2271b1;
        padding: 15px 20px;
        margin-top: 20px;
        border-radius: 4px;
    }

    .mads-advanced-info h4 {
        margin-top: 0;
        margin-bottom: 10px;
    }

    .mads-advanced-info ul {
        margin: 10px 0;
        padding-left: 25px;
    }

    .mads-advanced-info ul li {
        margin-bottom: 8px;
        line-height: 1.6;
    }

    .mads-warning-box {
        background: #fff8f8;
        border-left: 4px solid #d63638;
        padding: 15px 20px;
        margin-top: 20px;
        border-radius: 4px;
    }

    .mads-warning-box h4 {
        margin-top: 0;
        margin-bottom: 10px;
        color: #d63638;
    }

    .mads-warning-box ul {
        margin: 10px 0;
        padding-left: 25px;
    }

    .mads-warning-box ul li {
        margin-bottom: 8px;
        line-height: 1.6;
    }
</style>
