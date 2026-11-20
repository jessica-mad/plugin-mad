<?php
/**
 * Admin email: proof of payment received (HTML).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var WC_Email $email
 * @var string   $ai_result_text
 * @var string   $verdict_label
 * @var string   $order_edit_url
 * @var string   $approve_url
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><strong><?php echo esc_html( $verdict_label ); ?></strong></p>

<p><?php echo wp_kses_post( $body_text ); ?></p>

<p><em><?php echo esc_html( $ai_result_text ); ?></em></p>

<?php do_action( 'woocommerce_email_order_details', $order, true, false, $email ); ?>

<p>
    <?php if ( $approve_url ) : ?>
        <a href="<?php echo esc_url( $approve_url ); ?>" style="display:inline-block;background:#2e7d32;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;margin-right:10px;">
            <?php esc_html_e( 'Aprobar pago', 'mad-suite' ); ?>
        </a>
    <?php endif; ?>
    <a href="<?php echo esc_url( $order_edit_url ); ?>" style="display:inline-block;background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
        <?php esc_html_e( 'Ver pedido', 'mad-suite' ); ?>
    </a>
</p>

<?php if ( $additional_content ) : ?>
<div style="margin-top:20px;">
    <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email );
