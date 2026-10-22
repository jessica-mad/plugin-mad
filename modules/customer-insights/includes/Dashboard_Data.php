<?php
/**
 * Capa de lectura para el dashboard — consultas de solo lectura contra
 * las tablas ya precalculadas (nunca contra wc_get_orders() en vivo).
 *
 * @package MAD_Suite/Customer_Insights
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_CI_Dashboard_Data {

    /** @var string 'all' | 'public' | 'professional' */
    private $segment;

    public function __construct( string $segment = 'all' ) {
        $this->segment = in_array( $segment, [ 'all', 'public', 'professional' ], true ) ? $segment : 'all';
    }

    /**
     * KPIs de cliente — son promedios/totales históricos (de toda la vida
     * del cliente), no acotados al rango de fechas del dashboard: la tasa
     * de recompra, el CLV o la frecuencia de un cliente no cambian según
     * qué mes estés mirando.
     */
    public function get_customer_kpis(): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();
        $where = $this->customers_where();

        $row = $wpdb->get_row( "
            SELECT
                COUNT(*)                                        AS total_customers,
                SUM(CASE WHEN order_count > 1 THEN 1 ELSE 0 END) AS repeat_customers,
                AVG(lifetime_value)                              AS avg_ltv,
                AVG(avg_order_value)                             AS avg_aov,
                AVG(avg_days_between_orders)                     AS avg_frequency_days,
                AVG(days_to_second_order)                        AS avg_days_to_second_order
            FROM {$table}
            WHERE {$where}
        ", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $total  = (int) ( $row['total_customers'] ?? 0 );
        $repeat = (int) ( $row['repeat_customers'] ?? 0 );

        return [
            'total_customers'          => $total,
            'repeat_customers'         => $repeat,
            'repeat_rate'              => $total > 0 ? round( $repeat / $total * 100, 1 ) : 0.0,
            'avg_ltv'                  => round( (float) ( $row['avg_ltv'] ?? 0 ), 2 ),
            'avg_aov'                  => round( (float) ( $row['avg_aov'] ?? 0 ), 2 ),
            'avg_frequency_days'       => null !== $row['avg_frequency_days'] ? round( (float) $row['avg_frequency_days'], 1 ) : null,
            'avg_days_to_second_order' => null !== $row['avg_days_to_second_order'] ? round( (float) $row['avg_days_to_second_order'], 1 ) : null,
        ];
    }

    /** Promedio de login→compra, ponderado por nº de pedidos, dentro del rango de fechas. */
    public function get_login_to_purchase_avg( string $from, string $to ): ?float {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_monthly();

        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT
                SUM(avg_login_to_order_seconds * (new_customers + repeat_customers)) AS weighted_sum,
                SUM(CASE WHEN avg_login_to_order_seconds IS NOT NULL THEN (new_customers + repeat_customers) ELSE 0 END) AS weight
            FROM {$table}
            WHERE segment = %s AND month BETWEEN %s AND %s
        ", $this->segment, $from, $to ), ARRAY_A );

        if ( empty( $row['weight'] ) ) return null;
        return round( ( (float) $row['weighted_sum'] ) / ( (int) $row['weight'] ) / DAY_IN_SECONDS, 2 ); // en días
    }

    /** Serie mensual (nuevos vs. recurrentes) dentro del rango de fechas. */
    public function get_monthly_series( string $from, string $to ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_monthly();

        return $wpdb->get_results( $wpdb->prepare( "
            SELECT month, new_customers, repeat_customers, new_revenue, repeat_revenue
            FROM {$table}
            WHERE segment = %s AND month BETWEEN %s AND %s
            ORDER BY month ASC
        ", $this->segment, $from, $to ), ARRAY_A );
    }

    /**
     * Grid de retención por cohortes: filas = mes de cohorte, columnas =
     * meses transcurridos desde la primera compra (0, 1, 2…).
     *
     * @return array{cohorts: array<string,array>, max_period: int}
     */
    public function get_cohort_grid( int $max_cohorts = 12, int $max_periods = 6 ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_cohorts();

        $cohort_months = $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT cohort_month FROM {$table}
            WHERE segment = %s
            ORDER BY cohort_month DESC
            LIMIT %d
        ", $this->segment, $max_cohorts ) );

        if ( empty( $cohort_months ) ) return [ 'cohorts' => [], 'max_period' => 0 ];

        $placeholders = implode( ',', array_fill( 0, count( $cohort_months ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT cohort_month, period_offset, cohort_size, retained_count
            FROM {$table}
            WHERE segment = %s AND period_offset <= %d AND cohort_month IN ({$placeholders})
            ORDER BY cohort_month ASC, period_offset ASC
        ", array_merge( [ $this->segment, $max_periods ], $cohort_months ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $cohorts = [];
        foreach ( $rows as $row ) {
            $month = $row['cohort_month'];
            if ( ! isset( $cohorts[ $month ] ) ) {
                $cohorts[ $month ] = [ 'size' => (int) $row['cohort_size'], 'periods' => [] ];
            }
            $size = (int) $row['cohort_size'];
            $cohorts[ $month ]['periods'][ (int) $row['period_offset'] ] = [
                'retained' => (int) $row['retained_count'],
                'pct'      => $size > 0 ? round( $row['retained_count'] / $size * 100, 1 ) : 0.0,
            ];
        }
        ksort( $cohorts );

        return [ 'cohorts' => $cohorts, 'max_period' => $max_periods ];
    }

    /** @return array<string,int> rfm_segment => nº de clientes. */
    public function get_rfm_breakdown(): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();
        $where = $this->customers_where();

        $rows = $wpdb->get_results( "
            SELECT rfm_segment, COUNT(*) AS total
            FROM {$table}
            WHERE {$where}
            GROUP BY rfm_segment
            ORDER BY total DESC
        ", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $out = [];
        foreach ( $rows as $row ) {
            $out[ $row['rfm_segment'] ] = (int) $row['total'];
        }
        return $out;
    }

    public function get_top_customers( int $limit = 10 ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();
        $where = $this->customers_where();

        return $wpdb->get_results( $wpdb->prepare( "
            SELECT customer_email, user_id, order_count, lifetime_value, last_order_date
            FROM {$table}
            WHERE {$where}
            ORDER BY lifetime_value DESC
            LIMIT %d
        ", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Clientes con 2+ pedidos cuya ausencia ya duplica su propio intervalo habitual de compra. */
    public function get_at_risk_customers( int $limit = 20 ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_customers();
        $where = $this->customers_where();

        return $wpdb->get_results( $wpdb->prepare( "
            SELECT customer_email, user_id, order_count, lifetime_value, last_order_date,
                   avg_days_between_orders,
                   DATEDIFF(NOW(), last_order_date) AS days_since_last_order
            FROM {$table}
            WHERE {$where}
              AND order_count > 1
              AND avg_days_between_orders IS NOT NULL
              AND DATEDIFF(NOW(), last_order_date) > (avg_days_between_orders * 2)
            ORDER BY lifetime_value DESC
            LIMIT %d
        ", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Productos con mayor tasa de recompra (con un mínimo de compradores para que no sea ruido). */
    public function get_top_repurchased_products( int $limit = 15, int $min_buyers = 5 ): array {
        global $wpdb;
        $table = MAD_CI_DB_Schema::table_product_repurchase();

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT product_id, buyers_count, repeat_buyers_count, repeat_orders_count, repurchase_rate
            FROM {$table}
            WHERE segment = %s AND buyers_count >= %d
            ORDER BY repurchase_rate DESC, buyers_count DESC
            LIMIT %d
        ", $this->segment, $min_buyers, $limit ), ARRAY_A );

        foreach ( $rows as &$row ) {
            $row['product_name'] = get_the_title( $row['product_id'] ) ?: ( '#' . $row['product_id'] );
        }
        unset( $row );

        return $rows;
    }

    public function get_last_recalculated(): ?int {
        $ts = get_option( 'mad_ci_last_recalculated' );
        return $ts ? (int) $ts : null;
    }

    private function customers_where(): string {
        if ( 'public' === $this->segment )       return 'is_professional = 0';
        if ( 'professional' === $this->segment ) return 'is_professional = 1';
        return '1=1';
    }
}
