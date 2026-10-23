<?php
/**
 * Hace la agregación real (estadísticas por cliente, RFM, serie mensual,
 * cohortes, recompra por producto) a partir de la tabla scratch
 * mad_ci_raw_orders — filas planas, ya volcadas ahí en lotes pequeños por
 * MAD_CI_Batch_Collector. Nunca llama a wc_get_orders() directamente: esa
 * parte, la más cara, ya se repartió en pasos cortos antes de llegar acá.
 * Por eso esta pasada final es barata y puede correr de un tirón.
 *
 * @package MAD_Suite/Customer_Insights
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_CI_Stats_Calculator {

    /** Ritmo típico de recompra de la tienda (días), calculado en add_rfm_scores() y expuesto en el dashboard. */
    private ?float $store_avg_interval_days = null;

    public function recalculate(): void {
        $customers = $this->gather_customer_orders();
        if ( empty( $customers ) ) {
            $this->truncate_all();
            delete_option( 'mad_ci_store_avg_repurchase_days' );
            return;
        }

        $customer_rows = $this->build_customer_stats( $customers );
        $this->add_rfm_scores( $customer_rows );

        [ $monthly, $cohort_periods, $cohort_sizes ] = $this->build_monthly_and_cohorts( $customers, $customer_rows );
        $product_rows = $this->build_product_repurchase( $customers, $customer_rows );

        $this->truncate_all();
        $this->persist_customers( $customer_rows );
        $this->persist_monthly( $monthly );
        $this->persist_cohorts( $cohort_periods, $cohort_sizes );
        $this->persist_products( $product_rows );

        update_option( 'mad_ci_store_avg_repurchase_days', $this->store_avg_interval_days );
        update_option( 'mad_ci_last_recalculated', time() );
    }

    // ── Paso 1: leer la tabla scratch y agrupar por cliente ───────────────────

    /**
     * @return array<string, array{user_id:int, orders:array<int, array{date:DateTime,total:float,product_ids:int[],login_to_order:mixed}>}>
     *         Indexado por email de facturación en minúsculas. El mismo
     *         email agrupa pedidos de invitado y de cuenta registrada por
     *         igual — si alguien compró primero como invitado y luego creó
     *         cuenta (o viceversa), cuenta como el mismo cliente mientras
     *         use el mismo email.
     */
    private function gather_customer_orders(): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_raw_orders();

        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY order_date ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $customers = [];
        foreach ( $rows as $row ) {
            $email = $row['customer_email'];

            if ( ! isset( $customers[ $email ] ) ) {
                $customers[ $email ] = [ 'user_id' => (int) $row['user_id'], 'orders' => [] ];
            } elseif ( ! $customers[ $email ]['user_id'] && (int) $row['user_id'] ) {
                $customers[ $email ]['user_id'] = (int) $row['user_id'];
            }

            $customers[ $email ]['orders'][] = [
                'date'           => new DateTime( $row['order_date'] ),
                'total'          => (float) $row['total'],
                'product_ids'    => (array) ( json_decode( $row['product_ids'], true ) ?: [] ),
                'login_to_order' => $row['login_to_order_seconds'],
            ];
        }

        return $customers;
    }

    // ── Paso 2: estadísticas por cliente ──────────────────────────────────────

    /** @return array<string, array<string,mixed>> Indexado por email. */
    private function build_customer_stats( array $customers ): array {
        $rows = [];

        foreach ( $customers as $email => $data ) {
            $orders = $data['orders'];
            usort( $orders, fn( $a, $b ) => $a['date'] <=> $b['date'] );

            $dates  = array_map( fn( $o ) => $o['date'], $orders );
            $totals = array_map( fn( $o ) => $o['total'], $orders );

            $first = $dates[0];
            $last  = end( $dates );
            $count = count( $orders );
            $ltv   = array_sum( $totals );
            $aov   = $count ? $ltv / $count : 0.0;

            $avg_gap = null;
            if ( $count > 1 ) {
                $gaps = [];
                for ( $i = 1; $i < $count; $i++ ) {
                    $gaps[] = ( $dates[ $i ]->getTimestamp() - $dates[ $i - 1 ]->getTimestamp() ) / DAY_IN_SECONDS;
                }
                $avg_gap = array_sum( $gaps ) / count( $gaps );
            }

            // Específicamente 1ª → 2ª compra (cuándo se vuelve recurrente),
            // distinto de avg_days_between_orders (cadencia general entre
            // todas las compras consecutivas).
            $days_to_second = $count > 1
                ? ( $dates[1]->getTimestamp() - $dates[0]->getTimestamp() ) / DAY_IN_SECONDS
                : null;

            $rows[ $email ] = [
                'customer_email'          => $email,
                'user_id'                 => (int) $data['user_id'],
                'first_order_date'        => $first->format( 'Y-m-d H:i:s' ),
                'last_order_date'         => $last->format( 'Y-m-d H:i:s' ),
                'order_count'             => $count,
                'lifetime_value'          => round( $ltv, 2 ),
                'avg_order_value'         => round( $aov, 2 ),
                'avg_days_between_orders' => null !== $avg_gap ? round( $avg_gap, 2 ) : null,
                'days_to_second_order'    => null !== $days_to_second ? round( $days_to_second, 2 ) : null,
            ];
        }

        return $rows;
    }

    /**
     * Añade rfm_recency_score / rfm_frequency_score / rfm_monetary_score /
     * rfm_segment a cada fila, in-place.
     *
     * La recencia YA NO se mide por quintiles (comparar a cada cliente
     * contra el resto) — eso podía meter en "Nuevos prometedores" a alguien
     * que compró hace 2 años, si el resto de la base estaba aún más fría.
     * Ahora se mide contra un ritmo de recompra ABSOLUTO: cuántas veces su
     * propio intervalo habitual entre compras (o el promedio de la tienda,
     * si solo lleva un pedido y no tiene ritmo propio todavía) lleva sin
     * volver a comprar. Así "a tiempo", "va tarde" o "ya se perdió" se
     * decide con el mismo criterio para todos, sin importar cuántos otros
     * clientes están igual o peor.
     */
    private function add_rfm_scores( array &$rows ): void {
        if ( empty( $rows ) ) return;

        $now = time();

        // Ritmo típico de recompra de ESTA tienda: promedio del intervalo
        // propio de los clientes que ya repitieron. Es el benchmark que se
        // usa también para los de un solo pedido, que todavía no tienen un
        // ritmo propio con el que compararse.
        $known_intervals = [];
        foreach ( $rows as $r ) {
            if ( null !== $r['avg_days_between_orders'] ) $known_intervals[] = $r['avg_days_between_orders'];
        }
        $store_avg_interval = ! empty( $known_intervals ) ? array_sum( $known_intervals ) / count( $known_intervals ) : null;
        $this->store_avg_interval_days = null !== $store_avg_interval ? round( $store_avg_interval, 1 ) : null;

        $frequency_values = [];
        $monetary_values  = [];
        foreach ( $rows as $email => $r ) {
            $frequency_values[ $email ] = $r['order_count'];
            $monetary_values[ $email ]  = $r['lifetime_value'];
        }
        // Frecuencia y valor monetario sí siguen por quintiles — no dependen
        // del tiempo, comparar "cuánto compra uno frente a otro" sí tiene sentido ahí.
        $frequency_scores = $this->quintile_scores( $frequency_values, false );
        $monetary_scores  = $this->quintile_scores( $monetary_values, false );

        foreach ( $rows as $email => &$r ) {
            $is_repeat          = $r['order_count'] > 1;
            $benchmark_interval = $r['avg_days_between_orders'] ?? $store_avg_interval;
            $days_since_last    = ( $now - strtotime( $r['last_order_date'] ) ) / DAY_IN_SECONDS;
            $ratio              = ( $benchmark_interval && $benchmark_interval > 0 ) ? $days_since_last / $benchmark_interval : null;

            $r['rfm_recency_score']   = $this->recency_score_from_ratio( $ratio );
            $r['rfm_frequency_score'] = $frequency_scores[ $email ];
            $r['rfm_monetary_score']  = $monetary_scores[ $email ];
            $r['rfm_segment']         = $this->rfm_segment_label( $is_repeat, $ratio );
        }
        unset( $r );
    }

    /** Puntaje 1–5 a partir de cuántas veces su ritmo esperado de recompra lleva un cliente sin comprar. */
    private function recency_score_from_ratio( ?float $ratio ): int {
        if ( null === $ratio ) return 3; // Sin benchmark todavía (tienda sin repetidores) — neutral.
        if ( $ratio <= 1.0 ) return 5;   // Dentro de su ventana habitual.
        if ( $ratio <= 2.0 ) return 3;   // Va tarde, pero recuperable.
        return 1;                        // Ya pasó más del doble de su ritmo esperado.
    }

    /**
     * Puntaje 1–5 según el quintil de cada valor dentro de la distribución
     * completa de clientes. $invert = true cuando un valor más BAJO merece
     * mejor puntaje. Solo se usa ya para frecuencia y valor monetario — la
     * recencia se decide con recency_score_from_ratio(), no por quintiles.
     *
     * @return array<string,int> Mismas claves que $values.
     */
    private function quintile_scores( array $values, bool $invert ): array {
        $sorted = $values;
        asort( $sorted ); // Menor a mayor, conserva las claves (emails).
        $keys = array_keys( $sorted );
        $n    = count( $keys );
        $scores = [];

        foreach ( $keys as $i => $key ) {
            $percentile = $n > 1 ? $i / ( $n - 1 ) : 0;
            $score      = max( 1, min( 5, (int) ceil( $percentile * 5 ) ) );
            $scores[ $key ] = $invert ? ( 6 - $score ) : $score;
        }

        return $scores;
    }

    /**
     * Segmento RFM a partir de si el cliente ya repitió (2+ pedidos) y de
     * cuántas veces su ritmo esperado de recompra lleva sin volver a
     * comprar ($ratio — ver add_rfm_scores()). Ver rfm_segment_definitions()
     * en Dashboard_Data.php para las descripciones que se muestran en el
     * dashboard — deben coincidir con estas reglas:
     *
     *                  ratio ≤ 1          1 < ratio ≤ 2       ratio > 2
     *   1 pedido    Nuevos prometedores   Regulares           Perdidos
     *   2+ pedidos  Campeones             En riesgo           Perdidos
     */
    private function rfm_segment_label( bool $is_repeat, ?float $ratio ): string {
        if ( null === $ratio ) {
            // Tienda sin repetidores todavía — no hay benchmark con el que juzgar a nadie.
            return $is_repeat ? __( 'Regulares', 'mad-suite' ) : __( 'Nuevos prometedores', 'mad-suite' );
        }
        if ( $ratio <= 1.0 ) {
            return $is_repeat ? __( 'Campeones', 'mad-suite' ) : __( 'Nuevos prometedores', 'mad-suite' );
        }
        if ( $ratio <= 2.0 ) {
            return $is_repeat ? __( 'En riesgo', 'mad-suite' ) : __( 'Regulares', 'mad-suite' );
        }
        return __( 'Perdidos', 'mad-suite' );
    }

    // ── Paso 3: serie mensual (nuevos vs. recurrentes) y cohortes ─────────────

    private function months_between( string $ym1, string $ym2 ): int {
        [ $y1, $m1 ] = array_map( 'intval', explode( '-', $ym1 ) );
        [ $y2, $m2 ] = array_map( 'intval', explode( '-', $ym2 ) );
        return ( $y2 - $y1 ) * 12 + ( $m2 - $m1 );
    }

    /** @return array{0: array<string,array>, 1: array<string,array>, 2: array<string,array>} [monthly, cohort_periods, cohort_sizes] */
    private function build_monthly_and_cohorts( array $customers, array $customer_rows ): array {
        $monthly        = [];
        $cohort_periods = [];
        $cohort_sizes   = [];

        foreach ( $customers as $email => $data ) {
            if ( ! isset( $customer_rows[ $email ] ) ) continue;

            $orders = $data['orders'];
            usort( $orders, fn( $a, $b ) => $a['date'] <=> $b['date'] );
            $cohort_month = $orders[0]['date']->format( 'Y-m-01' );

            $cohort_sizes[ $cohort_month ]['cohort_month'] = $cohort_month;
            $cohort_sizes[ $cohort_month ]['customers'][ $email ] = true;

            foreach ( $orders as $i => $order ) {
                $month_key    = $order['date']->format( 'Y-m-01' );
                $is_new_order = ( 0 === $i );
                $has_login    = '' !== $order['login_to_order'] && is_numeric( $order['login_to_order'] );

                if ( ! isset( $monthly[ $month_key ] ) ) {
                    $monthly[ $month_key ] = [
                        'month' => $month_key,
                        'new_customers' => 0, 'repeat_customers' => 0,
                        'new_revenue' => 0.0, 'repeat_revenue' => 0.0,
                        'login_seconds_sum' => 0, 'login_seconds_count' => 0,
                    ];
                }
                if ( $is_new_order ) {
                    $monthly[ $month_key ]['new_customers']++;
                    $monthly[ $month_key ]['new_revenue'] += $order['total'];
                } else {
                    $monthly[ $month_key ]['repeat_customers']++;
                    $monthly[ $month_key ]['repeat_revenue'] += $order['total'];
                }
                if ( $has_login ) {
                    $monthly[ $month_key ]['login_seconds_sum']   += (int) $order['login_to_order'];
                    $monthly[ $month_key ]['login_seconds_count']++;
                }

                $offset = $this->months_between( $cohort_month, $month_key );
                $pk     = $cohort_month . '|' . $offset;
                $cohort_periods[ $pk ]['cohort_month']  = $cohort_month;
                $cohort_periods[ $pk ]['period_offset'] = $offset;
                $cohort_periods[ $pk ]['customers'][ $email ] = true;
            }
        }

        return [ $monthly, $cohort_periods, $cohort_sizes ];
    }

    // ── Paso 4: productos con mayor tasa de recompra ──────────────────────────

    private function build_product_repurchase( array $customers, array $customer_rows ): array {
        $product_buyers = []; // product_id => ['buyers' => [email => veces_comprado]]

        foreach ( $customers as $email => $data ) {
            if ( ! isset( $customer_rows[ $email ] ) ) continue;

            $times_per_product = [];
            foreach ( $data['orders'] as $order ) {
                foreach ( $order['product_ids'] as $pid ) {
                    $times_per_product[ $pid ] = ( $times_per_product[ $pid ] ?? 0 ) + 1;
                }
            }

            foreach ( $times_per_product as $pid => $times ) {
                if ( ! isset( $product_buyers[ $pid ] ) ) {
                    $product_buyers[ $pid ] = [ 'product_id' => $pid, 'buyers' => [] ];
                }
                $product_buyers[ $pid ]['buyers'][ $email ] = $times;
            }
        }

        $rows = [];
        foreach ( $product_buyers as $info ) {
            $buyers_count        = count( $info['buyers'] );
            $repeat_buyers       = array_filter( $info['buyers'], fn( $t ) => $t > 1 );
            $repeat_buyers_count = count( $repeat_buyers );
            $repeat_orders_count = array_sum( array_map( fn( $t ) => $t - 1, $repeat_buyers ) );

            $rows[] = [
                'product_id'          => $info['product_id'],
                'buyers_count'        => $buyers_count,
                'repeat_buyers_count' => $repeat_buyers_count,
                'repeat_orders_count' => $repeat_orders_count,
                'repurchase_rate'     => $buyers_count > 0 ? round( $repeat_buyers_count / $buyers_count * 100, 2 ) : 0.0,
            ];
        }

        return $rows;
    }

    // ── Persistencia ───────────────────────────────────────────────────────────

    private function truncate_all(): void {
        global $wpdb;
        foreach ( [
            MAD_CI_DB_Schema::table_customers(),
            MAD_CI_DB_Schema::table_monthly(),
            MAD_CI_DB_Schema::table_cohorts(),
            MAD_CI_DB_Schema::table_product_repurchase(),
        ] as $table ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    private function persist_customers( array $rows ): void {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();
        $now   = current_time( 'mysql' );

        foreach ( $rows as $r ) {
            $wpdb->insert( $table, [
                'customer_email'          => $r['customer_email'],
                'user_id'                 => $r['user_id'],
                'first_order_date'        => $r['first_order_date'],
                'last_order_date'         => $r['last_order_date'],
                'order_count'             => $r['order_count'],
                'lifetime_value'          => $r['lifetime_value'],
                'avg_order_value'         => $r['avg_order_value'],
                'avg_days_between_orders' => $r['avg_days_between_orders'],
                'days_to_second_order'    => $r['days_to_second_order'],
                'rfm_recency_score'       => $r['rfm_recency_score'],
                'rfm_frequency_score'     => $r['rfm_frequency_score'],
                'rfm_monetary_score'      => $r['rfm_monetary_score'],
                'rfm_segment'             => $r['rfm_segment'],
                'updated_at'              => $now,
            ] );
        }
    }

    private function persist_monthly( array $monthly ): void {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_monthly();

        foreach ( $monthly as $row ) {
            $avg_login = $row['login_seconds_count'] > 0
                ? (int) round( $row['login_seconds_sum'] / $row['login_seconds_count'] )
                : null;

            $wpdb->insert( $table, [
                'month'                      => $row['month'],
                'new_customers'              => $row['new_customers'],
                'repeat_customers'           => $row['repeat_customers'],
                'new_revenue'                => round( $row['new_revenue'], 2 ),
                'repeat_revenue'             => round( $row['repeat_revenue'], 2 ),
                'avg_login_to_order_seconds' => $avg_login,
            ] );
        }
    }

    private function persist_cohorts( array $cohort_periods, array $cohort_sizes ): void {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_cohorts();

        $sizes = [];
        foreach ( $cohort_sizes as $info ) {
            $sizes[ $info['cohort_month'] ] = count( $info['customers'] );
        }

        foreach ( $cohort_periods as $info ) {
            $wpdb->insert( $table, [
                'cohort_month'   => $info['cohort_month'],
                'period_offset'  => $info['period_offset'],
                'cohort_size'    => $sizes[ $info['cohort_month'] ] ?? 0,
                'retained_count' => count( $info['customers'] ),
            ] );
        }
    }

    private function persist_products( array $rows ): void {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_product_repurchase();

        foreach ( $rows as $r ) {
            $wpdb->insert( $table, $r );
        }
    }
}
