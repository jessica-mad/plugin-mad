<?php
/**
 * Customer email: reservation expired (plain text) — variants A/B/C/D.
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var string   $retry_url
 * @var string   $retry_label
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";
echo wp_strip_all_tags( $body_text ) . "\n\n";

if ( $retry_url ) {
    echo esc_html( $retry_label ) . ': ' . esc_url( $retry_url ) . "\n";
}

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
