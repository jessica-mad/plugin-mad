<?php
/**
 * Customer email: quote request confirmation (HTML).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
    printf(
        /* translators: 1: customer first name */
        esc_html__( 'Hola %s, hemos recibido tu solicitud de presupuesto correctamente. Te responderemos lo antes posible.', 'mad-suite' ),
        esc_html( $order->get_billing_first_name() )
    );
?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;margin-bottom:20px;">
    <thead>
        <tr>
            <th align="left" style="padding:9px 12px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Producto', 'mad-suite' ); ?></th>
            <th align="center" style="padding:9px 12px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Cantidad', 'mad-suite' ); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $order->get_items() as $item ) : ?>
        <tr>
            <td style="padding:9px 12px;border:1px solid #e5e5e5;"><?php echo esc_html( $item->get_name() ); ?></td>
            <td align="center" style="padding:9px 12px;border:1px solid #e5e5e5;"><?php echo esc_html( $item->get_quantity() ); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p>
    <?php esc_html_e( 'Pedido #', 'mad-suite' ); ?><?php echo esc_html( $order->get_order_number() ); ?><br>
    <?php esc_html_e( 'Fecha: ', 'mad-suite' ); ?><?php echo esc_html( date_i18n( wc_date_format(), strtotime( $order->get_date_created() ) ) ); ?>
</p>

<?php if ( $additional_content ) : ?>
<div style="margin-top:20px;">
    <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
</div>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email );
