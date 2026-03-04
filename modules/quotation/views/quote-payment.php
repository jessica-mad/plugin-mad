<?php
/**
 * Template: Página de revisión y pago de cotización para el cliente.
 * Shortcode: [mad_quote_payment]
 *
 * Variables disponibles:
 * @var WC_Order $order       Pedido de cotización validado
 * @var object   $module_ref  Instancia del módulo (para acceder a settings)
 */

if ( ! defined('ABSPATH') ) exit;
?>

<div class="mad-quote-payment-wrap">

    <h2 class="mad-qp-heading"><?php esc_html_e('Revisa tu cotización', 'mad-suite'); ?></h2>

    <p class="mad-qp-intro">
        <?php
        echo wp_kses_post(
            sprintf(
                /* translators: %s: order number */
                __('A continuación puedes revisar los productos de tu cotización <strong>#%s</strong>. Selecciona los que deseas comprar, ajusta las cantidades si es necesario y pulsa "Confirmar y pagar".', 'mad-suite'),
                esc_html( $order->get_order_number() )
            )
        );
        ?>
    </p>

    <table class="mad-qp-table shop_table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th class="mad-qp-col-check"><?php esc_html_e('Incluir', 'mad-suite'); ?></th>
                <th class="mad-qp-col-product"><?php esc_html_e('Producto', 'mad-suite'); ?></th>
                <th class="mad-qp-col-price"><?php esc_html_e('Precio unit.', 'mad-suite'); ?></th>
                <th class="mad-qp-col-qty"><?php esc_html_e('Cantidad', 'mad-suite'); ?></th>
                <th class="mad-qp-col-subtotal"><?php esc_html_e('Subtotal', 'mad-suite'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $order->get_items() as $item_id => $item ) :
                $product  = $item->get_product();
                $price    = $product ? (float) $product->get_price() : 0;
                $qty      = (int) $item->get_quantity();
                $subtotal = $price * $qty;
                $img_src  = $product ? wp_get_attachment_image_src( $product->get_image_id(), [60, 60] ) : false;
            ?>
            <tr class="mad-qp-item"
                data-item-id="<?php echo esc_attr( $item_id ); ?>"
                data-price="<?php echo esc_attr( $price ); ?>">
                <td class="mad-qp-col-check">
                    <input type="checkbox" class="mad-qp-check" checked="checked" />
                </td>
                <td class="mad-qp-col-product">
                    <?php if ( $img_src ) : ?>
                        <img src="<?php echo esc_url( $img_src[0] ); ?>" alt="" width="50" height="50" style="vertical-align:middle;margin-right:8px;" />
                    <?php endif; ?>
                    <?php echo esc_html( $item->get_name() ); ?>
                    <?php if ( $product && $product->get_sku() ) : ?>
                        <small class="mad-qp-sku" style="color:#888;display:block;">SKU: <?php echo esc_html( $product->get_sku() ); ?></small>
                    <?php endif; ?>
                </td>
                <td class="mad-qp-col-price"><?php echo wc_price( $price ); ?></td>
                <td class="mad-qp-col-qty">
                    <input type="number" class="mad-qp-qty" value="<?php echo esc_attr( $qty ); ?>" min="1" max="999" step="1" style="width:60px;" />
                </td>
                <td class="mad-qp-col-subtotal mad-qp-item-subtotal"><?php echo wc_price( $subtotal ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php if ( $order->get_shipping_total() > 0 ) : ?>
            <tr>
                <td colspan="4" style="text-align:right;"><strong><?php esc_html_e('Envío estimado:', 'mad-suite'); ?></strong></td>
                <td><?php echo wc_price( (float) $order->get_shipping_total() ); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="mad-qp-total-row">
                <td colspan="4" style="text-align:right;"><strong><?php esc_html_e('Total seleccionado:', 'mad-suite'); ?></strong></td>
                <td><strong id="mad-qp-total"><?php echo wc_price(0); ?></strong></td>
            </tr>
        </tfoot>
    </table>

    <p class="mad-qp-actions" style="margin-top:24px;">
        <button type="button" id="mad-qp-confirm-btn" class="button alt wc-forward">
            <?php esc_html_e('Confirmar selección y pagar', 'mad-suite'); ?>
        </button>
    </p>

    <p class="mad-qp-note" style="color:#888;font-size:0.9em;margin-top:12px;">
        <?php esc_html_e('Al confirmar, se abrirá la página de pago donde podrás abonar tu pedido con los métodos disponibles.', 'mad-suite'); ?>
    </p>

</div>

<style>
.mad-quote-payment-wrap { max-width: 860px; margin: 0 auto; }
.mad-qp-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
.mad-qp-table th, .mad-qp-table td { padding: 10px 12px; border: 1px solid #e2e2e2; vertical-align: middle; }
.mad-qp-table thead th { background: #f7f7f7; font-weight: 600; }
.mad-qp-item.deselected td { opacity: 0.45; }
.mad-qp-check { width: 18px; height: 18px; cursor: pointer; }
#mad-qp-confirm-btn { font-size: 1.05em; padding: 10px 28px; }
</style>
