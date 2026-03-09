<?php
/**
 * Módulo: MAD Quotes
 *
 * Extensión del plugin "Quotes for WooCommerce".
 * Delega al plugin original el gateway de pago, los emails básicos y la gestión
 * del carrito; MAD añade:
 *  - Control de acceso por rol de usuario.
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
        return __( 'Extensión del plugin "Quotes for WooCommerce". Añade control por rol, precios de presupuesto por producto, email con precios/nota y edición/reenvío desde el pedido.', 'mad-suite' );
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
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! is_plugin_active( 'quotes-wc/quotes-wc.php' ) ) {
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

        // ── Rol: cachear precio HTML antes de que el plugin original lo modifique ──
        add_filter( 'woocommerce_get_price_html',      [ $this, 'cache_original_price' ], 1, 2 );
        add_filter( 'woocommerce_variable_price_html', [ $this, 'cache_original_price' ], 1, 2 );

        // ── Rol: restaurar precio/botón para roles no habilitados (prioridad 20) ──
        add_filter( 'woocommerce_get_price_html',                  [ $this, 'maybe_restore_price' ],  20, 2 );
        add_filter( 'woocommerce_variable_price_html',             [ $this, 'maybe_restore_price' ],  20, 2 );
        add_filter( 'woocommerce_product_add_to_cart_text',        [ $this, 'maybe_restore_button' ], 20 );
        add_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'maybe_restore_button' ], 20 );

        // ── Rol: filtros nativos del plugin original (si los expone) ─────────────
        add_filter( 'qwc_hide_price_html',     [ $this, 'filter_by_role' ] );
        add_filter( 'qwc_disable_add_to_cart', [ $this, 'filter_by_role' ] );

        // ── Ciclo de vida del pedido ───────────────────────────────────
        add_action( 'woocommerce_checkout_update_order_meta',   [ $this, 'save_quote_order_meta' ] );
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

    /**
     * Devuelve true si el usuario actual tiene un rol habilitado para presupuestos.
     * Si no hay ningún rol seleccionado en ajustes, todos ven presupuestos.
     */
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

    /**
     * Cachea el precio HTML original (prioridad 1, antes de cualquier plugin).
     */
    public function cache_original_price( $price, $product ) {
        $this->price_cache[ $product->get_id() ] = $price;
        return $price;
    }

    /**
     * Para usuarios sin rol de presupuesto, restaura el precio HTML original.
     */
    public function maybe_restore_price( $price, $product ) {
        if ( ! $this->current_user_is_quote_role() ) {
            return $this->price_cache[ $product->get_id() ] ?? $price;
        }
        return $price;
    }

    /**
     * Para usuarios sin rol de presupuesto, restaura el texto del botón "Añadir al carrito".
     */
    public function maybe_restore_button( $text ) {
        if ( ! $this->current_user_is_quote_role() ) {
            return __( 'Añadir al carrito', 'woocommerce' );
        }
        return $text;
    }

    /**
     * Devuelve false si el usuario no tiene rol de presupuesto.
     * Útil para filtros nativos del plugin original (qwc_hide_price_html, qwc_disable_add_to_cart…).
     */
    public function filter_by_role( $value ) {
        if ( ! $this->current_user_is_quote_role() ) {
            return false;
        }
        return $value;
    }

    /* ================================================================ */
    /*  Ciclo de vida del pedido                                         */
    /* ================================================================ */

    public function prevent_cancel( $return, $order ) {
        if ( '1' === $order->get_meta( '_mad_qwc_quote' ) || $order->get_payment_method() === 'quotes-wc' ) {
            return false;
        }
        return $return;
    }

    public function save_quote_order_meta( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // El plugin original usa el gateway con ID 'quotes-wc'
        if ( $order->get_payment_method() === 'quotes-wc' ) {
            $order->update_meta_data( '_mad_quote_status', 'quote-pending' );
            $order->update_meta_data( '_mad_qwc_quote', '1' );
            $order->save();
        }
    }

    public function my_orders_actions( $actions, $order ) {
        if ( $order->has_status( 'pending' ) && $order->get_payment_method() === 'quotes-wc' ) {
            $quote_status = $order->get_meta( '_mad_quote_status' );
            if ( in_array( $quote_status, [ 'quote-pending', 'quote-cancelled' ], true ) ) {
                unset( $actions['pay'] );
            }
        }
        return $actions;
    }

    /* ================================================================ */
    /*  Admin: botones y tabla de precios en el pedido                   */
    /* ================================================================ */

    public function add_order_buttons( $order ) {
        // Solo actúa sobre pedidos de presupuesto del plugin original
        if ( $order->get_payment_method() !== 'quotes-wc' && ! $order->get_meta( '_mad_qwc_quote' ) ) {
            return;
        }

        $order_status     = $order->get_status();
        $allowed_statuses = apply_filters( 'mad_quotes_allowed_statuses_for_buttons', [ 'pending' ] );
        if ( ! in_array( $order_status, $allowed_statuses, true ) ) return;

        $quote_status = $order->get_meta( '_mad_quote_status' ) ?: 'quote-pending';

        if ( 'quote-pending' === $quote_status ) {
            ?>
            <button id="mad_quote_complete" type="button" class="button">
                <?php esc_html_e( 'Presupuesto completo', 'mad-suite' ); ?>
            </button>
            <?php
        } else {
            // quote-complete o quote-sent: mostrar tabla editable + botón enviar
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
            $this->update_quote_status( $order_id, $status );
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->add_order_note( __( 'Presupuesto marcado como completo.', 'mad-suite' ) );
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

        // Guardar precios editados en cada línea del pedido
        if ( ! empty( $line_prices ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                foreach ( $line_prices as $item_id => $price ) {
                    $item = $order->get_item( absint( $item_id ) );
                    if ( $item ) {
                        $item->update_meta_data( '_mad_quote_line_price', wc_format_decimal( sanitize_text_field( (string) $price ) ) );
                        $item->save();
                    }
                }
            }
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
        global $post;
        $product_id  = $post->ID;
        $quote_price = get_post_meta( $product_id, '_mad_quote_price', true );
        ?>
        <div id="mad_quotes_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label for="mad_quote_price">
                        <?php esc_html_e( 'Precio del presupuesto', 'mad-suite' ); ?>
                    </label>
                    <input type="text"
                           id="mad_quote_price"
                           name="mad_quote_price"
                           value="<?php echo esc_attr( $quote_price ); ?>"
                           class="short wc_input_price"
                           placeholder="<?php esc_attr_e( 'Dejar vacío para usar el precio regular', 'mad-suite' ); ?>">
                    <span class="description">
                        <?php esc_html_e( 'Precio que verá el cliente cuando el admin envíe el presupuesto. Si se deja vacío se usa el precio regular del producto.', 'mad-suite' ); ?>
                    </span>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_product_meta( $post_id ) {
        $quote_price = isset( $_POST['mad_quote_price'] )
            ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['mad_quote_price'] ) ) )
            : '';
        update_post_meta( $post_id, '_mad_quote_price', $quote_price );
    }

    /* ================================================================ */
    /*  Helpers privados                                                  */
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

        // Opción: visitantes no registrados
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
