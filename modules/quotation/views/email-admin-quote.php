<?php
/**
 * Template: Email informativo al administrador — Nueva solicitud de cotización.
 * NO incluye precios.
 *
 * Variables disponibles:
 * @var QuoteEmailAdmin $email  La instancia del email WC
 * @var WC_Order        $order  (vía $email->object)
 */

if ( ! defined('ABSPATH') ) exit;

$order = $email->object;
if ( ! $order instanceof WC_Order ) return;

// Usar el sistema de emails de WooCommerce para el envoltorio HTML
do_action( 'woocommerce_email_header', $email->get_heading(), $email );
?>

<p>
    <?php
    printf(
        /* translators: %s: customer name */
        esc_html__('Has recibido una nueva solicitud de cotización de %s.', 'mad-suite'),
        '<strong>' . esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . '</strong>'
    );
    ?>
</p>

<!-- Datos del cliente -->
<h2 style="color:#333;font-size:1em;margin-bottom:8px;"><?php esc_html_e('Datos del cliente', 'mad-suite'); ?></h2>
<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:20px;">
    <tr>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;background:#f7f7f7;width:30%;"><strong><?php esc_html_e('Pedido', 'mad-suite'); ?></strong></td>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;">#<?php echo esc_html( $order->get_order_number() ); ?></td>
    </tr>
    <tr>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;background:#f7f7f7;"><strong><?php esc_html_e('Nombre', 'mad-suite'); ?></strong></td>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;"><?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?></td>
    </tr>
    <tr>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;background:#f7f7f7;"><strong><?php esc_html_e('Email', 'mad-suite'); ?></strong></td>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;"><a href="mailto:<?php echo esc_attr( $order->get_billing_email() ); ?>"><?php echo esc_html( $order->get_billing_email() ); ?></a></td>
    </tr>
    <?php if ( $order->get_billing_phone() ) : ?>
    <tr>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;background:#f7f7f7;"><strong><?php esc_html_e('Teléfono', 'mad-suite'); ?></strong></td>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;"><?php echo esc_html( $order->get_billing_phone() ); ?></td>
    </tr>
    <?php endif; ?>
    <tr>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;background:#f7f7f7;"><strong><?php esc_html_e('Fecha', 'mad-suite'); ?></strong></td>
        <td style="padding:6px 10px;border:1px solid #e2e2e2;"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
    </tr>
</table>

<!-- Dirección de envío -->
<?php if ( $order->get_shipping_address_1() ) : ?>
<h2 style="color:#333;font-size:1em;margin-bottom:8px;"><?php esc_html_e('Dirección de envío', 'mad-suite'); ?></h2>
<p style="margin-bottom:20px;">
    <?php echo wp_kses_post( $order->get_formatted_shipping_address() ); ?>
</p>
<?php endif; ?>

<!-- Productos solicitados (SIN precios) -->
<h2 style="color:#333;font-size:1em;margin-bottom:8px;"><?php esc_html_e('Productos solicitados', 'mad-suite'); ?></h2>
<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-bottom:20px;">
    <thead>
        <tr>
            <th style="padding:8px 12px;border:1px solid #e2e2e2;background:#f7f7f7;text-align:left;"><?php esc_html_e('Producto', 'mad-suite'); ?></th>
            <th style="padding:8px 12px;border:1px solid #e2e2e2;background:#f7f7f7;text-align:center;"><?php esc_html_e('SKU', 'mad-suite'); ?></th>
            <th style="padding:8px 12px;border:1px solid #e2e2e2;background:#f7f7f7;text-align:center;"><?php esc_html_e('Cantidad', 'mad-suite'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ( $order->get_items() as $item ) :
            $product = $item->get_product();
        ?>
        <tr>
            <td style="padding:8px 12px;border:1px solid #e2e2e2;"><?php echo esc_html( $item->get_name() ); ?></td>
            <td style="padding:8px 12px;border:1px solid #e2e2e2;text-align:center;"><?php echo $product ? esc_html( $product->get_sku() ) : '—'; ?></td>
            <td style="padding:8px 12px;border:1px solid #e2e2e2;text-align:center;"><?php echo esc_html( $item->get_quantity() ); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Enlace al pedido en el panel -->
<p style="margin-top:24px;">
    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) ); ?>"
       style="background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block;">
        <?php esc_html_e('Ver pedido en el panel', 'mad-suite'); ?>
    </a>
</p>

<p style="margin-top:16px;color:#888;font-size:0.9em;">
    <?php esc_html_e('Desde el panel puedes aprobar la cotización y enviar los precios al cliente con el botón "Enviar cotización al cliente".', 'mad-suite'); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
