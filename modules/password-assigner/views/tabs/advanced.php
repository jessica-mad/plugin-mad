<?php
/**
 * Tab: Configuración Avanzada
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
    </ul>
</div>
