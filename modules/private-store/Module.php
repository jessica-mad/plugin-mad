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

        // Visualización de precio en items del carrito
        add_filter('woocommerce_cart_item_price', [$this, 'show_cart_item_discount_preview'], 99, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'show_cart_item_subtotal_discount_preview'], 99, 3);

        // Estilos
        add_action('wp_head', [$this, 'add_frontend_styles']);

        // Shortcodes
        add_shortcode('mi_cupon', [$this, 'render_my_coupon_shortcode']);

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
     * Verifica TODOS los roles del usuario (no solo el primero)
     * para compatibilidad con role-creator
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

            // Verificar si el usuario tiene alguno de los roles de la regla
            if (!empty($rule['roles'])) {
                $has_matching_role = false;
                foreach ($user_roles as $user_role) {
                    if (in_array($user_role, $rule['roles'])) {
                        $has_matching_role = true;
                        break;
                    }
                }

                if ($has_matching_role) {
                    $applicable_rules[] = $rule;
                }
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
     * IMPORTANTE: Esta función SOLO muestra el descuento visualmente.
     * El descuento real se aplica mediante cupón automático en el carrito.
     */
    public function show_discount_preview($price_html, $product) {
        // Solo para usuarios logueados
        if (!is_user_logged_in()) {
            return $price_html;
        }

        $user_id = get_current_user_id();
        $rule = $this->get_best_rule_for_user($user_id);

        // Si no hay regla aplicable, retornar precio normal
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

        // Obtener el precio actual del producto
        $current_price = floatval($product->get_price());

        // Si no hay precio, retornar HTML original
        if ($current_price <= 0) {
            return $price_html;
        }

        // Calcular precio con descuento (solo para visualización)
        if ($rule['discount_type'] === 'percentage') {
            $discounted_price = $current_price * (1 - ($rule['discount_value'] / 100));
        } else {
            $discounted_price = $current_price - $rule['discount_value'];
        }

        // Asegurar que el precio con descuento no sea negativo
        $discounted_price = max(0, $discounted_price);

        // Usar formato nativo de WooCommerce (igual que productos en oferta)
        $price_html = sprintf(
            '<del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>%s</bdi></span></del> ' .
            '<ins><span class="woocommerce-Price-amount amount"><bdi>%s</bdi></span></ins>',
            wc_price($current_price),
            wc_price($discounted_price)
        );

        return $price_html;
    }

    /**
     * Muestra preview de descuento en items del carrito
     * IMPORTANTE: Esta función SOLO muestra el descuento visualmente en la columna de precio.
     * El descuento real ya está aplicado mediante cupón automático.
     */
    public function show_cart_item_discount_preview($price_html, $cart_item, $cart_item_key) {
        // Solo para usuarios logueados
        if (!is_user_logged_in()) {
            return $price_html;
        }

        $user_id = get_current_user_id();
        $rule = $this->get_best_rule_for_user($user_id);

        // Si no hay regla aplicable, retornar precio normal
        if (!$rule) {
            return $price_html;
        }

        // Obtener el producto del item del carrito
        $product = $cart_item['data'];
        if (!$product) {
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

        // Obtener el precio actual del producto (precio unitario)
        $current_price = floatval($product->get_price());

        // Si no hay precio, retornar HTML original
        if ($current_price <= 0) {
            return $price_html;
        }

        // Calcular precio con descuento (solo para visualización)
        if ($rule['discount_type'] === 'percentage') {
            $discounted_price = $current_price * (1 - ($rule['discount_value'] / 100));
        } else {
            $discounted_price = $current_price - $rule['discount_value'];
        }

        // Asegurar que el precio con descuento no sea negativo
        $discounted_price = max(0, $discounted_price);

        // Usar formato nativo de WooCommerce (igual que productos en oferta)
        $price_html = sprintf(
            '<del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>%s</bdi></span></del> ' .
            '<ins><span class="woocommerce-Price-amount amount"><bdi>%s</bdi></span></ins>',
            wc_price($current_price),
            wc_price($discounted_price)
        );

        return $price_html;
    }

    /**
     * Muestra preview de descuento en subtotal de items del carrito
     * IMPORTANTE: Esta función SOLO muestra el descuento visualmente en la columna de subtotal.
     * El descuento real ya está aplicado mediante cupón automático.
     */
    public function show_cart_item_subtotal_discount_preview($subtotal_html, $cart_item, $cart_item_key) {
        // Solo para usuarios logueados
        if (!is_user_logged_in()) {
            return $subtotal_html;
        }

        $user_id = get_current_user_id();
        $rule = $this->get_best_rule_for_user($user_id);

        // Si no hay regla aplicable, retornar subtotal normal
        if (!$rule) {
            return $subtotal_html;
        }

        // Obtener el producto del item del carrito
        $product = $cart_item['data'];
        if (!$product) {
            return $subtotal_html;
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
            return $subtotal_html;
        }

        // Obtener cantidad del item
        $quantity = isset($cart_item['quantity']) ? intval($cart_item['quantity']) : 1;

        // Obtener el precio actual del producto (precio unitario)
        $current_price = floatval($product->get_price());

        // Si no hay precio, retornar HTML original
        if ($current_price <= 0) {
            return $subtotal_html;
        }

        // Calcular subtotal original (precio unitario * cantidad)
        $original_subtotal = $current_price * $quantity;

        // Calcular precio unitario con descuento
        if ($rule['discount_type'] === 'percentage') {
            $discounted_unit_price = $current_price * (1 - ($rule['discount_value'] / 100));
        } else {
            $discounted_unit_price = $current_price - $rule['discount_value'];
        }

        // Asegurar que el precio con descuento no sea negativo
        $discounted_unit_price = max(0, $discounted_unit_price);

        // Calcular subtotal con descuento (precio unitario con descuento * cantidad)
        $discounted_subtotal = $discounted_unit_price * $quantity;

        // Usar formato nativo de WooCommerce (igual que productos en oferta)
        $subtotal_html = sprintf(
            '<del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>%s</bdi></span></del> ' .
            '<ins><span class="woocommerce-Price-amount amount"><bdi>%s</bdi></span></ins>',
            wc_price($original_subtotal),
            wc_price($discounted_subtotal)
        );

        return $subtotal_html;
    }

    /**
     * Estilos CSS - Usa estilos nativos de WooCommerce
     */
    public function add_frontend_styles() {
        // No se requieren estilos personalizados
        // Se usa el formato nativo de WooCommerce para precios en oferta
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
    
    /**
     * Shortcode [mi_cupon] - Muestra el cupón activo del usuario
     */
    public function render_my_coupon_shortcode($atts) {
        // Verificar que el usuario esté logueado
        if (!is_user_logged_in()) {
            return '<div class="mad-coupon-box" style="text-align:center; padding: 30px; border: 2px solid #000; background: #fff; color: #000;">
                <p style="margin: 0; font-size: 16px;">Debes iniciar sesión para ver tu cupón</p>
            </div>';
        }

        $user_id = get_current_user_id();

        // Obtener cupón activo del usuario
        $coupon_code = $this->get_user_active_coupon($user_id);

        if (!$coupon_code) {
            return '<div class="mad-coupon-box" style="text-align:center; padding: 30px; border: 2px solid #000; background: #fff; color: #000;">
                <p style="margin: 0; font-size: 16px;">No tienes cupones activos en este momento</p>
            </div>';
        }

        // Obtener información del cupón de WooCommerce
        $coupon_id = wc_get_coupon_id_by_code($coupon_code);
        if (!$coupon_id) {
            return '<div class="mad-coupon-box" style="text-align:center; padding: 30px; border: 2px solid #000; background: #fff; color: #000;">
                <p style="margin: 0; font-size: 16px;">Error al cargar información del cupón</p>
            </div>';
        }

        try {
            $coupon = new \WC_Coupon($coupon_id);

            // Obtener información del cupón
            $discount_type = $coupon->get_discount_type();
            $discount_amount = $coupon->get_amount();
            $date_expires = $coupon->get_date_expires();
            $usage_count = $coupon->get_usage_count();
            $usage_limit = $coupon->get_usage_limit();

            // Formatear descuento
            if ($discount_type === 'percent') {
                $discount_text = $discount_amount . '% de descuento';
            } else {
                $discount_text = wc_price($discount_amount) . ' de descuento';
            }

            // Formatear fechas
            $date_from = get_post_meta($coupon_id, '_mad_ps_created', true);
            $date_from_text = $date_from ? date_i18n('d/m/Y', strtotime($date_from)) : 'N/A';

            if ($date_expires) {
                $date_expires_text = date_i18n('d/m/Y', $date_expires->getTimestamp());
                $days_remaining = ceil(($date_expires->getTimestamp() - time()) / DAY_IN_SECONDS);
            } else {
                $date_expires_text = 'Sin fecha de expiración';
                $days_remaining = null;
            }

            // Usos restantes
            if ($usage_limit) {
                $uses_remaining = max(0, $usage_limit - $usage_count);
                $uses_text = $uses_remaining > 0 ? "Te quedan {$uses_remaining} usos" : "Has usado todos los usos disponibles";
            } else {
                $uses_text = "Usos ilimitados";
            }

            // URL del carrito
            $cart_url = wc_get_cart_url();

            // Generar HTML del cupón
            ob_start();
            ?>
            <div class="mad-coupon-box" style="
                max-width: 600px;
                margin: 0 auto;
                border: 3px solid #000;
                background: #fff;
                color: #000;
                font-family: inherit;
            ">
                <!-- Header -->
                <div class="mad-coupon-header" style="
                    background: #000;
                    color: #fff;
                    padding: 20px;
                    text-align: center;
                ">
                    <h3 style="margin: 0 0 5px 0; font-size: 24px; font-weight: bold;">TU CUPÓN EXCLUSIVO</h3>
                    <p style="margin: 0; font-size: 14px; opacity: 0.9;">Private Store Member</p>
                </div>

                <!-- Código del cupón -->
                <div class="mad-coupon-code" style="
                    padding: 30px 20px;
                    text-align: center;
                    background: #f5f5f5;
                    border-bottom: 2px dashed #000;
                ">
                    <p style="margin: 0 0 10px 0; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Código del Cupón</p>
                    <div style="
                        font-family: 'Courier New', monospace;
                        font-size: 32px;
                        font-weight: bold;
                        letter-spacing: 3px;
                        padding: 15px;
                        background: #fff;
                        border: 2px solid #000;
                        display: inline-block;
                    "><?php echo esc_html($coupon_code); ?></div>
                </div>

                <!-- Beneficios -->
                <div class="mad-coupon-benefits" style="padding: 30px 20px; text-align: center;">
                    <div style="margin-bottom: 20px;">
                        <p style="margin: 0 0 5px 0; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: #666;">Tu Descuento</p>
                        <p style="margin: 0; font-size: 36px; font-weight: bold;"><?php echo esc_html($discount_text); ?></p>
                    </div>

                    <!-- Información adicional -->
                    <div style="
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 20px;
                        margin-top: 25px;
                        padding-top: 25px;
                        border-top: 1px solid #ddd;
                    ">
                        <div>
                            <p style="margin: 0 0 5px 0; font-size: 12px; text-transform: uppercase; color: #666;">Válido desde</p>
                            <p style="margin: 0; font-size: 16px; font-weight: bold;"><?php echo esc_html($date_from_text); ?></p>
                        </div>
                        <div>
                            <p style="margin: 0 0 5px 0; font-size: 12px; text-transform: uppercase; color: #666;">Válido hasta</p>
                            <p style="margin: 0; font-size: 16px; font-weight: bold;"><?php echo esc_html($date_expires_text); ?></p>
                        </div>
                    </div>

                    <?php if ($days_remaining !== null && $days_remaining > 0 && $days_remaining <= 7): ?>
                    <div style="
                        margin-top: 20px;
                        padding: 10px;
                        background: #000;
                        color: #fff;
                        border-radius: 4px;
                    ">
                        <p style="margin: 0; font-size: 13px;">⏰ ¡Quedan solo <?php echo $days_remaining; ?> días para usar este cupón!</p>
                    </div>
                    <?php endif; ?>

                    <div style="margin-top: 15px;">
                        <p style="margin: 0; font-size: 13px; color: #666;"><?php echo esc_html($uses_text); ?></p>
                    </div>
                </div>

                <!-- Botón de acción -->
                <div class="mad-coupon-action" style="padding: 0 20px 30px;">
                    <button
                        onclick="madApplyCoupon('<?php echo esc_js($coupon_code); ?>')"
                        style="
                            width: 100%;
                            padding: 18px;
                            background: #000;
                            color: #fff;
                            border: none;
                            font-size: 16px;
                            font-weight: bold;
                            text-transform: uppercase;
                            letter-spacing: 1px;
                            cursor: pointer;
                            transition: all 0.3s ease;
                        "
                        onmouseover="this.style.background='#333'"
                        onmouseout="this.style.background='#000'"
                    >
                        Aplicar a mi Próxima Compra →
                    </button>
                </div>

                <!-- Footer info -->
                <div style="
                    padding: 15px 20px;
                    background: #f5f5f5;
                    text-align: center;
                    font-size: 12px;
                    color: #666;
                    border-top: 1px solid #ddd;
                ">
                    <p style="margin: 0;">El cupón se aplicará automáticamente en el carrito</p>
                </div>
            </div>

            <script>
            function madApplyCoupon(couponCode) {
                // Mostrar mensaje de carga
                var button = event.target;
                var originalText = button.innerHTML;
                button.innerHTML = 'Aplicando...';
                button.disabled = true;

                // Aplicar cupón vía AJAX
                jQuery.ajax({
                    url: wc_add_to_cart_params.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'woocommerce_apply_coupon',
                        security: wc_checkout_params ? wc_checkout_params.apply_coupon_nonce : '',
                        coupon_code: couponCode
                    },
                    success: function(response) {
                        button.innerHTML = '✓ Cupón Aplicado';
                        button.style.background = '#4CAF50';

                        // Redirigir al carrito después de 1 segundo
                        setTimeout(function() {
                            window.location.href = '<?php echo esc_js($cart_url); ?>';
                        }, 1000);
                    },
                    error: function() {
                        // Si falla AJAX, redirigir al carrito con el cupón en la URL
                        window.location.href = '<?php echo esc_js(add_query_arg('apply_coupon', $coupon_code, $cart_url)); ?>';
                    }
                });
            }
            </script>
            <?php
            return ob_get_clean();

        } catch (\Exception $e) {
            return '<div class="mad-coupon-box" style="text-align:center; padding: 30px; border: 2px solid #000; background: #fff; color: #000;">
                <p style="margin: 0; font-size: 16px;">Error al cargar el cupón: ' . esc_html($e->getMessage()) . '</p>
            </div>';
        }
    }

    public function get_log_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/mad-suite-logs/private-shop-' . date('Y-m-d') . '.log';
    }
}

// Inicializar módulo
Module::instance();