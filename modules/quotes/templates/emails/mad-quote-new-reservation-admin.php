<?php
/**
 * Admin email: new reservation (HTML).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var WC_Email $email
 * @var string   $deadline
 * @var string   $order_edit_url
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php echo wp_kses_post( $body_text ); ?></p>

<?php do_action( 'woocommerce_email_order_details', $order, true, false, $email ); ?>
<?php do_action( 'woocommerce_email_customer_details', $order, true, false, $email ); ?>

<p><a href="<?php echo esc_url( $order_edit_url ); ?>"><?php esc_html_e( 'Ver pedido', 'mad-suite' ); ?></a></p>

<?php if ( $additional_content ) : ?>
<div style="margin-top:20px;">
    <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email );
