<?php
/**
 * Customer email: quote request confirmation (HTML).
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
        /* translators: 1: customer first name */
        esc_html__( 'Hola %s, hemos recibido tu solicitud de presupuesto correctamente. Te responderemos lo antes posible.', 'mad-suite' ),
        esc_html( $order->get_billing_first_name() )
    );
?></p>

<?php do_action( 'woocommerce_email_order_details', $order, false, false, $email ); ?>

<?php do_action( 'woocommerce_email_order_meta', $order, false, false, $email ); ?>

<?php do_action( 'woocommerce_email_footer', $email );
