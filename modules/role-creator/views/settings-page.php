<?php
if (! defined('ABSPATH')) {
    exit;
}

/** @var array  $roles */
/** @var array  $sample_rows */
/** @var string $download_url */
/** @var string $import_action */
/** @var object $module */
/** @var array  $all_rules */
/** @var string $current_tab */

$tabs = [
    'automatic-rules'    => __('Reglas Automáticas', 'mad-suite'),
    'manual-assign'      => __('Asignación Manual', 'mad-suite'),
    'csv-import'         => __('Importación CSV', 'mad-suite'),
    'role-management'    => __('Gestión de Roles', 'mad-suite'),
    'mailchimp-settings' => __('Mailchimp', 'mad-suite'),
];

$base_url = add_query_arg(['page' => $module->menu_slug()], admin_url('admin.php'));
?>

<div class="wrap mad-role-creator">
    <h1><?php echo esc_html($module->title()); ?></h1>
    <p class="description">
        <?php esc_html_e('Gestiona roles de usuarios de forma automática o manual, crea nuevos roles y asigna usuarios mediante reglas, selección individual o importación masiva.', 'mad-suite'); ?>
    </p>

    <!-- Tabs Navigation -->
    <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e('Pestañas', 'mad-suite'); ?>">
        <?php foreach ($tabs as $tab_key => $tab_label) : ?>
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

    <!-- Tab Content -->
    <div class="tab-content" style="margin-top: 24px;">
        <?php
        switch ($current_tab) {
            case 'automatic-rules':
                include __DIR__ . '/tabs/automatic-rules.php';
                break;

            case 'manual-assign':
                include __DIR__ . '/tabs/manual-assign.php';
                break;

            case 'csv-import':
                include __DIR__ . '/tabs/csv-import.php';
                break;

            case 'role-management':
                include __DIR__ . '/tabs/role-management.php';
                break;

            case 'mailchimp-settings':
                include __DIR__ . '/tabs/mailchimp-settings.php';
                break;

            default:
                include __DIR__ . '/tabs/automatic-rules.php';
                break;
        }
        ?>
    </div>
</div>
