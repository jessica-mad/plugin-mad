<?php
/**
 * Customer email: reservation confirmed (plain text).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";
echo wp_strip_all_tags( $body_text ) . "\n\n";

echo mad_quotes_order_items_text_list( $order ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

echo esc_html__( 'Total:', 'mad-suite' ) . ' ' . wp_strip_all_tags( wc_price( $order->get_total() ) ) . "\n\n";

echo esc_html__( 'Datos para la transferencia', 'mad-suite' ) . "\n";
foreach ( mad_quotes_bacs_account_details() as $account ) {
    foreach ( [
        'account_name'   => __( 'Titular', 'mad-suite' ),
        'bank_name'      => __( 'Banco', 'mad-suite' ),
        'account_number' => __( 'Cuenta', 'mad-suite' ),
        'iban'           => __( 'IBAN', 'mad-suite' ),
        'bic'            => __( 'BIC/SWIFT', 'mad-suite' ),
    ] as $field => $label ) {
        if ( empty( $account[ $field ] ) ) continue;
        echo esc_html( $label ) . ': ' . esc_html( $account[ $field ] ) . "\n";
    }
}
printf( esc_html__( 'Concepto: Pedido #%s', 'mad-suite' ) . "\n\n", esc_html( $order->get_order_number() ) );

echo esc_html__( 'Adjuntar comprobante:', 'mad-suite' ) . ' ' . esc_url( mad_quotes_order_payment_url( $order ) ) . "\n";

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
