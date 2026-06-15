<?php
/**
 * Olofane — Configuración específica para el cliente Olofane.
 *
 * Features:
 *  1. Etiqueta de texto sobre imagen de producto agotado en catálogo.
 *  2. Área de notas del pedido expandida por defecto en checkout.
 *  3. Precio en ficha de producto: grande sin IVA + pequeño con IVA.
 *  4. Campo NIF/CIF/VAT requerido en checkout para roles configurables.
 *  5. Descripciones con IA (Claude/OpenAI) + traducción automática WPML (ES→EN/FR).
 *  6. WPML + Quotes: emails en idioma del cliente + herramienta de traducción en pedidos.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/AI_Description.php';
require_once __DIR__ . '/includes/WPML_Quotes.php';

return new class ( $core ?? null ) implements MAD_Suite_Module {

    private const OPTION_KEY = 'madsuite_olofane_settings';
    private const NONCE_KEY  = 'mads_olofane_save';

    // ── MAD_Suite_Module identity ────────────────────────────────────────────

    public function __construct( $core ) {}

    public function slug()        { return 'olofane'; }
    public function title()       { return __( 'Olofane — Configuración de cliente', 'mad-suite' ); }
    public function menu_label()  { return __( 'Olofane', 'mad-suite' ); }
    public function menu_slug()   { return 'mad-suite-olofane'; }
    public function description() { return __( 'Ajustes exclusivos para el cliente Olofane.', 'mad-suite' ); }
    public function required_plugins() { return [ 'WooCommerce' => 'woocommerce/woocommerce.php' ]; }

    // ── Settings helper ──────────────────────────────────────────────────────

    private function get_settings(): array {
        $defaults = [
            // Feature 1 – out-of-stock label
            'outofstock_label'         => __( 'Agotado', 'mad-suite' ),
            'outofstock_label_enabled' => true,

            // Feature 3 – dual price display
            'dual_price_enabled'       => true,

            // Feature 4 – VAT field
            'vat_field_roles'          => [],
            'vat_field_label'          => __( 'NIF/CIF/VAT Number', 'mad-suite' ),

            // Feature 5 – AI descriptions
            'ai_provider'              => 'claude',   // 'claude' | 'openai'
            'ai_api_key_claude'        => '',
            'ai_api_key_openai'        => '',
            'ai_model_claude'          => 'claude-sonnet-4-6',
            'ai_model_openai'          => 'gpt-4o',
            'ai_prompt_description'    => 'Escribe una descripción de producto atractiva, profesional y en español para: {product_name}. Incluye características principales y beneficios. Devuelve solo el texto, sin etiquetas HTML.',
            'ai_prompt_translate_en'   => 'Translate the following Spanish product description to English. Return only the translated text:\n\n{text}',
            'ai_prompt_translate_fr'   => 'Traduis la description de produit suivante de l\'espagnol vers le français. Retourne uniquement le texte traduit :\n\n{text}',
            'ai_wpml_enabled'          => true,

            // Feature 6 – WPML + Quotes
            'wpml_quotes_enabled'      => true,
        ];

        $opts = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( is_array( $opts ) ? $opts : [], $defaults );
    }

    // ── Lifecycle hooks ──────────────────────────────────────────────────────

    public function init(): void {
        $s = $this->get_settings();

        // Feature 1 – out-of-stock label overlay
        if ( ! empty( $s['outofstock_label_enabled'] ) ) {
            add_action( 'woocommerce_before_shop_loop_item_title', [ $this, 'render_outofstock_overlay' ], 11 );
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
        }

        // Feature 2 – expanded order notes textarea
        add_filter( 'woocommerce_checkout_fields', [ $this, 'expand_order_notes' ], 20 );

        // Feature 3 – dual price on product page
        if ( ! empty( $s['dual_price_enabled'] ) ) {
            add_filter( 'woocommerce_get_price_html', [ $this, 'dual_price_display' ], 20, 2 );
        }

        // Feature 4 – VAT/NIF field
        if ( ! empty( $s['vat_field_roles'] ) ) {
            add_filter( 'woocommerce_checkout_fields',       [ $this, 'add_vat_checkout_field' ], 25 );
            add_action( 'woocommerce_checkout_process',      [ $this, 'validate_vat_field' ] );
            add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_vat_to_order' ] );
            add_action( 'woocommerce_checkout_update_customer', [ $this, 'save_vat_to_user' ], 10, 2 );
        }

        // Feature 5 – AI descriptions (admin only)
        if ( is_admin() ) {
            $ai = new MAD_Olofane_AI_Description( $s );
            $ai->init();
        }

        // Feature 6 – WPML + Quotes translation tool
        if ( ! empty( $s['wpml_quotes_enabled'] ) ) {
            $wpml = new MAD_Olofane_WPML_Quotes( $s );
            $wpml->init();
        }
    }

    public function admin_init(): void {
        add_action( 'admin_post_mads_olofane_save', [ $this, 'handle_save_settings' ] );
    }

    // ── Feature 1: Out-of-stock overlay ─────────────────────────────────────

    public function render_outofstock_overlay(): void {
        global $product;
        if ( ! $product instanceof WC_Product ) return;
        if ( $product->is_in_stock() ) return;

        $s     = $this->get_settings();
        $label = esc_html( trim( $s['outofstock_label'] ) );
        if ( $label === '' ) return;

        echo '<span class="mad-olofane-outofstock-label">' . $label . '</span>';
    }

    public function enqueue_frontend_assets(): void {
        if ( ! ( is_shop() || is_product_category() || is_product_tag() || is_product() ) ) return;
        wp_add_inline_style( 'woocommerce-general', $this->outofstock_css() );
    }

    private function outofstock_css(): string {
        return '
        .woocommerce ul.products li.product { position: relative; }
        .mad-olofane-outofstock-label {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.65);
            color: #fff;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 6px 14px;
            border-radius: 3px;
            pointer-events: none;
            z-index: 10;
            white-space: nowrap;
        }';
    }

    // ── Feature 2: Expand order notes ────────────────────────────────────────

    public function expand_order_notes( array $fields ): array {
        if ( isset( $fields['order']['order_comments'] ) ) {
            $fields['order']['order_comments']['rows'] = 5;
            $fields['order']['order_comments']['class'][] = 'mad-olofane-notes-expanded';
        }
        return $fields;
    }

    // ── Feature 3: Dual price display (excl. + incl. VAT) ───────────────────

    public function dual_price_display( string $price_html, WC_Product $product ): string {
        if ( ! is_product() ) return $price_html;
        if ( $price_html === '' ) return $price_html;

        // Only for simple/variable products with a regular price
        $price_excl = (float) wc_get_price_excluding_tax( $product );
        $price_incl = (float) wc_get_price_including_tax( $product );

        if ( $price_excl <= 0 ) return $price_html;

        $excl_formatted = wc_price( $price_excl );
        $incl_formatted = wc_price( $price_incl );

        return sprintf(
            '<span class="mad-olofane-price-excl">%s <small>%s</small></span>'
            . '<span class="mad-olofane-price-incl">%s <small>%s</small></span>',
            $excl_formatted,
            esc_html__( 'excl. IVA', 'mad-suite' ),
            $incl_formatted,
            esc_html__( 'incl. IVA', 'mad-suite' )
        );
    }

    // ── Feature 4: VAT / NIF field ───────────────────────────────────────────

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

        $s       = $this->get_settings();
        $label   = ! empty( $s['vat_field_label'] ) ? $s['vat_field_label'] : __( 'NIF/CIF/VAT Number', 'mad-suite' );
        $default = get_user_meta( get_current_user_id(), 'billing_vat', true );

        $fields['billing']['billing_vat'] = [
            'label'       => $label,
            'placeholder' => 'B12345678',
            'required'    => true,
            'class'       => [ 'form-row-wide' ],
            'priority'    => 25,
            'default'     => $default,
        ];

        return $fields;
    }

    public function validate_vat_field(): void {
        if ( ! $this->current_user_needs_vat() ) return;
        $val = isset( $_POST['billing_vat'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_vat'] ) ) : '';
        if ( empty( $val ) ) {
            $s = $this->get_settings();
            $label = $s['vat_field_label'] ?? __( 'NIF/CIF/VAT Number', 'mad-suite' );
            wc_add_notice(
                sprintf( __( 'El campo "%s" es obligatorio.', 'mad-suite' ), esc_html( $label ) ),
                'error'
            );
        }
    }

    public function save_vat_to_order( int $order_id ): void {
        if ( ! isset( $_POST['billing_vat'] ) ) return;
        $val = sanitize_text_field( wp_unslash( $_POST['billing_vat'] ) );
        update_post_meta( $order_id, '_billing_vat', $val );
    }

    public function save_vat_to_user( WC_Customer $customer, array $data ): void {
        $val = isset( $_POST['billing_vat'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_vat'] ) ) : '';
        if ( $val !== '' && $customer->get_id() ) {
            update_user_meta( $customer->get_id(), 'billing_vat', $val );
        }
    }

    // ── Settings save handler ────────────────────────────────────────────────

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
                                            ? $post['ai_provider']
                                            : 'claude',
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

                <!-- ── Feature 1: Out-of-stock label ──────────────────────── -->
                <h2><?php esc_html_e( '1. Etiqueta de producto agotado', 'mad-suite' ); ?></h2>
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
                        </td>
                    </tr>
                </table>

                <!-- ── Feature 3: Dual price ──────────────────────────────── -->
                <h2><?php esc_html_e( '3. Precio con/sin IVA en ficha de producto', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Activar precio dual', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="dual_price_enabled" value="1"
                                    <?php checked( ! empty( $s['dual_price_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Mostrar precio excl. IVA (grande) + incl. IVA (pequeño) en la ficha de producto', 'mad-suite' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- ── Feature 4: VAT field ───────────────────────────────── -->
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

                <!-- ── Feature 5: AI Descriptions ────────────────────────── -->
                <h2><?php esc_html_e( '5. Descripciones con IA', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Proveedor de IA', 'mad-suite' ); ?></th>
                        <td>
                            <select name="ai_provider">
                                <option value="claude" <?php selected( $s['ai_provider'], 'claude' ); ?>>Claude (Anthropic)</option>
                                <option value="openai" <?php selected( $s['ai_provider'], 'openai' ); ?>>OpenAI (ChatGPT)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_api_key_claude"><?php esc_html_e( 'API Key de Claude', 'mad-suite' ); ?></label></th>
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
                                value="<?php echo esc_attr( $s['ai_model_claude'] ); ?>"
                                class="regular-text">
                            <p class="description"><?php esc_html_e( 'p.ej. claude-sonnet-4-6, claude-haiku-4-5-20251001', 'mad-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_api_key_openai"><?php esc_html_e( 'API Key de OpenAI', 'mad-suite' ); ?></label></th>
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
                                value="<?php echo esc_attr( $s['ai_model_openai'] ); ?>"
                                class="regular-text">
                            <p class="description"><?php esc_html_e( 'p.ej. gpt-4o, gpt-4o-mini', 'mad-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_prompt_description"><?php esc_html_e( 'Prompt descripción (ES)', 'mad-suite' ); ?></label></th>
                        <td>
                            <textarea id="ai_prompt_description" name="ai_prompt_description"
                                rows="4" class="large-text"><?php echo esc_textarea( $s['ai_prompt_description'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Usa {product_name} para insertar el nombre del producto.', 'mad-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_prompt_translate_en"><?php esc_html_e( 'Prompt traducción EN', 'mad-suite' ); ?></label></th>
                        <td>
                            <textarea id="ai_prompt_translate_en" name="ai_prompt_translate_en"
                                rows="3" class="large-text"><?php echo esc_textarea( $s['ai_prompt_translate_en'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Usa {text} para insertar el texto a traducir.', 'mad-suite' ); ?></p>
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
                                <?php esc_html_e( 'Al guardar la descripción en español, crear/actualizar traducciones EN y FR automáticamente.', 'mad-suite' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- ── Feature 6: WPML + Quotes ───────────────────────────── -->
                <h2><?php esc_html_e( '6. WPML + Cotizaciones', 'mad-suite' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Herramienta de traducción en pedidos', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpml_quotes_enabled" value="1"
                                    <?php checked( ! empty( $s['wpml_quotes_enabled'] ) ); ?>>
                                <?php esc_html_e( 'Mostrar meta box de traducción en todos los pedidos de WooCommerce.', 'mad-suite' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Guardar cambios', 'mad-suite' ) ); ?>
            </form>
        </div>
        <?php
    }
};
