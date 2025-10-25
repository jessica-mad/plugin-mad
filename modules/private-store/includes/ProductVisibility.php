<?php
/**
 * Product Visibility Class
 * 
 * Controla qué productos son visibles en la tienda privada
 * Gestiona productos exclusivos VIP y visibilidad según contexto
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

namespace MAD_Suite\Modules\PrivateStore;

if (!defined('ABSPATH')) {
    exit;
}

class ProductVisibility {
    
    private static $instance = null;
    private $logger;
    private $is_private_store_context = false;
    
    /**
     * Meta key para productos VIP exclusivos
     */
    const META_VIP_ONLY = '_mads_ps_vip_only';
    
    /**
     * Meta key para ocultar en tienda regular
     */
    const META_HIDE_REGULAR = '_mads_ps_hide_regular';
    
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
        $this->logger = new Logger('private-store-visibility');
        
        // Detectar contexto de tienda privada
        add_action('init', [$this, 'detect_private_store_context'], 5);
        
        // Modificar queries de productos
        add_action('pre_get_posts', [$this, 'filter_products_query'], 999);
        add_filter('woocommerce_product_is_visible', [$this, 'filter_product_visibility'], 10, 2);
        
        // Meta box en editor de producto
        add_action('add_meta_boxes', [$this, 'add_product_meta_box']);
        add_action('save_post_product', [$this, 'save_product_meta'], 10, 2);
        
        // Columna en listado de productos
        add_filter('manage_edit-product_columns', [$this, 'add_product_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_product_column'], 10, 2);
        
        // Filtros masivos
        add_action('restrict_manage_posts', [$this, 'add_product_filters']);
        add_filter('parse_query', [$this, 'filter_products_by_vip_status']);
        
        // Avisos en producto
        add_action('woocommerce_single_product_summary', [$this, 'show_vip_badge'], 6);
        
        $this->logger->info('ProductVisibility inicializado');
    }
    
    /**
     * Detectar si estamos en contexto de tienda privada
     */
    public function detect_private_store_context() {
        // Verificar parámetro GET
        if (isset($_GET['private_store']) && $_GET['private_store'] == '1') {
            $this->is_private_store_context = true;
            $this->logger->debug('Contexto: Tienda Privada (parámetro GET)');
            return;
        }
        
        // Verificar endpoint de WooCommerce
        if (get_query_var('private-store', false) !== false) {
            $this->is_private_store_context = true;
            $this->logger->debug('Contexto: Tienda Privada (endpoint)');
            return;
        }
        
        // Verificar sesión
        if (WC()->session && WC()->session->get('private_store_mode')) {
            $this->is_private_store_context = true;
            $this->logger->debug('Contexto: Tienda Privada (sesión)');
            return;
        }
        
        $this->is_private_store_context = false;
    }
    
    /**
     * Verificar si estamos en tienda privada
     */
    public function is_private_store() {
        return $this->is_private_store_context;
    }
    
    /**
     * Filtrar query de productos según contexto
     */
    public function filter_products_query($query) {
        // Solo en queries principales de productos
        if (!$query->is_main_query() || is_admin()) {
            return;
        }
        
        // Solo para productos
        if (!isset($query->query_vars['post_type']) || $query->query_vars['post_type'] !== 'product') {
            return;
        }
        
        $user_is_vip = UserRole::instance()->is_vip_user();
        
        // TIENDA REGULAR (usuario normal)
        if (!$this->is_private_store() && !$user_is_vip) {
            $meta_query = $query->get('meta_query') ?: [];
            
            // Excluir productos VIP exclusivos
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => self::META_VIP_ONLY,
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => self::META_VIP_ONLY,
                    'value' => 'yes',
                    'compare' => '!='
                ]
            ];
            
            $query->set('meta_query', $meta_query);
            
            $this->logger->debug('Query filtrada: Tienda regular - productos VIP excluidos');
        }
        
        // TIENDA PRIVADA (usuario VIP)
        if ($this->is_private_store() && $user_is_vip) {
            // Los usuarios VIP ven todos los productos
            $this->logger->debug('Query: Tienda privada - todos los productos visibles');
        }
        
        // Usuario no VIP intentando acceder a tienda privada (no debería pasar)
        if ($this->is_private_store() && !$user_is_vip) {
            $this->logger->warning('Intento de acceso no autorizado a tienda privada', [
                'user_id' => get_current_user_id(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            // Redirigir a tienda regular
            wp_redirect(wc_get_page_permalink('shop'));
            exit;
        }
    }
    
    /**
     * Filtrar visibilidad individual de producto
     */
    public function filter_product_visibility($visible, $product_id) {
        $user_is_vip = UserRole::instance()->is_vip_user();
        $is_vip_only = get_post_meta($product_id, self::META_VIP_ONLY, true) === 'yes';
        
        // Si es producto VIP exclusivo y usuario no es VIP
        if ($is_vip_only && !$user_is_vip && !$this->is_private_store()) {
            $this->logger->debug("Producto #{$product_id} oculto para usuario no VIP");
            return false;
        }
        
        return $visible;
    }
    
    /**
     * Agregar meta box en editor de producto
     */
    public function add_product_meta_box() {
        add_meta_box(
            'mads_ps_product_visibility',
            __('Tienda Privada - Visibilidad', 'mad-suite'),
            [$this, 'render_product_meta_box'],
            'product',
            'side',
            'default'
        );
    }
    
    /**
     * Renderizar meta box de producto
     */
    public function render_product_meta_box($post) {
        wp_nonce_field('mads_ps_product_meta', 'mads_ps_product_meta_nonce');
        
        $vip_only = get_post_meta($post->ID, self::META_VIP_ONLY, true);
        $hide_regular = get_post_meta($post->ID, self::META_HIDE_REGULAR, true);
        
        ?>
        <div class="mads-ps-product-options">
            <p>
                <label>
                    <input type="checkbox" 
                           name="mads_ps_vip_only" 
                           value="yes" 
                           <?php checked($vip_only, 'yes'); ?>>
                    <strong><?php _e('Producto exclusivo VIP', 'mad-suite'); ?></strong>
                </label>
                <br>
                <small style="color: #666;">
                    <?php _e('Solo visible para usuarios VIP en la tienda privada', 'mad-suite'); ?>
                </small>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" 
                           name="mads_ps_hide_regular" 
                           value="yes" 
                           <?php checked($hide_regular, 'yes'); ?>>
                    <?php _e('Ocultar en tienda regular', 'mad-suite'); ?>
                </label>
                <br>
                <small style="color: #666;">
                    <?php _e('No aparecerá en búsquedas ni listados públicos', 'mad-suite'); ?>
                </small>
            </p>
            
            <?php if ($vip_only === 'yes'): ?>
                <div style="padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107; margin-top: 10px;">
                    <small>
                        <span class="dashicons dashicons-star-filled" style="color: #FFD700;"></span>
                        <?php _e('Este producto solo es visible para clientes VIP', 'mad-suite'); ?>
                    </small>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
            .mads-ps-product-options label { display: block; margin-bottom: 8px; }
            .mads-ps-product-options small { margin-left: 22px; display: block; }
        </style>
        <?php
    }
    
    /**
     * Guardar meta de producto
     */
    public function save_product_meta($post_id, $post) {
        // Verificar nonce
        if (!isset($_POST['mads_ps_product_meta_nonce']) || 
            !wp_verify_nonce($_POST['mads_ps_product_meta_nonce'], 'mads_ps_product_meta')) {
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
        
        $old_vip_only = get_post_meta($post_id, self::META_VIP_ONLY, true);
        $old_hide_regular = get_post_meta($post_id, self::META_HIDE_REGULAR, true);
        
        // VIP Only
        $vip_only = isset($_POST['mads_ps_vip_only']) && $_POST['mads_ps_vip_only'] === 'yes' ? 'yes' : 'no';
        update_post_meta($post_id, self::META_VIP_ONLY, $vip_only);
        
        // Hide in regular store
        $hide_regular = isset($_POST['mads_ps_hide_regular']) && $_POST['mads_ps_hide_regular'] === 'yes' ? 'yes' : 'no';
        update_post_meta($post_id, self::META_HIDE_REGULAR, $hide_regular);
        
        // Log cambios
        if ($old_vip_only !== $vip_only || $old_hide_regular !== $hide_regular) {
            $this->logger->info("Visibilidad de producto actualizada", [
                'product_id' => $post_id,
                'product_name' => $post->post_title,
                'vip_only' => $vip_only,
                'hide_regular' => $hide_regular,
                'previous' => [
                    'vip_only' => $old_vip_only,
                    'hide_regular' => $old_hide_regular
                ]
            ]);
        }
    }
    
    /**
     * Agregar columna en listado de productos
     */
    public function add_product_column($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Insertar después de la columna de precio
            if ($key === 'price') {
                $new_columns['vip_visibility'] = __('VIP', 'mad-suite');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Renderizar columna de producto
     */
    public function render_product_column($column, $post_id) {
        if ($column !== 'vip_visibility') {
            return;
        }
        
        $vip_only = get_post_meta($post_id, self::META_VIP_ONLY, true);
        $hide_regular = get_post_meta($post_id, self::META_HIDE_REGULAR, true);
        
        if ($vip_only === 'yes') {
            echo '<span class="dashicons dashicons-star-filled" style="color: #FFD700;" title="' . 
                 esc_attr__('Exclusivo VIP', 'mad-suite') . '"></span>';
        } elseif ($hide_regular === 'yes') {
            echo '<span class="dashicons dashicons-hidden" style="color: #999;" title="' . 
                 esc_attr__('Oculto en tienda regular', 'mad-suite') . '"></span>';
        } else {
            echo '<span class="dashicons dashicons-visibility" style="color: #ccc;" title="' . 
                 esc_attr__('Visible para todos', 'mad-suite') . '"></span>';
        }
    }
    
    /**
     * Agregar filtros en listado de productos
     */
    public function add_product_filters($post_type) {
        if ($post_type !== 'product') {
            return;
        }
        
        $current = isset($_GET['vip_visibility']) ? sanitize_key($_GET['vip_visibility']) : '';
        
        ?>
        <select name="vip_visibility">
            <option value=""><?php _e('Todos los productos', 'mad-suite'); ?></option>
            <option value="vip_only" <?php selected($current, 'vip_only'); ?>>
                <?php _e('Solo productos VIP', 'mad-suite'); ?>
            </option>
            <option value="regular" <?php selected($current, 'regular'); ?>>
                <?php _e('Solo productos regulares', 'mad-suite'); ?>
            </option>
            <option value="hidden" <?php selected($current, 'hidden'); ?>>
                <?php _e('Ocultos en tienda regular', 'mad-suite'); ?>
            </option>
        </select>
        <?php
    }
    
    /**
     * Filtrar productos por estado VIP
     */
    public function filter_products_by_vip_status($query) {
        global $pagenow, $typenow;
        
        if ($pagenow !== 'edit.php' || $typenow !== 'product' || !is_admin()) {
            return $query;
        }
        
        if (!isset($_GET['vip_visibility']) || empty($_GET['vip_visibility'])) {
            return $query;
        }
        
        $filter = sanitize_key($_GET['vip_visibility']);
        $meta_query = $query->get('meta_query') ?: [];
        
        switch ($filter) {
            case 'vip_only':
                $meta_query[] = [
                    'key' => self::META_VIP_ONLY,
                    'value' => 'yes',
                    'compare' => '='
                ];
                break;
                
            case 'regular':
                $meta_query[] = [
                    'relation' => 'AND',
                    [
                        'key' => self::META_VIP_ONLY,
                        'compare' => 'NOT EXISTS'
                    ]
                ];
                break;
                
            case 'hidden':
                $meta_query[] = [
                    'key' => self::META_HIDE_REGULAR,
                    'value' => 'yes',
                    'compare' => '='
                ];
                break;
        }
        
        $query->set('meta_query', $meta_query);
        
        return $query;
    }
    
    /**
     * Mostrar badge VIP en página de producto
     */
    public function show_vip_badge() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $vip_only = get_post_meta($product->get_id(), self::META_VIP_ONLY, true);
        
        if ($vip_only === 'yes' && $this->is_private_store()) {
            echo '<div class="mads-ps-vip-badge" style="display: inline-block; padding: 5px 12px; background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%); color: #000; border-radius: 20px; font-size: 12px; font-weight: bold; margin-bottom: 10px;">';
            echo '<span class="dashicons dashicons-star-filled" style="font-size: 14px; margin-right: 4px; vertical-align: middle;"></span>';
            echo esc_html__('PRODUCTO EXCLUSIVO VIP', 'mad-suite');
            echo '</div>';
        }
    }
    
    /**
     * Obtener productos VIP exclusivos
     */
    public function get_vip_products($args = []) {
        $defaults = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => self::META_VIP_ONLY,
                    'value' => 'yes',
                    'compare' => '='
                ]
            ]
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        return get_posts($args);
    }
    
    /**
     * Contar productos VIP
     */
    public function count_vip_products() {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_key = %s
            AND pm.meta_value = 'yes'
        ", self::META_VIP_ONLY));
        
        return intval($count);
    }
}