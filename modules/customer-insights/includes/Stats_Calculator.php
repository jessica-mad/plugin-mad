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

    public function recalculate(): void {
        $professional_roles = $this->get_professional_role_slugs();

        $customers = $this->gather_customer_orders();
        if ( empty( $customers ) ) {
            $this->truncate_all();
            return;
        }

        $customer_rows = $this->build_customer_stats( $customers, $professional_roles );
        $this->add_rfm_scores( $customer_rows );

        [ $monthly, $cohort_periods, $cohort_sizes ] = $this->build_monthly_and_cohorts( $customers, $customer_rows );
        $product_rows = $this->build_product_repurchase( $customers, $customer_rows );

        $this->truncate_all();
        $this->persist_customers( $customer_rows );
        $this->persist_monthly( $monthly );
        $this->persist_cohorts( $cohort_periods, $cohort_sizes );
        $this->persist_products( $product_rows );

        update_option( 'mad_ci_last_recalculated', time() );
    }

    // ── Paso 1: leer la tabla scratch y agrupar por cliente ───────────────────

    /**
     * @return array<string, array{user_id:int, orders:array<int, array{date:DateTime,total:float,product_ids:int[],login_to_order:mixed}>}>
     *         Indexado por email de facturación en minúsculas.
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

    private function get_professional_role_slugs(): array {
        if ( ! function_exists( 'mad_quotes_get_settings' ) ) return [];
        $settings = mad_quotes_get_settings();
        return array_values( array_filter( (array) ( $settings['store_manager_roles'] ?? [] ) ) );
    }

    private function is_user_professional( int $user_id, array $professional_roles ): bool {
        if ( ! $user_id || empty( $professional_roles ) ) return false;
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;
        return (bool) array_intersect( $professional_roles, (array) $user->roles );
    }

    /** @return array<string, array<string,mixed>> Indexado por email. */
    private function build_customer_stats( array $customers, array $professional_roles ): array {
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
                'is_professional'         => $this->is_user_professional( (int) $data['user_id'], $professional_roles ) ? 1 : 0,
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

    /** Añade rfm_recency_score / rfm_frequency_score / rfm_monetary_score / rfm_segment a cada fila, in-place. */
    private function add_rfm_scores( array &$rows ): void {
        if ( empty( $rows ) ) return;

        $now = time();

        $recency_values   = [];
        $frequency_values = [];
        $monetary_values  = [];
        foreach ( $rows as $email => $r ) {
            $recency_values[ $email ]   = $now - strtotime( $r['last_order_date'] );
            $frequency_values[ $email ] = $r['order_count'];
            $monetary_values[ $email ]  = $r['lifetime_value'];
        }

        // Recencia: menos días desde la última compra = mejor puntaje (se invierte).
        $recency_scores   = $this->quintile_scores( $recency_values, true );
        $frequency_scores = $this->quintile_scores( $frequency_values, false );
        $monetary_scores  = $this->quintile_scores( $monetary_values, false );

        foreach ( $rows as $email => &$r ) {
            $r['rfm_recency_score']   = $recency_scores[ $email ];
            $r['rfm_frequency_score'] = $frequency_scores[ $email ];
            $r['rfm_monetary_score']  = $monetary_scores[ $email ];
            $r['rfm_segment']         = $this->rfm_segment_label( $recency_scores[ $email ], $frequency_scores[ $email ] );
        }
        unset( $r );
    }

    /**
     * Puntaje 1–5 según el quintil de cada valor dentro de la distribución
     * completa de clientes. $invert = true cuando un valor más BAJO merece
     * mejor puntaje (la recencia: menos días = mejor).
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

    private function rfm_segment_label( int $recency, int $frequency ): string {
        if ( $recency >= 4 && $frequency >= 4 ) return __( 'Campeones', 'mad-suite' );
        if ( $recency >= 4 && $frequency < 4 )  return __( 'Nuevos prometedores', 'mad-suite' );
        if ( $recency <= 2 && $frequency >= 4 ) return __( 'En riesgo', 'mad-suite' );
        if ( $recency <= 2 && $frequency <= 2 ) return __( 'Perdidos', 'mad-suite' );
        return __( 'Regulares', 'mad-suite' );
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

            $is_pro   = (bool) $customer_rows[ $email ]['is_professional'];
            $segments = [ 'all', $is_pro ? 'professional' : 'public' ];

            $orders = $data['orders'];
            usort( $orders, fn( $a, $b ) => $a['date'] <=> $b['date'] );
            $cohort_month = $orders[0]['date']->format( 'Y-m-01' );

            foreach ( $segments as $seg ) {
                $ck = $cohort_month . '|' . $seg;
                $cohort_sizes[ $ck ]['cohort_month'] = $cohort_month;
                $cohort_sizes[ $ck ]['segment']      = $seg;
                $cohort_sizes[ $ck ]['customers'][ $email ] = true;
            }

            foreach ( $orders as $i => $order ) {
                $month_key    = $order['date']->format( 'Y-m-01' );
                $is_new_order = ( 0 === $i );
                $has_login    = '' !== $order['login_to_order'] && is_numeric( $order['login_to_order'] );

                foreach ( $segments as $seg ) {
                    $mk = $month_key . '|' . $seg;
                    if ( ! isset( $monthly[ $mk ] ) ) {
                        $monthly[ $mk ] = [
                            'month' => $month_key, 'segment' => $seg,
                            'new_customers' => 0, 'repeat_customers' => 0,
                            'new_revenue' => 0.0, 'repeat_revenue' => 0.0,
                            'login_seconds_sum' => 0, 'login_seconds_count' => 0,
                        ];
                    }
                    if ( $is_new_order ) {
                        $monthly[ $mk ]['new_customers']++;
                        $monthly[ $mk ]['new_revenue'] += $order['total'];
                    } else {
                        $monthly[ $mk ]['repeat_customers']++;
                        $monthly[ $mk ]['repeat_revenue'] += $order['total'];
                    }
                    if ( $has_login ) {
                        $monthly[ $mk ]['login_seconds_sum']   += (int) $order['login_to_order'];
                        $monthly[ $mk ]['login_seconds_count']++;
                    }
                }

                $offset = $this->months_between( $cohort_month, $month_key );
                foreach ( $segments as $seg ) {
                    $pk = $cohort_month . '|' . $offset . '|' . $seg;
                    $cohort_periods[ $pk ]['cohort_month']  = $cohort_month;
                    $cohort_periods[ $pk ]['period_offset'] = $offset;
                    $cohort_periods[ $pk ]['segment']       = $seg;
                    $cohort_periods[ $pk ]['customers'][ $email ] = true;
                }
            }
        }

        return [ $monthly, $cohort_periods, $cohort_sizes ];
    }

    // ── Paso 4: productos con mayor tasa de recompra ──────────────────────────

    private function build_product_repurchase( array $customers, array $customer_rows ): array {
        $product_buyers = []; // "{product_id}|{segment}" => ['product_id'=>, 'segment'=>, 'buyers'=>[email => veces_comprado]]

        foreach ( $customers as $email => $data ) {
            if ( ! isset( $customer_rows[ $email ] ) ) continue;

            $is_pro   = (bool) $customer_rows[ $email ]['is_professional'];
            $segments = [ 'all', $is_pro ? 'professional' : 'public' ];

            $times_per_product = [];
            foreach ( $data['orders'] as $order ) {
                foreach ( $order['product_ids'] as $pid ) {
                    $times_per_product[ $pid ] = ( $times_per_product[ $pid ] ?? 0 ) + 1;
                }
            }

            foreach ( $times_per_product as $pid => $times ) {
                foreach ( $segments as $seg ) {
                    $key = $pid . '|' . $seg;
                    if ( ! isset( $product_buyers[ $key ] ) ) {
                        $product_buyers[ $key ] = [ 'product_id' => $pid, 'segment' => $seg, 'buyers' => [] ];
                    }
                    $product_buyers[ $key ]['buyers'][ $email ] = $times;
                }
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
                'segment'             => $info['segment'],
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
                'is_professional'         => $r['is_professional'],
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
                'segment'                    => $row['segment'],
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
            $sizes[ $info['cohort_month'] . '|' . $info['segment'] ] = count( $info['customers'] );
        }

        foreach ( $cohort_periods as $info ) {
            $size_key = $info['cohort_month'] . '|' . $info['segment'];
            $wpdb->insert( $table, [
                'cohort_month'   => $info['cohort_month'],
                'period_offset'  => $info['period_offset'],
                'segment'        => $info['segment'],
                'cohort_size'    => $sizes[ $size_key ] ?? 0,
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
