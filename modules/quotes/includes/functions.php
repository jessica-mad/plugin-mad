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
 * Whether the current visitor should get the "quote" experience (hidden
 * prices, request-by-email flow) instead of the normal shop.
 *
 * Mirrors the private logic in the Quotes module so other modules (e.g.
 * Divano Toscano's configurador) can reuse the exact same rule without
 * depending on the module's internals.
 *
 * @return bool
 */
function mad_quotes_current_user_is_quote_role() {
    $settings    = mad_quotes_get_settings();
    $quote_roles = array_filter( (array) ( $settings['quote_roles'] ?? [] ) );

    if ( empty( $quote_roles ) ) {
        return true;
    }

    $user = wp_get_current_user();
    if ( ! $user->ID ) {
        return in_array( 'guest', $quote_roles, true );
    }

    return (bool) array_intersect( $quote_roles, (array) $user->roles );
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
        'quote_roles'              => [],
        'quote_expiry_days'        => 0,
        'quote_button_text'        => [],
        'quote_submit_button_text' => [],
        'mini_cart_view_cart_text' => [],
        'mini_cart_checkout_text'  => [],
        'quote_cart_page_id'       => 0,
        'payment_proof_strict'     => false,
        'real_price_roles'         => [],
        'add_to_cart_notice_text'  => __( 'a tu lista de precios', 'mad-suite' ),
        // A diferencia de quote_roles (vacío = todos), acá vacío = nadie:
        // este rol ve precio base real + IVA y puede enviar presupuestos ya
        // con precio final directo al cliente, así que el default seguro es
        // que no aplique a nadie hasta que se configure explícitamente.
        'store_manager_roles'      => [],
        // Texto de la página "Gracias por tu pedido", override opcional por
        // estado del pedido y por idioma. Vacío para un estado/idioma = se
        // usa el texto por defecto de WooCommerce, sin tocarlo.
        'thankyou_texts'           => [],

        // ── Reservas con plazo ──────────────────────────────────────────
        'reservations_enabled'     => true,
        'reservation_minutes'      => 30,   // Clientes con presupuesto.
        'pro_reservation_minutes'  => 30,   // Profesionales (checkout normal, BACS).
        'reservation_attempts'     => 2,    // Por presupuesto, en la ventana de abajo.
        'pro_reservation_attempts' => 2,    // Por cliente+producto, sin presupuesto padre.
        'reservation_window_hours' => 24,
        'terms_page_id'            => 0,
        'purchase_page_id'         => 0,
        // [lang][state] => texto, con {tiempo}. Ver mad_quotes_reservation_message().
        'reservation_messages'     => [],
        'contact_email'            => 'info@olofane.com',
        'contact_phone'            => '91 365 46 50',
    ];

    return wp_parse_args( is_array( $opts ) ? $opts : [], $defaults );
}

/**
 * Get the quote price for a product.
 *
 * Uses the native WooCommerce Regular Price as the quote price.
 * The Sale Price is reserved for professional users who buy directly.
 *
 * @param  int $product_id
 * @return float
 */
