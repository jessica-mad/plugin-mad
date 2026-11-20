<?php
/**
 * Customer email: the piece is available again (plain text).
 *
 * @var string $email_heading
 * @var string $body_text
 * @var string $additional_content
 * @var string $piece_url
 * @var string $piece_name
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";
echo wp_strip_all_tags( $body_text ) . "\n";

if ( $piece_url ) {
    echo "\n" . esc_html__( 'Ver la pieza:', 'mad-suite' ) . ' ' . esc_url( $piece_url ) . "\n";
}

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
