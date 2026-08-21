<?php
/**
 * Admin email: new quote request (HTML).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php echo wp_kses_post( $body_text ); ?></p>

<?php do_action( 'woocommerce_email_order_details', $order, true, false, $email ); ?>

<?php do_action( 'woocommerce_email_order_meta', $order, true, false, $email ); ?>

<?php do_action( 'woocommerce_email_customer_details', $order, true, false, $email ); ?>

<?php if ( $additional_content ) : ?>
<div style="margin-top:20px;">
    <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email );
