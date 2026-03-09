<?php
/**
 * Módulo: Quotes for WooCommerce
 *
 * Permite a los clientes solicitar presupuestos en lugar de comprar directamente.
 * - Oculta precios en tienda, carrito y checkout para productos configurados.
 * - Añade un gateway de pago "fantasma" para que el checkout no exija pago.
 * - Genera emails automáticos al admin y al cliente.
 * - El admin puede marcar el presupuesto como completado y enviarlo desde el pedido.
 * - Mejoras respecto al plugin original: nota del admin al enviar, historial de estados
 *   y fecha de caducidad configurable.
 *
 * @package MAD_Suite/Quotes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var MAD_Suite_Core $core Passed by the framework loader. */

define( 'MAD_QUOTES_TEMPLATE_PATH', plugin_dir_path( __FILE__ ) . 'templates/' );
define( 'MAD_QUOTES_DIR',           plugin_dir_path( __FILE__ ) );
define( 'MAD_QUOTES_URL',           plugin_dir_url( __FILE__ ) );

// Helper functions available globally once the module is loaded.
require_once MAD_QUOTES_DIR . 'includes/functions.php';

return new class( $core ) implements MAD_Suite_Module {

    private $core;
    private $slug = 'quotes';

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
        return __( 'Permite a los clientes solicitar presupuestos. Oculta precios, añade un flujo de aprobación en el admin y envía emails automáticos.', 'mad-suite' );
    }

    public function required_plugins() {
        return [ 'WooCommerce' => 'woocommerce/woocommerce.php' ];
    }

    /* ---------------------------------------------------------------- */
    /*  init() — hooks que deben registrarse siempre (front + admin)    */
    /* ---------------------------------------------------------------- */

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_wc_required' ] );
            return;
        }

        // Load gateway + emails on plugins_loaded so WC classes exist.
        add_action( 'plugins_loaded', [ $this, 'load_includes' ], 12 );

        // Register payment gateway.
        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );

        // Register WC email classes.
        add_filter( 'woocommerce_email_classes', [ $this, 'register_emails' ] );

        // ── Price visibility ─────────────────────────────────────────
        add_filter( 'woocommerce_get_price_html',                   [ $this, 'maybe_hide_price' ], 10, 2 );
        add_filter( 'woocommerce_variable_price_html',              [ $this, 'maybe_hide_price' ], 10, 2 );
        add_filter( 'woocommerce_variable_sale_price_html',         [ $this, 'maybe_hide_price' ], 10, 2 );
        add_filter( 'woocommerce_composited_product_price_string',  [ $this, 'maybe_hide_price' ], 10, 2 );

        // ── Button text ──────────────────────────────────────────────
        add_filter( 'woocommerce_product_add_to_cart_text',        [ $this, 'change_button_text' ], 99, 1 );
        add_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'change_button_text' ], 99, 1 );

        // ── CSS (hide prices on cart/checkout/account) ────────────────
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_css' ] );
        add_action( 'woocommerce_thankyou',    [ $this, 'maybe_enqueue_thankyou_css' ], 10, 1 );
        add_action( 'woocommerce_view_order',  [ $this, 'maybe_enqueue_thankyou_css' ], 10, 1 );

        // ── Cart widget ───────────────────────────────────────────────
        add_filter( 'woocommerce_cart_item_price',              [ $this, 'cart_widget_price' ], 10, 2 );
        add_action( 'woocommerce_widget_shopping_cart_total',   [ $this, 'remove_widget_subtotal_hook' ], 1 );
        add_action( 'woocommerce_widget_shopping_cart_total',   [ $this, 'widget_subtotal' ], 999 );

        // ── Cart validation ───────────────────────────────────────────
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'cart_validation' ], 10, 3 );
        add_filter( 'woocommerce_cart_needs_payment',     [ $this, 'cart_needs_payment' ], 10, 2 );

        // ── Prevent WC from auto-cancelling pending quote orders ──────
        add_filter( 'woocommerce_cancel_unpaid_order', [ $this, 'prevent_cancel' ], 10, 2 );

        // ── Available payment gateways at checkout ────────────────────
        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_payment_gateways' ], 10, 1 );

        // ── Order meta on checkout ────────────────────────────────────
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_quote_order_meta' ], 10, 2 );

        // ── My account: hide "Pay" for pending quotes ─────────────────
        add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'my_orders_actions' ], 10, 2 );

        // ── Admin order edit: buttons ─────────────────────────────────
        add_action( 'woocommerce_order_item_add_action_buttons', [ $this, 'add_order_buttons' ], 10, 1 );

        // ── JS ─────────────────────────────────────────────────────────
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_js' ] );
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_product_js' ] );

        // ── Page titles ───────────────────────────────────────────────
        add_filter( 'the_title', [ $this, 'filter_page_titles' ], 99, 2 );

        // ── Proceed-to-checkout button text ───────────────────────────
        add_action( 'woocommerce_proceed_to_checkout', [ $this, 'change_proceed_checkout_btn' ], 10 );

        // ── Shipping & address fields ─────────────────────────────────
        add_filter( 'woocommerce_cart_needs_shipping', [ $this, 'cart_needs_shipping' ] );
        add_filter( 'woocommerce_billing_fields',      [ $this, 'billing_fields' ], 999 );
        add_filter( 'woocommerce_checkout_fields',     [ $this, 'checkout_fields' ], 9999 );

        // ── Quote expiry cron ─────────────────────────────────────────
        if ( ! wp_next_scheduled( 'mad_quotes_check_expiry' ) ) {
            wp_schedule_event( time(), 'daily', 'mad_quotes_check_expiry' );
        }
        add_action( 'mad_quotes_check_expiry', [ $this, 'expire_old_quotes' ] );
    }

    /* ---------------------------------------------------------------- */
    /*  admin_init() — settings, AJAX                                   */
    /* ---------------------------------------------------------------- */

    public function admin_init() {
        $option_key = MAD_Suite_Core::option_key( $this->slug );

        register_setting( $this->menu_slug(), $option_key, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default'           => [],
        ] );

        // ── Section: Global ────────────────────────────────────────────
        add_settings_section(
            'mad_quotes_global',
            __( 'Ajustes globales', 'mad-suite' ),
            function () {
                echo '<p>' . esc_html__( 'Activa las cotizaciones para todos los productos a la vez o gestiona cada producto individualmente.', 'mad-suite' ) . '</p>';
            },
            $this->menu_slug()
        );

        $this->register_field( 'enable_global_quote',  __( 'Habilitar cotizaciones (global)', 'mad-suite' ), 'field_checkbox', 'mad_quotes_global',
            __( 'Activa el modo de cotización para todos los productos.', 'mad-suite' )
        );
        $this->register_field( 'enable_global_prices', __( 'Mostrar precios (global)', 'mad-suite' ), 'field_checkbox', 'mad_quotes_global',
            __( 'Muestra el precio incluso en productos cotizables.', 'mad-suite' )
        );

        // ── Section: Textos ────────────────────────────────────────────
        add_settings_section( 'mad_quotes_texts', __( 'Textos personalizados', 'mad-suite' ), '__return_false', $this->menu_slug() );

        $this->register_field( 'add_to_cart_button_text',    __( 'Texto botón «Añadir al carrito»', 'mad-suite' ),      'field_text', 'mad_quotes_texts',
            __( 'Dejar vacío para usar "Solicitar presupuesto".', 'mad-suite' )
        );
        $this->register_field( 'place_order_text',           __( 'Texto botón «Tramitar pedido»', 'mad-suite' ),         'field_text', 'mad_quotes_texts',
            __( 'Dejar vacío para usar "Solicitar presupuesto".', 'mad-suite' )
        );
        $this->register_field( 'cart_page_name',             __( 'Nombre de la página «Carrito»', 'mad-suite' ),         'field_text', 'mad_quotes_texts' );
        $this->register_field( 'checkout_page_name',         __( 'Nombre de la página «Checkout»', 'mad-suite' ),        'field_text', 'mad_quotes_texts' );
        $this->register_field( 'proceed_checkout_btn_label', __( 'Texto botón «Proceder al checkout»', 'mad-suite' ),    'field_text', 'mad_quotes_texts' );

        // ── Section: Checkout ──────────────────────────────────────────
        add_settings_section( 'mad_quotes_checkout', __( 'Ajustes de checkout', 'mad-suite' ), '__return_false', $this->menu_slug() );

        $this->register_field( 'hide_address_fields', __( 'Ocultar campos de dirección', 'mad-suite' ), 'field_checkbox', 'mad_quotes_checkout',
            __( 'Oculta los campos de empresa, dirección, ciudad, etc. en el checkout cuando el carrito solo contiene productos cotizables.', 'mad-suite' )
        );

        // ── Section: Caducidad ─────────────────────────────────────────
        add_settings_section( 'mad_quotes_expiry', __( 'Caducidad de presupuestos', 'mad-suite' ), function () {
            echo '<p>' . esc_html__( 'Caducan automáticamente los presupuestos pendientes después de N días. Escribe 0 para desactivar.', 'mad-suite' ) . '</p>';
        }, $this->menu_slug() );

        $this->register_field( 'quote_expiry_days', __( 'Días hasta caducidad', 'mad-suite' ), 'field_number', 'mad_quotes_expiry' );

        // ── AJAX ───────────────────────────────────────────────────────
        add_action( 'wp_ajax_mad_quotes_update_status', [ $this, 'ajax_update_status' ] );
        add_action( 'wp_ajax_mad_quotes_send_quote',    [ $this, 'ajax_send_quote' ] );

        // ── Product-level meta box ─────────────────────────────────────
        add_action( 'woocommerce_product_data_tabs',   [ $this, 'product_data_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'product_data_panel' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_meta' ] );
    }

    /* ---------------------------------------------------------------- */
    /*  render_settings_page()                                          */
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
    /*  Loaders                                                          */
    /* ================================================================ */

    public function load_includes() {
        require_once MAD_QUOTES_DIR . 'includes/class-mad-quotes-gateway.php';
        require_once MAD_QUOTES_DIR . 'includes/emails/class-mad-quotes-new-request.php';
        require_once MAD_QUOTES_DIR . 'includes/emails/class-mad-quotes-confirmation.php';
        require_once MAD_QUOTES_DIR . 'includes/emails/class-mad-quotes-send-quote.php';
    }

    public function register_gateway( $gateways ) {
        $gateways[] = 'MAD_Quotes_Payment_Gateway';
        return $gateways;
    }

    public function register_emails( $email_classes ) {
        $email_classes['MAD_Quotes_Email_New_Request']  = new MAD_Quotes_Email_New_Request();
        $email_classes['MAD_Quotes_Email_Confirmation'] = new MAD_Quotes_Email_Confirmation();
        $email_classes['MAD_Quotes_Email_Send_Quote']   = new MAD_Quotes_Email_Send_Quote();
        return $email_classes;
    }

    public function notice_wc_required() {
        echo '<div class="notice notice-warning"><p>' .
            esc_html__( 'El módulo "Presupuestos" requiere WooCommerce activo.', 'mad-suite' ) .
        '</p></div>';
    }

    /* ================================================================ */
    /*  Price visibility                                                  */
    /* ================================================================ */

    public function maybe_hide_price( $price, $product ) {
        global $post;
        $post_id = $post ? $post->ID : ( $product ? $product->get_id() : 0 );
        if ( ! $post_id ) return $price;

        $quote_enabled = mad_quotes_product_quote_enabled( $post_id );
        $quote_enabled = apply_filters( 'mad_quotes_hide_prices', $quote_enabled, $post_id );

        if ( $quote_enabled && ! mad_quotes_product_price_display( $post_id ) ) {
            $price = '';
        }

        return $price;
    }

    /* ================================================================ */
    /*  Button text                                                       */
    /* ================================================================ */

    public function change_button_text( $text ) {
        global $post;
        if ( ! $post ) return $text;

        if ( mad_quotes_product_quote_enabled( $post->ID ) ) {
            $settings  = mad_quotes_get_settings();
            $custom    = trim( $settings['add_to_cart_button_text'] ?? '' );
            $text = $custom !== '' ? $custom : __( 'Solicitar presupuesto', 'mad-suite' );
        }

        return $text;
    }

    /* ================================================================ */
    /*  CSS enqueue                                                       */
    /* ================================================================ */

    public function enqueue_frontend_css() {
        $contains   = mad_quotes_cart_contains_quotable();
        $global_on  = ! empty( mad_quotes_get_settings()['enable_global_quote'] );
        $show_price = mad_quotes_cart_display_price();

        if ( ( is_cart() || is_checkout() ) && ( $contains || $global_on ) && ! $show_price ) {
            wp_enqueue_style( 'mad-quotes-frontend', MAD_QUOTES_URL . 'assets/css/frontend.css', [], '1.0' );
        }

        if ( ( $contains || $global_on ) && ! $show_price ) {
            wp_enqueue_style( 'mad-quotes-shop', MAD_QUOTES_URL . 'assets/css/shop.css', [], '1.0' );
        }
    }

    public function maybe_enqueue_thankyou_css( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( 'quote-pending' === $order->get_meta( '_mad_quote_status' ) && ! mad_quotes_order_display_price( $order ) ) {
            wp_enqueue_style( 'mad-quotes-frontend', MAD_QUOTES_URL . 'assets/css/frontend.css', [], '1.0' );
        }
    }

    /* ================================================================ */
    /*  Cart widget                                                       */
    /* ================================================================ */

    public function cart_widget_price( $price, $cart_item ) {
        $product_id = apply_filters( 'mad_quotes_cart_item_product_id', $cart_item['product_id'], $cart_item );
        $qty        = $cart_item['quantity'] ?? 1;

        if ( mad_quotes_product_quote_enabled( $product_id, $qty ) && ! mad_quotes_cart_display_price() ) {
            return '';
        }

        return $price;
    }

    public function remove_widget_subtotal_hook() {
        if ( isset( WC()->cart ) && mad_quotes_cart_contains_quotable() && ! mad_quotes_cart_display_price() ) {
            remove_action( 'woocommerce_widget_shopping_cart_total', 'woocommerce_widget_shopping_cart_subtotal', 10 );
        }
    }

    public function widget_subtotal() {
        if ( isset( WC()->cart ) && mad_quotes_cart_contains_quotable() && ! mad_quotes_cart_display_price() ) {
            echo wp_kses_post(
                sprintf(
                    '<strong>%s</strong> <span class="amount">%s</span>',
                    esc_html__( 'Subtotal:', 'mad-suite' ),
                    ''
                )
            );
        }
    }

    /* ================================================================ */
    /*  Cart validation                                                   */
    /* ================================================================ */

    public function cart_validation( $passed, $product_id, $qty ) {
        if ( ! isset( WC()->cart ) || count( WC()->cart->cart_contents ) === 0 ) {
            return $passed;
        }

        $is_quotable     = mad_quotes_product_quote_enabled( $product_id, $qty );
        $cart_has_quotes = mad_quotes_cart_contains_quotable();

        if ( ( $is_quotable && ! $cart_has_quotes ) || ( ! $is_quotable && $cart_has_quotes ) ) {
            WC()->cart->empty_cart();
            $msg = apply_filters(
                'mad_quotes_cart_conflict_msg',
                __( 'No es posible mezclar productos cotizables con productos normales. Se ha vaciado el carrito.', 'mad-suite' )
            );
            wc_add_notice( $msg, 'notice' );
        }

        return $passed;
    }

    public function cart_needs_payment( $needs_payment, $cart ) {
        if ( $needs_payment ) return $needs_payment;

        foreach ( $cart->cart_contents as $item ) {
            $qty = $item['quantity'] ?? 1;
            if ( mad_quotes_product_quote_enabled( $item['product_id'], $qty ) ) {
                return true;
            }
        }

        return $needs_payment;
    }

    /* ================================================================ */
    /*  Order lifecycle                                                   */
    /* ================================================================ */

    public function prevent_cancel( $return, $order ) {
        if ( '1' === $order->get_meta( '_mad_qwc_quote' ) ) {
            return false;
        }
        return $return;
    }

    public function filter_payment_gateways( $gateways ) {
        $modify = apply_filters( 'mad_quotes_update_payments_at_checkout', true );

        if ( mad_quotes_cart_contains_quotable() && $modify ) {
            $gateways = [ 'mad-quotes-gateway' => new MAD_Quotes_Payment_Gateway() ];
        } else {
            unset( $gateways['mad-quotes-gateway'] );
        }

        return $gateways;
    }

    public function save_quote_order_meta( $order_id ) {
        if ( isset( WC()->session ) && 'mad-quotes-gateway' === WC()->session->get( 'chosen_payment_method' ) ) {
            $status = 'quote-pending';
        } else {
            $status = 'quote-complete';
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $order->update_meta_data( '_mad_quote_status', $status );
        $order->update_meta_data( '_mad_qwc_quote', '1' );
        $order->save();

        if ( 'quote-pending' === $status ) {
            // Notify admin + send customer confirmation.
            WC_Emails::instance();
            do_action( 'mad_quotes_pending_notification', $order_id );
        }
    }

    /* ================================================================ */
    /*  My account                                                        */
    /* ================================================================ */

    public function my_orders_actions( $actions, $order ) {
        if ( $order->has_status( 'pending' ) && 'mad-quotes-gateway' === $order->get_payment_method() ) {
            $quote_status = $order->get_meta( '_mad_quote_status' );
            if ( in_array( $quote_status, [ 'quote-pending', 'quote-cancelled' ], true ) ) {
                unset( $actions['pay'] );
            }
        }
        return $actions;
    }

    /* ================================================================ */
    /*  Admin order edit buttons                                          */
    /* ================================================================ */

    public function add_order_buttons( $order ) {
        $order_status = $order->get_status();
        $quote_status = $order->get_meta( '_mad_quote_status' );

        $allowed_statuses = apply_filters( 'mad_quotes_allowed_statuses_for_buttons', [ 'pending' ] );

        if ( ! in_array( $order_status, $allowed_statuses, true ) ) return;
        if ( ! $quote_status ) return;

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
            <button id="mad_send_quote" type="button" class="button">
                <?php echo esc_html( $label ); ?>
            </button>
            <textarea id="mad_quote_admin_note" placeholder="<?php esc_attr_e( 'Nota opcional para el cliente…', 'mad-suite' ); ?>" rows="2" style="display:block;width:100%;margin-top:6px;"></textarea>
            <span id="mad_quote_msg" style="font-weight:bold;"></span>
            <?php
        }
    }

    /* ================================================================ */
    /*  JS enqueue                                                        */
    /* ================================================================ */

    public function enqueue_admin_js( $hook ) {
        $order_id = $this->get_current_order_id();
        if ( ! $order_id ) return;

        wp_register_script( 'mad-quotes-admin', MAD_QUOTES_URL . 'assets/js/admin.js', [ 'jquery' ], '1.0', false );
        wp_localize_script( 'mad-quotes-admin', 'mad_quotes_admin_params', [
            'ajax_url'           => admin_url( 'admin-ajax.php' ),
            'order_id'           => $order_id,
            'nonce_update_status'=> wp_create_nonce( 'mad-quotes-update-status' ),
            'nonce_send_quote'   => wp_create_nonce( 'mad-quotes-send-quote' ),
            'i18n_sending'       => __( 'Enviando…', 'mad-suite' ),
            'i18n_updating'      => __( 'Actualizando…', 'mad-suite' ),
            'i18n_sent'          => __( '✔ Presupuesto enviado', 'mad-suite' ),
            'i18n_resend'        => __( 'Reenviar presupuesto', 'mad-suite' ),
            'i18n_complete'      => __( 'Presupuesto completo', 'mad-suite' ),
            'i18n_error'         => __( 'Error. Inténtalo de nuevo.', 'mad-suite' ),
        ] );
        wp_enqueue_script( 'mad-quotes-admin' );
    }

    public function enqueue_product_js() {
        if ( ! is_product() ) return;

        global $post;
        $product_id = $post ? $post->ID : 0;
        if ( ! $product_id || ! mad_quotes_product_quote_enabled( $product_id ) ) return;

        wp_register_script( 'mad-quotes-product', MAD_QUOTES_URL . 'assets/js/product.js', [], '1.0', true );
        wp_localize_script( 'mad-quotes-product', 'mad_quotes_product_params', [
            'product_id'    => $product_id,
            'quotes_enabled'=> true,
        ] );
        wp_enqueue_script( 'mad-quotes-product' );
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
            $this->update_quote_status( $order_id, $status );
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->add_order_note( __( 'Presupuesto marcado como completo.', 'mad-suite' ) );
            }
        }

        wp_die();
    }

    public function ajax_send_quote() {
        if ( ! current_user_can( 'manage_woocommerce' )
            || ! isset( $_POST['nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mad-quotes-send-quote' )
        ) {
            wp_send_json_error( 'Security check failed.' );
        }

        $order_id   = absint( $_POST['order_id'] ?? 0 );
        $admin_note = sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ?? '' ) );

        if ( ! $order_id ) wp_die();

        $result = $this->send_quote_email( $order_id, $admin_note );
        echo $result ? 'quote-sent' : 'error';
        wp_die();
    }

    /* ================================================================ */
    /*  Page title filters                                                */
    /* ================================================================ */

    public function filter_page_titles( $title, $id ) {
        $settings = mad_quotes_get_settings();

        if ( mad_quotes_cart_contains_quotable() && wc_get_page_id( 'cart' ) === $id ) {
            $name  = trim( $settings['cart_page_name'] ?? '' );
            $title = $name !== '' ? esc_attr( $name ) : $title;
        }

        if ( mad_quotes_cart_contains_quotable() && wc_get_page_id( 'checkout' ) === $id ) {
            $name  = trim( $settings['checkout_page_name'] ?? '' );
            $title = $name !== '' ? esc_attr( $name ) : $title;
        }

        return $title;
    }

    /* ================================================================ */
    /*  Proceed to checkout button                                        */
    /* ================================================================ */

    public function change_proceed_checkout_btn() {
        if ( ! mad_quotes_cart_contains_quotable() ) return;
        if ( ! apply_filters( 'mad_quotes_modify_checkout_button', true ) ) return;

        remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );

        $settings = mad_quotes_get_settings();
        $label    = trim( $settings['proceed_checkout_btn_label'] ?? '' );
        $label    = $label !== '' ? $label : __( 'Proceder al checkout', 'mad-suite' );
        ?>
        <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>"
           class="checkout-button button alt wc-forward">
            <?php echo esc_html( $label ); ?>
        </a>
        <?php
    }

    /* ================================================================ */
    /*  Shipping & address fields                                         */
    /* ================================================================ */

    public function cart_needs_shipping( $needs_shipping ) {
        $settings = mad_quotes_get_settings();
        if ( mad_quotes_cart_contains_quotable() && ! empty( $settings['hide_address_fields'] ) ) {
            return false;
        }
        return $needs_shipping;
    }

    public function billing_fields( $fields ) {
        $settings = mad_quotes_get_settings();
        if ( ! mad_quotes_cart_contains_quotable() || empty( $settings['hide_address_fields'] ) ) {
            return $fields;
        }

        $hidden = apply_filters( 'mad_quotes_hidden_billing_fields', [
            'billing_company', 'billing_address_1', 'billing_address_2',
            'billing_state',   'billing_city',       'billing_postcode', 'billing_country',
        ] );

        foreach ( $hidden as $key ) {
            unset( $fields[ $key ] );
        }

        return $fields;
    }

    public function checkout_fields( $fields ) {
        $settings = mad_quotes_get_settings();
        if ( ! mad_quotes_cart_contains_quotable() || empty( $settings['hide_address_fields'] ) ) {
            return $fields;
        }

        $hidden = apply_filters( 'mad_quotes_hidden_billing_fields', [
            'billing_company', 'billing_address_1', 'billing_address_2',
            'billing_state',   'billing_city',       'billing_postcode', 'billing_country',
        ] );

        foreach ( $hidden as $key ) {
            unset( $fields['billing'][ $key ] );
        }

        return $fields;
    }

    /* ================================================================ */
    /*  Product-level meta box                                            */
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
        global $post;
        $product_id    = $post->ID;
        $enable_quotes = get_post_meta( $product_id, 'mad_quotes_enable', true );
        $show_price    = get_post_meta( $product_id, 'mad_quotes_display_price', true );
        ?>
        <div id="mad_quotes_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label for="mad_quotes_enable">
                        <?php esc_html_e( 'Habilitar cotización en este producto', 'mad-suite' ); ?>
                    </label>
                    <select id="mad_quotes_enable" name="mad_quotes_enable">
                        <option value="" <?php selected( $enable_quotes, '' ); ?>><?php esc_html_e( '— Usar ajuste global —', 'mad-suite' ); ?></option>
                        <option value="on"  <?php selected( $enable_quotes, 'on' ); ?>><?php esc_html_e( 'Sí', 'mad-suite' ); ?></option>
                        <option value="off" <?php selected( $enable_quotes, 'off' ); ?>><?php esc_html_e( 'No', 'mad-suite' ); ?></option>
                    </select>
                </p>
                <p class="form-field">
                    <label for="mad_quotes_display_price">
                        <?php esc_html_e( 'Mostrar precio aunque sea cotizable', 'mad-suite' ); ?>
                    </label>
                    <select id="mad_quotes_display_price" name="mad_quotes_display_price">
                        <option value="" <?php selected( $show_price, '' ); ?>><?php esc_html_e( '— Usar ajuste global —', 'mad-suite' ); ?></option>
                        <option value="on"  <?php selected( $show_price, 'on' ); ?>><?php esc_html_e( 'Sí', 'mad-suite' ); ?></option>
                        <option value="off" <?php selected( $show_price, 'off' ); ?>><?php esc_html_e( 'No', 'mad-suite' ); ?></option>
                    </select>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_product_meta( $post_id ) {
        $enable = isset( $_POST['mad_quotes_enable'] ) ? sanitize_text_field( wp_unslash( $_POST['mad_quotes_enable'] ) ) : '';
        $price  = isset( $_POST['mad_quotes_display_price'] ) ? sanitize_text_field( wp_unslash( $_POST['mad_quotes_display_price'] ) ) : '';

        update_post_meta( $post_id, 'mad_quotes_enable', $enable );
        update_post_meta( $post_id, 'mad_quotes_display_price', $price );
    }

    /* ================================================================ */
    /*  Quote expiry cron                                                 */
    /* ================================================================ */

    public function expire_old_quotes() {
        $settings = mad_quotes_get_settings();
        $days     = absint( $settings['quote_expiry_days'] ?? 0 );
        if ( $days < 1 ) return;

        $cutoff = strtotime( "-{$days} days" );

        $orders = wc_get_orders( [
            'status'       => 'pending',
            'meta_key'     => '_mad_quote_status',
            'meta_value'   => 'quote-pending',
            'date_created' => '<' . $cutoff,
            'limit'        => -1,
        ] );

        foreach ( $orders as $order ) {
            $order->update_meta_data( '_mad_quote_status', 'quote-cancelled' );
            $order->add_order_note( __( 'Presupuesto caducado automáticamente.', 'mad-suite' ) );
            $order->save();
        }
    }

    /* ================================================================ */
    /*  Private helpers                                                   */
    /* ================================================================ */

    private function update_quote_status( $order_id, $status ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $old = $order->get_meta( '_mad_quote_status' );
        $order->update_meta_data( '_mad_quote_status', $status );
        /* translators: 1: old status, 2: new status */
        $order->add_order_note( sprintf( __( 'Estado del presupuesto: %1$s → %2$s', 'mad-suite' ), $old, $status ) );
        $order->save();
    }

    private function send_quote_email( $order_id, $admin_note = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return false;

        WC_Emails::instance();
        do_action( 'mad_quotes_send_quote_notification', $order_id, $admin_note );

        $this->update_quote_status( $order_id, 'quote-sent' );
        $order->add_order_note(
            sprintf(
                /* translators: email address */
                __( 'Presupuesto enviado a %s.', 'mad-suite' ),
                $order->get_billing_email()
            )
        );
        $order->save();

        return true;
    }

    /**
     * Detect the current order ID from the admin screen (HPOS-compatible).
     *
     * @return int|null
     */
    private function get_current_order_id() {
        // HPOS: ?page=wc-orders&id=123
        if ( isset( $_GET['page'], $_GET['id'] ) && 'wc-orders' === $_GET['page'] && $_GET['id'] > 0 ) { //phpcs:ignore WordPress.Security.NonceVerification
            return absint( $_GET['id'] );
        }

        // Legacy post-based orders.
        global $post;
        if ( isset( $post->post_type ) && 'shop_order' === $post->post_type ) {
            return $post->ID;
        }

        return null;
    }

    /* ================================================================ */
    /*  Settings field helpers                                            */
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

    public function field_checkbox( $args ) {
        $settings = mad_quotes_get_settings();
        $key      = $args['key'];
        $opt_key  = MAD_Suite_Core::option_key( $this->slug );
        $checked  = ! empty( $settings[ $key ] );
        printf(
            '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
            esc_attr( $opt_key ),
            esc_attr( $key ),
            checked( $checked, true, false ),
            esc_html( $args['desc'] ?? '' )
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

    public function sanitize_settings( $input ) {
        $clean = [];

        $booleans = [ 'enable_global_quote', 'enable_global_prices', 'hide_address_fields' ];
        foreach ( $booleans as $key ) {
            $clean[ $key ] = ! empty( $input[ $key ] );
        }

        $texts = [
            'add_to_cart_button_text', 'place_order_text', 'cart_page_name',
            'checkout_page_name', 'proceed_checkout_btn_label',
        ];
        foreach ( $texts as $key ) {
            $clean[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
        }

        $clean['quote_expiry_days'] = absint( $input['quote_expiry_days'] ?? 0 );

        return $clean;
    }
};
