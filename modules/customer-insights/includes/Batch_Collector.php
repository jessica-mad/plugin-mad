<?php
/**
 * Recolecta los pedidos pagados en lotes pequeños, en segundo plano (vía
 * Action Scheduler, la librería que ya trae WooCommerce), en vez de
 * cargarlos todos de una sola vez. Para una tienda con muchos pedidos
 * existentes, instanciar todos los WC_Order de golpe en una sola petición
 * podía tardar bastante y competir por recursos del servidor con las
 * visitas reales — esto lo reparte en pasos cortos, cada uno procesando
 * solo un lote, sin bloquear nada.
 *
 * Flujo:
 *   start()                     → vacía la tabla scratch y agenda el primer lote.
 *   process_batch( $page )      → vuelca un lote de pedidos a mad_ci_raw_orders
 *                                  (datos planos, no objetos WC_Order) y agenda
 *                                  el siguiente lote o el cierre.
 *   finalize()                  → una sola pasada, ahora barata porque lee filas
 *                                  planas en vez de reconstruir pedidos — hace la
 *                                  agregación real (MAD_CI_Stats_Calculator) y
 *                                  publica los resultados en las tablas finales.
 *
 * @package MAD_Suite/Customer_Insights
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_CI_Batch_Collector {

    public const HOOK_PROCESS_BATCH = 'mad_ci_process_batch';
    public const HOOK_FINALIZE      = 'mad_ci_finalize_recalculation';
    private const STATUS_OPTION     = 'mad_ci_recalc_status';
    private const BATCH_SIZE        = 150;
    private const ACTION_GROUP      = 'mad-customer-insights';

    public function init(): void {
        add_action( self::HOOK_PROCESS_BATCH, [ $this, 'process_batch' ] );
        add_action( self::HOOK_FINALIZE,      [ $this, 'finalize' ] );
    }

    /** Lanza una recolección nueva en segundo plano (si no hay una ya en curso). $months_window = 0 → histórico completo. */
    public function start( int $months_window = 0 ): bool {
        if ( self::is_running() ) {
            return false; // Ya hay una corriendo — no solaparla.
        }

        $this->reset_for_new_run( $months_window );
        $this->schedule( time(), self::HOOK_PROCESS_BATCH, [ 'page' => 1 ] );
        return true;
    }

    /**
     * Igual que start(), pero corre todo de un tirón, en la misma petición,
     * sin pasar por Action Scheduler. Solo para probar rápido con un rango
     * acotado (según venga $months_window) — nunca para el histórico
     * completo, porque eso es justo lo que el modo en segundo plano evita.
     */
    public function run_sync( int $months_window ): void {
        $this->reset_for_new_run( $months_window );

        $page = 1;
        do {
            $processed = $this->process_batch( $page, false );
            $page++;
        } while ( $processed === self::BATCH_SIZE );

        self::update_status( [ 'state' => 'finalizing' ] );
        $this->finalize();
    }

    private function reset_for_new_run( int $months_window ): void {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . MAD_CI_DB_Schema::table_raw_orders() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        self::update_status( [
            'state'            => 'collecting',
            'page'             => 0,
            'processed_orders' => 0,
            'since'            => $months_window > 0 ? gmdate( 'Y-m-d', strtotime( "-{$months_window} months" ) ) : null,
            'started_at'       => time(),
            'updated_at'       => time(),
            'finished_at'      => null,
        ] );
    }

    /**
     * Vuelca un lote de pedidos pagados a la tabla scratch y, en modo
     * background, agenda el siguiente paso. Devuelve cuántos pedidos trajo
     * este lote (para que run_sync() sepa cuándo parar).
     */
    public function process_batch( int $page, bool $schedule_next = true ): int {
        $status = self::get_status();

        $args = [
            'status'  => wc_get_is_paid_statuses(),
            'limit'   => self::BATCH_SIZE,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'ASC',
            'return'  => 'objects',
        ];
        if ( ! empty( $status['since'] ) ) {
            $args['date_created'] = '>=' . strtotime( $status['since'] );
        }

        $orders = wc_get_orders( $args );

        global $wpdb;
        $table = MAD_CI_DB_Schema::table_raw_orders();

        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order ) continue;

            $email = strtolower( trim( $order->get_billing_email() ) );
            $date  = $order->get_date_created();
            if ( '' === $email || ! $date ) continue;

            $product_ids = [];
            foreach ( $order->get_items() as $item ) {
                if ( $item instanceof WC_Order_Item_Product && $item->get_product_id() ) {
                    $product_ids[] = $item->get_product_id();
                }
            }

            $login_seconds = $order->get_meta( MAD_CI_Login_Tracker::ORDER_META, true );

            $ok = $wpdb->replace( $table, [
                'order_id'               => $order->get_id(),
                'customer_email'         => $email,
                'user_id'                => $order->get_customer_id(),
                'order_date'             => $date->date( 'Y-m-d H:i:s' ),
                'total'                  => (float) $order->get_total(),
                'product_ids'            => wp_json_encode( array_values( array_unique( $product_ids ) ) ),
                'login_to_order_seconds' => ( '' !== $login_seconds && is_numeric( $login_seconds ) ) ? (int) $login_seconds : null,
            ] );

            if ( false === $ok && defined( 'WP_DEBUG' ) && WP_DEBUG && $wpdb->last_error ) {
                error_log( '[MAD Customer Insights] Error al guardar el pedido #' . $order->get_id() . ' en la tabla scratch: ' . $wpdb->last_error );
            }
        }

        $processed = count( $orders );

        self::update_status( [
            'state'            => 'collecting',
            'page'             => $page,
            'processed_orders' => ( $status['processed_orders'] ?? 0 ) + $processed,
            'started_at'       => $status['started_at'] ?? time(),
            'updated_at'       => time(),
        ] );

        if ( $schedule_next ) {
            if ( $processed === self::BATCH_SIZE ) {
                // Probablemente quedan más páginas.
                $this->schedule( time() + 5, self::HOOK_PROCESS_BATCH, [ 'page' => $page + 1 ] );
            } else {
                // Última página (o ninguna): cerrar con la agregación final.
                self::update_status( [ 'state' => 'finalizing' ] );
                $this->schedule( time() + 5, self::HOOK_FINALIZE );
            }
        }

        return $processed;
    }

    /** Agregación final — ahora barata: lee filas planas de la tabla scratch en vez de reconstruir pedidos. */
    public function finalize(): void {
        ( new MAD_CI_Stats_Calculator() )->recalculate();

        self::update_status( [
            'state'       => 'done',
            'finished_at' => time(),
        ] );
        update_option( 'mad_ci_last_recalculated', time() );
    }

    private function schedule( int $timestamp, string $hook, array $args = [] ): void {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( $timestamp, $hook, $args, self::ACTION_GROUP );
        } else {
            wp_schedule_single_event( $timestamp, $hook, $args );
        }
    }

    // ── Estado (para el aviso de progreso en el dashboard) ────────────────────

    public static function get_status(): array {
        $defaults = [
            'state'            => 'idle', // idle | collecting | finalizing | done
            'page'             => 0,
            'processed_orders' => 0,
            'since'            => null, // Y-m-d: si no es null, solo se recolectan pedidos desde esa fecha.
            'started_at'       => null,
            'updated_at'       => null,
            'finished_at'      => null,
        ];
        $saved = get_option( self::STATUS_OPTION, [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
    }

    private static function update_status( array $partial ): void {
        update_option( self::STATUS_OPTION, array_merge( self::get_status(), $partial ) );
    }

    public static function is_running(): bool {
        return in_array( self::get_status()['state'], [ 'collecting', 'finalizing' ], true );
    }

    /**
     * true si quedó "colgado" en collecting/finalizing sin avanzar en un
     * buen rato — normalmente significa que Action Scheduler no está
     * procesando las acciones en segundo plano en este sitio (revisar
     * WooCommerce → Estado → Acciones programadas).
     */
    public static function is_stuck( int $threshold_seconds = 180 ): bool {
        $status = self::get_status();
        if ( ! in_array( $status['state'], [ 'collecting', 'finalizing' ], true ) ) return false;
        if ( empty( $status['updated_at'] ) ) return false;
        return ( time() - (int) $status['updated_at'] ) > $threshold_seconds;
    }
}
