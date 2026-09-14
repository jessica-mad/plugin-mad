<?php
/**
 * Customer email: quote ready (plain text).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var string   $admin_note
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";

echo wp_strip_all_tags( $body_text );

echo "\n\n";

if ( ! empty( $admin_note ) ) {
    echo esc_html__( 'Nota del administrador:', 'mad-suite' ) . "\n";
    echo esc_html( $admin_note ) . "\n\n";
}

// Tabla de artículos con precios de presupuesto
echo esc_html__( 'DETALLE DEL PRESUPUESTO', 'mad-suite' ) . "\n";
echo str_repeat( '-', 50 ) . "\n";

$grand_total          = 0.0;
$grand_total_incl_tax = 0.0;
foreach ( $order->get_items() as $item_id => $item ) {
    $product_id  = $item->get_product_id();
    $product     = wc_get_product( $product_id );
    $qty         = $item->get_quantity();

    $saved_price = $item->get_meta( '_mad_quote_line_price' );
    $unit_price  = ( $saved_price !== '' && false !== $saved_price )
        ? (float) $saved_price
        : mad_quotes_get_product_quote_price( $product_id );
    $line_total  = $unit_price * $qty;
    $grand_total += $line_total;

    $grand_total_incl_tax += $product
        ? wc_get_price_including_tax( $product, [ 'qty' => $qty, 'price' => $unit_price ] )
        : $line_total;

    printf(
        "%s (x%d): %s c/u — %s\n",
        esc_html( $item->get_name() ),
        $qty,
        wp_strip_all_tags( wc_price( $unit_price ) ),
        wp_strip_all_tags( wc_price( $line_total ) )
    );
}

echo str_repeat( '-', 50 ) . "\n";

if ( $grand_total_incl_tax - $grand_total > 0.005 ) {
    printf( esc_html__( 'Base imponible: %s', 'mad-suite' ) . "\n", wp_strip_all_tags( wc_price( $grand_total ) ) );
    printf(
        esc_html__( 'IVA (tarifa estándar, solo aplica a facturas con destino España): %s', 'mad-suite' ) . "\n",
        wp_strip_all_tags( wc_price( $grand_total_incl_tax - $grand_total ) )
    );
    printf( esc_html__( 'Total con IVA: %s', 'mad-suite' ) . "\n", wp_strip_all_tags( wc_price( $grand_total_incl_tax ) ) );
} else {
    printf(
        esc_html__( 'Total del presupuesto: %s', 'mad-suite' ) . "\n",
        wp_strip_all_tags( wc_price( $grand_total ) )
    );
}

echo "\n";
echo esc_html__( 'Aceptar y pagar: ', 'mad-suite' ) . esc_url( $order->get_checkout_payment_url() ) . "\n";

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
