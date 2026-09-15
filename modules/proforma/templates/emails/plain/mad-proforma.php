<?php
/**
 * Customer email: factura proforma (plain text).
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
echo esc_html__( 'Este documento es una factura proforma, no una factura fiscal válida. La factura definitiva se emite una vez confirmado el pago.', 'mad-suite' ) . "\n";

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
