<?php
/**
 * Plantillas de configurador — Divano Toscano.
 *
 * CPT interno (mad_dt_template) donde el admin define, por plantilla, una
 * secuencia de pasos (uno por atributo de WooCommerce) con las opciones a
 * ofrecer y su recargo (fijo o %), más reglas simples de "mostrar este paso
 * solo si...". Cada plantilla se asigna a productos por categoría, etiqueta
 * o producto específico (prioridad: producto > etiqueta > categoría).
 *
 * En el frontend, el paso se pinta como botones-swatch reutilizando las
 * imágenes ya configuradas en el término del atributo (feature existente
 * de este módulo). El recargo solo se muestra a usuarios que NO tengan el
 * rol de presupuesto (ver módulo Quotes); para presupuesto nunca aparece
 * ningún precio en el DOM.
 *
 * @package MAD_Suite/DiVanoToscano
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_DT_Templates {

    const CPT = 'mad_dt_template';

    /* ================================================================ */
    /*  Registro                                                         */
    /* ================================================================ */

    /**
     * Llamado desde Module::init() — ya se ejecuta dentro del hook 'init'
     * de WordPress, así que registrar el CPT aquí directamente es seguro.
     */
    public function init(): void {
        $this->register_cpt();

        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_configurator' ], 5 );
        add_filter( 'woocommerce_add_cart_item_data',        [ $this, 'add_selection_to_cart_item' ], 10, 3 );
        add_filter( 'woocommerce_get_item_data',             [ $this, 'display_selection_in_cart' ], 10, 2 );
        add_action( 'woocommerce_before_calculate_totals',   [ $this, 'apply_price_deltas_to_cart' ], 20 );

        add_action( 'wp_head',   [ $this, 'frontend_css' ] );
        add_action( 'wp_footer', [ $this, 'frontend_js' ] );

        // Enganche con el módulo Quotes: recibe el ítem de pedido recién creado
        // a partir del ítem de carrito, para copiar la selección + precio sugerido.
        add_action( 'mad_quotes_order_item_created', [ $this, 'attach_selection_to_quote_order_item' ], 10, 2 );
    }

    /** Llamado desde Module::admin_init(). */
    public function admin_init(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_' . self::CPT, [ $this, 'save_meta_boxes' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_mad_dt_search_products', [ $this, 'ajax_search_products' ] );
    }

    private function register_cpt(): void {
        register_post_type( self::CPT, [
            'labels' => [
                'name'          => __( 'Plantillas configurador', 'mad-suite' ),
                'singular_name' => __( 'Plantilla configurador', 'mad-suite' ),
                'add_new'       => __( 'Añadir nueva', 'mad-suite' ),
                'add_new_item'  => __( 'Nueva plantilla de configurador', 'mad-suite' ),
                'edit_item'     => __( 'Editar plantilla', 'mad-suite' ),
                'all_items'     => __( 'Plantillas configurador', 'mad-suite' ),
                'search_items'  => __( 'Buscar plantillas', 'mad-suite' ),
                'not_found'     => __( 'No hay plantillas todavía.', 'mad-suite' ),
            ],
            'public'              => false,
            'show_ui'             => true,
            // OJO: no usar aquí el slug del menú raíz de MAD Suite. WordPress añade
            // el submenú de los CPT (_add_post_type_submenus) al hook 'admin_menu'
            // antes que los submenús de los módulos (que se registran durante
            // 'init'), así que el CPT quedaría primero en el submenú y WordPress
            // usaría esa URL como destino del enlace del menú padre "MAD Plugins",
            // rompiendo el acceso al editor de módulos. Se accede a esta pantalla
            // solo mediante el enlace de la página de ajustes de Divano Toscano.
            'show_in_menu'        => false,
            'exclude_from_search' => true,
            'capability_type'     => 'page',
            'supports'            => [ 'title' ],
            'menu_icon'           => 'dashicons-forms',
        ] );
    }

    /* ================================================================ */
    /*  Meta boxes (admin)                                               */
    /* ================================================================ */

    public function add_meta_boxes(): void {
        add_meta_box(
            'mad_dt_steps',
            __( 'Pasos del configurador', 'mad-suite' ),
            [ $this, 'render_steps_meta_box' ],
            self::CPT,
            'normal',
            'high'
        );

        add_meta_box(
            'mad_dt_scope',
            __( 'Asignar esta plantilla a…', 'mad-suite' ),
            [ $this, 'render_scope_meta_box' ],
            self::CPT,
            'side',
            'default'
        );
    }

    public function render_steps_meta_box( WP_Post $post ): void {
        $steps = get_post_meta( $post->ID, '_mad_dt_steps', true );
        if ( ! is_array( $steps ) ) $steps = [];

        wp_nonce_field( 'mad_dt_save_template', 'mad_dt_template_nonce' );
        ?>
        <p class="description">
            <?php esc_html_e( 'Cada paso corresponde a un atributo de WooCommerce ya configurado (pa_*). Marca las opciones que quieres ofrecer en este paso y, si al elegirla debe cambiar el precio, indica el recargo. Las imágenes se toman de las que ya asignaste al término del atributo.', 'mad-suite' ); ?>
        </p>
        <div id="mad-dt-steps-app" data-steps="<?php echo esc_attr( wp_json_encode( $steps ) ); ?>"></div>
        <p><button type="button" class="button button-primary" id="mad-dt-add-step"><?php esc_html_e( '+ Añadir paso', 'mad-suite' ); ?></button></p>
        <input type="hidden" name="mad_dt_steps_json" id="mad-dt-steps-json" value="">
        <?php
    }

    public function render_scope_meta_box( WP_Post $post ): void {
        $scope_cats     = array_map( 'absint', (array) get_post_meta( $post->ID, '_mad_dt_scope_cats', true ) );
        $scope_tags     = array_map( 'absint', (array) get_post_meta( $post->ID, '_mad_dt_scope_tags', true ) );
        $scope_products = array_map( 'absint', (array) get_post_meta( $post->ID, '_mad_dt_scope_products', true ) );
        ?>
        <p class="description">
            <?php esc_html_e( 'Si varias plantillas aplican al mismo producto, gana en este orden: producto específico → etiqueta → categoría.', 'mad-suite' ); ?>
        </p>

        <h4><?php esc_html_e( 'Categorías', 'mad-suite' ); ?></h4>
        <div class="mad-dt-scope-tree" style="max-height:160px;overflow:auto;border:1px solid #dcdcde;padding:6px;">
            <ul style="margin:0;">
                <?php
                $roots = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0 ] );
                if ( $roots && ! is_wp_error( $roots ) ) {
                    foreach ( $roots as $root ) {
                        $this->render_cat_branch( $root->term_id, $scope_cats );
                    }
                } else {
                    echo '<li><em>' . esc_html__( 'No hay categorías.', 'mad-suite' ) . '</em></li>';
                }
                ?>
            </ul>
        </div>

        <h4><?php esc_html_e( 'Etiquetas', 'mad-suite' ); ?></h4>
        <div style="max-height:120px;overflow:auto;border:1px solid #dcdcde;padding:6px;">
            <?php
            $tags = get_terms( [ 'taxonomy' => 'product_tag', 'hide_empty' => false ] );
            if ( $tags && ! is_wp_error( $tags ) ) {
                foreach ( $tags as $tag ) {
                    printf(
                        '<label style="display:block;margin:2px 0;"><input type="checkbox" name="mad_dt_scope_tags[]" value="%d" %s> %s</label>',
                        $tag->term_id,
                        checked( in_array( $tag->term_id, $scope_tags, true ), true, false ),
                        esc_html( $tag->name )
                    );
                }
            } else {
                echo '<em>' . esc_html__( 'No hay etiquetas.', 'mad-suite' ) . '</em>';
            }
            ?>
        </div>

        <h4><?php esc_html_e( 'Productos específicos', 'mad-suite' ); ?></h4>
        <input type="text" id="mad_dt_scope_product_search" placeholder="<?php esc_attr_e( 'Buscar productos…', 'mad-suite' ); ?>" style="width:100%;margin-bottom:6px;">
        <div id="mad_dt_scope_product_list">
            <?php foreach ( $scope_products as $pid ) : $p = wc_get_product( $pid ); if ( ! $p ) continue; ?>
                <span class="mad-dt-scope-pill" data-id="<?php echo esc_attr( $pid ); ?>" style="display:inline-block;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:3px 6px;margin:2px 2px 2px 0;font-size:12px;">
                    <?php echo esc_html( '#' . $pid . ' - ' . $p->get_name() ); ?>
                    <a href="#" class="mad-dt-scope-del" style="color:#b32d2e;text-decoration:none;font-weight:bold;margin-left:4px;">&times;</a>
                    <input type="hidden" name="mad_dt_scope_products[]" value="<?php echo esc_attr( $pid ); ?>">
                </span>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_cat_branch( int $term_id, array $selected ): void {
        $term     = get_term( $term_id, 'product_cat' );
        $children = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $term_id ] );
        if ( ! $term || is_wp_error( $term ) ) return;

        echo '<li>';
        printf(
            '<label><input type="checkbox" name="mad_dt_scope_cats[]" value="%d" %s> %s</label>',
            $term_id,
            checked( in_array( $term_id, $selected, true ), true, false ),
            esc_html( $term->name )
        );
        if ( $children && ! is_wp_error( $children ) ) {
            echo '<ul style="margin-left:16px;">';
            foreach ( $children as $child ) {
                $this->render_cat_branch( $child->term_id, $selected );
            }
            echo '</ul>';
        }
        echo '</li>';
    }

    public function save_meta_boxes( int $post_id ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['mad_dt_template_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mad_dt_template_nonce'] ) ), 'mad_dt_save_template' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Pasos.
        $raw     = isset( $_POST['mad_dt_steps_json'] ) ? wp_unslash( $_POST['mad_dt_steps_json'] ) : '[]';
        $decoded = json_decode( $raw, true );
        $steps   = [];

        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $step ) {
                $tax = isset( $step['attribute'] ) ? sanitize_key( $step['attribute'] ) : '';
                if ( ! $tax || ! taxonomy_exists( $tax ) ) continue;

                $clean_options = [];
                foreach ( (array) ( $step['options'] ?? [] ) as $opt ) {
                    $term_id = absint( $opt['term_id'] ?? 0 );
                    if ( ! $term_id || ! get_term( $term_id, $tax ) || is_wp_error( get_term( $term_id, $tax ) ) ) continue;

                    $delta_type = in_array( $opt['delta_type'] ?? '', [ 'none', 'fixed', 'percent' ], true ) ? $opt['delta_type'] : 'none';
                    $clean_options[] = [
                        'term_id'     => $term_id,
                        'delta_type'  => $delta_type,
                        'delta_value' => round( (float) ( $opt['delta_value'] ?? 0 ), 2 ),
                    ];
                }
                if ( ! $clean_options ) continue;

                $steps[] = [
                    'id'                => preg_replace( '/[^a-z0-9_]/', '', sanitize_key( $step['id'] ?? uniqid( 'step_' ) ) ),
                    'attribute'         => $tax,
                    'label'             => sanitize_text_field( $step['label'] ?? '' ),
                    'options'           => $clean_options,
                    'reveal_when_step'  => sanitize_key( $step['reveal_when_step'] ?? '' ),
                    'reveal_when_slugs' => array_map( 'sanitize_title', (array) ( $step['reveal_when_slugs'] ?? [] ) ),
                ];
            }
        }
        update_post_meta( $post_id, '_mad_dt_steps', $steps );

        // Asignación.
        update_post_meta( $post_id, '_mad_dt_scope_cats', array_map( 'absint', (array) ( $_POST['mad_dt_scope_cats'] ?? [] ) ) );
        update_post_meta( $post_id, '_mad_dt_scope_tags', array_map( 'absint', (array) ( $_POST['mad_dt_scope_tags'] ?? [] ) ) );
        update_post_meta( $post_id, '_mad_dt_scope_products', array_map( 'absint', (array) ( $_POST['mad_dt_scope_products'] ?? [] ) ) );
    }

    /* ================================================================ */
    /*  Resolución: qué plantilla aplica a un producto                   */
    /* ================================================================ */

    public function find_template_for_product( WC_Product $product ): ?int {
        $product_id = $product->get_id();

        $templates = get_posts( [
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        if ( ! $templates ) return null;

        foreach ( $templates as $tid ) {
            $scope = array_map( 'absint', (array) get_post_meta( $tid, '_mad_dt_scope_products', true ) );
            if ( in_array( $product_id, $scope, true ) ) return (int) $tid;
        }

        $product_tags = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $product_tags ) && $product_tags ) {
            foreach ( $templates as $tid ) {
                $scope = array_map( 'absint', (array) get_post_meta( $tid, '_mad_dt_scope_tags', true ) );
                if ( $scope && array_intersect( $scope, $product_tags ) ) return (int) $tid;
            }
        }

        $product_cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $product_cats ) && $product_cats ) {
            foreach ( $templates as $tid ) {
                $scope = array_map( 'absint', (array) get_post_meta( $tid, '_mad_dt_scope_cats', true ) );
                if ( $scope && array_intersect( $scope, $product_cats ) ) return (int) $tid;
            }
        }

        return null;
    }

    /* ================================================================ */
    /*  Frontend: render del configurador                                */
    /* ================================================================ */

    public function render_configurator(): void {
        global $product;
        if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) ) return;

        $template_id = $this->find_template_for_product( $product );
        if ( ! $template_id ) return;

        $steps = get_post_meta( $template_id, '_mad_dt_steps', true );
        if ( empty( $steps ) || ! is_array( $steps ) ) return;

        $show_price = function_exists( 'mad_quotes_current_user_is_quote_role' )
            ? ! mad_quotes_current_user_is_quote_role()
            : true;

        $base_price = (float) $product->get_price();
        $currency   = function_exists( 'get_woocommerce_currency_symbol' )
            ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES )
            : '€';

        echo '<div class="mad-dt-configurator"'
            . ' data-template-id="' . esc_attr( $template_id ) . '"'
            . ' data-base-price="' . esc_attr( $show_price ? $base_price : 0 ) . '"'
            . ' data-show-price="' . ( $show_price ? '1' : '0' ) . '"'
            . ' data-currency-symbol="' . esc_attr( $currency ) . '">';

        foreach ( $steps as $step ) {
            $tax = $step['attribute'] ?? '';
            if ( ! $tax || ! taxonomy_exists( $tax ) ) continue;

            $label = ! empty( $step['label'] ) ? $step['label'] : wc_attribute_label( $tax );

            $reveal_attrs = '';
            if ( ! empty( $step['reveal_when_step'] ) ) {
                $reveal_attrs = ' data-reveal-step="' . esc_attr( $step['reveal_when_step'] ) . '"'
                    . ' data-reveal-values="' . esc_attr( wp_json_encode( array_values( (array) ( $step['reveal_when_slugs'] ?? [] ) ) ) ) . '"'
                    . ' style="display:none;"';
            }

            echo '<div class="mad-dt-cfg-step" data-step-id="' . esc_attr( $step['id'] ) . '"' . $reveal_attrs . '>';
            echo '<h4 class="mad-dt-cfg-step-label">' . esc_html( $label ) . '</h4>';
            echo '<div class="mad-dt-cfg-options">';

            foreach ( (array) ( $step['options'] ?? [] ) as $opt ) {
                $term = get_term( (int) ( $opt['term_id'] ?? 0 ), $tax );
                if ( ! $term || is_wp_error( $term ) ) continue;

                $image_id  = get_term_meta( $term->term_id, '_mad_dt_attr_image_id', true );
                $image_url = $image_id ? wp_get_attachment_image_url( $image_id, [ 70, 70 ] ) : '';

                $delta_type  = $opt['delta_type'] ?? 'none';
                $delta_value = (float) ( $opt['delta_value'] ?? 0 );
                $delta_label = '';
                $delta_attrs = '';

                // Importante: si el usuario es de rol presupuesto, NO se emite ningún
                // dato de precio en el HTML (ni siquiera en atributos data-*) — el
                // objetivo es que no haya nada que inspeccionar, a diferencia de los
                // plugins de opciones de terceros que solo ocultan el precio por CSS/JS.
                if ( $show_price ) {
                    $delta_attrs = ' data-delta-type="' . esc_attr( $delta_type ) . '"'
                        . ' data-delta-value="' . esc_attr( $delta_value ) . '"';
                    if ( 'none' !== $delta_type && $delta_value ) {
                        $delta_label = 'percent' === $delta_type
                            ? '+' . rtrim( rtrim( number_format( $delta_value, 2, ',', '.' ), '0' ), ',' ) . '%'
                            : '+' . number_format( $delta_value, 2, ',', '.' ) . ' ' . $currency;
                    }
                }

                echo '<button type="button" class="mad-dt-cfg-swatch ' . ( $image_url ? 'mad-dt-cfg-swatch--image' : 'mad-dt-cfg-swatch--text' ) . '"'
                    . ' data-value="' . esc_attr( $term->slug ) . '"'
                    . $delta_attrs
                    . ' title="' . esc_attr( $term->name ) . '">';

                if ( $image_url ) {
                    echo '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $term->name ) . '">';
                    echo '<span class="mad-dt-cfg-swatch-label">' . esc_html( $term->name ) . '</span>';
                } else {
                    echo esc_html( $term->name );
                }

                if ( $delta_label ) {
                    echo '<span class="mad-dt-cfg-swatch-delta">' . esc_html( $delta_label ) . '</span>';
                }

                echo '</button>';
            }

            echo '</div>';
            echo '<input type="hidden" class="mad-dt-cfg-input" name="mad_dt_selection[' . esc_attr( $tax ) . ']" value="">';
            echo '</div>';
        }

        if ( $show_price ) {
            echo '<div class="mad-dt-cfg-total">' . esc_html__( 'Precio con estas opciones:', 'mad-suite' )
                . ' <span class="mad-dt-cfg-total-amount">' . wp_kses_post( wc_price( $base_price ) ) . '</span></div>';
        }

        echo '<input type="hidden" name="mad_dt_template_id" value="' . esc_attr( $template_id ) . '">';
        echo '</div>';
    }

    /* ================================================================ */
    /*  Carrito: captura de la selección (sin precio para presupuesto)   */
    /* ================================================================ */

    public function add_selection_to_cart_item( $cart_item_data, $product_id, $variation_id ) {
        if ( empty( $_POST['mad_dt_selection'] ) || ! is_array( $_POST['mad_dt_selection'] ) ) return $cart_item_data;

        $template_id = absint( $_POST['mad_dt_template_id'] ?? 0 );
        $steps       = $template_id ? get_post_meta( $template_id, '_mad_dt_steps', true ) : [];
        if ( empty( $steps ) || ! is_array( $steps ) ) return $cart_item_data;

        $posted    = wp_unslash( $_POST['mad_dt_selection'] );
        $selection = [];

        foreach ( $steps as $step ) {
            $tax = $step['attribute'] ?? '';
            if ( ! $tax || empty( $posted[ $tax ] ) ) continue;

            $slug = sanitize_title( $posted[ $tax ] );
            foreach ( (array) ( $step['options'] ?? [] ) as $opt ) {
                $term = get_term( (int) ( $opt['term_id'] ?? 0 ), $tax );
                if ( $term && ! is_wp_error( $term ) && $term->slug === $slug ) {
                    $selection[ $tax ] = [
                        'label'       => wc_attribute_label( $tax ),
                        'value'       => $term->name,
                        'slug'        => $slug,
                        'delta_type'  => $opt['delta_type'],
                        'delta_value' => $opt['delta_value'],
                    ];
                    break;
                }
            }
        }

        if ( $selection ) {
            $product = wc_get_product( $variation_id ?: $product_id );
            $cart_item_data['mad_dt_selection']   = $selection;
            $cart_item_data['mad_dt_template_id'] = $template_id;
            $cart_item_data['mad_dt_base_price']  = $product ? (float) $product->get_price() : 0.0;
            $cart_item_data['unique_key']         = md5( wp_json_encode( $selection ) . microtime() );
        }

        return $cart_item_data;
    }

    public function display_selection_in_cart( $item_data, $cart_item ) {
        if ( empty( $cart_item['mad_dt_selection'] ) ) return $item_data;
        foreach ( $cart_item['mad_dt_selection'] as $sel ) {
            $item_data[] = [ 'key' => $sel['label'], 'value' => $sel['value'] ];
        }
        return $item_data;
    }

    /**
     * Ajusta el precio real del ítem de carrito según los recargos elegidos.
     * Siempre recalcula desde el precio base guardado al añadir al carrito,
     * así que es seguro que este hook se dispare varias veces por request.
     */
    public function apply_price_deltas_to_cart( $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['mad_dt_selection'] ) || ! isset( $cart_item['mad_dt_base_price'] ) ) continue;

            [ $fixed, $percent ] = $this->sum_deltas( $cart_item['mad_dt_selection'] );
            $base  = (float) $cart_item['mad_dt_base_price'];
            $price = round( $base + $fixed + ( $base * $percent / 100 ), 2 );
            $cart_item['data']->set_price( $price );
        }
    }

    /* ================================================================ */
    /*  Enganche con el módulo Quotes: precio sugerido en la línea       */
    /* ================================================================ */

    public function attach_selection_to_quote_order_item( $order_item, $cart_item ): void {
        if ( ! ( $order_item instanceof WC_Order_Item ) || empty( $cart_item['mad_dt_selection'] ) ) return;

        foreach ( $cart_item['mad_dt_selection'] as $sel ) {
            $order_item->add_meta_data( $sel['label'], $sel['value'] );
        }

        [ $fixed, $percent ] = $this->sum_deltas( $cart_item['mad_dt_selection'] );
        $base      = isset( $cart_item['mad_dt_base_price'] ) ? (float) $cart_item['mad_dt_base_price'] : 0.0;
        $suggested = round( $base + $fixed + ( $base * $percent / 100 ), 2 );

        $order_item->update_meta_data( '_mad_dt_suggested_price', $suggested );
        $order_item->save();
    }

    /** @return array{0:float,1:float} [suma de recargos fijos, suma de recargos %] */
    private function sum_deltas( array $selection ): array {
        $fixed = 0.0; $percent = 0.0;
        foreach ( $selection as $sel ) {
            if ( 'fixed' === ( $sel['delta_type'] ?? '' ) )   $fixed   += (float) $sel['delta_value'];
            if ( 'percent' === ( $sel['delta_type'] ?? '' ) ) $percent += (float) $sel['delta_value'];
        }
        return [ $fixed, $percent ];
    }

    /* ================================================================ */
    /*  Frontend: CSS / JS                                                */
    /* ================================================================ */

    public function frontend_css(): void {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
        ?>
        <style id="mad-dt-configurator-css">
        .mad-dt-configurator { margin: 16px 0; }
        .mad-dt-cfg-step { margin-bottom: 18px; }
        .mad-dt-cfg-step-label { margin: 0 0 8px; font-size: 14px; font-weight: 600; }
        .mad-dt-cfg-options { display: flex; flex-wrap: wrap; gap: 8px; }
        .mad-dt-cfg-swatch {
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            background: #fff;
            transition: border-color .15s, box-shadow .15s;
            text-align: center;
            padding: 6px 10px;
            line-height: 1.2;
        }
        .mad-dt-cfg-swatch:hover { border-color: #555; }
        .mad-dt-cfg-swatch.selected { border-color: #222; box-shadow: 0 0 0 1px #222; }
        .mad-dt-cfg-swatch--image { padding: 4px; }
        .mad-dt-cfg-swatch--image img { display: block; width: 60px; height: 60px; object-fit: cover; border-radius: 3px; margin: 0 auto; }
        .mad-dt-cfg-swatch-label { display: block; font-size: 11px; margin-top: 4px; color: #555; max-width: 70px; }
        .mad-dt-cfg-swatch-delta { display: block; font-size: 11px; color: #2980b9; font-weight: 600; margin-top: 3px; }
        .mad-dt-cfg-total { margin-top: 14px; padding: 10px 14px; background: #f7f7f7; border-radius: 4px; font-size: 15px; }
        .mad-dt-cfg-total-amount { font-weight: 700; }
        </style>
        <?php
    }

    public function frontend_js(): void {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
        ?>
        <script id="mad-dt-configurator-js">
        (function($) {
            'use strict';

            function fmtMoney($config, amount) {
                var symbol = $config.data('currency-symbol') || '€';
                return amount.toFixed(2).replace('.', ',') + ' ' + symbol;
            }

            function recalcTotal($config) {
                if (String($config.data('show-price')) !== '1') return;
                var base = parseFloat($config.data('base-price')) || 0;
                var fixed = 0, percent = 0;
                $config.find('.mad-dt-cfg-step:visible .mad-dt-cfg-swatch.selected').each(function() {
                    var type = $(this).data('delta-type');
                    var val  = parseFloat($(this).data('delta-value')) || 0;
                    if (type === 'fixed') fixed += val;
                    if (type === 'percent') percent += val;
                });
                var total = base + fixed + (base * percent / 100);
                $config.find('.mad-dt-cfg-total-amount').text(fmtMoney($config, total));
            }

            function evaluateVisibility($config) {
                $config.find('.mad-dt-cfg-step[data-reveal-step]').each(function() {
                    var $step   = $(this);
                    var sourceId = $step.data('reveal-step');
                    var values  = $step.data('reveal-values') || [];
                    var $sourceInput = $config.find('.mad-dt-cfg-step[data-step-id="' + sourceId + '"] .mad-dt-cfg-input');
                    var visible = $.inArray($sourceInput.val(), values) !== -1;
                    $step.toggle(visible);
                    if (!visible) {
                        $step.find('.mad-dt-cfg-input').val('');
                        $step.find('.mad-dt-cfg-swatch').removeClass('selected');
                    }
                });
            }

            function updateAddToCartState($config) {
                var ready = true;
                $config.find('.mad-dt-cfg-step:visible .mad-dt-cfg-input').each(function() {
                    if (!$(this).val()) ready = false;
                });
                $config.closest('form.cart').find('button.single_add_to_cart_button').prop('disabled', !ready);
            }

            function refresh($config) {
                evaluateVisibility($config);
                recalcTotal($config);
                updateAddToCartState($config);
            }

            $(document).on('click', '.mad-dt-configurator .mad-dt-cfg-swatch', function() {
                var $swatch = $(this);
                var $step   = $swatch.closest('.mad-dt-cfg-step');
                var $config = $swatch.closest('.mad-dt-configurator');
                var already = $swatch.hasClass('selected');

                $step.find('.mad-dt-cfg-swatch').removeClass('selected');
                $step.find('.mad-dt-cfg-input').val(already ? '' : $swatch.data('value'));
                if (!already) $swatch.addClass('selected');

                refresh($config);
            });

            $(function() {
                $('.mad-dt-configurator').each(function() {
                    refresh($(this));
                });
            });

        })(jQuery);
        </script>
        <?php
    }

    /* ================================================================ */
    /*  Admin: assets (builder de pasos + buscador de productos)         */
    /* ================================================================ */

    public function enqueue_admin_assets( string $hook ): void {
        global $post_type;
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || self::CPT !== $post_type ) return;

        wp_enqueue_script( 'jquery-ui-sortable' );

        wp_register_script( 'mad-dt-template-builder', false, [ 'jquery', 'jquery-ui-sortable' ], '1.0', true );
        wp_localize_script( 'mad-dt-template-builder', 'mad_dt_builder_data', [
            'attributes' => $this->get_attributes_data(),
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'mad_dt_search_products' ),
        ] );
        wp_add_inline_script( 'mad-dt-template-builder', $this->steps_builder_js() );
        wp_add_inline_script( 'mad-dt-template-builder', $this->scope_picker_js() );
        wp_enqueue_script( 'mad-dt-template-builder' );
    }

    private function get_attributes_data(): array {
        $out = [];
        if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) return $out;

        foreach ( wc_get_attribute_taxonomies() as $attr ) {
            $tax = wc_attribute_taxonomy_name( $attr->attribute_name );
            if ( ! taxonomy_exists( $tax ) ) continue;

            $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
            if ( is_wp_error( $terms ) ) continue;

            $term_list = [];
            foreach ( $terms as $term ) {
                $image_id    = get_term_meta( $term->term_id, '_mad_dt_attr_image_id', true );
                $term_list[] = [
                    'id'    => $term->term_id,
                    'name'  => $term->name,
                    'slug'  => $term->slug,
                    'image' => $image_id ? wp_get_attachment_image_url( $image_id, [ 40, 40 ] ) : '',
                ];
            }

            $out[ $tax ] = [
                'label' => $attr->attribute_label ?: $attr->attribute_name,
                'terms' => $term_list,
            ];
        }

        return $out;
    }

    public function ajax_search_products(): void {
        check_ajax_referer( 'mad_dt_search_products', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $query   = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        $results = [];

        if ( strlen( $query ) >= 2 ) {
            $products = get_posts( [
                'post_type'      => 'product',
                'posts_per_page' => 20,
                's'              => $query,
                'post_status'    => 'publish',
            ] );
            foreach ( $products as $p ) {
                $results[] = [ 'id' => $p->ID, 'text' => '#' . $p->ID . ' - ' . $p->post_title ];
            }
        }

        wp_send_json( [ 'results' => $results ] );
    }

    /**
     * JS del constructor de pasos: repeater cliente-servidor, sin AJAX
     * (los atributos/términos ya se localizan enteros en mad_dt_builder_data).
     */
    private function steps_builder_js(): string {
        return <<<'JS'
jQuery(function($) {
    'use strict';

    var data  = window.mad_dt_builder_data || { attributes: {} };
    var $app  = $('#mad-dt-steps-app');
    if (!$app.length) return;

    var steps = [];
    try { steps = JSON.parse($app.attr('data-steps') || '[]'); } catch (e) { steps = []; }

    function uid(prefix) { return prefix + '_' + Math.random().toString(36).slice(2, 9); }

    function attrOptionsHtml(selected) {
        var html = '<option value="">— elegir atributo —</option>';
        $.each(data.attributes, function(tax, info) {
            html += '<option value="' + tax + '"' + (tax === selected ? ' selected' : '') + '>' + info.label + '</option>';
        });
        return html;
    }

    function stepLabel(step, idx) {
        var info = data.attributes[step.attribute];
        return (idx + 1) + '. ' + (step.label || (info ? info.label : step.attribute) || ('Paso ' + (idx + 1)));
    }

    function revealSourceOptionsHtml(currentIdx, selectedStepId) {
        var html = '<option value="">(siempre visible)</option>';
        steps.forEach(function(s, i) {
            if (i >= currentIdx) return;
            html += '<option value="' + s.id + '"' + (s.id === selectedStepId ? ' selected' : '') + '>' + stepLabel(s, i) + '</option>';
        });
        return html;
    }

    function revealValuesHtml(sourceStepId, selectedSlugs) {
        var source = null;
        steps.forEach(function(s) { if (s.id === sourceStepId) source = s; });
        if (!source) return '<em>Elige primero el paso disparador.</em>';

        var info = data.attributes[source.attribute];
        if (!info) return '';

        var html = '';
        (source.options || []).forEach(function(opt) {
            var term = null;
            info.terms.forEach(function(t) { if (t.id == opt.term_id) term = t; });
            if (!term) return;
            var checked = selectedSlugs.indexOf(term.slug) !== -1 ? ' checked' : '';
            html += '<label style="margin-right:10px;"><input type="checkbox" class="mad-dt-reveal-value" value="' + term.slug + '"' + checked + '> ' + term.name + '</label>';
        });
        return html || '<em>Este paso todavía no tiene opciones marcadas.</em>';
    }

    function optionsRowsHtml(step) {
        var info = data.attributes[step.attribute];
        if (!info) return '';

        var rows = '';
        info.terms.forEach(function(term) {
            var existing = null;
            (step.options || []).forEach(function(o) { if (o.term_id == term.id) existing = o; });
            var checked    = existing ? ' checked' : '';
            var deltaType  = existing ? existing.delta_type : 'none';
            var deltaValue = existing ? existing.delta_value : 0;

            rows += '<tr data-term-id="' + term.id + '">' +
                '<td><label>' +
                    (term.image ? '<img src="' + term.image + '" style="width:28px;height:28px;object-fit:cover;border-radius:3px;vertical-align:middle;margin-right:6px;">' : '') +
                    '<input type="checkbox" class="mad-dt-opt-include"' + checked + '> ' + term.name +
                '</label></td>' +
                '<td>' +
                    '<select class="mad-dt-opt-delta-type">' +
                        '<option value="none"' + (deltaType === 'none' ? ' selected' : '') + '>Sin recargo</option>' +
                        '<option value="fixed"' + (deltaType === 'fixed' ? ' selected' : '') + '>Importe fijo</option>' +
                        '<option value="percent"' + (deltaType === 'percent' ? ' selected' : '') + '>Porcentaje</option>' +
                    '</select> ' +
                    '<input type="number" step="0.01" class="mad-dt-opt-delta-value small-text" value="' + deltaValue + '">' +
                '</td>' +
            '</tr>';
        });
        return rows;
    }

    function renderStep(step, idx) {
        var $row = $('<div class="mad-dt-step-row postbox" style="padding:12px;margin-bottom:10px;"></div>');
        $row.attr('data-step-id', step.id);
        $row.html(
            '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;cursor:move;" class="mad-dt-step-handle">' +
                '<strong>' + (idx + 1) + '.</strong>' +
                '<select class="mad-dt-step-attribute">' + attrOptionsHtml(step.attribute) + '</select>' +
                '<input type="text" class="mad-dt-step-label regular-text" placeholder="Etiqueta personalizada (opcional)" value="' + (step.label || '') + '" style="max-width:220px;">' +
                '<button type="button" class="button-link mad-dt-remove-step" style="color:#b32d2e;margin-left:auto;">Eliminar paso</button>' +
            '</div>' +
            (step.attribute ?
                '<div class="mad-dt-step-reveal" style="margin-bottom:8px;background:#f6f7f7;padding:8px;border-radius:3px;">' +
                    '<label>Mostrar este paso solo si: ' +
                        '<select class="mad-dt-reveal-source">' + revealSourceOptionsHtml(idx, step.reveal_when_step || '') + '</select>' +
                    '</label> ' +
                    '<span class="mad-dt-reveal-values">' + (step.reveal_when_step ? revealValuesHtml(step.reveal_when_step, step.reveal_when_slugs || []) : '') + '</span>' +
                '</div>' +
                '<table class="widefat striped mad-dt-step-options"><thead><tr><th style="width:60%">Opción</th><th>Recargo</th></tr></thead><tbody>' +
                    optionsRowsHtml(step) +
                '</tbody></table>'
            : '<p class="description">Elige un atributo para configurar sus opciones.</p>')
        );
        return $row;
    }

    function renderAll() {
        $app.empty();
        steps.forEach(function(step, idx) { $app.append(renderStep(step, idx)); });

        if ($.fn.sortable) {
            $app.sortable({
                items: '.mad-dt-step-row',
                handle: '.mad-dt-step-handle',
                update: function() { syncFromDom(); renderAll(); }
            });
        }
    }

    function syncFromDom() {
        var newSteps = [];
        $app.find('.mad-dt-step-row').each(function() {
            var $row = $(this);
            var revealSlugs = [];
            $row.find('.mad-dt-reveal-value:checked').each(function() { revealSlugs.push($(this).val()); });

            var options = [];
            $row.find('.mad-dt-step-options tbody tr').each(function() {
                var $tr = $(this);
                if (!$tr.find('.mad-dt-opt-include').is(':checked')) return;
                options.push({
                    term_id: parseInt($tr.data('term-id'), 10),
                    delta_type: $tr.find('.mad-dt-opt-delta-type').val(),
                    delta_value: parseFloat($tr.find('.mad-dt-opt-delta-value').val()) || 0
                });
            });

            newSteps.push({
                id: $row.data('step-id'),
                attribute: $row.find('.mad-dt-step-attribute').val(),
                label: $row.find('.mad-dt-step-label').val(),
                options: options,
                reveal_when_step: $row.find('.mad-dt-reveal-source').val() || '',
                reveal_when_slugs: revealSlugs
            });
        });
        steps = newSteps;
    }

    $app.on('change', '.mad-dt-step-attribute', function() {
        syncFromDom();
        var idx = $(this).closest('.mad-dt-step-row').index();
        steps[idx].attribute = $(this).val();
        steps[idx].options = [];
        renderAll();
    });

    $app.on('change', '.mad-dt-reveal-source', function() {
        syncFromDom();
        var idx = $(this).closest('.mad-dt-step-row').index();
        steps[idx].reveal_when_step = $(this).val();
        steps[idx].reveal_when_slugs = [];
        renderAll();
    });

    $app.on('click', '.mad-dt-remove-step', function() {
        syncFromDom();
        var idx = $(this).closest('.mad-dt-step-row').index();
        steps.splice(idx, 1);
        renderAll();
    });

    $('#mad-dt-add-step').on('click', function(e) {
        e.preventDefault();
        syncFromDom();
        steps.push({ id: uid('step'), attribute: '', label: '', options: [], reveal_when_step: '', reveal_when_slugs: [] });
        renderAll();
    });

    $('#post').on('submit', function() {
        syncFromDom();
        $('#mad-dt-steps-json').val(JSON.stringify(steps));
    });

    renderAll();
});
JS;
    }

    /** JS del buscador AJAX de productos específicos (meta box de asignación). */
    private function scope_picker_js(): string {
        return <<<'JS'
jQuery(function($) {
    'use strict';

    var $search = $('#mad_dt_scope_product_search');
    var $list   = $('#mad_dt_scope_product_list');
    if (!$search.length) return;

    var timer = null;
    $search.on('input', function() {
        clearTimeout(timer);
        var q = $(this).val().trim();
        if (q.length < 2) return;
        timer = setTimeout(function() { doSearch(q); }, 400);
    });

    function doSearch(q) {
        $.get(mad_dt_builder_data.ajax_url, {
            action: 'mad_dt_search_products',
            nonce: mad_dt_builder_data.nonce,
            q: q
        }, function(res) {
            var results = res && res.results ? res.results : [];
            if (!results.length) return;
            var first = results[0];
            if ($list.find('[data-id="' + first.id + '"]').length) return;

            var html = '<span class="mad-dt-scope-pill" data-id="' + first.id + '" style="display:inline-block;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:3px 6px;margin:2px 2px 2px 0;font-size:12px;">' +
                first.text +
                ' <a href="#" class="mad-dt-scope-del" style="color:#b32d2e;text-decoration:none;font-weight:bold;margin-left:4px;">&times;</a>' +
                '<input type="hidden" name="mad_dt_scope_products[]" value="' + first.id + '">' +
                '</span>';
            $list.append(html);
            $search.val('');
        });
    }

    $list.on('click', '.mad-dt-scope-del', function(e) {
        e.preventDefault();
        $(this).closest('.mad-dt-scope-pill').remove();
    });
});
JS;
    }
}
