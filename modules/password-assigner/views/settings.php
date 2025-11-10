<?php
/**
 * Vista: Configuración del módulo Password Assigner
 *
 * @var array  $tabs
 * @var string $current_tab
 * @var array  $settings
 * @var object $module
 */

if (!defined('ABSPATH')) exit;

$option_key = MAD_Suite_Core::option_key($module->slug());
$base_url = admin_url('admin.php?page=' . $module->menu_slug());
?>

<div class="wrap mads-password-assigner">
    <h1><?php echo esc_html($module->title()); ?></h1>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Configuración guardada correctamente.', 'mad-suite'); ?></p>
        </div>
    <?php endif; ?>

    <?php if (empty($settings['password'])): ?>
        <div class="notice notice-warning">
            <p><?php _e('⚠️ Debes configurar una contraseña para activar la protección del sitio.', 'mad-suite'); ?></p>
        </div>
    <?php endif; ?>

    <!-- Tabs de navegación -->
    <nav class="nav-tab-wrapper wp-clearfix">
        <?php foreach ($tabs as $tab_key => $tab_label): ?>
            <?php
            $tab_url = add_query_arg(['tab' => $tab_key], $base_url);
            $is_active = $current_tab === $tab_key;
            ?>
            <a href="<?php echo esc_url($tab_url); ?>"
               class="nav-tab <?php echo $is_active ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tab_label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Contenido del tab actual -->
    <div class="tab-content">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mads_password_assigner_save', 'mads_password_assigner_nonce'); ?>
            <input type="hidden" name="action" value="mads_password_assigner_save">
            <input type="hidden" name="current_tab" value="<?php echo esc_attr($current_tab); ?>">

            <?php
            // Renderizar el contenido del tab actual
            switch ($current_tab) {
                case 'general':
                    include __DIR__ . '/tabs/general.php';
                    break;
                case 'schedule':
                    include __DIR__ . '/tabs/schedule.php';
                    break;
                case 'advanced':
                    include __DIR__ . '/tabs/advanced.php';
                    break;
                default:
                    include __DIR__ . '/tabs/general.php';
            }
            ?>

            <?php submit_button(__('Guardar cambios', 'mad-suite')); ?>
        </form>
    </div>

    <!-- Información adicional -->
    <div class="mads-info-box">
        <h3><?php _e('ℹ️ Información de uso', 'mad-suite'); ?></h3>
        <ul>
            <li><?php _e('Usa el shortcode <code>[password_access_form]</code> en cualquier página para mostrar el formulario de contraseña.', 'mad-suite'); ?></li>
            <li><?php _e('Se recomienda crear una página específica para el login y configurarla como página de inicio en <strong>Ajustes > Lectura</strong>.', 'mad-suite'); ?></li>
            <li><?php _e('Los administradores pueden ver el sitio sin ingresar contraseña (configurable en la pestaña Avanzado).', 'mad-suite'); ?></li>
            <li><?php _e('Puedes configurar horarios para que la protección solo esté activa en ciertos días y horas.', 'mad-suite'); ?></li>
        </ul>
    </div>

    <!-- Estado actual -->
    <div class="mads-status-box">
        <h3><?php _e('Estado de la protección', 'mad-suite'); ?></h3>
        <table class="widefat">
            <tbody>
                <tr>
                    <td><strong><?php _e('Protección activa:', 'mad-suite'); ?></strong></td>
                    <td>
                        <?php if (!empty($settings['enabled']) && !empty($settings['password'])): ?>
                            <span class="mads-status-active">✓ <?php _e('Activa', 'mad-suite'); ?></span>
                        <?php else: ?>
                            <span class="mads-status-inactive">✗ <?php _e('Inactiva', 'mad-suite'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php _e('Contraseña configurada:', 'mad-suite'); ?></strong></td>
                    <td>
                        <?php if (!empty($settings['password'])): ?>
                            <span class="mads-status-active">✓ <?php _e('Sí', 'mad-suite'); ?></span>
                        <?php else: ?>
                            <span class="mads-status-inactive">✗ <?php _e('No', 'mad-suite'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php _e('Horario configurado:', 'mad-suite'); ?></strong></td>
                    <td>
                        <?php if (!empty($settings['enable_schedule'])): ?>
                            <span class="mads-status-active">✓ <?php _e('Sí', 'mad-suite'); ?></span>
                            <br>
                            <small>
                                <?php
                                printf(
                                    __('De %s a %s', 'mad-suite'),
                                    esc_html($settings['schedule_start']),
                                    esc_html($settings['schedule_end'])
                                );
                                ?>
                            </small>
                        <?php else: ?>
                            <span class="mads-status-inactive">✗ <?php _e('No', 'mad-suite'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php _e('Página de login:', 'mad-suite'); ?></strong></td>
                    <td>
                        <?php if (!empty($settings['redirect_url'])): ?>
                            <a href="<?php echo esc_url($settings['redirect_url']); ?>" target="_blank">
                                <?php echo esc_url($settings['redirect_url']); ?>
                            </a>
                        <?php else: ?>
                            <span class="mads-status-inactive"><?php _e('No configurada', 'mad-suite'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
