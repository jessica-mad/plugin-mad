<?php
/**
 * Reservas con plazo — núcleo de la lógica (sin HTML, sin emails).
 *
 * La reserva vive en el PRODUCTO (fuente de verdad), no en el pedido:
 *   _mad_reserved_until  (timestamp)
 *   _mad_reserved_order  (ID del pedido de compra que la reservó)
 *
 * El pedido de compra (quote-reserved) guarda, a su vez:
 *   _mad_order_type          'quote_purchase' | 'pro_purchase'
 *   _mad_quote_parent        ID del presupuesto (solo quote_purchase)
 *   _mad_reservation_minutes Plazo con el que se creó (no cambia si luego se
 *                            toca el ajuste — reservas ya abiertas respetan
 *                            el suyo).
 *   _mad_quote_lang          'es'|'en'|'fr', para emails/placeholders.
 *
 * El presupuesto (quote-sent) guarda:
 *   _mad_reservation_attempts  Lista de timestamps (JSON) — un intento por
 *                              cada vez que se creó un pedido quote-reserved
 *                              desde este presupuesto, se complete o no.
 *   _mad_purchase_orders       Lista de IDs de sus pedidos de compra.
 *
 * @package MAD_Suite/Quotes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Reservations {

    public const HOOK_EXPIRE      = 'mad_reservation_expire';
    public const ACTION_GROUP     = 'mad-quotes-reservations';
    private const LOCK_TTL        = 15; // segundos — solo cubre comprobar+crear, no la reserva en sí.

    public function init(): void {
        add_action( self::HOOK_EXPIRE, [ $this, 'handle_expire' ] );

        // "Compra en la tienda física": si alguien pone el stock a 0 a mano
        // (panel o ATUM) mientras había una reserva activa, se anula esa
        // reserva (y se avisa al cliente — Module.php engancha el email).
        add_action( 'woocommerce_product_set_stock_status',   [ $this, 'maybe_release_on_manual_stock_change' ], 10, 3 );
        add_action( 'woocommerce_variation_set_stock_status', [ $this, 'maybe_release_on_manual_stock_change' ], 10, 3 );
    }

    /* ────────────────────────────────────────────────────────────────
     * Estado de un producto/variación de cara al cliente.
     * ──────────────────────────────────────────────────────────────── */

    /** @return string 'available' | 'reserved' | 'pending_proof' | 'sold' */
    public function product_state( WC_Product $product ): string {
        $product_id = $product->get_id();
        $until      = (int) get_post_meta( $product_id, '_mad_reserved_until', true );
        $order_id   = (int) get_post_meta( $product_id, '_mad_reserved_order', true );

        if ( $order_id ) {
            $order = wc_get_order( $order_id );

            if ( $order instanceof WC_Order ) {
                // Reserva activa (dentro del plazo), todavía sin comprobante.
                if ( $until > time() && 'quote-reserved' === $order->get_status() ) {
                    return 'reserved';
                }

                // Comprobante subido: en espera, pendiente de que el admin
                // apruebe o rechace la transferencia. Ya no depende del
                // plazo (la caducidad se cancela al recibir el comprobante).
                if ( 'on-hold' === $order->get_status() && '1' === $order->get_meta( '_mad_payment_proof_verified' ) ) {
                    return 'pending_proof';
                }
            }
        }

        // Nada reservado (o la reserva venció/se liberó): lo que mande es el
        // stock real. Sin unidades = vendida (por una compra aprobada, o
        // porque se vendió en la tienda física).
        return $product->is_in_stock() ? 'available' : 'sold';
    }

    /** true si YA hay una reserva activa (no vencida) sobre este producto, la haya hecho quien la haya hecho. */
    public function is_actively_reserved( WC_Product $product ): bool {
        return 'reserved' === $this->product_state( $product );
    }

    /* ────────────────────────────────────────────────────────────────
     * Bloqueo atómico por producto — solo mientras se decide si se puede
     * reservar y se crea el pedido (TTL corto). La duración real de la
     * reserva vive en _mad_reserved_until, no aquí.
     * ──────────────────────────────────────────────────────────────── */

    /** @return bool true si se consiguió el lock. */
    public function lock_product( int $product_id ): bool {
        $key = 'mad_lock_' . $product_id;

        if ( add_option( $key, time() + self::LOCK_TTL, '', 'no' ) ) {
            return true;
        }

        // Ya existe un lock — si venció (p.ej. una petición anterior murió a
        // mitad de camino sin liberar), se borra y se reintenta UNA vez.
        $existing = (int) get_option( $key, 0 );
        if ( $existing > 0 && $existing < time() ) {
            delete_option( $key );
            return add_option( $key, time() + self::LOCK_TTL, '', 'no' );
        }

        return false;
    }

    public function unlock_product( int $product_id ): void {
        delete_option( 'mad_lock_' . $product_id );
    }

    /**
     * Toma el lock de varios productos a la vez (todos o ninguno). Si alguno
     * falla, libera los que sí había conseguido y devuelve el ID del
     * primero que falló.
     *
     * @return int 0 si se consiguieron todos, o el product_id que falló.
     */
    public function lock_products( array $product_ids ): int {
        $locked = [];
        foreach ( $product_ids as $pid ) {
            if ( $this->lock_product( $pid ) ) {
                $locked[] = $pid;
                continue;
            }
            foreach ( $locked as $l ) {
                $this->unlock_product( $l );
            }
            return $pid;
        }
        return 0;
    }

    public function unlock_products( array $product_ids ): void {
        foreach ( $product_ids as $pid ) {
            $this->unlock_product( $pid );
        }
    }

    /* ────────────────────────────────────────────────────────────────
     * Crear / liberar una reserva.
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Intenta reservar un conjunto de líneas, creando un pedido nuevo en
     * estado quote-reserved. Todo o nada: si una sola pieza no está
     * disponible (o no se consigue su lock), no se crea nada.
     *
     * @param  array  $lines  Lista de ['product_id' => int, 'qty' => int, 'price' => float, 'name' => string].
     * @param  array  $args   order_type ('quote_purchase'|'pro_purchase'), parent_quote_id (int),
     *                        lang ('es'|'en'|'fr'), minutes (int, plazo a aplicar),
     *                        billing (array opcional de datos de facturación/email/nombre),
     *                        existing_order (WC_Order opcional — profesionales: convierte el
     *                        pedido que YA creó el checkout en vez de crear uno nuevo).
     * @return WC_Order|WP_Error
     */
    public function try_reserve( array $lines, array $args ) {
        if ( empty( $lines ) ) {
            return new WP_Error( 'mad_no_lines', __( 'No se seleccionó ninguna pieza.', 'mad-suite' ) );
        }

        $product_ids = array_values( array_unique( array_map( static fn( $l ) => (int) $l['product_id'], $lines ) ) );

        // Comprobar disponibilidad ANTES de pelear por el lock (evita tomar
        // locks para nada si ya sabemos que algo no está disponible).
        foreach ( $lines as $line ) {
            $product = wc_get_product( $line['product_id'] );
            if ( ! $product || 'available' !== $this->product_state( $product ) ) {
                return new WP_Error(
                    'mad_unavailable',
                    __( 'Alguien acaba de reservar esta pieza.', 'mad-suite' ),
                    [ 'product_id' => $line['product_id'] ]
                );
            }
        }

        $failed = $this->lock_products( $product_ids );
        if ( $failed ) {
            return new WP_Error(
                'mad_lock_failed',
                __( 'Alguien acaba de reservar esta pieza.', 'mad-suite' ),
                [ 'product_id' => $failed ]
            );
        }

        try {
            // Re-comprobar YA con el lock en mano (cierra la ventana de
            // carrera entre el primer chequeo y conseguir el lock).
            foreach ( $lines as $line ) {
                $product = wc_get_product( $line['product_id'] );
                if ( ! $product || 'available' !== $this->product_state( $product ) ) {
                    return new WP_Error(
                        'mad_unavailable',
                        __( 'Alguien acaba de reservar esta pieza.', 'mad-suite' ),
                        [ 'product_id' => $line['product_id'] ]
                    );
                }
            }

            $order_type = $args['order_type'] ?? 'quote_purchase';
            $minutes    = max( 10, min( 1440, (int) ( $args['minutes'] ?? 30 ) ) );
            $lang       = $args['lang'] ?? 'es';

            $order = $args['existing_order'] ?? null;
            if ( ! ( $order instanceof WC_Order ) ) {
                $order = wc_create_order( [ 'status' => 'pending' ] );
                if ( is_wp_error( $order ) ) return $order;

                foreach ( $lines as $line ) {
                    $product = wc_get_product( $line['product_id'] );
                    $item    = new WC_Order_Item_Product();
                    $item->set_product( $product );
                    $item->set_quantity( (int) ( $line['qty'] ?? 1 ) );
                    $item->set_subtotal( (float) $line['price'] * (int) ( $line['qty'] ?? 1 ) );
                    $item->set_total( (float) $line['price'] * (int) ( $line['qty'] ?? 1 ) );
                    $item->add_meta_data( '_mad_quote_line_price', $line['price'], true );
                    $order->add_item( $item );
                }

                if ( ! empty( $args['billing'] ) && is_array( $args['billing'] ) ) {
                    foreach ( $args['billing'] as $key => $value ) {
                        $setter = 'set_billing_' . $key;
                        if ( method_exists( $order, $setter ) ) $order->$setter( $value );
                    }
                }
            }

            $until = time() + ( $minutes * MINUTE_IN_SECONDS );

            $order->update_meta_data( '_mad_order_type', $order_type );
            $order->update_meta_data( '_mad_reservation_minutes', $minutes );
            $order->update_meta_data( '_mad_quote_lang', $lang );
            if ( ! empty( $args['parent_quote_id'] ) ) {
                $order->update_meta_data( '_mad_quote_parent', (int) $args['parent_quote_id'] );
            }
            $order->calculate_totals( false );
            $order->update_status( 'quote-reserved', __( 'Reserva creada — a la espera del comprobante de transferencia.', 'mad-suite' ) );
            $order->save();

            foreach ( $product_ids as $pid ) {
                update_post_meta( $pid, '_mad_reserved_until', $until );
                update_post_meta( $pid, '_mad_reserved_order', $order->get_id() );
            }

            if ( ! empty( $args['parent_quote_id'] ) ) {
                $this->register_attempt( (int) $args['parent_quote_id'] );
                $this->append_purchase_order( (int) $args['parent_quote_id'], $order->get_id() );
            }

            $this->schedule_expiry( $order->get_id(), $until );

            do_action( 'mad_reservation_created', $order, $lines );

            return $order;
        } finally {
            $this->unlock_products( $product_ids );
        }
    }

    /**
     * Libera una reserva: limpia las metas de cada producto que esté
     * reservado por ESTE pedido (nunca toca la de otro pedido — por si ya
     * fue reasignada) y cancela la acción de caducidad pendiente, si la hay.
     */
    public function release_reservation( WC_Order $order, string $reason = '' ): void {
        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            if ( ! $product_id ) continue;

            if ( (int) get_post_meta( $product_id, '_mad_reserved_order', true ) === $order->get_id() ) {
                delete_post_meta( $product_id, '_mad_reserved_until' );
                delete_post_meta( $product_id, '_mad_reserved_order' );
            }
        }

        $this->cancel_scheduled_expiry( $order->get_id() );

        if ( $reason ) {
            $order->add_order_note( $reason );
        }
    }

    /* ────────────────────────────────────────────────────────────────
     * Caducidad — Action Scheduler + callback.
     * ──────────────────────────────────────────────────────────────── */

    private function schedule_expiry( int $order_id, int $timestamp ): void {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( $timestamp, self::HOOK_EXPIRE, [ 'order_id' => $order_id ], self::ACTION_GROUP );
        } else {
            wp_schedule_single_event( $timestamp, self::HOOK_EXPIRE, [ 'order_id' => $order_id ] );
        }
    }

    /**
     * Cancela solo la caducidad programada, SIN tocar las metas de reserva
     * del producto (a diferencia de release_reservation()) — se usa al
     * recibir el comprobante: la pieza sigue "apartada" para este pedido
     * (estado pending_proof), ya no corre contra el reloj.
     */
    public function cancel_expiry_only( int $order_id ): void {
        $this->cancel_scheduled_expiry( $order_id );
    }

    private function cancel_scheduled_expiry( int $order_id ): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::HOOK_EXPIRE, [ 'order_id' => $order_id ], self::ACTION_GROUP );
        } else {
            wp_clear_scheduled_hook( self::HOOK_EXPIRE, [ 'order_id' => $order_id ] );
        }
    }

    /**
     * Callback de la caducidad. Si el pedido sigue en quote-reserved (nadie
     * subió comprobante a tiempo), lo cancela — eso repone stock solo
     * (nunca se llegó a descontar) y dispara release_reservation() a través
     * del hook de cambio de estado que Module.php engancha.
     */
    public function handle_expire( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order || 'quote-reserved' !== $order->get_status() ) return; // ya se resolvió por otra vía.

        $order->update_status( 'cancelled', __( 'Reserva caducada: no se recibió el comprobante a tiempo.', 'mad-suite' ) );
        do_action( 'mad_reservation_expired', $order );
    }

    /* ────────────────────────────────────────────────────────────────
     * Intentos de reserva (sección 4) — por presupuesto.
     * ──────────────────────────────────────────────────────────────── */

    public function register_attempt( int $quote_id ): void {
        $attempts   = $this->get_attempts( $quote_id );
        $attempts[] = time();
        update_post_meta( $quote_id, '_mad_reservation_attempts', wp_json_encode( $attempts ) );
    }

    public function reset_attempts( int $quote_id ): void {
        delete_post_meta( $quote_id, '_mad_reservation_attempts' );
    }

    /** @return int[] Timestamps de intentos, dentro de la ventana configurada (los más viejos ya no cuentan). */
    public function get_attempts( int $quote_id ): array {
        $raw      = get_post_meta( $quote_id, '_mad_reservation_attempts', true );
        $attempts = $raw ? (array) json_decode( (string) $raw, true ) : [];
        $settings = mad_quotes_get_settings();
        $window   = (int) $settings['reservation_window_hours'] * HOUR_IN_SECONDS;
        $cutoff   = time() - $window;

        return array_values( array_filter( $attempts, static fn( $t ) => (int) $t > $cutoff ) );
    }

    /** Cuántos intentos le quedan al presupuesto en la ventana actual. */
    public function attempts_remaining( int $quote_id ): int {
        $settings = mad_quotes_get_settings();
        $max      = (int) $settings['reservation_attempts'];
        return max( 0, $max - count( $this->get_attempts( $quote_id ) ) );
    }

    /** Timestamp en el que el intento más antiguo de la ventora actual deja de contar (próximo intento disponible), o null si ya tiene intentos libres. */
    public function next_attempt_at( int $quote_id ): ?int {
        if ( $this->attempts_remaining( $quote_id ) > 0 ) return null;

        $attempts = $this->get_attempts( $quote_id );
        if ( empty( $attempts ) ) return null;

        $settings = mad_quotes_get_settings();
        $window   = (int) $settings['reservation_window_hours'] * HOUR_IN_SECONDS;
        return (int) min( $attempts ) + $window;
    }

    private function append_purchase_order( int $quote_id, int $order_id ): void {
        $raw  = get_post_meta( $quote_id, '_mad_purchase_orders', true );
        $list = $raw ? (array) json_decode( (string) $raw, true ) : [];
        if ( ! in_array( $order_id, $list, true ) ) {
            $list[] = $order_id;
        }
        update_post_meta( $quote_id, '_mad_purchase_orders', wp_json_encode( $list ) );
    }

    /** @return int[] IDs de los pedidos de compra ligados a este presupuesto. */
    public function purchase_orders_for_quote( int $quote_id ): array {
        $raw = get_post_meta( $quote_id, '_mad_purchase_orders', true );
        return $raw ? array_map( 'absint', (array) json_decode( (string) $raw, true ) ) : [];
    }

    /**
     * true si este pedido de reserva sigue "vivo": o bien reservado contra
     * el reloj, o bien con el comprobante ya subido y a la espera de que el
     * admin apruebe/rechace la transferencia. false si ya se resolvió
     * (vendida, caducada, cancelada).
     */
    public function order_is_awaiting_payment( WC_Order $order ): bool {
        return in_array( $order->get_status(), [ 'quote-reserved', 'on-hold' ], true );
    }

    /**
     * El pedido de compra todavía "vivo" de este presupuesto, si lo hay —
     * usado para que la página de selección redirija sola a la de pago
     * cuando el cliente ya reservó (caso límite: vuelve a usar el enlace
     * del presupuesto). El más reciente manda si hubiera más de uno.
     */
    public function active_purchase_order_for_quote( int $quote_id ): ?WC_Order {
        $order_ids = $this->purchase_orders_for_quote( $quote_id );
        rsort( $order_ids );

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order instanceof WC_Order && $this->order_is_awaiting_payment( $order ) ) {
                return $order;
            }
        }

        return null;
    }

    /* ────────────────────────────────────────────────────────────────
     * Intentos de reserva — profesionales (sin presupuesto padre): por
     * cliente + producto. user_meta si está logueado, si no un transient
     * por email (no hay cuenta a la que colgar la meta).
     * ──────────────────────────────────────────────────────────────── */

    private function pro_attempts_key( int $product_id ): string {
        return '_mad_pro_reservation_attempts_' . $product_id;
    }

    public function register_pro_attempt( int $product_id, int $user_id, string $email ): void {
        $attempts   = $this->get_pro_attempts( $product_id, $user_id, $email );
        $attempts[] = time();
        $this->save_pro_attempts( $product_id, $user_id, $email, $attempts );
    }

    /** @return int[] */
    public function get_pro_attempts( int $product_id, int $user_id, string $email ): array {
        $key      = $this->pro_attempts_key( $product_id );
        $raw      = $user_id ? get_user_meta( $user_id, $key, true ) : get_transient( $key . '_' . md5( strtolower( $email ) ) );
        $attempts = $raw ? (array) json_decode( (string) $raw, true ) : [];

        $settings = mad_quotes_get_settings();
        $cutoff   = time() - ( (int) $settings['reservation_window_hours'] * HOUR_IN_SECONDS );

        return array_values( array_filter( $attempts, static fn( $t ) => (int) $t > $cutoff ) );
    }

    private function save_pro_attempts( int $product_id, int $user_id, string $email, array $attempts ): void {
        $key = $this->pro_attempts_key( $product_id );
        $settings = mad_quotes_get_settings();
        $window   = (int) $settings['reservation_window_hours'] * HOUR_IN_SECONDS;

        if ( $user_id ) {
            update_user_meta( $user_id, $key, wp_json_encode( $attempts ) );
        } else {
            set_transient( $key . '_' . md5( strtolower( $email ) ), wp_json_encode( $attempts ), $window );
        }
    }

    public function pro_attempts_remaining( int $product_id, int $user_id, string $email ): int {
        $settings = mad_quotes_get_settings();
        $max      = (int) $settings['pro_reservation_attempts'];
        return max( 0, $max - count( $this->get_pro_attempts( $product_id, $user_id, $email ) ) );
    }

    public function pro_next_attempt_at( int $product_id, int $user_id, string $email ): ?int {
        if ( $this->pro_attempts_remaining( $product_id, $user_id, $email ) > 0 ) return null;

        $attempts = $this->get_pro_attempts( $product_id, $user_id, $email );
        if ( empty( $attempts ) ) return null;

        $settings = mad_quotes_get_settings();
        return (int) min( $attempts ) + ( (int) $settings['reservation_window_hours'] * HOUR_IN_SECONDS );
    }

    /* ────────────────────────────────────────────────────────────────
     * Venta en tienda física: stock a 0 a mano anula cualquier reserva.
     * ──────────────────────────────────────────────────────────────── */

    public function maybe_release_on_manual_stock_change( $product_id, $status, $product ): void {
        if ( 'outofstock' !== $status ) return;
        if ( ! $product instanceof WC_Product ) return;

        $order_id = (int) get_post_meta( $product->get_id(), '_mad_reserved_order', true );
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order || 'quote-reserved' !== $order->get_status() ) return;

        // Pieza vendida fuera de este pedido (tienda física) mientras había
        // una reserva activa: se anula esa reserva, se cancela SU pedido
        // (no se mantiene "vivo" apuntando a una pieza ya vendida a otro) y
        // se avisa al cliente (Module.php escucha mad_reservation_voided).
        $order->update_status( 'cancelled', __( 'Reserva anulada: la pieza se vendió fuera de este pedido.', 'mad-suite' ) );
        do_action( 'mad_reservation_voided', $order );
    }
}
