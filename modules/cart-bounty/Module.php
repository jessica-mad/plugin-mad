<?php
/**
 * Módulo: Cart Bounty by MAD
 *
 * Popup de solo-email que aparece al agregar un producto al carrito, para
 * alimentar la recuperación de carrito abandonado de FunnelKit Automations
 * capturando el email de invitados antes del checkout.
 *
 * Arquitectura (deliberada, no cambiar sin repensar el flujo):
 *  - Fluent Forms y FunnelKit Automations son ecosistemas separados — no
 *    existe conector nativo entre ellos. El puente es escribir el email
 *    como billing email en la sesión de WooCommerce
 *    (WC()->customer->set_billing_email()) al enviarse el formulario.
 *    FunnelKit detecta esa sesión con email y hace el resto (carrito
 *    recuperable, evento Cart Abandoned, add-to-list, secuencia de
 *    emails) de forma nativa — no hay nada más que "empujar" desde acá.
 *  - La lista/etiqueta de contacto se configura en FunnelKit → Settings →
 *    Carts (Add to list / Add tag), no en este módulo ni en el formulario.
 *  - El popup solo se muestra a invitados no logueados: a los usuarios
 *    logueados FunnelKit ya los identifica al agregar al carrito.
 *
 * @package MAD_Suite/CartBounty
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var MAD_Suite_Core $core */

