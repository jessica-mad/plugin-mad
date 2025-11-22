<?php
/**
 * Vista de configuración del módulo Guest Activation
 */

if (!defined('ABSPATH')) exit;

$option_key = MAD_Suite_Core::option_key($module->slug());
?>

<div class="wrap mad-guest-activation-settings">
    <h1><?php echo esc_html($module->title()); ?></h1>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Configuración guardada correctamente.', 'mad-suite'); ?></p>
        </div>
    <?php endif; ?>

    <!-- Pestañas -->
    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $tab_key => $tab_label): ?>
            <a href="<?php echo esc_url(add_query_arg('tab', $tab_key)); ?>"
               class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tab_label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="tab-content">
        <?php if ($current_tab === 'logs'): ?>
            <!-- Pestaña de Logs -->
            <?php include __DIR__ . '/logs-tab.php'; ?>
        <?php else: ?>
            <!-- Formulario de configuración -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mads_guest_activation_save">
                <input type="hidden" name="current_tab" value="<?php echo esc_attr($current_tab); ?>">
                <?php wp_nonce_field('mads_guest_activation_save', 'mads_guest_activation_nonce'); ?>

                <?php if ($current_tab === 'general'): ?>
                    <!-- Configuración General -->
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="activation_page_id"><?php esc_html_e('Página de Activación', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <?php
                                    wp_dropdown_pages([
                                        'name' => $option_key . '[activation_page_id]',
                                        'id' => 'activation_page_id',
                                        'selected' => $settings['activation_page_id'],
                                        'show_option_none' => __('- Seleccionar página -', 'mad-suite'),
                                        'option_none_value' => 0,
                                    ]);
                                    ?>
                                    <p class="description">
                                        <?php esc_html_e('Página donde está el shortcode [mad_guest_activation].', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="token_expiration_hours"><?php esc_html_e('Expiración del Token (horas)', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="number"
                                           name="<?php echo esc_attr($option_key); ?>[token_expiration_hours]"
                                           id="token_expiration_hours"
                                           value="<?php echo esc_attr($settings['token_expiration_hours']); ?>"
                                           min="1"
                                           max="168"
                                           class="small-text">
                                    <p class="description">
                                        <?php esc_html_e('Tiempo de validez del enlace de activación (default: 24 horas).', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="enable_wpml"><?php esc_html_e('Activar WPML', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="checkbox"
                                           name="<?php echo esc_attr($option_key); ?>[enable_wpml]"
                                           id="enable_wpml"
                                           value="1"
                                           <?php checked($settings['enable_wpml'], 1); ?>>
                                    <label for="enable_wpml">
                                        <?php esc_html_e('Habilitar soporte multiidioma (detecta /en/ en URL)', 'mad-suite'); ?>
                                    </label>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                <?php elseif ($current_tab === 'messages'): ?>
                    <!-- Mensajes -->
                    <table class="form-table">
                        <tbody>
                            <!-- Español -->
                            <tr>
                                <th colspan="2">
                                    <h2><?php esc_html_e('Mensajes en Español', 'mad-suite'); ?></h2>
                                </th>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="block_message_es"><?php esc_html_e('Mensaje de Bloqueo en Registro', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[block_message_es]"
                                              id="block_message_es"
                                              rows="3"
                                              class="large-text"><?php echo esc_textarea($settings['block_message_es']); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="email_found_message_es"><?php esc_html_e('Mensaje Email Encontrado', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[email_found_message_es]"
                                              id="email_found_message_es"
                                              rows="2"
                                              class="large-text"><?php echo esc_textarea($settings['email_found_message_es']); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="email_not_found_message_es"><?php esc_html_e('Mensaje Email No Encontrado', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[email_not_found_message_es]"
                                              id="email_not_found_message_es"
                                              rows="2"
                                              class="large-text"><?php echo esc_textarea($settings['email_not_found_message_es']); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="button_text_es"><?php esc_html_e('Texto del Botón en "Mis Pedidos"', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="<?php echo esc_attr($option_key); ?>[button_text_es]"
                                           id="button_text_es"
                                           value="<?php echo esc_attr($settings['button_text_es']); ?>"
                                           class="regular-text">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="orders_found_message_es"><?php esc_html_e('Mensaje Pedidos Encontrados', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[orders_found_message_es]"
                                              id="orders_found_message_es"
                                              rows="2"
                                              class="large-text"><?php echo esc_textarea($settings['orders_found_message_es']); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e('Usar {count} para mostrar el número de pedidos encontrados.', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="no_orders_message_es"><?php esc_html_e('Mensaje Sin Pedidos Previos', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[no_orders_message_es]"
                                              id="no_orders_message_es"
                                              rows="2"
                                              class="large-text"><?php echo esc_textarea($settings['no_orders_message_es']); ?></textarea>
                                </td>
                            </tr>

                            <!-- Inglés -->
                            <tr>
                                <th colspan="2">
                                    <h2><?php esc_html_e('Mensajes en English', 'mad-suite'); ?></h2>
                                </th>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="block_message_en"><?php esc_html_e('Block Message on Registration', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[block_message_en]"
                                              id="block_message_en"
                                              rows="3"
                                              class="large-text"><?php echo esc_textarea($settings['block_message_en']); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="email_found_message_en"><?php esc_html_e('Email Found Message', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[email_found_message_en]"
                                              id="email_found_message_en"
                                              rows="2"
                                              class="large-text"><?php echo esc_textarea($settings['email_found_message_en']); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="email_not_found_message_en"><?php esc_html_e('Email Not Found Message', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[email_not_found_message_en]"
                                              id="email_not_found_message_en"
                                              rows="2"
                                              class="large-text"><?php echo esc_textarea($settings['email_not_found_message_en']); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="button_text_en"><?php esc_html_e('Button Text in "My Orders"', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="<?php echo esc_attr($option_key); ?>[button_text_en]"
                                           id="button_text_en"
                                           value="<?php echo esc_attr($settings['button_text_en']); ?>"
                                           class="regular-text">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="orders_found_message_en"><?php esc_html_e('Orders Found Message', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[orders_found_message_en]"
                                              id="orders_found_message_en"
                                              rows="2"
                                              class="large-text"><?php echo esc_textarea($settings['orders_found_message_en']); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e('Use {count} to display the number of orders found.', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="no_orders_message_en"><?php esc_html_e('No Previous Orders Message', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[no_orders_message_en]"
                                              id="no_orders_message_en"
                                              rows="2"
                                              class="large-text"><?php echo esc_textarea($settings['no_orders_message_en']); ?></textarea>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                <?php elseif ($current_tab === 'emails'): ?>
                    <!-- Emails -->
                    <table class="form-table">
                        <tbody>
                            <!-- Email de Activación - Español -->
                            <tr>
                                <th colspan="2">
                                    <h2><?php esc_html_e('Email de Activación (Español)', 'mad-suite'); ?></h2>
                                </th>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="token_email_subject_es"><?php esc_html_e('Asunto del Email', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="<?php echo esc_attr($option_key); ?>[token_email_subject_es]"
                                           id="token_email_subject_es"
                                           value="<?php echo esc_attr($settings['token_email_subject_es']); ?>"
                                           class="large-text">
                                    <p class="description">
                                        <?php esc_html_e('Usar {site_name} para el nombre del sitio.', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="token_email_body_es"><?php esc_html_e('Cuerpo del Email', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[token_email_body_es]"
                                              id="token_email_body_es"
                                              rows="6"
                                              class="large-text"><?php echo esc_textarea($settings['token_email_body_es']); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e('Usar: {activation_link}, {expiration_hours}, {site_name}', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <!-- Email de Activación - Inglés -->
                            <tr>
                                <th colspan="2">
                                    <h2><?php esc_html_e('Email de Activación (English)', 'mad-suite'); ?></h2>
                                </th>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="token_email_subject_en"><?php esc_html_e('Email Subject', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="<?php echo esc_attr($option_key); ?>[token_email_subject_en]"
                                           id="token_email_subject_en"
                                           value="<?php echo esc_attr($settings['token_email_subject_en']); ?>"
                                           class="large-text">
                                    <p class="description">
                                        <?php esc_html_e('Use {site_name} for site name.', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="token_email_body_en"><?php esc_html_e('Email Body', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[token_email_body_en]"
                                              id="token_email_body_en"
                                              rows="6"
                                              class="large-text"><?php echo esc_textarea($settings['token_email_body_en']); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e('Use: {activation_link}, {expiration_hours}, {site_name}', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <!-- Email de Confirmación - Español -->
                            <tr>
                                <th colspan="2">
                                    <h2><?php esc_html_e('Email de Confirmación (Español)', 'mad-suite'); ?></h2>
                                </th>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="confirmation_email_subject_es"><?php esc_html_e('Asunto del Email', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="<?php echo esc_attr($option_key); ?>[confirmation_email_subject_es]"
                                           id="confirmation_email_subject_es"
                                           value="<?php echo esc_attr($settings['confirmation_email_subject_es']); ?>"
                                           class="large-text">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="confirmation_email_body_es"><?php esc_html_e('Cuerpo del Email', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[confirmation_email_body_es]"
                                              id="confirmation_email_body_es"
                                              rows="4"
                                              class="large-text"><?php echo esc_textarea($settings['confirmation_email_body_es']); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e('Usar {site_name} para el nombre del sitio.', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>

                            <!-- Email de Confirmación - Inglés -->
                            <tr>
                                <th colspan="2">
                                    <h2><?php esc_html_e('Email de Confirmación (English)', 'mad-suite'); ?></h2>
                                </th>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="confirmation_email_subject_en"><?php esc_html_e('Email Subject', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="<?php echo esc_attr($option_key); ?>[confirmation_email_subject_en]"
                                           id="confirmation_email_subject_en"
                                           value="<?php echo esc_attr($settings['confirmation_email_subject_en']); ?>"
                                           class="large-text">
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="confirmation_email_body_en"><?php esc_html_e('Email Body', 'mad-suite'); ?></label>
                                </th>
                                <td>
                                    <textarea name="<?php echo esc_attr($option_key); ?>[confirmation_email_body_en]"
                                              id="confirmation_email_body_en"
                                              rows="4"
                                              class="large-text"><?php echo esc_textarea($settings['confirmation_email_body_en']); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e('Use {site_name} for site name.', 'mad-suite'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($current_tab !== 'logs'): ?>
                    <?php submit_button(__('Guardar Configuración', 'mad-suite')); ?>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
