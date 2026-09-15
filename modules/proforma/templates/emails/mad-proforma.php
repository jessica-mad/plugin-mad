<?php
/**
 * Customer email: factura proforma (HTML).
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

<p style="background:#fff8e1;border-left:4px solid #f0ad4e;padding:10px 14px;margin:16px 0;">
    <?php esc_html_e( 'Este documento es una factura proforma, no una factura fiscal válida. La factura definitiva se emite una vez confirmado el pago.', 'mad-suite' ); ?>
</p>

<?php if ( $additional_content ) : ?>
<div style="margin-top:20px;">
    <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email );