function mad_quotes_get_product_quote_price( $product_id ) {
    $product = wc_get_product( absint( $product_id ) );
    if ( ! $product ) return 0.0;
    return (float) $product->get_regular_price();
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

/* =====================================================================
 * Reservas con plazo — helpers puros (sin estado, reutilizables desde
 * cualquier clase: emails, mensajes de ficha/catálogo, páginas, REST).
 * ===================================================================== */

/**
 * Idioma del pedido (_mad_quote_lang), o el idioma por defecto de WPML, o
 * 'es' si no hay WPML. 'es'|'en'|'fr' en minúsculas, nunca vacío.
 */
function mad_quotes_order_lang( $order ): string {
    $lang = $order instanceof WC_Order ? (string) $order->get_meta( '_mad_quote_lang' ) : '';
    if ( '' === $lang && function_exists( 'apply_filters' ) ) {
        $lang = (string) apply_filters( 'wpml_default_language', null );
    }
    $lang = strtolower( $lang );
    return in_array( $lang, [ 'es', 'en', 'fr' ], true ) ? $lang : 'es';
}

/**
 * "30 minutos" / "1 hour" / "1 h 30 min", según el idioma — nunca un
 * número escrito a mano en ningún texto al cliente (sección 8).
 */
function mad_quotes_format_duration( int $minutes, string $lang ): string {
    $h = intdiv( $minutes, 60 );
    $m = $minutes % 60;

    if ( 'en' === $lang ) {
        if ( $h > 0 && $m > 0 ) return sprintf( '%d hr %d min', $h, $m );
        if ( $h > 0 )           return 1 === $h ? '1 hour' : sprintf( '%d hours', $h );
        return sprintf( '%d minutes', $m );
    }

    if ( 'fr' === $lang ) {
        if ( $h > 0 && $m > 0 ) return sprintf( '%d h %d min', $h, $m );
        if ( $h > 0 )           return 1 === $h ? '1 heure' : sprintf( '%d heures', $h );
        return sprintf( '%d minutes', $m );
    }

    // ES (por defecto).
    if ( $h > 0 && $m > 0 ) return sprintf( '%d h %d min', $h, $m );
    if ( $h > 0 )           return 1 === $h ? '1 hora' : sprintf( '%d horas', $h );
    return sprintf( '%d minutos', $m );
}

/** "24 horas" / "24 hours" / "24 heures". */
function mad_quotes_format_window( int $hours, string $lang ): string {
    if ( 'en' === $lang ) return 1 === $hours ? '1 hour' : sprintf( '%d hours', $hours );
    if ( 'fr' === $lang ) return 1 === $hours ? '1 heure' : sprintf( '%d heures', $hours );
    return 1 === $hours ? '1 hora' : sprintf( '%d horas', $hours );
}

/**
 * Hora de Madrid en formato "18:42", para {hora_limite} — siempre esa zona,
 * sea cual sea el huso del visitante (sección 8, caso límite del móvil).
 */
function mad_quotes_madrid_time( int $timestamp ): string {
    try {
        $dt = new DateTime( '@' . $timestamp );
        $dt->setTimezone( new DateTimeZone( 'Europe/Madrid' ) );
        return $dt->format( 'H:i' );
    } catch ( Exception $e ) {
        return wp_date( 'H:i', $timestamp );
    }
}

/**
 * Resuelve {plural:variable|singular|plural} dentro de un texto, según el
 * valor de $vars['variable'] y las reglas de plural de $lang:
 * ES/EN → singular solo en 1, plural en el resto (incluido 0).
 * FR    → singular en 0 y 1, plural en el resto.
 *
 * @param  string                $text
 * @param  array<string,int|float> $vars  Nombre de variable => valor numérico.
 * @param  string                $lang
 * @return string
 */
function mad_quotes_apply_plural_placeholders( string $text, array $vars, string $lang ): string {
    return (string) preg_replace_callback(
        '/\{plural:([a-zA-Z_]+)\|([^|}]*)\|([^}]*)\}/u',
        function ( $m ) use ( $vars, $lang ) {
            $var      = $m[1];
            $singular = $m[2];
            $plural   = $m[3];
            $value    = isset( $vars[ $var ] ) ? (float) $vars[ $var ] : 0;

            $is_singular = ( 'fr' === $lang ) ? ( $value === 0.0 || $value === 1.0 ) : ( $value === 1.0 );
            return $is_singular ? $singular : $plural;
        },
        $text
    );
}

/**
 * Texto configurado (sección 5) para un estado de reserva, en el idioma
 * dado, con {tiempo} ya sustituido. $state: 'reserved'|'pending_proof'|
 * 'contested'|'sold'. Si no hay texto configurado para ese estado/idioma,
 * usa el texto por defecto de la tabla de la sección 5 del documento.
 */
function mad_quotes_reservation_message( string $state, string $lang, string $tiempo_html = '' ): string {
    $settings = mad_quotes_get_settings();
    $custom   = $settings['reservation_messages'][ $lang ][ $state ] ?? '';
    $text     = '' !== trim( (string) $custom ) ? $custom : mad_quotes_default_reservation_message( $state, $lang );

    return str_replace( '{tiempo}', $tiempo_html, $text );
}

/** Textos por defecto de la sección 5 del documento, tal cual. */
function mad_quotes_default_reservation_message( string $state, string $lang ): string {
    $texts = [
        'reserved' => [
            'es' => 'Esta pieza está en proceso de compra. De no ser comprada, se libera en {tiempo}.',
            'en' => 'This piece is currently being purchased. If the purchase is not completed, it will be released in {tiempo}.',
            'fr' => 'Cette pièce est en cours d\'achat. Si l\'achat n\'est pas finalisé, elle sera libérée dans {tiempo}.',
        ],
        'pending_proof' => [
            'es' => 'Esta pieza está reservada, pendiente de confirmación del pago.',
            'en' => 'This piece is reserved, pending payment confirmation.',
            'fr' => 'Cette pièce est réservée, en attente de confirmation du paiement.',
        ],
        'contested' => [
            'es' => 'Alguien acaba de reservar esta pieza. Si no se completa la compra, volverá a estar disponible en {tiempo}.',
            'en' => 'Someone has just reserved this piece. If the purchase is not completed, it will be available again in {tiempo}.',
            'fr' => 'Quelqu\'un vient de réserver cette pièce. Si l\'achat n\'est pas finalisé, elle sera de nouveau disponible dans {tiempo}.',
        ],
        'sold' => [
            'es' => 'Esta pieza ya ha sido vendida.',
            'en' => 'This piece has already been sold.',
            'fr' => 'Cette pièce a déjà été vendue.',
        ],
    ];

    return $texts[ $state ][ $lang ] ?? ( $texts[ $state ]['es'] ?? '' );
}

/** "mm:ss" a partir de un número de segundos (nunca negativo). */
function mad_quotes_format_mmss( int $seconds ): string {
    $seconds = max( 0, $seconds );
    return sprintf( '%d:%02d', intdiv( $seconds, 60 ), $seconds % 60 );
}

/**
 * Span con la cuenta atrás en vivo (JS la actualiza leyendo data-mad-until;
 * el valor en el HTML es solo el estado inicial para quien tenga JS
 * deshabilitado). Usado como {tiempo} en los estados 'reserved'/'contested'.
 */
function mad_quotes_countdown_html( int $until_ts ): string {
    return sprintf(
        '<span class="mad-reservation-countdown" data-mad-until="%d">%s</span>',
        $until_ts,
        esc_html( mad_quotes_format_mmss( $until_ts - time() ) )
    );
}

/**
 * URL base de la página de selección/pago ([mad_quote_purchase]), o la
 * portada si no hay ninguna configurada todavía.
 */
function mad_quotes_purchase_page_url(): string {
    $settings = mad_quotes_get_settings();
    $page_id  = absint( $settings['purchase_page_id'] ?? 0 );
    return $page_id ? get_permalink( $page_id ) : home_url( '/' );
}

/**
 * URL de la página de selección de un presupuesto ({enlace_presupuesto}):
 * lista sus líneas para elegir qué comprar. Si el presupuesto ya tiene una
 * reserva activa, esta misma URL redirige sola a la página de pago.
 */
function mad_quotes_quote_selection_url( WC_Order $quote ): string {
    return add_query_arg( 'mad_quote_key', $quote->get_order_key(), mad_quotes_purchase_page_url() );
}

/**
 * URL de la página de pago de un pedido de reserva ya creado
 * ({enlace_pago}): datos bancarios + cuenta atrás + subida de comprobante.
 * Sirve tanto para compras de presupuesto como para profesionales (estos
 * no tienen presupuesto padre, por eso identifican su propia reserva por
 * su propio order_key en vez de por el del presupuesto).
 */
function mad_quotes_order_payment_url( WC_Order $order ): string {
    return add_query_arg( 'mad_order_key', $order->get_order_key(), mad_quotes_purchase_page_url() );
}
