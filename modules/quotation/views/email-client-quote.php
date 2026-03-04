<?php
/**
 * Template: Email al cliente con precios reales y enlace para revisar y pagar.
 *
 * Variables disponibles:
 * @var QuoteEmailClient $email  La instancia del email WC
 * @var WC_Order         $order  (vía $email->object)
 */

if ( ! defined('ABSPATH') ) exit;

$order       = $email->object;
$payment_url = $email->get_payment_review_url( $order );

if ( ! $order instanceof WC_Order ) return;

do_action( 'woocommerce_email_header', $email->get_heading(), $email );
?>

<p>
    <?php
    printf(
        /* translators: %s: customer first name */
        esc_html__('Hola %s,', 'mad-suite'),
        '<strong>' . esc_html( $order->get_billing_first_name() ) . '</strong>'
    );
    ?>
</p>

<p>
    <?php esc_html_e('Hemos revisado tu solicitud de cotización y a continuación encontrarás los precios de los productos que solicitaste. Para proceder a la compra, pulsa el botón al final de este email.', 'mad-suite'); ?>
</p>

<!-- Productos con precios -->
<h2 style="color:#333;font-size:1em;margin-bottom:8px;"><?php esc_html_e('Productos cotizados', 'mad-suite'); ?></h2>
<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:20px;">
    <thead>
        <tr>
            <th style="padding:8px 12px;border:1px solid #e2e2e2;background:#f7f7f7;text-align:left;"><?php esc_html_e('Producto', 'mad-suite'); ?></th>
            <th style="padding:8px 12px;border:1px solid #e2e2e2;background:#f7f7f7;text-align:center;"><?php esc_html_e('Precio unit.', 'mad-suite'); ?></th>
            <th style="padding:8px 12px;border:1px solid #e2e2e2;background:#f7f7f7;text-align:center;"><?php esc_html_e('Cantidad', 'mad-suite'); ?></th>
            <th style="padding:8px 12px;border:1px solid #e2e2e2;background:#f7f7f7;text-align:right;"><?php esc_html_e('Subtotal', 'mad-suite'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ( $order->get_items() as $item ) :
            $product  = $item->get_product();
            $price    = $product ? (float) $product->get_price() : 0;
            $qty      = (int) $item->get_quantity();
            $subtotal = $price * $qty;
        ?>
        <tr>
            <td style="padding:8px 12px;border:1px solid #e2e2e2;">
                <?php echo esc_html( $item->get_name() ); ?>
                <?php if ( $product && $product->get_sku() ) : ?>
                    <small style="color:#888;display:block;">SKU: <?php echo esc_html( $product->get_sku() ); ?></small>
                <?php endif; ?>
            </td>
            <td style="padding:8px 12px;border:1px solid #e2e2e2;text-align:center;"><?php echo wc_price( $price ); ?></td>
            <td style="padding:8px 12px;border:1px solid #e2e2e2;text-align:center;"><?php echo esc_html( $qty ); ?></td>
            <td style="padding:8px 12px;border:1px solid #e2e2e2;text-align:right;"><?php echo wc_price( $subtotal ); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <?php if ( (float) $order->get_shipping_total() > 0 ) : ?>
        <tr>
            <td colspan="3" style="padding:8px 12px;border:1px solid #e2e2e2;text-align:right;"><strong><?php esc_html_e('Envío estimado:', 'mad-suite'); ?></strong></td>
            <td style="padding:8px 12px;border:1px solid #e2e2e2;text-align:right;"><?php echo wc_price( (float) $order->get_shipping_total() ); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td colspan="3" style="padding:8px 12px;border:1px solid #e2e2e2;text-align:right;background:#f7f7f7;"><strong><?php esc_html_e('Total estimado:', 'mad-suite'); ?></strong></td>
            <td style="padding:8px 12px;border:1px solid #e2e2e2;text-align:right;background:#f7f7f7;"><strong><?php echo wc_price( (float) $order->get_cart_tax() + array_sum( array_map( function($item) { $p = $item->get_product(); return $p ? (float)$p->get_price() * $item->get_quantity() : 0; }, $order->get_items() ) ) + (float) $order->get_shipping_total() ); ?></strong></td>
        </tr>
    </tfoot>
</table>

<p style="color:#555;font-size:0.9em;">
    <?php esc_html_e('* Los precios mostrados son orientativos y podrán variar según disponibilidad. Si quieres comprar solo algunos de los productos, podrás seleccionarlos en el siguiente paso.', 'mad-suite'); ?>
</p>

<!-- CTA: Revisar y pagar -->
<table cellpadding="0" cellspacing="0" style="width:100%;margin:28px 0;">
    <tr>
        <td style="text-align:center;">
            <a href="<?php echo esc_url( $payment_url ); ?>"
               style="background:#0073aa;color:#ffffff;padding:14px 32px;text-decoration:none;border-radius:4px;font-size:1.05em;display:inline-block;font-weight:600;">
                <?php esc_html_e('Revisar y proceder al pago', 'mad-suite'); ?>
            </a>
        </td>
    </tr>
</table>

<p style="color:#888;font-size:0.85em;text-align:center;">
    <?php esc_html_e('O copia y pega este enlace en tu navegador:', 'mad-suite'); ?><br />
    <a href="<?php echo esc_url( $payment_url ); ?>" style="color:#0073aa;"><?php echo esc_html( $payment_url ); ?></a>
</p>

<p style="margin-top:20px;">
    <?php esc_html_e('Si tienes alguna pregunta sobre tu cotización, no dudes en contactarnos.', 'mad-suite'); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
