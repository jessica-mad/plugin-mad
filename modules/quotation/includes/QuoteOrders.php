<?php
/**
 * QuoteOrders — Gestiona los estados de pedido personalizados, el checkout
 * de cotización y la pasarela de pago ficticia para cotizaciones.
 *
 * @package MAD_Suite
 * @subpackage Quotation
 */

namespace MADSuite\Modules\Quotation;

if ( ! defined('ABSPATH') ) exit;

/* ============================================================
 * Pasarela WooCommerce para "Solicitud de Cotización"
 * ============================================================ */

if ( class_exists('WC_Payment_Gateway') && ! class_exists('MADSuite\Modules\Quotation\WC_Gateway_Mad_Quote') ) {

    class WC_Gateway_Mad_Quote extends \WC_Payment_Gateway {

        private static $role_manager_instance = null;

        public static function set_role_manager( RoleManager $rm ) {
            self::$role_manager_instance = $rm;
        }

        public function __construct() {
            $this->id                 = 'mad_quote_request';
            $this->method_title       = __('Solicitud de Cotización (MAD Suite)', 'mad-suite');
            $this->method_description = __('Permite al cliente enviar una solicitud de cotización sin pago inmediato.', 'mad-suite');
            $this->title              = __('Solicitud de Cotización', 'mad-suite');
            $this->description        = __('Tu pedido quedará pendiente de cotización. Recibirás los precios por email.', 'mad-suite');
            $this->has_fields         = false;
            $this->supports           = [ 'products' ];

            $this->init_form_fields();
            $this->init_settings();
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => __('Activar', 'mad-suite'),
                    'type'    => 'checkbox',
                    'label'   => __('Activar pasarela de cotización', 'mad-suite'),
                    'default' => 'yes',
                ],
            ];
        }

        public function is_available(): bool {
            if ( ! parent::is_available() ) return false;
            if ( self::$role_manager_instance && self::$role_manager_instance->is_professional() ) {
                return false;
            }
            return true;
        }

        public function process_payment( $order_id ): array {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wc_add_notice( __('Error al procesar el pedido.', 'mad-suite'), 'error' );
                return [ 'result' => 'failure' ];
            }

            // Marcar como cotización y cambiar estado
            $order->update_meta_data( '_mad_is_quote', '1' );
            $order->update_status( 'wc-quote-request', __('Solicitud de cotización recibida.', 'mad-suite') );
            $order->save();

            // Vaciar carrito
            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];
        }
    }
}

/* ============================================================
 * QuoteOrders
 * ============================================================ */

class QuoteOrders {

    const STATUS_REQUEST = 'wc-quote-request';
    const STATUS_SENT    = 'wc-quote-sent';

    private $role_manager;
    private $module;
    private $logger;

    public function __construct( RoleManager $role_manager, $module, Logger $logger ) {
        $this->role_manager = $role_manager;
        $this->module       = $module;
        $this->logger       = $logger;
    }

    public function init() {
        // Registrar estados de pedido WC directamente (ya estamos en init priority 1)
        $this->register_order_statuses();
        add_filter( 'wc_order_statuses', [ $this, 'add_order_statuses_to_list' ] );

        // Registrar pasarela de cotización
        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );

        // Para usuarios no profesionales: solo mostrar nuestra pasarela de cotización
        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways_for_quote' ] );

        // Cuando WC crea la orden: marcar como cotización si aplica
        add_action( 'woocommerce_checkout_order_created', [ $this, 'mark_order_as_quote' ] );

        // Suprimir emails WC estándar para cotizaciones
        add_filter( 'woocommerce_email_enabled_new_order',                    [ $this, 'suppress_standard_email' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_customer_processing_order',    [ $this, 'suppress_standard_email' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_customer_on_hold_order',       [ $this, 'suppress_standard_email' ], 10, 2 );
        add_filter( 'woocommerce_email_enabled_cancelled_order',              [ $this, 'suppress_standard_email' ], 10, 2 );

        // Disparar nuestros emails de cotización en cambios de estado
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );

        // Registrar clases de email WC
        add_filter( 'woocommerce_email_classes', [ $this, 'register_email_classes' ] );

