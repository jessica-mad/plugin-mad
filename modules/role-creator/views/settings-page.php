<?php
if (! defined('ABSPATH')) {
    exit;
}

/** @var array  $roles */
/** @var array  $sample_rows */
/** @var string $download_url */
/** @var string $import_action */
/** @var object $module */
?>
<div class="wrap mad-contact-importer">
    <h1><?php echo esc_html($module->title()); ?></h1>
    <p class="description">
        <?php esc_html_e('Carga contactos desde un archivo CSV para crear nuevos usuarios o actualizar los existentes asignándoles un rol común.', 'mad-suite'); ?>
    </p>

    <div class="mad-contact-importer__grid">
        <div class="mad-contact-importer__column">
            <div class="card">
                <h2><?php esc_html_e('Importar contactos desde CSV', 'mad-suite'); ?></h2>
                <p><?php esc_html_e('El CSV debe contener al menos la columna email. Puedes incluir first_name, last_name, display_name, user_login, user_pass o columnas meta_* para metadatos personalizados.', 'mad-suite'); ?></p>

                <p>
                    <a class="button button-secondary" href="<?php echo esc_url($download_url); ?>">
                        <?php esc_html_e('Descargar plantilla CSV', 'mad-suite'); ?>
                    </a>
                </p>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($import_action); ?>" class="mad-contact-importer__form">
                    <?php wp_nonce_field('mads_role_creator_import', 'mads_role_creator_nonce'); ?>
                    <input type="hidden" name="action" value="mads_role_creator_import" />

                    <label for="mad-contact-importer-role" class="mad-contact-importer__label">
                        <?php esc_html_e('Rol para los contactos importados', 'mad-suite'); ?>
                    </label>
                    <select id="mad-contact-importer-role" name="mads_role_creator_role" class="regular-text" required>
                        <option value=""><?php esc_html_e('Selecciona un rol…', 'mad-suite'); ?></option>
                        <?php foreach ($roles as $slug => $role_data) : ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($role_data['name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="mad-contact-importer-file" class="mad-contact-importer__label">
                        <?php esc_html_e('Selecciona el archivo CSV', 'mad-suite'); ?>
                    </label>
                    <input type="file" id="mad-contact-importer-file" name="mads_role_creator_csv" accept=".csv" class="regular-text" required />

                    <p class="description">
                        <?php esc_html_e('Los contactos existentes se actualizarán por email; los nuevos se crearán automáticamente.', 'mad-suite'); ?>
                    </p>

                    <?php submit_button(__('Importar contactos', 'mad-suite')); ?>
                </form>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Formato esperado', 'mad-suite'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('email', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('first_name', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('last_name', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('display_name', 'mad-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sample_rows as $sample) : ?>
                            <tr>
                                <td><code><?php echo esc_html($sample['email']); ?></code></td>
                                <td><?php echo esc_html($sample['first_name']); ?></td>
                                <td><?php echo esc_html($sample['last_name']); ?></td>
                                <td><?php echo esc_html($sample['display_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mad-contact-importer__column">
            <div class="card">
                <h2><?php esc_html_e('Crear un nuevo rol', 'mad-suite'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Genera un rol personalizado antes de asignarlo en la importación. Las capacidades deben separarse por comas, punto y coma o barras verticales.', 'mad-suite'); ?>
                </p>

                <form method="post" action="<?php echo esc_url($import_action); ?>" class="mad-contact-importer__form">
                    <?php wp_nonce_field('mads_role_creator_create_role', 'mads_role_creator_nonce'); ?>
                    <input type="hidden" name="action" value="mads_role_creator_create_role" />

                    <label for="mad-contact-importer-new-role" class="mad-contact-importer__label">
                        <?php esc_html_e('Slug del rol', 'mad-suite'); ?>
                    </label>
                    <input type="text" id="mad-contact-importer-new-role" name="mads_role_creator_new_role" class="regular-text" required />

                    <label for="mad-contact-importer-new-role-name" class="mad-contact-importer__label">
                        <?php esc_html_e('Nombre visible', 'mad-suite'); ?>
                    </label>
                    <input type="text" id="mad-contact-importer-new-role-name" name="mads_role_creator_new_role_name" class="regular-text" required />

                    <label for="mad-contact-importer-new-role-caps" class="mad-contact-importer__label">
                        <?php esc_html_e('Capacidades', 'mad-suite'); ?>
                    </label>
                    <textarea id="mad-contact-importer-new-role-caps" name="mads_role_creator_new_role_caps" rows="4" class="large-text" placeholder="read, edit_posts, manage_woocommerce"></textarea>

                    <?php submit_button(__('Crear rol', 'mad-suite'), 'secondary'); ?>
                </form>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Roles disponibles actualmente', 'mad-suite'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Verifica los roles existentes antes de importar. Puedes crear uno nuevo con el formulario anterior.', 'mad-suite'); ?>
                </p>

                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Slug', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('Nombre visible', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('Capacidades', 'mad-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roles as $slug => $role_data) : ?>
                            <tr>
                                <td><code><?php echo esc_html($slug); ?></code></td>
                                <td><?php echo esc_html($role_data['name']); ?></td>
                                <td>
                                    <?php
                                    $caps = array_keys(array_filter($role_data['capabilities']));
                                    if (empty($caps)) {
                                        esc_html_e('Sin capacidades registradas.', 'mad-suite');
                                    } else {
                                        echo esc_html(implode(', ', array_slice($caps, 0, 6)));
                                        if (count($caps) > 6) {
                                            printf(' <span class="description">(+%d)</span>', count($caps) - 6);
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .mad-contact-importer__grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 24px;
        margin-top: 24px;
    }

    .mad-contact-importer__column .card {
        padding: 20px;
    }

    .mad-contact-importer__form {
        margin-top: 16px;
    }

    .mad-contact-importer__label {
        display: block;
        margin-top: 12px;
        margin-bottom: 4px;
        font-weight: 600;
    }

    .mad-contact-importer__form .button {
        margin-top: 12px;
    }
</style>