<?php
/**
 * Customer email: the piece is available again (HTML).
 *
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var WC_Email $email
 * @var string   $piece_url
 * @var string   $piece_name
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo wp_kses_post( wpautop( $body_text ) ); ?>

<?php if ( $piece_url ) : ?>
<p style="text-align:center;margin:20px 0;">
    <a href="<?php echo esc_url( $piece_url ); ?>" style="display:inline-block;background:#0073aa;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;font-weight:bold;">
        <?php esc_html_e( 'Ver la pieza', 'mad-suite' ); ?>
    </a>
</p>
<?php endif; ?>

<?php if ( $additional_content ) : ?>
<div style="margin-top:20px;">
    <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email );
