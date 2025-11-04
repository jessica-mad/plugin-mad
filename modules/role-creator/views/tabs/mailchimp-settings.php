<?php
if (! defined('ABSPATH')) {
    exit;
}

/** @var string $import_action */

use MAD_Suite\Modules\RoleCreator\MailchimpIntegration;

$mailchimp = MailchimpIntegration::instance();
$settings = $mailchimp->get_settings();
$is_configured = $mailchimp->is_configured();
?>

<div class="mad-role-creator__mailchimp-settings">
    <div class="mad-role-creator__grid">
        <!-- Columna Izquierda: Configuración -->
        <div class="mad-role-creator__column">
            <div class="card">
                <h2><?php esc_html_e('Configuración de Mailchimp', 'mad-suite'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Conecta tu cuenta de Mailchimp para sincronizar automáticamente los roles de usuarios como tags en tu audiencia.', 'mad-suite'); ?>
                </p>

                <form method="post" action="<?php echo esc_url($import_action); ?>" class="mad-role-creator__form">
                    <?php wp_nonce_field('mads_role_creator_mailchimp_save', 'mads_role_creator_nonce'); ?>
                    <input type="hidden" name="action" value="mads_role_creator_mailchimp_save" />

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="mailchimp-api-key"><?php esc_html_e('API Key', 'mad-suite'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="mailchimp-api-key" name="mailchimp_api_key" class="regular-text"
                                           value="<?php echo esc_attr($settings['api_key']); ?>" required
                                           placeholder="<?php esc_attr_e('xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us1', 'mad-suite'); ?>" />
                                    <p class="description">
                                        <?php
                                        printf(
                                            esc_html__('Tu API Key de Mailchimp. %sObtén tu API Key aquí%s.', 'mad-suite'),
                                            '<a href="https://admin.mailchimp.com/account/api/" target="_blank">',
                                            '</a>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="mailchimp-audience-id"><?php esc_html_e('Audience ID', 'mad-suite'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="mailchimp-audience-id" name="mailchimp_audience_id" class="regular-text"
                                           value="<?php echo esc_attr($settings['audience_id']); ?>" required
                                           placeholder="<?php esc_attr_e('xxxxxxxxxx', 'mad-suite'); ?>" />
                                    <p class="description">
                                        <?php
                                        printf(
                                            esc_html__('El ID de tu audiencia de Mailchimp. %sCómo encontrar tu Audience ID%s.', 'mad-suite'),
                                            '<a href="https://mailchimp.com/help/find-audience-id/" target="_blank">',
                                            '</a>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="mailchimp-auto-sync"><?php esc_html_e('Sincronización Automática', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <label for="mailchimp-auto-sync">
                                        <input type="checkbox" id="mailchimp-auto-sync" name="mailchimp_auto_sync" value="1"
                                               <?php checked(isset($settings['auto_sync']) && $settings['auto_sync']); ?> />
                                        <?php esc_html_e('Sincronizar automáticamente cuando los roles cambien', 'mad-suite'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('Cuando está activado, los usuarios se sincronizan con Mailchimp automáticamente al completar un pedido o cuando se les asignan roles.', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php submit_button(__('Guardar Configuración', 'mad-suite'), 'primary'); ?>
                </form>
            </div>

            <?php if ($is_configured) : ?>
            <div class="card" style="margin-top: 20px;">
                <h3><?php esc_html_e('⚡ Acciones Rápidas', 'mad-suite'); ?></h3>

                <!-- Estado de la última prueba -->
                <div id="mailchimp-test-result" style="display: none; padding: 15px; margin: 15px 0; border-left: 4px solid #ddd; border-radius: 4px;"></div>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php
                    $test_url = wp_nonce_url(
                        admin_url('admin-post.php?action=mads_role_creator_mailchimp_test&tab=mailchimp-settings'),
                        'mads_role_creator_mailchimp_test',
                        'mads_role_creator_nonce'
                    );
                    $sync_all_url = wp_nonce_url(
                        admin_url('admin-post.php?action=mads_role_creator_mailchimp_sync_all&tab=mailchimp-settings'),
                        'mads_role_creator_mailchimp_sync_all',
                        'mads_role_creator_nonce'
                    );
                    ?>
                    <a href="<?php echo esc_url($test_url); ?>" class="button button-secondary" id="test-connection-btn">
                        <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span>
                        <?php esc_html_e('Probar Conexión', 'mad-suite'); ?>
                    </a>
                    <a href="<?php echo esc_url($sync_all_url); ?>" class="button button-secondary"
                       onclick="return confirm('<?php esc_attr_e('¿Sincronizar todos los usuarios con Mailchimp? Esta operación puede tomar algunos minutos.', 'mad-suite'); ?>');">
                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                        <?php esc_html_e('Sincronizar Todos los Usuarios', 'mad-suite'); ?>
                    </a>
                </div>

                <p style="margin-top: 15px; color: #666; font-size: 12px;">
                    <strong><?php esc_html_e('💡 Consejo:', 'mad-suite'); ?></strong>
                    <?php esc_html_e('Después de configurar tu API Key y Audience ID, haz clic en "Probar Conexión" para verificar que todo funciona correctamente. Luego revisa los Logs para ver los detalles.', 'mad-suite'); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Columna Derecha: Información -->
        <div class="mad-role-creator__column">
            <div class="card">
                <h2><?php esc_html_e('ℹ️ Cómo Funciona la Integración', 'mad-suite'); ?></h2>
                <ol style="line-height: 1.8;">
                    <li><?php esc_html_e('Los usuarios se crean en Mailchimp con estado "transactional" (sin suscripción automática).', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Los roles de WordPress se sincronizan como tags en Mailchimp con el prefijo "role_".', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Ejemplo: Un usuario con el rol "vip1" tendrá el tag "role_vip1" en Mailchimp.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Cuando un usuario cambia de rol, los tags antiguos se eliminan y los nuevos se agregan.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Puedes usar estos tags en Mailchimp para crear segmentos y automatizaciones personalizadas.', 'mad-suite'); ?></li>
                </ol>
            </div>

            <div class="card" style="margin-top: 20px; background: #fff8e5; border-left: 4px solid #ffa500;">
                <h3><?php esc_html_e('📋 Estado de la Conexión', 'mad-suite'); ?></h3>
                <?php if ($is_configured) : ?>
                    <p style="margin: 0;">
                        <span class="dashicons dashicons-yes-alt" style="color: #46b450; vertical-align: middle;"></span>
                        <strong><?php esc_html_e('Configuración completa', 'mad-suite'); ?></strong>
                    </p>
                    <p style="margin: 10px 0 0 0; color: #666;">
                        <?php esc_html_e('Mailchimp está configurado y listo para sincronizar.', 'mad-suite'); ?>
                    </p>
                <?php else : ?>
                    <p style="margin: 0;">
                        <span class="dashicons dashicons-warning" style="color: #ffa500; vertical-align: middle;"></span>
                        <strong><?php esc_html_e('Configuración pendiente', 'mad-suite'); ?></strong>
                    </p>
                    <p style="margin: 10px 0 0 0; color: #666;">
                        <?php esc_html_e('Completa el formulario con tu API Key y Audience ID para comenzar.', 'mad-suite'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3><?php esc_html_e('🎯 Casos de Uso', 'mad-suite'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><strong><?php esc_html_e('Emails personalizados por nivel VIP:', 'mad-suite'); ?></strong><br>
                        <?php esc_html_e('Crea segmentos en Mailchimp usando los tags "role_vip1", "role_vip2", etc. para enviar ofertas exclusivas.', 'mad-suite'); ?>
                    </li>
                    <li><strong><?php esc_html_e('Automatizaciones de bienvenida:', 'mad-suite'); ?></strong><br>
                        <?php esc_html_e('Configura flujos automáticos que se activen cuando un usuario obtenga el tag "role_customer" o "role_vip1".', 'mad-suite'); ?>
                    </li>
                    <li><strong><?php esc_html_e('Segmentación avanzada:', 'mad-suite'); ?></strong><br>
                        <?php esc_html_e('Combina los tags de roles con otros criterios en Mailchimp para crear audiencias ultra-específicas.', 'mad-suite'); ?>
                    </li>
                    <li><strong><?php esc_html_e('Reportes y análisis:', 'mad-suite'); ?></strong><br>
                        <?php esc_html_e('Analiza el comportamiento de tus diferentes segmentos de roles en las campañas de email marketing.', 'mad-suite'); ?>
                    </li>
                </ul>
            </div>

            <div class="card" style="margin-top: 20px; background: #f0f9ff; border-left: 4px solid #2271b1;">
                <h3><?php esc_html_e('💡 Consejos', 'mad-suite'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php esc_html_e('Usa la sincronización automática para mantener Mailchimp siempre actualizado.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('El estado "transactional" permite gestionar tags sin que los usuarios aparezcan como suscritos.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Prueba la conexión después de configurar para asegurar que todo funciona correctamente.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Si tienes muchos usuarios, la sincronización inicial puede tomar varios minutos.', 'mad-suite'); ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>
