<?php
/**
 * Admin email: new quote request (HTML).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
    printf(
        /* translators: 1: customer name */
        esc_html__( 'Has recibido una nueva solicitud de presupuesto de %s.', 'mad-suite' ),
        esc_html( $order->get_formatted_billing_full_name() )
    );
?></p>

<?php do_action( 'woocommerce_email_order_details', $order, true, false, $email ); ?>

<?php do_action( 'woocommerce_email_order_meta', $order, true, false, $email ); ?>

<?php do_action( 'woocommerce_email_customer_details', $order, true, false, $email ); ?>

<?php do_action( 'woocommerce_email_footer', $email );
