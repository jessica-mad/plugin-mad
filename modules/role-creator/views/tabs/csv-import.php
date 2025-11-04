<?php
if (! defined('ABSPATH')) {
    exit;
}

/** @var array  $roles */
/** @var array  $sample_rows */
/** @var string $download_url */
/** @var string $import_action */
?>

<div class="mad-role-creator__csv-import">
    <div class="mad-role-creator__grid">
        <div class="mad-role-creator__column">
            <div class="card">
                <h2><?php esc_html_e('Importar contactos desde CSV', 'mad-suite'); ?></h2>
                <p><?php esc_html_e('El CSV debe contener al menos la columna email. Puedes incluir first_name, last_name, display_name, user_login, user_pass o columnas meta_* para metadatos personalizados.', 'mad-suite'); ?></p>

                <p>
                    <a class="button button-secondary" href="<?php echo esc_url($download_url); ?>">
                        <?php esc_html_e('Descargar plantilla CSV', 'mad-suite'); ?>
                    </a>
                </p>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($import_action); ?>" class="mad-role-creator__form">
                    <?php wp_nonce_field('mads_role_creator_import', 'mads_role_creator_nonce'); ?>
                    <input type="hidden" name="action" value="mads_role_creator_import" />

                    <label for="mad-contact-importer-role" class="mad-role-creator__label">
                        <?php esc_html_e('Rol para los contactos importados', 'mad-suite'); ?>
                    </label>
                    <select id="mad-contact-importer-role" name="mads_role_creator_role" class="regular-text" required>
                        <option value=""><?php esc_html_e('Selecciona un rol…', 'mad-suite'); ?></option>
                        <?php foreach ($roles as $slug => $role_data) : ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($role_data['name']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="mad-contact-importer-file" class="mad-role-creator__label">
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

        <div class="mad-role-creator__column">
            <div class="card">
                <h3><?php esc_html_e('ℹ️ Instrucciones de Importación', 'mad-suite'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><strong><?php esc_html_e('Columna obligatoria:', 'mad-suite'); ?></strong> <?php esc_html_e('email', 'mad-suite'); ?></li>
                    <li><strong><?php esc_html_e('Columnas opcionales:', 'mad-suite'); ?></strong> first_name, last_name, display_name, user_login, user_pass</li>
                    <li><?php esc_html_e('Los usuarios se identifican por email. Si el email ya existe, el usuario se actualizará.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Si no se especifica user_login, se generará automáticamente desde el email.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Si no se especifica user_pass, se generará una contraseña aleatoria.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('El rol seleccionado se asignará a todos los usuarios importados/actualizados.', 'mad-suite'); ?></li>
                </ul>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3><?php esc_html_e('Metadatos Personalizados', 'mad-suite'); ?></h3>
                <p><?php esc_html_e('Puedes agregar columnas con el prefijo "meta_" para guardar metadatos personalizados:', 'mad-suite'); ?></p>
                <ul style="line-height: 1.8;">
                    <li><code>meta_phone</code> → <?php esc_html_e('Se guardará como user meta "phone"', 'mad-suite'); ?></li>
                    <li><code>meta_company</code> → <?php esc_html_e('Se guardará como user meta "company"', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Cualquier columna que empiece con "meta_" se procesará como metadato.', 'mad-suite'); ?></li>
                </ul>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3><?php esc_html_e('💡 Consejos', 'mad-suite'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php esc_html_e('Descarga la plantilla CSV para tener el formato correcto.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Asegúrate de que el archivo esté codificado en UTF-8.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Los emails deben ser válidos y únicos.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Puedes importar el mismo archivo múltiples veces; los usuarios existentes se actualizarán.', 'mad-suite'); ?></li>
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

.mad-role-creator__form .button {
    margin-top: 12px;
}
</style>
