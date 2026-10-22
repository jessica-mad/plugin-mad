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

    /** Lanza una recolección nueva (si no hay una ya en curso). */
    public function start(): bool {
        $status = self::get_status();
        if ( in_array( $status['state'], [ 'collecting', 'finalizing' ], true ) ) {
            return false; // Ya hay una corriendo — no solaparla.
        }

        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . MAD_CI_DB_Schema::table_raw_orders() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        self::update_status( [
            'state'            => 'collecting',
            'page'             => 0,
            'processed_orders' => 0,
            'started_at'       => time(),
            'updated_at'       => time(),
        ] );

        $this->schedule( time(), self::HOOK_PROCESS_BATCH, [ 'page' => 1 ] );
        return true;
    }

    /** Vuelca un lote de pedidos pagados a la tabla scratch. */
    public function process_batch( int $page ): void {
        $orders = wc_get_orders( [
            'status'  => wc_get_is_paid_statuses(),
            'limit'   => self::BATCH_SIZE,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'ASC',
            'return'  => 'objects',
        ] );

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

            $wpdb->replace( $table, [
                'order_id'               => $order->get_id(),
                'customer_email'         => $email,
                'user_id'                => $order->get_customer_id(),
                'order_date'             => $date->date( 'Y-m-d H:i:s' ),
                'total'                  => (float) $order->get_total(),
                'product_ids'            => wp_json_encode( array_values( array_unique( $product_ids ) ) ),
                'login_to_order_seconds' => ( '' !== $login_seconds && is_numeric( $login_seconds ) ) ? (int) $login_seconds : null,
            ] );
        }

        $processed = count( $orders );
        $status    = self::get_status();

        self::update_status( [
            'state'            => 'collecting',
            'page'             => $page,
            'processed_orders' => ( $status['processed_orders'] ?? 0 ) + $processed,
            'started_at'       => $status['started_at'] ?? time(),
            'updated_at'       => time(),
        ] );

        if ( $processed === self::BATCH_SIZE ) {
            // Probablemente quedan más páginas.
            $this->schedule( time() + 5, self::HOOK_PROCESS_BATCH, [ 'page' => $page + 1 ] );
        } else {
            // Última página (o ninguna): cerrar con la agregación final.
            self::update_status( [ 'state' => 'finalizing' ] );
            $this->schedule( time() + 5, self::HOOK_FINALIZE );
        }
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
}
