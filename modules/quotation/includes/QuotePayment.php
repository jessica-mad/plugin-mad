<?php
/**
 * QuotePayment — Shortcode [mad_quote_payment] que muestra al cliente los
 * items de su cotización (con checkboxes), permite seleccionarlos y pagar.
 *
 * @package MAD_Suite
 * @subpackage Quotation
 */

namespace MADSuite\Modules\Quotation;

if ( ! defined('ABSPATH') ) exit;

class QuotePayment {

    private $role_manager;
    private $module;
    private $logger;

    public function __construct( RoleManager $role_manager, $module, Logger $logger ) {
        $this->role_manager = $role_manager;
        $this->module       = $module;
        $this->logger       = $logger;
    }

    public function init() {
        add_action( 'wp_ajax_mad_quote_confirm',        [ $this, 'ajax_confirm_payment' ] );
        add_action( 'wp_ajax_nopriv_mad_quote_confirm', [ $this, 'ajax_confirm_payment' ] );
    }

    /* ===== Shortcode ===== */

    public function render( array $atts = [] ): string {
        $order_id  = absint( $_GET['order_id']  ?? 0 );
        $order_key = sanitize_text_field( wp_unslash( $_GET['order_key'] ?? '' ) );

        if ( ! $order_id || ! $order_key ) {
            return '<p class="mad-quote-error">' . esc_html__('Enlace de cotización inválido.', 'mad-suite') . '</p>';
        }

        $order = wc_get_order( $order_id );

        if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
            return '<p class="mad-quote-error">' . esc_html__('No se encontró la cotización. El enlace puede haber expirado.', 'mad-suite') . '</p>';
        }

        if ( $order->get_meta('_mad_is_quote') !== '1' ) {
            return '<p class="mad-quote-error">' . esc_html__('Este pedido no corresponde a una cotización.', 'mad-suite') . '</p>';
        }

        $status = $order->get_status();

        if ( $status === 'quote-request' ) {
            return '<p class="mad-quote-pending">' . esc_html__('Tu cotización está siendo revisada. Recibirás un email con los precios en breve.', 'mad-suite') . '</p>';
        }

        if ( ! in_array( $status, [ 'quote-sent', 'pending' ], true ) ) {
            return '<p class="mad-quote-info">' . esc_html__('Esta cotización ya ha sido procesada o ha expirado.', 'mad-suite') . '</p>';
        }

        ob_start();
        $module_ref = $this->module;
        include dirname( __DIR__ ) . '/views/quote-payment.php';
        return ob_get_clean();
    }

    /* ===== AJAX: confirmar selección y redirigir a pago ===== */

    public function ajax_confirm_payment() {
        // Validar nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mad_quote_confirm' ) ) {
            wp_send_json_error( [ 'message' => __('Nonce inválido.', 'mad-suite') ] );
        }

        $order_id  = absint( $_POST['order_id']  ?? 0 );
        $order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
        $selected  = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? array_map( 'absint', $_POST['items'] ) : [];
        $quantities = isset( $_POST['quantities'] ) && is_array( $_POST['quantities'] ) ? $_POST['quantities'] : [];

        if ( ! $order_id || ! $order_key ) {
            wp_send_json_error( [ 'message' => __('Datos inválidos.', 'mad-suite') ] );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
            wp_send_json_error( [ 'message' => __('Pedido no encontrado.', 'mad-suite') ] );
        }

        if ( $order->get_meta('_mad_is_quote') !== '1' ) {
            wp_send_json_error( [ 'message' => __('Pedido no válido.', 'mad-suite') ] );
        }

        if ( empty( $selected ) ) {
            wp_send_json_error( [ 'message' => __('Debes seleccionar al menos un producto.', 'mad-suite') ] );
        }

        // Actualizar items del pedido
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! in_array( $item_id, $selected, true ) ) {
                // Item no seleccionado → eliminar
                $order->remove_item( $item_id );
            } else {
                // Actualizar cantidad si se envió
                $qty_key = 'qty_' . $item_id;
                if ( isset( $quantities[ $qty_key ] ) ) {
                    $new_qty = max( 1, absint( $quantities[ $qty_key ] ) );
                    $item->set_quantity( $new_qty );
                    $item->calculate_taxes();
                    $item->save();
                }
            }
        }

        // Recalcular totales y cambiar estado a "pending" para habilitar pago
        $order->calculate_totals();
        $order->update_status( 'pending', __('Cliente revisó y aprobó la cotización.', 'mad-suite') );
        $order->save();

        $this->logger->info( "Cliente aprobó cotización para pedido #{$order_id}", [
            'selected_items' => $selected,
        ] );

        wp_send_json_success( [
            'redirect' => $order->get_checkout_payment_url(),
        ] );
    }
}
