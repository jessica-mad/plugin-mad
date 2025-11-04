<?php
if (! defined('ABSPATH')) {
    exit;
}

/** @var array $roles */
/** @var string $import_action */
?>

<div class="mad-role-creator__manual-assign">
    <div class="card">
        <h2><?php esc_html_e('Asignación Manual de Roles', 'mad-suite'); ?></h2>
        <p class="description">
            <?php esc_html_e('Selecciona usuarios individuales y asígnales un rol específico. Puedes buscar usuarios por nombre, email o usuario.', 'mad-suite'); ?>
        </p>

        <form method="post" action="<?php echo esc_url($import_action); ?>" class="mad-role-creator__form" id="manual-assign-form">
            <?php wp_nonce_field('mads_role_creator_assign_manual', 'mads_role_creator_nonce'); ?>
            <input type="hidden" name="action" value="mads_role_creator_assign_manual" />

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="user-selector"><?php esc_html_e('Seleccionar Usuarios', 'mad-suite'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <select id="user-selector" name="user_ids[]" class="mad-user-select" multiple="multiple" required style="width: 100%; max-width: 500px;">
                                <!-- Las opciones se cargarán dinámicamente via AJAX -->
                            </select>
                            <p class="description"><?php esc_html_e('Escribe para buscar usuarios por nombre, email o usuario. Puedes seleccionar múltiples usuarios.', 'mad-suite'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="assign-role"><?php esc_html_e('Rol a Asignar', 'mad-suite'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <select id="assign-role" name="assign_role" class="regular-text" required>
                                <option value=""><?php esc_html_e('Selecciona un rol…', 'mad-suite'); ?></option>
                                <?php foreach ($roles as $slug => $role_data) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>">
                                        <?php echo esc_html($role_data['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('El rol que se asignará a los usuarios seleccionados.', 'mad-suite'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="remove-existing"><?php esc_html_e('Opciones de Asignación', 'mad-suite'); ?></label>
                        </th>
                        <td>
                            <label for="remove-existing">
                                <input type="checkbox" id="remove-existing" name="remove_existing" value="1" />
                                <?php esc_html_e('Reemplazar roles existentes (eliminar todos los roles actuales antes de asignar el nuevo)', 'mad-suite'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Por defecto, el nuevo rol se agrega sin eliminar los roles existentes. Marca esta opción si quieres que sea el único rol del usuario.', 'mad-suite'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button(__('Asignar Rol a Usuarios Seleccionados', 'mad-suite'), 'primary'); ?>
        </form>
    </div>

    <div class="card" style="margin-top: 20px;">
        <h3><?php esc_html_e('Buscar Usuarios por Rol', 'mad-suite'); ?></h3>
        <p class="description"><?php esc_html_e('Consulta qué usuarios tienen actualmente un rol específico.', 'mad-suite'); ?></p>

        <form method="get" action="" style="margin-top: 16px;">
            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? ''); ?>" />
            <input type="hidden" name="tab" value="manual-assign" />

            <label for="search-by-role"><?php esc_html_e('Selecciona un rol:', 'mad-suite'); ?></label>
            <select id="search-by-role" name="search_role" class="regular-text">
                <option value=""><?php esc_html_e('Selecciona un rol…', 'mad-suite'); ?></option>
                <?php foreach ($roles as $slug => $role_data) : ?>
                    <option value="<?php echo esc_attr($slug); ?>"
                        <?php selected(isset($_GET['search_role']) ? $_GET['search_role'] : '', $slug); ?>>
                        <?php echo esc_html($role_data['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-secondary"><?php esc_html_e('Buscar', 'mad-suite'); ?></button>
        </form>

        <?php
        if (isset($_GET['search_role']) && ! empty($_GET['search_role'])) {
            $search_role = sanitize_key(wp_unslash($_GET['search_role']));
            $users_with_role = get_users([
                'role'   => $search_role,
                'fields' => ['ID', 'display_name', 'user_email'],
            ]);

            echo '<div style="margin-top: 20px;">';
            if (empty($users_with_role)) {
                echo '<p><em>' . esc_html__('No hay usuarios con este rol.', 'mad-suite') . '</em></p>';
            } else {
                echo '<h4>' . sprintf(
                    esc_html__('Usuarios con el rol "%s" (%d)', 'mad-suite'),
                    esc_html($roles[$search_role]['name']),
                    count($users_with_role)
                ) . '</h4>';
                echo '<table class="widefat striped"><thead><tr>';
                echo '<th>' . esc_html__('Usuario', 'mad-suite') . '</th>';
                echo '<th>' . esc_html__('Email', 'mad-suite') . '</th>';
                echo '<th>' . esc_html__('ID', 'mad-suite') . '</th>';
                echo '</tr></thead><tbody>';

                foreach ($users_with_role as $user) {
                    echo '<tr>';
                    echo '<td>' . esc_html($user->display_name) . '</td>';
                    echo '<td>' . esc_html($user->user_email) . '</td>';
                    echo '<td><code>' . esc_html($user->ID) . '</code></td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            }
            echo '</div>';
        }
        ?>
    </div>
</div>

<style>
.mad-role-creator__form .form-table th {
    padding-left: 0;
}

.mad-role-creator__form .required {
    color: #dc3232;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Inicializar Select2 para búsqueda de usuarios
    if (typeof $.fn.select2 !== 'undefined') {
        $('#user-selector').select2({
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'mads_role_creator_search_users',
                        q: params.term
                    };
                },
                processResults: function(data) {
                    return {
                        results: data
                    };
                },
                cache: true
            },
            minimumInputLength: 2,
            placeholder: '<?php echo esc_js(__('Buscar usuarios...', 'mad-suite')); ?>',
            language: {
                inputTooShort: function() {
                    return '<?php echo esc_js(__('Escribe al menos 2 caracteres para buscar', 'mad-suite')); ?>';
                },
                searching: function() {
                    return '<?php echo esc_js(__('Buscando...', 'mad-suite')); ?>';
                },
                noResults: function() {
                    return '<?php echo esc_js(__('No se encontraron usuarios', 'mad-suite')); ?>';
                }
            }
        });
    } else {
        // Fallback si Select2 no está disponible
        console.warn('Select2 no está disponible. La búsqueda de usuarios puede no funcionar correctamente.');
    }
});
</script>
