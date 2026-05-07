<?php
/**
 * Customer email: quote ready (plain text).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $admin_note
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";

printf(
    esc_html__( 'Hola %s, tu presupuesto está listo. Puedes revisarlo a continuación.', 'mad-suite' ),
    esc_html( $order->get_billing_first_name() )
);

echo "\n\n";

if ( ! empty( $admin_note ) ) {
    echo esc_html__( 'Nota del administrador:', 'mad-suite' ) . "\n";
    echo esc_html( $admin_note ) . "\n\n";
}

// Tabla de artículos con precios de presupuesto
echo esc_html__( 'DETALLE DEL PRESUPUESTO', 'mad-suite' ) . "\n";
echo str_repeat( '-', 50 ) . "\n";

$grand_total = 0.0;
foreach ( $order->get_items() as $item_id => $item ) {
    $product_id  = $item->get_product_id();
    $qty         = $item->get_quantity();

    $saved_price = $item->get_meta( '_mad_quote_line_price' );
    $unit_price  = ( $saved_price !== '' && false !== $saved_price )
        ? (float) $saved_price
        : mad_quotes_get_product_quote_price( $product_id );
    $line_total  = $unit_price * $qty;
    $grand_total += $line_total;

    printf(
        "%s (x%d): %s c/u — %s\n",
        esc_html( $item->get_name() ),
        $qty,
        wp_strip_all_tags( wc_price( $unit_price ) ),
        wp_strip_all_tags( wc_price( $line_total ) )
    );
}

echo str_repeat( '-', 50 ) . "\n";
printf(
    esc_html__( 'Total del presupuesto: %s', 'mad-suite' ) . "\n",
    wp_strip_all_tags( wc_price( $grand_total ) )
);

echo "\n";
echo esc_html__( 'Aceptar y pagar: ', 'mad-suite' ) . esc_url( $order->get_checkout_payment_url() ) . "\n";
