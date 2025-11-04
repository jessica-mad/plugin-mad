<?php
if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/ContactImporter.php';
require_once __DIR__ . '/includes/ContactManager.php';
require_once __DIR__ . '/includes/RoleManager.php';
require_once __DIR__ . '/includes/UserRoleAnalyzer.php';
require_once __DIR__ . '/includes/RoleRule.php';
require_once __DIR__ . '/includes/RoleAssigner.php';

use MAD_Suite\Modules\RoleCreator\ContactImporter;
use MAD_Suite\Modules\RoleCreator\ContactManager;
use MAD_Suite\Modules\RoleCreator\RoleManager;
use MAD_Suite\Modules\RoleCreator\UserRoleAnalyzer;
use MAD_Suite\Modules\RoleCreator\RoleRule;
use MAD_Suite\Modules\RoleCreator\RoleAssigner;

return new class ($core ?? null) implements MAD_Suite_Module {
    private $core;

    public function __construct($core)
    {
        $this->core = $core;

        add_action('admin_notices', [$this, 'render_admin_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function slug()
    {
        return 'role-creator';
    }

    public function title()
    {
        return __('Gestor de Roles', 'mad-suite');
    }

    public function menu_label()
    {
        return __('Gestor de Roles', 'mad-suite');
    }

    public function menu_slug()
    {
        return 'mad-suite-role-creator';
    }

    public function init()
    {
        // Hooks de WooCommerce para evaluación automática
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed']);
        add_action('woocommerce_payment_complete', [$this, 'on_payment_complete']);
    }

    public function admin_init()
    {
        // Acciones existentes
        add_action('admin_post_mads_role_creator_import', [$this, 'handle_import']);
        add_action('admin_post_mads_role_creator_download_template', [$this, 'download_template']);
        add_action('admin_post_mads_role_creator_create_role', [$this, 'handle_role_creation']);

        // Nuevas acciones para reglas automáticas
        add_action('admin_post_mads_role_creator_create_rule', [$this, 'handle_create_rule']);
        add_action('admin_post_mads_role_creator_delete_rule', [$this, 'handle_delete_rule']);
        add_action('admin_post_mads_role_creator_toggle_rule', [$this, 'handle_toggle_rule']);
        add_action('admin_post_mads_role_creator_apply_rule', [$this, 'handle_apply_rule']);
        add_action('admin_post_mads_role_creator_apply_all_rules', [$this, 'handle_apply_all_rules']);

        // Acciones para asignación manual
        add_action('admin_post_mads_role_creator_assign_manual', [$this, 'handle_manual_assignment']);

        // Acciones para gestión de roles
        add_action('admin_post_mads_role_creator_delete_role', [$this, 'handle_role_deletion']);

        // Registrar AJAX para búsqueda de usuarios
        add_action('wp_ajax_mads_role_creator_search_users', [$this, 'ajax_search_users']);

        // Registrar AJAX para previsualización de reglas
        add_action('wp_ajax_mads_role_creator_preview_rule', [$this, 'ajax_preview_rule']);
    }

    public function render_settings_page()
    {
        $this->ensure_capability();

        $roles        = RoleManager::instance()->get_editable_roles();
        $sample_rows  = ContactImporter::sample_rows();
        $all_rules    = RoleRule::instance()->get_all_rules();
        $current_tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'automatic-rules';

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
            'all_rules'     => $all_rules,
            'current_tab'   => $current_tab,
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

    /**
     * Handler para crear una nueva regla automática
     */
    public function handle_create_rule()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_create_rule', 'mads_role_creator_nonce');

        $name                = isset($_POST['rule_name']) ? sanitize_text_field(wp_unslash($_POST['rule_name'])) : '';
        $role                = isset($_POST['rule_role']) ? sanitize_key(wp_unslash($_POST['rule_role'])) : '';
        $source_role         = isset($_POST['rule_source_role']) && ! empty($_POST['rule_source_role']) ? sanitize_key(wp_unslash($_POST['rule_source_role'])) : null;
        $replace_source_role = isset($_POST['rule_replace_source_role']) && $_POST['rule_replace_source_role'] === '1';
        $min_spent           = isset($_POST['rule_min_spent']) ? floatval($_POST['rule_min_spent']) : 0;
        $min_orders          = isset($_POST['rule_min_orders']) ? intval($_POST['rule_min_orders']) : 0;
        $operator            = isset($_POST['rule_operator']) ? sanitize_key(wp_unslash($_POST['rule_operator'])) : 'AND';

        $result = RoleRule::instance()->create_rule([
            'name'                => $name,
            'role'                => $role,
            'source_role'         => $source_role,
            'replace_source_role' => $replace_source_role,
            'conditions'          => [
                'min_spent'  => $min_spent,
                'min_orders' => $min_orders,
                'operator'   => $operator,
            ],
            'active'              => true,
        ]);

        if (is_wp_error($result)) {
            $this->add_notice('error', $result->get_error_message());
        } else {
            $this->add_notice('updated', __('La regla se creó correctamente.', 'mad-suite'));
        }

        return $this->redirect_back();
    }

    /**
     * Handler para eliminar una regla
     */
    public function handle_delete_rule()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_delete_rule', 'mads_role_creator_nonce');

        $rule_id = isset($_GET['rule_id']) ? sanitize_text_field(wp_unslash($_GET['rule_id'])) : '';

        $result = RoleRule::instance()->delete_rule($rule_id);

        if (is_wp_error($result)) {
            $this->add_notice('error', $result->get_error_message());
        } else {
            $this->add_notice('updated', __('La regla se eliminó correctamente.', 'mad-suite'));
        }

        return $this->redirect_back();
    }

    /**
     * Handler para alternar el estado de una regla
     */
    public function handle_toggle_rule()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_toggle_rule', 'mads_role_creator_nonce');

        $rule_id = isset($_GET['rule_id']) ? sanitize_text_field(wp_unslash($_GET['rule_id'])) : '';

        $result = RoleRule::instance()->toggle_rule_status($rule_id);

        if (is_wp_error($result)) {
            $this->add_notice('error', $result->get_error_message());
        } else {
            $this->add_notice('updated', __('El estado de la regla se actualizó.', 'mad-suite'));
        }

        return $this->redirect_back();
    }

    /**
     * Handler para aplicar una regla específica
     */
    public function handle_apply_rule()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_apply_rule', 'mads_role_creator_nonce');

        $rule_id = isset($_GET['rule_id']) ? sanitize_text_field(wp_unslash($_GET['rule_id'])) : '';

        $result = RoleAssigner::instance()->apply_single_rule($rule_id);

        if (is_wp_error($result)) {
            $this->add_notice('error', $result->get_error_message());
        } else {
            $message = isset($result['message']) ? $result['message'] : __('Regla aplicada correctamente.', 'mad-suite');
            $this->add_notice('updated', $message);

            if (! empty($result['errors'])) {
                $this->add_notice('error', implode(' ', $result['errors']));
            }
        }

        return $this->redirect_back();
    }

    /**
     * Handler para aplicar todas las reglas activas
     */
    public function handle_apply_all_rules()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_apply_all_rules', 'mads_role_creator_nonce');

        $result = RoleAssigner::instance()->apply_automatic_rules();

        $message = sprintf(
            __('Se procesaron %1$d reglas y se asignaron roles a %2$d usuarios.', 'mad-suite'),
            $result['rules_processed'],
            $result['assigned']
        );

        $this->add_notice('updated', $message);

        if (! empty($result['errors'])) {
            $this->add_notice('error', implode(' ', $result['errors']));
        }

        return $this->redirect_back();
    }

    /**
     * Handler para asignación manual de rol
     */
    public function handle_manual_assignment()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_assign_manual', 'mads_role_creator_nonce');

        $user_ids = isset($_POST['user_ids']) ? array_map('intval', (array) $_POST['user_ids']) : [];
        $role     = isset($_POST['assign_role']) ? sanitize_key(wp_unslash($_POST['assign_role'])) : '';
        $remove   = isset($_POST['remove_existing']) && $_POST['remove_existing'] === '1';

        if (empty($user_ids)) {
            $this->add_notice('error', __('Debes seleccionar al menos un usuario.', 'mad-suite'));
            return $this->redirect_back();
        }

        if (empty($role)) {
            $this->add_notice('error', __('Debes seleccionar un rol.', 'mad-suite'));
            return $this->redirect_back();
        }

        $result = RoleAssigner::instance()->assign_role_to_users($user_ids, $role, $remove);

        $message = sprintf(
            __('Se asignó el rol a %d usuarios correctamente.', 'mad-suite'),
            $result['success']
        );

        $this->add_notice('updated', $message);

        if (! empty($result['errors'])) {
            $this->add_notice('error', implode(' ', $result['errors']));
        }

        return $this->redirect_back();
    }

    /**
     * Handler para eliminar un rol
     */
    public function handle_role_deletion()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_delete_role', 'mads_role_creator_nonce');

        $role = isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : '';

        $result = RoleManager::instance()->delete_role($role);

        if (is_wp_error($result)) {
            $this->add_notice('error', $result->get_error_message());
        } else {
            $this->add_notice('updated', __('El rol se eliminó correctamente.', 'mad-suite'));
        }

        return $this->redirect_back();
    }

    /**
     * AJAX handler para buscar usuarios
     */
    public function ajax_search_users()
    {
        $this->ensure_capability();

        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

        if (empty($search)) {
            wp_send_json([]);
            return;
        }

        $users = get_users([
            'search'         => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number'         => 20,
            'fields'         => ['ID', 'user_email', 'display_name'],
        ]);

        $results = [];
        foreach ($users as $user) {
            $results[] = [
                'id'   => $user->ID,
                'text' => sprintf('%s (%s)', $user->display_name, $user->user_email),
            ];
        }

        wp_send_json($results);
    }

    /**
     * AJAX handler para previsualizar impacto de una regla
     */
    public function ajax_preview_rule()
    {
        $this->ensure_capability();

        // Verificar nonce
        if (! isset($_POST['nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mads_role_creator_preview')) {
            wp_send_json_error(['message' => __('Verificación de seguridad fallida.', 'mad-suite')]);
            return;
        }

        // Obtener parámetros
        $min_spent   = isset($_POST['min_spent']) ? floatval($_POST['min_spent']) : 0;
        $min_orders  = isset($_POST['min_orders']) ? intval($_POST['min_orders']) : 0;
        $operator    = isset($_POST['operator']) ? sanitize_key(wp_unslash($_POST['operator'])) : 'AND';
        $source_role = isset($_POST['source_role']) && ! empty($_POST['source_role']) ? sanitize_key(wp_unslash($_POST['source_role'])) : null;

        // Validar que al menos una condición esté especificada
        if ($min_spent <= 0 && $min_orders <= 0) {
            wp_send_json_error(['message' => __('Debes especificar al menos una condición (gasto o pedidos).', 'mad-suite')]);
            return;
        }

        // Construir condiciones
        $conditions = [
            'min_spent'  => $min_spent,
            'min_orders' => $min_orders,
            'operator'   => strtoupper($operator),
        ];

        // Obtener preview
        $analyzer = UserRoleAnalyzer::instance();
        $preview  = $analyzer->preview_rule_impact($conditions, $source_role, 10);

        // Agregar información formateada para mostrar
        $preview['formatted'] = [
            'total_text'    => sprintf(
                _n('%d usuario cumple las condiciones', '%d usuarios cumplen las condiciones', $preview['total'], 'mad-suite'),
                $preview['total']
            ),
            'eligible_text' => $source_role
                ? sprintf(
                    _n('%d usuario tiene el rol de origen requerido', '%d usuarios tienen el rol de origen requerido', $preview['eligible'], 'mad-suite'),
                    $preview['eligible']
                )
                : '',
        ];

        wp_send_json_success($preview);
    }

    /**
     * Hook cuando un pedido se completa
     */
    public function on_order_completed($order_id)
    {
        $order = wc_get_order($order_id);

        if (! $order) {
            return;
        }

        $user_id = $order->get_user_id();

        if ($user_id) {
            $this->evaluate_user_rules($user_id);
        }
    }

    /**
     * Hook cuando un pago se completa
     */
    public function on_payment_complete($order_id)
    {
        $order = wc_get_order($order_id);

        if (! $order) {
            return;
        }

        $user_id = $order->get_user_id();

        if ($user_id) {
            $this->evaluate_user_rules($user_id);
        }
    }

    /**
     * Evalúa y aplica reglas para un usuario específico
     */
    private function evaluate_user_rules($user_id)
    {
        $active_rules = RoleRule::instance()->get_active_rules();
        $analyzer     = UserRoleAnalyzer::instance();
        $assigner     = RoleAssigner::instance();

        foreach ($active_rules as $rule) {
            if ($analyzer->user_meets_conditions($user_id, $rule['conditions'])) {
                // Verificar si el usuario ya tiene el rol
                if (! $assigner->user_has_role($user_id, $rule['role'])) {
                    $assigner->assign_role_to_users($user_id, $rule['role'], false);
                }
            }
        }
    }

    /**
     * Encola assets (CSS y JS) para el panel de administración
     */
    public function enqueue_admin_assets($hook)
    {
        // Solo cargar en la página del módulo
        if (strpos($hook, $this->menu_slug()) === false) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'mads-role-creator-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            '1.0.0'
        );

        // Select2 (si está disponible en WordPress)
        wp_enqueue_style('select2');
        wp_enqueue_script('select2');

        // JavaScript
        wp_enqueue_script(
            'mads-role-creator-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            ['jquery', 'select2'],
            '1.0.0',
            true
        );

        // Localización para JavaScript
        wp_localize_script('mads-role-creator-admin', 'madsRoleCreatorL10n', [
            'searchPlaceholder' => __('Buscar usuarios...', 'mad-suite'),
            'inputTooShort'     => __('Escribe al menos 2 caracteres para buscar', 'mad-suite'),
            'searching'         => __('Buscando...', 'mad-suite'),
            'noResults'         => __('No se encontraron usuarios', 'mad-suite'),
            'errorLoading'      => __('No se pudieron cargar los resultados', 'mad-suite'),
            'previewNonce'      => wp_create_nonce('mads_role_creator_preview'),
            'ajaxUrl'           => admin_url('admin-ajax.php'),
        ]);
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

    /**
     * Renderiza una vista desde el directorio views/
     *
     * @param string $view_name Nombre del archivo sin extensión
     * @param array  $data      Variables a extraer en la vista
     * @return void
     */
    private function render_view($view_name, $data = [])
    {
        $view_file = __DIR__ . '/views/' . $view_name . '.php';

        if (! file_exists($view_file)) {
            wp_die(sprintf(__('La vista %s no existe.', 'mad-suite'), esc_html($view_name)));
        }

        extract($data, EXTR_SKIP);
        include $view_file;
    }
};