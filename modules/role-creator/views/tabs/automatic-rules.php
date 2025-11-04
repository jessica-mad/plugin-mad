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
                                <th><?php esc_html_e('Transformación', 'mad-suite'); ?></th>
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
                                $source_role = isset($rule['source_role']) ? $rule['source_role'] : null;
                                $replace_source = isset($rule['replace_source_role']) && $rule['replace_source_role'];

                                $conditions_text = [];
                                $min_spent = ! empty($rule['conditions']['min_spent']) ? $rule['conditions']['min_spent'] : 0;
                                $max_spent = ! empty($rule['conditions']['max_spent']) ? $rule['conditions']['max_spent'] : 0;
                                $min_orders = ! empty($rule['conditions']['min_orders']) ? $rule['conditions']['min_orders'] : 0;
                                $max_orders = ! empty($rule['conditions']['max_orders']) ? $rule['conditions']['max_orders'] : 0;

                                // Gasto
                                if ($min_spent > 0 || $max_spent > 0) {
                                    if ($min_spent > 0 && $max_spent > 0) {
                                        $conditions_text[] = sprintf(
                                            __('Gasto %s - %s', 'mad-suite'),
                                            wc_price($min_spent),
                                            wc_price($max_spent)
                                        );
                                    } elseif ($min_spent > 0) {
                                        $conditions_text[] = sprintf(
                                            __('Gasto ≥ %s', 'mad-suite'),
                                            wc_price($min_spent)
                                        );
                                    } elseif ($max_spent > 0) {
                                        $conditions_text[] = sprintf(
                                            __('Gasto ≤ %s', 'mad-suite'),
                                            wc_price($max_spent)
                                        );
                                    }
                                }

                                // Pedidos
                                if ($min_orders > 0 || $max_orders > 0) {
                                    if ($min_orders > 0 && $max_orders > 0) {
                                        $conditions_text[] = sprintf(
                                            __('Pedidos %d - %d', 'mad-suite'),
                                            $min_orders,
                                            $max_orders
                                        );
                                    } elseif ($min_orders > 0) {
                                        $conditions_text[] = sprintf(
                                            __('Pedidos ≥ %d', 'mad-suite'),
                                            $min_orders
                                        );
                                    } elseif ($max_orders > 0) {
                                        $conditions_text[] = sprintf(
                                            __('Pedidos ≤ %d', 'mad-suite'),
                                            $max_orders
                                        );
                                    }
                                }

                                $operator = isset($rule['conditions']['operator']) ? $rule['conditions']['operator'] : 'AND';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($rule['name']); ?></strong></td>
                                    <td>
                                        <?php if ($source_role) : ?>
                                            <?php
                                            $source_role_name = isset($roles[$source_role]['name']) ? $roles[$source_role]['name'] : $source_role;
                                            ?>
                                            <code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($source_role_name); ?></code>
                                            <span style="font-size: 16px;">→</span>
                                            <code style="background: #d4f0d4; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($role_name); ?></code>
                                            <?php if ($replace_source) : ?>
                                                <br><small style="color: #666;"><?php esc_html_e('(Reemplaza rol anterior)', 'mad-suite'); ?></small>
                                            <?php else : ?>
                                                <br><small style="color: #666;"><?php esc_html_e('(Agrega rol)', 'mad-suite'); ?></small>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span style="color: #999;"><?php esc_html_e('Cualquier rol', 'mad-suite'); ?></span>
                                            <span style="font-size: 16px;">→</span>
                                            <code style="background: #d4f0d4; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($role_name); ?></code>
                                        <?php endif; ?>
                                    </td>
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
                                        <button type="button" class="button button-small edit-rule-btn"
                                                data-rule-id="<?php echo esc_attr($rule_id); ?>"
                                                data-rule-name="<?php echo esc_attr($rule['name']); ?>"
                                                data-rule-role="<?php echo esc_attr($rule['role']); ?>"
                                                data-rule-source-role="<?php echo esc_attr($source_role ? $source_role : ''); ?>"
                                                data-rule-replace-source="<?php echo esc_attr($replace_source ? '1' : '0'); ?>"
                                                data-rule-min-spent="<?php echo esc_attr($min_spent); ?>"
                                                data-rule-max-spent="<?php echo esc_attr($max_spent); ?>"
                                                data-rule-min-orders="<?php echo esc_attr($min_orders); ?>"
                                                data-rule-max-orders="<?php echo esc_attr($max_orders); ?>"
                                                data-rule-operator="<?php echo esc_attr($operator); ?>">
                                            <?php esc_html_e('Editar', 'mad-suite'); ?>
                                        </button>
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
                                    <label for="rule-role"><?php esc_html_e('Rol Destino', 'mad-suite'); ?> <span class="required">*</span></label>
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

                            <tr style="background: #fff8e5; border-left: 4px solid #ffa500;">
                                <th scope="row">
                                    <label for="rule-source-role"><?php esc_html_e('Rol de Origen (Opcional)', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <select id="rule-source-role" name="rule_source_role" class="regular-text">
                                        <option value=""><?php esc_html_e('Cualquier rol (sin restricción)', 'mad-suite'); ?></option>
                                        <?php foreach ($roles as $slug => $role_data) : ?>
                                            <option value="<?php echo esc_attr($slug); ?>">
                                                <?php echo esc_html($role_data['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <strong><?php esc_html_e('🔄 Transformación de Roles:', 'mad-suite'); ?></strong>
                                        <?php esc_html_e('Si especificas un rol de origen, SOLO los usuarios con ese rol podrán ser transformados. Ejemplo: customer → vip1 → vip2.', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr style="background: #fff8e5;">
                                <th scope="row">
                                    <label for="rule-replace-source-role"><?php esc_html_e('Modo de Asignación', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <label for="rule-replace-source-role">
                                        <input type="checkbox" id="rule-replace-source-role" name="rule_replace_source_role" value="1" />
                                        <?php esc_html_e('Reemplazar el rol de origen (transformación)', 'mad-suite'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('Si está activado, el rol de origen será removido y reemplazado por el rol destino. Si está desactivado, el rol destino se agregará sin eliminar el rol de origen.', 'mad-suite'); ?>
                                    </p>
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
                                    <label for="rule-max-spent"><?php esc_html_e('Gasto Máximo (Opcional)', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="rule-max-spent" name="rule_max_spent" class="regular-text"
                                           min="0" step="0.01" placeholder="0.00" />
                                    <p class="description"><?php esc_html_e('Monto máximo que debe haber gastado el usuario (opcional, dejar en 0 para sin límite). Ejemplo: para usuarios entre $100-$500, usa mínimo=100 y máximo=500.', 'mad-suite'); ?></p>
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
                                    <label for="rule-max-orders"><?php esc_html_e('Cantidad Máxima de Pedidos (Opcional)', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="rule-max-orders" name="rule_max_orders" class="regular-text"
                                           min="0" step="1" placeholder="0" />
                                    <p class="description"><?php esc_html_e('Cantidad máxima de pedidos (opcional, dejar en 0 para sin límite). Ejemplo: para usuarios entre 5-10 pedidos, usa mínimo=5 y máximo=10.', 'mad-suite'); ?></p>
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

                    <!-- Área de Vista Previa -->
                    <div id="rule-preview-container" style="margin: 20px 0; padding: 15px; background: #f0f9ff; border-left: 4px solid #2271b1; border-radius: 4px; display: none;">
                        <h4 style="margin-top: 0; color: #2271b1;">
                            <span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Vista Previa de la Regla', 'mad-suite'); ?>
                        </h4>
                        <div id="rule-preview-content">
                            <!-- El contenido se cargará dinámicamente via AJAX -->
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" id="preview-rule-btn" class="button button-secondary">
                            <span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Vista Previa', 'mad-suite'); ?>
                        </button>
                        <?php submit_button(__('Crear Regla', 'mad-suite'), 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3><?php esc_html_e('ℹ️ Cómo funcionan las reglas', 'mad-suite'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php esc_html_e('Las reglas activas se evalúan automáticamente cuando un usuario completa un pedido.', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Puedes aplicar reglas manualmente usando el botón "Aplicar Ahora" o "Aplicar Todas".', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Si especificas ambas condiciones (gasto y pedidos), el operador lógico determina si deben cumplirse ambas (AND) o solo una (OR).', 'mad-suite'); ?></li>
                    <li><?php esc_html_e('Por defecto, los roles se agregan sin eliminar roles existentes.', 'mad-suite'); ?></li>
                </ul>
            </div>

            <div class="card" style="margin-top: 20px; background: #fff8e5; border-left: 4px solid #ffa500;">
                <h3><?php esc_html_e('🔄 Transformación de Roles Secuencial', 'mad-suite'); ?></h3>
                <p><?php esc_html_e('Puedes crear un sistema de niveles o tiers de lealtad:', 'mad-suite'); ?></p>
                <div style="padding: 15px; background: white; border-radius: 5px; margin: 15px 0;">
                    <p style="margin-bottom: 10px;"><strong><?php esc_html_e('Ejemplo de cadena de transformación:', 'mad-suite'); ?></strong></p>
                    <div style="font-size: 16px; text-align: center; padding: 10px;">
                        <code style="background: #e8e8e8; padding: 5px 10px; border-radius: 3px;">customer</code>
                        <span style="font-size: 20px; color: #ffa500;">→</span>
                        <code style="background: #d4f0d4; padding: 5px 10px; border-radius: 3px;">vip1</code>
                        <span style="font-size: 20px; color: #ffa500;">→</span>
                        <code style="background: #d4e8ff; padding: 5px 10px; border-radius: 3px;">vip2</code>
                        <span style="font-size: 20px; color: #ffa500;">→</span>
                        <code style="background: #ffe4d4; padding: 5px 10px; border-radius: 3px;">vip3</code>
                    </div>
                </div>
                <ol style="line-height: 1.8;">
                    <li><strong><?php esc_html_e('Regla 1:', 'mad-suite'); ?></strong> <?php esc_html_e('customer → vip1 (si 3+ pedidos o $500+ gastados)', 'mad-suite'); ?></li>
                    <li><strong><?php esc_html_e('Regla 2:', 'mad-suite'); ?></strong> <?php esc_html_e('vip1 → vip2 (si 10+ pedidos o $2000+ gastados)', 'mad-suite'); ?></li>
                    <li><strong><?php esc_html_e('Regla 3:', 'mad-suite'); ?></strong> <?php esc_html_e('vip2 → vip3 (si 25+ pedidos o $5000+ gastados)', 'mad-suite'); ?></li>
                </ol>
                <p style="margin-top: 10px;">
                    <strong><?php esc_html_e('💡 Consejo:', 'mad-suite'); ?></strong>
                    <?php esc_html_e('Usa el checkbox "Reemplazar rol de origen" para que los usuarios pasen de un nivel a otro (customer desaparece cuando se convierte en vip1).', 'mad-suite'); ?>
                </p>
            </div>

            <?php
            // Mostrar cadenas de transformación existentes
            use MAD_Suite\Modules\RoleCreator\RoleRule;
            $chains = RoleRule::instance()->get_transformation_chains();
            if (! empty($chains)) :
            ?>
            <div class="card" style="margin-top: 20px;">
                <h3><?php esc_html_e('📊 Cadenas de Transformación Activas', 'mad-suite'); ?></h3>
                <p class="description"><?php esc_html_e('Visualización de las transformaciones de roles configuradas:', 'mad-suite'); ?></p>
                <ul style="list-style: none; padding-left: 0;">
                    <?php foreach ($chains as $chain) : ?>
                        <?php
                        $from_name = isset($roles[$chain['from']]['name']) ? $roles[$chain['from']]['name'] : $chain['from'];
                        $to_name = isset($roles[$chain['to']]['name']) ? $roles[$chain['to']]['name'] : $chain['to'];
                        $rule_active = isset($chain['rule']['active']) && $chain['rule']['active'];
                        ?>
                        <li style="padding: 8px; margin: 5px 0; background: <?php echo $rule_active ? '#f0f9ff' : '#f5f5f5'; ?>; border-left: 3px solid <?php echo $rule_active ? '#2271b1' : '#999'; ?>; border-radius: 3px;">
                            <code><?php echo esc_html($from_name); ?></code>
                            <span style="font-size: 16px;">→</span>
                            <code><?php echo esc_html($to_name); ?></code>
                            <small style="color: #666;">(<?php echo esc_html($chain['rule']['name']); ?>)</small>
                            <?php if (! $rule_active) : ?>
                                <span style="color: #dc3232;"><?php esc_html_e('- Inactiva', 'mad-suite'); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal para Editar Regla -->
    <div id="edit-rule-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; overflow-y: auto;">
        <div style="max-width: 800px; margin: 50px auto; background: white; padding: 30px; border-radius: 8px; position: relative;">
            <button type="button" id="close-edit-modal" style="position: absolute; top: 15px; right: 15px; background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>

            <h2><?php esc_html_e('Editar Regla Automática', 'mad-suite'); ?></h2>

            <form method="post" action="<?php echo esc_url($import_action); ?>" id="edit-rule-form">
                <?php wp_nonce_field('mads_role_creator_update_rule', 'mads_role_creator_nonce'); ?>
                <input type="hidden" name="action" value="mads_role_creator_update_rule" />
                <input type="hidden" name="rule_id" id="edit-rule-id" value="" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="edit-rule-name"><?php esc_html_e('Nombre de la Regla', 'mad-suite'); ?> <span class="required">*</span></label>
                            </th>
                            <td>
                                <input type="text" id="edit-rule-name" name="rule_name" class="regular-text" required />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="edit-rule-role"><?php esc_html_e('Rol Destino', 'mad-suite'); ?> <span class="required">*</span></label>
                            </th>
                            <td>
                                <select id="edit-rule-role" name="rule_role" class="regular-text" required>
                                    <option value=""><?php esc_html_e('Selecciona un rol…', 'mad-suite'); ?></option>
                                    <?php foreach ($roles as $slug => $role_data) : ?>
                                        <option value="<?php echo esc_attr($slug); ?>">
                                            <?php echo esc_html($role_data['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="edit-rule-source-role"><?php esc_html_e('Rol de Origen (Opcional)', 'mad-suite'); ?></label>
                            </th>
                            <td>
                                <select id="edit-rule-source-role" name="rule_source_role" class="regular-text">
                                    <option value=""><?php esc_html_e('Cualquier rol', 'mad-suite'); ?></option>
                                    <?php foreach ($roles as $slug => $role_data) : ?>
                                        <option value="<?php echo esc_attr($slug); ?>">
                                            <?php echo esc_html($role_data['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="edit-rule-replace-source-role"><?php esc_html_e('Modo de Asignación', 'mad-suite'); ?></label>
                            </th>
                            <td>
                                <label for="edit-rule-replace-source-role">
                                    <input type="checkbox" id="edit-rule-replace-source-role" name="rule_replace_source_role" value="1" />
                                    <?php esc_html_e('Reemplazar el rol de origen', 'mad-suite'); ?>
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="edit-rule-min-spent"><?php esc_html_e('Gasto Mínimo', 'mad-suite'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="edit-rule-min-spent" name="rule_min_spent" class="regular-text"
                                       min="0" step="0.01" placeholder="0.00" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="edit-rule-max-spent"><?php esc_html_e('Gasto Máximo (Opcional)', 'mad-suite'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="edit-rule-max-spent" name="rule_max_spent" class="regular-text"
                                       min="0" step="0.01" placeholder="0.00" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="edit-rule-min-orders"><?php esc_html_e('Cantidad Mínima de Pedidos', 'mad-suite'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="edit-rule-min-orders" name="rule_min_orders" class="regular-text"
                                       min="0" step="1" placeholder="0" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="edit-rule-max-orders"><?php esc_html_e('Cantidad Máxima de Pedidos (Opcional)', 'mad-suite'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="edit-rule-max-orders" name="rule_max_orders" class="regular-text"
                                       min="0" step="1" placeholder="0" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="edit-rule-operator"><?php esc_html_e('Operador Lógico', 'mad-suite'); ?></label>
                            </th>
                            <td>
                                <select id="edit-rule-operator" name="rule_operator" class="regular-text">
                                    <option value="AND"><?php esc_html_e('Y (AND)', 'mad-suite'); ?></option>
                                    <option value="OR"><?php esc_html_e('O (OR)', 'mad-suite'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" id="cancel-edit-btn" class="button"><?php esc_html_e('Cancelar', 'mad-suite'); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Guardar Cambios', 'mad-suite'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
