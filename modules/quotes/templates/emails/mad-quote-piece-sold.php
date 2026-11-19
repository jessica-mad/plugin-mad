<?php
/**
 * Customer email: a piece was sold (HTML).
 *
 * @var WC_Order $order Es el presupuesto (quote), no el pedido de compra.
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var WC_Email $email
 * @var string[] $available_names
 * @var string   $selection_url
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php echo wp_kses_post( $body_text ); ?></p>

<?php if ( ! empty( $available_names ) ) : ?>
    <p><?php esc_html_e( 'El resto de tu lista de precios sigue disponible:', 'mad-suite' ); ?></p>
    <ul>
        <?php foreach ( $available_names as $name ) : ?>
            <li><?php echo esc_html( $name ); ?></li>
        <?php endforeach; ?>
    </ul>
    <p>
        <a href="<?php echo esc_url( $selection_url ); ?>" style="display:inline-block;background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
            <?php esc_html_e( 'Ver mi lista de precios', 'mad-suite' ); ?>
        </a>
    </p>
<?php endif; ?>

<?php if ( $additional_content ) : ?>
<div style="margin-top:20px;">
    <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email );
