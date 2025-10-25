<?php
if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/ContactImporter.php';
require_once __DIR__ . '/includes/ContactManager.php';
require_once __DIR__ . '/includes/RoleManager.php';

use MAD_Suite\Modules\RoleCreator\ContactImporter;
use MAD_Suite\Modules\RoleCreator\ContactManager;
use MAD_Suite\Modules\RoleCreator\RoleManager;

return new class ($core ?? null) implements MAD_Suite_Module {
    private $core;

    public function __construct($core)
    {
        $this->core = $core;

        add_action('admin_notices', [$this, 'render_admin_notices']);
    }

    public function slug()
    {
        return 'role-creator';
    }

    public function title()
    {
        return __('Importador de contactos', 'mad-suite');
    }

    public function menu_label()
    {
        return __('Importador de contactos', 'mad-suite');
    }

    public function menu_slug()
    {
        return 'mad-suite-role-creator';
    }

    public function init()
    {
    }

    public function admin_init()
    {
        add_action('admin_post_mads_role_creator_import', [$this, 'handle_import']);
        add_action('admin_post_mads_role_creator_download_template', [$this, 'download_template']);
        add_action('admin_post_mads_role_creator_create_role', [$this, 'handle_role_creation']);
    }

    public function render_settings_page()
    {
        $this->ensure_capability();

        $roles        = RoleManager::instance()->get_editable_roles();
        $sample_rows  = ContactImporter::sample_rows();
        $download_url = wp_nonce_url(
            admin_url('admin-post.php?action=mads_role_creator_download_template'),
            'mads_role_creator_download_template',
            'mads_role_creator_nonce'
        );

        $this->render_view('settings-page', [
            'module'        => $this,
            'roles'         => $roles,
            'sample_rows'   => $sample_rows,
            'download_url'  => $download_url,
            'import_action' => admin_url('admin-post.php'),
        ]);
    }

    public function handle_import()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_import', 'mads_role_creator_nonce');

        $selected_role = isset($_POST['mads_role_creator_role']) ? sanitize_key(wp_unslash($_POST['mads_role_creator_role'])) : '';

        if (! $selected_role) {
            $this->add_notice('error', __('Debes seleccionar un rol para asignar a los contactos importados.', 'mad-suite'));
            return $this->redirect_back();
        }

        if (! RoleManager::instance()->role_exists($selected_role)) {
            $this->add_notice('error', __('El rol seleccionado no existe.', 'mad-suite'));
            return $this->redirect_back();
        }

        if (empty($_FILES['mads_role_creator_csv']) || empty($_FILES['mads_role_creator_csv']['tmp_name'])) {
            $this->add_notice('error', __('Debes seleccionar un archivo CSV para importar.', 'mad-suite'));
            return $this->redirect_back();
        }

        $file   = $_FILES['mads_role_creator_csv'];
        $result = ContactImporter::from_uploaded_file($file);

        if (is_wp_error($result)) {
            $this->add_notice('error', $result->get_error_message());
            return $this->redirect_back();
        }

        if (empty($result)) {
            $this->add_notice('error', __('El archivo CSV no contiene filas válidas para importar.', 'mad-suite'));
            return $this->redirect_back();
        }

        $sync = ContactManager::instance()->sync_contacts($result, $selected_role);

        $message = sprintf(
            __('Contactos creados: %1$d. Contactos actualizados: %2$d.', 'mad-suite'),
            (int) $sync['created'],
            (int) $sync['updated']
        );

        $this->add_notice('updated', $message);

        if (! empty($sync['errors'])) {
            $this->add_notice('error', implode(' ', $sync['errors']));
        }

        return $this->redirect_back();
    }

    public function download_template()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_download_template', 'mads_role_creator_nonce');

        $filename = 'contact-import-template-' . date('Ymd-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');
        fputcsv($output, ['email', 'first_name', 'last_name', 'display_name']);
        foreach (ContactImporter::sample_rows() as $row) {
            fputcsv($output, [
                $row['email'],
                $row['first_name'],
                $row['last_name'],
                $row['display_name'],
            ]);
        }
        fclose($output);
        exit;
    }

    public function handle_role_creation()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_create_role', 'mads_role_creator_nonce');

        $role         = isset($_POST['mads_role_creator_new_role']) ? sanitize_key(wp_unslash($_POST['mads_role_creator_new_role'])) : '';
        $display_name = isset($_POST['mads_role_creator_new_role_name']) ? sanitize_text_field(wp_unslash($_POST['mads_role_creator_new_role_name'])) : '';
        $capabilities = isset($_POST['mads_role_creator_new_role_caps']) ? wp_unslash($_POST['mads_role_creator_new_role_caps']) : '';

        if (! $role || ! $display_name) {
            $this->add_notice('error', __('Debes indicar el slug y el nombre visible del nuevo rol.', 'mad-suite'));
            return $this->redirect_back();
        }

        $created = RoleManager::instance()->create_role($role, $display_name, $capabilities);

        if (is_wp_error($created)) {
            $this->add_notice('error', $created->get_error_message());
        } else {
            $this->add_notice('updated', __('El rol se creó correctamente.', 'mad-suite'));
        }

        return $this->redirect_back();
    }

    public function render_admin_notices()
    {
        settings_errors('mads_role_creator');
    }

    private function add_notice($type, $message)
    {
        add_settings_error('mads_role_creator', 'mads_role_creator_' . wp_generate_uuid4(), $message, $type);
    }

    private function ensure_capability()
    {
        if (! current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_die(__('No tienes permisos suficientes para realizar esta acción.', 'mad-suite'));
        }
    }

    private function redirect_back()
    {
        wp_safe_redirect(
            add_query_arg(
                ['page' => $this->menu_slug()],
                admin_url('admin.php')
            )
        );
        exit;
    }
};