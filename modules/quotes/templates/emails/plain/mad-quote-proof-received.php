<?php
/**
 * Customer email: proof of payment received (plain text).
 *
 * @var string $email_heading
 * @var string $body_text
 * @var string $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";
echo wp_strip_all_tags( $body_text ) . "\n";

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
