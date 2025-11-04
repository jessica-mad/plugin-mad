<?php
if (! defined('ABSPATH')) {
    exit;
}

/** @var array $all_rules */
/** @var array $roles */
/** @var string $import_action */
?>

<div class="mad-role-creator__automatic-rules">
    <div class="mad-role-creator__grid">
        <!-- Columna Izquierda: Lista de Reglas -->
        <div class="mad-role-creator__column">
            <div class="card">
                <h2><?php esc_html_e('Reglas Automáticas Configuradas', 'mad-suite'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Las reglas activas se evalúan automáticamente cuando un usuario completa un pedido. También puedes aplicarlas manualmente.', 'mad-suite'); ?>
                </p>

                <?php if (empty($all_rules)) : ?>
                    <p><em><?php esc_html_e('No hay reglas configuradas aún. Crea una nueva regla usando el formulario a la derecha.', 'mad-suite'); ?></em></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Nombre', 'mad-suite'); ?></th>
                                <th><?php esc_html_e('Rol', 'mad-suite'); ?></th>
                                <th><?php esc_html_e('Condiciones', 'mad-suite'); ?></th>
                                <th><?php esc_html_e('Estado', 'mad-suite'); ?></th>
                                <th><?php esc_html_e('Acciones', 'mad-suite'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_rules as $rule) : ?>
                                <?php
                                $is_active = isset($rule['active']) && $rule['active'];
                                $rule_id = isset($rule['id']) ? $rule['id'] : '';
                                $role_name = isset($roles[$rule['role']]['name']) ? $roles[$rule['role']]['name'] : $rule['role'];

                                $conditions_text = [];
                                if (! empty($rule['conditions']['min_spent'])) {
                                    $conditions_text[] = sprintf(
                                        __('Gasto ≥ %s', 'mad-suite'),
                                        wc_price($rule['conditions']['min_spent'])
                                    );
                                }
                                if (! empty($rule['conditions']['min_orders'])) {
                                    $conditions_text[] = sprintf(
                                        __('Pedidos ≥ %d', 'mad-suite'),
                                        $rule['conditions']['min_orders']
                                    );
                                }
                                $operator = isset($rule['conditions']['operator']) ? $rule['conditions']['operator'] : 'AND';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($rule['name']); ?></strong></td>
                                    <td><code><?php echo esc_html($role_name); ?></code></td>
                                    <td>
                                        <?php echo esc_html(implode(' ' . $operator . ' ', $conditions_text)); ?>
                                    </td>
                                    <td>
                                        <?php if ($is_active) : ?>
                                            <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <?php esc_html_e('Activa', 'mad-suite'); ?>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> <?php esc_html_e('Inactiva', 'mad-suite'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $toggle_url = wp_nonce_url(
                                            admin_url('admin-post.php?action=mads_role_creator_toggle_rule&rule_id=' . urlencode($rule_id)),
                                            'mads_role_creator_toggle_rule',
                                            'mads_role_creator_nonce'
                                        );
                                        $apply_url = wp_nonce_url(
                                            admin_url('admin-post.php?action=mads_role_creator_apply_rule&rule_id=' . urlencode($rule_id)),
                                            'mads_role_creator_apply_rule',
                                            'mads_role_creator_nonce'
                                        );
                                        $delete_url = wp_nonce_url(
                                            admin_url('admin-post.php?action=mads_role_creator_delete_rule&rule_id=' . urlencode($rule_id)),
                                            'mads_role_creator_delete_rule',
                                            'mads_role_creator_nonce'
                                        );
                                        ?>
                                        <a href="<?php echo esc_url($toggle_url); ?>" class="button button-small">
                                            <?php echo $is_active ? esc_html__('Desactivar', 'mad-suite') : esc_html__('Activar', 'mad-suite'); ?>
                                        </a>
                                        <a href="<?php echo esc_url($apply_url); ?>" class="button button-small button-primary">
                                            <?php esc_html_e('Aplicar Ahora', 'mad-suite'); ?>
                                        </a>
                                        <a href="<?php echo esc_url($delete_url); ?>"
                                           class="button button-small button-link-delete"
                                           onclick="return confirm('<?php esc_attr_e('¿Estás seguro de eliminar esta regla?', 'mad-suite'); ?>');">
                                            <?php esc_html_e('Eliminar', 'mad-suite'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p style="margin-top: 16px;">
                        <?php
                        $apply_all_url = wp_nonce_url(
                            admin_url('admin-post.php?action=mads_role_creator_apply_all_rules'),
                            'mads_role_creator_apply_all_rules',
                            'mads_role_creator_nonce'
                        );
                        ?>
                        <a href="<?php echo esc_url($apply_all_url); ?>" class="button button-secondary">
                            <?php esc_html_e('Aplicar Todas las Reglas Activas', 'mad-suite'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna Derecha: Crear Nueva Regla -->
        <div class="mad-role-creator__column">
            <div class="card">
                <h2><?php esc_html_e('Crear Nueva Regla Automática', 'mad-suite'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Define las condiciones que deben cumplir los usuarios para que se les asigne automáticamente un rol específico.', 'mad-suite'); ?>
                </p>

                <form method="post" action="<?php echo esc_url($import_action); ?>" class="mad-role-creator__form">
                    <?php wp_nonce_field('mads_role_creator_create_rule', 'mads_role_creator_nonce'); ?>
                    <input type="hidden" name="action" value="mads_role_creator_create_rule" />

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="rule-name"><?php esc_html_e('Nombre de la Regla', 'mad-suite'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="rule-name" name="rule_name" class="regular-text" required
                                           placeholder="<?php esc_attr_e('Ej: Clientes VIP', 'mad-suite'); ?>" />
                                    <p class="description"><?php esc_html_e('Un nombre descriptivo para identificar esta regla.', 'mad-suite'); ?></p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="rule-role"><?php esc_html_e('Rol a Asignar', 'mad-suite'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <select id="rule-role" name="rule_role" class="regular-text" required>
                                        <option value=""><?php esc_html_e('Selecciona un rol…', 'mad-suite'); ?></option>
                                        <?php foreach ($roles as $slug => $role_data) : ?>
                                            <option value="<?php echo esc_attr($slug); ?>">
                                                <?php echo esc_html($role_data['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e('El rol que se asignará a los usuarios que cumplan las condiciones.', 'mad-suite'); ?></p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="rule-min-spent"><?php esc_html_e('Gasto Mínimo', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="rule-min-spent" name="rule_min_spent" class="regular-text"
                                           min="0" step="0.01" placeholder="0.00" />
                                    <p class="description"><?php esc_html_e('Monto mínimo que debe haber gastado el usuario en la tienda (dejar en 0 para no evaluar esta condición).', 'mad-suite'); ?></p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="rule-min-orders"><?php esc_html_e('Cantidad Mínima de Pedidos', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="rule-min-orders" name="rule_min_orders" class="regular-text"
                                           min="0" step="1" placeholder="0" />
                                    <p class="description"><?php esc_html_e('Cantidad mínima de pedidos completados (dejar en 0 para no evaluar esta condición).', 'mad-suite'); ?></p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="rule-operator"><?php esc_html_e('Operador Lógico', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <select id="rule-operator" name="rule_operator" class="regular-text">
                                        <option value="AND"><?php esc_html_e('Y (AND) - Debe cumplir ambas condiciones', 'mad-suite'); ?></option>
                                        <option value="OR"><?php esc_html_e('O (OR) - Debe cumplir al menos una', 'mad-suite'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Define si el usuario debe cumplir ambas condiciones o solo una de ellas.', 'mad-suite'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php submit_button(__('Crear Regla', 'mad-suite'), 'primary'); ?>
                </form>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3><?php esc_html_e('ℹ️ Cómo funcionan las reglas', 'mad-suite'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php esc_html_e('Las reglas activas se evalúan automáticamente cuando un usuario completa un pedido.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Puedes aplicar reglas manualmente usando el botón "Aplicar Ahora" o "Aplicar Todas".', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Si especificas ambas condiciones (gasto y pedidos), el operador lógico determina si deben cumplirse ambas (AND) o solo una (OR).', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Los roles se agregan a los usuarios existentes sin eliminar sus roles actuales.', 'mad-suite'); ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>
