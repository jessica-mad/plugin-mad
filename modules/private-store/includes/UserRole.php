<?php
/**
 * User Role Class - MEJORADO
 * 
 * Gestiona el rol de usuario VIP para acceso a tienda privada
 * Incluye importación masiva CSV y gestión avanzada
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

namespace MAD_Suite\Modules\PrivateStore;

if (!defined('ABSPATH')) {
    exit;
}

class UserRole {
    
    private static $instance = null;
    private $role_slug = 'vip_customer';
    private $logger;
    
    /**
     * Singleton instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->logger = new Logger('private-store-roles');
        
        // Hooks básicos
        add_action('init', [$this, 'maybe_create_role']);
        add_filter('user_row_actions', [$this, 'add_user_actions'], 10, 2);
        add_action('admin_post_mads_toggle_vip_access', [$this, 'toggle_vip_access']);
        
        // Columna personalizada en listado de usuarios
        add_filter('manage_users_columns', [$this, 'add_vip_column']);
        add_filter('manage_users_custom_column', [$this, 'render_vip_column'], 10, 3);
        
        // NUEVO: Campo en perfil de usuario
        add_action('show_user_profile', [$this, 'add_user_profile_field']);
        add_action('edit_user_profile', [$this, 'add_user_profile_field']);
        add_action('personal_options_update', [$this, 'save_user_profile_field']);
        add_action('edit_user_profile_update', [$this, 'save_user_profile_field']);
        
        // NUEVO: Handlers AJAX
        add_action('wp_ajax_mads_ps_import_users_csv', [$this, 'ajax_import_users_csv']);
        add_action('wp_ajax_mads_ps_add_vip_users_bulk', [$this, 'ajax_add_vip_users_bulk']);
        add_action('wp_ajax_mads_ps_export_vip_users', [$this, 'ajax_export_vip_users']);
        add_action('wp_ajax_mads_ps_search_users', [$this, 'ajax_search_users']);
        
        // Mensaje de notificación
        add_action('admin_notices', [$this, 'show_admin_notices']);
        
        $this->logger->info('UserRole inicializado con funcionalidades mejoradas');
    }
    
    /**
     * Helper para verificar permisos de administración
     * Permite tanto a administradores como a shop managers gestionar usuarios VIP
     */
    private function has_admin_access() {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }
    
    /**
     * Crear rol VIP
     */
    public function create_role() {
        $customer_role = get_role('customer');
        
        if (!$customer_role) {
            $this->logger->error('Rol customer no encontrado al crear rol VIP');
            return false;
        }
        
        $capabilities = $customer_role->capabilities;
        $capabilities['private_store_access'] = true;
        
        $role_name = get_option('mads_ps_role_name', __('Cliente VIP', 'mad-suite'));
        
        if (get_role($this->role_slug)) {
            $this->logger->warning("Rol VIP ya existe: {$this->role_slug}");
            return false;
        }
        
        $role = add_role($this->role_slug, $role_name, $capabilities);
        
        if ($role) {
            $this->logger->info("Rol VIP creado exitosamente", [
                'role_slug' => $this->role_slug,
                'role_name' => $role_name,
                'capabilities' => array_keys($capabilities)
            ]);
            
            $this->grant_admin_capability();
            return true;
        }
        
        return false;
    }
    
    /**
     * Otorgar capacidad a administradores
     */
    private function grant_admin_capability() {
        $admin_role = get_role('administrator');
        if ($admin_role && !$admin_role->has_cap('private_store_access')) {
            $admin_role->add_cap('private_store_access');
            $this->logger->info('Capacidad private_store_access agregada a administradores');
        }
        
        $shop_manager_role = get_role('shop_manager');
        if ($shop_manager_role && !$shop_manager_role->has_cap('private_store_access')) {
            $shop_manager_role->add_cap('private_store_access');
            $this->logger->info('Capacidad private_store_access agregada a shop_manager');
        }
    }
    
    /**
     * Verificar si el rol existe, si no, crearlo
     */
    public function maybe_create_role() {
        if (!get_role($this->role_slug)) {
            $this->logger->info('Rol VIP no existe, creando...');
            $this->create_role();
        }
    }
    
    /**
     * Actualizar nombre del rol
     */
    public function update_role_name($new_name) {
        global $wp_roles;
        
        if (!isset($wp_roles)) {
            $wp_roles = new \WP_Roles();
        }
        
        if (!isset($wp_roles->roles[$this->role_slug])) {
            $this->logger->error("No se puede actualizar nombre del rol: rol no existe");
            return false;
        }
        
        $old_name = $wp_roles->roles[$this->role_slug]['name'];
        $wp_roles->roles[$this->role_slug]['name'] = $new_name;
        update_option($wp_roles->role_key, $wp_roles->roles);
        
        $this->logger->info("Nombre del rol VIP actualizado", [
            'old_name' => $old_name,
            'new_name' => $new_name
        ]);
        
        return true;
    }
    
    /**
     * Campo en perfil de usuario
     */
    public function add_user_profile_field($user) {
        if (!$this->has_admin_access()) {
            return;
        }
        
        $is_vip = $this->is_vip_user($user->ID);
        $vip_since = get_user_meta($user->ID, '_mads_ps_vip_since', true);
        
        ?>
        <h2><?php _e('Acceso VIP - Tienda Privada', 'mad-suite'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label><?php _e('Estado VIP', 'mad-suite'); ?></label></th>
                <td>
                    <label for="mads_ps_is_vip">
                        <input type="checkbox" 
                               name="mads_ps_is_vip" 
                               id="mads_ps_is_vip" 
                               value="1" 
                               <?php checked($is_vip, true); ?>>
                        <?php _e('Dar acceso VIP a este usuario', 'mad-suite'); ?>
                    </label>
                    
                    <?php if ($is_vip): ?>
                        <p class="description" style="margin-top: 10px;">
                            <span class="dashicons dashicons-star-filled" style="color: #FFD700;"></span>
                            <strong><?php _e('Usuario VIP activo', 'mad-suite'); ?></strong>
                        </p>
                        
                        <?php if ($vip_since): ?>
                            <p class="description">
                                <?php 
                                printf(
                                    __('VIP desde: %s', 'mad-suite'), 
                                    date_i18n(get_option('date_format'), $vip_since)
                                ); 
                                ?>
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="description" style="margin-top: 10px;">
                            <?php _e('Este usuario no tiene acceso VIP', 'mad-suite'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        
        <style>
        #mads_ps_is_vip {
            margin-right: 5px;
        }
        </style>
        <?php
    }
    
    /**
     * Guardar campo de perfil
     */
    public function save_user_profile_field($user_id) {
        if (!$this->has_admin_access()) {
            return;
        }
        
        $was_vip = $this->is_vip_user($user_id);
        $is_vip = isset($_POST['mads_ps_is_vip']) && $_POST['mads_ps_is_vip'] == '1';
        
        // Si cambió el estado
        if ($was_vip !== $is_vip) {
            if ($is_vip) {
                $this->add_vip_access($user_id);
            } else {
                $this->remove_vip_access($user_id);
            }
        }
    }
    
    /**
     * Agregar acciones rápidas en listado de usuarios
     */
    public function add_user_actions($actions, $user) {
        if (!$this->has_admin_access()) {
            return $actions;
        }
        
        if (get_current_user_id() === $user->ID) {
            return $actions;
        }
        
        $user_roles = $user->roles;
        if (empty($user_roles) || (!in_array('customer', $user_roles) && !in_array($this->role_slug, $user_roles))) {
            return $actions;
        }
        
        $is_vip = $this->is_vip_user($user->ID);
        
        $action_url = add_query_arg([
            'action' => 'mads_toggle_vip_access',
            'user_id' => $user->ID,
            'toggle' => $is_vip ? 'remove' : 'add',
            '_wpnonce' => wp_create_nonce('mads_toggle_vip_' . $user->ID)
        ], admin_url('admin-post.php'));
        
        if ($is_vip) {
            $actions['remove_vip'] = sprintf(
                '<a href="%s" style="color: #dc3232;">%s</a>',
                esc_url($action_url),
                __('Quitar acceso VIP', 'mad-suite')
            );
        } else {
            $actions['add_vip'] = sprintf(
                '<a href="%s" style="color: #46b450;">%s</a>',
                esc_url($action_url),
                __('Dar acceso VIP', 'mad-suite')
            );
        }
        
        return $actions;
    }
    
    /**
     * Toggle acceso VIP
     */
    public function toggle_vip_access() {
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $toggle = isset($_GET['toggle']) ? sanitize_key($_GET['toggle']) : '';
        
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mads_toggle_vip_' . $user_id)) {
            wp_die(__('Error de seguridad', 'mad-suite'));
        }
        
        if (!$this->has_admin_access()) {
            wp_die(__('Permisos insuficientes', 'mad-suite'));
        }
        
        $user = get_user_by('id', $user_id);
        
        if (!$user) {
            wp_die(__('Usuario no encontrado', 'mad-suite'));
        }
        
        $success = false;
        $message = '';
        
        if ($toggle === 'add') {
            $success = $this->add_vip_access($user_id);
            $message = $success ? 
                sprintf(__('Acceso VIP otorgado a %s', 'mad-suite'), $user->display_name) :
                sprintf(__('Error al otorgar acceso VIP a %s', 'mad-suite'), $user->display_name);
        } else {
            $success = $this->remove_vip_access($user_id);
            $message = $success ?
                sprintf(__('Acceso VIP removido de %s', 'mad-suite'), $user->display_name) :
                sprintf(__('Error al remover acceso VIP de %s', 'mad-suite'), $user->display_name);
        }
        
        $redirect_url = add_query_arg([
            'mads_ps_message' => urlencode($message),
            'mads_ps_status' => $success ? 'success' : 'error'
        ], admin_url('users.php'));
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Agregar acceso VIP a un usuario
     */
    public function add_vip_access($user_id) {
        $user = get_user_by('id', $user_id);
        
        if (!$user) {
            $this->logger->error("Usuario no encontrado al intentar agregar acceso VIP", ['user_id' => $user_id]);
            return false;
        }
        
        if (!get_role($this->role_slug)) {
            $this->logger->error("Rol VIP no existe al intentar asignar");
            $this->create_role();
        }
        
        // Guardar fecha de inicio VIP
        $vip_since = get_user_meta($user_id, '_mads_ps_vip_since', true);
        if (!$vip_since) {
            update_user_meta($user_id, '_mads_ps_vip_since', time());
        }
        
        $user->set_role($this->role_slug);
        
        $this->logger->info("Acceso VIP otorgado exitosamente", [
            'user_id' => $user_id,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'previous_roles' => $user->roles
        ]);
        
        do_action('mads_ps_vip_access_granted', $user_id, $user);
        
        return true;
    }
    
    /**
     * Remover acceso VIP de un usuario
     */
    public function remove_vip_access($user_id) {
        $user = get_user_by('id', $user_id);
        
        if (!$user) {
            $this->logger->error("Usuario no encontrado al intentar remover acceso VIP", ['user_id' => $user_id]);
            return false;
        }
        
        $user->set_role('customer');
        
        $this->logger->info("Acceso VIP removido exitosamente", [
            'user_id' => $user_id,
            'username' => $user->user_login,
            'email' => $user->user_email
        ]);
        
        do_action('mads_ps_vip_access_revoked', $user_id, $user);
        
        return true;
    }
    
    /**
     * AJAX - Importar usuarios desde CSV
     */
    public function ajax_import_users_csv() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!$this->has_admin_access()) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'mad-suite')]);
        }
        
        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error(['message' => __('No se recibió ningún archivo', 'mad-suite')]);
        }
        
        $file = $_FILES['csv_file'];
        
        // Validar tipo de archivo
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'csv') {
            wp_send_json_error(['message' => __('Solo se permiten archivos CSV', 'mad-suite')]);
        }
        
        // Leer archivo
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error(['message' => __('No se pudo leer el archivo', 'mad-suite')]);
        }
        
        $results = [
            'success' => 0,
            'errors' => 0,
            'skipped' => 0,
            'details' => []
        ];
        
        $row = 0;
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $row++;
            
            // Saltar encabezado
            if ($row === 1) {
                continue;
            }
            
            // Obtener email o username (primera columna)
            $identifier = trim($data[0] ?? '');
            
            if (empty($identifier)) {
                $results['skipped']++;
                continue;
            }
            
            // Buscar usuario por email o username
            $user = false;
            if (is_email($identifier)) {
                $user = get_user_by('email', $identifier);
            } else {
                $user = get_user_by('login', $identifier);
            }
            
            if (!$user) {
                $results['errors']++;
                $results['details'][] = sprintf(
                    __('Fila %d: Usuario "%s" no encontrado', 'mad-suite'),
                    $row,
                    $identifier
                );
                continue;
            }
            
            // Verificar si ya es VIP
            if ($this->is_vip_user($user->ID)) {
                $results['skipped']++;
                $results['details'][] = sprintf(
                    __('Fila %d: %s ya es VIP', 'mad-suite'),
                    $row,
                    $user->display_name
                );
                continue;
            }
            
            // Agregar acceso VIP
            if ($this->add_vip_access($user->ID)) {
                $results['success']++;
                $results['details'][] = sprintf(
                    __('Fila %d: Acceso VIP otorgado a %s', 'mad-suite'),
                    $row,
                    $user->display_name
                );
            } else {
                $results['errors']++;
                $results['details'][] = sprintf(
                    __('Fila %d: Error al procesar %s', 'mad-suite'),
                    $row,
                    $user->display_name
                );
            }
        }
        
        fclose($handle);
        
        $this->logger->info("Importación CSV completada", $results);
        
        wp_send_json_success([
            'message' => sprintf(
                __('Importación completada: %d exitosos, %d errores, %d omitidos', 'mad-suite'),
                $results['success'],
                $results['errors'],
                $results['skipped']
            ),
            'results' => $results
        ]);
    }
    
    /**
     * AJAX - Agregar usuarios VIP en lote
     */
    public function ajax_add_vip_users_bulk() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!$this->has_admin_access()) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'mad-suite')]);
        }
        
        $user_ids = isset($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : [];
        
        if (empty($user_ids)) {
            wp_send_json_error(['message' => __('No se seleccionaron usuarios', 'mad-suite')]);
        }
        
        $results = [
            'success' => 0,
            'errors' => 0,
            'skipped' => 0,
            'details' => []
        ];
        
        foreach ($user_ids as $user_id) {
            $user = get_user_by('id', $user_id);
            
            if (!$user) {
                $results['errors']++;
                continue;
            }
            
            if ($this->is_vip_user($user_id)) {
                $results['skipped']++;
                $results['details'][] = sprintf(
                    __('%s ya es VIP', 'mad-suite'),
                    $user->display_name
                );
                continue;
            }
            
            if ($this->add_vip_access($user_id)) {
                $results['success']++;
                $results['details'][] = sprintf(
                    __('Acceso VIP otorgado a %s', 'mad-suite'),
                    $user->display_name
                );
            } else {
                $results['errors']++;
            }
        }
        
        $this->logger->info("Usuarios VIP agregados en lote", $results);
        
        wp_send_json_success([
            'message' => sprintf(
                __('%d usuarios agregados correctamente', 'mad-suite'),
                $results['success']
            ),
            'results' => $results
        ]);
    }
    
    /**
     * AJAX - Exportar usuarios VIP a CSV
     */
    public function ajax_export_vip_users() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!$this->has_admin_access()) {
            wp_die(__('Permisos insuficientes', 'mad-suite'));
        }
        
        $vip_users = $this->get_vip_users(['number' => -1]);
        
        // Headers para descarga
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="usuarios-vip-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Encabezados
        fputcsv($output, [
            'Usuario',
            'Nombre',
            'Email',
            'VIP desde',
            'Fecha registro'
        ]);
        
        // Datos
        foreach ($vip_users as $user) {
            $vip_since = get_user_meta($user->ID, '_mads_ps_vip_since', true);
            
            fputcsv($output, [
                $user->user_login,
                $user->display_name,
                $user->user_email,
                $vip_since ? date('Y-m-d', $vip_since) : '',
                date('Y-m-d', strtotime($user->user_registered))
            ]);
        }
        
        fclose($output);
        
        $this->logger->info("Usuarios VIP exportados a CSV", [
            'count' => count($vip_users)
        ]);
        
        exit;
    }
    
    /**
     * AJAX - Buscar usuarios
     */
    public function ajax_search_users() {
        check_ajax_referer('mads_private_store', 'nonce');
        
        if (!$this->has_admin_access()) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'mad-suite')]);
        }
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        if (strlen($search) < 2) {
            wp_send_json_success(['users' => []]);
        }
        
        $args = [
            'search' => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => 20,
            'role__in' => ['customer', 'subscriber'],
            'orderby' => 'display_name',
            'order' => 'ASC'
        ];
        
        $users = get_users($args);
        
        $results = [];
        foreach ($users as $user) {
            $is_vip = $this->is_vip_user($user->ID);
            
            $results[] = [
                'id' => $user->ID,
                'username' => $user->user_login,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                'is_vip' => $is_vip
            ];
        }
        
        wp_send_json_success(['users' => $results]);
    }
    
    /**
     * Verificar si un usuario es VIP
     */
    public function is_vip_user($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return false;
        }
        
        $user = get_user_by('id', $user_id);
        
        if (!$user) {
            return false;
        }
        
        return in_array($this->role_slug, $user->roles) || 
               user_can($user_id, 'private_store_access');
    }
    
    /**
     * Obtener todos los usuarios VIP
     */
    public function get_vip_users($args = []) {
        $defaults = [
            'role' => $this->role_slug,
            'orderby' => 'registered',
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $users = get_users($args);
        
        $this->logger->debug("Consulta de usuarios VIP", [
            'total' => count($users),
            'args' => $args
        ]);
        
        return $users;
    }
    
    /**
     * Contar usuarios VIP
     */
    public function count_vip_users() {
        $users = count_users();
        return isset($users['avail_roles'][$this->role_slug]) ? 
               $users['avail_roles'][$this->role_slug] : 0;
    }
    
    /**
     * Agregar columna VIP en listado de usuarios
     */
    public function add_vip_column($columns) {
        $columns['vip_access'] = __('Acceso VIP', 'mad-suite');
        return $columns;
    }
    
    /**
     * Renderizar columna VIP
     */
    public function render_vip_column($output, $column_name, $user_id) {
        if ($column_name !== 'vip_access') {
            return $output;
        }
        
        if ($this->is_vip_user($user_id)) {
            return '<span class="dashicons dashicons-star-filled" style="color: #FFD700;" title="' . 
                   esc_attr__('Usuario VIP', 'mad-suite') . '"></span>';
        }
        
        return '<span class="dashicons dashicons-star-empty" style="color: #ccc;" title="' . 
               esc_attr__('Cliente regular', 'mad-suite') . '"></span>';
    }
    
    /**
     * Mostrar notificaciones en admin
     */
    public function show_admin_notices() {
        if (!isset($_GET['mads_ps_message']) || !isset($_GET['mads_ps_status'])) {
            return;
        }
        
        $message = sanitize_text_field(urldecode($_GET['mads_ps_message']));
        $status = sanitize_key($_GET['mads_ps_status']);
        
        $class = $status === 'success' ? 'notice-success' : 'notice-error';
        
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }
    
    /**
     * Eliminar rol al desactivar (opcional)
     */
    public function remove_role() {
        remove_role($this->role_slug);
        $this->logger->info("Rol VIP eliminado: {$this->role_slug}");
    }
    
    /**
     * Obtener slug del rol
     */
    public function get_role_slug() {
        return $this->role_slug;
    }
}