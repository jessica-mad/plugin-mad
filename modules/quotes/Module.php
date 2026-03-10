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
        add_action( 'admin_head', [ $this, 'order_status_css' ] );

        // ── Rol: cachear precio HTML antes de que el plugin original lo modifique ──
        add_filter( 'woocommerce_get_price_html',      [ $this, 'cache_original_price' ], 1, 2 );
        add_filter( 'woocommerce_variable_price_html', [ $this, 'cache_original_price' ], 1, 2 );

        // ── Rol: restaurar precio/botón para roles no habilitados (prioridad 999 para sobreescribir al plugin original) ──
        add_filter( 'woocommerce_get_price_html',                  [ $this, 'maybe_restore_price' ],  999, 2 );
        add_filter( 'woocommerce_variable_price_html',             [ $this, 'maybe_restore_price' ],  999, 2 );
        add_filter( 'woocommerce_product_add_to_cart_text',        [ $this, 'maybe_restore_button' ], 999 );
        add_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'maybe_restore_button' ], 999 );

        // ── Rol: filtros nativos del plugin original (si los expone) ─────────────
        add_filter( 'qwc_hide_price_html',     [ $this, 'filter_by_role' ] );
        add_filter( 'qwc_disable_add_to_cart', [ $this, 'filter_by_role' ] );

        // ── Rol: quitar gateway de presupuesto para usuarios sin rol habilitado ──
        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_quote_gateway' ], 999 );

        // ── Carrito: ocultar precios de línea y totales ───────────────
        add_filter( 'woocommerce_cart_item_price',    [ $this, 'hide_cart_item_price' ], 10, 2 );
        add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'hide_cart_item_price' ], 10, 2 );
        add_action( 'wp_head', [ $this, 'inject_cart_css' ] );

        // ── Checkout: simplificar campos y deshabilitar envío ─────────
        add_filter( 'woocommerce_checkout_fields',     [ $this, 'simplify_quote_checkout_fields' ], 9999 );
        add_filter( 'woocommerce_cart_needs_shipping', [ $this, 'no_shipping_for_quotes' ] );

        // ── Ciclo de vida del pedido ───────────────────────────────────
        add_action( 'woocommerce_checkout_update_order_meta',   [ $this, 'save_quote_order_meta' ] );
        // Forzar estado "Presupuesto pendiente" DESPUÉS de que el gateway llame a process_payment()
        add_action( 'woocommerce_checkout_order_processed',     [ $this, 'finalize_quote_order_status' ], 999, 1 );
        add_filter( 'woocommerce_can_reduce_order_stock',       [ $this, 'prevent_stock_reduction' ], 10, 2 );
        add_filter( 'woocommerce_cancel_unpaid_order',          [ $this, 'prevent_cancel' ], 10, 2 );
        add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'my_orders_actions' ], 10, 2 );

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
        require_once MAD_QUOTES_DIR . 'includes/emails/class-mad-quotes-send-quote.php';
        $email_classes['MAD_Quotes_Email_Send_Quote'] = new MAD_Quotes_Email_Send_Quote();
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

    public function maybe_restore_button( $text ) {
        if ( ! $this->current_user_is_quote_role() ) {
            return __( 'Añadir al carrito', 'woocommerce' );
        }
        return $text;
    }

    public function filter_by_role( $value ) {
        if ( ! $this->current_user_is_quote_role() ) {
            return false;
        }
        return $value;
    }

    /* ================================================================ */
    /*  Carrito: detección y ocultación de precios                       */
    /* ================================================================ */

    /**
     * Devuelve true si el carrito contiene artículos del plugin original de presupuestos.
     * El plugin "Quotes for WooCommerce" usa el meta 'qwc_quote_status' = 'on' en el producto.
     */
    private function cart_contains_quote_items(): bool {
        if ( ! isset( WC()->cart ) || is_null( WC()->cart ) ) return false;

        foreach ( WC()->cart->get_cart() as $item ) {
            if ( get_post_meta( $item['product_id'], 'qwc_quote_status', true ) === 'on' ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True si el usuario tiene rol de presupuesto Y el carrito tiene artículos de presupuesto.
     */
    private function cart_is_quote_experience(): bool {
        return $this->current_user_is_quote_role() && $this->cart_contains_quote_items();
    }

    /**
     * Oculta el precio individual de cada línea del carrito en experiencia de presupuesto.
     */
    public function hide_cart_item_price( $price, $cart_item ) {
        if ( $this->cart_is_quote_experience() ) {
            return '—';
        }
        return $price;
    }

    /**
     * Inyecta CSS en carrito/checkout para ocultar subtotales, taxes y total.
     */
    public function inject_cart_css() {
        if ( ! is_cart() && ! is_checkout() ) return;
        if ( ! $this->cart_is_quote_experience() ) return;

        echo '<style>
            /* MAD Quotes: ocultar importes en carrito y checkout */
            .cart-subtotal,
            .shipping,
            .tax-total,
            .order-total,
            .woocommerce-checkout-review-order-table tfoot {
                display: none !important;
            }
        </style>';
    }

    /* ================================================================ */
    /*  Checkout: simplificar campos                                     */
    /* ================================================================ */

    /**
     * En experiencia de presupuesto solo pedimos nombre, apellido y email.
     */
    public function simplify_quote_checkout_fields( $fields ) {
        if ( ! $this->cart_is_quote_experience() ) return $fields;

        $keep = [ 'billing_first_name', 'billing_last_name', 'billing_email' ];

        foreach ( array_keys( $fields['billing'] ?? [] ) as $key ) {
            if ( ! in_array( $key, $keep, true ) ) {
                unset( $fields['billing'][ $key ] );
            }
        }

        $fields['shipping'] = [];
        unset( $fields['order'] );

        return $fields;
    }

    public function no_shipping_for_quotes( $needs_shipping ) {
        if ( $this->cart_is_quote_experience() ) return false;
        return $needs_shipping;
    }

    /* ================================================================ */
    /*  Ciclo de vida del pedido                                         */
    /* ================================================================ */

    /**
     * Fuerza el estado "Presupuesto pendiente" después de que el gateway haya procesado el pago.
     * Se ejecuta con prioridad 999 en woocommerce_checkout_order_processed para sobreescribir
     * cualquier cambio de estado que el gateway quotes-wc haga en process_payment().
     */
    public function finalize_quote_order_status( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( $order->get_payment_method() !== 'quotes-wc'
            && '1' !== $order->get_meta( '_mad_qwc_quote' )
        ) {
            return;
        }
        if ( $order->get_status() === 'quote-pending' ) return;
        $order->update_status( 'quote-pending', __( 'Solicitud de presupuesto recibida.', 'mad-suite' ) );
        $order->save();
    }

    /**
     * Impide que WooCommerce reduzca el stock para pedidos de presupuesto.
     */
    public function prevent_stock_reduction( $can_reduce, $order ) {
        if ( in_array( $order->get_status(), [ 'quote-pending', 'quote-sent' ], true )
            || '1' === $order->get_meta( '_mad_qwc_quote' )
        ) {
            return false;
        }
        return $can_reduce;
    }

    /**
     * Oculta el gateway "quotes-wc" para usuarios sin rol de presupuesto habilitado.
     */
    public function filter_quote_gateway( $gateways ) {
        if ( ! $this->current_user_is_quote_role() ) {
            unset( $gateways['quotes-wc'] );
        }
        return $gateways;
    }

    public function prevent_cancel( $return, $order ) {
        $status = $order->get_status();
        if ( in_array( $status, [ 'quote-pending', 'quote-sent' ], true )
            || '1' === $order->get_meta( '_mad_qwc_quote' )
            || $order->get_payment_method() === 'quotes-wc'
        ) {
            return false;
        }
        return $return;
    }

    public function save_quote_order_meta( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( $order->get_payment_method() !== 'quotes-wc' ) return;

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
        if ( $order->get_payment_method() !== 'quotes-wc' && ! $order->get_meta( '_mad_qwc_quote' ) ) {
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

        wp_die();
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
        echo $result ? 'quote-sent' : 'error';
        wp_die();
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

    public function sanitize_settings( $input ) {
        $clean = [];

        $clean['quote_roles'] = isset( $input['quote_roles'] )
            ? array_values( array_map( 'sanitize_text_field', (array) $input['quote_roles'] ) )
            : [];

        $clean['quote_expiry_days'] = absint( $input['quote_expiry_days'] ?? 0 );

        return $clean;
    }
};
