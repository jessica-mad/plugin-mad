<?php
/**
 * Customer email: quote request confirmation (plain text).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";

printf(
    esc_html__( 'Hola %s, hemos recibido tu solicitud de presupuesto correctamente. Te responderemos lo antes posible.', 'mad-suite' ),
    esc_html( $order->get_billing_first_name() )
);

echo "\n\n";
echo esc_html__( 'Pedido #', 'mad-suite' ) . esc_html( $order->get_order_number() ) . "\n";
echo esc_html__( 'Fecha: ', 'mad-suite' ) . esc_html( date_i18n( wc_date_format(), strtotime( $order->get_date_created() ) ) ) . "\n\n";

echo esc_html__( 'ARTÍCULOS SOLICITADOS', 'mad-suite' ) . "\n";
echo str_repeat( '-', 40 ) . "\n";

foreach ( $order->get_items() as $item ) {
    printf( "%s (x%d)\n", esc_html( $item->get_name() ), $item->get_quantity() );
}

echo str_repeat( '-', 40 ) . "\n";

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
