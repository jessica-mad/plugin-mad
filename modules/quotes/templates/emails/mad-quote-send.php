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

<!-- Tabla de artículos con precios de presupuesto -->
<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;margin-bottom:20px;">
    <thead>
        <tr>
            <th align="left" style="padding:9px 12px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Producto', 'mad-suite' ); ?></th>
            <th align="center" style="padding:9px 12px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Cantidad', 'mad-suite' ); ?></th>
            <th align="right" style="padding:9px 12px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Precio', 'mad-suite' ); ?></th>
            <th align="right" style="padding:9px 12px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Total', 'mad-suite' ); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php
    $grand_total = 0.0;
    foreach ( $order->get_items() as $item_id => $item ) :
        $product_id  = $item->get_product_id();
        $qty         = $item->get_quantity();

        // Precio de presupuesto: usa el guardado en el pedido (si fue editado) o el del producto
        $saved_price = $item->get_meta( '_mad_quote_line_price' );
        $unit_price  = ( $saved_price !== '' && false !== $saved_price )
            ? (float) $saved_price
            : mad_quotes_get_product_quote_price( $product_id );
        $line_total  = $unit_price * $qty;
        $grand_total += $line_total;
    ?>
        <tr>
            <td style="padding:9px 12px;border:1px solid #e5e5e5;"><?php echo esc_html( $item->get_name() ); ?></td>
            <td align="center" style="padding:9px 12px;border:1px solid #e5e5e5;"><?php echo esc_html( $qty ); ?></td>
            <td align="right" style="padding:9px 12px;border:1px solid #e5e5e5;"><?php echo wp_kses_post( wc_price( $unit_price ) ); ?></td>
            <td align="right" style="padding:9px 12px;border:1px solid #e5e5e5;"><?php echo wp_kses_post( wc_price( $line_total ) ); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <th colspan="3" align="right" style="padding:9px 12px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Total del presupuesto:', 'mad-suite' ); ?></th>
            <td align="right" style="padding:9px 12px;border:1px solid #e5e5e5;font-weight:bold;"><?php echo wp_kses_post( wc_price( $grand_total ) ); ?></td>
        </tr>
    </tfoot>
</table>

<p>
    <a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" style="display:inline-block;background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
        <?php esc_html_e( 'Aceptar y pagar', 'mad-suite' ); ?>
    </a>
</p>

<?php do_action( 'woocommerce_email_footer', $email );
