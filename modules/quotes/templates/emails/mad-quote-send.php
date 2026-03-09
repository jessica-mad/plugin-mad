<?php
/**
 * Customer email: quote ready (HTML).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var WC_Email $email
 * @var string   $admin_note   Optional note from the admin.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
    printf(
        /* translators: 1: customer first name */
        esc_html__( 'Hola %s, tu presupuesto está listo. Puedes revisarlo a continuación.', 'mad-suite' ),
        esc_html( $order->get_billing_first_name() )
    );
?></p>

<?php if ( ! empty( $admin_note ) ) : ?>
<blockquote style="border-left:4px solid #ddd;margin:12px 0;padding:8px 16px;color:#555;">
    <?php echo nl2br( esc_html( $admin_note ) ); ?>
</blockquote>
<?php endif; ?>

<?php do_action( 'woocommerce_email_order_details', $order, false, false, $email ); ?>

<?php do_action( 'woocommerce_email_order_meta', $order, false, false, $email ); ?>

<p>
    <a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" style="display:inline-block;background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
        <?php esc_html_e( 'Aceptar y pagar', 'mad-suite' ); ?>
    </a>
</p>

<?php do_action( 'woocommerce_email_footer', $email );
