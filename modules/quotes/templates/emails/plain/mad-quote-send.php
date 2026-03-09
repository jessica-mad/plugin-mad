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

echo esc_html__( 'Pedido #', 'mad-suite' ) . esc_html( $order->get_order_number() ) . "\n\n";
echo wc_get_email_order_items( $order, [ 'plain_text' => true ] ); // phpcs:ignore

echo "\n";
echo esc_html__( 'Aceptar y pagar: ', 'mad-suite' ) . esc_url( $order->get_checkout_payment_url() ) . "\n";