return new class( $core ) implements MAD_Suite_Module {

    private $core;
    private $slug    = 'cart-bounty';
    private $opt_key = 'madsuite_cart_bounty';

    public function __construct( $core ) {
        $this->core = $core;
    }

    /* ================================================================ */
    /*  Interface                                                        */
    /* ================================================================ */

    public function slug()       { return $this->slug; }
    public function title()      { return __( 'Cart Bounty by MAD', 'mad-suite' ); }
    public function menu_label() { return __( 'Cart Bounty', 'mad-suite' ); }
    public function menu_slug()  { return MAD_Suite_Core::MENU_SLUG_ROOT . '-cart-bounty'; }
    public function description() {
        return __( 'Popup de solo-email al agregar al carrito, para alimentar la recuperación de carrito abandonado de FunnelKit Automations. Requiere Fluent Forms para el formulario y FunnelKit Automations para la recuperación.', 'mad-suite' );
    }
    public function required_plugins() {
        return [
            'WooCommerce'           => 'woocommerce/woocommerce.php',
            'Fluent Forms'          => 'fluentform/fluentform.php',
            'FunnelKit Automations' => 'wp-marketing-automations/wp-marketing-automations.php',
        ];
    }

    /* ================================================================ */
    /*  init()                                                           */
    /* ================================================================ */

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) return;
        if ( ! $this->get_form_id() ) return; // sin form_id configurado, el módulo no hace nada en el front

        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );

        // Panel inline, no popup: se imprime justo después del bloque de
        // avisos de WooCommerce (".woocommerce-notices-wrapper", donde
        // aparece el "Se agregó [producto] a tu carrito/lista de precios")
        // en la ficha de producto — prioridad 20 para correr después de que
        // wc_print_notices() imprima ese bloque en woocommerce_before_single_product
        // (prioridad 10). Se queda oculto hasta que added_to_cart lo abre,
        // así que en la carga inicial no se nota que está ahí.
        // En la página de Carrito, como aviso arriba de la tabla (fallback
        // si por lo que sea el evento added_to_cart no llegó a dispararse
        // en la ficha).
        add_action( 'woocommerce_before_single_product', [ $this, 'render_panel_product' ], 20 );
        add_action( 'woocommerce_before_cart_table',      [ $this, 'render_panel_cart' ] );

        // Puente: al enviarse el form de Fluent Forms, guardar el email en
        // la sesión de WooCommerce. Guardas exactamente como se pidió: no
        // tocar nada si el form no es el configurado, si el email es
        // inválido, o si WooCommerce/la sesión no están disponibles.
        add_action( 'fluentform/submission_inserted', [ $this, 'bridge_email_to_woo_session' ], 10, 3 );
    }

    /* ================================================================ */
    /*  admin_init()                                                     */
    /* ================================================================ */

    public function admin_init() {
        register_setting( $this->opt_key, $this->opt_key, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'mad_cb_main',
            __( 'Configuración', 'mad-suite' ),
            function () {
                echo '<p>' . wp_kses_post( __(
                    'La lista/etiqueta de contacto para la secuencia de recuperación se configura en <strong>FunnelKit Automations → Settings → Carts</strong> (Add to list / Add tag) — no acá. Este módulo solo se encarga de que el email quede guardado en la sesión de WooCommerce antes del checkout.',
                    'mad-suite'
                ) ) . '</p>';
            },
            $this->opt_key
        );

        add_settings_field(
            'form_id',
            __( 'ID del formulario (Fluent Forms)', 'mad-suite' ),
            [ $this, 'field_form_id' ],
            $this->opt_key,
            'mad_cb_main'
        );

        add_settings_field(
            'title_text',
            __( 'Título / texto del panel', 'mad-suite' ),
            [ $this, 'field_title_text' ],
            $this->opt_key,
            'mad_cb_main'
        );

        add_settings_field(
            'consent_text',
            __( 'Texto de consentimiento', 'mad-suite' ),
            [ $this, 'field_consent_text' ],
            $this->opt_key,
            'mad_cb_main'
        );
    }

    /* ================================================================ */
    /*  Frontend: enqueue + modal                                        */
    /* ================================================================ */

    public function maybe_enqueue_assets() {
        if ( ! function_exists( 'is_product' ) || ! ( is_product() || is_cart() ) ) return;
        if ( ! $this->should_show() ) return;

        wp_enqueue_style(
            'mad-cart-bounty',
            plugins_url( 'assets/css/cart-bounty.css', __FILE__ ),
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'mad-cart-bounty',
            plugins_url( 'assets/js/cart-bounty.js', __FILE__ ),
            [ 'jquery' ],
            '1.0.0',
            true
        );

        wp_localize_script( 'mad-cart-bounty', 'madCartBounty', [
            'isCart'          => function_exists( 'is_cart' ) && is_cart(),
            'cartHasItems'    => ( function_exists( 'WC' ) && WC()->cart ) ? ! WC()->cart->is_empty() : false,
            'alreadyCaptured' => $this->already_captured_this_session(),
        ] );
    }

    public function render_panel_product() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
        if ( ! $this->should_show() ) return;
        $this->render_panel();
    }

    public function render_panel_cart() {
        if ( ! function_exists( 'is_cart' ) || ! is_cart() ) return;
        if ( ! $this->should_show() ) return;
        $this->render_panel();
    }

    private function render_panel() {
        $settings = $this->get_settings();
        $form_id  = $this->get_form_id();
        ?>
        <div id="mad-cart-bounty-panel" class="mad-cb-panel" aria-hidden="true">
            <button type="button" class="mad-cb-close" data-mad-cb-close aria-label="<?php esc_attr_e( 'Cerrar', 'mad-suite' ); ?>">&times;</button>
            <h5 id="mad-cb-title" class="mad-cb-title"><?php echo esc_html( $settings['title_text'] ); ?></h5>
            <div class="mad-cb-form">
                <?php echo do_shortcode( '[fluentform id="' . absint( $form_id ) . '"]' ); ?>
            </div>
            <p class="mad-cb-consent"><?php echo esc_html( $settings['consent_text'] ); ?></p>
        </div>
        <?php
    }

    /**
     * Solo mostrar a invitados, en ficha de producto o carrito, si hay
     * form_id configurado y todavía no se capturó el email esta sesión.
     * El chequeo de página (is_product()/is_cart()) lo hace cada caller.
     */
    private function should_show(): bool {
        if ( is_admin() ) return false;
        if ( is_user_logged_in() ) return false;
        if ( ! $this->get_form_id() ) return false;
        if ( $this->already_captured_this_session() ) return false;

        return true;
    }

    private function already_captured_this_session(): bool {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) return false;
        return (bool) WC()->session->get( 'mad_cart_bounty_captured' );
    }

    /* ================================================================ */
    /*  Puente: Fluent Forms → sesión de WooCommerce                     */
    /* ================================================================ */

    public function bridge_email_to_woo_session( $entryId, $formData, $form ) {
        $target_form_id = $this->get_form_id();
        if ( ! $target_form_id ) return;
        if ( ! isset( $form->id ) || (int) $form->id !== $target_form_id ) return;

        $email = isset( $formData['email'] ) ? sanitize_email( $formData['email'] ) : '';
        if ( ! $email || ! is_email( $email ) ) return;
        if ( ! function_exists( 'WC' ) || ! WC()->customer ) return;

        WC()->customer->set_billing_email( $email );
        WC()->customer->save();

        if ( WC()->session ) {
            WC()->session->set( 'mad_cart_bounty_captured', 1 );
        }
    }

    /* ================================================================ */
    /*  ID del formulario: constante > filtro > ajuste                   */
    /* ================================================================ */

    private function get_form_id(): int {
        if ( defined( 'MAD_CART_BOUNTY_FORM_ID' ) && MAD_CART_BOUNTY_FORM_ID ) {
            return (int) MAD_CART_BOUNTY_FORM_ID;
        }

        $settings = $this->get_settings();
        $form_id  = (int) ( $settings['form_id'] ?? 0 );

        /**
         * Permite sobreescribir el ID del formulario de Fluent Forms sin
         * tocar el ajuste guardado (ej. distinto ID por entorno/idioma).
         */
        return (int) apply_filters( 'mad_cart_bounty_form_id', $form_id );
    }

    /* ================================================================ */
    /*  Settings page                                                    */
    /* ================================================================ */

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->title() ); ?></h1>

            <?php if ( ! $this->get_form_id() ) : ?>
                <div class="notice notice-warning"><p>
                    <?php esc_html_e( 'Falta configurar el ID del formulario de Fluent Forms — hasta entonces el popup no se muestra en el sitio.', 'mad-suite' ); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( $this->opt_key );
                do_settings_sections( $this->opt_key );
                submit_button( __( 'Guardar ajustes', 'mad-suite' ) );
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Formulario de Fluent Forms', 'mad-suite' ); ?></h2>
            <p>
                <?php esc_html_e( '¿No tenés todavía un formulario de solo-email? Importá este archivo desde Fluent Forms → Ajustes → Importar/Exportar → Importar formularios, y después cargá el ID que te asigne arriba:', 'mad-suite' ); ?>
            </p>
            <p><code><?php echo esc_html( plugin_dir_path( __FILE__ ) . 'fluent-forms-import.json' ); ?></code></p>
            <p class="description">
                <?php esc_html_e( 'El campo de email del formulario debe llamarse exactamente "email" — el puente PHP lee ese nombre de campo. Si la importación falla, creá el formulario a mano: un campo Email (required + validación) y un honeypot (el nativo de Fluent Forms Pro en Spam & Seguridad, si tu versión lo trae).', 'mad-suite' ); ?>
            </p>
        </div>
        <?php
    }

    /* ================================================================ */
    /*  Settings fields                                                  */
    /* ================================================================ */

    public function field_form_id(): void {
        $v = $this->get_settings()['form_id'];
        printf(
            '<input type="number" min="0" step="1" name="%s[form_id]" value="%s" style="width:100px;">',
            esc_attr( $this->opt_key ),
            esc_attr( $v )
        );
        echo '<p class="description">' . esc_html__( 'El ID numérico del formulario en Fluent Forms (se puede sobreescribir con la constante MAD_CART_BOUNTY_FORM_ID o el filtro mad_cart_bounty_form_id).', 'mad-suite' ) . '</p>';
    }

    public function field_title_text(): void {
        $v = $this->get_settings()['title_text'];
        printf(
            '<textarea name="%s[title_text]" rows="2" class="large-text">%s</textarea>',
            esc_attr( $this->opt_key ),
            esc_textarea( $v )
        );
    }

    public function field_consent_text(): void {
        $v = $this->get_settings()['consent_text'];
        printf(
            '<textarea name="%s[consent_text]" rows="2" class="large-text">%s</textarea>',
            esc_attr( $this->opt_key ),
            esc_textarea( $v )
        );
    }

    /* ================================================================ */
    /*  Helpers                                                          */
    /* ================================================================ */

    public function sanitize_settings( $input ): array {
        return [
            'form_id'      => absint( $input['form_id'] ?? 0 ),
            'title_text'   => sanitize_textarea_field( $input['title_text'] ?? '' ),
            'consent_text' => sanitize_textarea_field( $input['consent_text'] ?? '' ),
        ];
    }

    private function get_settings(): array {
        $defaults = [
            'form_id'      => 0,
            'title_text'   => __( 'Le guardamos su selección. Déjenos su correo y le enviamos el detalle.', 'mad-suite' ),
            'consent_text' => __( 'Al dejar su correo acepta recibir recordatorios sobre su selección.', 'mad-suite' ),
        ];
        $saved = get_option( $this->opt_key, [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
    }

};
