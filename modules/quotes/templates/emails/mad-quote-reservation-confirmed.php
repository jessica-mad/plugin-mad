<?php
/**
 * Customer email: reservation confirmed (HTML).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text   Ya incluye los datos bancarios en el lugar de {datos_bancarios}.
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo wp_kses_post( wpautop( $body_text ) ); ?>

<p>
    <a href="<?php echo esc_url( mad_quotes_order_payment_url( $order ) ); ?>" style="display:inline-block;background:#0073aa;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;font-weight:bold;">
        <?php esc_html_e( 'Adjuntar comprobante', 'mad-suite' ); ?>
    </a>
</p>

<?php if ( $additional_content ) : ?>
<div style="margin-top:20px;">
    <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email );
