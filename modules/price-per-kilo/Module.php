<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Módulo: Precio por Kilo
 * Muestra automáticamente el precio/kg en productos con peso
 */

return new class($core ?? null) implements MAD_Suite_Module {
    
    private $core;
    private $settings = [];
    
    public function __construct($core) {
        $this->core = $core;
    }
    
    public function slug() {
        return 'price-per-kilo';
    }
    
    public function title() {
        return __('Precio por Kilo', 'mad-suite');
    }
    
    public function menu_label() {
        return __('Precio por Kilo', 'mad-suite');
    }
    
    public function menu_slug() {
        return 'mad-suite-price-per-kilo';
    }
    
    /* ===== INICIALIZACIÓN ===== */
    
    public function init() {
        $this->settings = get_option(MAD_Suite_Core::option_key($this->slug()), []);
        
        if (!empty($this->settings['enabled'])) {
            // Para productos simples - inyectar directamente en el HTML del precio
            add_filter('woocommerce_get_price_html', [$this, 'append_price_per_kilo_to_html'], 100, 2);
            
            // Para variaciones - añadir al array de datos
            add_filter('woocommerce_available_variation', [$this, 'add_variation_price_per_kilo'], 10, 3);
            
            // Enqueue scripts para variaciones
            add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        }
    }
    
    public function admin_init() {
        register_setting('madsuite_price_per_kilo_group', MAD_Suite_Core::option_key($this->slug()));
        
        add_settings_section(
            'mad_ppk_main',
            __('Configuración General', 'mad-suite'),
            [$this, 'render_main_section'],
            $this->menu_slug()
        );
        
        add_settings_field(
            'mad_ppk_enabled',
            __('Activar módulo', 'mad-suite'),
            [$this, 'render_enabled_field'],
            $this->menu_slug(),
            'mad_ppk_main'
        );
        
        add_settings_field(
            'mad_ppk_unit',
            __('Unidad de medida', 'mad-suite'),
            [$this, 'render_unit_field'],
            $this->menu_slug(),
            'mad_ppk_main'
        );
        
        add_settings_field(
            'mad_ppk_text_format',
            __('Formato del texto', 'mad-suite'),
            [$this, 'render_text_format_field'],
            $this->menu_slug(),
            'mad_ppk_main'
        );
        
        // Sección de ámbito
        add_settings_section(
            'mad_ppk_scope',
            __('Ámbito de aplicación', 'mad-suite'),
            [$this, 'render_scope_section'],
            $this->menu_slug()
        );
        
        add_settings_field(
            'mad_ppk_scope_cats',
            __('Categorías', 'mad-suite'),
            [$this, 'render_scope_cats_field'],
            $this->menu_slug(),
            'mad_ppk_scope'
        );
        
        add_settings_field(
            'mad_ppk_scope_tags',
            __('Etiquetas', 'mad-suite'),
            [$this, 'render_scope_tags_field'],
            $this->menu_slug(),
            'mad_ppk_scope'
        );
        
        add_settings_field(
            'mad_ppk_scope_products',
            __('Productos específicos', 'mad-suite'),
            [$this, 'render_scope_products_field'],
            $this->menu_slug(),
            'mad_ppk_scope'
        );
        
        // AJAX para búsqueda de productos
        add_action('wp_ajax_mad_ppk_search_products', [$this, 'ajax_search_products']);
    }
    
    /* ===== RENDERIZADO DE LA PÁGINA DE AJUSTES ===== */
    
    public function render_settings_page() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_die(__('No tienes permisos suficientes.', 'mad-suite'));
        }
        
        $this->enqueue_admin_scripts();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($this->title()); ?></h1>
            <p><?php _e('Muestra automáticamente el precio por kilogramo en productos que tengan peso configurado.', 'mad-suite'); ?></p>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('madsuite_price_per_kilo_group');
                do_settings_sections($this->menu_slug());
                submit_button();
                ?>
            </form>
        </div>
        
        <style>
            .mad-ppk-tree ul { list-style: none; margin-left: 20px; }
            .mad-ppk-tree > ul { margin-left: 0; }
            .mad-ppk-pill {
                display: inline-block;
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-radius: 3px;
                padding: 4px 8px;
                margin: 4px 4px 4px 0;
                font-size: 13px;
            }
            .mad-ppk-del {
                color: #b32d2e;
                text-decoration: none;
                font-weight: bold;
                margin-left: 6px;
            }
            .mad-ppk-del:hover { color: #dc3232; }
            .mad-ppk-hint { font-style: italic; color: #646970; margin-top: 8px; }
        </style>
        <?php
    }
    
    /* ===== SECCIONES ===== */
    
    public function render_main_section() {
        echo '<p>' . __('Configura cómo y dónde se mostrará el precio por kilo.', 'mad-suite') . '</p>';
    }
    
    public function render_scope_section() {
        echo '<p>' . __('Selecciona las categorías, etiquetas o productos específicos donde aplicar el precio por kilo.', 'mad-suite') . '</p>';
        echo '<p class="mad-ppk-hint">' . __('Si no seleccionas nada, se aplicará a todos los productos con peso.', 'mad-suite') . '</p>';
    }
    
    /* ===== CAMPOS ===== */
    
    public function render_enabled_field() {
        $enabled = !empty($this->settings['enabled']) ? 1 : 0;
        printf(
            '<label><input type="checkbox" name="%s[enabled]" value="1" %s> %s</label>',
            esc_attr(MAD_Suite_Core::option_key($this->slug())),
            checked($enabled, 1, false),
            __('Mostrar precio por kilo en productos con peso', 'mad-suite')
        );
    }
    
    public function render_unit_field() {
        $unit = $this->settings['unit'] ?? 'kg';
        $option_key = MAD_Suite_Core::option_key($this->slug());
        ?>
        <select name="<?php echo esc_attr($option_key); ?>[unit]">
            <option value="kg" <?php selected($unit, 'kg'); ?>>Kilogramo (kg)</option>
            <option value="g" <?php selected($unit, 'g'); ?>>Gramo (g)</option>
        </select>
        <p class="mad-ppk-hint"><?php _e('El peso del producto debe estar en la misma unidad configurada en WooCommerce.', 'mad-suite'); ?></p>
        <?php
    }
    
    public function render_text_format_field() {
        $format = $this->settings['text_format'] ?? '({price}/{unit})';
        $option_key = MAD_Suite_Core::option_key($this->slug());
        ?>
        <input type="text" name="<?php echo esc_attr($option_key); ?>[text_format]" 
               value="<?php echo esc_attr($format); ?>" 
               class="regular-text" 
               placeholder="({price}/{unit})">
        <p class="mad-ppk-hint">
            <?php _e('Variables disponibles: {price} = precio calculado, {unit} = kg/g', 'mad-suite'); ?><br>
            <?php _e('Ejemplo: "({price}/{unit})" mostrará "(5,50 €/kg)"', 'mad-suite'); ?>
        </p>
        <?php
    }
    
    public function render_scope_cats_field() {
        $scope_cats = $this->settings['scope_cats'] ?? [];
        $all_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0]);
        
        echo '<div class="mad-ppk-tree"><ul>';
        if ($all_cats && !is_wp_error($all_cats)) {
            foreach ($all_cats as $root) {
                $this->render_cat_branch($root->term_id, $scope_cats);
            }
        } else {
            echo '<li><em>' . __('No hay categorías.', 'mad-suite') . '</em></li>';
        }
        echo '</ul></div>';
        echo '<p class="mad-ppk-hint">' . __('Marcar una categoría padre incluye todas sus subcategorías.', 'mad-suite') . '</p>';
    }
    
    private function render_cat_branch($term_id, $selected) {
        $term = get_term($term_id);
        $children = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $term_id]);
        $option_key = MAD_Suite_Core::option_key($this->slug());
        
        echo '<li>';
        printf(
            '<label><input type="checkbox" name="%s[scope_cats][]" value="%d" %s> %s</label>',
            esc_attr($option_key),
            $term_id,
            in_array($term_id, $selected, true) ? 'checked' : '',
            esc_html($term->name)
        );
        
        if ($children && !is_wp_error($children)) {
            echo '<ul>';
            foreach ($children as $child) {
                $this->render_cat_branch($child->term_id, $selected);
            }
            echo '</ul>';
        }
        echo '</li>';
    }
    
    public function render_scope_tags_field() {
        $scope_tags = $this->settings['scope_tags'] ?? [];
        $all_tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);
        $option_key = MAD_Suite_Core::option_key($this->slug());
        
        if ($all_tags && !is_wp_error($all_tags)) {
            echo '<div style="max-height:200px;overflow:auto;border:1px solid #ddd;padding:8px;">';
            foreach ($all_tags as $tag) {
                printf(
                    '<label style="display:inline-block;min-width:200px;margin:4px 10px 4px 0;"><input type="checkbox" name="%s[scope_tags][]" value="%d" %s> %s</label>',
                    esc_attr($option_key),
                    $tag->term_id,
                    in_array($tag->term_id, $scope_tags, true) ? 'checked' : '',
                    esc_html($tag->name)
                );
            }
            echo '</div>';
        } else {
            echo '<p><em>' . __('No hay etiquetas.', 'mad-suite') . '</em></p>';
        }
    }
    
    public function render_scope_products_field() {
        $scope_products = $this->settings['scope_products'] ?? [];
        ?>
        <div style="margin-bottom:12px;">
            <input type="text" id="mad_ppk_product_search" 
                   placeholder="<?php esc_attr_e('Buscar productos…', 'mad-suite'); ?>" 
                   style="min-width:300px;">
            <button type="button" class="button" id="mad_ppk_add_product">
                <?php _e('Añadir', 'mad-suite'); ?>
            </button>
        </div>
        
        <div id="mad_ppk_product_list">
            <?php
            if (!empty($scope_products)) {
                foreach ($scope_products as $product_id) {
                    $this->render_product_pill($product_id);
                }
            }
            ?>
        </div>
        <?php
    }
    
    private function render_product_pill($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) return;
        
        $title = $product->get_name();
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            $title = $parent->get_name() . ' - ' . wc_get_formatted_variation($product, true);
        }
        
        $option_key = MAD_Suite_Core::option_key($this->slug());
        
        printf(
            '<span class="mad-ppk-pill" data-id="%d">%s <a href="#" class="mad-ppk-del" title="%s">×</a><input type="hidden" name="%s[scope_products][]" value="%d"></span>',
            $product_id,
            esc_html('#' . $product_id . ' - ' . $title),
            esc_attr__('Quitar', 'mad-suite'),
            esc_attr($option_key),
            $product_id
        );
    }
    
    /* ===== AJAX ===== */
    
    public function ajax_search_products() {
        check_ajax_referer('mad_ppk_nonce', 'nonce');
        
        $query = sanitize_text_field($_GET['q'] ?? '');
        $results = [];
        
        if (strlen($query) >= 2) {
            $args = [
                'post_type' => ['product', 'product_variation'],
                'posts_per_page' => 20,
                's' => $query,
                'post_status' => 'publish'
            ];
            
            $products = get_posts($args);
            
            foreach ($products as $post) {
                $product = wc_get_product($post->ID);
                if (!$product) continue;
                
                $title = $product->get_name();
                if ($product->is_type('variation')) {
                    $parent = wc_get_product($product->get_parent_id());
                    $title = $parent->get_name() . ' - ' . wc_get_formatted_variation($product, true);
                }
                
                $results[] = [
                    'id' => $post->ID,
                    'text' => '#' . $post->ID . ' - ' . $title
                ];
            }
        }
        
        wp_send_json(['results' => $results]);
    }
    
    /* ===== ENQUEUE SCRIPTS ===== */
    
    public function enqueue_admin_scripts() {
        ?>
        <script>
        var MAD_PPK_ADMIN = {
            ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('mad_ppk_nonce'); ?>'
        };
        </script>
        <script>
        jQuery(document).ready(function($){
            var $searchInput = $('#mad_ppk_product_search');
            var $productList = $('#mad_ppk_product_list');
            
            function searchProducts(query, callback) {
                $.get(MAD_PPK_ADMIN.ajax_url, {
                    action: 'mad_ppk_search_products',
                    nonce: MAD_PPK_ADMIN.nonce,
                    q: query
                }, function(response) {
                    callback(response && response.results ? response.results : []);
                });
            }
            
            $('#mad_ppk_add_product').on('click', function(e) {
                e.preventDefault();
                var query = $searchInput.val().trim();
                if (!query) return;
                
                searchProducts(query, function(results) {
                    if (!results.length) {
                        alert('<?php echo esc_js(__('No se encontraron productos', 'mad-suite')); ?>');
                        return;
                    }
                    
                    var product = results[0];
                    
                    if ($productList.find('[data-id="' + product.id + '"]').length) {
                        alert('<?php echo esc_js(__('Este producto ya está añadido', 'mad-suite')); ?>');
                        return;
                    }
                    
                    var optionKey = '<?php echo esc_js(MAD_Suite_Core::option_key($this->slug())); ?>';
                    var html = '<span class="mad-ppk-pill" data-id="' + product.id + '">' +
                               product.text + 
                               ' <a href="#" class="mad-ppk-del" title="<?php echo esc_js(__('Quitar', 'mad-suite')); ?>">×</a>' +
                               '<input type="hidden" name="' + optionKey + '[scope_products][]" value="' + product.id + '">' +
                               '</span>';
                    
                    $productList.append(html);
                    $searchInput.val('');
                });
            });
            
            $productList.on('click', '.mad-ppk-del', function(e) {
                e.preventDefault();
                $(this).closest('.mad-ppk-pill').remove();
            });
        });
        </script>
        <?php
    }
    
    public function enqueue_frontend_scripts() {
        if (!is_product()) return;
        
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($){
                var variationForm = $('form.variations_form');
                
                // Guardar el HTML original del precio por kilo
                var originalPricePerKilo = $('.mad-price-per-kilo').html();
                
                // Cuando se selecciona una variación
                variationForm.on('found_variation', function(event, variation) {
                    if (variation.price_per_kilo_html) {
                        // Buscar el contenedor del precio de la variación
                        var priceContainer = $('.woocommerce-variation-price');
                        
                        // Eliminar cualquier precio por kilo existente
                        priceContainer.find('.mad-price-per-kilo').remove();
                        
                        // Añadir el nuevo precio por kilo justo después del precio
                        priceContainer.append('<div class=\"mad-price-per-kilo\" style=\"margin: 8px 0; font-size: 0.9em; color: #666;\">' + variation.price_per_kilo_html + '</div>');
                    }
                });
                
                // Cuando se resetea la selección
                variationForm.on('reset_data', function() {
                    $('.woocommerce-variation-price .mad-price-per-kilo').remove();
                    if (originalPricePerKilo) {
                        $('.summary .mad-price-per-kilo').html(originalPricePerKilo);
                    }
                });
            });
        ");
    }
    
    /* ===== FRONTEND: MOSTRAR PRECIO POR KILO ===== */
    
    /**
     * Añade el precio por kilo directamente al HTML del precio
     * Funciona para productos simples
     */
    public function append_price_per_kilo_to_html($price_html, $product) {
        // Solo en páginas de producto individual
        if (!is_product()) {
            return $price_html;
        }
        
        // Solo para productos simples (las variaciones se manejan por separado)
        if ($product->is_type('variable')) {
            return $price_html;
        }
        
        if (!$this->should_show_for_product($product)) {
            return $price_html;
        }
        
        $per_kilo = $this->get_price_per_kilo_html($product);
        
        if ($per_kilo) {
            $price_html .= '<div class="mad-price-per-kilo" style="margin: 8px 0; font-size: 0.9em; color: #666;">' . $per_kilo . '</div>';
        }
        
        return $price_html;
    }
    
    /**
     * Añade el precio por kilo a los datos de las variaciones
     */
    public function add_variation_price_per_kilo($variation_data, $product, $variation) {
        if (!$this->should_show_for_product($variation)) {
            return $variation_data;
        }
        
        $html = $this->get_price_per_kilo_html($variation);
        $variation_data['price_per_kilo_html'] = $html;
        
        return $variation_data;
    }
    
    private function get_price_per_kilo_html($product) {
        $weight_raw = $product->get_weight();
        $weight = $this->normalize_decimal($weight_raw);
        
        if ($weight <= 0) {
            return '';
        }
        
        $price = (float) $product->get_price();
        if ($price <= 0) {
            return '';
        }
        
        $unit = $this->settings['unit'] ?? 'kg';
        $wc_weight_unit = get_option('woocommerce_weight_unit');
        
        $weight_in_unit = $weight;
        
        if ($unit === 'g' && $wc_weight_unit === 'kg') {
            $weight_in_unit = $weight * 1000;
        } elseif ($unit === 'kg' && $wc_weight_unit === 'g') {
            $weight_in_unit = $weight / 1000;
        }
        
        if ($weight_in_unit <= 0) {
            return '';
        }
        
        $price_per_unit = $price / $weight_in_unit;
        $formatted_price = wc_price($price_per_unit);
        
        $format = $this->settings['text_format'] ?? '({price}/{unit})';
        $text = str_replace(
            ['{price}', '{unit}'],
            [$formatted_price, $unit],
            $format
        );
        
        return $text;
    }
    
    private function normalize_decimal($value) {
        if (empty($value)) {
            return 0;
        }
        
        if (is_numeric($value)) {
            return (float) $value;
        }
        
        $value = (string) $value;
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value);
        
        return (float) $value;
    }
    
    private function should_show_for_product($product) {
        if (!$product) return false;
        
        $product_id = $product->get_id();
        $parent_id = $product->get_parent_id();
        
        $has_scope = !empty($this->settings['scope_cats']) || 
                     !empty($this->settings['scope_tags']) || 
                     !empty($this->settings['scope_products']);
        
        if (!$has_scope) {
            return true;
        }
        
        if (!empty($this->settings['scope_products'])) {
            if (in_array($product_id, $this->settings['scope_products']) ||
                in_array($parent_id, $this->settings['scope_products'])) {
                return true;
            }
        }
        
        if (!empty($this->settings['scope_cats'])) {
            $check_id = $parent_id ?: $product_id;
            $terms = wp_get_post_terms($check_id, 'product_cat', ['fields' => 'ids']);
            if (!is_wp_error($terms) && array_intersect($terms, $this->settings['scope_cats'])) {
                return true;
            }
        }
        
        if (!empty($this->settings['scope_tags'])) {
            $check_id = $parent_id ?: $product_id;
            $terms = wp_get_post_terms($check_id, 'product_tag', ['fields' => 'ids']);
            if (!is_wp_error($terms) && array_intersect($terms, $this->settings['scope_tags'])) {
                return true;
            }
        }
        
        return false;
    }
};