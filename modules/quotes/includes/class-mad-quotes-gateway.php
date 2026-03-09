<?php
/**
 * WooCommerce Payment Gateway – Quotes.
 *
 * This gateway intercepts the checkout for quotable carts so that no real
 * payment is collected. The order is placed in "pending" status until the
 * admin reviews and sends the quote.
 *
 * @package MAD_Suite/Quotes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class MAD_Quotes_Payment_Gateway
 */
class MAD_Quotes_Payment_Gateway extends WC_Payment_Gateway {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'mad-quotes-gateway';
        $this->method_title       = __( 'MAD Quotes', 'mad-suite' );
        $this->method_description = __( 'Gateway used internally for quote requests. Customers do not pay; the admin sends a quote later.', 'mad-suite' );
        $this->has_fields         = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'Request a Quote', 'mad-suite' ) );
        $this->description = $this->get_option( 'description', '' );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_settings' ] );
    }

    /**
     * Admin settings fields.
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable', 'mad-suite' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable MAD Quotes gateway', 'mad-suite' ),
                'default' => 'yes',
            ],
            'title' => [
                'title'   => __( 'Title', 'mad-suite' ),
                'type'    => 'text',
                'default' => __( 'Request a Quote', 'mad-suite' ),
            ],
            'description' => [
                'title'   => __( 'Description', 'mad-suite' ),
                'type'    => 'textarea',
                'default' => '',
            ],
        ];
    }

    /**
     * Process settings (save).
     */
    public function process_settings() {
        $this->process_admin_options();
    }

    /**
     * Process the payment: place the order without collecting money.
     *
     * @param  int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [ 'result' => 'failure' ];
        }

        // Mark as pending (not paid – awaiting quote).
        $order->update_status( 'pending', __( 'Quote request received. Awaiting admin review.', 'mad-suite' ) );

        // Empty the cart.
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }

    /**
     * Override the "Place order" button label on checkout.
     *
     * @return string
     */
    public function order_button_text() {
        $settings = mad_quotes_get_settings();
        $text     = trim( $settings['place_order_text'] ?? '' );
        return $text !== '' ? $text : __( 'Request Quote', 'mad-suite' );
    }
}
