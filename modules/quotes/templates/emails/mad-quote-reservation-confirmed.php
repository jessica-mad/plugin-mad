<?php
/**
 * Customer email: reservation confirmed (HTML).
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

<ul><?php echo mad_quotes_order_items_html_list( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></ul>

<p style="text-align:right;font-weight:bold;">
    <?php esc_html_e( 'Total:', 'mad-suite' ); ?> <?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?>
</p>

<h2><?php esc_html_e( 'Datos para la transferencia', 'mad-suite' ); ?></h2>
<table cellspacing="0" cellpadding="6" style="width:100%;max-width:480px;border-collapse:collapse;margin-bottom:16px;">
    <?php foreach ( mad_quotes_bacs_account_details() as $account ) : ?>
        <?php foreach ( [
            'account_name'   => __( 'Titular', 'mad-suite' ),
            'bank_name'      => __( 'Banco', 'mad-suite' ),
            'account_number' => __( 'Cuenta', 'mad-suite' ),
            'iban'           => __( 'IBAN', 'mad-suite' ),
            'bic'            => __( 'BIC/SWIFT', 'mad-suite' ),
        ] as $field => $label ) :
            if ( empty( $account[ $field ] ) ) continue;
        ?>
            <tr>
                <th align="left" style="padding:6px 10px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php echo esc_html( $label ); ?></th>
                <td style="padding:6px 10px;border:1px solid #e5e5e5;"><?php echo esc_html( $account[ $field ] ); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <tr>
        <th align="left" style="padding:6px 10px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Concepto', 'mad-suite' ); ?></th>
        <td style="padding:6px 10px;border:1px solid #e5e5e5;">
            <?php
            printf(
                /* translators: %s: número de pedido */
                esc_html__( 'Pedido #%s', 'mad-suite' ),
                esc_html( $order->get_order_number() )
            );
            ?>
        </td>
    </tr>
</table>

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
