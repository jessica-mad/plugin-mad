<?php
/**
 * Admin email: new quote request (plain text).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";

echo wp_strip_all_tags( $body_text );

echo "\n\n";
echo esc_html__( 'Pedido #', 'mad-suite' ) . esc_html( $order->get_order_number() ) . "\n";
echo esc_html__( 'Fecha: ', 'mad-suite' ) . esc_html( date_i18n( wc_date_format(), strtotime( $order->get_date_created() ) ) ) . "\n";
echo esc_html__( 'Cliente: ', 'mad-suite' ) . esc_html( $order->get_billing_email() ) . "\n\n";

echo wc_get_email_order_items( $order, [ 'plain_text' => true ] ); // phpcs:ignore

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
