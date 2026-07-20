<?php
/**
 * Módulo: MAD Quotes
 *
 * Extensión del plugin "Quotes for WooCommerce".
 * Delega al plugin original el gateway de pago, los emails básicos y la gestión
 * del carrito; MAD añade:
 *  - Estados de pedido personalizados (Presupuesto pendiente / enviado).
 *  - Control de acceso por rol de usuario.
 *  - Ocultación de precios y totales en carrito y checkout.
 *  - Checkout simplificado (solo nombre, apellido y email).
 *  - Precio de presupuesto configurable por producto.
 *  - Email con tabla de precios y nota opcional del admin.
 *  - Edición de precios por línea desde el pedido y reenvío.
 *  - Gestión de estado del presupuesto y caducidad automática.
 *
 * @package MAD_Suite/Quotes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var MAD_Suite_Core $core Passed by the framework loader. */

define( 'MAD_QUOTES_TEMPLATE_PATH', plugin_dir_path( __FILE__ ) . 'templates/' );
define( 'MAD_QUOTES_DIR',           plugin_dir_path( __FILE__ ) );
define( 'MAD_QUOTES_URL',           plugin_dir_url( __FILE__ ) );

require_once MAD_QUOTES_DIR . 'includes/functions.php';

return new class( $core ) implements MAD_Suite_Module {

    private $core;
    private $slug   = 'quotes';
    private $active = false;

    /** @var array Cache de precios originales antes de que el plugin original los filtre. */
    private $price_cache = [];

    /** @var array Gateways disponibles ANTES de que el plugin original los filtre (capturados a prioridad 1). */
    private $original_gateways = [];

    /** @var bool Flag para evitar recursión infinita en enforce_quote_status(). */
    private static $enforcing_status = false;

    public function __construct( $core ) {
        $this->core = $core;
    }

    /* ================================================================ */
    /*  MAD_Suite_Module interface                                       */
    /* ================================================================ */

    public function slug()       { return $this->slug; }
    public function title()      { return __( 'Presupuestos para WooCommerce', 'mad-suite' ); }
    public function menu_label() { return __( 'Presupuestos', 'mad-suite' ); }
    public function menu_slug()  { return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug; }

    public function description() {
        return __( 'Extensión del plugin "Quotes for WooCommerce". Añade estados personalizados, control por rol, precios de presupuesto, email con precios/nota y edición/reenvío desde el pedido.', 'mad-suite' );
    }

    public function required_plugins() {
        return [ 'WooCommerce' => 'woocommerce/woocommerce.php' ];
    }

    /* ---------------------------------------------------------------- */
    /*  init()                                                           */
    /* ---------------------------------------------------------------- */

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-warning"><p>' .
                    esc_html__( 'El módulo "Presupuestos" requiere WooCommerce activo.', 'mad-suite' ) .
                '</p></div>';
            } );
            return;
        }

        // ── Dependencia: Quotes for WooCommerce ───────────────────────
        if ( ! $this->is_quotes_plugin_active() ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>' .
                    wp_kses(
                        __( 'MAD Suite – El módulo <strong>Presupuestos</strong> requiere el plugin <strong>Quotes for WooCommerce</strong> instalado y activo.', 'mad-suite' ),
                        [ 'strong' => [] ]
                    ) .
                '</p></div>';
            } );
            return;
        }

        $this->active = true;

        // ── Estados de pedido personalizados ──────────────────────────
        register_post_status( 'wc-quote-pending', [
            'label'                     => _x( 'Presupuesto pendiente', 'Order status', 'mad-suite' ),
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Presupuesto pendiente (%s)', 'Presupuestos pendientes (%s)', 'mad-suite' ),
        ] );
        register_post_status( 'wc-quote-sent', [
            'label'                     => _x( 'Presupuesto enviado', 'Order status', 'mad-suite' ),
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Presupuesto enviado (%s)', 'Presupuestos enviados (%s)', 'mad-suite' ),
        ] );
        add_filter( 'wc_order_statuses',                           [ $this, 'add_wc_order_statuses' ] );
        add_filter( 'woocommerce_valid_order_statuses_for_payment', [ $this, 'valid_payment_statuses' ] );
        add_filter( 'woocommerce_order_needs_payment',             [ $this, 'quote_sent_needs_payment' ], 10, 2 );
        add_action( 'admin_head', [ $this, 'order_status_css' ] );

        // ── Rol: cachear precio HTML antes de que el plugin original lo modifique ──
        add_filter( 'woocommerce_get_price_html',      [ $this, 'cache_original_price' ], 1, 2 );
        add_filter( 'woocommerce_variable_price_html', [ $this, 'cache_original_price' ], 1, 2 );

        // ── Rol: ocultar precio para usuarios de presupuesto independientemente de la config por producto ──
        add_filter( 'woocommerce_get_price_html',      [ $this, 'hide_price_for_quote_role' ], 50, 2 );
        add_filter( 'woocommerce_variable_price_html', [ $this, 'hide_price_for_quote_role' ], 50, 2 );

        // ── Rol: restaurar precio/botón para roles no habilitados (prioridad 999 para sobreescribir al plugin original) ──
        add_filter( 'woocommerce_get_price_html',                  [ $this, 'maybe_restore_price' ],  999, 2 );
        add_filter( 'woocommerce_variable_price_html',             [ $this, 'maybe_restore_price' ],  999, 2 );
        add_filter( 'woocommerce_product_add_to_cart_text',        [ $this, 'maybe_restore_button' ], 999 );
        add_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'maybe_restore_button' ], 999 );

        // ── WPML: registrar strings traducibles en cada carga ────────────────
        add_action( 'init', [ $this, 'register_wpml_strings' ], 20 );

        // ── Shortcode del formulario de presupuesto ───────────────────────
        add_shortcode( 'mad_quote_cart', [ $this, 'shortcode_quote_cart' ] );

        // ── Logging de fallos de entrega de email ─────────────────────────
        add_action( 'wp_mail_failed', [ $this, 'log_mail_failure' ] );

        // ── Rol: filtro de ocultación de precio de QWC (hook real del plugin original) ──
        add_filter( 'qwc_hide_prices', [ $this, 'filter_by_role' ], 10, 2 );

        // ── Gateways: capturar los originales antes de que el plugin de presupuestos los filtre ──
        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'capture_original_gateways' ], 1 );
        // Asegurar que quotes-gateway esté disponible para usuarios de presupuesto aunque QWC no lo inyecte ──
        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'inject_quotes_gateway_for_role' ], 5 );
        // Para usuarios con rol de presupuesto: solo quotes-gateway.
        // Para el resto (profesionales / order-pay): restaurar gateways originales y quitar quotes-gateway.
        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_quote_gateway' ], 999 );

        // ── Checkout presupuesto: cambiar texto del botón "Realizar pedido" ──────
        add_filter( 'woocommerce_order_button_text', [ $this, 'quote_checkout_button_text' ] );

        // ── Carrito: formulario de presupuesto propio (NO Blocks checkout) ──────────
        // Prioridad 1: interceptar el POST del formulario antes de que se cargue el template.
        add_action( 'template_redirect', [ $this, 'maybe_handle_create_quote' ],      1 );
        // Prioridad 10: si es la página de carrito de un usuario de presupuesto, cargar nuestro template.
        add_action( 'template_redirect', [ $this, 'serve_quote_cart_template' ] );
        // Prioridad 10: si un usuario de presupuesto llega al checkout de WC (no order-pay), redirigir al carrito.
        add_action( 'template_redirect', [ $this, 'redirect_quote_checkout_to_cart' ] );

        // Datos de facturación en la página order-pay de presupuestos enviados.
        add_action( 'woocommerce_pay_order_before_payment', [ $this, 'inject_billing_fields_on_pay_page' ] );
        add_action( 'woocommerce_before_pay_action',        [ $this, 'save_billing_fields_before_pay'   ], 1, 1 );

        // ── Mini-carrito: ocultar botones y subtotal para usuarios de presupuesto ─
        // El plugin QWC base solo lo hace por producto (cart_contains_quotable()),
        // no por rol — aquí lo cubrimos con la lógica de rol de MAD Quotes.
        add_action( 'woocommerce_widget_shopping_cart_buttons', [ $this, 'hide_mini_cart_buttons' ],    1 );
        add_action( 'woocommerce_widget_shopping_cart_total',   [ $this, 'hide_mini_cart_total' ],      1 );

        // ── Mini-carrito: textos personalizables para usuarios normales ──
        add_action( 'woocommerce_widget_shopping_cart_buttons', [ $this, 'override_mini_cart_buttons' ], 5 );

        // ── Checkout: ocultar precios y pagos para experiencia de presupuesto ─
        // PHP hooks: actúan en el origen, sin depender de selectores CSS del tema
        add_filter( 'woocommerce_cart_item_price',    [ $this, 'hide_cart_item_price' ],    10, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'hide_cart_item_subtotal' ], 10, 3 );
        add_filter( 'woocommerce_checkout_show_payment', [ $this, 'hide_checkout_payment' ] );
        // Ocultar los totales del footer (subtotal, total) vía PHP para el checkout clásico
        add_filter( 'woocommerce_cart_subtotal',                [ $this, 'hide_cart_totals_html' ], 10, 3 );
        add_filter( 'woocommerce_cart_totals_order_total_html', [ $this, 'hide_cart_totals_html_single' ] );
        // CSS temprano (wp_head): evita flash si el carrito ya está disponible
        add_action( 'wp_head', [ $this, 'inject_checkout_css' ] );
        // CSS inline antes de la tabla de revisión: timing garantizado dentro del template
        add_action( 'woocommerce_checkout_before_order_review_heading', [ $this, 'inject_checkout_css' ] );
        // CSS de seguridad DESPUÉS de la tabla: dispara con el carrito definitivamente renderizado;
        // usa current_user_is_quote_role() sin depender del estado del carrito.
        add_action( 'woocommerce_checkout_after_order_review', [ $this, 'inject_checkout_css_after_review' ] );

        // ── Checkout: simplificar campos y deshabilitar envío ─────────
        // PHP_INT_MAX garantiza que nuestro filtro sea el último en ejecutarse
        add_filter( 'woocommerce_checkout_fields',     [ $this, 'simplify_quote_checkout_fields' ], PHP_INT_MAX );
        add_filter( 'woocommerce_cart_needs_shipping', [ $this, 'no_shipping_for_quotes' ] );

        // ── Blocks checkout: eliminar bloque de dirección del DOM ────────
        // CSS no es suficiente: React valida client-side los campos ocultos.
        // render_block elimina el bloque por completo del HTML renderizado
        // → sin DOM, sin validación React, sin campos requeridos visibles.
        add_filter( 'render_block', [ $this, 'remove_billing_block_for_quote_role' ], 10, 2 );

        // ── Blocks checkout: quitar "required" de campos de dirección ─
        // woocommerce_checkout_fields no afecta a Blocks; hay que actuar sobre
        // woocommerce_billing_fields para que la validación Store API no bloquee el envío.
        add_filter( 'woocommerce_billing_fields', [ $this, 'unrequire_billing_address_for_quotes' ], PHP_INT_MAX );

        // ── Blocks checkout: rellenar datos de facturación mínimos ───────
        // La Store API valida el pedido incluso con woocommerce_billing_fields.
        // Rellenamos placeholders para campos que WC pueda exigir en la BD.
        add_action( 'woocommerce_store_api_checkout_update_order_from_request',              [ $this, 'fill_quote_billing_defaults' ], PHP_INT_MAX, 2 );
        add_action( '__experimental_woocommerce_blocks_checkout_update_order_from_request',  [ $this, 'fill_quote_billing_defaults' ], PHP_INT_MAX, 2 );

        // ── Ciclo de vida del pedido ───────────────────────────────────
        add_action( 'woocommerce_checkout_update_order_meta',   [ $this, 'save_quote_order_meta' ] );
        // Forzar estado "Presupuesto pendiente" DESPUÉS de que el gateway llame a process_payment()
        add_action( 'woocommerce_checkout_order_processed',     [ $this, 'finalize_quote_order_status' ], 999, 1 );
        // Interceptar cualquier cambio de estado no permitido para pedidos de presupuesto
        add_action( 'woocommerce_order_status_changed',         [ $this, 'enforce_quote_status' ], 999, 3 );
        // Red de seguridad: corregir estado en la página de confirmación (after payment processing)
        add_action( 'woocommerce_thankyou',                     [ $this, 'enforce_quote_status_thankyou' ], 999 );
        add_filter( 'woocommerce_can_reduce_order_stock',       [ $this, 'prevent_stock_reduction' ], 10, 2 );
        add_filter( 'woocommerce_cancel_unpaid_order',          [ $this, 'prevent_cancel' ], 10, 2 );
        add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'my_orders_actions' ], 10, 2 );

        // Ocultar precios al cliente en pedidos de presupuesto pendiente de confirmación.
        add_action( 'woocommerce_thankyou',                 [ $this, 'hide_prices_on_pending_quote_page' ] );
        add_action( 'woocommerce_view_order',               [ $this, 'hide_prices_on_pending_quote_page' ] );
        add_filter( 'woocommerce_get_order_item_totals',    [ $this, 'hide_totals_on_pending_quote' ], 10, 2 );

        // ── UI de admin (botones + tabla de precios en el pedido) ──────
        add_action( 'woocommerce_order_item_add_action_buttons', [ $this, 'add_order_buttons' ] );
        add_action( 'admin_enqueue_scripts',                     [ $this, 'enqueue_admin_js' ] );

        // ── Email exclusivo MAD: presupuesto con precios y nota ────────
        add_filter( 'woocommerce_email_classes', [ $this, 'register_emails' ] );

        // ── Cron de expiración de presupuestos ────────────────────────
        if ( ! wp_next_scheduled( 'mad_quotes_check_expiry' ) ) {
            wp_schedule_event( time(), 'daily', 'mad_quotes_check_expiry' );
        }
        add_action( 'mad_quotes_check_expiry', [ $this, 'expire_old_quotes' ] );
    }

    /* ---------------------------------------------------------------- */
    /*  admin_init()                                                     */
    /* ---------------------------------------------------------------- */

    public function admin_init() {
        if ( ! $this->active ) return;

        $option_key = MAD_Suite_Core::option_key( $this->slug );

        register_setting( $this->menu_slug(), $option_key, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default'           => [],
        ] );

        // ── Sección: Acceso por rol ────────────────────────────────────
        add_settings_section(
            'mad_quotes_roles',
            __( 'Acceso por rol', 'mad-suite' ),
            function () {
                echo '<p>' . esc_html__( 'Selecciona qué roles ven la experiencia de presupuesto (precios ocultos, botón "Solicitar presupuesto"). El resto verá la tienda normal.', 'mad-suite' ) . '</p>';
            },
            $this->menu_slug()
        );
        $this->register_field(
            'quote_roles',
            __( 'Roles habilitados', 'mad-suite' ),
            'field_roles_multiselect',
            'mad_quotes_roles',
            __( 'Si no marcas ninguno, todos los usuarios verán la experiencia de presupuesto.', 'mad-suite' )
        );
        $this->register_field(
            'quote_button_text',
            __( 'Texto del botón de solicitud', 'mad-suite' ),
            'field_button_text',
            'mad_quotes_roles',
            __( 'Texto del botón en páginas de producto y carrito. Ej: "Solicitar presupuesto". Deja en blanco para usar el texto por defecto del plugin.', 'mad-suite' )
        );
        $this->register_field(
            'quote_cart_page_id',
            __( 'Página de solicitud de presupuesto', 'mad-suite' ),
            'field_page_select',
            'mad_quotes_roles',
            __( 'Página que contiene el shortcode [mad_quote_cart]. Los usuarios de presupuesto serán redirigidos aquí en lugar del carrito de WooCommerce.', 'mad-suite' )
        );

        // ── Sección: Textos del mini-carrito ─────────────────────────
        add_settings_section(
            'mad_quotes_mini_cart',
            __( 'Textos del mini-carrito', 'mad-suite' ),
            function () {
                echo '<p>' . esc_html__( 'Personaliza los botones del mini-carrito flotante. Si WPML está activo puedes definir el texto en cada idioma.', 'mad-suite' ) . '</p>';
            },
            $this->menu_slug()
        );
        $this->register_field(
            'mini_cart_view_cart_text',
            __( 'Botón "Ver carrito"', 'mad-suite' ),
            'field_multilang_text',
            'mad_quotes_mini_cart',
            __( 'Texto del botón que lleva al carrito. Por defecto: "Ver carrito".', 'mad-suite' )
        );
        $this->register_field(
            'mini_cart_checkout_text',
            __( 'Botón "Finalizar compra"', 'mad-suite' ),
            'field_multilang_text',
            'mad_quotes_mini_cart',
            __( 'Texto del botón que lleva al checkout. Por defecto: "Finalizar compra".', 'mad-suite' )
        );

        // ── Sección: Caducidad ─────────────────────────────────────────
        add_settings_section(
            'mad_quotes_expiry',
            __( 'Caducidad de presupuestos', 'mad-suite' ),
            function () {
                echo '<p>' . esc_html__( 'Caducan automáticamente los presupuestos pendientes después de N días. Escribe 0 para desactivar.', 'mad-suite' ) . '</p>';
            },
            $this->menu_slug()
        );
        $this->register_field( 'quote_expiry_days', __( 'Días hasta caducidad', 'mad-suite' ), 'field_number', 'mad_quotes_expiry' );

        // ── AJAX ───────────────────────────────────────────────────────
        add_action( 'wp_ajax_mad_quotes_update_status', [ $this, 'ajax_update_status' ] );
        add_action( 'wp_ajax_mad_quotes_send_quote',    [ $this, 'ajax_send_quote' ] );

        // ── Panel de producto ──────────────────────────────────────────
        add_action( 'woocommerce_product_data_tabs',    [ $this, 'product_data_tab' ] );
        add_action( 'woocommerce_product_data_panels',  [ $this, 'product_data_panel' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_meta' ] );
    }

    /* ---------------------------------------------------------------- */
    /*  render_settings_page()                                           */
    /* ---------------------------------------------------------------- */

    public function render_settings_page() {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'mad-suite' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->title() ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->menu_slug() );
                do_settings_sections( $this->menu_slug() );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* ================================================================ */
    /*  Estados de pedido personalizados                                 */
    /* ================================================================ */

    public function add_wc_order_statuses( $statuses ) {
        $statuses['wc-quote-pending'] = __( 'Presupuesto pendiente', 'mad-suite' );
        $statuses['wc-quote-sent']    = __( 'Presupuesto enviado',   'mad-suite' );
        return $statuses;
    }

    public function valid_payment_statuses( $statuses ) {
        $statuses[] = 'quote-sent';
        return $statuses;
    }

    /**
     * WooCommerce moderno usa needs_payment() que requiere status válido Y total > 0.
     * Para presupuestos en quote-sent el total lo fija el admin, y queremos que el
     * pago sea posible aunque el total fuera 0 (ej. muestra / regalo).
     */
    public function quote_sent_needs_payment( bool $needs_payment, WC_Order $order ): bool {
        if ( $needs_payment ) return true;
        if ( $order->get_status() !== 'quote-sent' ) return $needs_payment;
        if ( '1' !== $order->get_meta( '_mad_qwc_quote' ) ) return $needs_payment;
        return true;
    }

    /**
     * Muestra campos de facturación en la página order-pay cuando el pedido
     * es un presupuesto enviado y aún no tiene dirección de facturación.
     */
    public function inject_billing_fields_on_pay_page(): void {
        $order_id = absint( get_query_var( 'order-pay' ) );
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order || '1' !== $order->get_meta( '_mad_qwc_quote' ) ) return;
        if ( ! in_array( $order->get_status(), [ 'quote-sent', 'quote-complete' ], true ) ) return;

        // Si ya tiene dirección completa, no mostrar el formulario.
        if ( $order->get_billing_first_name() && $order->get_billing_last_name() ) return;

        $default_country = wc_get_base_location()['country'] ?? 'ES';
        $nonce           = wp_create_nonce( 'mad_quote_billing_' . $order_id );

        include MAD_QUOTES_TEMPLATE_PATH . 'quote-billing-fields.php';
    }

    /**
     * Guarda los campos de facturación enviados en la página order-pay antes
     * de que WooCommerce procese el pago.
     *
     * @param WC_Order $order
     */
    public function save_billing_fields_before_pay( WC_Order $order ): void {
        if ( '1' !== $order->get_meta( '_mad_qwc_quote' ) ) return;
        if ( empty( $_POST['mad_billing_nonce'] ) ) return;

        $nonce = sanitize_text_field( wp_unslash( $_POST['mad_billing_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'mad_quote_billing_' . $order->get_id() ) ) return;

        $fields = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ];
        foreach ( $fields as $field ) {
            $value  = sanitize_text_field( wp_unslash( $_POST[ 'billing_' . $field ] ?? '' ) );
            $setter = 'set_billing_' . $field;
            if ( method_exists( $order, $setter ) ) {
                $order->$setter( $value );
            }
        }
        $order->save();
    }

    public function order_status_css() {
        echo '<style>
            .order-status.status-quote-pending { background: #c0392b !important; color: #fff !important; }
            .order-status.status-quote-sent    { background: #2980b9 !important; color: #fff !important; }
        </style>';
    }

    /* ================================================================ */
    /*  Email                                                             */
    /* ================================================================ */

    public function register_emails( $email_classes ) {
        require_once MAD_QUOTES_DIR . 'includes/emails/trait-mad-email-wpml.php';
        require_once MAD_QUOTES_DIR . 'includes/emails/class-mad-quotes-confirmation.php';
        require_once MAD_QUOTES_DIR . 'includes/emails/class-mad-quotes-new-request.php';
        require_once MAD_QUOTES_DIR . 'includes/emails/class-mad-quotes-send-quote.php';

        $email_classes['MAD_Quotes_Email_Confirmation'] = new MAD_Quotes_Email_Confirmation();
        $email_classes['MAD_Quotes_Email_New_Request']  = new MAD_Quotes_Email_New_Request();
        $email_classes['MAD_Quotes_Email_Send_Quote']   = new MAD_Quotes_Email_Send_Quote();

        return $email_classes;
    }

    /* ================================================================ */
    /*  Rol: control de acceso                                           */
    /* ================================================================ */

    private function current_user_is_quote_role(): bool {
        $settings    = mad_quotes_get_settings();
        $quote_roles = array_filter( (array) ( $settings['quote_roles'] ?? [] ) );

        if ( empty( $quote_roles ) ) {
            return true;
        }

        $user = wp_get_current_user();
        if ( ! $user->ID ) {
            return in_array( 'guest', $quote_roles, true );
        }

        return (bool) array_intersect( $quote_roles, (array) $user->roles );
    }

    public function cache_original_price( $price, $product ) {
        $this->price_cache[ $product->get_id() ] = $price;
        return $price;
    }

    public function maybe_restore_price( $price, $product ) {
        if ( ! $this->current_user_is_quote_role() ) {
            return $this->price_cache[ $product->get_id() ] ?? $price;
        }
        return $price;
    }

    public function register_wpml_strings(): void {
        // No-op: button text is now managed per-language directly in MAD Suite settings.
    }

    public function maybe_restore_button( $text ) {
        if ( ! $this->current_user_is_quote_role() ) {
            return __( 'Añadir al carrito', 'woocommerce' );
        }
        $settings    = mad_quotes_get_settings();
        $custom_text = trim( $this->resolve_button_text( $settings ) );
        return $custom_text !== '' ? $custom_text : $text;
    }

    /**
     * Returns the button text for the current WPML language.
     * Handles both the new array format and the legacy string format.
     */
    private function resolve_button_text( array $settings ): string {
        $val = $settings['quote_button_text'] ?? [];

        // Legacy: stored as plain string before multilang support
        if ( is_string( $val ) ) {
            return $val;
        }

        if ( empty( $val ) ) {
            return '';
        }

        $current_lang = apply_filters( 'wpml_current_language', null );
        if ( $current_lang && isset( $val[ $current_lang ] ) && $val[ $current_lang ] !== '' ) {
            return $val[ $current_lang ];
        }

        // Fall back to default WPML language
        $default_lang = apply_filters( 'wpml_default_language', null );
        if ( $default_lang && isset( $val[ $default_lang ] ) && $val[ $default_lang ] !== '' ) {
            return $val[ $default_lang ];
        }

        // Fall back to any non-empty entry
        foreach ( $val as $entry ) {
            if ( $entry !== '' ) return $entry;
        }

        return '';
    }

    public function filter_by_role( $value, $product_id = null ) {
        if ( ! $this->current_user_is_quote_role() ) {
            return false;
        }
        return $value;
    }

    /* ================================================================ */
    /*  Carrito: detección y ocultación de precios                       */
    /* ================================================================ */

    /**
     * True si el usuario tiene rol de presupuesto y tiene productos en el carrito.
     * No depende de la configuración por producto de QWC; cualquier producto en el carrito
     * de un usuario de presupuesto activa la experiencia de solicitud.
     */
    private function cart_is_quote_experience(): bool {
        if ( ! $this->current_user_is_quote_role() ) return false;
        if ( ! isset( WC()->cart ) || is_null( WC()->cart ) ) return false;
        return ! WC()->cart->is_empty();
    }

    /**
     * Intercepta el POST del formulario de solicitud de presupuesto.
     * Crea el pedido directamente (sin pasar por el checkout de WooCommerce Blocks),
     * vacía el carrito y redirige a la página de confirmación del pedido.
     * Debe correr con prioridad 1 en template_redirect para que el POST se procese
     * antes de que serve_quote_cart_template() cargue el template.
     */
    public function maybe_handle_create_quote(): void {
        if ( empty( $_POST['mad_create_quote_nonce'] ) ) return;

        $back_url = $this->get_quote_cart_url();

        if ( ! $this->current_user_is_quote_role() ) {
            wp_safe_redirect( $back_url );
            exit;
        }

        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['mad_create_quote_nonce'] ) ),
            'mad_create_quote'
        ) ) {
            wp_safe_redirect( $back_url );
            exit;
        }

        if ( ! isset( WC()->cart ) || WC()->cart->is_empty() ) {
            wp_safe_redirect( $back_url );
            exit;
        }

        $email = sanitize_email( wp_unslash( $_POST['olofane_email'] ?? '' ) );
        $notas = sanitize_textarea_field( wp_unslash( $_POST['olofane_notas'] ?? '' ) );

        if ( ! is_email( $email ) ) {
            wc_add_notice( __( 'Por favor, introduce un email válido.', 'mad-suite' ), 'error' );
            wp_safe_redirect( $back_url );
            exit;
        }

        $user  = wp_get_current_user();
        $order = wc_create_order( [ 'customer_id' => $user->ID ] );

        foreach ( WC()->cart->get_cart() as $item ) {
            $order->add_product( $item['data'], $item['quantity'] );
        }

        // Pre-poblar con precio regular de cada producto como punto de partida.
        // El admin puede modificarlos en el meta box antes de enviar el presupuesto.
        $order_total = 0.0;
        foreach ( $order->get_items() as $line ) {
            $quote_price = mad_quotes_get_product_quote_price( $line->get_product_id() );
            $qty         = $line->get_quantity();
            $line_total  = $quote_price * $qty;
            $line->update_meta_data( '_mad_quote_line_price', (string) $quote_price );
            $line->set_subtotal( $line_total );
            $line->set_total( $line_total );
            $line->save();
            $order_total += $line_total;
        }

        $order->set_billing_email( $email );
        $order->set_billing_first_name( $user->first_name ?: $user->display_name );
        $order->set_billing_last_name( $user->last_name ?: '' );
        $order->set_payment_method( 'quotes-gateway' );
        $order->set_cart_tax( 0 );
        $order->set_shipping_total( 0 );
        $order->set_shipping_tax( 0 );
        $order->set_total( $order_total );
        $order->update_meta_data( '_mad_qwc_quote', '1' );
        $order->update_meta_data( '_mad_quote_status', 'quote-pending' );
        $lang = apply_filters( 'wpml_current_language', null );
        if ( $lang ) {
            $order->update_meta_data( '_mad_quote_lang', sanitize_key( $lang ) );
        }

        if ( $notas ) {
            $order->add_order_note( esc_html( $notas ), true );
        }

        $order->update_status( 'quote-pending', __( 'Solicitud de presupuesto recibida.', 'mad-suite' ) );
        $order->save();

        WC()->cart->empty_cart();

        // Emails: confirmación al cliente + aviso al admin.
        if ( ! $order->get_meta( '_mad_quote_emails_sent' ) ) {
            $order->update_meta_data( '_mad_quote_emails_sent', '1' );
            $order->save();
            WC_Emails::instance();
            wc_get_logger()->info(
                sprintf( 'mad_quotes_new_request disparado — pedido #%d → destinatario: %s', $order->get_id(), $email ),
                [ 'source' => 'mad-quotes-email' ]
            );
            do_action( 'mad_quotes_new_request', $order->get_id() );
        }

        wp_safe_redirect( $order->get_checkout_order_received_url() );
        exit;
    }

    /**
     * Carga el template de carrito de presupuesto (quote-cart.php) para usuarios con rol
     * de presupuesto cuando visitan la página del carrito. El template incluye el formulario
     * con email + notas que crea el pedido sin pasar por el checkout de Blocks.
     * Los profesionales ven el carrito normal de WooCommerce sin cambios.
     */
    public function serve_quote_cart_template(): void {
        if ( ! is_cart() ) return;
        if ( ! $this->current_user_is_quote_role() ) return;
        if ( ! isset( WC()->cart ) || is_null( WC()->cart ) ) return;

        // Si hay una página configurada con el shortcode, redirigir allí.
        $settings = mad_quotes_get_settings();
        $page_id  = absint( $settings['quote_cart_page_id'] ?? 0 );
        if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
            wp_safe_redirect( (string) get_permalink( $page_id ) );
            exit;
        }

        // Fallback: cargar la plantilla standalone (solo si el carrito no está vacío).
        if ( WC()->cart->is_empty() ) return;
        $template = MAD_QUOTES_TEMPLATE_PATH . 'quote-cart.php';
        if ( file_exists( $template ) ) {
            include $template;
            exit;
        }
    }

    /**
     * Redirige al formulario de presupuesto a los usuarios de presupuesto que lleguen
     * al checkout de WooCommerce por cualquier vía. El flujo order-pay queda intacto.
     */
    public function redirect_quote_checkout_to_cart(): void {
        if ( ! is_checkout() ) return;
        if ( is_order_received_page() ) return;
        if ( get_query_var( 'order-pay' ) ) return;
        if ( ! $this->current_user_is_quote_role() ) return;

        wp_safe_redirect( $this->get_quote_cart_url() );
        exit;
    }

    /** URL de la página de solicitud de presupuesto, o carrito de WC como fallback. */
    private function get_quote_cart_url(): string {
        $settings = mad_quotes_get_settings();
        $page_id  = absint( $settings['quote_cart_page_id'] ?? 0 );
        if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
            return (string) get_permalink( $page_id );
        }
        return wc_get_cart_url();
    }

    /**
     * Oculta el precio HTML para todos los usuarios con rol de presupuesto,
     * independientemente de la configuración por producto del plugin QWC.
     * Se ejecuta en prioridad 50: después de que QWC oculta precios (10)
     * y antes de que maybe_restore_price los restaure para profesionales (999).
     */
    public function hide_price_for_quote_role( $price, $product ) {
        if ( $this->current_user_is_quote_role() ) {
            return '';
        }
        return $price;
    }

    /**
     * Inyecta quotes-gateway en los gateways disponibles para usuarios de presupuesto
     * antes de que QWC pueda haberlo eliminado (prioridad 5).
     * Garantiza que el gateway esté disponible aunque el carrito no tenga productos
     * marcados individualmente como "quote" en la configuración de QWC.
     */
    public function inject_quotes_gateway_for_role( $gateways ) {
        if ( ! $this->current_user_is_quote_role() ) return $gateways;
        if ( isset( $gateways['quotes-gateway'] ) ) return $gateways;

        $all = WC()->payment_gateways()->payment_gateways();
        if ( isset( $all['quotes-gateway'] ) ) {
            $gateways['quotes-gateway'] = $all['quotes-gateway'];
        }
        return $gateways;
    }

    /** @var bool Evita doble inyección de CSS si wp_head y el hook de revisión coinciden. */
    private $checkout_css_injected = false;

    /**
     * Inyecta CSS en el checkout de presupuesto para ocultar precios y totales.
     * Se dispara tanto en wp_head (carga rápida) como en
     * woocommerce_checkout_before_order_review_heading (carrito garantizado disponible).
     * El flag $checkout_css_injected evita salida duplicada.
     */
    public function inject_checkout_css() {
        if ( ! is_checkout() || is_order_received_page() ) return;
        if ( $this->checkout_css_injected ) return;
        if ( ! $this->cart_is_quote_experience() ) return;

        $this->checkout_css_injected = true;
        $this->output_checkout_hide_css();
    }

    /** Elimina el precio unitario de los ítems en carrito/checkout para usuarios de presupuesto. */
    public function hide_cart_item_price( $price_html, $cart_item, $cart_item_key ) {
        if ( $this->cart_is_quote_experience() ) return '';
        return $price_html;
    }

    /** Elimina el subtotal de línea en carrito/checkout para usuarios de presupuesto. */
    public function hide_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {
        if ( $this->cart_is_quote_experience() ) return '';
        return $subtotal;
    }

    /**
     * Oculta vía PHP el subtotal del carrito en la tabla de revisión del checkout clásico.
     * Firma compatible con woocommerce_cart_subtotal ($subtotal, $compound, $cart).
     */
    public function hide_cart_totals_html( $subtotal, $compound = false, $cart = null ) {
        if ( $this->cart_is_quote_experience() ) return '';
        return $subtotal;
    }

    /**
     * Oculta vía PHP el total del pedido en la tabla de revisión del checkout clásico.
     * Firma compatible con woocommerce_cart_totals_order_total_html ($value).
     */
    public function hide_cart_totals_html_single( $value ) {
        if ( $this->cart_is_quote_experience() ) return '';
        return $value;
    }

    /** Elimina los botones "Ver carrito" y "Finalizar compra" del mini-carrito para usuarios de presupuesto. */
    public function hide_mini_cart_buttons(): void {
        if ( ! $this->current_user_is_quote_role() ) return;

        remove_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_button_view_cart', 10 );
        remove_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20 );

        // Usa los mismos campos configurables que los usuarios normales,
        // con fallbacks apropiados para la experiencia de presupuesto.
        $settings = mad_quotes_get_settings();

        $view_text = trim( $this->resolve_lang_text( $settings['mini_cart_view_cart_text'] ?? [] ) );
        if ( $view_text === '' ) {
            $view_text = __( 'Ver lista', 'mad-suite' );
        }

        $checkout_text = trim( $this->resolve_lang_text( $settings['mini_cart_checkout_text'] ?? [] ) );
        if ( $checkout_text === '' ) {
            $checkout_text = __( 'Solicitar presupuesto', 'mad-suite' );
        }

        $quote_url = $this->get_quote_cart_url();
        printf(
            '<a href="%s" class="button wc-forward">%s</a>',
            esc_url( $quote_url ),
            esc_html( $view_text )
        );
        printf(
            '<a href="%s" class="button checkout wc-forward">%s</a>',
            esc_url( $quote_url ),
            esc_html( $checkout_text )
        );
    }

    /** Elimina la línea de subtotal del mini-carrito para usuarios de presupuesto. */
    public function hide_mini_cart_total(): void {
        if ( ! $this->current_user_is_quote_role() ) return;
        remove_action( 'woocommerce_widget_shopping_cart_total', 'woocommerce_widget_shopping_cart_subtotal', 10 );
    }

    /** Reemplaza los botones estándar del mini-carrito con los textos configurados (usuarios normales). */
    public function override_mini_cart_buttons(): void {
        if ( $this->current_user_is_quote_role() ) return; // quote users handled by hide_mini_cart_buttons

        $settings       = mad_quotes_get_settings();
        $view_cart_text = trim( $this->resolve_lang_text( $settings['mini_cart_view_cart_text'] ?? [] ) );
        $checkout_text  = trim( $this->resolve_lang_text( $settings['mini_cart_checkout_text'] ?? [] ) );

        if ( $view_cart_text === '' && $checkout_text === '' ) return; // nothing to override

        remove_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_button_view_cart', 10 );
        remove_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20 );

        if ( $view_cart_text === '' ) {
            $view_cart_text = __( 'Ver carrito', 'woocommerce' );
        }
        if ( $checkout_text === '' ) {
            $checkout_text = __( 'Finalizar compra', 'woocommerce' );
        }

        printf(
            '<a href="%s" class="button wc-forward">%s</a>',
            esc_url( wc_get_cart_url() ),
            esc_html( $view_cart_text )
        );
        printf(
            '<a href="%s" class="button checkout wc-forward">%s</a>',
            esc_url( wc_get_checkout_url() ),
            esc_html( $checkout_text )
        );
    }

    /** Resolves a per-language text array to the current WPML language string. */
    private function resolve_lang_text( $val ): string {
        if ( is_string( $val ) ) return $val;
        if ( empty( $val ) ) return '';

        $current_lang = apply_filters( 'wpml_current_language', null );
        if ( $current_lang && isset( $val[ $current_lang ] ) && $val[ $current_lang ] !== '' ) {
            return $val[ $current_lang ];
        }
        $default_lang = apply_filters( 'wpml_default_language', null );
        if ( $default_lang && isset( $val[ $default_lang ] ) && $val[ $default_lang ] !== '' ) {
            return $val[ $default_lang ];
        }
        foreach ( $val as $entry ) {
            if ( $entry !== '' ) return $entry;
        }
        return '';
    }

    /** Elimina el bloque de métodos de pago del checkout para usuarios de presupuesto. */
    public function hide_checkout_payment( $show ) {
        if ( $this->cart_is_quote_experience() ) return false;
        return $show;
    }

    /**
     * Inyección de CSS de seguridad DESPUÉS de la tabla de revisión del pedido.
     * Dispara con el carrito definitivamente renderizado; no usa cart_is_quote_experience()
     * para evitar problemas de timing. Solo comprueba el rol y excluye order-pay.
     */
    public function inject_checkout_css_after_review() {
        if ( is_order_received_page() ) return;
        if ( get_query_var( 'order-pay' ) ) return;
        if ( ! $this->current_user_is_quote_role() ) return;
        if ( $this->checkout_css_injected ) return;

        $this->checkout_css_injected = true;
        $this->output_checkout_hide_css();
    }

    /** Emite el bloque <style> que oculta precios en el checkout. Reutilizado por ambos métodos. */
    private function output_checkout_hide_css() {
        echo '<style>
            /* Checkout clásico: columna "Total/Subtotal" en cabecera, cuerpo y pie */
            .woocommerce-checkout-review-order-table .product-total,
            .woocommerce-checkout-review-order-table tfoot,
            .woocommerce-checkout-review-order-table tfoot tr,
            .woocommerce-checkout-review-order-table .cart-subtotal,
            .woocommerce-checkout-review-order-table .order-total { display: none !important; }
            /* Checkout en bloques (WooCommerce Blocks): precios */
            .wc-block-components-order-summary-item__individual-prices,
            .wc-block-components-order-summary-item__total-price,
            .wc-block-components-totals-item,
            .wc-block-components-totals-footer-item,
            .wc-block-order-summary-item__price { display: none !important; }
            /* Checkout Blocks: ocultar sección completa de dirección de facturación */
            .wc-block-checkout__billing-fields,
            .wc-block-checkout__shipping-fields { display: none !important; }
        </style>';
    }

    /* ================================================================ */
    /*  Checkout: simplificar campos                                     */
    /* ================================================================ */

    /**
     * Simplifica el checkout a nombre, apellido, email y notas.
     * Usa current_user_is_quote_role() (sin verificar carrito) para mayor robustez:
     * evita falsos negativos de cart_is_quote_experience() por timing en AJAX o sesión.
     * - Excluye la página order-pay: ahí el cliente completa todos sus datos antes de pagar.
     * - Excluye la página de confirmación (order-received).
     */
    public function simplify_quote_checkout_fields( $fields ) {
        if ( is_order_received_page() )       return $fields;
        if ( get_query_var( 'order-pay' ) )   return $fields;
        if ( ! $this->current_user_is_quote_role() ) return $fields;

        // Solo email — sin dirección de facturación ni nombre para la solicitud de presupuesto
        foreach ( array_keys( $fields['billing'] ?? [] ) as $key ) {
            if ( $key !== 'billing_email' ) {
                unset( $fields['billing'][ $key ] );
            }
        }

        $fields['shipping'] = [];

        // Mantener el campo de notas: el cliente puede explicar su solicitud
        if ( isset( $fields['order'] ) ) {
            foreach ( array_keys( $fields['order'] ) as $key ) {
                if ( $key !== 'order_comments' ) {
                    unset( $fields['order'][ $key ] );
                }
            }
        }

        return $fields;
    }

    /**
     * Removes the `required` flag from all billing fields except email for quote-role users.
     * Necessary for WooCommerce Blocks checkout: `woocommerce_checkout_fields` is ignored by
     * Blocks, but the REST API validation honours `required` set here.
     */
    public function unrequire_billing_address_for_quotes( array $fields ): array {
        if ( is_order_received_page() )              return $fields;
        if ( get_query_var( 'order-pay' ) )          return $fields;
        if ( ! $this->current_user_is_quote_role() ) return $fields;

        foreach ( $fields as $key => &$field ) {
            if ( $key !== 'billing_email' ) {
                $field['required'] = false;
            }
        }
        unset( $field );

        return $fields;
    }

    /**
     * Removes the billing and shipping address blocks entirely from the rendered
     * WooCommerce Blocks checkout for quote-role users.
     * CSS alone is not enough: React validates client-side any field present in the DOM.
     * With the block removed there is no DOM element → no React validation → no errors.
     */
    public function remove_billing_block_for_quote_role( string $content, array $block ): string {
        if ( ! is_checkout() || is_order_received_page() || get_query_var( 'order-pay' ) ) {
            return $content;
        }
        if ( ! $this->current_user_is_quote_role() ) {
            return $content;
        }
        $hidden = [
            'woocommerce/checkout-billing-address-block',
            'woocommerce/checkout-shipping-address-block',
        ];
        return in_array( $block['blockName'], $hidden, true ) ? '' : $content;
    }

    /**
     * Fills placeholder billing data for quote-role users after the Store API updates
     * the order from the request. This runs before server-side validation, ensuring
     * WooCommerce does not reject the order for missing address fields.
     *
     * @param \WC_Order                $order
     * @param \WP_REST_Request         $request
     */
    public function fill_quote_billing_defaults( $order, $request ): void {
        if ( ! $this->current_user_is_quote_role() ) return;

        if ( ! $order->get_billing_first_name() ) {
            $order->set_billing_first_name( 'Presupuesto' );
        }
        if ( ! $order->get_billing_last_name() ) {
            $order->set_billing_last_name( '-' );
        }
        if ( ! $order->get_billing_address_1() ) {
            $order->set_billing_address_1( '-' );
        }
        if ( ! $order->get_billing_city() ) {
            $order->set_billing_city( '-' );
        }
        if ( ! $order->get_billing_postcode() ) {
            $order->set_billing_postcode( '00000' );
        }
    }

    public function no_shipping_for_quotes( $needs_shipping ) {
        if ( get_query_var( 'order-pay' ) )   return $needs_shipping;
        if ( ! $this->current_user_is_quote_role() ) return $needs_shipping;
        return false;
    }

    /* ================================================================ */
    /*  Ciclo de vida del pedido                                         */
    /* ================================================================ */

    /**
     * Fuerza el estado "Presupuesto pendiente" después de que el gateway haya procesado el pago.
     * Se ejecuta con prioridad 999 en woocommerce_checkout_order_processed para sobreescribir
     * cualquier cambio de estado que el gateway quotes-gateway haga en process_payment().
     */
    public function finalize_quote_order_status( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( $order->get_payment_method() !== 'quotes-gateway'
            && '1' !== $order->get_meta( '_mad_qwc_quote' )
        ) {
            return;
        }

        $already_pending = $order->get_status() === 'quote-pending';

        if ( ! $already_pending ) {
            $order->update_status( 'quote-pending', __( 'Solicitud de presupuesto recibida.', 'mad-suite' ) );
            $order->save();
        }

        // Fire confirmation + admin notification only once (guard via order meta)
        if ( ! $order->get_meta( '_mad_quote_emails_sent' ) ) {
            $order->update_meta_data( '_mad_quote_emails_sent', '1' );
            $order->save();
            WC_Emails::instance();
            do_action( 'mad_quotes_new_request', $order_id );
        }
    }

    /**
     * Impide que WooCommerce reduzca el stock para pedidos de presupuesto.
     */
    public function prevent_stock_reduction( $can_reduce, $order ) {
        // Comprobación primaria: payment method disponible sin depender del cache de metas.
        if ( $order->get_payment_method() === 'quotes-gateway' ) {
            return false;
        }
        // Comprobación secundaria: estado o meta explícita (pedidos ya procesados).
        if ( in_array( $order->get_status(), [ 'quote-pending', 'quote-sent' ], true )
            || '1' === $order->get_meta( '_mad_qwc_quote' )
        ) {
            return false;
        }
        return $can_reduce;
    }

    /**
     * Captura los gateways disponibles ANTES de que el plugin original los filtre (prioridad 1).
     * Necesario para poder restaurarlos a los usuarios sin rol de presupuesto.
     */
    public function capture_original_gateways( $gateways ) {
        $this->original_gateways = $gateways;
        return $gateways;
    }

    /**
     * Controla qué gateways de pago se muestran según el contexto.
     *
     * Contextos detectados:
     * – Página order-pay: get_query_var('order-pay') tiene el ID del pedido.
     * – AJAX de pago desde order-pay: wp_doing_ajax() true y carrito vacío
     *   (el order-pay no añade ítems al carrito de sesión).
     * – Checkout normal con rol de presupuesto: resto de casos.
     *
     * Nota clave: QWC elimina 'quotes-gateway' de $gateways en prioridad 10
     * cuando cart_contains_quotable() es false (productos sin qwc_enable_quotes='on').
     * Por eso recuperamos el gateway directamente de payment_gateways() en lugar
     * de confiar en que siga presente en $gateways al llegar a prioridad 999.
     */
    public function filter_quote_gateway( $gateways ) {
        $is_order_pay = (bool) get_query_var( 'order-pay' )
            || ( wp_doing_ajax() && isset( WC()->cart ) && WC()->cart->is_empty() );

        if ( $is_order_pay ) {
            $restored = ! empty( $this->original_gateways ) ? $this->original_gateways : $gateways;
            unset( $restored['quotes-gateway'] );
            return $restored;
        }

        if ( $this->current_user_is_quote_role() ) {
            // QWC puede haber eliminado quotes-gateway de $gateways (prioridad 10).
            // Lo buscamos en todos los gateways registrados para garantizar que esté disponible.
            if ( isset( $gateways['quotes-gateway'] ) ) {
                return [ 'quotes-gateway' => $gateways['quotes-gateway'] ];
            }
            $all = WC()->payment_gateways()->payment_gateways();
            if ( isset( $all['quotes-gateway'] ) ) {
                return [ 'quotes-gateway' => $all['quotes-gateway'] ];
            }
            return $gateways; // Fallback: quotes-gateway no registrado en absoluto
        }

        // Profesionales: gateways reales sin quotes-gateway
        $restored = ! empty( $this->original_gateways ) ? $this->original_gateways : $gateways;
        unset( $restored['quotes-gateway'] );
        return $restored;
    }

    /**
     * Intercepta cambios de estado no permitidos en pedidos de presupuesto.
     * Fires cuando el gateway cambia el estado a 'pending' durante process_payment().
     */
    public function enforce_quote_status( $order_id, $from_status, $to_status ) {
        if ( self::$enforcing_status ) return;

        // Siempre permitidos.
        $always_allowed = [ 'quote-pending', 'quote-sent', 'cancelled', 'completed', 'refunded', 'failed', 'trash' ];
        if ( in_array( $to_status, $always_allowed, true ) ) return;

        // on-hold / processing están permitidos solo si el cliente viene del flujo de pago
        // (transición desde quote-sent o quote-complete). En cualquier otro caso se bloquean.
        $payment_statuses = [ 'on-hold', 'processing' ];
        $payment_origins  = [ 'quote-sent', 'quote-complete' ];
        if ( in_array( $to_status, $payment_statuses, true ) && in_array( $from_status, $payment_origins, true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || '1' !== $order->get_meta( '_mad_qwc_quote' ) ) return;

        self::$enforcing_status = true;
        $order->update_status( 'quote-pending', __( 'Solicitud de presupuesto recibida.', 'mad-suite' ) );
        $order->save();
        self::$enforcing_status = false;
    }

    /**
     * Red de seguridad en la página de confirmación: solo fuerza quote-pending
     * si el pedido no ha pasado por el flujo de pago real (on-hold, processing…).
     */
    public function enforce_quote_status_thankyou( $order_id ) {
        if ( self::$enforcing_status ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order || '1' !== $order->get_meta( '_mad_qwc_quote' ) ) return;

        // Si el pedido ya está en un estado de pago legítimo, no tocarlo.
        $paid_statuses = [ 'on-hold', 'processing', 'completed', 'quote-pending', 'cancelled', 'refunded', 'failed' ];
        if ( in_array( $order->get_status(), $paid_statuses, true ) ) return;

        self::$enforcing_status = true;
        $order->update_status( 'quote-pending', __( 'Solicitud de presupuesto recibida.', 'mad-suite' ) );
        $order->save();
        self::$enforcing_status = false;
    }

    /**
     * Inyecta CSS en la página de confirmación / vista de pedido del cliente
     * para ocultar precios mientras el presupuesto aún no ha sido enviado.
     * El admin ve los precios correctamente desde el panel de administración.
     */
    public function hide_prices_on_pending_quote_page( int $order_id ): void {
        if ( is_admin() ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order || '1' !== $order->get_meta( '_mad_qwc_quote' ) ) return;
        if ( $order->get_status() !== 'quote-pending' ) return;

        echo '<style>
            /* Ocultar columna de precio en la tabla de ítems del pedido */
            .woocommerce-order .product-total,
            .woocommerce-table--order-details .product-total { display:none!important; }
            /* Ocultar el pie de totales (subtotal, total, método de pago) */
            .woocommerce-order .woocommerce-table--order-details tfoot,
            .woocommerce-order .woocommerce-order-overview__total { display:none!important; }
        </style>';
    }

    /**
     * Elimina las filas financieras (subtotal, total) del resumen de pedido
     * en las vistas del cliente para presupuestos pendientes de confirmación.
     *
     * @param  array    $totals  Filas de totales generadas por WooCommerce.
     * @param  WC_Order $order
     * @return array
     */
    public function hide_totals_on_pending_quote( array $totals, WC_Order $order ): array {
        if ( is_admin() ) return $totals;
        if ( '1' !== $order->get_meta( '_mad_qwc_quote' ) ) return $totals;
        if ( $order->get_status() !== 'quote-pending' ) return $totals;

        $financial_keys = [ 'cart_subtotal', 'order_total', 'cart_tax', 'shipping', 'shipping_tax', 'fee', 'discount' ];
        foreach ( $financial_keys as $key ) {
            unset( $totals[ $key ] );
        }
        return $totals;
    }

    /**
     * Cambia el texto del botón "Realizar pedido" en el checkout de presupuesto.
     */
    public function quote_checkout_button_text( $text ) {
        if ( ! $this->cart_is_quote_experience() ) return $text;
        return __( 'Solicitar presupuesto', 'mad-suite' );
    }

    public function prevent_cancel( $return, $order ) {
        $status = $order->get_status();
        if ( in_array( $status, [ 'quote-pending', 'quote-sent' ], true )
            || '1' === $order->get_meta( '_mad_qwc_quote' )
            || $order->get_payment_method() === 'quotes-gateway'
        ) {
            return false;
        }
        return $return;
    }

    public function save_quote_order_meta( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( $order->get_payment_method() !== 'quotes-gateway' ) return;

        // Marcar como pedido de presupuesto MAD
        $order->update_meta_data( '_mad_quote_status', 'quote-pending' );
        $order->update_meta_data( '_mad_qwc_quote', '1' );

        // Poner a 0 todos los importes: se revelarán cuando el admin envíe el presupuesto
        foreach ( $order->get_items() as $item ) {
            $item->set_subtotal( 0 );
            $item->set_total( 0 );
            $item->save();
        }
        $order->set_cart_tax( 0 );
        $order->set_shipping_total( 0 );
        $order->set_shipping_tax( 0 );
        $order->set_total( 0 );

        // Cambiar a estado personalizado "Presupuesto pendiente"
        $order->update_status( 'quote-pending', __( 'Solicitud de presupuesto recibida.', 'mad-suite' ) );
        $order->save();
    }

    public function my_orders_actions( $actions, $order ) {
        $status = $order->get_status();

        if ( $status === 'quote-pending' ) {
            // Pendiente de revisión: ocultar "Pagar"
            unset( $actions['pay'] );
        } elseif ( $status === 'quote-sent' ) {
            // Presupuesto enviado: renombrar "Pagar" como "Pagar presupuesto"
            if ( isset( $actions['pay'] ) ) {
                $actions['pay']['name'] = __( 'Pagar presupuesto', 'mad-suite' );
            }
        }

        return $actions;
    }

    /* ================================================================ */
    /*  Admin: botones y tabla de precios en el pedido                   */
    /* ================================================================ */

    public function add_order_buttons( $order ) {
        if ( $order->get_payment_method() !== 'quotes-gateway' && ! $order->get_meta( '_mad_qwc_quote' ) ) {
            return;
        }

        $order_status     = $order->get_status();
        $allowed_statuses = apply_filters( 'mad_quotes_allowed_statuses_for_buttons', [ 'quote-pending', 'quote-sent', 'pending' ] );
        if ( ! in_array( $order_status, $allowed_statuses, true ) ) return;

        $quote_status = $order->get_meta( '_mad_quote_status' ) ?: 'quote-pending';

        if ( 'quote-pending' === $quote_status ) {
            ?>
            <button id="mad_quote_complete" type="button" class="button">
                <?php esc_html_e( 'Presupuesto completo', 'mad-suite' ); ?>
            </button>
            <?php
        } else {
            $label = 'quote-sent' === $quote_status
                ? esc_html__( 'Reenviar presupuesto', 'mad-suite' )
                : esc_html__( 'Enviar presupuesto', 'mad-suite' );
            ?>
            <div id="mad_quote_price_editor" style="margin-top:12px;margin-bottom:8px;">
                <h4 style="margin:0 0 6px;"><?php esc_html_e( 'Precios del presupuesto', 'mad-suite' ); ?></h4>
                <table class="widefat striped" style="max-width:480px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Producto', 'mad-suite' ); ?></th>
                            <th><?php esc_html_e( 'Cant.', 'mad-suite' ); ?></th>
                            <th><?php esc_html_e( 'Precio unitario', 'mad-suite' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $order->get_items() as $item_id => $item ) :
                        $product_id  = $item->get_product_id();
                        $saved_price = $item->get_meta( '_mad_quote_line_price' );
                        $default     = ( $saved_price !== '' && false !== $saved_price )
                            ? $saved_price
                            : mad_quotes_get_product_quote_price( $product_id );
                    ?>
                        <tr>
                            <td><?php echo esc_html( $item->get_name() ); ?></td>
                            <td><?php echo esc_html( $item->get_quantity() ); ?></td>
                            <td>
                                <input type="number"
                                       step="0.01"
                                       min="0"
                                       class="mad-quote-line-price"
                                       data-item-id="<?php echo esc_attr( $item_id ); ?>"
                                       value="<?php echo esc_attr( $default ); ?>"
                                       style="width:110px;">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button id="mad_send_quote" type="button" class="button button-primary">
                <?php echo esc_html( $label ); ?>
            </button>
            <textarea id="mad_quote_admin_note"
                      placeholder="<?php esc_attr_e( 'Nota opcional para el cliente…', 'mad-suite' ); ?>"
                      rows="2"
                      style="display:block;width:100%;max-width:480px;margin-top:6px;"></textarea>
            <span id="mad_quote_msg" style="display:block;margin-top:4px;font-weight:bold;"></span>
            <?php
        }
    }

    /* ================================================================ */
    /*  JS enqueue                                                        */
    /* ================================================================ */

    public function enqueue_admin_js( $hook ) {
        $order_id = $this->get_current_order_id();
        if ( ! $order_id ) return;

        wp_register_script( 'mad-quotes-admin', MAD_QUOTES_URL . 'assets/js/admin.js', [ 'jquery' ], '2.0', false );
        wp_localize_script( 'mad-quotes-admin', 'mad_quotes_admin_params', [
            'ajax_url'            => admin_url( 'admin-ajax.php' ),
            'order_id'            => $order_id,
            'nonce_update_status' => wp_create_nonce( 'mad-quotes-update-status' ),
            'nonce_send_quote'    => wp_create_nonce( 'mad-quotes-send-quote' ),
            'i18n_sending'        => __( 'Enviando…', 'mad-suite' ),
            'i18n_updating'       => __( 'Actualizando…', 'mad-suite' ),
            'i18n_sent'           => __( '✔ Presupuesto enviado', 'mad-suite' ),
            'i18n_resend'         => __( 'Reenviar presupuesto', 'mad-suite' ),
            'i18n_complete'       => __( 'Presupuesto completo', 'mad-suite' ),
            'i18n_error'          => __( 'Error. Inténtalo de nuevo.', 'mad-suite' ),
        ] );
        wp_enqueue_script( 'mad-quotes-admin' );
    }

    /* ================================================================ */
    /*  AJAX handlers                                                     */
    /* ================================================================ */

    public function ajax_update_status() {
        if ( ! current_user_can( 'manage_woocommerce' )
            || ! isset( $_POST['nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mad-quotes-update-status' )
        ) {
            wp_send_json_error( 'Security check failed.' );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $status   = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

        if ( $order_id && $status ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_mad_quote_status', $status );
                $order->add_order_note( __( 'Presupuesto marcado como completo. Listo para enviar.', 'mad-suite' ) );
                $order->save();
            }
        }

        wp_send_json_success();
    }

    public function ajax_send_quote() {
        if ( ! current_user_can( 'manage_woocommerce' )
            || ! isset( $_POST['nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mad-quotes-send-quote' )
        ) {
            wp_send_json_error( 'Security check failed.' );
        }

        $order_id    = absint( $_POST['order_id'] ?? 0 );
        $admin_note  = sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ?? '' ) );
        $line_prices = isset( $_POST['line_prices'] ) ? (array) $_POST['line_prices'] : [];

        if ( ! $order_id ) wp_die();

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die();

        // Guardar precios editados en la meta y en los totales reales de línea
        if ( ! empty( $line_prices ) ) {
            foreach ( $line_prices as $item_id => $price ) {
                $item = $order->get_item( absint( $item_id ) );
                if ( $item ) {
                    $decimal = wc_format_decimal( sanitize_text_field( (string) $price ) );
                    $qty     = $item->get_quantity();
                    $item->update_meta_data( '_mad_quote_line_price', $decimal );
                    $item->set_subtotal( (float) $decimal * $qty );
                    $item->set_total( (float) $decimal * $qty );
                    $item->save();
                }
            }
            // Recalcular total del pedido; luego eliminar impuestos (presupuesto sin IVA)
            $order->calculate_totals();
            $order->set_cart_tax( 0 );
            $order->set_shipping_tax( 0 );
            foreach ( $order->get_taxes() as $tax_item ) {
                $tax_item->set_tax_total( 0 );
                $tax_item->set_shipping_tax_total( 0 );
                $tax_item->save();
            }
            $order->save();
        }

        $result = $this->send_quote_email( $order_id, $admin_note );
        if ( $result ) {
            wp_send_json_success( 'quote-sent' );
        } else {
            wp_send_json_error( 'error' );
        }
    }

    /* ================================================================ */
    /*  Expiración automática                                             */
    /* ================================================================ */

    public function expire_old_quotes() {
        $settings = mad_quotes_get_settings();
        $days     = absint( $settings['quote_expiry_days'] ?? 0 );
        if ( $days < 1 ) return;

        $cutoff = strtotime( "-{$days} days" );

        $orders = wc_get_orders( [
            'status'       => [ 'quote-pending', 'quote-sent' ],
            'meta_key'     => '_mad_quote_status',
            'meta_value'   => 'quote-pending',
            'date_created' => '<' . $cutoff,
            'limit'        => -1,
        ] );

        foreach ( $orders as $order ) {
            $order->update_meta_data( '_mad_quote_status', 'quote-cancelled' );
            $order->update_status( 'cancelled', __( 'Presupuesto caducado automáticamente.', 'mad-suite' ) );
            $order->save();
        }
    }

    /* ================================================================ */
    /*  Panel de producto                                                 */
    /* ================================================================ */

    public function product_data_tab( $tabs ) {
        $tabs['mad-quotes'] = [
            'label'    => __( 'Presupuesto', 'mad-suite' ),
            'target'   => 'mad_quotes_product_data',
            'class'    => [],
            'priority' => 90,
        ];
        return $tabs;
    }

    public function product_data_panel() {
        ?>
        <div id="mad_quotes_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field" style="padding:12px 12px 12px 162px;">
                    <span style="display:block;background:#f0f6fc;border-left:4px solid #2980b9;padding:10px 14px;border-radius:2px;">
                        <strong><?php esc_html_e( 'Precio de cotización', 'mad-suite' ); ?></strong>
                        &rarr; <?php esc_html_e( 'Precio regular del producto.', 'mad-suite' ); ?><br>
                        <?php esc_html_e( 'Es el precio que el admin enviará al cliente en el email de presupuesto (editable antes de enviar).', 'mad-suite' ); ?>
                    </span>
                    <span style="display:block;background:#f0faf0;border-left:4px solid #27ae60;padding:10px 14px;border-radius:2px;margin-top:8px;">
                        <strong><?php esc_html_e( 'Precio de profesionales', 'mad-suite' ); ?></strong>
                        &rarr; <?php esc_html_e( 'Precio de oferta del producto.', 'mad-suite' ); ?><br>
                        <?php esc_html_e( 'Los usuarios con rol profesional ven y pagan este precio directamente, sin pasar por presupuesto.', 'mad-suite' ); ?>
                    </span>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_product_meta( $post_id ) {
        // La configuración de precios usa los campos nativos de WooCommerce:
        // Precio regular → cotización | Precio de oferta → profesionales.
        // No hay metadatos adicionales que guardar.
    }

    /* ================================================================ */
    /*  Helpers privados                                                  */
    /* ================================================================ */

    private function send_quote_email( $order_id, $admin_note = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return false;

        WC_Emails::instance();
        do_action( 'mad_quotes_send_quote_notification', $order_id, $admin_note );

        // Actualizar meta de estado y cambiar estado WC a "Presupuesto enviado"
        $order->update_meta_data( '_mad_quote_status', 'quote-sent' );
        $order->update_status( 'quote-sent', sprintf(
            /* translators: email address */
            __( 'Presupuesto enviado a %s.', 'mad-suite' ),
            $order->get_billing_email()
        ) );
        $order->save();

        return true;
    }

    /**
     * Comprueba si algún plugin de "Quotes for WooCommerce" está activo.
     */
    private function is_quotes_plugin_active(): bool {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $known_slugs = [
            'quotes-wc/quotes-wc.php',
            'quotes-for-woocommerce/quotes-for-woocommerce.php',
            'woocommerce-quotes/woocommerce-quotes.php',
            'woo-quotes/woo-quotes.php',
        ];

        foreach ( $known_slugs as $slug ) {
            if ( is_plugin_active( $slug ) ) {
                return true;
            }
        }

        // Escaneo flexible
        $active = (array) apply_filters( 'active_plugins', get_option( 'active_plugins', [] ) );
        foreach ( $active as $plugin_file ) {
            $lower = strtolower( $plugin_file );
            if ( strpos( $lower, 'quote' ) !== false
                && ( strpos( $lower, 'wc' ) !== false || strpos( $lower, 'woo' ) !== false )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detecta el ID del pedido actual en pantalla de edición (HPOS-compatible).
     */
    private function get_current_order_id() {
        if ( isset( $_GET['page'], $_GET['id'] ) && 'wc-orders' === $_GET['page'] && $_GET['id'] > 0 ) { //phpcs:ignore WordPress.Security.NonceVerification
            return absint( $_GET['id'] );
        }
        global $post;
        if ( isset( $post->post_type ) && 'shop_order' === $post->post_type ) {
            return $post->ID;
        }
        return null;
    }

    /* ================================================================ */
    /*  Helpers de campos de ajustes                                     */
    /* ================================================================ */

    private function register_field( $key, $label, $callback_method, $section, $desc = '' ) {
        add_settings_field(
            'mad_quotes_' . $key,
            $label,
            [ $this, $callback_method ],
            $this->menu_slug(),
            $section,
            [ 'key' => $key, 'desc' => $desc ]
        );
    }

    public function field_text( $args ) {
        $settings = mad_quotes_get_settings();
        $key      = $args['key'];
        $opt_key  = MAD_Suite_Core::option_key( $this->slug );
        printf(
            '<input type="text" name="%1$s[%2$s]" value="%3$s" class="regular-text"><br><span class="description">%4$s</span>',
            esc_attr( $opt_key ),
            esc_attr( $key ),
            esc_attr( $settings[ $key ] ?? '' ),
            esc_html( $args['desc'] ?? '' )
        );
    }

    public function field_button_text( $args ) {
        $settings = mad_quotes_get_settings();
        $opt_key  = MAD_Suite_Core::option_key( $this->slug );
        $stored   = $settings['quote_button_text'] ?? [];

        // Legacy: old installs stored a plain string — migrate on render
        if ( is_string( $stored ) && $stored !== '' ) {
            $default_lang = apply_filters( 'wpml_default_language', 'es' ) ?: 'es';
            $stored = [ $default_lang => $stored ];
        }
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        // Detect active WPML languages; fall back to single field if WPML absent
        $languages = [];
        if ( function_exists( 'icl_get_languages' ) ) {
            $raw = icl_get_languages( 'skip_missing=0' );
            foreach ( $raw as $code => $info ) {
                $languages[ $code ] = $info['native_name'];
            }
        }

        if ( empty( $languages ) ) {
            // No WPML — single input
            $value = is_array( $stored ) ? ( reset( $stored ) ?: '' ) : $stored;
            printf(
                '<input type="text" name="%1$s[quote_button_text][default]" value="%2$s" class="regular-text"><br><span class="description">%3$s</span>',
                esc_attr( $opt_key ),
                esc_attr( $value ),
                esc_html( $args['desc'] ?? '' )
            );
            return;
        }

        // One input per language
        echo '<table class="form-table" style="margin:0;"><tbody>';
        foreach ( $languages as $code => $name ) {
            $value = $stored[ $code ] ?? '';
            printf(
                '<tr><th style="padding:4px 10px 4px 0;font-weight:normal;width:80px;">%1$s <small>(%2$s)</small></th>'
                . '<td><input type="text" name="%3$s[quote_button_text][%4$s]" value="%5$s" class="regular-text"></td></tr>',
                esc_html( $name ),
                esc_html( strtoupper( $code ) ),
                esc_attr( $opt_key ),
                esc_attr( $code ),
                esc_attr( $value )
            );
        }
        echo '</tbody></table>';
        echo '<span class="description">' . esc_html( $args['desc'] ?? '' ) . '</span>';
    }

    /** Generic per-language text field (reused for mini-cart button texts). */
    public function field_multilang_text( $args ) {
        $settings = mad_quotes_get_settings();
        $key      = $args['key'];
        $opt_key  = MAD_Suite_Core::option_key( $this->slug );
        $stored   = $settings[ $key ] ?? [];
        if ( ! is_array( $stored ) ) {
            $stored = $stored !== '' ? [ 'default' => $stored ] : [];
        }

        $languages = [];
        if ( function_exists( 'icl_get_languages' ) ) {
            foreach ( icl_get_languages( 'skip_missing=0' ) as $code => $info ) {
                $languages[ $code ] = $info['native_name'];
            }
        }

        if ( empty( $languages ) ) {
            $value = reset( $stored ) ?: '';
            printf(
                '<input type="text" name="%1$s[%2$s][default]" value="%3$s" class="regular-text"><br><span class="description">%4$s</span>',
                esc_attr( $opt_key ),
                esc_attr( $key ),
                esc_attr( $value ),
                esc_html( $args['desc'] ?? '' )
            );
            return;
        }

        echo '<table class="form-table" style="margin:0;"><tbody>';
        foreach ( $languages as $code => $name ) {
            printf(
                '<tr><th style="padding:4px 10px 4px 0;font-weight:normal;width:80px;">%1$s <small>(%2$s)</small></th>'
                . '<td><input type="text" name="%3$s[%4$s][%5$s]" value="%6$s" class="regular-text"></td></tr>',
                esc_html( $name ),
                esc_html( strtoupper( $code ) ),
                esc_attr( $opt_key ),
                esc_attr( $key ),
                esc_attr( $code ),
                esc_attr( $stored[ $code ] ?? '' )
            );
        }
        echo '</tbody></table>';
        echo '<span class="description">' . esc_html( $args['desc'] ?? '' ) . '</span>';
    }

    public function field_number( $args ) {
        $settings = mad_quotes_get_settings();
        $key      = $args['key'];
        $opt_key  = MAD_Suite_Core::option_key( $this->slug );
        printf(
            '<input type="number" name="%1$s[%2$s]" value="%3$s" min="0" class="small-text"><br><span class="description">%4$s</span>',
            esc_attr( $opt_key ),
            esc_attr( $key ),
            esc_attr( $settings[ $key ] ?? 0 ),
            esc_html( $args['desc'] ?? '' )
        );
    }


    public function field_roles_multiselect( $args ) {
        $settings = mad_quotes_get_settings();
        $selected = array_filter( (array) ( $settings['quote_roles'] ?? [] ) );
        $opt_key  = MAD_Suite_Core::option_key( $this->slug );
        $roles    = wp_roles()->roles;

        echo '<fieldset>';

        $checked = in_array( 'guest', $selected, true ) ? ' checked' : '';
        printf(
            '<label><input type="checkbox" name="%s[quote_roles][]" value="guest"%s> %s</label><br>',
            esc_attr( $opt_key ),
            $checked,
            esc_html__( 'Visitantes (no registrados)', 'mad-suite' )
        );

        foreach ( $roles as $slug => $role ) {
            $checked = in_array( $slug, $selected, true ) ? ' checked' : '';
            printf(
                '<label><input type="checkbox" name="%s[quote_roles][]" value="%s"%s> %s</label><br>',
                esc_attr( $opt_key ),
                esc_attr( $slug ),
                $checked,
                esc_html( translate_user_role( $role['name'] ) )
            );
        }

        echo '</fieldset>';
        echo '<p class="description">' . esc_html( $args['desc'] ?? '' ) . '</p>';
    }

    /** Renderiza el shortcode [mad_quote_cart] para incrustar en cualquier página. */
    public function shortcode_quote_cart(): string {
        if ( ! $this->current_user_is_quote_role() ) return '';
        if ( ! function_exists( 'WC' ) || ! isset( WC()->cart ) ) return '';

        $settings  = mad_quotes_get_settings();
        $btn_label = trim( $this->resolve_lang_text( $settings['quote_button_text'] ?? [] ) );
        if ( $btn_label === '' ) {
            $btn_label = __( 'Solicitar presupuesto', 'mad-suite' );
        }

        ob_start();
        wc_print_notices();

        if ( WC()->cart->is_empty() ) : ?>
            <p class="cart-empty">
                <?php esc_html_e( 'Tu solicitud de presupuesto está vacía.', 'mad-suite' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
                    <?php esc_html_e( 'Ver productos', 'mad-suite' ); ?>
                </a>
            </p>
        <?php else : ?>
            <form class="mad-quote-cart__form"
                  action="<?php echo esc_url( wc_get_cart_url() ); ?>"
                  method="post">
                <table class="mad-quote-cart__table">
                    <thead>
                        <tr>
                            <th class="product-remove">&nbsp;</th>
                            <th class="product-thumbnail">&nbsp;</th>
                            <th class="product-name"><?php esc_html_e( 'Producto', 'mad-suite' ); ?></th>
                            <th class="product-quantity"><?php esc_html_e( 'Cantidad', 'mad-suite' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
                        $product    = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                        $product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
                        if ( ! $product || ! $product->exists() || 0 === $cart_item['quantity'] ) continue;
                        $product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $product->is_visible() ? $product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
                    ?>
                        <tr class="woocommerce-cart-form__cart-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">
                            <td class="product-remove">
                                <?php echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                    'woocommerce_cart_item_remove_link',
                                    sprintf(
                                        '<a href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
                                        esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
                                        esc_html__( 'Eliminar este artículo', 'mad-suite' ),
                                        esc_attr( $product_id ),
                                        esc_attr( $product->get_sku() )
                                    ),
                                    $cart_item_key
                                ); ?>
                            </td>
                            <td class="product-thumbnail">
                                <?php
                                $thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $product->get_image(), $cart_item, $cart_item_key );
                                if ( $product_permalink ) {
                                    printf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $thumbnail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                } else {
                                    echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                }
                                ?>
                            </td>
                            <td class="product-name" data-title="<?php esc_attr_e( 'Producto', 'mad-suite' ); ?>">
                                <?php if ( $product_permalink ) : ?>
                                    <a href="<?php echo esc_url( $product_permalink ); ?>"><?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $cart_item, $cart_item_key ) ); ?></a>
                                <?php else : ?>
                                    <?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $cart_item, $cart_item_key ) ); ?>
                                <?php endif; ?>
                                <?php do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key ); ?>
                                <?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </td>
                            <td class="product-quantity" data-title="<?php esc_attr_e( 'Cantidad', 'mad-suite' ); ?>">
                                <?php if ( $product->is_sold_individually() ) {
                                    echo '1';
                                } else {
                                    woocommerce_quantity_input( [
                                        'input_name'   => "cart[{$cart_item_key}][qty]",
                                        'input_value'  => $cart_item['quantity'],
                                        'max_value'    => $product->get_max_purchase_quantity(),
                                        'min_value'    => '0',
                                        'product_name' => $product->get_name(),
                                    ], $product );
                                } ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="mad-quote-cart__update">
                    <button type="submit" class="mad-quote-cart__btn-update" name="update_cart"
                            value="<?php esc_attr_e( 'Actualizar', 'mad-suite' ); ?>">
                        <?php esc_html_e( 'Actualizar solicitud', 'mad-suite' ); ?>
                    </button>
                    <?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
                </div>
            </form>

            <div class="mad-quote-cart__actions">
                <form method="post" class="mad-quote-submit-form">
                    <?php wp_nonce_field( 'mad_create_quote', 'mad_create_quote_nonce' ); ?>
                    <?php $current_user = wp_get_current_user(); ?>
                    <p class="mad-quote-cart__field">
                        <label for="mad-quote-email"><?php esc_html_e( 'Email', 'mad-suite' ); ?></label>
                        <input type="email" id="mad-quote-email" name="olofane_email"
                               value="<?php echo esc_attr( $current_user->user_email ); ?>" required>
                    </p>
                    <p class="mad-quote-cart__field">
                        <label for="mad-quote-notas"><?php esc_html_e( 'Notas (opcional)', 'mad-suite' ); ?></label>
                        <textarea id="mad-quote-notas" name="olofane_notas" rows="4"></textarea>
                    </p>
                    <button type="submit" name="mad_submit_quote" class="mad-quote-cart__proceed">
                        <?php echo esc_html( $btn_label ); ?>
                    </button>
                </form>
                <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
                   class="mad-quote-cart__back">
                    <?php esc_html_e( 'Seguir viendo productos', 'mad-suite' ); ?>
                </a>
            </div>
        <?php endif;

        return ob_get_clean();
    }

    /** Registra en el log de WooCommerce los fallos de entrega de email. */
    public function log_mail_failure( WP_Error $error ): void {
        wc_get_logger()->error(
            'wp_mail() falló: ' . $error->get_error_message(),
            [ 'source' => 'mad-quotes-email' ]
        );
    }

    /** Selector de página de WordPress para los ajustes del módulo. */
    public function field_page_select( $args ) {
        $settings = mad_quotes_get_settings();
        $key      = $args['key'];
        $opt_key  = MAD_Suite_Core::option_key( $this->slug );
        $current  = absint( $settings[ $key ] ?? 0 );

        wp_dropdown_pages( [
            'name'              => $opt_key . '[' . $key . ']',
            'id'                => 'mad_quotes_' . $key,
            'selected'          => $current,
            'show_option_none'  => __( '— Usar plantilla por defecto —', 'mad-suite' ),
            'option_none_value' => 0,
            'post_status'       => 'publish',
        ] );
        echo '<br><span class="description">' . esc_html( $args['desc'] ?? '' ) . '</span>';
    }

    public function sanitize_settings( $input ) {
        $clean = [];

        $clean['quote_roles'] = isset( $input['quote_roles'] )
            ? array_values( array_map( 'sanitize_text_field', (array) $input['quote_roles'] ) )
            : [];

        $clean['quote_expiry_days'] = absint( $input['quote_expiry_days'] ?? 0 );

        $raw_button = $input['quote_button_text'] ?? [];
        if ( is_array( $raw_button ) ) {
            $clean['quote_button_text'] = array_map( 'sanitize_text_field', $raw_button );
        } else {
            // Legacy plain string → keep as-is so resolve_button_text() can migrate it
            $clean['quote_button_text'] = sanitize_text_field( $raw_button );
        }

        foreach ( [ 'mini_cart_view_cart_text', 'mini_cart_checkout_text' ] as $field ) {
            $raw = $input[ $field ] ?? [];
            $clean[ $field ] = is_array( $raw )
                ? array_map( 'sanitize_text_field', $raw )
                : sanitize_text_field( $raw );
        }

        $clean['quote_cart_page_id'] = absint( $input['quote_cart_page_id'] ?? 0 );

        return $clean;
    }
};
