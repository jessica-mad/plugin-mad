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
        ];
        
        $rules[$rule_id] = $rule;
        update_option('mad_private_shop_rules', $rules);
        
        $this->log(sprintf('Regla guardada: %s | Tipo: %s %s | Aplica a: %s', 
            $rule['name'], 
            $rule['discount_value'], 
            $rule['discount_type'],
            $rule['apply_to']
        ), 'SUCCESS');
        
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