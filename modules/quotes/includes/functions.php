<?php
/**
 * Helper functions for the Quotes module.
 *
 * @package MAD_Suite/Quotes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Check whether quotes are enabled for a given product.
 *
 * Checks product-level meta first; falls back to global setting.
 *
 * @param  int $product_id
 * @param  int $quantity   Optional. Defaults to 1.
 * @return bool
 */
function mad_quotes_product_quote_enabled( $product_id, $quantity = 1 ) {
    $product_id = absint( $product_id );
    if ( ! $product_id ) return false;

    // Product-level override.
    $product_level = get_post_meta( $product_id, 'mad_quotes_enable', true );
    if ( '' !== $product_level ) {
        return 'on' === $product_level;
    }

    // Global fallback.
    $settings = mad_quotes_get_settings();
    return ! empty( $settings['enable_global_quote'] );
}

/**
 * Check whether price should be displayed for a given product.
 *
 * @param  int $product_id
 * @return bool
 */
function mad_quotes_product_price_display( $product_id ) {
    $product_id = absint( $product_id );

    // Product-level override.
    $product_level = get_post_meta( $product_id, 'mad_quotes_display_price', true );
    if ( '' !== $product_level ) {
        return 'on' === $product_level;
    }

    // Global fallback.
    $settings = mad_quotes_get_settings();
    return ! empty( $settings['enable_global_prices'] );
}

/**
 * Return true if the cart contains at least one quotable product.
 *
 * @return bool
 */
function mad_quotes_cart_contains_quotable() {
    if ( ! isset( WC()->cart ) || is_null( WC()->cart ) ) return false;

    foreach ( WC()->cart->get_cart() as $item ) {
        $product_id = apply_filters( 'mad_quotes_cart_item_product_id', $item['product_id'], $item );
        $qty        = isset( $item['quantity'] ) ? $item['quantity'] : 1;

        if ( mad_quotes_product_quote_enabled( $product_id, $qty ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Return true if cart prices should be displayed (even for quotable products).
 *
 * @return bool
 */
function mad_quotes_cart_display_price() {
    $settings = mad_quotes_get_settings();
    return ! empty( $settings['enable_global_prices'] );
}

/**
 * Return true if order prices should be displayed.
 *
 * @param  WC_Order $order
 * @return bool
 */
function mad_quotes_order_display_price( $order ) {
    // Check if any item in the order allows price display.
    foreach ( $order->get_items() as $item ) {
        $product_id = $item->get_product_id();
        if ( mad_quotes_product_price_display( $product_id ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Get module settings with defaults applied.
 *
 * @return array
 */
function mad_quotes_get_settings() {
    $option_key = MAD_Suite_Core::option_key( 'quotes' );
    $opts       = get_option( $option_key, [] );

    $defaults = [
        'enable_global_quote'        => false,
        'enable_global_prices'       => false,
        'hide_address_fields'        => false,
        'add_to_cart_button_text'    => '',
        'place_order_text'           => '',
        'cart_page_name'             => '',
        'checkout_page_name'         => '',
        'proceed_checkout_btn_label' => '',
        'quote_expiry_days'          => 0,
    ];

    return wp_parse_args( is_array( $opts ) ? $opts : [], $defaults );
}

/**
 * Bulk-update a post-meta setting for a list of products.
 *
 * @param  WP_Post[] $product_list
 * @param  string    $meta_key
 * @param  string    $meta_value
 */
function mad_quotes_bulk_update_meta( $product_list, $meta_key, $meta_value ) {
    if ( empty( $product_list ) ) return;

    foreach ( $product_list as $post ) {
        $id = is_object( $post ) ? $post->ID : absint( $post );
        if ( $id ) {
            update_post_meta( $id, $meta_key, $meta_value );
        }
    }
}
