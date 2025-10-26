<?php
/**
 * Private Shop Module - Sistema de Reglas de Descuento
 * 
 * Estructura de una regla de descuento:
 * 
 * [
 *   'id' => 'unique_id',
 *   'name' => 'Black Friday VIP',
 *   'enabled' => true,
 *   'discount_type' => 'percentage', // 'percentage' o 'fixed'
 *   'discount_value' => 20,
 *   'apply_to' => 'categories', // 'products', 'categories', 'tags'
 *   'target_ids' => [12, 15, 23], // IDs de productos, categorías o tags
 *   'roles' => ['customer', 'subscriber'],
 *   'priority' => 10, // Menor número = mayor prioridad
 *   'date_from' => '2025-10-01', // Opcional
 *   'date_to' => '2025-10-31', // Opcional
 * ]
 */

namespace MADSuite\Modules\PrivateShop;

class Module {
    
    private static $instance = null;
    private $log_file;
    private $active_rules_cache = null;
    private $processing_cart = false;
    private $price_cache = [];
    
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
     * Log con formato legible (optimizado)
     */
    private function log($message, $level = 'INFO') {
        // Solo logear en modo debug o eventos críticos
        if ($level === 'SUCCESS' || $level === 'ERROR' || $level === 'WARNING') {
            $timestamp = date('Y-m-d H:i:s');
            $formatted = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);
            error_log($formatted, 3, $this->log_file);
        }
    }
    
    /**
     * Helper: Obtener ID de cupón por código (compatible con todas las versiones)
     */
    private function get_coupon_id_by_code($code) {
        $coupon_post = get_page_by_title($code, OBJECT, 'shop_coupon');
        return $coupon_post ? $coupon_post->ID : 0;
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
        
        // Frontend - Descuentos
        add_filter('woocommerce_product_get_price', [$this, 'apply_discount_to_price'], 99, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'apply_discount_to_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'apply_discount_to_price'], 99, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'apply_discount_to_price'], 99, 2);
        
        // Carrito - CRÍTICO
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_discount_to_cart'], 99);
        
        // Display
        add_filter('woocommerce_get_price_html', [$this, 'custom_price_html'], 99, 2);
        
        // Estilos frontend
        add_action('wp_head', [$this, 'add_frontend_styles']);
        
        $this->log('Module initialized successfully');
    }
    
    /**
     * Obtiene todas las reglas de descuento
     */
    private function get_discount_rules() {
        return get_option('mad_private_shop_rules', []);
    }
    
    /**
     * Obtiene reglas activas para el usuario actual (con caché)
     */
    private function get_active_rules_for_user() {
        // Usar caché si ya se calculó en esta request
        if ($this->active_rules_cache !== null) {
            return $this->active_rules_cache;
        }
        
        if (!is_user_logged_in()) {
            $this->active_rules_cache = [];
            return [];
        }
        
        $all_rules = $this->get_discount_rules();
        $user = wp_get_current_user();
        $active_rules = [];
        
        foreach ($all_rules as $rule) {
            // Verificar si está habilitada
            if (!isset($rule['enabled']) || !$rule['enabled']) {
                continue;
            }
            
            // Verificar fechas (si están configuradas)
            if (isset($rule['date_from']) && !empty($rule['date_from'])) {
                if (strtotime($rule['date_from']) > time()) {
                    continue;
                }
            }
            if (isset($rule['date_to']) && !empty($rule['date_to'])) {
                if (strtotime($rule['date_to']) < time()) {
                    continue;
                }
            }
            
            // Verificar rol del usuario
            if (!empty($rule['roles'])) {
                $has_role = false;
                foreach ($rule['roles'] as $role) {
                    if (in_array($role, $user->roles)) {
                        $has_role = true;
                        break;
                    }
                }
                if (!$has_role) {
                    continue;
                }
            }
            
            $active_rules[] = $rule;
        }
        
        // Ordenar por prioridad (menor = mayor prioridad)
        usort($active_rules, function($a, $b) {
            $priority_a = isset($a['priority']) ? intval($a['priority']) : 999;
            $priority_b = isset($b['priority']) ? intval($b['priority']) : 999;
            return $priority_a - $priority_b;
        });
        
        // Guardar en caché para esta request
        $this->active_rules_cache = $active_rules;
        
        return $active_rules;
    }
    
    /**
     * Obtiene el descuento aplicable a un producto
     * Retorna: ['type' => 'percentage'|'fixed', 'value' => float, 'rule_name' => string]
     */
    private function get_product_discount($product_id) {
        $active_rules = $this->get_active_rules_for_user();
        
        if (empty($active_rules)) {
            return null;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }
        
        // Buscar la primera regla que aplique (ya están ordenadas por prioridad)
        foreach ($active_rules as $rule) {
            $applies = false;
            
            switch ($rule['apply_to']) {
                case 'products':
                    // Descuento por productos específicos
                    if (in_array($product_id, $rule['target_ids'])) {
                        $applies = true;
                    }
                    break;
                    
                case 'categories':
                    // Descuento por categorías
                    $product_categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
                    if (array_intersect($rule['target_ids'], $product_categories)) {
                        $applies = true;
                    }
                    break;
                    
                case 'tags':
                    // Descuento por etiquetas
                    $product_tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'ids']);
                    if (array_intersect($rule['target_ids'], $product_tags)) {
                        $applies = true;
                    }
                    break;
            }
            
            if ($applies) {
                return [
                    'type' => $rule['discount_type'],
                    'value' => floatval($rule['discount_value']),
                    'rule_name' => $rule['name'],
                    'rule_id' => $rule['id']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Calcula el precio con descuento
     */
    private function calculate_discounted_price($original_price, $discount) {
        if (!$discount) {
            return $original_price;
        }
        
        if ($discount['type'] === 'percentage') {
            return $original_price * (1 - ($discount['value'] / 100));
        } else {
            // Descuento fijo
            $new_price = $original_price - $discount['value'];
            return max(0, $new_price); // No puede ser negativo
        }
    }
    
    /**
     * Aplica descuento al precio del producto (con caché y protección)
     */
    public function apply_discount_to_price($price, $product) {
        // Solo en frontend
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }
        
        // Evitar recursión infinita
        static $processing = [];
        $product_id = $product->get_id();
        
        if (isset($processing[$product_id])) {
            return $price;
        }
        
        $processing[$product_id] = true;
        
        // Usar caché si ya calculamos este precio
        if (isset($this->price_cache[$product_id])) {
            unset($processing[$product_id]);
            return $this->price_cache[$product_id];
        }
        
        $discount = $this->get_product_discount($product_id);
        
        if ($discount) {
            // Usar get_regular_price() del producto
            $original_price = floatval($product->get_regular_price());
            
            // Fallback al parámetro $price
            if ($original_price <= 0) {
                $original_price = floatval($price);
            }
            
            // Validación de precio válido
            if ($original_price <= 0) {
                unset($processing[$product_id]);
                return $price;
            }
            
            $discounted_price = $this->calculate_discounted_price($original_price, $discount);
            
            // Guardar en caché
            $this->price_cache[$product_id] = $discounted_price;
            
            unset($processing[$product_id]);
            return $discounted_price;
        }
        
        unset($processing[$product_id]);
        return $price;
    }
    
    /**
     * Aplica descuento en el carrito
     * CRÍTICO: Este hook hace que funcione en carrito y checkout
     */
    public function apply_discount_to_cart($cart) {
        // Solo en frontend
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        // CRÍTICO: Evitar ejecución múltiple
        if ($this->processing_cart) {
            return;
        }
        
        $this->processing_cart = true;
        
        // Evitar bucles infinitos adicional
        static $run_count = 0;
        $run_count++;
        
        if ($run_count > 1) {
            $this->processing_cart = false;
            return;
        }
        
        $items_modified = 0;
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $product->get_id();
            
            // Si es variación, obtener el ID del padre para buscar descuento
            $parent_id = $product->get_parent_id();
            $search_id = $parent_id > 0 ? $parent_id : $product_id;
            
            $discount = $this->get_product_discount($search_id);
            
            if ($discount) {
                // Obtener precio original
                $original_price = floatval($product->get_regular_price());
                
                if ($original_price <= 0) {
                    continue;
                }
                
                // Calcular precio con descuento
                $discounted_price = $this->calculate_discounted_price($original_price, $discount);
                
                // IMPORTANTE: set_price modifica el precio del producto en el carrito
                $cart_item['data']->set_price($discounted_price);
                
                $items_modified++;
            }
        }
        
        $this->processing_cart = false;
    }
    
    /**
     * Muestra el precio con descuento en el frontend (simplificado)
     */
    public function custom_price_html($price_html, $product) {
        // Evitar recursión
        static $processing = [];
        $product_id = $product->get_id();
        
        if (isset($processing[$product_id])) {
            return $price_html;
        }
        
        $processing[$product_id] = true;
        
        // Si es variación, buscar descuento en el padre también
        $parent_id = $product->get_parent_id();
        $search_id = $parent_id > 0 ? $parent_id : $product_id;
        
        $discount = $this->get_product_discount($search_id);
        
        if ($discount) {
            // Obtener precio original
            $original_price = floatval($product->get_regular_price());
            
            if ($original_price > 0) {
                // Calcular precio con descuento
                $discounted_price = $this->calculate_discounted_price($original_price, $discount);
                
                // Badge según tipo de descuento
                if ($discount['type'] === 'percentage') {
                    $badge = sprintf(
                        '<span class="private-shop-badge private-shop-percentage">-%s%%</span>', 
                        number_format($discount['value'], 0)
                    );
                } else {
                    $badge = sprintf(
                        '<span class="private-shop-badge private-shop-fixed">-%s</span>', 
                        wc_price($discount['value'])
                    );
                }
                
                // HTML del precio
                $price_html = sprintf(
                    '<del><span class="woocommerce-Price-amount amount">%s</span></del> ' .
                    '<ins><span class="woocommerce-Price-amount amount">%s</span></ins> ' .
                    '%s',
                    wc_price($original_price),
                    wc_price($discounted_price),
                    $badge
                );
            }
        }
        
        unset($processing[$product_id]);
        return $price_html;
    }
    
    /**
     * Añade menú de administración
     */
    public function add_admin_menu() {
        add_submenu_page(
            'mad-suite',
            'Private Shop - Descuentos',
            'Private Shop',
            'manage_options',
            'mad-private-shop',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Renderiza página de administración
     */
    public function render_admin_page() {
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        switch ($action) {
            case 'edit':
            case 'new':
                include __DIR__ . '/views/edit-rule.php';
                break;
            default:
                include __DIR__ . '/views/rules-list.php';
                break;
        }
    }
    
    /**
     * Guarda una regla de descuento
     */
    public function save_discount_rule() {
        // Verificar nonce y permisos
        if (!isset($_POST['private_shop_nonce']) || 
            !wp_verify_nonce($_POST['private_shop_nonce'], 'save_private_shop_rule') ||
            !current_user_can('manage_options')) {
            wp_die('Error de seguridad');
        }
        
        $this->log('=== GUARDANDO REGLA DE DESCUENTO ===');
        
        $rules = $this->get_discount_rules();
        
        // Obtener o crear ID
        $rule_id = isset($_POST['rule_id']) && !empty($_POST['rule_id']) 
            ? sanitize_text_field($_POST['rule_id']) 
            : uniqid('rule_');
        
        // NUEVO: Construir coupon_config
        $coupon_config = [
            'prefix' => isset($_POST['coupon_prefix']) ? sanitize_text_field($_POST['coupon_prefix']) : 'ps',
            'name_length' => isset($_POST['coupon_name_length']) ? intval($_POST['coupon_name_length']) : 7,
            'exclude_sale_items' => isset($_POST['exclude_sale_items']),
            'individual_use' => isset($_POST['individual_use']),
        ];
        
        // Construir regla
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
            'coupon_config' => $coupon_config,
        ];
        
        // Si es edición, sincronizar cupones existentes
        $is_edit = isset($rules[$rule_id]);
        
        $rules[$rule_id] = $rule;
        update_option('mad_private_shop_rules', $rules);
        
        $this->log(sprintf('Regla guardada: %s | Tipo: %s %s | Aplica a: %s', 
            $rule['name'], 
            $rule['discount_value'], 
            $rule['discount_type'],
            $rule['apply_to']
        ), 'SUCCESS');
        
        // Si es edición, actualizar cupones
        if ($is_edit) {
            $this->sync_rule_coupons($rule_id);
        }
        
        wp_redirect(add_query_arg([
            'page' => 'mad-private-shop',
            'saved' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Elimina una regla
     */
    public function delete_discount_rule() {
        if (!isset($_GET['nonce']) || 
            !wp_verify_nonce($_GET['nonce'], 'delete_rule') ||
            !current_user_can('manage_options')) {
            wp_die('Error de seguridad');
        }
        
        $rule_id = sanitize_text_field($_GET['rule_id']);
        $rules = $this->get_discount_rules();
        
        if (isset($rules[$rule_id])) {
            $rule_name = $rules[$rule_id]['name'];
            
            // Eliminar cupones asociados
            $this->delete_rule_coupons($rule_id);
            
            unset($rules[$rule_id]);
            update_option('mad_private_shop_rules', $rules);
            $this->log("Regla eliminada: $rule_name", 'INFO');
        }
        
        wp_redirect(add_query_arg([
            'page' => 'mad-private-shop',
            'deleted' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Activa/desactiva una regla
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
            
            $status = $rules[$rule_id]['enabled'] ? 'activada' : 'desactivada';
            $this->log("Regla {$rules[$rule_id]['name']} $status", 'INFO');
            
            // Si se desactiva, eliminar cupones
            if (!$rules[$rule_id]['enabled']) {
                $this->delete_rule_coupons($rule_id);
            }
        }
        
        wp_redirect(add_query_arg([
            'page' => 'mad-private-shop'
        ], admin_url('admin.php')));
        exit;
    }
    
    public function get_log_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/mad-suite-logs/private-shop-' . date('Y-m-d') . '.log';
    }
    
    /**
     * Sincroniza cupones de una regla (al editar)
     */
    private function sync_rule_coupons($rule_id) {
        $rules = $this->get_discount_rules();
        if (!isset($rules[$rule_id])) {
            return;
        }
        
        $rule = $rules[$rule_id];
        $rule_coupons = $this->get_rule_coupons();
        
        if (!isset($rule_coupons[$rule_id]) || !isset($rule_coupons[$rule_id]['coupon_ids'])) {
            return;
        }
        
        $coupon_ids = $rule_coupons[$rule_id]['coupon_ids'];
        $updated = 0;
        
        foreach ($coupon_ids as $coupon_id) {
            $coupon = new \WC_Coupon($coupon_id);
            if (!$coupon->get_id()) {
                continue;
            }
            
            // Actualizar tipo y valor
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
            
            // Actualizar fecha de expiración
            $date_expires = !empty($rule['date_to']) ? $rule['date_to'] : null;
            $coupon->set_date_expires($date_expires);
            
            // Actualizar configuración
            $exclude_sale = isset($rule['coupon_config']['exclude_sale_items']) 
                ? $rule['coupon_config']['exclude_sale_items'] 
                : true;
            $individual = isset($rule['coupon_config']['individual_use']) 
                ? $rule['coupon_config']['individual_use'] 
                : true;
                
            $coupon->set_exclude_sale_items($exclude_sale);
            $coupon->set_individual_use($individual);
            
            $coupon->save();
            $updated++;
        }
        
        $this->log("Regla {$rule_id}: {$updated} cupones actualizados", 'SUCCESS');
    }
    
    /**
     * Elimina cupones de una regla
     */
    private function delete_rule_coupons($rule_id) {
        $rule_coupons = $this->get_rule_coupons();
        
        if (!isset($rule_coupons[$rule_id])) {
            return;
        }
        
        $coupon_ids = isset($rule_coupons[$rule_id]['coupon_ids']) 
            ? $rule_coupons[$rule_id]['coupon_ids'] 
            : [];
        
        foreach ($coupon_ids as $coupon_id) {
            wp_delete_post($coupon_id, true);
        }
        
        unset($rule_coupons[$rule_id]);
        $this->save_rule_coupons($rule_coupons);
        
        $this->log("Regla {$rule_id}: " . count($coupon_ids) . " cupones eliminados", 'INFO');
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
                    'operator' => 'IN'
                ]
            ]
        ]);
        
        return array_map(function($product) {
            return $product->get_id();
        }, $products);
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
        
        // Verificar si producto aplica
        $product_id = $product->get_id();
        $applies = false;
        
        if ($rule['apply_to'] === 'products') {
            $applies = in_array($product_id, $rule['target_ids']);
        } else if ($rule['apply_to'] === 'categories') {
            $cat_ids = $product->get_category_ids();
            $applies = !empty(array_intersect($cat_ids, $rule['target_ids']));
        } else if ($rule['apply_to'] === 'tags') {
            $tag_ids = $product->get_tag_ids();
            $applies = !empty(array_intersect($tag_ids, $rule['target_ids']));
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
            $discount = ($regular_price * $rule['discount_value']) / 100;
            $discounted_price = $regular_price - $discount;
            $badge = sprintf('-%.0f%%', $rule['discount_value']);
        } else {
            $discount = $rule['discount_value'];
            $discounted_price = $regular_price - $discount;
            $badge = sprintf('-%s', wc_price($discount));
        }
        
        // Asegurar que el precio no sea negativo
        if ($discounted_price < 0) {
            $discounted_price = 0;
        }
        
        // Construir HTML del precio
        $price_html = sprintf(
            '<del>%s</del> <ins>%s</ins> <span class="private-shop-badge">%s</span>',
            wc_price($regular_price),
            wc_price($discounted_price),
            $badge
        );
        
        return $price_html;
    }
    
    /**
     * Obtiene cupón activo del usuario
     */
    private function get_user_active_coupon($user_id) {
        $rule_coupons = $this->get_rule_coupons();
        
        foreach ($rule_coupons as $data) {
            if (isset($data['user_coupons'][$user_id])) {
                return $data['user_coupons'][$user_id];
            }
        }
        
        return null;
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
            // Remover solo cupones del sistema (formato: prefix_name_id)
            if (preg_match('/^[a-z0-9]+_[a-z0-9]+_\d+$/', $coupon_code)) {
                WC()->cart->remove_coupon($coupon_code);
                $this->log("Cupón {$coupon_code} removido del carrito (logout)", 'INFO');
            }
        }
    }
    
    /**
     * Auto-aplica cupón al añadir producto al carrito
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
        
        $applied_coupons = WC()->cart->get_applied_coupons();
        if (in_array($coupon_code, $applied_coupons)) {
            return;
        }
        
        WC()->cart->apply_coupon($coupon_code);
        $this->log("Cupón {$coupon_code} aplicado automáticamente", 'INFO');
    }
    
    /**
     * Auto-aplica cupón al ver carrito
     */
    public function auto_apply_user_coupon_on_cart() {
        $this->auto_apply_user_coupon();
    }
    
    /**
     * Maneja cupón manual vs automático
     */
    public function handle_manual_coupon($valid, $coupon) {
        if (!$valid || !is_user_logged_in()) {
            return $valid;
        }
        
        $manual_code = $coupon->get_code();
        $user_id = get_current_user_id();
        $auto_code = $this->get_user_active_coupon($user_id);
        
        if (!$auto_code || $manual_code === $auto_code) {
            return $valid;
        }
        
        // Comparar descuentos
        $manual_amount = floatval($coupon->get_amount());
        
        $auto_coupon_id = $this->get_coupon_id_by_code($auto_code);
        if (!$auto_coupon_id) {
            return $valid;
        }
        
        $auto_coupon = new \WC_Coupon($auto_coupon_id);
        $auto_amount = floatval($auto_coupon->get_amount());
        
        if ($manual_amount > $auto_amount) {
            // Manual es mejor, remover automático
            WC()->cart->remove_coupon($auto_code);
            wc_add_notice(sprintf('Cupón %s aplicado (%s)', $manual_code, $coupon->get_amount() . ($coupon->get_discount_type() === 'percent' ? '%' : '€')), 'success');
            $this->log("Cupón manual {$manual_code} reemplazó automático {$auto_code}", 'INFO');
            return $valid;
        } else {
            // Automático es mejor
            wc_add_notice(sprintf('Tu cupón actual (%s) ofrece un mejor descuento', $auto_amount . ($auto_coupon->get_discount_type() === 'percent' ? '%' : '€')), 'notice');
            return false;
        }
    }
    
    /**
     * Admin: Regenerar cupón de usuario
     */
    public function admin_regenerate_coupon() {
        if (!isset($_GET['nonce']) || 
            !wp_verify_nonce($_GET['nonce'], 'regenerate_coupon') ||
            !current_user_can('manage_options')) {
            wp_die('Error de seguridad');
        }
        
        $user_id = intval($_GET['user_id']);
        
        // Eliminar cupón actual
        $current_code = $this->get_user_active_coupon($user_id);
        if ($current_code) {
            $coupon_id = $this->get_coupon_id_by_code($current_code);
            if ($coupon_id) {
                wp_delete_post($coupon_id, true);
            }
        }
        
        // Buscar regla del usuario
        $rule = $this->get_best_rule_for_user($user_id);
        if (!$rule) {
            wp_redirect(add_query_arg(['page' => 'mad-private-shop', 'action' => 'coupons'], admin_url('admin.php')));
            exit;
        }
        
        // Generar nuevo cupón
        $coupon_code = $this->generate_coupon_code($user_id, $rule);
        $coupon_id = $this->create_wc_coupon($coupon_code, $rule, $user_id);
        
        if ($coupon_id) {
            // Actualizar mapeo
            $rule_coupons = $this->get_rule_coupons();
            $rule_coupons[$rule['id']]['user_coupons'][$user_id] = $coupon_code;
            $this->save_rule_coupons($rule_coupons);
            
            $this->log("Cupón {$coupon_code} regenerado para usuario {$user_id}", 'SUCCESS');
        }
        
        wp_redirect(add_query_arg([
            'page' => 'mad-private-shop', 
            'action' => 'coupons',
            'regenerated' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Admin: Eliminar cupón de usuario
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
            $coupon_id = $this->get_coupon_id_by_code($coupon_code);
            if ($coupon_id) {
                wp_delete_post($coupon_id, true);
                
                // Limpiar mapeo
                $rule_coupons = $this->get_rule_coupons();
                foreach ($rule_coupons as $rule_id => $data) {
                    if (isset($data['user_coupons'][$user_id])) {
                        unset($rule_coupons[$rule_id]['user_coupons'][$user_id]);
                    }
                }
                $this->save_rule_coupons($rule_coupons);
                
                $this->log("Cupón {$coupon_code} eliminado para usuario {$user_id}", 'INFO');
            }
        }
        
        wp_redirect(add_query_arg([
            'page' => 'mad-private-shop', 
            'action' => 'coupons',
            'deleted_coupon' => 'true'
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Añade estilos CSS para los badges de descuento
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
        .private-shop-badge.private-shop-percentage {
            background: #2196F3;
        }
        .private-shop-badge.private-shop-fixed {
            background: #FF9800;
        }
        </style>
        <?php
    }
}

// Inicializar módulo
Module::instance();