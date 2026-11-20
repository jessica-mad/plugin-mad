<?php
/**
 * Admin email: proof of payment received (plain text).
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body_text
 * @var string   $additional_content
 * @var string   $ai_result_text
 * @var string   $order_edit_url
 */

defined( 'ABSPATH' ) || exit;

echo esc_html( $email_heading ) . "\n\n";
echo wp_strip_all_tags( $body_text ) . "\n\n";
echo wp_strip_all_tags( $ai_result_text ) . "\n\n";

echo wc_get_email_order_items( $order, [ 'plain_text' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

echo "\n" . esc_html__( 'Ver pedido:', 'mad-suite' ) . ' ' . esc_url( $order_edit_url ) . "\n";

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
