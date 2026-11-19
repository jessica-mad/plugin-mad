<?php
/**
 * Customer email: a piece was sold (plain text).
 *
 * @var string $email_heading
 * @var string $body_text
 * @var string $additional_content
 * @var string $selection_url
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";
echo wp_strip_all_tags( $body_text ) . "\n\n";

if ( $selection_url ) {
    echo esc_html__( 'Ver mi lista de precios:', 'mad-suite' ) . ' ' . esc_url( $selection_url ) . "\n";
}

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
