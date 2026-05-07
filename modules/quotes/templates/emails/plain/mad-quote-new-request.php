<?php
/**
 * Admin email: new quote request (plain text).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";

printf(
    /* translators: 1: customer name */
    esc_html__( 'Has recibido una nueva solicitud de presupuesto de %s.', 'mad-suite' ),
    esc_html( $order->get_formatted_billing_full_name() )
);

echo "\n\n";
echo esc_html__( 'Pedido #', 'mad-suite' ) . esc_html( $order->get_order_number() ) . "\n";
echo esc_html__( 'Fecha: ', 'mad-suite' ) . esc_html( date_i18n( wc_date_format(), strtotime( $order->get_date_created() ) ) ) . "\n";
echo esc_html__( 'Cliente: ', 'mad-suite' ) . esc_html( $order->get_billing_email() ) . "\n\n";

echo wc_get_email_order_items( $order, [ 'plain_text' => true ] ); // phpcs:ignore
