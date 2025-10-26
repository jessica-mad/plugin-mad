<?php
/**
 * Private Shop Module - Sistema de Cupones por Reglas
 * 
 * Cada regla define su propia configuración de cupones
 * Los cupones se generan automáticamente al login del usuario
 */

namespace MADSuite\Modules\PrivateShop;

class Module {
    
    private static $instance = null;
    private $log_file;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_logs();
        $this->init_hooks();
    }
    
    /**
     * Inicializa el sistema de logs
     */
    private function init_logs() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/mad-suite-logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            file_put_contents($log_dir . '/.htaccess', 'Deny from all');
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
        }
        
        $this->log_file = $log_dir . '/private-shop-' . date('Y-m-d') . '.log';
    }
    
    /**
     * Log optimizado
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $formatted = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);
        error_log($formatted, 3, $this->log_file);
    }
    
    /**
     * Inicializa todos los hooks
     */
    private function init_hooks() {
        // Admin
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_save_private_shop_rule', [$this, 'save_discount_rule']);
        add_action('admin_post_delete_private_shop_rule', [$this, 'delete_discount_rule']);
        add_action('admin_post_toggle_private_shop_rule', [$this, 'toggle_discount_rule']);
        add_action('admin_post_regenerate_user_coupon', [$this, 'admin_regenerate_coupon']);
        add_action('admin_post_delete_user_coupon', [$this, 'admin_delete_coupon']);
        
        // Usuario Login/Logout
        add_action('wp_login', [$this, 'on_user_login'], 10, 2);
        add_action('wp_logout', [$this, 'on_user_logout']);
        
        // Carrito
        add_action('woocommerce_add_to_cart', [$this, 'auto_apply_user_coupon'], 10, 6);
        add_action('woocommerce_before_cart', [$this, 'auto_apply_user_coupon_on_cart']);
        
        // Cupón manual
        add_filter('woocommerce_coupon_is_valid', [$this, 'handle_manual_coupon'], 10, 2);
        
        // Visualización de precio con descuento
        add_filter('woocommerce_get_price_html', [$this, 'show_discount_preview'], 99, 2);
        
        // Estilos
        add_action('wp_head', [$this, 'add_frontend_styles']);
        
        $this->log('Module initialized', 'SUCCESS');
    }
    
    /**
     * Obtiene todas las reglas
     */
    private function get_discount_rules() {
        return get_option('mad_private_shop_rules', []);
    }
    
    /**
     * Obtiene mapeo de cupones por regla
     */
    private function get_rule_coupons() {
        return get_option('mad_private_shop_rule_coupons', []);
    }
    
    /**
     * Guarda mapeo de cupones por regla
     */
    private function save_rule_coupons($rule_coupons) {
        update_option('mad_private_shop_rule_coupons', $rule_coupons);
    }
    
    /**
     * Obtiene la mejor regla para un usuario
     */
    private function get_best_rule_for_user($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return null;
        }
        
        $user_roles = $user->roles;
        if (empty($user_roles)) {
            return null;
        }
        
        $user_role = $user_roles[0]; // Usuario tiene solo 1 rol
        
        $rules = $this->get_discount_rules();
        $applicable_rules = [];
        
        foreach ($rules as $rule) {
            // Solo reglas activas
            if (!isset($rule['enabled']) || !$rule['enabled']) {
                continue;
            }
            
            // Verificar fechas
            if (!empty($rule['date_from']) && strtotime($rule['date_from']) > time()) {
                continue;
            }
            if (!empty($rule['date_to']) && strtotime($rule['date_to']) < time()) {
                continue;
            }
            
            // Verificar rol
            if (!empty($rule['roles']) && in_array($user_role, $rule['roles'])) {
                $applicable_rules[] = $rule;
            }
        }
        
        if (empty($applicable_rules)) {
            return null;
        }
        
        // Ordenar por prioridad (menor = mayor prioridad)
        usort($applicable_rules, function($a, $b) {
            $priority_a = isset($a['priority']) ? intval($a['priority']) : 999;
            $priority_b = isset($b['priority']) ? intval($b['priority']) : 999;
            return $priority_a - $priority_b;
        });
        
        return $applicable_rules[0];
    }
    
    /**
     * Limpia username para usar en cupón
     */
    private function clean_username($username, $max_length = 7) {
        // Quitar extensión de email si existe
        if (strpos($username, '@') !== false) {
            $username = substr($username, 0, strpos($username, '@'));
        }
        
        // Transliterar caracteres especiales
        $unwanted_array = [
            'á'=>'a', 'Á'=>'A', 'é'=>'e', 'É'=>'E', 'í'=>'i', 'Í'=>'I', 'ó'=>'o', 'Ó'=>'O', 'ú'=>'u', 'Ú'=>'U',
            'ñ'=>'n', 'Ñ'=>'N', 'ü'=>'u', 'Ü'=>'U',
        ];
        $username = strtr($username, $unwanted_array);
        
        // Quitar caracteres no alfanuméricos
        $username = preg_replace('/[^a-zA-Z0-9]/', '', $username);
        
        // Lowercase
        $username = strtolower($username);
        
        // Limitar longitud
        if (strlen($username) > $max_length) {
            $username = substr($username, 0, $max_length);
        }
        
        return $username;
    }
    
    /**
     * Genera código de cupón
     */
    private function generate_coupon_code($user_id, $rule) {
        $user = get_userdata($user_id);
        if (!$user) {
            return null;
        }
        
        $prefix = isset($rule['coupon_config']['prefix']) ? $rule['coupon_config']['prefix'] : 'ps';
        $name_length = isset($rule['coupon_config']['name_length']) ? intval($rule['coupon_config']['name_length']) : 7;
        
        $clean_name = $this->clean_username($user->user_login, $name_length);
        
        // Si el nombre queda vacío después de limpiar, usar "user"
        if (empty($clean_name)) {
            $clean_name = 'user';
        }
        
        return strtolower($prefix . '_' . $clean_name . '_' . $user_id);
    }
    
    /**
     * Crea cupón de WooCommerce
     */
    private function create_wc_coupon($code, $rule, $user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        
        // Tipo y valor de descuento
        $discount_type = $rule['discount_type'] === 'percentage' ? 'percent' : 'fixed_cart';
        $coupon->set_discount_type($discount_type);
        $coupon->set_amount($rule['discount_value']);
        
        // Aplicación
        if ($rule['apply_to'] === 'products') {
            $coupon->set_product_ids($rule['target_ids']);
        } else if ($rule['apply_to'] === 'categories') {
            $coupon->set_product_categories($rule['target_ids']);
        } else if ($rule['apply_to'] === 'tags') {
            // Obtener productos con estos tags
            $products = $this->get_products_by_tags($rule['target_ids']);
            $coupon->set_product_ids($products);
        }
        
        // Configuración del cupón desde la regla
        $exclude_sale = isset($rule['coupon_config']['exclude_sale_items']) 
            ? $rule['coupon_config']['exclude_sale_items'] 
            : true;
        $individual = isset($rule['coupon_config']['individual_use']) 
            ? $rule['coupon_config']['individual_use'] 
            : true;
            
        $coupon->set_exclude_sale_items($exclude_sale);
        $coupon->set_individual_use($individual);
        
        // Restricciones de usuario
        $coupon->set_email_restrictions([$user->user_email]);
        $coupon->set_usage_limit(0); // Ilimitado
        $coupon->set_usage_limit_per_user(0); // Ilimitado
        
        // Fechas
        if (!empty($rule['date_to'])) {
            $coupon->set_date_expires(strtotime($rule['date_to']));
        }
        
        // Meta personalizada
        $coupon->update_meta_data('_mad_ps_rule_id', $rule['id']);
        $coupon->update_meta_data('_mad_ps_user_id', $user_id);
        $coupon->update_meta_data('_mad_ps_created', current_time('mysql'));
        
        $coupon->save();
        
        $this->log("Cupón creado: {$code} para usuario {$user->user_login} (ID: {$user_id})", 'SUCCESS');
        
        return $coupon->get_id();
    }
    
    /**
     * Obtiene productos por tags
     */
    private function get_products_by_tags($tag_ids) {
        $products = wc_get_products([
            'limit' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'product_tag',
                    'field' => 'term_id',
                    'terms' => $tag_ids,
                ]
            ],
            'return' => 'ids'
        ]);
        
        return $products;
    }
    
    /**
     * Obtiene cupón activo del usuario
     */
    private function get_user_active_coupon($user_id) {
        $rule_coupons = $this->get_rule_coupons();
        
        foreach ($rule_coupons as $rule_id => $data) {
            if (isset($data['user_coupons'][$user_id])) {
                $coupon_code = $data['user_coupons'][$user_id];
                
                // Verificar que el cupón existe en WC
                $coupon_id = wc_get_coupon_id_by_code($coupon_code);
                if ($coupon_id) {
                    return $coupon_code;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Evento: Usuario hace login
     */
    public function on_user_login($user_login, $user) {
        $user_id = $user->ID;
        
        // Obtener mejor regla para este usuario
        $rule = $this->get_best_rule_for_user($user_id);
        
        if (!$rule) {
            $this->log("Usuario {$user_login} sin reglas aplicables");
            return;
        }
        
        // Verificar si ya tiene cupón
        $existing_coupon = $this->get_user_active_coupon($user_id);
        
        if ($existing_coupon) {
            $this->log("Usuario {$user_login} ya tiene cupón: {$existing_coupon}");
            return;
        }
        
        // Generar código de cupón
        $coupon_code = $this->generate_coupon_code($user_id, $rule);
        
        if (!$coupon_code) {
            $this->log("Error generando código de cupón para usuario {$user_login}", 'ERROR');
            return;
        }
        
        // Crear cupón en WooCommerce
        $coupon_id = $this->create_wc_coupon($coupon_code, $rule, $user_id);
        
        if (!$coupon_id) {
            $this->log("Error creando cupón WC para usuario {$user_login}", 'ERROR');
            return;
        }
        
        // Guardar en mapeo
        $rule_coupons = $this->get_rule_coupons();
        
        if (!isset($rule_coupons[$rule['id']])) {
            $rule_coupons[$rule['id']] = [
                'coupon_ids' => [],
                'user_coupons' => []
            ];
        }
        
        $rule_coupons[$rule['id']]['coupon_ids'][] = $coupon_id;
        $rule_coupons[$rule['id']]['user_coupons'][$user_id] = $coupon_code;
        
        $this->save_rule_coupons($rule_coupons);
        
        $this->log("Sistema completo - Cupón {$coupon_code} asignado a {$user_login}", 'SUCCESS');
    }
    
    /**
     * Evento: Usuario hace logout
     */
    public function on_user_logout() {
        if (!WC()->cart) {
            return;
        }
        
        $applied_coupons = WC()->cart->get_applied_coupons();
        
        foreach ($applied_coupons as $coupon_code) {
            // Remover solo cupones que sean del sistema (formato: prefix_name_id)
            if (preg_match('/^[a-z0-9]+_[a-z0-9]+_\d+$/', $coupon_code)) {
                WC()->cart->remove_coupon($coupon_code);
                $this->log("Cupón {$coupon_code} removido al logout");
            }
        }
    }
    
    /**
     * Auto-aplicar cupón al añadir al carrito
     */
    public function auto_apply_user_coupon() {
        if (!is_user_logged_in() || !WC()->cart) {
            return;
        }
        
        $user_id = get_current_user_id();
        $coupon_code = $this->get_user_active_coupon($user_id);
        
        if (!$coupon_code) {
            return;
        }
        
        // Verificar si ya está aplicado
        $applied_coupons = WC()->cart->get_applied_coupons();
        
        if (in_array($coupon_code, $applied_coupons)) {
            return;
        }
        
        // Aplicar cupón
        WC()->cart->apply_coupon($coupon_code);
        $this->log("Cupón {$coupon_code} aplicado automáticamente");
    }
    
    /**
     * Auto-aplicar cupón en página de carrito
     */
    public function auto_apply_user_coupon_on_cart() {
        $this->auto_apply_user_coupon();
    }
    
    /**
     * Maneja cupón manual vs automático
     */
    public function handle_manual_coupon($valid, $coupon) {
        if (!is_user_logged_in() || !WC()->cart) {
            return $valid;
        }
        
        $manual_code = $coupon->get_code();
        $user_id = get_current_user_id();
        $auto_code = $this->get_user_active_coupon($user_id);
        
        // Si no hay cupón automático, permitir cualquier cupón manual
        if (!$auto_code) {
            return $valid;
        }
        
        // Si el cupón manual ES el automático, permitir
        if ($manual_code === $auto_code) {
            return $valid;
        }
        
        // Comparar descuentos
        $manual_coupon = new \WC_Coupon($manual_code);
        $auto_coupon = new \WC_Coupon($auto_code);
        
        $manual_amount = floatval($manual_coupon->get_amount());
        $auto_amount = floatval($auto_coupon->get_amount());
        
        // Si manual es mejor, remover automático y permitir manual
        if ($manual_amount > $auto_amount) {
            WC()->cart->remove_coupon($auto_code);
            wc_add_notice(
                sprintf('Cupón %s aplicado (%s%% de descuento)', $manual_code, $manual_amount),
                'success'
            );
            return $valid;
        }
        
        // Si automático es mejor o igual, no permitir manual
        wc_add_notice(
            sprintf('Tu cupón actual (%s) ofrece un mejor descuento (%s%%)', $auto_code, $auto_amount),
            'notice'
        );
        
        return false;
    }
    
    /**
     * Muestra preview de descuento en producto
     */
    public function show_discount_preview($price_html, $product) {
        if (!is_user_logged_in()) {
            return $price_html;
        }
        
        $user_id = get_current_user_id();
        $rule = $this->get_best_rule_for_user($user_id);
        
        if (!$rule) {
            return $price_html;
        }
        
        // Verificar si este producto aplica a la regla
        $product_id = $product->get_id();
        $parent_id = $product->get_parent_id();
        $check_id = $parent_id > 0 ? $parent_id : $product_id;
        
        $applies = false;
        
        if ($rule['apply_to'] === 'products') {
            $applies = in_array($check_id, $rule['target_ids']);
        } else if ($rule['apply_to'] === 'categories') {
            $categories = wp_get_post_terms($check_id, 'product_cat', ['fields' => 'ids']);
            $applies = !empty(array_intersect($rule['target_ids'], $categories));
        } else if ($rule['apply_to'] === 'tags') {
            $tags = wp_get_post_terms($check_id, 'product_tag', ['fields' => 'ids']);
            $applies = !empty(array_intersect($rule['target_ids'], $tags));
        }
        
        if (!$applies) {
            return $price_html;
        }
        
        // Calcular precio con descuento
        $regular_price = floatval($product->get_regular_price());
        
        if ($regular_price <= 0) {
            return $price_html;
        }
        
        if ($rule['discount_type'] === 'percentage') {
            $discounted_price = $regular_price * (1 - ($rule['discount_value'] / 100));
            $badge = sprintf('-%.0f%%', $rule['discount_value']);
        } else {
            $discounted_price = $regular_price - $rule['discount_value'];
            $badge = '-' . wc_price($rule['discount_value']);
        }
        
        $discounted_price = max(0, $discounted_price);
        
        $price_html = sprintf(
            '<del><span class="woocommerce-Price-amount amount">%s</span></del> ' .
            '<ins><span class="woocommerce-Price-amount amount">%s</span></ins> ' .
            '<span class="private-shop-badge">%s</span>',
            wc_price($regular_price),
            wc_price($discounted_price),
            $badge
        );
        
        return $price_html;
    }
    
    /**
     * Estilos CSS
     */
    public function add_frontend_styles() {
        ?>
        <style>
        .private-shop-badge {
            display: inline-block;
            background: #4CAF50;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: bold;
            margin-left: 8px;
            vertical-align: middle;
        }
        </style>
        <?php
    }
    
    /**
     * Sincroniza cupones cuando una regla cambia
     */
    public function sync_rule_coupons($rule_id) {
        $rules = $this->get_discount_rules();
        
        if (!isset($rules[$rule_id])) {
            return;
        }
        
        $rule = $rules[$rule_id];
        $rule_coupons = $this->get_rule_coupons();
        
        if (!isset($rule_coupons[$rule_id])) {
            return;
        }
        
        $coupon_ids = $rule_coupons[$rule_id]['coupon_ids'];
        $updated = 0;
        
        foreach ($coupon_ids as $coupon_id) {
            $coupon = new \WC_Coupon($coupon_id);
            
            if (!$coupon->get_id()) {
                continue;
            }
            
            // Actualizar propiedades
            $discount_type = $rule['discount_type'] === 'percentage' ? 'percent' : 'fixed_cart';
            $coupon->set_discount_type($discount_type);
            $coupon->set_amount($rule['discount_value']);
            
            // Actualizar aplicación
            if ($rule['apply_to'] === 'products') {
                $coupon->set_product_ids($rule['target_ids']);
                $coupon->set_product_categories([]);
            } else if ($rule['apply_to'] === 'categories') {
                $coupon->set_product_categories($rule['target_ids']);
                $coupon->set_product_ids([]);
            } else if ($rule['apply_to'] === 'tags') {
                $products = $this->get_products_by_tags($rule['target_ids']);
                $coupon->set_product_ids($products);
                $coupon->set_product_categories([]);
            }
            
            // Actualizar fechas
            if (!empty($rule['date_to'])) {
                $coupon->set_date_expires(strtotime($rule['date_to']));
            } else {
                $coupon->set_date_expires(null);
            }
            
            // Actualizar config
            if (isset($rule['coupon_config'])) {
                $coupon->set_exclude_sale_items($rule['coupon_config']['exclude_sale_items'] ?? true);
                $coupon->set_individual_use($rule['coupon_config']['individual_use'] ?? true);
            }
            
            $coupon->save();
            $updated++;
        }
        
        $this->log("Regla {$rule_id}: {$updated} cupones actualizados", 'SUCCESS');
    }
    
    /**
     * Elimina cupones de una regla
     */
    public function delete_rule_coupons($rule_id) {
        $rule_coupons = $this->get_rule_coupons();
        
        if (!isset($rule_coupons[$rule_id])) {
            return;
        }
        
        $coupon_ids = $rule_coupons[$rule_id]['coupon_ids'];
        $deleted = 0;
        
        foreach ($coupon_ids as $coupon_id) {
            wp_delete_post($coupon_id, true);
            $deleted++;
        }
        
        unset($rule_coupons[$rule_id]);
        $this->save_rule_coupons($rule_coupons);
        
        $this->log("Regla {$rule_id}: {$deleted} cupones eliminados", 'SUCCESS');
    }
    
    /**
     * Menú admin
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mad-suite',
            'Private Shop',
            'Private Shop',
            'manage_options',
            'mad-private-shop',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Renderiza página admin
     */
    public function render_admin_page() {
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        switch ($action) {
            case 'edit':
            case 'new':
                include __DIR__ . '/views/edit-rule.php';
                break;
            case 'coupons':
                include __DIR__ . '/views/coupons-list.php';
                break;
            default:
                include __DIR__ . '/views/rules-list.php';
                break;
        }
    }
    
    /**
     * Guarda regla
     */
    public function save_discount_rule() {
        if (!isset($_POST['private_shop_nonce']) || 
            !wp_verify_nonce($_POST['private_shop_nonce'], 'save_private_shop_rule') ||
            !current_user_can('manage_options')) {
            wp_die('Error de seguridad');
        }
        
        $rules = $this->get_discount_rules();
        
        $rule_id = isset($_POST['rule_id']) && !empty($_POST['rule_id']) 
            ? sanitize_text_field($_POST['rule_id']) 
            : uniqid('rule_');
        
        $is_new = !isset($rules[$rule_id]);
        
        $rule = [
            'id' => $rule_id,
            'name' => sanitize_text_field($_POST['rule_name']),
            'enabled' => isset($_POST['rule_enabled']),
            'discount_type' => sanitize_text_field($_POST['discount_type']),
            'discount_value' => floatval($_POST['discount_value']),
            'apply_to' => sanitize_text_field($_POST['apply_to']),
            'target_ids' => isset($_POST['target_ids']) ? array_map('intval', $_POST['target_ids']) : [],
            'roles' => isset($_POST['roles']) ? array_map('sanitize_text_field', $_POST['roles']) : [],
            'priority' => isset($_POST['priority']) ? intval($_POST['priority']) : 10,
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
            'coupon_config' => [
                'prefix' => sanitize_text_field($_POST['coupon_prefix'] ?? 'ps'),
                'name_length' => intval($_POST['coupon_name_length'] ?? 7),
                'exclude_sale_items' => isset($_POST['exclude_sale_items']),
                'individual_use' => isset($_POST['individual_use']),
            ]
        ];
        
        $rules[$rule_id] = $rule;
        update_option('mad_private_shop_rules', $rules);
        
        // Sincronizar cupones si es edición
        if (!$is_new) {
            $this->sync_rule_coupons($rule_id);
        }
        
        $this->log("Regla guardada: {$rule['name']}", 'SUCCESS');
        
        wp_redirect(add_query_arg([
            'page' => 'mad-private-shop',
            'saved' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Elimina regla
     */
    public function delete_discount_rule() {
        if (!isset($_GET['nonce']) || 
            !wp_verify_nonce($_GET['nonce'], 'delete_rule') ||
            !current_user_can('manage_options')) {
            wp_die('Error de seguridad');
        }
        
        $rule_id = sanitize_text_field($_GET['rule_id']);
        
        // Eliminar cupones asociados
        $this->delete_rule_coupons($rule_id);
        
        // Eliminar regla
        $rules = $this->get_discount_rules();
        unset($rules[$rule_id]);
        update_option('mad_private_shop_rules', $rules);
        
        $this->log("Regla {$rule_id} eliminada", 'SUCCESS');
        
        wp_redirect(add_query_arg([
            'page' => 'mad-private-shop',
            'deleted' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Activa/desactiva regla
     */
    public function toggle_discount_rule() {
        if (!isset($_GET['nonce']) || 
            !wp_verify_nonce($_GET['nonce'], 'toggle_rule') ||
            !current_user_can('manage_options')) {
            wp_die('Error de seguridad');
        }
        
        $rule_id = sanitize_text_field($_GET['rule_id']);
        $rules = $this->get_discount_rules();
        
        if (isset($rules[$rule_id])) {
            $rules[$rule_id]['enabled'] = !$rules[$rule_id]['enabled'];
            update_option('mad_private_shop_rules', $rules);
            
            // Si se desactiva, eliminar cupones
            if (!$rules[$rule_id]['enabled']) {
                $this->delete_rule_coupons($rule_id);
            }
        }
        
        wp_redirect(add_query_arg(['page' => 'mad-private-shop'], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Regenera cupón de usuario
     */
    public function admin_regenerate_coupon() {
        if (!isset($_GET['nonce']) || 
            !wp_verify_nonce($_GET['nonce'], 'regenerate_coupon') ||
            !current_user_can('manage_options')) {
            wp_die('Error de seguridad');
        }
        
        $user_id = intval($_GET['user_id']);
        
        // Eliminar cupón actual
        $coupon_code = $this->get_user_active_coupon($user_id);
        if ($coupon_code) {
            $coupon_id = wc_get_coupon_id_by_code($coupon_code);
            wp_delete_post($coupon_id, true);
        }
        
        // Crear nuevo
        $user = get_userdata($user_id);
        $this->on_user_login($user->user_login, $user);
        
        wp_redirect(add_query_arg([
            'page' => 'mad-private-shop',
            'action' => 'coupons',
            'regenerated' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Elimina cupón de usuario
     */
    public function admin_delete_coupon() {
        if (!isset($_GET['nonce']) || 
            !wp_verify_nonce($_GET['nonce'], 'delete_coupon') ||
            !current_user_can('manage_options')) {
            wp_die('Error de seguridad');
        }
        
        $user_id = intval($_GET['user_id']);
        $coupon_code = $this->get_user_active_coupon($user_id);
        
        if ($coupon_code) {
            $coupon_id = wc_get_coupon_id_by_code($coupon_code);
            wp_delete_post($coupon_id, true);
            
            // Limpiar mapeo
            $rule_coupons = $this->get_rule_coupons();
            foreach ($rule_coupons as $rule_id => $data) {
                if (isset($data['user_coupons'][$user_id])) {
                    unset($rule_coupons[$rule_id]['user_coupons'][$user_id]);
                    $rule_coupons[$rule_id]['coupon_ids'] = array_diff(
                        $rule_coupons[$rule_id]['coupon_ids'], 
                        [$coupon_id]
                    );
                }
            }
            $this->save_rule_coupons($rule_coupons);
        }
        
        wp_redirect(add_query_arg([
            'page' => 'mad-private-shop',
            'action' => 'coupons',
            'deleted_coupon' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    public function get_log_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/mad-suite-logs/private-shop-' . date('Y-m-d') . '.log';
    }
}

// Inicializar módulo
Module::instance();