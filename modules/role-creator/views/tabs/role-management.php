<?php
if (! defined('ABSPATH')) {
    exit;
}

/** @var array $roles */
/** @var string $import_action */

use MAD_Suite\Modules\RoleCreator\RoleManager;

$role_manager = RoleManager::instance();
?>

<div class="mad-role-creator__role-management">
    <div class="mad-role-creator__grid">
        <!-- Columna Izquierda: Roles Existentes -->
        <div class="mad-role-creator__column">
            <div class="card">
                <h2><?php esc_html_e('Roles Disponibles', 'mad-suite'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Verifica los roles existentes en tu sitio. Los roles protegidos del sistema no pueden eliminarse.', 'mad-suite'); ?>
                </p>

                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th style="width: 20%;"><?php esc_html_e('Slug', 'mad-suite'); ?></th>
                            <th style="width: 25%;"><?php esc_html_e('Nombre visible', 'mad-suite'); ?></th>
                            <th style="width: 15%;"><?php esc_html_e('Usuarios', 'mad-suite'); ?></th>
                            <th style="width: 25%;"><?php esc_html_e('Capacidades', 'mad-suite'); ?></th>
                            <th style="width: 15%;"><?php esc_html_e('Acciones', 'mad-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roles as $slug => $role_data) : ?>
                            <?php
                            $user_count = $role_manager->get_role_user_count($slug);
                            $protected_roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager'];
                            $is_protected = in_array($slug, $protected_roles, true);
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($slug); ?></code></td>
                                <td><strong><?php echo esc_html($role_data['name']); ?></strong></td>
                                <td><?php echo esc_html($user_count); ?></td>
                                <td>
                                    <?php
                                    $caps = array_keys(array_filter($role_data['capabilities']));
                                    if (empty($caps)) {
                                        esc_html_e('Sin capacidades', 'mad-suite');
                                    } else {
                                        echo esc_html(implode(', ', array_slice($caps, 0, 3)));
                                        if (count($caps) > 3) {
                                            printf(' <span class="description">(+%d)</span>', count($caps) - 3);
                                        }
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($is_protected) : ?>
                                        <span class="description"><?php esc_html_e('Protegido', 'mad-suite'); ?></span>
                                    <?php else : ?>
                                        <?php
                                        $delete_url = wp_nonce_url(
                                            admin_url('admin-post.php?action=mads_role_creator_delete_role&role=' . urlencode($slug)),
                                            'mads_role_creator_delete_role',
                                            'mads_role_creator_nonce'
                                        );
                                        ?>
                                        <a href="<?php echo esc_url($delete_url); ?>"
                                           class="button button-small button-link-delete"
                                           onclick="return confirm('<?php echo esc_js(sprintf(__('¿Eliminar el rol "%s"? Esta acción no se puede deshacer.', 'mad-suite'), $role_data['name'])); ?>');">
                                            <?php esc_html_e('Eliminar', 'mad-suite'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Columna Derecha: Crear Nuevo Rol -->
        <div class="mad-role-creator__column">
            <div class="card">
                <h2><?php esc_html_e('Crear un nuevo rol', 'mad-suite'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Genera un rol personalizado con capacidades específicas. Las capacidades deben separarse por comas, punto y coma o barras verticales.', 'mad-suite'); ?>
                </p>

                <form method="post" action="<?php echo esc_url($import_action); ?>" class="mad-role-creator__form">
                    <?php wp_nonce_field('mads_role_creator_create_role', 'mads_role_creator_nonce'); ?>
                    <input type="hidden" name="action" value="mads_role_creator_create_role" />

                    <label for="mad-role-creator-new-role" class="mad-role-creator__label">
                        <?php esc_html_e('Slug del rol', 'mad-suite'); ?> <span class="required">*</span>
                    </label>
                    <input type="text" id="mad-role-creator-new-role" name="mads_role_creator_new_role" class="regular-text" required
                           placeholder="<?php esc_attr_e('cliente_vip', 'mad-suite'); ?>" />
                    <p class="description"><?php esc_html_e('Identificador único del rol (solo letras minúsculas, números y guiones bajos).', 'mad-suite'); ?></p>

                    <label for="mad-role-creator-new-role-name" class="mad-role-creator__label">
                        <?php esc_html_e('Nombre visible', 'mad-suite'); ?> <span class="required">*</span>
                    </label>
                    <input type="text" id="mad-role-creator-new-role-name" name="mads_role_creator_new_role_name" class="regular-text" required
                           placeholder="<?php esc_attr_e('Cliente VIP', 'mad-suite'); ?>" />
                    <p class="description"><?php esc_html_e('Nombre que se mostrará en la interfaz de WordPress.', 'mad-suite'); ?></p>

                    <label for="mad-role-creator-new-role-caps" class="mad-role-creator__label">
                        <?php esc_html_e('Capacidades', 'mad-suite'); ?>
                    </label>
                    <textarea id="mad-role-creator-new-role-caps" name="mads_role_creator_new_role_caps" rows="6" class="large-text" placeholder="read, edit_posts, upload_files"></textarea>
                    <p class="description"><?php esc_html_e('Capacidades del rol separadas por comas. La capacidad "read" se incluye automáticamente.', 'mad-suite'); ?></p>

                    <?php submit_button(__('Crear rol', 'mad-suite'), 'primary'); ?>
                </form>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3><?php esc_html_e('Capacidades Comunes de WordPress', 'mad-suite'); ?></h3>
                <details>
                    <summary style="cursor: pointer; font-weight: 600; margin-bottom: 10px;">
                        <?php esc_html_e('Ver lista de capacidades', 'mad-suite'); ?>
                    </summary>
                    <div style="line-height: 1.8;">
                        <p><strong><?php esc_html_e('Capacidades básicas:', 'mad-suite'); ?></strong></p>
                        <ul style="columns: 2; -webkit-columns: 2; -moz-columns: 2;">
                            <li><code>read</code></li>
                            <li><code>edit_posts</code></li>
                            <li><code>delete_posts</code></li>
                            <li><code>publish_posts</code></li>
                            <li><code>upload_files</code></li>
                            <li><code>edit_pages</code></li>
                            <li><code>delete_pages</code></li>
                            <li><code>publish_pages</code></li>
                        </ul>

                        <p><strong><?php esc_html_e('Capacidades de WooCommerce:', 'mad-suite'); ?></strong></p>
                        <ul style="columns: 2; -webkit-columns: 2; -moz-columns: 2;">
                            <li><code>read_product</code></li>
                            <li><code>edit_product</code></li>
                            <li><code>delete_product</code></li>
                            <li><code>edit_products</code></li>
                            <li><code>publish_products</code></li>
                            <li><code>read_shop_order</code></li>
                            <li><code>edit_shop_orders</code></li>
                            <li><code>manage_woocommerce</code></li>
                        </ul>
                    </div>
                </details>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3><?php esc_html_e('ℹ️ Información Importante', 'mad-suite'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php esc_html_e('Los roles personalizados son persistentes y se guardan en la base de datos de WordPress.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('No puedes eliminar roles protegidos del sistema (Administrator, Editor, etc.).', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Al eliminar un rol, los usuarios que lo tenían asignado lo perderán.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Las capacidades determinan qué puede hacer un usuario en el sitio.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Un usuario puede tener múltiples roles simultáneamente.', 'mad-suite'); ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
.mad-role-creator__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 24px;
    margin-top: 24px;
}

.mad-role-creator__column .card {
    padding: 20px;
}

.mad-role-creator__form {
    margin-top: 16px;
}

.mad-role-creator__label {
    display: block;
    margin-top: 12px;
    margin-bottom: 4px;
    font-weight: 600;
}

.mad-role-creator__label .required {
    color: #dc3232;
}

.mad-role-creator__form .button {
    margin-top: 12px;
}

details summary:hover {
    color: #2271b1;
}
</style>
