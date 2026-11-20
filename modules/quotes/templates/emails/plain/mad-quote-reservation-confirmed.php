<?php
/**
 * Customer email: reservation confirmed (plain text).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text   Ya incluye los datos bancarios en el lugar de {datos_bancarios}.
 * @var string   $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";
echo wp_strip_all_tags( $body_text ) . "\n\n";

echo esc_html__( 'Adjuntar comprobante:', 'mad-suite' ) . ' ' . esc_url( mad_quotes_order_payment_url( $order ) ) . "\n";

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
