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
require_once __DIR__ . '/includes/MailchimpIntegration.php';
require_once __DIR__ . '/includes/Logger.php';

use MAD_Suite\Modules\RoleCreator\ContactImporter;
use MAD_Suite\Modules\RoleCreator\ContactManager;
use MAD_Suite\Modules\RoleCreator\RoleManager;
use MAD_Suite\Modules\RoleCreator\UserRoleAnalyzer;
use MAD_Suite\Modules\RoleCreator\RoleRule;
use MAD_Suite\Modules\RoleCreator\RoleAssigner;
use MAD_Suite\Modules\RoleCreator\MailchimpIntegration;
use MAD_Suite\Modules\RoleCreator\Logger;

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

    public function description()
    {
        return __('Gestiona roles de usuarios automáticamente según compras, productos o condiciones. Incluye integración con Mailchimp y asignación manual de roles.', 'mad-suite');
    }

    public function required_plugins()
    {
        return [
            'WooCommerce' => 'woocommerce/woocommerce.php',
        ];
    }

    public function init()
    {
        $logger = Logger::instance();
        $logger->info('Módulo Role Creator inicializado');

        // Hooks de WooCommerce para evaluación automática
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed']);
        add_action('woocommerce_payment_complete', [$this, 'on_payment_complete']);

        // Hooks de WordPress para detectar cambios de roles
        add_action('set_user_role', [$this, 'on_user_role_changed'], 10, 3);
        add_action('add_user_role', [$this, 'on_user_role_added'], 10, 2);
        add_action('remove_user_role', [$this, 'on_user_role_removed'], 10, 2);

        // Hook para detectar nuevos usuarios registrados
        add_action('user_register', [$this, 'on_user_registered'], 10, 1);

        $logger->debug('Hooks de WooCommerce y WordPress registrados', [
            'woocommerce_hooks' => ['woocommerce_order_status_completed', 'woocommerce_payment_complete'],
            'wordpress_hooks' => ['set_user_role', 'add_user_role', 'remove_user_role', 'user_register'],
        ]);
    }

    public function admin_init()
    {
        // Acciones existentes
        add_action('admin_post_mads_role_creator_import', [$this, 'handle_import']);
        add_action('admin_post_mads_role_creator_download_template', [$this, 'download_template']);
        add_action('admin_post_mads_role_creator_create_role', [$this, 'handle_role_creation']);

        // Nuevas acciones para reglas automáticas
        add_action('admin_post_mads_role_creator_create_rule', [$this, 'handle_create_rule']);
        add_action('admin_post_mads_role_creator_update_rule', [$this, 'handle_update_rule']);
        add_action('admin_post_mads_role_creator_delete_rule', [$this, 'handle_delete_rule']);
        add_action('admin_post_mads_role_creator_toggle_rule', [$this, 'handle_toggle_rule']);
        add_action('admin_post_mads_role_creator_apply_rule', [$this, 'handle_apply_rule']);
        add_action('admin_post_mads_role_creator_apply_all_rules', [$this, 'handle_apply_all_rules']);

        // Acciones para asignación manual
        add_action('admin_post_mads_role_creator_assign_manual', [$this, 'handle_manual_assignment']);

        // Acciones para gestión de roles
        add_action('admin_post_mads_role_creator_delete_role', [$this, 'handle_role_deletion']);

        // Acciones para Mailchimp
        add_action('admin_post_mads_role_creator_mailchimp_save', [$this, 'handle_mailchimp_save']);
        add_action('admin_post_mads_role_creator_mailchimp_test', [$this, 'handle_mailchimp_test']);
        add_action('admin_post_mads_role_creator_mailchimp_sync_all', [$this, 'handle_mailchimp_sync_all']);

        // Acciones para logs
        add_action('admin_post_mads_role_creator_clear_logs', [$this, 'handle_clear_logs']);

        // Registrar AJAX para búsqueda de usuarios
        add_action('wp_ajax_mads_role_creator_search_users', [$this, 'ajax_search_users']);

        // Registrar AJAX para previsualización de reglas
        add_action('wp_ajax_mads_role_creator_preview_rule', [$this, 'ajax_preview_rule']);

        // Registrar AJAX para sincronización manual de Mailchimp
        add_action('wp_ajax_mads_role_creator_sync_user', [$this, 'ajax_sync_user']);
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
        $import_mode = isset($_POST['mads_role_creator_mode']) ? sanitize_key(wp_unslash($_POST['mads_role_creator_mode'])) : 'sync';

        // Validar modo de importación
        if (!in_array($import_mode, ['sync', 'create_only', 'update_only'])) {
            $import_mode = 'sync';
        }

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

        $sync = ContactManager::instance()->sync_contacts($result, $selected_role, $import_mode);

        // Construir mensaje según los resultados
        $message_parts = [];
        if ($sync['created'] > 0) {
            $message_parts[] = sprintf(__('Creados: %d', 'mad-suite'), (int) $sync['created']);
        }
        if ($sync['updated'] > 0) {
            $message_parts[] = sprintf(__('Actualizados: %d', 'mad-suite'), (int) $sync['updated']);
        }
        if ($sync['skipped'] > 0) {
            $message_parts[] = sprintf(__('Saltados: %d', 'mad-suite'), (int) $sync['skipped']);
        }

        $message = __('Importación completada. ', 'mad-suite') . implode(', ', $message_parts) . '.';

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
        $max_spent           = isset($_POST['rule_max_spent']) ? floatval($_POST['rule_max_spent']) : 0;
        $min_orders          = isset($_POST['rule_min_orders']) ? intval($_POST['rule_min_orders']) : 0;
        $max_orders          = isset($_POST['rule_max_orders']) ? intval($_POST['rule_max_orders']) : 0;
        $operator            = isset($_POST['rule_operator']) ? sanitize_key(wp_unslash($_POST['rule_operator'])) : 'AND';

        $result = RoleRule::instance()->create_rule([
            'name'                => $name,
            'role'                => $role,
            'source_role'         => $source_role,
            'replace_source_role' => $replace_source_role,
            'conditions'          => [
                'min_spent'  => $min_spent,
                'max_spent'  => $max_spent,
                'min_orders' => $min_orders,
                'max_orders' => $max_orders,
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
     * Handler para actualizar una regla existente
     */
    public function handle_update_rule()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_update_rule', 'mads_role_creator_nonce');

        $rule_id             = isset($_POST['rule_id']) ? sanitize_text_field(wp_unslash($_POST['rule_id'])) : '';
        $name                = isset($_POST['rule_name']) ? sanitize_text_field(wp_unslash($_POST['rule_name'])) : '';
        $role                = isset($_POST['rule_role']) ? sanitize_key(wp_unslash($_POST['rule_role'])) : '';
        $source_role         = isset($_POST['rule_source_role']) && ! empty($_POST['rule_source_role']) ? sanitize_key(wp_unslash($_POST['rule_source_role'])) : null;
        $replace_source_role = isset($_POST['rule_replace_source_role']) && $_POST['rule_replace_source_role'] === '1';
        $min_spent           = isset($_POST['rule_min_spent']) ? floatval($_POST['rule_min_spent']) : 0;
        $max_spent           = isset($_POST['rule_max_spent']) ? floatval($_POST['rule_max_spent']) : 0;
        $min_orders          = isset($_POST['rule_min_orders']) ? intval($_POST['rule_min_orders']) : 0;
        $max_orders          = isset($_POST['rule_max_orders']) ? intval($_POST['rule_max_orders']) : 0;
        $operator            = isset($_POST['rule_operator']) ? sanitize_key(wp_unslash($_POST['rule_operator'])) : 'AND';

        if (empty($rule_id)) {
            $this->add_notice('error', __('ID de regla no válido.', 'mad-suite'));
            return $this->redirect_back();
        }

        $result = RoleRule::instance()->update_rule($rule_id, [
            'name'                => $name,
            'role'                => $role,
            'source_role'         => $source_role,
            'replace_source_role' => $replace_source_role,
            'conditions'          => [
                'min_spent'  => $min_spent,
                'max_spent'  => $max_spent,
                'min_orders' => $min_orders,
                'max_orders' => $max_orders,
                'operator'   => $operator,
            ],
        ]);

        if (is_wp_error($result)) {
            $this->add_notice('error', $result->get_error_message());
        } else {
            $this->add_notice('updated', __('La regla se actualizó correctamente.', 'mad-suite'));
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

        $logger = Logger::instance();

        $user_ids = isset($_POST['user_ids']) ? array_map('intval', (array) $_POST['user_ids']) : [];
        $role     = isset($_POST['assign_role']) ? sanitize_key(wp_unslash($_POST['assign_role'])) : '';
        $remove   = isset($_POST['remove_existing']) && $_POST['remove_existing'] === '1';

        $logger->info('Asignación manual de rol solicitada', [
            'user_ids' => $user_ids,
            'role' => $role,
            'remove_existing' => $remove,
        ]);

        if (empty($user_ids)) {
            $this->add_notice('error', __('Debes seleccionar al menos un usuario.', 'mad-suite'));
            return $this->redirect_back();
        }

        if (empty($role)) {
            $this->add_notice('error', __('Debes seleccionar un rol.', 'mad-suite'));
            return $this->redirect_back();
        }

        $result = RoleAssigner::instance()->assign_role_to_users($user_ids, $role, $remove);

        // Log de cada usuario asignado
        foreach ($user_ids as $user_id) {
            Logger::instance()->log_role_assignment($user_id, $role, 'manual');
        }

        // Sincronizar cada usuario con Mailchimp
        foreach ($user_ids as $user_id) {
            $this->sync_user_to_mailchimp($user_id);
        }

        $message = sprintf(
            __('Se asignó el rol a %d usuarios correctamente.', 'mad-suite'),
            $result['success']
        );

        $logger->success('Asignación manual completada', [
            'success' => $result['success'],
            'errors' => count($result['errors']),
        ]);

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
        $max_spent   = isset($_POST['max_spent']) ? floatval($_POST['max_spent']) : 0;
        $min_orders  = isset($_POST['min_orders']) ? intval($_POST['min_orders']) : 0;
        $max_orders  = isset($_POST['max_orders']) ? intval($_POST['max_orders']) : 0;
        $operator    = isset($_POST['operator']) ? sanitize_key(wp_unslash($_POST['operator'])) : 'AND';
        $source_role = isset($_POST['source_role']) && ! empty($_POST['source_role']) ? sanitize_key(wp_unslash($_POST['source_role'])) : null;

        // Validar que al menos una condición esté especificada
        if ($min_spent <= 0 && $min_orders <= 0 && $max_spent <= 0 && $max_orders <= 0) {
            wp_send_json_error(['message' => __('Debes especificar al menos una condición (gasto o pedidos).', 'mad-suite')]);
            return;
        }

        // Construir condiciones
        $conditions = [
            'min_spent'  => $min_spent,
            'max_spent'  => $max_spent,
            'min_orders' => $min_orders,
            'max_orders' => $max_orders,
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
     * AJAX handler para sincronizar un usuario específico con Mailchimp
     */
    public function ajax_sync_user()
    {
        $this->ensure_capability();

        // Verificar nonce
        if (! isset($_POST['nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'mads_role_creator_sync')) {
            wp_send_json_error(['message' => __('Verificación de seguridad fallida.', 'mad-suite')]);
            return;
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        if (! $user_id) {
            wp_send_json_error(['message' => __('ID de usuario inválido.', 'mad-suite')]);
            return;
        }

        $logger = Logger::instance();
        $logger->info('Sincronización manual solicitada desde AJAX', ['user_id' => $user_id]);

        $result = $this->sync_user_to_mailchimp($user_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success(['message' => __('Usuario sincronizado exitosamente con Mailchimp.', 'mad-suite')]);
        }
    }

    /**
     * Hook cuando un pedido se completa
     */
    public function on_order_completed($order_id)
    {
        $logger = Logger::instance();
        $logger->debug('Hook on_order_completed ejecutado', ['order_id' => $order_id]);

        $order = wc_get_order($order_id);

        if (! $order) {
            $logger->warning('Pedido no encontrado', ['order_id' => $order_id]);
            return;
        }

        $user_id = $order->get_user_id();

        if ($user_id) {
            $logger->info('Pedido completado, evaluando reglas para usuario', [
                'order_id' => $order_id,
                'user_id' => $user_id,
            ]);
            $this->evaluate_user_rules($user_id);
        } else {
            $logger->debug('Pedido sin usuario asociado', ['order_id' => $order_id]);
        }
    }

    /**
     * Hook cuando un pago se completa
     */
    public function on_payment_complete($order_id)
    {
        $logger = Logger::instance();
        $logger->debug('Hook on_payment_complete ejecutado', ['order_id' => $order_id]);

        $order = wc_get_order($order_id);

        if (! $order) {
            $logger->warning('Pedido no encontrado', ['order_id' => $order_id]);
            return;
        }

        $user_id = $order->get_user_id();

        if ($user_id) {
            $logger->info('Pago completado, evaluando reglas para usuario', [
                'order_id' => $order_id,
                'user_id' => $user_id,
            ]);
            $this->evaluate_user_rules($user_id);
        } else {
            $logger->debug('Pedido sin usuario asociado', ['order_id' => $order_id]);
        }
    }

    /**
     * Hook cuando un rol de usuario cambia
     */
    public function on_user_role_changed($user_id, $role, $old_roles)
    {
        $logger = Logger::instance();
        $logger->info('Rol de usuario cambiado (set_user_role)', [
            'user_id' => $user_id,
            'new_role' => $role,
            'old_roles' => $old_roles,
        ]);

        // Sincronizar con Mailchimp
        $this->sync_user_to_mailchimp($user_id);
    }

    /**
     * Hook cuando se agrega un rol a un usuario
     */
    public function on_user_role_added($user_id, $role)
    {
        $logger = Logger::instance();
        $logger->info('Rol agregado a usuario (add_user_role)', [
            'user_id' => $user_id,
            'role' => $role,
        ]);

        // Sincronizar con Mailchimp
        $this->sync_user_to_mailchimp($user_id);
    }

    /**
     * Hook cuando se remueve un rol de un usuario
     */
    public function on_user_role_removed($user_id, $role)
    {
        $logger = Logger::instance();
        $logger->info('Rol removido de usuario (remove_user_role)', [
            'user_id' => $user_id,
            'role' => $role,
        ]);

        // Sincronizar con Mailchimp
        $this->sync_user_to_mailchimp($user_id);
    }

    /**
     * Hook cuando se registra un nuevo usuario
     */
    public function on_user_registered($user_id)
    {
        $logger = Logger::instance();

        $user = get_userdata($user_id);

        if (! $user) {
            $logger->warning('Usuario recién registrado no encontrado', ['user_id' => $user_id]);
            return;
        }

        $logger->info('Nuevo usuario registrado, sincronizando con Mailchimp', [
            'user_id' => $user_id,
            'email' => $user->user_email,
            'roles' => $user->roles,
            'username' => $user->user_login,
        ]);

        // Sincronizar con Mailchimp (se creará como subscribed)
        $this->sync_user_to_mailchimp($user_id);
    }

    /**
     * Evalúa y aplica reglas para un usuario específico
     */
    private function evaluate_user_rules($user_id)
    {
        $logger = Logger::instance();
        $logger->debug('Evaluando reglas para usuario', ['user_id' => $user_id]);

        $active_rules = RoleRule::instance()->get_active_rules();
        $analyzer     = UserRoleAnalyzer::instance();
        $assigner     = RoleAssigner::instance();

        $logger->info(sprintf('Encontradas %d reglas activas para evaluar', count($active_rules)), ['user_id' => $user_id]);

        $roles_changed = false;

        foreach ($active_rules as $rule) {
            $rule_id = isset($rule['id']) ? $rule['id'] : 'unknown';
            $rule_name = isset($rule['name']) ? $rule['name'] : 'unknown';

            $logger->debug(sprintf('Evaluando regla "%s" (ID: %s)', $rule_name, $rule_id), [
                'user_id' => $user_id,
                'rule_id' => $rule_id,
                'conditions' => $rule['conditions'],
            ]);

            if ($analyzer->user_meets_conditions($user_id, $rule['conditions'])) {
                $logger->info(sprintf('Usuario cumple condiciones de regla "%s"', $rule_name), [
                    'user_id' => $user_id,
                    'rule_id' => $rule_id,
                ]);

                // Verificar si el usuario ya tiene el rol
                if (! $assigner->user_has_role($user_id, $rule['role'])) {
                    $logger->info(sprintf('Asignando rol "%s" a usuario', $rule['role']), [
                        'user_id' => $user_id,
                        'rule_id' => $rule_id,
                        'role' => $rule['role'],
                    ]);

                    $assigner->assign_role_to_users($user_id, $rule['role'], false);
                    $logger->log_role_assignment($user_id, $rule['role'], 'automatic', $rule_id);
                    $roles_changed = true;
                } else {
                    $logger->debug(sprintf('Usuario ya tiene el rol "%s", no se asigna nuevamente', $rule['role']), [
                        'user_id' => $user_id,
                        'role' => $rule['role'],
                    ]);
                }
            } else {
                $logger->debug(sprintf('Usuario NO cumple condiciones de regla "%s"', $rule_name), [
                    'user_id' => $user_id,
                    'rule_id' => $rule_id,
                ]);
            }
        }

        // Sincronizar con Mailchimp si los roles cambiaron
        if ($roles_changed) {
            $logger->info('Roles cambiados, iniciando sincronización con Mailchimp', ['user_id' => $user_id]);
            $this->sync_user_to_mailchimp($user_id);
        } else {
            $logger->debug('No hubo cambios de roles, no se sincroniza con Mailchimp', ['user_id' => $user_id]);
        }
    }

    /**
     * Sincroniza un usuario con Mailchimp
     *
     * @return bool|WP_Error
     */
    private function sync_user_to_mailchimp($user_id)
    {
        $logger = Logger::instance();
        $mailchimp = MailchimpIntegration::instance();

        if (! $mailchimp->is_configured()) {
            $logger->warning('Mailchimp no está configurado, sincronización omitida', ['user_id' => $user_id]);
            return new \WP_Error('not_configured', __('Mailchimp no está configurado.', 'mad-suite'));
        }

        $logger->info('Iniciando sincronización con Mailchimp', ['user_id' => $user_id]);

        $result = $mailchimp->sync_user($user_id);

        if (is_wp_error($result)) {
            $logger->log_mailchimp_sync($user_id, false, $result->get_error_message(), [
                'error_code' => $result->get_error_code(),
            ]);
        } else {
            $logger->log_mailchimp_sync($user_id, true, 'Sincronización exitosa');
        }

        return $result;
    }

    /**
     * Handler para guardar configuración de Mailchimp
     */
    public function handle_mailchimp_save()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_mailchimp_save', 'mads_role_creator_nonce');

        $api_key     = isset($_POST['mailchimp_api_key']) ? sanitize_text_field(wp_unslash($_POST['mailchimp_api_key'])) : '';
        $audience_id = isset($_POST['mailchimp_audience_id']) ? sanitize_text_field(wp_unslash($_POST['mailchimp_audience_id'])) : '';
        $auto_sync   = isset($_POST['mailchimp_auto_sync']) && $_POST['mailchimp_auto_sync'] === '1';

        $settings = [
            'api_key'     => $api_key,
            'audience_id' => $audience_id,
            'auto_sync'   => $auto_sync,
        ];

        MailchimpIntegration::instance()->save_settings($settings);

        $this->add_notice('updated', __('Configuración de Mailchimp guardada correctamente.', 'mad-suite'));

        return $this->redirect_back();
    }

    /**
     * Handler para probar conexión de Mailchimp
     */
    public function handle_mailchimp_test()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_mailchimp_test', 'mads_role_creator_nonce');

        $logger = Logger::instance();
        $logger->info('Test de conexión Mailchimp solicitado desde UI');

        $mailchimp = MailchimpIntegration::instance();

        // Verificar configuración primero
        if (! $mailchimp->is_configured()) {
            $logger->error('Test fallido: Mailchimp no está configurado');
            $this->add_notice('error', __('❌ Mailchimp no está configurado. Por favor configura tu API Key y Audience ID primero.', 'mad-suite'));
            return $this->redirect_back();
        }

        $logger->info('Configuración de Mailchimp detectada, probando conexión...');

        $result = $mailchimp->test_connection();

        if (is_wp_error($result)) {
            $logger->error('Test de conexión falló', [
                'error_code' => $result->get_error_code(),
                'error_message' => $result->get_error_message(),
            ]);
            $this->add_notice('error', sprintf(
                __('❌ Error al conectar con Mailchimp: %s', 'mad-suite'),
                $result->get_error_message()
            ));
        } else {
            $logger->success('Test de conexión exitoso');
            $this->add_notice('updated', __('✅ ¡Conexión con Mailchimp exitosa! Tu configuración es correcta. Revisa los Logs para ver más detalles.', 'mad-suite'));
        }

        return $this->redirect_back();
    }

    /**
     * Handler para sincronizar todos los usuarios con Mailchimp
     */
    public function handle_mailchimp_sync_all()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_mailchimp_sync_all', 'mads_role_creator_nonce');

        // Obtener todos los usuarios
        $users = get_users(['fields' => 'ID']);
        $user_ids = array_map(function($user) {
            return is_object($user) ? $user->ID : $user;
        }, $users);

        $result = MailchimpIntegration::instance()->sync_users_bulk($user_ids);

        $message = sprintf(
            __('Se sincronizaron %d usuarios con Mailchimp correctamente.', 'mad-suite'),
            $result['success']
        );

        $this->add_notice('updated', $message);

        if (! empty($result['errors'])) {
            $error_summary = sprintf(
                __('Errores encontrados: %d', 'mad-suite'),
                count($result['errors'])
            );
            $this->add_notice('error', $error_summary);
        }

        return $this->redirect_back();
    }

    /**
     * Handler para limpiar logs
     */
    public function handle_clear_logs()
    {
        $this->ensure_capability();
        check_admin_referer('mads_role_creator_clear_logs', 'mads_role_creator_nonce');

        Logger::instance()->clear_logs();

        $this->add_notice('updated', __('Logs limpiados correctamente.', 'mad-suite'));

        return $this->redirect_back();
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
            'syncNonce'         => wp_create_nonce('mads_role_creator_sync'),
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
        $args = ['page' => $this->menu_slug()];

        // Preservar el tab actual si existe
        if (isset($_GET['tab'])) {
            $args['tab'] = sanitize_key($_GET['tab']);
        }

        wp_safe_redirect(
            add_query_arg($args, admin_url('admin.php'))
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