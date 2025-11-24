<?php
/**
 * Vista de configuración del módulo FedEx Returns
 */

if (!defined('ABSPATH')) exit;

$option_key = MAD_Suite_Core::option_key($module->slug());
?>

<div class="wrap">
    <h1><?php echo esc_html($module->title()); ?></h1>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html__('Configuración guardada correctamente.', 'mad-suite'); ?></p>
        </div>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $tab_id => $tab_name): ?>
            <a href="<?php echo esc_url(add_query_arg(['page' => $module->menu_slug(), 'tab' => $tab_id], admin_url('admin.php'))); ?>"
               class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tab_name); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="mads_fedex_returns_save">
        <input type="hidden" name="current_tab" value="<?php echo esc_attr($current_tab); ?>">
        <?php wp_nonce_field('mads_fedex_returns_save', 'mads_fedex_returns_nonce'); ?>

        <?php if ($current_tab === 'general'): ?>
            <!-- Tab: General -->
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="attach_existing_invoice"><?php echo esc_html__('Adjuntar Factura al Envío', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[attach_existing_invoice]" id="attach_existing_invoice"
                               value="1" <?php checked($settings['attach_existing_invoice'] ?? 1, 1); ?>>
                        <p class="description">
                            <?php echo esc_html__('Adjunta automáticamente la factura existente del pedido a la devolución de FedEx. Las facturas son generadas por tu plugin de facturas instalado.', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="allow_partial_returns"><?php echo esc_html__('Permitir Devoluciones Parciales', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[allow_partial_returns]" id="allow_partial_returns"
                               value="1" <?php checked($settings['allow_partial_returns'], 1); ?>>
                        <p class="description">
                            <?php echo esc_html__('Permite devolver solo algunos productos del pedido.', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="require_return_reason"><?php echo esc_html__('Requerir Motivo de Devolución', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[require_return_reason]" id="require_return_reason"
                               value="1" <?php checked($settings['require_return_reason'], 1); ?>>
                        <p class="description">
                            <?php echo esc_html__('Hace obligatorio especificar el motivo de la devolución.', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="enable_logging"><?php echo esc_html__('Habilitar Logs', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[enable_logging]" id="enable_logging"
                               value="1" <?php checked($settings['enable_logging'], 1); ?>>
                        <p class="description">
                            <?php echo esc_html__('Registra todas las operaciones del módulo en archivos de log.', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="log_api_requests"><?php echo esc_html__('Registrar Llamadas a API', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[log_api_requests]" id="log_api_requests"
                               value="1" <?php checked($settings['log_api_requests'], 1); ?>>
                        <p class="description">
                            <?php echo esc_html__('Registra todas las llamadas a la API de FedEx (útil para debugging).', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
            </table>

        <?php elseif ($current_tab === 'api'): ?>
            <!-- Tab: Credenciales FedEx -->
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="fedex_environment"><?php echo esc_html__('Ambiente', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <select name="<?php echo esc_attr($option_key); ?>[fedex_environment]" id="fedex_environment">
                            <option value="test" <?php selected($settings['fedex_environment'], 'test'); ?>>
                                <?php echo esc_html__('Pruebas (Sandbox)', 'mad-suite'); ?>
                            </option>
                            <option value="production" <?php selected($settings['fedex_environment'], 'production'); ?>>
                                <?php echo esc_html__('Producción', 'mad-suite'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php echo esc_html__('Selecciona el ambiente de FedEx a utilizar.', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fedex_api_key"><?php echo esc_html__('API Key', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[fedex_api_key]" id="fedex_api_key"
                               value="<?php echo esc_attr($settings['fedex_api_key']); ?>" class="regular-text">
                        <p class="description">
                            <?php echo esc_html__('Tu API Key de FedEx.', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fedex_api_secret"><?php echo esc_html__('API Secret', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="password" name="<?php echo esc_attr($option_key); ?>[fedex_api_secret]" id="fedex_api_secret"
                               value="<?php echo esc_attr($settings['fedex_api_secret']); ?>" class="regular-text">
                        <p class="description">
                            <?php echo esc_html__('Tu API Secret de FedEx.', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fedex_account_number"><?php echo esc_html__('Número de Cuenta', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[fedex_account_number]" id="fedex_account_number"
                               value="<?php echo esc_attr($settings['fedex_account_number']); ?>" class="regular-text">
                        <p class="description">
                            <?php echo esc_html__('Tu número de cuenta de FedEx.', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php echo esc_html__('Probar Conexión', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <button type="button" id="test-fedex-connection" class="button button-secondary">
                            <?php echo esc_html__('Probar Conexión con FedEx', 'mad-suite'); ?>
                        </button>
                        <div id="test-connection-result" style="margin-top: 10px;"></div>
                    </td>
                </tr>
            </table>

        <?php elseif ($current_tab === 'defaults'): ?>
            <!-- Tab: Valores por Defecto -->
            <h3><?php echo esc_html__('Configuración de Envío', 'mad-suite'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="default_service_type"><?php echo esc_html__('Tipo de Servicio', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <select name="<?php echo esc_attr($option_key); ?>[default_service_type]" id="default_service_type">
                            <option value="FEDEX_GROUND" <?php selected($settings['default_service_type'], 'FEDEX_GROUND'); ?>>FedEx Ground</option>
                            <option value="FEDEX_2_DAY" <?php selected($settings['default_service_type'], 'FEDEX_2_DAY'); ?>>FedEx 2Day</option>
                            <option value="FEDEX_EXPRESS_SAVER" <?php selected($settings['default_service_type'], 'FEDEX_EXPRESS_SAVER'); ?>>FedEx Express Saver</option>
                            <option value="STANDARD_OVERNIGHT" <?php selected($settings['default_service_type'], 'STANDARD_OVERNIGHT'); ?>>Standard Overnight</option>
                            <option value="PRIORITY_OVERNIGHT" <?php selected($settings['default_service_type'], 'PRIORITY_OVERNIGHT'); ?>>Priority Overnight</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="default_packaging_type"><?php echo esc_html__('Tipo de Empaque', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <select name="<?php echo esc_attr($option_key); ?>[default_packaging_type]" id="default_packaging_type">
                            <option value="YOUR_PACKAGING" <?php selected($settings['default_packaging_type'], 'YOUR_PACKAGING'); ?>>Tu Empaque</option>
                            <option value="FEDEX_ENVELOPE" <?php selected($settings['default_packaging_type'], 'FEDEX_ENVELOPE'); ?>>FedEx Envelope</option>
                            <option value="FEDEX_PAK" <?php selected($settings['default_packaging_type'], 'FEDEX_PAK'); ?>>FedEx Pak</option>
                            <option value="FEDEX_BOX" <?php selected($settings['default_packaging_type'], 'FEDEX_BOX'); ?>>FedEx Box</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="default_weight_unit"><?php echo esc_html__('Unidad de Peso', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <select name="<?php echo esc_attr($option_key); ?>[default_weight_unit]" id="default_weight_unit">
                            <option value="KG" <?php selected($settings['default_weight_unit'], 'KG'); ?>>Kilogramos (KG)</option>
                            <option value="LB" <?php selected($settings['default_weight_unit'], 'LB'); ?>>Libras (LB)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="default_dimension_unit"><?php echo esc_html__('Unidad de Dimensiones', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <select name="<?php echo esc_attr($option_key); ?>[default_dimension_unit]" id="default_dimension_unit">
                            <option value="CM" <?php selected($settings['default_dimension_unit'], 'CM'); ?>>Centímetros (CM)</option>
                            <option value="IN" <?php selected($settings['default_dimension_unit'], 'IN'); ?>>Pulgadas (IN)</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h3><?php echo esc_html__('Información del Remitente (Tu Almacén)', 'mad-suite'); ?></h3>
            <p class="description"><?php echo esc_html__('Esta información se usa como destino de las devoluciones.', 'mad-suite'); ?></p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="sender_name"><?php echo esc_html__('Nombre de Contacto', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[sender_name]" id="sender_name"
                               value="<?php echo esc_attr($settings['sender_name']); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sender_company"><?php echo esc_html__('Nombre de Empresa', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[sender_company]" id="sender_company"
                               value="<?php echo esc_attr($settings['sender_company']); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sender_phone"><?php echo esc_html__('Teléfono', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[sender_phone]" id="sender_phone"
                               value="<?php echo esc_attr($settings['sender_phone']); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sender_address_line1"><?php echo esc_html__('Dirección Línea 1', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[sender_address_line1]" id="sender_address_line1"
                               value="<?php echo esc_attr($settings['sender_address_line1']); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sender_address_line2"><?php echo esc_html__('Dirección Línea 2', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[sender_address_line2]" id="sender_address_line2"
                               value="<?php echo esc_attr($settings['sender_address_line2']); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sender_city"><?php echo esc_html__('Ciudad', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[sender_city]" id="sender_city"
                               value="<?php echo esc_attr($settings['sender_city']); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sender_state"><?php echo esc_html__('Estado/Provincia', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[sender_state]" id="sender_state"
                               value="<?php echo esc_attr($settings['sender_state']); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sender_postal_code"><?php echo esc_html__('Código Postal', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[sender_postal_code]" id="sender_postal_code"
                               value="<?php echo esc_attr($settings['sender_postal_code']); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sender_country"><?php echo esc_html__('País (Código)', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($option_key); ?>[sender_country]" id="sender_country"
                               value="<?php echo esc_attr($settings['sender_country']); ?>" class="regular-text" placeholder="MX">
                        <p class="description"><?php echo esc_html__('Código de país de 2 letras (ej: MX, US, CA)', 'mad-suite'); ?></p>
                    </td>
                </tr>
            </table>

        <?php elseif ($current_tab === 'logs'): ?>
            <!-- Tab: Logs -->
            <h3><?php echo esc_html__('Archivos de Log', 'mad-suite'); ?></h3>

            <?php
            $log_files = $module->get_log_files();
            if (!empty($log_files)):
            ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Archivo', 'mad-suite'); ?></th>
                            <th><?php echo esc_html__('Tamaño', 'mad-suite'); ?></th>
                            <th><?php echo esc_html__('Última Modificación', 'mad-suite'); ?></th>
                            <th><?php echo esc_html__('Acciones', 'mad-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log_files as $log_file): ?>
                            <tr>
                                <td><?php echo esc_html($log_file['name']); ?></td>
                                <td><?php echo size_format($log_file['size']); ?></td>
                                <td><?php echo date_i18n('d/m/Y H:i:s', $log_file['modified']); ?></td>
                                <td>
                                    <button type="button" class="button button-small view-log-btn"
                                            data-file="<?php echo esc_attr($log_file['path']); ?>">
                                        <?php echo esc_html__('Ver', 'mad-suite'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 20px;">
                    <button type="button" id="clear-old-logs" class="button button-secondary">
                        <?php echo esc_html__('Limpiar Logs Antiguos (>30 días)', 'mad-suite'); ?>
                    </button>
                </div>

                <div id="log-viewer" style="display: none; margin-top: 20px;">
                    <h3><?php echo esc_html__('Contenido del Log', 'mad-suite'); ?></h3>
                    <pre style="background: #f5f5f5; padding: 15px; overflow-x: auto; max-height: 500px;"><code id="log-content"></code></pre>
                </div>

            <?php else: ?>
                <p><?php echo esc_html__('No hay archivos de log disponibles.', 'mad-suite'); ?></p>
            <?php endif; ?>

        <?php endif; ?>

        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary"
                   value="<?php echo esc_attr__('Guardar Cambios', 'mad-suite'); ?>">
        </p>
    </form>
</div>