        // Mostrar estado cotización en "Mi cuenta"
        add_filter( 'woocommerce_my_account_my_orders_column_order-status', '__return_false', 5 );
    }

    public function admin_init() {
        // Botón "Enviar cotización" en pantalla de detalle del pedido
        add_action( 'woocommerce_order_item_add_action_buttons', [ $this, 'render_send_quote_button' ] );

        // AJAX handler para enviar cotización
        add_action( 'wp_ajax_mad_send_quote', [ $this, 'ajax_send_quote' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_admin_scripts' ] );

        // Mostrar nuestros estados en los dropdowns de estado
        add_filter( 'woocommerce_order_is_paid_statuses', [ $this, 'add_paid_statuses' ] );
    }

    /* ===== Registrar estados ===== */

    public function register_order_statuses() {
        register_post_status( 'wc-quote-request', [
            'label'                     => _x('Solicitud de cotización', 'Order status', 'mad-suite'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: count */
            'label_count'               => _n_noop('Solicitud de cotización <span class="count">(%s)</span>', 'Solicitudes de cotización <span class="count">(%s)</span>', 'mad-suite'),
        ] );

        register_post_status( 'wc-quote-sent', [
            'label'                     => _x('Cotización enviada', 'Order status', 'mad-suite'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Cotización enviada <span class="count">(%s)</span>', 'Cotizaciones enviadas <span class="count">(%s)</span>', 'mad-suite'),
        ] );
    }

    public function add_order_statuses_to_list( array $statuses ): array {
        $statuses['wc-quote-request'] = _x('Solicitud de cotización', 'Order status', 'mad-suite');
        $statuses['wc-quote-sent']    = _x('Cotización enviada',       'Order status', 'mad-suite');
        return $statuses;
    }

    /* ===== Pasarela ===== */

    public function register_gateway( array $gateways ): array {
        WC_Gateway_Mad_Quote::set_role_manager( $this->role_manager );
        $gateways[] = WC_Gateway_Mad_Quote::class;
        return $gateways;
    }

    public function filter_gateways_for_quote( array $gateways ): array {
        if ( $this->role_manager->is_professional() ) return $gateways;

        // Solo mostrar nuestra pasarela de cotización
        $quote_gateway = null;
        foreach ( $gateways as $id => $gateway ) {
            if ( $gateway->id === 'mad_quote_request' ) {
                $quote_gateway = $gateway;
                break;
            }
        }

        return $quote_gateway ? [ 'mad_quote_request' => $quote_gateway ] : [];
    }

    /* ===== Marcar orden como cotización ===== */

    public function mark_order_as_quote( \WC_Order $order ) {
        if ( $this->role_manager->is_professional() ) return;
        $order->update_meta_data( '_mad_is_quote', '1' );
        $order->save();
    }

    /* ===== Suprimir emails estándar ===== */

    public function suppress_standard_email( bool $enabled, $order ): bool {
        if ( $order instanceof \WC_Order && $order->get_meta('_mad_is_quote') === '1' ) return false;
        return $enabled;
    }

    /* ===== Cambios de estado → emails de cotización ===== */

    public function on_order_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ) {
        if ( $order->get_meta('_mad_is_quote') !== '1' ) return;

        if ( $new_status === 'quote-request' ) {
            $this->logger->info( "Cotización recibida: pedido #{$order_id}" );
            // El email admin se dispara a través de las clases WC_Email registradas
            do_action( 'mad_quote_request_notification', $order_id );
        }

        if ( $new_status === 'quote-sent' ) {
            $this->logger->info( "Cotización enviada al cliente: pedido #{$order_id}" );
            do_action( 'mad_quote_sent_notification', $order_id );
        }
    }

    /* ===== Registrar clases de email WC ===== */

    public function register_email_classes( array $emails ): array {
        $emails['MAD_Quote_Email_Admin']  = new QuoteEmailAdmin();
        $emails['MAD_Quote_Email_Client'] = new QuoteEmailClient( $this->module );
        return $emails;
    }

    /* ===== Admin: botón "Enviar cotización" ===== */

    public function render_send_quote_button( \WC_Order $order ) {
        if ( $order->get_meta('_mad_is_quote') !== '1' ) return;
        if ( $order->get_status() !== 'quote-request' ) return;

        $nonce = wp_create_nonce('mad_send_quote_' . $order->get_id());
        echo '<button type="button" class="button button-primary" id="mad-send-quote-btn"
              data-order-id="' . esc_attr( $order->get_id() ) . '"
              data-nonce="' . esc_attr( $nonce ) . '">'
             . esc_html__('Enviar cotización al cliente', 'mad-suite')
             . '</button>';
        echo '<span id="mad-send-quote-spinner" class="spinner" style="float:none;"></span>';
    }

    public function enqueue_admin_scripts( string $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
        global $post;
        if ( ! $post || get_post_type( $post ) !== 'shop_order' ) return;

        $order = wc_get_order( $post->ID );
        if ( ! $order || $order->get_meta('_mad_is_quote') !== '1' ) return;

        wp_add_inline_script( 'jquery', "
            jQuery(function($) {
                $('#mad-send-quote-btn').on('click', function() {
                    var btn    = $(this);
                    var spinner = $('#mad-send-quote-spinner');
                    btn.prop('disabled', true);
                    spinner.addClass('is-active');
                    $.post(ajaxurl, {
                        action:   'mad_send_quote',
                        order_id: btn.data('order-id'),
                        nonce:    btn.data('nonce')
                    }, function(res) {
                        spinner.removeClass('is-active');
                        if (res.success) {
                            alert('" . esc_js(__('Cotización enviada correctamente.', 'mad-suite')) . "');
                            location.reload();
                        } else {
                            btn.prop('disabled', false);
                            alert('" . esc_js(__('Error al enviar la cotización.', 'mad-suite')) . "');
                        }
                    });
                });
            });
        " );
    }

    public function ajax_send_quote() {
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $nonce    = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );

        if ( ! wp_verify_nonce( $nonce, 'mad_send_quote_' . $order_id ) || ! current_user_can('manage_woocommerce') ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta('_mad_is_quote') !== '1' ) {
            wp_send_json_error( [ 'message' => 'Invalid order' ] );
        }

        $order->update_status( 'wc-quote-sent', __('Cotización enviada al cliente.', 'mad-suite') );
        $order->save();

        $this->logger->info( "Admin envió cotización para pedido #{$order_id}" );
        wp_send_json_success( [ 'message' => 'Quote sent' ] );
    }

    public function add_paid_statuses( array $statuses ): array {
        // quote-request y quote-sent NO son pagados
        return $statuses;
    }
}
