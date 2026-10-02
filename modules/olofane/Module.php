<?php
/**
 * Olofane — Configuración específica para el cliente Olofane.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/AI_Description.php';
require_once __DIR__ . '/includes/WPML_Quotes.php';
require_once __DIR__ . '/includes/Menu_Duplicator.php';
require_once __DIR__ . '/includes/CSV_User_Import.php';
require_once __DIR__ . '/includes/FK_Destacados.php';

return new class ( $core ?? null ) implements MAD_Suite_Module {

    private const OPTION_KEY = 'madsuite_olofane_settings';
    private const NONCE_KEY  = 'mads_olofane_save';

    public function __construct( $core ) {}

    public function slug()        { return 'olofane'; }
    public function title()       { return __( 'Olofane — Configuración de cliente', 'mad-suite' ); }
    public function menu_label()  { return __( 'Olofane', 'mad-suite' ); }
    public function menu_slug()   { return 'mad-suite-olofane'; }
    public function description() { return __( 'Ajustes exclusivos para el cliente Olofane.', 'mad-suite' ); }
    public function required_plugins() { return [ 'WooCommerce' => 'woocommerce/woocommerce.php' ]; }

    // ── Settings ─────────────────────────────────────────────────────────────

    private function get_settings(): array {
        $defaults = [
            'outofstock_label'         => __( 'Agotado', 'mad-suite' ),
            'outofstock_label_enabled' => true,
            'dual_price_enabled'       => true,
            'vat_field_roles'          => [],
            'vat_field_label'          => __( 'NIF/CIF/VAT Number', 'mad-suite' ),
            'ai_provider'              => 'claude',
            'ai_api_key_claude'        => '',
            'ai_api_key_openai'        => '',
            'ai_model_claude'          => 'claude-sonnet-4-6',
            'ai_model_openai'          => 'gpt-4o',
            'ai_prompt_description'    => 'Escribe una descripción de producto atractiva, profesional y en español para: {product_name}. Incluye características principales y beneficios. Devuelve solo el texto, sin etiquetas HTML.',
            'ai_prompt_translate_en'   => 'Translate the following Spanish product description to English. Return only the translated text:\n\n{text}',
            'ai_prompt_translate_fr'   => "Traduis la description de produit suivante de l'espagnol vers le français. Retourne uniquement le texte traduit :\n\n{text}",
            'ai_wpml_enabled'          => true,
            'wpml_quotes_enabled'      => true,
            'product_columns_enabled'  => true,
            'product_stock_sort_enabled' => true,
            'product_grid_view_enabled'  => true,
            'fk_destacados_enabled'      => true,
        ];

        $opts = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( is_array( $opts ) ? $opts : [], $defaults );
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function init(): void {
        $s = $this->get_settings();

        // Feature 1 – out-of-stock overlay
        // CSS loads on wp_head so it works in standard WC loops AND manual/Elementor templates.
        // PHP filter wraps the image when WC renders it; manual templates use the ::after CSS
        // by adding class "mad-olofane-outofstock-wrap" to their image container widget.
        if ( ! empty( $s['outofstock_label_enabled'] ) ) {
            add_filter( 'woocommerce_product_get_image', [ $this, 'wrap_outofstock_image' ], 10, 6 );
            add_action( 'wp_head', [ $this, 'output_outofstock_css' ] );
        }

        // Feature 2 – expanded order notes
        // Classic checkout: filter fields
        add_filter( 'woocommerce_checkout_fields', [ $this, 'expand_order_notes_classic' ], 20 );
        // Blocks checkout: JS MutationObserver to auto-click the "Add note" checkbox
        add_action( 'wp_footer', [ $this, 'expand_order_notes_blocks_js' ] );

        // Feature 3 – dual price on product page
        // Priority 1000 so it runs AFTER Quotes module's maybe_restore_price (priority 999)
        if ( ! empty( $s['dual_price_enabled'] ) ) {
            add_filter( 'woocommerce_get_price_html',      [ $this, 'dual_price_display' ], 1000, 2 );
            add_filter( 'woocommerce_variable_price_html', [ $this, 'dual_price_display' ], 1000, 2 );
        }

        // Feature 4 – VAT/NIF field
        if ( ! empty( $s['vat_field_roles'] ) ) {
            // Classic checkout
            add_filter( 'woocommerce_checkout_fields',            [ $this, 'add_vat_checkout_field' ], 25 );
            add_action( 'woocommerce_checkout_process',           [ $this, 'validate_vat_field_classic' ] );
            add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_vat_to_order_classic' ] );
            add_action( 'woocommerce_checkout_update_customer',   [ $this, 'save_vat_to_user_classic' ], 10, 2 );

            // Blocks checkout (WooCommerce Blocks / Store API)
            add_action( 'init', [ $this, 'register_vat_blocks_field' ], 10 );
            add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'save_vat_from_blocks' ] );
            add_action( 'wp_footer', [ $this, 'prefill_vat_blocks_js' ] );
        }

        // Feature 5 – AI descriptions (admin only, handled in AI_Description class)
        if ( is_admin() ) {
            $ai = new MAD_Olofane_AI_Description( $s );
            $ai->init();

            $menu_dup = new MAD_Olofane_Menu_Duplicator();
            $menu_dup->init();

            $csv_import = new MAD_Olofane_CSV_User_Import();
            $csv_import->init();
        }

        // Feature 6 – NIF in WP admin user profiles
        if ( ! empty( $s['vat_field_roles'] ) ) {
            add_action( 'show_user_profile',       [ $this, 'render_vat_user_profile_field' ] );
            add_action( 'edit_user_profile',       [ $this, 'render_vat_user_profile_field' ] );
            add_action( 'personal_options_update', [ $this, 'save_vat_user_profile_field' ] );
            add_action( 'edit_user_profile_update',[ $this, 'save_vat_user_profile_field' ] );
        }

        // Feature 6b – WPML + Quotes translation tool
        if ( ! empty( $s['wpml_quotes_enabled'] ) ) {
            $wpml = new MAD_Olofane_WPML_Quotes( $s );
            $wpml->init();
        }

        // Feature 7 – Post author selector meta box
        if ( is_admin() ) {
            add_action( 'add_meta_boxes', [ $this, 'register_post_author_meta_box' ] );
            add_action( 'save_post',      [ $this, 'save_post_author_meta_box' ], 10, 2 );
        }

        // Feature 8 – Columnas extra en el listado de Productos del admin:
        // medidas (L×A×A nativas de WooCommerce), ubicación de stock (taxonomía
        // "Ubicaciones" de ATUM Inventory) y miniatura al doble de tamaño.
        if ( is_admin() && ! empty( $s['product_columns_enabled'] ) ) {
            add_filter( 'manage_edit-product_columns',        [ $this, 'add_product_list_columns' ] );
            add_action( 'manage_product_posts_custom_column',  [ $this, 'render_product_list_column' ], 10, 2 );
            add_action( 'admin_head',                          [ $this, 'output_product_list_thumb_css' ] );
        }

        // Feature 9 – Coste del proveedor: campo interno en la pestaña
        // General del producto (junto a Precio regular/Rebajado), usado
        // para calcular la columna "Margen" de la Feature 8.
        if ( is_admin() ) {
            add_action( 'woocommerce_product_options_pricing', [ $this, 'render_product_cost_field' ] );
            add_action( 'woocommerce_process_product_meta',    [ $this, 'save_product_cost_field' ] );
        }

        // Feature 10 – Productos sin stock al final del listado de admin,
        // por defecto (solo cuando no hay búsqueda ni orden explícito por
        // columna, para no interferir si el usuario pidió otro orden).
        if ( is_admin() && ! empty( $s['product_stock_sort_enabled'] ) ) {
            add_action( 'pre_get_posts', [ $this, 'sort_outofstock_last' ], 20 );
        }

        // Feature 11 – Vista de cuadrícula del listado de Productos, con
        // organización manual por arrastre (menu_order) y filtro "solo
        // destacados". La vista elegida se recuerda por usuario (igual que
        // la Biblioteca de medios de WordPress) vía get/set_user_setting().
        if ( is_admin() && ! empty( $s['product_grid_view_enabled'] ) ) {
            add_action( 'load-edit.php',            [ $this, 'persist_grid_view_choice' ] );
            add_filter( 'admin_body_class',         [ $this, 'add_grid_view_body_class' ] );
            add_action( 'restrict_manage_posts',    [ $this, 'render_grid_view_controls' ], 10, 2 );
            add_action( 'pre_get_posts',             [ $this, 'filter_featured_only' ], 20 );
            add_filter( 'post_class',                [ $this, 'tag_outofstock_row_class' ], 10, 3 );
            add_action( 'admin_footer-edit.php',    [ $this, 'output_grid_view_assets' ] );
            add_action( 'wp_ajax_mad_update_product_order', [ $this, 'ajax_update_product_order' ] );
            add_action( 'admin_post_mad_reorder_products_by_date', [ $this, 'handle_reorder_products_by_date' ] );
            add_action( 'admin_notices',            [ $this, 'render_reorder_success_notice' ] );
        }

        // Feature 12 – FunnelKit: en el bloque "Product" de un email, si el
        // admin configura Feed = "Specific Categories" + Sort by = "Random"
        // (combo sin uso real, reutilizado como marcador), se envían solo
        // los productos destacados en el mismo orden manual del backend
        // (el de la Feature 11). No se restringe a is_admin(): FunnelKit
        // renderiza y envía los emails fuera del admin (cron/REST).
        if ( ! empty( $s['fk_destacados_enabled'] ) ) {
            $fk_destacados = new MAD_Olofane_FK_Destacados();
            $fk_destacados->init();
        }
    }

    public function admin_init(): void {
        add_action( 'admin_post_mads_olofane_save', [ $this, 'handle_save_settings' ] );
    }

    // ── Feature 1: Out-of-stock overlay ──────────────────────────────────────
    // Wraps the product image HTML so the label sits inside the image container,
    // making CSS position:absolute reliable regardless of theme structure.

    public function wrap_outofstock_image( $image, $product, $size, $attr, $placeholder, $image_id ): string {
        if ( ! $product instanceof WC_Product ) return $image;
        if ( $product->is_in_stock() ) return $image;

        $s     = $this->get_settings();
        $label = esc_html( trim( $s['outofstock_label'] ) );
        if ( $label === '' ) return $image;

        return '<span class="mad-olofane-outofstock-wrap">'
            . $image
            . '<span class="mad-olofane-outofstock-label">' . $label . '</span>'
            . '</span>';
    }

    public function output_outofstock_css(): void {
        $s     = $this->get_settings();
        $label = esc_attr( trim( $s['outofstock_label'] ) );
        if ( $label === '' ) return;

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<style id="mad-olofane-outofstock">' . $this->outofstock_css( $label ) . '</style>';
    }

    private function outofstock_css( string $label = 'Agotado' ): string {
        $shared = '
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.65);
            color: #fff;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 7px 16px;
            border-radius: 3px;
            pointer-events: none;
            z-index: 10;
            white-space: nowrap;';

        // Escape for CSS content value (replace " with \")
        $label_css = str_replace( '"', '\\"', $label );

        return '
        /* Olofane: out-of-stock overlay — shared wrapper */
        .mad-olofane-outofstock-wrap {
            position: relative;
            display: block;
            overflow: hidden;
        }
        /* Case A: PHP filter injected a <span> inside the image wrapper */
        .mad-olofane-outofstock-label {' . $shared . '
        }
        /* Case B: manual / Elementor template — add class mad-olofane-outofstock-wrap
           to the image container widget. WooCommerce adds .outofstock to the product <li>.
           The label text is generated server-side from the settings value. */
        .outofstock .mad-olofane-outofstock-wrap::after {
            content: "' . $label_css . '";' . $shared . '
        }';
    }

    // ── Feature 2: Order notes expanded ──────────────────────────────────────

    public function expand_order_notes_classic( array $fields ): array {
        if ( isset( $fields['order']['order_comments'] ) ) {
            $fields['order']['order_comments']['rows'] = 5;
        }
        return $fields;
    }

    public function expand_order_notes_blocks_js(): void {
        if ( ! is_checkout() ) return;
        ?>
        <script>
        (function () {
            'use strict';
            // Auto-click the "Add order note" checkbox in the WooCommerce Blocks checkout
            // so the textarea is open by default.
            function tryExpandNotes() {
                var cb = document.querySelector(
                    '#order-notes .wc-block-components-checkbox__input, '
                    + '.wc-block-checkout__add-note .wc-block-components-checkbox__input'
                );
                if (cb && !cb.checked) {
                    cb.click();
                    return true;
                }
                return !!cb;
            }

            if (!tryExpandNotes()) {
                var obs = new MutationObserver(function (mutations, observer) {
                    if (tryExpandNotes()) observer.disconnect();
                });
                obs.observe(document.body, { childList: true, subtree: true });
                // Safety: disconnect after 15 s to avoid leaking observers
                setTimeout(function () { obs.disconnect(); }, 15000);
            }
        })();
        </script>
        <?php
    }

    // ── Feature 3: Dual price (excl. + incl. VAT) ────────────────────────────
    // Priority 1000 ensures this runs AFTER the Quotes module's maybe_restore_price
    // (priority 999), which otherwise overwrites our transformation.
    //
    // Price logic for this store:
    //   Regular price = PVP (público), siempre se muestra con su propio
    //                    desglose excl./incl. IVA.
    //   Sale price     = precio de Profesionales — si existe, se añade un
    //                    segundo bloque debajo (con su propio desglose),
    //                    separado por una línea y el encabezado "PROFESIONALES".
    // Si no hay precio de venta (sin rebaja), solo se muestra el bloque PVP.

    public function dual_price_display( string $price_html, WC_Product $product ): string {
        if ( ! is_product() ) return $price_html;
        if ( $price_html === '' ) return $price_html;

        static $css_printed = false;
        $css = '';
        if ( ! $css_printed ) {
            $css_printed = true;
            $css = '<style>
                .mad-olofane-price-excl { display:block; font-size:1.5em; font-weight:700; line-height:1.15; }
                .mad-olofane-price-excl small { font-size:0.5em; font-weight:400; opacity:.7; }
                .mad-olofane-price-incl { display:block; font-size:0.85em; color:#666; margin-top:3px; }
                .mad-olofane-price-incl small { font-size:0.9em; }
                .mad-olofane-price-divider { border:0; border-top:1px solid #ddd; margin:10px 0 6px; }
                /* text-decoration no se aplica en un solo punto: al heredarse
                   sobre texto de tamaños muy distintos (precio grande + label
                   pequeño), el navegador dibuja la línea a una altura fija que
                   queda mal centrada en el texto pequeño y parece un subrayado
                   en vez de un tachado. Se declara en cada tamaño por separado
                   para que cada uno dibuje su propia línea centrada. */
                .mad-olofane-price-pvp-strike { display:block; opacity:.6; }
                .mad-olofane-price-pvp-strike .mad-olofane-price-excl,
                .mad-olofane-price-pvp-strike .mad-olofane-price-excl small,
                .mad-olofane-price-pvp-strike .mad-olofane-price-incl,
                .mad-olofane-price-pvp-strike .mad-olofane-price-incl small { text-decoration: line-through; }
                .mad-olofane-price-pro-label { display:block; font-size:.75em; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#888; margin-bottom:2px; }
            </style>';
        }

        // Variable products: PVP = rango de precio regular de las variaciones,
        // PROFESIONALES = rango de precio activo (rebajado si lo hay).
        if ( $product->is_type( 'variable' ) ) {
            $reg_min = (float) $product->get_variation_regular_price( 'min' );
            if ( $reg_min <= 0 ) return $price_html;
            $reg_max = (float) $product->get_variation_regular_price( 'max' );

            $pvp_block = $this->price_pair_html(
                $this->price_range_html_excl_tax( $product, $reg_min, $reg_max ),
                wc_price( (float) wc_get_price_including_tax( $product, [ 'price' => $reg_min ] ) ),
                __( 'PVP excl. IVA', 'mad-suite' ),
                __( 'PVP incl. IVA', 'mad-suite' )
            );

            if ( ! $product->is_on_sale() ) {
                return $css . $pvp_block;
            }

            $pro_min = (float) $product->get_variation_price( 'min' );
            $pro_max = (float) $product->get_variation_price( 'max' );

            $pro_block = '<strong class="mad-olofane-price-pro-label">' . esc_html__( 'PROFESIONALES', 'mad-suite' ) . '</strong>'
                . $this->price_pair_html(
                    $this->price_range_html_excl_tax( $product, $pro_min, $pro_max ),
                    wc_price( (float) wc_get_price_including_tax( $product, [ 'price' => $pro_min ] ) ),
                    __( 'excl. IVA', 'mad-suite' ),
                    __( 'incl. IVA', 'mad-suite' )
                );

            return $css . '<span class="mad-olofane-price-pvp-strike">' . $pvp_block . '</span>'
                . '<hr class="mad-olofane-price-divider">' . $pro_block;
        }

        // Simple / external product
        // PVP = precio regular, PROFESIONALES = precio de venta (si existe una rebaja).
        $regular_raw = (float) $product->get_regular_price();
        if ( $regular_raw <= 0 ) return $price_html;

        $pvp_block = $this->price_pair_html(
            wc_price( (float) wc_get_price_excluding_tax( $product, [ 'price' => $regular_raw ] ) ),
            wc_price( (float) wc_get_price_including_tax( $product, [ 'price' => $regular_raw ] ) ),
            __( 'PVP excl. IVA', 'mad-suite' ),
            __( 'PVP incl. IVA', 'mad-suite' )
        );

        if ( ! $product->is_on_sale() ) {
            return $css . $pvp_block;
        }

        $pro_raw   = (float) $product->get_sale_price();
        $pro_block = '<strong class="mad-olofane-price-pro-label">' . esc_html__( 'PROFESIONALES', 'mad-suite' ) . '</strong>'
            . $this->price_pair_html(
                wc_price( (float) wc_get_price_excluding_tax( $product, [ 'price' => $pro_raw ] ) ),
                wc_price( (float) wc_get_price_including_tax( $product, [ 'price' => $pro_raw ] ) ),
                __( 'excl. IVA', 'mad-suite' ),
                __( 'incl. IVA', 'mad-suite' )
            );

        return $css . '<span class="mad-olofane-price-pvp-strike">' . $pvp_block . '</span>'
            . '<hr class="mad-olofane-price-divider">' . $pro_block;
    }

    /** Línea "precio excl. IVA (grande) + precio incl. IVA (pequeño)" reutilizada por PVP y Profesionales. */
    private function price_pair_html( string $excl_price_html, string $incl_price_html, string $excl_label, string $incl_label ): string {
        return sprintf(
            '<span class="mad-olofane-price-excl">%s <small>%s</small></span>'
            . '<span class="mad-olofane-price-incl">%s <small>%s</small></span>',
            $excl_price_html,
            esc_html( $excl_label ),
            $incl_price_html,
            esc_html( $incl_label )
        );
    }

    /** Precio (o rango min–max) sin IVA, formateado, para productos variables. */
    private function price_range_html_excl_tax( WC_Product $product, float $min, float $max ): string {
        $excl_min = (float) wc_get_price_excluding_tax( $product, [ 'price' => $min ] );
        if ( $min === $max ) {
            return wc_price( $excl_min );
        }
        $excl_max = (float) wc_get_price_excluding_tax( $product, [ 'price' => $max ] );
        return wc_price( $excl_min ) . ' – ' . wc_price( $excl_max );
    }

    // ── Feature 4: VAT / NIF — classic checkout ───────────────────────────────

    private function current_user_needs_vat(): bool {
        $s     = $this->get_settings();
        $roles = array_filter( (array) ( $s['vat_field_roles'] ?? [] ) );
        if ( empty( $roles ) ) return false;
        $user = wp_get_current_user();
        if ( ! $user->ID ) return false;
        return (bool) array_intersect( $roles, (array) $user->roles );
    }

    public function add_vat_checkout_field( array $fields ): array {
        if ( ! $this->current_user_needs_vat() ) return $fields;

        $s     = $this->get_settings();
        $label = ! empty( $s['vat_field_label'] ) ? $s['vat_field_label'] : __( 'NIF/CIF/VAT Number', 'mad-suite' );

        $fields['billing']['billing_vat'] = [
            'label'       => $label,
            'placeholder' => 'B12345678',
            'required'    => true,
            'class'       => [ 'form-row-wide' ],
            'priority'    => 25,
            'default'     => get_user_meta( get_current_user_id(), 'billing_vat', true ),
        ];

        return $fields;
    }

    public function validate_vat_field_classic(): void {
        if ( ! $this->current_user_needs_vat() ) return;
        $val = isset( $_POST['billing_vat'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_vat'] ) ) : '';
        if ( empty( $val ) ) {
            $s     = $this->get_settings();
            $label = $s['vat_field_label'] ?? __( 'NIF/CIF/VAT Number', 'mad-suite' );
            wc_add_notice(
                sprintf( __( 'El campo "%s" es obligatorio.', 'mad-suite' ), esc_html( $label ) ),
                'error'
            );
        }
    }

    public function save_vat_to_order_classic( int $order_id ): void {
        if ( ! isset( $_POST['billing_vat'] ) ) return;
        $val = sanitize_text_field( wp_unslash( $_POST['billing_vat'] ) );
        update_post_meta( $order_id, '_billing_vat', $val );
    }

    public function save_vat_to_user_classic( WC_Customer $customer, array $data ): void {
        $val = isset( $_POST['billing_vat'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_vat'] ) ) : '';
        if ( $val !== '' && $customer->get_id() ) {
            update_user_meta( $customer->get_id(), 'billing_vat', $val );
        }
    }

    // ── Feature 4: VAT / NIF — WooCommerce Blocks checkout ───────────────────

    public function register_vat_blocks_field(): void {
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) return;

        $s     = $this->get_settings();
        $label = ! empty( $s['vat_field_label'] ) ? $s['vat_field_label'] : __( 'NIF/CIF/VAT Number', 'mad-suite' );

        woocommerce_register_additional_checkout_field( [
            'id'                => 'mad-olofane/billing-vat',
            'label'             => $label,
            'location'          => 'address',
            'required'          => false,       // conditional: enforced via validate_callback
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => [ $this, 'validate_vat_blocks_field' ],
        ] );
    }

    public function validate_vat_blocks_field( $value ) {
        if ( ! $this->current_user_needs_vat() ) return true;
        if ( empty( trim( (string) $value ) ) ) {
            $s     = $this->get_settings();
            $label = $s['vat_field_label'] ?? __( 'NIF/CIF/VAT Number', 'mad-suite' );
            return new WP_Error(
                'required_vat',
                sprintf( __( 'El campo "%s" es obligatorio.', 'mad-suite' ), $label )
            );
        }
        return true;
    }

    public function save_vat_from_blocks( WC_Order $order ): void {
        // WC Blocks stores the additional field value in order meta with the field ID as key
        $val = $order->get_meta( 'mad-olofane/billing-vat' );
        if ( ! $val ) return;

        // Mirror to _billing_vat for consistency with classic checkout and admin display
        $order->update_meta_data( '_billing_vat', $val );
        $order->save();

        if ( $order->get_customer_id() ) {
            update_user_meta( $order->get_customer_id(), 'billing_vat', $val );
        }
    }

    public function prefill_vat_blocks_js(): void {
        if ( ! is_checkout() ) return;
        if ( ! is_user_logged_in() ) return;
        if ( ! $this->current_user_needs_vat() ) return;

        $saved = get_user_meta( get_current_user_id(), 'billing_vat', true );
        if ( empty( $saved ) ) return;
        ?>
        <script>
        (function () {
            'use strict';
            var savedVat = <?php echo wp_json_encode( $saved ); ?>;

            function tryFillVat() {
                // WC Blocks renders the additional field input with an ID derived from the field id
                var inp = document.querySelector(
                    'input[id="billing-vat"], '
                    + 'input[id*="billing-vat"], '
                    + 'input[name="billing-vat"]'
                );
                if (!inp) {
                    // Fallback: find by associated label text
                    var labels = document.querySelectorAll('label');
                    for (var i = 0; i < labels.length; i++) {
                        var text = labels[i].textContent || '';
                        if (text.indexOf('NIF') !== -1 || text.indexOf('VAT') !== -1) {
                            var forId = labels[i].getAttribute('for');
                            if (forId) { inp = document.getElementById(forId); }
                            break;
                        }
                    }
                }
                if (inp && inp.value === '') {
                    // React-controlled input requires native setter + synthetic event
                    var nativeSetter = Object.getOwnPropertyDescriptor(
                        window.HTMLInputElement.prototype, 'value'
                    );
                    nativeSetter.set.call(inp, savedVat);
                    inp.dispatchEvent(new Event('input', { bubbles: true }));
                    return true;
                }
                return !!inp;
            }

            if (!tryFillVat()) {
                var obs = new MutationObserver(function (mutations, observer) {
                    if (tryFillVat()) observer.disconnect();
                });
                obs.observe(document.body, { childList: true, subtree: true });
                setTimeout(function () { obs.disconnect(); }, 15000);
            }
        })();
        </script>
        <?php
    }

    // ── Feature 6: NIF in WP admin user profiles ─────────────────────────────

    public function render_vat_user_profile_field( WP_User $user ): void {
        $s     = $this->get_settings();
        $label = ! empty( $s['vat_field_label'] ) ? $s['vat_field_label'] : __( 'NIF/CIF/VAT Number', 'mad-suite' );
        $val   = get_user_meta( $user->ID, 'billing_vat', true );
        $roles = array_filter( (array) ( $s['vat_field_roles'] ?? [] ) );
        $note  = ! empty( array_intersect( (array) $user->roles, $roles ) )
            ? __( 'Requerido en el checkout para este usuario.', 'mad-suite' )
            : __( 'Este rol no requiere NIF en el checkout, pero puede guardarse igualmente.', 'mad-suite' );
        ?>
        <h2><?php esc_html_e( 'Datos de facturación (Olofane)', 'mad-suite' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="mad_billing_vat"><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <input type="text"
                           id="mad_billing_vat"
                           name="mad_billing_vat"
                           value="<?php echo esc_attr( $val ); ?>"
                           class="regular-text"
                           placeholder="B12345678">
                    <p class="description"><?php echo esc_html( $note ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_vat_user_profile_field( int $user_id ): void {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;
        if ( ! isset( $_POST['mad_billing_vat'] ) ) return;
        $val = sanitize_text_field( wp_unslash( $_POST['mad_billing_vat'] ) );
        update_user_meta( $user_id, 'billing_vat', $val );
    }

    // ── Feature 7: Post author selector ──────────────────────────────────────

    public function register_post_author_meta_box(): void {
        foreach ( [ 'post', 'page' ] as $post_type ) {
            add_meta_box(
                'mad-olofane-post-author',
                __( 'Autor de la entrada', 'mad-suite' ),
                [ $this, 'render_post_author_meta_box' ],
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function render_post_author_meta_box( WP_Post $post ): void {
        if ( ! current_user_can( 'edit_others_posts' ) ) {
            echo '<p>' . esc_html__( 'Sin permisos para cambiar el autor.', 'mad-suite' ) . '</p>';
            return;
        }

        wp_nonce_field( 'mad_olofane_post_author_' . $post->ID, 'mad_olofane_post_author_nonce' );

        echo '<label for="mad-olofane-post-author-select" style="display:block;margin-bottom:4px;">';
        echo esc_html__( 'Selecciona el autor:', 'mad-suite' );
        echo '</label>';

        wp_dropdown_users( [
            'name'             => 'mad_olofane_post_author_id',
            'id'               => 'mad-olofane-post-author-select',
            'selected'         => $post->post_author,
            'who'              => 'authors',
            'show'             => 'display_name_with_login',
            'style'            => 'width:100%;',
            'show_option_none' => false,
        ] );
    }

    public function save_post_author_meta_box( int $post_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['mad_olofane_post_author_nonce'] ) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mad_olofane_post_author_nonce'] ) ), 'mad_olofane_post_author_' . $post_id ) ) return;
        if ( ! current_user_can( 'edit_others_posts' ) ) return;
        if ( ! isset( $_POST['mad_olofane_post_author_id'] ) ) return;

        $new_author = absint( $_POST['mad_olofane_post_author_id'] );
        if ( ! $new_author ) return;

        // Prevent recursive save_post loop
        remove_action( 'save_post', [ $this, 'save_post_author_meta_box' ], 10 );
        wp_update_post( [
            'ID'          => $post_id,
            'post_author' => $new_author,
        ] );
        add_action( 'save_post', [ $this, 'save_post_author_meta_box' ], 10, 2 );
    }

    // ── Feature 8: Columnas extra en el listado de Productos ─────────────────

    /** Inserta "Medidas" y "Ubicación" justo después del nombre del producto, y "Margen" después del precio. */
    public function add_product_list_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'name' === $key ) {
                $new['mad_dimensions']    = __( 'Medidas', 'mad-suite' );
                $new['mad_atum_location'] = __( 'Ubicación', 'mad-suite' );
            }
            if ( 'price' === $key ) {
                $new['mad_margin'] = __( 'Margen', 'mad-suite' );
            }
        }
        return $new;
    }

    public function render_product_list_column( string $column, int $post_id ): void {
        if ( 'mad_dimensions' === $column ) {
            $this->render_dimensions_column( $post_id );
            return;
        }
        if ( 'mad_atum_location' === $column ) {
            $this->render_atum_location_column( $post_id );
            return;
        }
        if ( 'mad_margin' === $column ) {
            $this->render_margin_column( $post_id );
            return;
        }
    }

    /** Largo × Ancho × Alto nativos de WooCommerce (pestaña "Envío" del producto). */
    private function render_dimensions_column( int $post_id ): void {
        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            echo '—';
            return;
        }

        $dims = array_filter(
            [ $product->get_length(), $product->get_width(), $product->get_height() ],
            static function ( $v ) { return '' !== $v && null !== $v; }
        );

        if ( empty( $dims ) ) {
            echo '—';
            return;
        }

        $unit = get_option( 'woocommerce_dimension_unit', 'cm' );
        echo esc_html( implode( ' × ', $dims ) . ' ' . $unit );
    }

    /**
     * Ubicación de stock — taxonomía "Ubicaciones" del plugin ATUM Inventory
     * Manager (atum_location, función gratuita). Si ATUM no está activo, se
     * avisa en la columna en vez de fallar.
     */
    private function render_atum_location_column( int $post_id ): void {
        if ( ! taxonomy_exists( 'atum_location' ) ) {
            echo '<span style="color:#999;">' . esc_html__( 'ATUM no activo', 'mad-suite' ) . '</span>';
            return;
        }

        $terms = get_the_terms( $post_id, 'atum_location' );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            echo '—';
            return;
        }

        echo esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) );
    }

    /**
     * Margen sobre el precio regular (PVP) y sobre el precio rebajado (B2B),
     * calculado como (precio - coste) / precio × 100 — el "margen comercial"
     * estándar (qué porcentaje del precio de venta es ganancia), no el
     * markup sobre coste. El coste se carga en la pestaña "General" del
     * producto, campo "Coste (proveedor)".
     */
    private function render_margin_column( int $post_id ): void {
        $product = wc_get_product( $post_id );
        if ( ! $product || $product->is_type( 'variable' ) ) {
            echo '—';
            return;
        }

        $cost = $this->get_product_cost( $post_id );
        if ( null === $cost ) {
            echo '<span style="color:#999;">' . esc_html__( 'Sin coste', 'mad-suite' ) . '</span>';
            return;
        }

        $regular = $product->get_regular_price();
        $sale    = $product->get_sale_price();

        $lines = [];
        $lines[] = 'PVP: ' . $this->format_margin( $cost, $regular );
        $lines[] = 'B2B: ' . ( '' !== $sale ? $this->format_margin( $cost, $sale ) : '—' );

        echo wp_kses_post( implode( '<br>', $lines ) );
    }

    /** Devuelve el % de margen formateado (en rojo si es negativo: se vendería con pérdida). */
    private function format_margin( float $cost, $price ): string {
        if ( '' === $price || ! is_numeric( $price ) || (float) $price <= 0 ) {
            return '—';
        }

        $price  = (float) $price;
        $margin = ( $price - $cost ) / $price * 100;
        $color  = $margin < 0 ? '#c00' : ( $margin < 15 ? '#b26a00' : '#2e7d32' );

        return '<span style="color:' . $color . ';">' . esc_html( number_format_i18n( $margin, 1 ) ) . '%</span>';
    }

    /**
     * Coste del proveedor. Se importó vía el importador nativo de CSV de
     * WooCommerce bajo la meta key literal "cost value" (con espacio — así
     * la generó el importador, no es un campo ACF). Se comprueba también
     * "cost_value" (guion bajo) como respaldo, por si en algún producto se
     * hubiera guardado normalizada.
     *
     * @return float|null Null si no hay coste cargado.
     */
    private function get_product_cost( int $product_id ) {
        $cost = get_post_meta( $product_id, 'cost value', true );
        if ( '' === $cost ) {
            $cost = get_post_meta( $product_id, 'cost_value', true );
        }
        return ( '' !== $cost && is_numeric( $cost ) ) ? (float) $cost : null;
    }

    /**
     * Campo "Coste (proveedor)" — info interna, no se muestra nunca en la
     * tienda. Reutiliza la meta "cost value" ya cargada por el cliente vía
     * importador CSV. Incluye una calculadora de margen en tiempo real
     * (PVP y B2B) que se actualiza mientras se edita el coste o los precios,
     * sin recargar la página.
     *
     * Nota: el <input> no puede llamarse "cost value" (con espacio) porque
     * PHP convierte automáticamente los espacios de los nombres de campo en
     * guiones bajos al poblar $_POST. Por eso el campo usa el id/name
     * "mad_cost_value" y es save_product_cost_field() quien escribe
     * explícitamente en la meta key real "cost value".
     */
    public function render_product_cost_field(): void {
        global $post;
        $cost = $this->get_product_cost( $post->ID );
        ?>
        <div class="options_group">
            <?php woocommerce_wp_text_input( [
                'id'                => 'mad_cost_value',
                'value'             => null !== $cost ? $cost : '',
                'label'             => __( 'Coste (proveedor)', 'mad-suite' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                'description'       => __( 'Precio al que se adquirió este producto al proveedor. Uso interno — nunca se muestra en la tienda, solo en la columna "Margen" del listado de Productos y en la calculadora de abajo.', 'mad-suite' ),
                'desc_tip'          => true,
                'data_type'         => 'price',
                'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
            ] ); ?>
            <p class="form-field mad_margin_calc_field">
                <label><?php esc_html_e( 'Margen (calculadora en tiempo real)', 'mad-suite' ); ?></label>
                <span class="description">
                    PVP: <strong id="mad_margin_pvp">—</strong>
                    &nbsp;&nbsp;&nbsp;
                    B2B: <strong id="mad_margin_b2b">—</strong>
                </span>
            </p>
        </div>
        <script>
        jQuery( function( $ ) {
            function parseNum( val ) {
                if ( ! val ) return null;
                var n = parseFloat( String( val ).replace( ',', '.' ) );
                return isNaN( n ) ? null : n;
            }
            function colorFor( margin ) {
                if ( margin < 0 ) return '#c00';
                if ( margin < 15 ) return '#b26a00';
                return '#2e7d32';
            }
            function renderMargin( id, cost, price ) {
                var $el = $( '#' + id );
                if ( null === cost || null === price || price <= 0 ) {
                    $el.text( '—' ).css( 'color', '' );
                    return;
                }
                var margin = ( price - cost ) / price * 100;
                $el.text( margin.toFixed( 1 ) + '%' ).css( 'color', colorFor( margin ) );
            }
            function updateMargins() {
                var cost    = parseNum( $( '#mad_cost_value' ).val() );
                var regular = parseNum( $( '#_regular_price' ).val() );
                var sale    = parseNum( $( '#_sale_price' ).val() );
                renderMargin( 'mad_margin_pvp', cost, regular );
                renderMargin( 'mad_margin_b2b', cost, sale );
            }
            $( document ).on( 'input change', '#mad_cost_value, #_regular_price, #_sale_price', updateMargins );
            updateMargins();
        } );
        </script>
        <?php
    }

    public function save_product_cost_field( int $post_id ): void {
        if ( ! isset( $_POST['mad_cost_value'] ) ) return;

        $raw = wc_clean( wp_unslash( $_POST['mad_cost_value'] ) );
        if ( '' === $raw ) {
            delete_post_meta( $post_id, 'cost value' );
            return;
        }

        update_post_meta( $post_id, 'cost value', wc_format_decimal( $raw ) );
    }

    // ── Feature 10: Sin stock al final del listado ────────────────────────────

    /**
     * Ordena el listado de admin de Productos con "Agotado" siempre al
     * final. _stock_status vale 'instock' | 'onbackorder' | 'outofstock';
     * ordenando ASC alfabéticamente ya da el resultado deseado (outofstock
     * es el último alfabéticamente). Solo se aplica cuando no hay búsqueda
     * ni una columna de orden explícita, para no pisar lo que pida el
     * usuario.
     */
    public function sort_outofstock_last( $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) return;
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) return;
        if ( ! empty( $_GET['orderby'] ) || ! empty( $_GET['s'] ) ) return;

        $query->set( 'meta_key', '_stock_status' );
        $query->set( 'orderby', [ 'meta_value' => 'ASC', 'menu_order' => 'ASC', 'title' => 'ASC' ] );
    }

    // ── Feature 11: Vista de cuadrícula + orden por arrastre + destacados ────

    private const GRID_COLS_MIN = 2;
    private const GRID_COLS_MAX = 6;

    /** Recuerda la vista elegida (tabla/cuadrícula) y el número de columnas por usuario, igual que la Biblioteca de medios. */
    public function persist_grid_view_choice(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) return;
        if ( isset( $_GET['mad_view'] ) && in_array( $_GET['mad_view'], [ 'grid', 'list' ], true ) ) {
            set_user_setting( 'mad_product_view', $_GET['mad_view'] );
        }
        if ( isset( $_GET['mad_cols'] ) ) {
            $cols = absint( $_GET['mad_cols'] );
            if ( $cols >= self::GRID_COLS_MIN && $cols <= self::GRID_COLS_MAX ) {
                set_user_setting( 'mad_product_cols', (string) $cols );
            }
        }
    }

    private function get_current_grid_view(): string {
        if ( isset( $_GET['mad_view'] ) && in_array( $_GET['mad_view'], [ 'grid', 'list' ], true ) ) {
            return $_GET['mad_view'];
        }
        return (string) get_user_setting( 'mad_product_view', 'list' );
    }

    private function get_grid_columns(): int {
        if ( isset( $_GET['mad_cols'] ) ) {
            $cols = absint( $_GET['mad_cols'] );
            if ( $cols >= self::GRID_COLS_MIN && $cols <= self::GRID_COLS_MAX ) {
                return $cols;
            }
        }
        $cols = (int) get_user_setting( 'mad_product_cols', 4 );
        return ( $cols >= self::GRID_COLS_MIN && $cols <= self::GRID_COLS_MAX ) ? $cols : 4;
    }

    public function add_grid_view_body_class( string $classes ): string {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) return $classes;
        return $classes . ' mad-view-' . $this->get_current_grid_view() . ' ';
    }

    /** Botones de Tabla/Cuadrícula + filtro de Destacados, sobre el listado de Productos. */
    public function render_grid_view_controls( string $post_type, string $which ): void {
        if ( 'product' !== $post_type || 'top' !== $which ) return;

        $view     = $this->get_current_grid_view();
        $base_url = remove_query_arg( [ 'mad_view', 'paged' ] );
        $list_url = add_query_arg( 'mad_view', 'list', $base_url );
        $grid_url = add_query_arg( 'mad_view', 'grid', $base_url );

        $featured_on  = ! empty( $_GET['mad_featured'] );
        $featured_url = $featured_on
            ? remove_query_arg( [ 'mad_featured', 'paged' ] )
            : add_query_arg( 'mad_featured', '1', remove_query_arg( 'paged' ) );
        ?>
        <div style="display:inline-flex;align-items:center;gap:6px;margin:1px 8px 0 0;">
            <span style="display:inline-flex;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;">
                <a href="<?php echo esc_url( $list_url ); ?>"
                   class="button<?php echo 'grid' !== $view ? ' button-primary' : ''; ?>"
                   style="border:0;border-radius:0;box-shadow:none;"
                   title="<?php esc_attr_e( 'Vista de tabla', 'mad-suite' ); ?>">☰ <?php esc_html_e( 'Tabla', 'mad-suite' ); ?></a><a href="<?php echo esc_url( $grid_url ); ?>"
                   class="button<?php echo 'grid' === $view ? ' button-primary' : ''; ?>"
                   style="border:0;border-radius:0;box-shadow:none;"
                   title="<?php esc_attr_e( 'Vista de cuadrícula', 'mad-suite' ); ?>">▦ <?php esc_html_e( 'Cuadrícula', 'mad-suite' ); ?></a>
            </span>
            <a href="<?php echo esc_url( $featured_url ); ?>"
               class="button<?php echo $featured_on ? ' button-primary' : ''; ?>"
               title="<?php esc_attr_e( 'Mostrar solo productos destacados', 'mad-suite' ); ?>">★ <?php esc_html_e( 'Destacados', 'mad-suite' ); ?></a>

            <?php if ( 'grid' === $view ) : ?>
                <input type="hidden" name="mad_view" value="grid">
                <?php if ( $featured_on ) : ?>
                    <input type="hidden" name="mad_featured" value="1">
                <?php endif; ?>
                <label style="margin-left:2px;">
                    <?php esc_html_e( 'Columnas:', 'mad-suite' ); ?>
                    <select name="mad_cols" onchange="this.form.submit()">
                        <?php for ( $n = self::GRID_COLS_MIN; $n <= self::GRID_COLS_MAX; $n++ ) : ?>
                            <option value="<?php echo esc_attr( $n ); ?>" <?php selected( $this->get_grid_columns(), $n ); ?>><?php echo esc_html( $n ); ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
            <?php endif; ?>

            <a href="<?php echo esc_url( $this->get_reorder_by_date_url() ); ?>"
               class="button"
               onclick="return confirm('<?php echo esc_js( __( 'Esto reorganiza todos los productos por fecha de publicación (más recientes primero). El orden manual actual se perderá. ¿Continuar?', 'mad-suite' ) ); ?>');"
               title="<?php esc_attr_e( 'Reorganizar todos los productos por fecha de publicación, con los más recientes primero', 'mad-suite' ); ?>">
               ↓ <?php esc_html_e( 'Más recientes primero', 'mad-suite' ); ?>
            </a>
        </div>
        <?php
    }

    private function get_reorder_by_date_url(): string {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=mad_reorder_products_by_date' ),
            'mad_reorder_products_by_date'
        );
    }

    /** Reordena admin-post.php?action=mad_reorder_products_by_date → reespacia menu_order por fecha de publicación descendente. */
    public function handle_reorder_products_by_date(): void {
        if ( ! current_user_can( 'edit_products' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'mad_reorder_products_by_date' );

        $this->reorder_products_by_date();

        $redirect = wp_get_referer() ?: admin_url( 'edit.php?post_type=product' );
        wp_safe_redirect( add_query_arg( 'mad_reordered', '1', remove_query_arg( 'mad_reordered', $redirect ) ) );
        exit;
    }

    /**
     * Reespacia el menu_order de todos los productos (de 10 en 10) según su
     * fecha de publicación, más recientes primero, respetando que los
     * agotados queden siempre al final (misma agrupación que usa la
     * Feature 10 en el orden por defecto).
     */
    private function reorder_products_by_date(): void {
        global $wpdb;
        $ids = $wpdb->get_col( "
            SELECT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_stock_status'
            WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private','future')
            ORDER BY pm.meta_value ASC, p.post_date DESC
        " );

        $order = 0;
        foreach ( $ids as $id ) {
            $wpdb->update( $wpdb->posts, [ 'menu_order' => $order ], [ 'ID' => $id ] );
            clean_post_cache( (int) $id );
            $order += 10;
        }
    }

    /** Aviso de "reordenado con éxito" tras usar el botón de arriba. */
    public function render_reorder_success_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) return;
        if ( empty( $_GET['mad_reordered'] ) ) return;
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Productos reorganizados por fecha de publicación (más recientes primero).', 'mad-suite' ); ?></p>
        </div>
        <?php
    }

    /** Filtra el listado a solo productos marcados como "destacado" cuando el botón de arriba está activo. */
    public function filter_featured_only( $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) return;
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) return;
        if ( empty( $_GET['mad_featured'] ) ) return;

        $tax_query   = (array) $query->get( 'tax_query' );
        $tax_query[] = [
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => 'featured',
        ];
        $query->set( 'tax_query', $tax_query );
    }

    /**
     * Marca las filas de productos agotados con la clase "mad-outofstock"
     * en el listado de admin, para que la vista de cuadrícula pueda
     * bloquear su arrastre y ponerles la etiqueta "SOLD" en rojo. La
     * Feature 10 (sort_outofstock_last) ya se encarga de que queden al
     * final del orden por defecto; esto solo los distingue visualmente.
     */
    public function tag_outofstock_row_class( array $classes, $class, int $post_id ): array {
        if ( ! is_admin() ) return $classes;
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) return $classes;

        $product = wc_get_product( $post_id );
        if ( $product && 'outofstock' === $product->get_stock_status() ) {
            $classes[] = 'mad-outofstock';
        }
        return $classes;
    }

    /**
     * CSS que convierte la tabla nativa del listado en tarjetas de
     * cuadrícula (reutiliza las mismas celdas/columnas ya registradas, sin
     * duplicar la consulta ni el render), y JS de arrastre (jQuery UI
     * Sortable) que persiste el nuevo orden en menu_order vía AJAX.
     *
     * El arrastre solo se activa si no hay una columna de orden explícita
     * activa (orderby en la URL): si el listado no está en su orden por
     * defecto, la posición visual no refleja menu_order y arrastrar
     * confundiría más de lo que ayuda.
     */
    public function output_grid_view_assets(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) return;
        if ( 'grid' !== $this->get_current_grid_view() ) return;

        $can_sort = empty( $_GET['orderby'] );
        if ( $can_sort ) {
            wp_enqueue_script( 'jquery-ui-sortable' );
        }
        $cols = $this->get_grid_columns();
        ?>
        <style>
            body.mad-view-grid .wp-list-table.posts { border: 0; box-shadow: none; background: transparent; }
            body.mad-view-grid .wp-list-table thead, body.mad-view-grid .wp-list-table tfoot { display: none; }
            body.mad-view-grid #the-list { display: grid; grid-template-columns: repeat(<?php echo (int) $cols; ?>, 1fr); gap: 16px; }
            body.mad-view-grid #the-list tr.type-product { display: flex; flex-direction: column; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 10px 12px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
            body.mad-view-grid #the-list tr.type-product.ui-sortable-helper { box-shadow: 0 4px 14px rgba(0,0,0,.18); }
            body.mad-view-grid #the-list .mad-sortable-placeholder { border: 2px dashed #c3c4c7; border-radius: 6px; background: #f6f7f7; }
            body.mad-view-grid #the-list tr.type-product .check-column,
            body.mad-view-grid #the-list tr.type-product .toggle-row { display: none; }
            body.mad-view-grid #the-list tr.type-product td { display: block; padding: 3px 0; border: 0; white-space: normal; overflow-wrap: break-word; }
            /* En cuadrícula solo interesan la foto y el nombre — el resto de
               columnas (precio, margen, medidas, ubicación...) se ocultan. */
            body.mad-view-grid #the-list tr.type-product td:not(.column-thumb):not(.column-name) { display: none; }
            /* width:auto y las reglas de imagen con !important pisan tanto el
               ancho de columna fijo de tabla (heredado de WP core, en % del
               ancho de la tabla) como el tamaño 110×110 de la Feature 8
               (output_product_list_thumb_css), pensado para la vista de tabla. */
            body.mad-view-grid #the-list tr.type-product .column-thumb {
                width: auto !important;
                text-align: center;
            }
            body.mad-view-grid #the-list tr.type-product .column-thumb img {
                display: block !important;
                width: 100% !important;
                height: 180px !important;
                max-width: none !important;
                max-height: none !important;
                min-width: 0 !important;
                min-height: 0 !important;
                object-fit: cover;
                border-radius: 4px;
                margin: 0 auto;
            }
            body.mad-view-grid #the-list tr.type-product .column-name {
                width: auto !important;
                font-weight: 600;
                text-align: center;
                margin-top: 8px;
            }
            body.mad-view-grid #the-list tr.type-product .column-name .row-actions { display: none; }
            /* Agotados: bloqueados al final (Feature 10 ya los ordena ahí por
               defecto), sin arrastre y con etiqueta "SOLD" en rojo. */
            body.mad-view-grid #the-list tr.type-product.mad-outofstock { opacity: .55; cursor: default; }
            body.mad-view-grid #the-list tr.type-product.mad-outofstock .column-thumb { position: relative; }
            body.mad-view-grid #the-list tr.type-product.mad-outofstock .column-thumb::after {
                content: "SOLD";
                position: absolute;
                top: 8px;
                right: 8px;
                color: #fff;
                background: #c00;
                font-weight: 700;
                font-size: 11px;
                letter-spacing: .05em;
                padding: 2px 7px;
                border-radius: 3px;
            }
            <?php if ( $can_sort ) : ?>
            body.mad-view-grid #the-list tr.type-product:not(.mad-outofstock) { cursor: move; }
            <?php endif; ?>
        </style>
        <?php if ( $can_sort ) : ?>
        <script>
        jQuery( function( $ ) {
            $( '#the-list' ).sortable( {
                items:       '> tr.type-product:not(.mad-outofstock)',
                placeholder: 'mad-sortable-placeholder',
                opacity:     0.7,
                start: function( e, ui ) {
                    ui.placeholder.height( ui.item.outerHeight() );
                },
                update: function( e, ui ) {
                    var $row  = ui.item;
                    var id    = ( $row.attr( 'id' ) || '' ).replace( 'post-', '' );
                    var $prev = $row.prev( 'tr.type-product' );
                    var $next = $row.next( 'tr.type-product' );

                    $.post( ajaxurl, {
                        action:  'mad_update_product_order',
                        nonce:   '<?php echo esc_js( wp_create_nonce( 'mad_product_order' ) ); ?>',
                        post_id: id,
                        prev_id: $prev.length ? ( $prev.attr( 'id' ) || '' ).replace( 'post-', '' ) : 0,
                        next_id: $next.length ? ( $next.attr( 'id' ) || '' ).replace( 'post-', '' ) : 0
                    } );
                }
            } );
        } );
        </script>
        <?php endif;
    }

    /**
     * Guarda el nuevo orden de un producto arrastrado, insertándolo entre
     * los menu_order de sus vecinos visuales (prev_id/next_id). Si no queda
     * hueco numérico entre ambos, reespacia todo el catálogo antes de
     * insertar (poco frecuente: solo pasa cuando se agotan los múltiplos
     * de 10 entre dos productos concretos).
     */
    public function ajax_update_product_order(): void {
        check_ajax_referer( 'mad_product_order', 'nonce' );
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( 'forbidden' );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $prev_id = absint( $_POST['prev_id'] ?? 0 );
        $next_id = absint( $_POST['next_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( 'missing post_id' );
        }

        $prev_order = $prev_id ? (int) get_post_field( 'menu_order', $prev_id ) : null;
        $next_order = $next_id ? (int) get_post_field( 'menu_order', $next_id ) : null;

        $needs_renumber = ( null !== $prev_order && null !== $next_order && ( $next_order - $prev_order ) < 2 )
            || ( null === $prev_order && null !== $next_order && $next_order < 1 );

        if ( $needs_renumber ) {
            $this->renumber_product_menu_order();
            $prev_order = $prev_id ? (int) get_post_field( 'menu_order', $prev_id ) : null;
            $next_order = $next_id ? (int) get_post_field( 'menu_order', $next_id ) : null;
        }

        if ( null !== $prev_order && null !== $next_order ) {
            $new_order = (int) floor( ( $prev_order + $next_order ) / 2 );
        } elseif ( null !== $prev_order ) {
            $new_order = $prev_order + 10;
        } elseif ( null !== $next_order ) {
            $new_order = $next_order - 10;
        } else {
            $new_order = 0;
        }

        wp_update_post( [ 'ID' => $post_id, 'menu_order' => $new_order ] );
        wp_send_json_success( [ 'menu_order' => $new_order ] );
    }

    /** Reespacia el menu_order de todos los productos (de 10 en 10), respetando el orden por defecto (sin stock al final). */
    private function renumber_product_menu_order(): void {
        global $wpdb;
        $ids = $wpdb->get_col( "
            SELECT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_stock_status'
            WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private','future')
            ORDER BY pm.meta_value ASC, p.menu_order ASC, p.post_title ASC
        " );

        $order = 0;
        foreach ( $ids as $id ) {
            $wpdb->update( $wpdb->posts, [ 'menu_order' => $order ], [ 'ID' => $id ] );
            clean_post_cache( (int) $id );
            $order += 10;
        }
    }

    /** Miniatura del listado de Productos ampliada (40px por defecto → 110px). */
    public function output_product_list_thumb_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) return;
        // object-fit: cover recorta la imagen manteniendo su proporción en vez
        // de estirarla para llenar la caja de 110×110 — sin esto, las fotos que
        // no son cuadradas se deforman.
        // max-width/max-height explícitos porque WooCommerce trae su propio
        // max-height fijo en esta columna — sin pisarlo, "height" no alcanza
        // (max-height siempre gana sobre height) y la imagen queda achatada.
        echo '<style>
            .wp-list-table .column-thumb { width: 110px !important; }
            .wp-list-table .column-thumb img {
                width: 110px !important;
                height: 110px !important;
                max-width: 110px !important;
                max-height: 110px !important;
                min-width: 110px !important;
                min-height: 110px !important;
                object-fit: cover;
            }
        </style>';
    }

    // ── Settings save ────────────────────────────────────────────────────────

    public function handle_save_settings(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( self::NONCE_KEY, 'mads_olofane_nonce' );

        $post = $_POST;

        $data = [
            'outofstock_label'         => sanitize_text_field( $post['outofstock_label'] ?? '' ),
            'outofstock_label_enabled' => ! empty( $post['outofstock_label_enabled'] ),
            'dual_price_enabled'       => ! empty( $post['dual_price_enabled'] ),
            'vat_field_roles'          => isset( $post['vat_field_roles'] )
                                            ? array_map( 'sanitize_key', (array) $post['vat_field_roles'] )
                                            : [],
            'vat_field_label'          => sanitize_text_field( $post['vat_field_label'] ?? '' ),
            'ai_provider'              => in_array( $post['ai_provider'] ?? '', [ 'claude', 'openai' ], true )
                                            ? $post['ai_provider'] : 'claude',
            'ai_api_key_claude'        => sanitize_text_field( $post['ai_api_key_claude'] ?? '' ),
            'ai_api_key_openai'        => sanitize_text_field( $post['ai_api_key_openai'] ?? '' ),
            'ai_model_claude'          => sanitize_text_field( $post['ai_model_claude'] ?? 'claude-sonnet-4-6' ),
            'ai_model_openai'          => sanitize_text_field( $post['ai_model_openai'] ?? 'gpt-4o' ),
            'ai_prompt_description'    => sanitize_textarea_field( $post['ai_prompt_description'] ?? '' ),
            'ai_prompt_translate_en'   => sanitize_textarea_field( $post['ai_prompt_translate_en'] ?? '' ),
            'ai_prompt_translate_fr'   => sanitize_textarea_field( $post['ai_prompt_translate_fr'] ?? '' ),
            'ai_wpml_enabled'          => ! empty( $post['ai_wpml_enabled'] ),
            'wpml_quotes_enabled'      => ! empty( $post['wpml_quotes_enabled'] ),
            'product_columns_enabled'  => ! empty( $post['product_columns_enabled'] ),
            'product_stock_sort_enabled' => ! empty( $post['product_stock_sort_enabled'] ),
            'product_grid_view_enabled'  => ! empty( $post['product_grid_view_enabled'] ),
            'fk_destacados_enabled'      => ! empty( $post['fk_destacados_enabled'] ),
        ];

        update_option( self::OPTION_KEY, $data );

        wp_safe_redirect( add_query_arg( [ 'page' => $this->menu_slug(), 'saved' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── Settings page ────────────────────────────────────────────────────────

    public function render_settings_page(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) wp_die( 'Sin permisos.' );
        $s     = $this->get_settings();
        $roles = wp_roles()->roles;
        $saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Olofane — Configuración de cliente', 'mad-suite' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Configuración guardada.', 'mad-suite' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::NONCE_KEY, 'mads_olofane_nonce' ); ?>
                <input type="hidden" name="action" value="mads_olofane_save">

                <!-- ── 1: Out-of-stock ─────────────────────────────────── -->
                <h2><?php esc_html_e( '1. Etiqueta de producto agotado en catálogo', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Activar etiqueta', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="outofstock_label_enabled" value="1"
                                    <?php checked( ! empty( $s['outofstock_label_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Mostrar etiqueta sobre imagen en catálogo', 'mad-suite' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="outofstock_label"><?php esc_html_e( 'Texto de etiqueta', 'mad-suite' ); ?></label></th>
                        <td>
                            <input type="text" id="outofstock_label" name="outofstock_label"
                                value="<?php echo esc_attr( $s['outofstock_label'] ); ?>"
                                class="regular-text">
                            <span style="display:inline-block;margin-left:12px;background:rgba(0,0,0,0.65);color:#fff;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;padding:4px 10px;border-radius:3px;">
                                <?php echo esc_html( $s['outofstock_label'] ); ?>
                            </span>
                            <p class="description"><?php esc_html_e( 'Vista previa de cómo se verá la etiqueta.', 'mad-suite' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- ── 3: Dual price ──────────────────────────────────── -->
                <h2><?php esc_html_e( '3. Precio con/sin IVA en ficha de producto', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Activar precio dual', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="dual_price_enabled" value="1"
                                    <?php checked( ! empty( $s['dual_price_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Mostrar precio PVP (excl./incl. IVA) y, si hay una rebaja, un segundo bloque "PROFESIONALES" (excl./incl. IVA) debajo', 'mad-suite' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- ── 4: VAT field ──────────────────────────────────── -->
                <h2><?php esc_html_e( '4. Campo NIF/CIF/VAT en checkout', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="vat_field_label"><?php esc_html_e( 'Etiqueta del campo', 'mad-suite' ); ?></label></th>
                        <td>
                            <input type="text" id="vat_field_label" name="vat_field_label"
                                value="<?php echo esc_attr( $s['vat_field_label'] ); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Roles que deben rellenarlo', 'mad-suite' ); ?></th>
                        <td>
                            <?php foreach ( $roles as $role_slug => $role_data ) : ?>
                                <?php if ( $role_slug === 'administrator' ) continue; ?>
                                <label style="display:block;margin-bottom:5px;">
                                    <input type="checkbox" name="vat_field_roles[]"
                                        value="<?php echo esc_attr( $role_slug ); ?>"
                                        <?php checked( in_array( $role_slug, (array) $s['vat_field_roles'], true ) ); ?>>
                                    <?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
                                    <code style="font-size:11px;color:#666;">(<?php echo esc_html( $role_slug ); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e( 'Dejar vacío para no mostrar el campo.', 'mad-suite' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- ── 5: AI ────────────────────────────────────────── -->
                <h2><?php esc_html_e( '5. Descripciones con IA', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Proveedor', 'mad-suite' ); ?></th>
                        <td>
                            <select name="ai_provider">
                                <option value="claude" <?php selected( $s['ai_provider'], 'claude' ); ?>>Claude (Anthropic)</option>
                                <option value="openai" <?php selected( $s['ai_provider'], 'openai' ); ?>>OpenAI (ChatGPT)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_api_key_claude"><?php esc_html_e( 'API Key Claude', 'mad-suite' ); ?></label></th>
                        <td>
                            <input type="password" id="ai_api_key_claude" name="ai_api_key_claude"
                                value="<?php echo esc_attr( $s['ai_api_key_claude'] ); ?>"
                                class="regular-text" autocomplete="new-password">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_model_claude"><?php esc_html_e( 'Modelo Claude', 'mad-suite' ); ?></label></th>
                        <td>
                            <input type="text" id="ai_model_claude" name="ai_model_claude"
                                value="<?php echo esc_attr( $s['ai_model_claude'] ); ?>" class="regular-text">
                            <p class="description">claude-sonnet-4-6 · claude-haiku-4-5-20251001</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_api_key_openai"><?php esc_html_e( 'API Key OpenAI', 'mad-suite' ); ?></label></th>
                        <td>
                            <input type="password" id="ai_api_key_openai" name="ai_api_key_openai"
                                value="<?php echo esc_attr( $s['ai_api_key_openai'] ); ?>"
                                class="regular-text" autocomplete="new-password">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_model_openai"><?php esc_html_e( 'Modelo OpenAI', 'mad-suite' ); ?></label></th>
                        <td>
                            <input type="text" id="ai_model_openai" name="ai_model_openai"
                                value="<?php echo esc_attr( $s['ai_model_openai'] ); ?>" class="regular-text">
                            <p class="description">gpt-4o · gpt-4o-mini</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_prompt_description"><?php esc_html_e( 'Prompt descripción (ES)', 'mad-suite' ); ?></label></th>
                        <td>
                            <textarea id="ai_prompt_description" name="ai_prompt_description"
                                rows="4" class="large-text"><?php echo esc_textarea( $s['ai_prompt_description'] ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( '{product_name} — nombre del producto. {product_context} — opcional: si lo incluyes, ahí se inserta la categoría, atributos y precio del producto; si no lo incluyes, se añade igualmente al final del prompt. Además, la imagen destacada del producto se envía siempre que exista (Claude y GPT-4o pueden verla).', 'mad-suite' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_prompt_translate_en"><?php esc_html_e( 'Prompt traducción EN', 'mad-suite' ); ?></label></th>
                        <td>
                            <textarea id="ai_prompt_translate_en" name="ai_prompt_translate_en"
                                rows="3" class="large-text"><?php echo esc_textarea( $s['ai_prompt_translate_en'] ); ?></textarea>
                            <p class="description">{text}</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_prompt_translate_fr"><?php esc_html_e( 'Prompt traducción FR', 'mad-suite' ); ?></label></th>
                        <td>
                            <textarea id="ai_prompt_translate_fr" name="ai_prompt_translate_fr"
                                rows="3" class="large-text"><?php echo esc_textarea( $s['ai_prompt_translate_fr'] ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Auto-traducir con WPML', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ai_wpml_enabled" value="1"
                                    <?php checked( ! empty( $s['ai_wpml_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Al guardar descripción en español → actualizar EN y FR automáticamente', 'mad-suite' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- ── 6: WPML + Quotes ──────────────────────────────── -->
                <h2><?php esc_html_e( '6. WPML + Cotizaciones', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Herramienta de traducción en pedidos', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpml_quotes_enabled" value="1"
                                    <?php checked( ! empty( $s['wpml_quotes_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Meta box de respuesta/traducción en todos los pedidos de WooCommerce', 'mad-suite' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- ── 7: Columnas de producto ──────────────────────────── -->
                <h2><?php esc_html_e( '7. Columnas extra en el listado de Productos', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Activar columnas', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="product_columns_enabled" value="1"
                                    <?php checked( ! empty( $s['product_columns_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Agregar columnas "Medidas" (L×A×A de WooCommerce), "Ubicación" (taxonomía de ATUM Inventory) y "Margen" (sobre precio regular y rebajado) en WooCommerce → Productos, y mostrar la miniatura al doble de tamaño.', 'mad-suite' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'La ubicación usa la función gratuita de ATUM ("Ubicaciones de producto"). Si ATUM no está activo, esa columna avisa en vez de mostrar datos. El margen se calcula con el campo "Coste (proveedor)" que aparece en la pestaña General de cada producto — si un producto no tiene coste cargado, la columna muestra "Sin coste".', 'mad-suite' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- ── 8: Orden y vista del listado de Productos ──────────── -->
                <h2><?php esc_html_e( '8. Orden y vista del listado de Productos', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Sin stock al final', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="product_stock_sort_enabled" value="1"
                                    <?php checked( ! empty( $s['product_stock_sort_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Por defecto, mostrar los productos agotados al final del listado de Productos.', 'mad-suite' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Solo aplica cuando no se ha pulsado ninguna columna de orden ni hay una búsqueda activa — si el usuario ordena manualmente por otra columna, se respeta ese orden.', 'mad-suite' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Vista de cuadrícula', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="product_grid_view_enabled" value="1"
                                    <?php checked( ! empty( $s['product_grid_view_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Añadir un selector Tabla/Cuadrícula sobre el listado de Productos, con un filtro para ver solo los destacados y organización manual arrastrando las tarjetas en la vista de cuadrícula.', 'mad-suite' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'El arrastre reordena los productos (guardado en el campo nativo "menu_order" de WordPress) y solo está disponible cuando el listado está en su orden por defecto, sin una columna de orden explícita activa. La vista de cuadrícula solo muestra foto y nombre. Los productos agotados quedan siempre al final, bloqueados (no se pueden arrastrar) y marcados con la etiqueta "SOLD" en rojo. El botón "Más recientes primero" reorganiza automáticamente todos los productos por fecha de publicación (perdiendo el orden manual anterior).', 'mad-suite' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- ── 9: Destacados en FunnelKit ──────────────────────────── -->
                <h2><?php esc_html_e( '9. Productos destacados en emails de FunnelKit', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Activar', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="fk_destacados_enabled" value="1"
                                    <?php checked( ! empty( $s['fk_destacados_enabled'] ) ); ?>>
                                <?php esc_html_e( 'En el bloque "Product" de un email de FunnelKit, si se configura Feed = "Specific Categories" y Sort by = "Random", enviar solo los productos destacados en el orden manual del listado de Productos (el mismo de la sección 8).', 'mad-suite' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Ese combo de ajustes no tiene un uso real en FunnelKit — se reutiliza como marcador para activar este comportamiento sin depender de una opción nativa. En cualquier otra combinación, el bloque funciona como siempre.', 'mad-suite' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Guardar cambios', 'mad-suite' ) ); ?>
            </form>
        </div>
        <?php
    }
};
