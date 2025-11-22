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
            // Solo reglas activas (habilitadas en configuración)
            if (!isset($rule['enabled']) || !$rule['enabled']) {
                continue;
            }

            // NO filtrar por fechas - queremos crear cupones para reglas futuras también
            // El cupón mismo tendrá las restricciones de fecha configuradas

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
     * Obtiene TODAS las reglas aplicables para un usuario
     * Devuelve array de reglas ordenadas por prioridad
     */
    private function get_all_applicable_rules_for_user($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return [];
        }

        $user_roles = $user->roles;
        if (empty($user_roles)) {
            return [];
        }

        $rules = $this->get_discount_rules();
        $applicable_rules = [];

        foreach ($rules as $rule) {
            // Solo reglas activas (habilitadas en configuración)
            if (!isset($rule['enabled']) || !$rule['enabled']) {
                continue;
            }

            // NO filtrar por fechas - queremos crear cupones para reglas futuras también
            // El cupón mismo tendrá las restricciones de fecha configuradas

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
            return [];
        }

        // Ordenar por prioridad (menor = mayor prioridad)
        usort($applicable_rules, function($a, $b) {
            $priority_a = isset($a['priority']) ? intval($a['priority']) : 999;
            $priority_b = isset($b['priority']) ? intval($b['priority']) : 999;
            return $priority_a - $priority_b;
        });

        return $applicable_rules; // Devolver TODAS las reglas, no solo la primera
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
     * Obtiene TODOS los cupones del usuario (activos, futuros y expirados)
     */
    private function get_all_user_coupons($user_id) {
        $rule_coupons = $this->get_rule_coupons();
        $user_coupons = [];

        foreach ($rule_coupons as $rule_id => $data) {
            if (isset($data['user_coupons'][$user_id])) {
                $coupon_code = $data['user_coupons'][$user_id];

                // Verificar que el cupón existe en WC
                $coupon_id = wc_get_coupon_id_by_code($coupon_code);
                if ($coupon_id) {
                    try {
                        $coupon = new \WC_Coupon($coupon_id);

                        // Obtener regla asociada para fechas programadas
                        $rules = $this->get_discount_rules();
                        $rule = isset($rules[$rule_id]) ? $rules[$rule_id] : null;

                        $user_coupons[] = [
                            'code' => $coupon_code,
                            'coupon' => $coupon,
                            'rule' => $rule,
                            'rule_id' => $rule_id
                        ];
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        return $user_coupons;
    }
    
    /**
     * Evento: Usuario hace login
     * Crea cupones para TODAS las reglas aplicables al usuario
     */
    public function on_user_login($user_login, $user) {
        $user_id = $user->ID;

        // Obtener TODAS las reglas aplicables para este usuario
        $applicable_rules = $this->get_all_applicable_rules_for_user($user_id);

        if (empty($applicable_rules)) {
            $this->log("Usuario {$user_login} sin reglas aplicables");
            return;
        }

        $rule_coupons = $this->get_rule_coupons();
        $created_count = 0;

        // Crear un cupón para CADA regla aplicable
        foreach ($applicable_rules as $rule) {
            // Verificar si ya tiene cupón para ESTA regla específica
            if (isset($rule_coupons[$rule['id']]['user_coupons'][$user_id])) {
                $existing_code = $rule_coupons[$rule['id']]['user_coupons'][$user_id];
                $this->log("Usuario {$user_login} ya tiene cupón para regla {$rule['name']}: {$existing_code}");
                continue; // Ya tiene cupón para esta regla, pasar a la siguiente
            }

            // Generar código de cupón
            $coupon_code = $this->generate_coupon_code($user_id, $rule);

            if (!$coupon_code) {
                $this->log("Error generando código de cupón para usuario {$user_login} y regla {$rule['name']}", 'ERROR');
                continue;
            }

            // Crear cupón en WooCommerce
            $coupon_id = $this->create_wc_coupon($coupon_code, $rule, $user_id);

            if (!$coupon_id) {
                $this->log("Error creando cupón WC para usuario {$user_login} y regla {$rule['name']}", 'ERROR');
                continue;
            }

            // Guardar en mapeo
            if (!isset($rule_coupons[$rule['id']])) {
                $rule_coupons[$rule['id']] = [
                    'coupon_ids' => [],
                    'user_coupons' => []
                ];
            }

            $rule_coupons[$rule['id']]['coupon_ids'][] = $coupon_id;
            $rule_coupons[$rule['id']]['user_coupons'][$user_id] = $coupon_code;

            $created_count++;
            $this->log("Cupón creado para usuario {$user_login} y regla {$rule['name']}: {$coupon_code}");
        }

        // Guardar todos los cambios
        $this->save_rule_coupons($rule_coupons);

        $this->log("Sistema completo - {$created_count} cupón(es) creado(s) para {$user_login}", 'SUCCESS');
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
            'time_from' => sanitize_text_field($_POST['time_from'] ?? '00:00'),
            'time_to' => sanitize_text_field($_POST['time_to'] ?? '23:59'),
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
     * Shortcode [mi_cupon] - Muestra TODOS los cupones del usuario (activos, futuros, expirados)
     */
    public function render_my_coupon_shortcode($atts) {
        // Verificar que el usuario esté logueado
        if (!is_user_logged_in()) {
            return '<div class="mad-coupon-box" style="text-align:center; padding: 30px; border: 2px solid #000; background: #fff; color: #000;">
                <p style="margin: 0; font-size: 16px;">Debes iniciar sesión para ver tus cupones</p>
            </div>';
        }

        $user_id = get_current_user_id();

        // Obtener TODOS los cupones del usuario
        $user_coupons = $this->get_all_user_coupons($user_id);

        if (empty($user_coupons)) {
            return '<div class="mad-coupon-box" style="text-align:center; padding: 30px; border: 2px solid #000; background: #fff; color: #000;">
                <p style="margin: 0; font-size: 16px;">No tienes cupones disponibles</p>
            </div>';
        }

        // URL del carrito
        $cart_url = wc_get_cart_url();

        // Zona horaria de Madrid para verificar estado de cupones
        $timezone = new \DateTimeZone('Europe/Madrid');
        $now = new \DateTime('now', $timezone);

        // Buffer de salida
        ob_start();

        // Procesar cada cupón
        foreach ($user_coupons as $coupon_data) {
            $coupon_code = $coupon_data['code'];
            $coupon = $coupon_data['coupon'];
            $rule = $coupon_data['rule'];

            try {

                // Obtener información del cupón
                $discount_type = $coupon->get_discount_type();
                $discount_amount = $coupon->get_amount();
                $date_expires = $coupon->get_date_expires();
                $usage_count = $coupon->get_usage_count();
                $usage_limit = $coupon->get_usage_limit();

                // Determinar estado del cupón basándose en la regla programada
                $coupon_status = 'active'; // Por defecto activo
                $status_badge = '';
                $status_message = '';
                $activation_date = '';

                // Verificar programación de la regla (fecha y hora en Madrid)
                if ($rule && !empty($rule['date_from'])) {
                    $time_from = isset($rule['time_from']) && !empty($rule['time_from']) ? $rule['time_from'] : '00:00';
                    $datetime_from = \DateTime::createFromFormat('Y-m-d H:i', $rule['date_from'] . ' ' . $time_from, $timezone);

                    if ($datetime_from && $now < $datetime_from) {
                        // Cupón FUTURO - aún no activo
                        $coupon_status = 'future';
                        $status_badge = 'PRÓXIMAMENTE';
                        $activation_date = $datetime_from->format('d/m/Y H:i');
                        $status_message = "Este cupón se activará el {$activation_date} (hora Madrid)";
                    }
                }

                // Verificar si ha expirado
                if ($rule && !empty($rule['date_to'])) {
                    $time_to = isset($rule['time_to']) && !empty($rule['time_to']) ? $rule['time_to'] : '23:59';
                    $datetime_to = \DateTime::createFromFormat('Y-m-d H:i', $rule['date_to'] . ' ' . $time_to, $timezone);

                    if ($datetime_to && $now > $datetime_to) {
                        // Cupón EXPIRADO
                        $coupon_status = 'expired';
                        $status_badge = 'EXPIRADO';
                        $status_message = "Este cupón expiró el " . $datetime_to->format('d/m/Y H:i') . " (hora Madrid)";
                    }
                }

                // Si está activo, verificar si está por expirar pronto
                if ($coupon_status === 'active' && $date_expires) {
                    $days_remaining = ceil(($date_expires->getTimestamp() - time()) / DAY_IN_SECONDS);
                    if ($days_remaining > 0 && $days_remaining <= 7) {
                        $status_message = "⏰ ¡Solo quedan {$days_remaining} días!";
                    }
                    $status_badge = 'ACTIVO AHORA';
                }

                // Formatear fechas para mostrar
                $date_from_text = 'N/A';
                if ($rule && !empty($rule['date_from'])) {
                    $time_from_display = isset($rule['time_from']) ? $rule['time_from'] : '00:00';
                    $date_from_text = date_i18n('d/m/Y', strtotime($rule['date_from'])) . ' a las ' . $time_from_display;
                }

                $date_to_text = 'Sin expiración';
                if ($rule && !empty($rule['date_to'])) {
                    $time_to_display = isset($rule['time_to']) ? $rule['time_to'] : '23:59';
                    $date_to_text = date_i18n('d/m/Y', strtotime($rule['date_to'])) . ' a las ' . $time_to_display;
                }

                // Usos restantes
                if ($usage_limit) {
                    $uses_remaining = max(0, $usage_limit - $usage_count);
                    $uses_text = $uses_remaining > 0 ? "Te quedan {$uses_remaining} usos" : "Has usado todos los usos disponibles";
                } else {
                    $uses_text = "Usos ilimitados";
                }

                // Generar HTML del cupón tipo ticket
                ?>
                <div class="mad-coupon-ticket" style="
                    max-width: 500px;
                    margin: 20px auto;
                    background: #000;
                    color: #fff;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
                    border-radius: 8px;
                    overflow: hidden;
                    position: relative;
                ">
                    <!-- Badge de estado en esquina superior derecha -->
                    <?php if (!empty($status_badge)): ?>
                    <div style="
                        position: absolute;
                        top: 10px;
                        right: 10px;
                        background: rgba(255, 255, 255, 0.25);
                        color: #fff;
                        padding: 5px 12px;
                        border-radius: 20px;
                        font-size: 10px;
                        font-weight: 700;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                        z-index: 10;
                    "><?php echo esc_html($status_badge); ?></div>
                    <?php endif; ?>
                <!-- Contenedor de 2 columnas -->
                <div style="
                    display: flex;
                    min-height: 180px;
                ">
                    <!-- Columna 1: Descuento -->
                    <div style="
                        flex: 1;
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        justify-content: center;
                        padding: 30px 20px;
                        border-right: 2px dashed rgba(255, 255, 255, 0.3);
                    ">
                        <div style="
                            font-size: 64px;
                            font-weight: 900;
                            line-height: 1;
                            margin-bottom: 8px;
                            color: #fff;
                        ">
                            <?php
                            if ($discount_type === 'percent') {
                                echo esc_html($discount_amount) . '<span style="font-size: 48px;">%</span>';
                            } else {
                                echo '<span style="font-size: 36px;">' . wp_strip_all_tags(wc_price($discount_amount, ['currency' => 'EUR'])) . '</span>';
                            }
                            ?>
                        </div>
                        <div style="
                            font-size: 13px;
                            text-transform: uppercase;
                            letter-spacing: 1px;
                            color: rgba(255, 255, 255, 0.7);
                            font-weight: 600;
                        ">
                            descuento
                        </div>
                    </div>

                    <!-- Columna 2: Información del cupón -->
                    <div style="
                        flex: 1.3;
                        display: flex;
                        flex-direction: column;
                        justify-content: center;
                        padding: 30px 25px;
                    ">
                        <!-- Código del cupón -->
                        <div style="margin-bottom: 18px;">
                            <div style="
                                font-size: 11px;
                                text-transform: uppercase;
                                letter-spacing: 1px;
                                color: rgba(255, 255, 255, 0.6);
                                margin-bottom: 6px;
                                font-weight: 600;
                            ">Tu cupón</div>
                            <div style="
                                font-family: 'Courier New', monospace;
                                font-size: 18px;
                                font-weight: bold;
                                letter-spacing: 1px;
                                color: #fff;
                            "><?php echo esc_html($coupon_code); ?></div>
                        </div>

                        <!-- Fechas de validez -->
                        <div style="
                            font-size: 12px;
                            line-height: 1.6;
                            color: rgba(255, 255, 255, 0.85);
                        ">
                            <div style="margin-bottom: 4px;">
                                <span style="color: rgba(255, 255, 255, 0.6);">Activo desde:</span>
                                <strong><?php echo esc_html($date_from_text); ?></strong>
                            </div>
                            <div>
                                <span style="color: rgba(255, 255, 255, 0.6);">Hasta:</span>
                                <strong><?php echo esc_html($date_to_text); ?></strong>
                            </div>
                        </div>

                        <!-- Mensaje de estado -->
                        <?php if (!empty($status_message)): ?>
                        <div style="
                            margin-top: 12px;
                            padding: 8px 10px;
                            background: rgba(255, 255, 255, 0.15);
                            border-left: 3px solid rgba(255, 255, 255, 0.5);
                            font-size: 11px;
                            color: rgba(255, 255, 255, 0.95);
                            font-weight: 600;
                            border-radius: 3px;
                        ">
                            <?php echo esc_html($status_message); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($coupon_status === 'active'): ?>
                <!-- Botón de aplicar cupón -->
                <div style="
                    padding: 0;
                    border-top: 1px solid rgba(255, 255, 255, 0.1);
                ">
                    <button
                        onclick="madApplyCoupon('<?php echo esc_js($coupon_code); ?>')"
                        style="
                            width: 100%;
                            padding: 16px;
                            background: rgba(255, 255, 255, 0.1);
                            color: #fff;
                            border: none;
                            font-size: 14px;
                            font-weight: 700;
                            text-transform: uppercase;
                            letter-spacing: 1px;
                            cursor: pointer;
                            transition: all 0.3s ease;
                        "
                        onmouseover="this.style.background='rgba(255, 255, 255, 0.2)'"
                        onmouseout="this.style.background='rgba(255, 255, 255, 0.1)'"
                    >
                        Aplicar cupón →
                    </button>
                </div>
                <?php elseif ($coupon_status === 'future'): ?>
                <!-- Mensaje cupón futuro -->
                <div style="
                    padding: 16px;
                    background: rgba(255, 255, 255, 0.1);
                    border-top: 1px solid rgba(255, 255, 255, 0.2);
                    text-align: center;
                    font-size: 13px;
                    color: rgba(255, 255, 255, 0.9);
                    font-weight: 600;
                ">
                    ⏳ Este cupón se activará pronto
                </div>
                <?php else: ?>
                <!-- Mensaje de expirado -->
                <div style="
                    padding: 16px;
                    background: rgba(255, 255, 255, 0.1);
                    border-top: 1px solid rgba(255, 255, 255, 0.2);
                    text-align: center;
                    font-size: 13px;
                    color: rgba(255, 255, 255, 0.7);
                    font-weight: 600;
                ">
                    Este cupón ha expirado y ya no se puede utilizar
                </div>
                <?php endif; ?>

                <!-- Info de usos (solo si está activo) -->
                <?php if ($coupon_status === 'active'): ?>
                <div style="
                    padding: 10px 20px;
                    background: rgba(0, 0, 0, 0.3);
                    text-align: center;
                    font-size: 11px;
                    color: rgba(255, 255, 255, 0.5);
                ">
                    <?php echo esc_html($uses_text); ?>
                </div>
                <?php endif; ?>
                </div>
                <?php

            } catch (\Exception $e) {
                // Error al procesar este cupón, continuar con el siguiente
                continue;
            }
        } // Fin del foreach

        // Script y estilos (solo una vez)
        ?>
        <script>
        function madApplyCoupon(couponCode) {
            // Mostrar mensaje de carga
            var button = event.target;
            var originalText = button.innerHTML;
            button.innerHTML = '⏳ Aplicando...';
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
                    button.innerHTML = '✓ Cupón aplicado';
                    button.style.background = 'rgba(76, 175, 80, 0.3)';
                    button.style.color = '#4CAF50';

                    // Redirigir al carrito después de 1 segundo
                    setTimeout(function() {
                        window.location.href = '<?php echo esc_js($cart_url); ?>';
                    }, 1000);
                },
                error: function() {
                    // Si falla AJAX, redirigir al carrito con el cupón en la URL
                    window.location.href = '<?php echo esc_js($cart_url); ?>?apply_coupon=' + couponCode;
                }
            });
        }
        </script>

        <style>
        /* Responsive para móviles */
        @media (max-width: 480px) {
            .mad-coupon-ticket > div:nth-child(2) {
                flex-direction: column !important;
            }
            .mad-coupon-ticket > div:nth-child(2) > div:first-child {
                border-right: none !important;
                border-bottom: 2px dashed rgba(255, 255, 255, 0.3) !important;
                padding: 25px 20px !important;
            }
            .mad-coupon-ticket > div:nth-child(2) > div:last-child {
                padding: 25px 20px !important;
            }
        }
        </style>
        <?php

        return ob_get_clean();
    }

    public function get_log_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/mad-suite-logs/private-shop-' . date('Y-m-d') . '.log';
    }
}

// Inicializar módulo
Module::instance();