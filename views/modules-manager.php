<?php
/**
 * Vista: Gestión de Módulos de MAD Suite
 *
 * Página principal donde se pueden activar/desactivar módulos
 */

if (!defined('ABSPATH')) exit;

$core = MAD_Suite_Core::instance();
$all_modules = $core->get_all_modules_info();

// Ordenar módulos alfabéticamente por título
uasort($all_modules, function($a, $b) {
    return strcmp($a['title'], $b['title']);
});

?>

<div class="wrap mads-modules-manager">
    <h1><?php _e('Gestión de Módulos MAD Suite', 'mad-suite'); ?></h1>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('✓ Módulo actualizado correctamente. Los cambios se aplicarán en la próxima carga.', 'mad-suite'); ?></p>
        </div>
    <?php endif; ?>

    <div class="mads-intro-box">
        <p class="description">
            <?php _e('Desde aquí puedes activar o desactivar los módulos de MAD Suite. Por defecto, todos los módulos están deshabilitados hasta que los actives manualmente.', 'mad-suite'); ?>
        </p>
        <p class="description">
            <strong><?php _e('Nota:', 'mad-suite'); ?></strong>
            <?php _e('Algunos módulos requieren que ciertos plugins estén instalados y activos para funcionar correctamente.', 'mad-suite'); ?>
        </p>
    </div>

    <?php if (empty($all_modules)): ?>
        <div class="notice notice-warning">
            <p><?php _e('⚠️ No se encontraron módulos disponibles.', 'mad-suite'); ?></p>
        </div>
    <?php else: ?>

        <div class="mads-modules-grid">
            <?php foreach ($all_modules as $module_info): ?>
                <?php
                $slug = $module_info['slug'];
                $title = $module_info['title'];
                $description = $module_info['description'];
                $required_plugins = $module_info['required_plugins'];
                $is_enabled = $module_info['enabled'];

                // Verificar si los plugins requeridos están activos
                $missing_plugins = [];
                foreach ($required_plugins as $plugin_name => $plugin_file) {
                    if (!is_plugin_active($plugin_file)) {
                        $missing_plugins[] = $plugin_name;
                    }
                }

                $has_missing_plugins = !empty($missing_plugins);
                $card_class = $is_enabled ? 'mads-module-card enabled' : 'mads-module-card';
                if ($has_missing_plugins) {
                    $card_class .= ' has-warning';
                }
                ?>

                <div class="<?php echo esc_attr($card_class); ?>" data-module="<?php echo esc_attr($slug); ?>">
                    <div class="mads-module-header">
                        <h3 class="mads-module-title"><?php echo esc_html($title); ?></h3>
                        <div class="mads-module-status">
                            <?php if ($is_enabled): ?>
                                <span class="mads-status-badge enabled"><?php _e('Activo', 'mad-suite'); ?></span>
                            <?php else: ?>
                                <span class="mads-status-badge disabled"><?php _e('Inactivo', 'mad-suite'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($description)): ?>
                        <p class="mads-module-description"><?php echo esc_html($description); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($required_plugins)): ?>
                        <div class="mads-module-requirements">
                            <strong><?php _e('Requiere:', 'mad-suite'); ?></strong>
                            <ul>
                                <?php foreach ($required_plugins as $plugin_name => $plugin_file): ?>
                                    <?php
                                    $is_active = is_plugin_active($plugin_file);
                                    $icon = $is_active ? '✓' : '✗';
                                    $class = $is_active ? 'plugin-active' : 'plugin-missing';
                                    ?>
                                    <li class="<?php echo esc_attr($class); ?>">
                                        <span class="plugin-status-icon"><?php echo $icon; ?></span>
                                        <?php echo esc_html($plugin_name); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="mads-module-actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('mads_toggle_module', 'mads_nonce'); ?>
                            <input type="hidden" name="action" value="mads_toggle_module">
                            <input type="hidden" name="module_slug" value="<?php echo esc_attr($slug); ?>">

                            <?php if ($is_enabled): ?>
                                <input type="hidden" name="module_action" value="disable">
                                <button type="submit" class="button button-secondary mads-btn-disable">
                                    <?php _e('Desactivar', 'mad-suite'); ?>
                                </button>
                            <?php else: ?>
                                <input type="hidden" name="module_action" value="enable">
                                <button type="submit" class="button button-primary mads-btn-enable" <?php disabled($has_missing_plugins); ?>>
                                    <?php _e('Activar', 'mad-suite'); ?>
                                </button>
                            <?php endif; ?>

                            <?php if ($is_enabled): ?>
                                <?php
                                $settings_url = admin_url('admin.php?page=' . $module_info['instance']->menu_slug());
                                ?>
                                <a href="<?php echo esc_url($settings_url); ?>" class="button button-secondary">
                                    <?php _e('Configurar', 'mad-suite'); ?>
                                </a>
                            <?php endif; ?>
                        </form>

                        <?php if ($has_missing_plugins && !$is_enabled): ?>
                            <p class="mads-warning-message">
                                <?php _e('⚠️ Instala y activa los plugins requeridos antes de habilitar este módulo.', 'mad-suite'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <div class="mads-stats-box">
        <h3><?php _e('📊 Estadísticas', 'mad-suite'); ?></h3>
        <ul>
            <li>
                <strong><?php _e('Módulos disponibles:', 'mad-suite'); ?></strong>
                <?php echo count($all_modules); ?>
            </li>
            <li>
                <strong><?php _e('Módulos activos:', 'mad-suite'); ?></strong>
                <?php echo count(array_filter($all_modules, function($m) { return $m['enabled']; })); ?>
            </li>
            <li>
                <strong><?php _e('Módulos inactivos:', 'mad-suite'); ?></strong>
                <?php echo count(array_filter($all_modules, function($m) { return !$m['enabled']; })); ?>
            </li>
        </ul>
    </div>
</div>

<style>
<?php include plugin_dir_path(__FILE__) . 'assets/css/modules-manager.css'; ?>
</style>
