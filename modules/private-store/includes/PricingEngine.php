<?php
/**
 * Pricing Engine Class
 * 
 * Motor de descuentos para la tienda privada
 * Aplica descuentos por categorías, etiquetas y productos individuales
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

namespace MAD_Suite\Modules\PrivateStore;

if (!defined('ABSPATH')) {
    exit;
}

class PricingEngine {
    
    private static $instance = null;
    private $logger;
    private $applied_discounts = [];
    
    /**
     * Meta key para descuento individual de producto
     */
    const META_INDIVIDUAL_DISCOUNT = '_mads_ps_individual_discount';
    const META_INDIVIDUAL_DISCOUNT_TYPE = '_mads_ps_individual_discount_type';
    
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
        $this->logger = new Logger('private-store-pricing');
        
        // Hooks de precios
        add_filter('woocommerce_product_get_price', [$this, 'apply_vip_price'], 999, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'apply_vip_price'], 999, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'apply_vip_price'], 999, 2);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'apply_vip_price'], 999, 2);
        
        // Mostrar precio original tachado
        add_filter('woocommerce_get_price_html', [$this, 'modify_price_html'], 999, 2);
        
        // Meta box para descuento individual
        add_action('add_meta_boxes', [$this, 'add_pricing_meta_box']);
        add_action('save_post_product', [$this, 'save_pricing_meta'], 10, 2);
        
        // Badges y avisos
        add_action('woocommerce_before_add_to_cart_button', [$this, 'show_discount_badge']);
        add_action('woocommerce_after_shop_loop_item_title', [$this, 'show_discount_badge_loop'], 15);
        
        // Columna en listado de productos
        add_filter('manage_edit-product_columns', [$this, 'add_discount_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_discount_column'], 10, 2);
        
        $this->logger->info('PricingEngine inicializado');
    }
    
    /**
     * Aplicar precio VIP
     */
    public function apply_vip_price($price, $product) {
        // Solo aplicar en tienda privada o para usuarios VIP
        if (!ProductVisibility::instance()->is_private_store() && !UserRole::instance()->is_vip_user()) {
            return $price;
        }
        
        // Si no hay precio, retornar
        if (empty($price) || $price <= 0) {
            return $price;
        }
        
        $product_id = $product->get_id();
        $original_price = $price;
        
        // 1. Verificar descuento individual del producto (máxima prioridad)
        $individual_discount = $this->get_individual_discount($product_id);
        
        if ($individual_discount) {
            $price = $this->calculate_discount($price, $individual_discount['amount'], $individual_discount['type']);
            
            $this->log_discount_applied($product_id, 'individual', $original_price, $price, $individual_discount);
            
            return $price;
        }
        
        // 2. Verificar descuentos por categoría y etiqueta
        $category_discount = $this->get_best_category_discount($product_id);
        $tag_discount = $this->get_best_tag_discount($product_id);
        
        // Aplicar el mejor descuento disponible
        $best_discount = $this->get_best_discount($category_discount, $tag_discount);
        
        if ($best_discount) {
            $price = $this->calculate_discount($price, $best_discount['amount'], $best_discount['type']);
            
            $this->log_discount_applied($product_id, $best_discount['discount_type'], $original_price, $price, $best_discount);
        }
        
        return $price;
    }
    
    /**
     * Obtener descuento individual del producto
     */
    private function get_individual_discount($product_id) {
        $amount = get_post_meta($product_id, self::META_INDIVIDUAL_DISCOUNT, true);
        $type = get_post_meta($product_id, self::META_INDIVIDUAL_DISCOUNT_TYPE, true);
        
        if (empty($amount) || $amount <= 0) {
            return null;
        }
        
        return [
            'amount' => floatval($amount),
            'type' => $type ?: 'percentage',
            'discount_type' => 'individual'
        ];
    }
    
    /**
     * Obtener mejor descuento por categoría
     */
    private function get_best_category_discount($product_id) {
        $discounts = get_option('mads_ps_discounts', []);
        $product_categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        
        if (empty($product_categories)) {
            return null;
        }
        
        $best_discount = null;
        $best_value = 0;
        
        foreach ($discounts as $discount) {
            if ($discount['type'] !== 'category') {
                continue;
            }
            
            // Verificar si el producto pertenece a esta categoría
            if (!in_array($discount['target'], $product_categories)) {
                continue;
            }
            
            // Calcular valor del descuento para comparar
            $discount_value = $this->calculate_discount_value(100, $discount['amount'], $discount['amount_type']);
            
            if ($discount_value > $best_value) {
                $best_value = $discount_value;
                $best_discount = array_merge($discount, ['discount_type' => 'category']);
            }
        }
        
        return $best_discount;
    }
    
    /**
     * Obtener mejor descuento por etiqueta
     */
    private function get_best_tag_discount($product_id) {
        $discounts = get_option('mads_ps_discounts', []);
        $product_tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'ids']);
        
        if (empty($product_tags)) {
            return null;
        }
        
        $best_discount = null;
        $best_value = 0;
        
        foreach ($discounts as $discount) {
            if ($discount['type'] !== 'tag') {
                continue;
            }
            
            // Verificar si el producto tiene esta etiqueta
            if (!in_array($discount['target'], $product_tags)) {
                continue;
            }
            
            // Calcular valor del descuento para comparar
            $discount_value = $this->calculate_discount_value(100, $discount['amount'], $discount['amount_type']);
            
            if ($discount_value > $best_value) {
                $best_value = $discount_value;
                $best_discount = array_merge($discount, ['discount_type' => 'tag']);
            }
        }
        
        return $best_discount;
    }
    
    /**
     * Obtener el mejor descuento entre dos opciones
     */
    private function get_best_discount($discount1, $discount2) {
        if (!$discount1 && !$discount2) {
            return null;
        }
        
        if (!$discount1) {
            return $discount2;
        }
        
        if (!$discount2) {
            return $discount1;
        }
        
        // Comparar cuál ofrece mayor descuento
        $value1 = $this->calculate_discount_value(100, $discount1['amount'], $discount1['amount_type']);
        $value2 = $this->calculate_discount_value(100, $discount2['amount'], $discount2['amount_type']);
        
        return $value1 >= $value2 ? $discount1 : $discount2;
    }
    
    /**
     * Calcular descuento
     */
    private function calculate_discount($price, $amount, $type) {
        if ($type === 'percentage') {
            return $price - ($price * ($amount / 100));
        } elseif ($type === 'fixed') {
            return max(0, $price - $amount);
        }
        
        return $price;
    }
    
    /**
     * Calcular valor de descuento (para comparar)
     */
    private function calculate_discount_value($base_price, $amount, $type) {
        if ($type === 'percentage') {
            return $base_price * ($amount / 100);
        } elseif ($type === 'fixed') {
            return $amount;
        }
        
        return 0;
    }
    
    /**
     * Registrar descuento aplicado
     */
    private function log_discount_applied($product_id, $type, $original_price, $final_price, $discount_data) {
        $discount_amount = $original_price - $final_price;
        $discount_percentage = $original_price > 0 ? ($discount_amount / $original_price) * 100 : 0;
        
        $this->applied_discounts[$product_id] = [
            'type' => $type,
            'original_price' => $original_price,
            'final_price' => $final_price,
            'discount_amount' => $discount_amount,
            'discount_percentage' => $discount_percentage,
            'discount_data' => $discount_data
        ];
        
        $this->logger->debug("Descuento VIP aplicado", [
            'product_id' => $product_id,
            'discount_type' => $type,
            'original' => wc_price($original_price),
            'final' => wc_price($final_price),
            'saved' => wc_price($discount_amount),
            'percentage' => round($discount_percentage, 2) . '%'
        ]);
    }
    
    /**
     * Modificar HTML del precio para mostrar original tachado
     */
    public function modify_price_html($price_html, $product) {
        // Solo en tienda privada o para usuarios VIP
        if (!ProductVisibility::instance()->is_private_store() && !UserRole::instance()->is_vip_user()) {
            return $price_html;
        }
        
        $product_id = $product->get_id();
        
        // Verificar si se aplicó descuento
        if (!isset($this->applied_discounts[$product_id])) {
            return $price_html;
        }
        
        $discount_info = $this->applied_discounts[$product_id];
        
        if ($discount_info['discount_amount'] <= 0) {
            return $price_html;
        }
        
        // Construir HTML con precio original tachado
        $original_price = wc_price($discount_info['original_price']);
        $final_price = wc_price($discount_info['final_price']);
        $saved_text = sprintf(
            __('Ahorras %s (%s%%)', 'mad-suite'),
            wc_price($discount_info['discount_amount']),
            round($discount_info['discount_percentage'], 0)
        );
        
        $price_html = sprintf(
            '<del style="opacity: 0.5;">%s</del> <ins style="text-decoration: none; color: #e74c3c; font-weight: bold;">%s</ins><br><small style="color: #27ae60;">%s</small>',
            $original_price,
            $final_price,
            $saved_text
        );
        
        return $price_html;
    }
    
    /**
     * Meta box para descuento individual
     */
    public function add_pricing_meta_box() {
        add_meta_box(
            'mads_ps_product_pricing',
            __('Tienda Privada - Descuento VIP', 'mad-suite'),
            [$this, 'render_pricing_meta_box'],
            'product',
            'side',
            'default'
        );
    }
    
    /**
     * Renderizar meta box de pricing
     */
    public function render_pricing_meta_box($post) {
        wp_nonce_field('mads_ps_pricing_meta', 'mads_ps_pricing_meta_nonce');
        
        $discount_amount = get_post_meta($post->ID, self::META_INDIVIDUAL_DISCOUNT, true);
        $discount_type = get_post_meta($post->ID, self::META_INDIVIDUAL_DISCOUNT_TYPE, true) ?: 'percentage';
        
        ?>
        <div class="mads-ps-pricing-options">
            <p>
                <label for="mads_ps_discount_amount">
                    <strong><?php _e('Descuento individual VIP', 'mad-suite'); ?></strong>
                </label>
            </p>
            
            <div style="display: flex; gap: 8px; align-items: center;">
                <input type="number" 
                       id="mads_ps_discount_amount"
                       name="mads_ps_discount_amount" 
                       value="<?php echo esc_attr($discount_amount); ?>" 
                       step="0.01"
                       min="0"
                       style="width: 80px;"
                       placeholder="0">
                
                <select name="mads_ps_discount_type" style="width: auto;">
                    <option value="percentage" <?php selected($discount_type, 'percentage'); ?>>%</option>
                    <option value="fixed" <?php selected($discount_type, 'fixed'); ?>>
                        <?php echo get_woocommerce_currency_symbol(); ?>
                    </option>
                </select>
            </div>
            
            <p style="margin-top: 10px;">
                <small style="color: #666;">
                    <?php _e('Deja en 0 para usar descuentos por categoría/etiqueta', 'mad-suite'); ?>
                </small>
            </p>
            
            <?php if (!empty($discount_amount) && $discount_amount > 0): ?>
                <div style="padding: 8px; background: #d4edda; border-left: 3px solid #28a745; margin-top: 10px;">
                    <small>
                        <strong><?php _e('Descuento activo:', 'mad-suite'); ?></strong><br>
                        <?php 
                        if ($discount_type === 'percentage') {
                            echo esc_html($discount_amount) . '%';
                        } else {
                            echo wc_price($discount_amount);
                        }
                        ?>
                    </small>
                </div>
            <?php endif; ?>
            
            <p style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
                <small style="color: #666;">
                    <strong><?php _e('Prioridad de descuentos:', 'mad-suite'); ?></strong><br>
                    1. Individual (este producto)<br>
                    2. Categoría<br>
                    3. Etiqueta
                </small>
            </p>
        </div>
        <?php
    }
    
    /**
     * Guardar meta de pricing
     */
    public function save_pricing_meta($post_id, $post) {
        // Verificar nonce
        if (!isset($_POST['mads_ps_pricing_meta_nonce']) || 
            !wp_verify_nonce($_POST['mads_ps_pricing_meta_nonce'], 'mads_ps_pricing_meta')) {
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('edit_product', $post_id)) {
            return;
        }
        
        // Evitar auto-save
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        $old_amount = get_post_meta($post_id, self::META_INDIVIDUAL_DISCOUNT, true);
        $old_type = get_post_meta($post_id, self::META_INDIVIDUAL_DISCOUNT_TYPE, true);
        
        // Guardar descuento
        $discount_amount = isset($_POST['mads_ps_discount_amount']) ? floatval($_POST['mads_ps_discount_amount']) : 0;
        $discount_type = isset($_POST['mads_ps_discount_type']) ? sanitize_key($_POST['mads_ps_discount_type']) : 'percentage';
        
        // Validar tipo
        if (!in_array($discount_type, ['percentage', 'fixed'])) {
            $discount_type = 'percentage';
        }
        
        // Validar cantidad
        if ($discount_type === 'percentage' && $discount_amount > 100) {
            $discount_amount = 100;
        }
        
        update_post_meta($post_id, self::META_INDIVIDUAL_DISCOUNT, $discount_amount);
        update_post_meta($post_id, self::META_INDIVIDUAL_DISCOUNT_TYPE, $discount_type);
        
        // Limpiar cache de precios de WooCommerce
        wc_delete_product_transients($post_id);
        
        // Log cambios
        if ($old_amount != $discount_amount || $old_type != $discount_type) {
            $this->logger->info("Descuento individual actualizado", [
                'product_id' => $post_id,
                'product_name' => $post->post_title,
                'amount' => $discount_amount,
                'type' => $discount_type,
                'previous' => [
                    'amount' => $old_amount,
                    'type' => $old_type
                ]
            ]);
        }
    }
    
    /**
     * Mostrar badge de descuento en página de producto
     */
    public function show_discount_badge() {
        global $product;
        
        if (!$product || !ProductVisibility::instance()->is_private_store()) {
            return;
        }
        
        $product_id = $product->get_id();
        
        if (!isset($this->applied_discounts[$product_id])) {
            return;
        }
        
        $discount_info = $this->applied_discounts[$product_id];
        
        if ($discount_info['discount_percentage'] <= 0) {
            return;
        }
        
        ?>
        <div class="mads-ps-discount-badge" style="display: inline-block; padding: 8px 16px; background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: #fff; border-radius: 25px; font-size: 14px; font-weight: bold; margin: 15px 0; box-shadow: 0 2px 8px rgba(231, 76, 60, 0.3);">
            <span class="dashicons dashicons-tag" style="font-size: 16px; margin-right: 6px; vertical-align: middle;"></span>
            <?php printf(
                __('DESCUENTO VIP: -%s%%', 'mad-suite'),
                round($discount_info['discount_percentage'], 0)
            ); ?>
        </div>
        <?php
    }
    
    /**
     * Mostrar badge de descuento en loop
     */
    public function show_discount_badge_loop() {
        global $product;
        
        if (!$product || !ProductVisibility::instance()->is_private_store()) {
            return;
        }
        
        $product_id = $product->get_id();
        
        if (!isset($this->applied_discounts[$product_id])) {
            return;
        }
        
        $discount_info = $this->applied_discounts[$product_id];
        
        if ($discount_info['discount_percentage'] <= 0) {
            return;
        }
        
        ?>
        <span class="mads-ps-vip-discount-loop" style="display: inline-block; background: #e74c3c; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; margin-top: 5px;">
            <?php printf(__('-%s%% VIP', 'mad-suite'), round($discount_info['discount_percentage'], 0)); ?>
        </span>
        <?php
    }
    
    /**
     * Agregar columna de descuento
     */
    public function add_discount_column($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'price') {
                $new_columns['vip_discount'] = __('Dto. VIP', 'mad-suite');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Renderizar columna de descuento
     */
    public function render_discount_column($column, $post_id) {
        if ($column !== 'vip_discount') {
            return;
        }
        
        $discount_amount = get_post_meta($post_id, self::META_INDIVIDUAL_DISCOUNT, true);
        $discount_type = get_post_meta($post_id, self::META_INDIVIDUAL_DISCOUNT_TYPE, true);
        
        if (empty($discount_amount) || $discount_amount <= 0) {
            echo '<span style="color: #ccc;">—</span>';
            return;
        }
        
        if ($discount_type === 'percentage') {
            echo '<strong style="color: #e74c3c;">' . esc_html($discount_amount) . '%</strong>';
        } else {
            echo '<strong style="color: #e74c3c;">' . wc_price($discount_amount) . '</strong>';
        }
    }
    
    /**
     * Obtener descuento aplicado a un producto
     */
    public function get_applied_discount($product_id) {
        return isset($this->applied_discounts[$product_id]) ? $this->applied_discounts[$product_id] : null;
    }
    
    /**
     * Limpiar caché de precios
     */
    public function clear_pricing_cache() {
        $this->applied_discounts = [];
        $this->logger->info('Caché de precios limpiado');
    }
}