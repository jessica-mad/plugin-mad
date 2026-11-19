<?php
/**
 * Customer email: a piece was sold (plain text).
 *
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var string[] $available_names
 * @var string   $selection_url
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";
echo wp_strip_all_tags( $body_text ) . "\n\n";

if ( ! empty( $available_names ) ) {
    echo esc_html__( 'El resto de tu lista de precios sigue disponible:', 'mad-suite' ) . "\n";
    foreach ( $available_names as $name ) {
        echo '- ' . esc_html( $name ) . "\n";
    }
    echo "\n" . esc_html__( 'Ver mi lista de precios:', 'mad-suite' ) . ' ' . esc_url( $selection_url ) . "\n";
}

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
