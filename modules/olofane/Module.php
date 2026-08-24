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

        // Feature 8 – Custom user avatar
        add_filter( 'get_avatar_url',  [ $this, 'custom_avatar_url' ], 10, 3 );
        add_filter( 'get_avatar',      [ $this, 'custom_avatar_img' ], 10, 6 );
        add_action( 'show_user_profile',        [ $this, 'render_avatar_field' ] );
        add_action( 'edit_user_profile',        [ $this, 'render_avatar_field' ] );
        add_action( 'personal_options_update',  [ $this, 'save_avatar_field' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_avatar_field' ] );
        if ( is_admin() ) {
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_avatar_admin_js' ] );
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
    //   Regular price = B2C price  (shown crossed-out so B2B sees the discount)
    //   Sale price    = B2B price  (shown as excl. IVA big + incl. IVA small)
    // If no sale price exists, only show excl/incl for the regular price.

    public function dual_price_display( string $price_html, WC_Product $product ): string {
        if ( ! is_product() ) return $price_html;
        if ( $price_html === '' ) return $price_html;

        static $css_printed = false;
        $css = '';
        if ( ! $css_printed ) {
            $css_printed = true;
            $css = '<style>
                .mad-olofane-price-b2c { display:block; font-size:.9em; opacity:.6; text-decoration:line-through; margin-bottom:4px; }
                .mad-olofane-price-excl { display:block; font-size:1.5em; font-weight:700; line-height:1.15; }
                .mad-olofane-price-excl small { font-size:0.5em; font-weight:400; opacity:.7; }
                .mad-olofane-price-incl { display:block; font-size:0.85em; color:#666; margin-top:3px; }
                .mad-olofane-price-incl small { font-size:0.9em; }
            </style>';
        }

        // Variable products: show min–max excl. range + min incl.
        // We don't show the B2C del for variables since the range already implies discount.
        if ( $product->is_type( 'variable' ) ) {
            $min_price = (float) $product->get_variation_price( 'min' );
            if ( $min_price <= 0 ) return $price_html;

            $max_price = (float) $product->get_variation_price( 'max' );
            $excl_min  = (float) wc_get_price_excluding_tax( $product, [ 'price' => $min_price ] );
            $incl_min  = (float) wc_get_price_including_tax( $product, [ 'price' => $min_price ] );

            if ( $min_price !== $max_price ) {
                $excl_max       = (float) wc_get_price_excluding_tax( $product, [ 'price' => $max_price ] );
                $excl_formatted = wc_price( $excl_min ) . ' – ' . wc_price( $excl_max );
            } else {
                $excl_formatted = wc_price( $excl_min );
            }

            return $css . sprintf(
                '<span class="mad-olofane-price-excl">%s <small>%s</small></span>'
                . '<span class="mad-olofane-price-incl">%s <small>%s</small></span>',
                $excl_formatted,
                esc_html__( 'excl. IVA', 'mad-suite' ),
                wc_price( $incl_min ),
                esc_html__( 'incl. IVA', 'mad-suite' )
            );
        }

        // Simple / external product
        // The B2B price is the active price (sale price if set, else regular price)
        $b2b_raw = (float) $product->get_price();
        if ( $b2b_raw <= 0 ) return $price_html;

        $excl = (float) wc_get_price_excluding_tax( $product );
        $incl = (float) wc_get_price_including_tax( $product );

        // B2C crossed-out price — only when there is a separate sale (B2B) price
        $del_html = '';
        if ( $product->is_on_sale() ) {
            // Use WC's display-price logic so it respects the shop's tax display setting
            $b2c_display = (float) wc_get_price_to_display( $product, [ 'price' => $product->get_regular_price() ] );
            $del_html    = '<span class="mad-olofane-price-b2c">' . wc_price( $b2c_display ) . '</span>';
        }

        return $css . $del_html . sprintf(
            '<span class="mad-olofane-price-excl">%s <small>%s</small></span>'
            . '<span class="mad-olofane-price-incl">%s <small>%s</small></span>',
            wc_price( $excl ),
            esc_html__( 'excl. IVA', 'mad-suite' ),
            wc_price( $incl ),
            esc_html__( 'incl. IVA', 'mad-suite' )
        );
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
                                <?php esc_html_e( 'Mostrar precio excl. IVA (grande) + incl. IVA (pequeño)', 'mad-suite' ); ?>
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
                            <p class="description">{product_name}</p>
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

                <?php submit_button( __( 'Guardar cambios', 'mad-suite' ) ); ?>
            </form>
        </div>
        <?php
    }

    // ── Feature 8: Custom user avatar ────────────────────────────────────────

    private function get_user_avatar_url( int $user_id ): string {
        $attachment_id = (int) get_user_meta( $user_id, '_mad_avatar_id', true );
        if ( ! $attachment_id ) return '';
        $url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
        return $url ?: '';
    }

    public function custom_avatar_url( string $url, $id_or_email, array $args ): string {
        $user_id = 0;
        if ( is_numeric( $id_or_email ) ) {
            $user_id = (int) $id_or_email;
        } elseif ( is_string( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            if ( $user ) $user_id = $user->ID;
        } elseif ( $id_or_email instanceof WP_User ) {
            $user_id = $id_or_email->ID;
        } elseif ( $id_or_email instanceof WP_Post ) {
            $user_id = (int) $id_or_email->post_author;
        } elseif ( $id_or_email instanceof WP_Comment ) {
            $user_id = (int) $id_or_email->user_id;
        }
        if ( ! $user_id ) return $url;

        $custom = $this->get_user_avatar_url( $user_id );
        return $custom ?: $url;
    }

    public function custom_avatar_img( string $avatar, $id_or_email, $size, string $default, string $alt, array $args ): string {
        $user_id = 0;
        if ( is_numeric( $id_or_email ) ) {
            $user_id = (int) $id_or_email;
        } elseif ( is_string( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            if ( $user ) $user_id = $user->ID;
        } elseif ( $id_or_email instanceof WP_User ) {
            $user_id = $id_or_email->ID;
        } elseif ( $id_or_email instanceof WP_Post ) {
            $user_id = (int) $id_or_email->post_author;
        } elseif ( $id_or_email instanceof WP_Comment ) {
            $user_id = (int) $id_or_email->user_id;
        }
        if ( ! $user_id ) return $avatar;

        $custom_url = $this->get_user_avatar_url( $user_id );
        if ( ! $custom_url ) return $avatar;

        $size_px = is_array( $size ) ? ( $size[0] ?? 96 ) : (int) $size;
        $class   = isset( $args['class'] ) ? esc_attr( implode( ' ', (array) $args['class'] ) ) : 'avatar';

        return sprintf(
            '<img src="%s" width="%d" height="%d" class="%s avatar-%d photo" alt="%s" loading="lazy">',
            esc_url( $custom_url ),
            $size_px,
            $size_px,
            esc_attr( $class . ' avatar-' . $size_px ),
            $size_px,
            esc_attr( $alt )
        );
    }

    public function render_avatar_field( WP_User $user ): void {
        $attachment_id = (int) get_user_meta( $user->ID, '_mad_avatar_id', true );
        $current_url   = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
        ?>
        <h2><?php esc_html_e( 'Foto de perfil', 'mad-suite' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label><?php esc_html_e( 'Imagen', 'mad-suite' ); ?></label></th>
                <td>
                    <?php wp_nonce_field( 'mad_avatar_save_' . $user->ID, 'mad_avatar_nonce' ); ?>
                    <div id="mad-avatar-preview" style="margin-bottom:10px;">
                        <?php if ( $current_url ) : ?>
                            <img src="<?php echo esc_url( $current_url ); ?>"
                                 style="width:96px;height:96px;object-fit:cover;border-radius:50%;border:2px solid #ddd;display:block;">
                        <?php else : ?>
                            <div style="width:96px;height:96px;border-radius:50%;background:#e0e0e0;display:flex;align-items:center;justify-content:center;color:#999;font-size:32px;">
                                &#128100;
                            </div>
                        <?php endif; ?>
                    </div>

                    <input type="hidden" name="mad_avatar_attachment_id"
                           id="mad-avatar-attachment-id"
                           value="<?php echo esc_attr( $attachment_id ?: '' ); ?>">
                    <input type="hidden" name="mad_avatar_remove"
                           id="mad-avatar-remove-flag" value="0">

                    <button type="button" class="button" id="mad-avatar-upload-btn">
                        <?php esc_html_e( $attachment_id ? 'Cambiar foto' : 'Subir foto', 'mad-suite' ); ?>
                    </button>
                    <?php if ( $attachment_id ) : ?>
                    <button type="button" class="button" id="mad-avatar-remove-btn" style="margin-left:6px;">
                        <?php esc_html_e( 'Quitar foto', 'mad-suite' ); ?>
                    </button>
                    <?php endif; ?>

                    <p class="description" style="margin-top:6px;">
                        <?php esc_html_e( 'Formatos: JPG, PNG, WebP. Se mostrará en lugar del Gravatar.', 'mad-suite' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_avatar_field( int $user_id ): void {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;
        if ( ! isset( $_POST['mad_avatar_nonce'] ) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mad_avatar_nonce'] ) ), 'mad_avatar_save_' . $user_id ) ) return;

        // Remove requested
        if ( ! empty( $_POST['mad_avatar_remove'] ) && '1' === $_POST['mad_avatar_remove'] ) {
            delete_user_meta( $user_id, '_mad_avatar_id' );
            return;
        }

        $attachment_id = isset( $_POST['mad_avatar_attachment_id'] ) ? absint( $_POST['mad_avatar_attachment_id'] ) : 0;
        if ( $attachment_id ) {
            update_user_meta( $user_id, '_mad_avatar_id', $attachment_id );
        }
    }

    public function enqueue_avatar_admin_js( string $hook ): void {
        if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) return;
        wp_enqueue_media();
        wp_register_script( 'mad-olofane-avatar', false, [ 'jquery', 'media-editor' ], null, true );
        wp_enqueue_script( 'mad-olofane-avatar' );
        wp_add_inline_script( 'mad-olofane-avatar', $this->avatar_admin_js() );
    }

    private function avatar_admin_js(): string {
        return <<<'JS'
(function($) {
    'use strict';
    var frame;

    $('#mad-avatar-upload-btn').on('click', function(e) {
        e.preventDefault();
        frame = wp.media({
            title   : 'Seleccionar foto de perfil',
            button  : { text: 'Usar esta foto' },
            multiple: false,
            library : { type: 'image' }
        });
        frame.on('select', function() {
            var att   = frame.state().get('selection').first().toJSON();
            var thumb = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
            $('#mad-avatar-attachment-id').val(att.id);
            $('#mad-avatar-remove-flag').val('0');
            $('#mad-avatar-preview').html(
                '<img src="' + thumb + '" style="width:96px;height:96px;object-fit:cover;border-radius:50%;border:2px solid #ddd;display:block;">'
            );
            $('#mad-avatar-upload-btn').text('Cambiar foto');
            if (!$('#mad-avatar-remove-btn').length) {
                $('<button type="button" class="button" id="mad-avatar-remove-btn" style="margin-left:6px;">Quitar foto</button>')
                    .insertAfter('#mad-avatar-upload-btn');
            }
            $('#mad-avatar-remove-btn').show();
        });
        frame.open();
    });

    $(document).on('click', '#mad-avatar-remove-btn', function(e) {
        e.preventDefault();
        $('#mad-avatar-attachment-id').val('');
        $('#mad-avatar-remove-flag').val('1');
        $('#mad-avatar-preview').html(
            '<div style="width:96px;height:96px;border-radius:50%;background:#e0e0e0;display:flex;align-items:center;justify-content:center;color:#999;font-size:32px;">&#128100;</div>'
        );
        $('#mad-avatar-upload-btn').text('Subir foto');
        $(this).hide();
    });
})(jQuery);
JS;
    }
};
