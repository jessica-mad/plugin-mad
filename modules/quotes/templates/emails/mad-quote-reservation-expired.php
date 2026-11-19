<?php
/**
 * Customer email: reservation expired (HTML) — variants A/B/C/D.
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var WC_Email $email
 * @var bool     $is_pro
 * @var string   $attempts_text
 * @var string   $no_attempts_text
 * @var string   $retry_url
 * @var string   $retry_label
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php echo wp_kses_post( $body_text ); ?></p>

<?php if ( $retry_url ) : ?>
    <p><?php echo esc_html( $attempts_text ); ?></p>
    <p>
        <a href="<?php echo esc_url( $retry_url ); ?>" style="display:inline-block;background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
            <?php echo esc_html( $retry_label ); ?>
        </a>
    </p>
<?php else : ?>
    <p><?php echo esc_html( $no_attempts_text ); ?></p>
<?php endif; ?>

<?php if ( $additional_content ) : ?>
<div style="margin-top:20px;">
    <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email );
