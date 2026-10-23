<?php
/**
 * Customer Insights — panel de analítica de clientes para WooCommerce:
 * clientes nuevos vs. recurrentes, valor de vida del cliente (CLV),
 * frecuencia de compra, cohortes de retención, segmentación RFM,
 * productos con mayor tasa de recompra, y tiempo de login a compra.
 *
 * Los números se precalculan por cron nocturno (o bajo demanda con el
 * botón "Recalcular ahora") en tablas propias — el dashboard nunca
 * consulta wc_get_orders() en vivo, para que cargue rápido sin importar
 * cuántos pedidos tenga la tienda. El recálculo en sí corre en lotes
 * pequeños en segundo plano (ver includes/Batch_Collector.php), nunca de
 * un solo golpe, para no competir por recursos con las visitas reales de
 * una tienda con muchos pedidos existentes.
 *
 * @package MAD_Suite/Customer_Insights
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/DB_Schema.php';
require_once __DIR__ . '/includes/Login_Tracker.php';
require_once __DIR__ . '/includes/Stats_Calculator.php';
require_once __DIR__ . '/includes/Batch_Collector.php';
require_once __DIR__ . '/includes/Dashboard_Data.php';

return new class ( $core ?? null ) implements MAD_Suite_Module {

    private $core;
    private string $slug = 'customer-insights';
    private MAD_CI_Batch_Collector $batch_collector;

    private const CRON_HOOK      = 'mad_ci_nightly_recalculate';
    private const RECALC_LOCK_KEY = 'mad_ci_recalc_lock';
    private const RECALC_LOCK_TTL = 5 * MINUTE_IN_SECONDS;

    public function __construct( $core ) {
        $this->core            = $core;
        $this->batch_collector = new MAD_CI_Batch_Collector();
    }

    // ── MAD_Suite_Module interface ────────────────────────────────────────────

    public function slug(): string       { return $this->slug; }
    public function title(): string      { return __( 'Analítica de Clientes', 'mad-suite' ); }
    public function menu_label(): string { return __( 'Clientes', 'mad-suite' ); }
    public function menu_slug(): string  { return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug; }

    public function description(): string {
        return __( 'Clientes nuevos vs. recurrentes, valor de vida del cliente, frecuencia de compra, cohortes de retención, segmentación RFM y productos con mayor recompra.', 'mad-suite' );
    }

    public function required_plugins(): array {
        return [ 'WooCommerce' => 'woocommerce/woocommerce.php' ];
    }

    public function init(): void {
        MAD_CI_DB_Schema::maybe_create_tables();

        $login_tracker = new MAD_CI_Login_Tracker();
        $login_tracker->init();

        $this->batch_collector->init();

        // El cron nocturno dispara el recolector en lotes (igual que el botón
        // "Recalcular ahora"), nunca la agregación pesada de un solo golpe.
        add_action( self::CRON_HOOK, [ $this->batch_collector, 'start' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }

        if ( is_admin() ) {
            add_action( 'admin_post_mad_ci_recalculate', [ $this, 'handle_manual_recalculate' ] );
            add_action( 'admin_post_mad_ci_export_customers', [ $this, 'handle_export_customers' ] );
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        }
    }

    public function admin_init(): void {
        // Sin Settings API: esta es una página de analítica, no de ajustes.
    }

    public function handle_manual_recalculate(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }
        check_admin_referer( 'mad_ci_recalculate' );

        $window = isset( $_POST['mad_ci_window'] ) ? (int) $_POST['mad_ci_window'] : 0;
        if ( ! in_array( $window, [ 0, 3, 6, 12 ], true ) ) $window = 0;

        $mode = isset( $_POST['mad_ci_mode'] ) ? sanitize_key( $_POST['mad_ci_mode'] ) : 'background';

        if ( 'sync' === $mode && 0 === $window ) {
            // El modo directo (de prueba) nunca corre sobre el histórico completo
            // — para eso está el modo en segundo plano.
            $mode = 'background';
        }

        if ( MAD_CI_Batch_Collector::is_running() ) {
            $notice = 'already_running';
        } elseif ( false === get_transient( self::RECALC_LOCK_KEY ) ) {
            set_transient( self::RECALC_LOCK_KEY, 1, self::RECALC_LOCK_TTL );

            if ( 'sync' === $mode ) {
                if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                $this->batch_collector->run_sync( $window );
                $notice = 'sync_done';
            } else {
                $this->batch_collector->start( $window );
                $notice = 'started';
            }
        } else {
            $notice = 'rate_limited';
        }

        wp_safe_redirect( add_query_arg(
            array_merge( $this->current_dashboard_query_args(), [ 'mad_ci_notice' => $notice ] ),
            admin_url( 'admin.php?page=' . $this->menu_slug() )
        ) );
        exit;
    }

    /** Descarga en CSV el listado de clientes (opcionalmente filtrado por segmento RFM) para campañas externas. */
    public function handle_export_customers(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }
        check_admin_referer( 'mad_ci_export_customers' );

        $segment = isset( $_REQUEST['mad_ci_segment'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mad_ci_segment'] ) ) : '';
        $valid   = array_keys( MAD_CI_Dashboard_Data::rfm_segment_definitions() );
        if ( ! in_array( $segment, $valid, true ) ) $segment = '';

        $rows = ( new MAD_CI_Dashboard_Data() )->get_customers_list( $segment, 50000 );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="clientes-' . ( $segment ? sanitize_title( $segment ) : 'todos' ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // BOM UTF-8, para que Excel muestre bien los acentos.
        fputcsv( $out, [ 'Email', 'Segmento RFM', 'Pedidos', 'Primera compra', 'Última compra', 'Valor total', 'Ticket promedio' ] );
        foreach ( $rows as $r ) {
            fputcsv( $out, [
                $r['customer_email'],
                $r['rfm_segment'],
                $r['order_count'],
                $r['first_order_date'],
                $r['last_order_date'],
                $r['lifetime_value'],
                $r['avg_order_value'],
            ] );
        }
        fclose( $out );
        exit;
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, $this->menu_slug() ) === false ) return;

        $url = plugin_dir_url( __FILE__ );
        $dir = __DIR__;

        // Chart.js vendorizado no cambia nunca — su propia versión basta.
        wp_enqueue_script( 'mad-ci-chartjs', $url . 'assets/js/chart.umd.min.js', [], '4.4.4', true );

        // dashboard.js y admin.css sí cambian con cada actualización del
        // módulo: se versionan con la fecha de modificación del archivo
        // (no con un número fijo) para que el navegador del cliente
        // siempre baje la copia nueva en vez de servir la cacheada.
        $js_path  = $dir . '/assets/js/dashboard.js';
        $css_path = $dir . '/assets/css/admin.css';
        wp_enqueue_script( 'mad-ci-dashboard', $url . 'assets/js/dashboard.js', [ 'mad-ci-chartjs' ], (string) ( file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0' ), true );
        wp_enqueue_style( 'mad-ci-admin', $url . 'assets/css/admin.css', [], (string) ( file_exists( $css_path ) ? filemtime( $css_path ) : '1.0.0' ) );
    }

    // ── Página del dashboard ────────────────────────────────────────────────

    public function render_settings_page(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }

        [ $from, $to ]  = $this->get_current_date_range();
        $granularity    = $this->get_current_granularity();
        $compare        = $this->get_current_compare();
        $segment        = $this->get_current_segment();
        $customers_limit = $this->get_current_customers_limit();

        $data              = new MAD_CI_Dashboard_Data();
        $kpis              = $data->get_customer_kpis();
        $login_avg_days    = $data->get_login_to_purchase_avg( $from, $to );
        $period_series     = $data->get_period_series( $from, $to, $granularity );
        $cohort_grid       = $data->get_cohort_grid( 12, 12 );
        $rfm_breakdown     = $data->get_rfm_breakdown();
        $rfm_definitions   = MAD_CI_Dashboard_Data::rfm_segment_definitions();
        $store_avg_repurchase_days = $data->get_store_avg_repurchase_days();
        $customers_list    = $data->get_customers_list( $segment, $customers_limit );
        $customers_total   = $data->count_customers( $segment );
        $top_products   = $data->get_top_repurchased_products();
        $last_updated   = $data->get_last_recalculated();
        $recalc_status  = MAD_CI_Batch_Collector::get_status();
        $recalc_stuck   = MAD_CI_Batch_Collector::is_stuck();

        $recalc_action_url  = admin_url( 'admin-post.php' );
        $recalc_nonce_field = wp_nonce_field( 'mad_ci_recalculate', '_wpnonce', true, false );
        $recalc_hidden_args = array_merge( $this->current_dashboard_query_args(), [ 'action' => 'mad_ci_recalculate' ] );

        // Comparador con el mismo periodo del año anterior: funciona con
        // cualquier granularidad salvo "Anual" (ahí el propio selector ya
        // deja comparar años uno junto al otro, como barras separadas).
        // Cada periodo se empareja con el mismo periodo exacto UN AÑO
        // antes (no solo "el mismo número de mes/trimestre", que
        // mezclaría años distintos si el rango abarca varios años) — si
        // ese periodo anterior no tiene pedidos (p. ej. antes de que la
        // tienda existiera), queda como null y no se dibuja nada ahí.
        $compare_payload = null;
        if ( $compare && 'year' !== $granularity ) {
            $prior_from   = gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $from ) ) );
            $prior_to     = gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $to ) ) );
            $prior_series = $data->get_period_series( $prior_from, $prior_to, $granularity );

            $prior_by_period = [];
            foreach ( $prior_series as $row ) {
                $prior_by_period[ $row['period'] ] = $row;
            }

            $previous_new = $previous_repeat = $previous_total = [];
            foreach ( $period_series as $row ) {
                $prior_key = $this->prior_period_key( $row['period'] );
                $prev      = $prior_by_period[ $prior_key ] ?? null;

                $previous_new[]    = $prev ? (int) $prev['new_customers'] : null;
                $previous_repeat[] = $prev ? (int) $prev['repeat_customers'] : null;
                $previous_total[]  = $prev ? ( (int) $prev['new_customers'] + (int) $prev['repeat_customers'] ) : null;
            }
            $compare_payload = [
                'new'    => $previous_new,
                'repeat' => $previous_repeat,
                'total'  => $previous_total,
            ];
        }

        wp_add_inline_script( 'mad-ci-dashboard', 'window.madCIData = ' . wp_json_encode( [
            'periods' => array_map( static function ( $row ) {
                return [
                    'label'            => $row['label'],
                    'new_customers'    => (int) $row['new_customers'],
                    'repeat_customers' => (int) $row['repeat_customers'],
                ];
            }, $period_series ),
            'compare' => $compare_payload,
            'i18n' => [
                'new'        => __( 'Nuevos', 'mad-suite' ),
                'repeat'     => __( 'Recurrentes', 'mad-suite' ),
                'newPrev'    => __( 'Nuevos (año anterior)', 'mad-suite' ),
                'repeatPrev' => __( 'Recurrentes (año anterior)', 'mad-suite' ),
                'totalPrev'  => __( 'Total (año anterior)', 'mad-suite' ),
            ],
        ] ) . ';', 'before' );

        include __DIR__ . '/views/dashboard.php';
    }

    /**
     * A partir de una clave de periodo ('YYYY-MM', 'YYYY-Qn' o 'YYYY-Sn'),
     * la clave del mismo periodo un año antes — le resta 1 al año y deja
     * el resto igual (mes, trimestre o semestre no cambian).
     */
    private function prior_period_key( string $period ): string {
        $year = (int) substr( $period, 0, 4 );
        $rest = substr( $period, 4 );
        return ( $year - 1 ) . $rest;
    }

    /** @return array{0:string,1:string} [from, to] en formato Y-m-d, por defecto los últimos 12 meses. */
    private function get_current_date_range(): array {
        $default_to   = current_time( 'Y-m-d' );
        $default_from = date( 'Y-m-d', strtotime( '-12 months', strtotime( $default_to ) ) );

        $from = isset( $_REQUEST['mad_ci_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mad_ci_from'] ) ) : $default_from;
        $to   = isset( $_REQUEST['mad_ci_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mad_ci_to'] ) ) : $default_to;

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $from = $default_from;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $to   = $default_to;

        return [ $from, $to ];
    }

    /** 'month' | 'quarter' | 'semester' | 'year' — tamaño de bloque para la gráfica de nuevos vs. recurrentes. */
    private function get_current_granularity(): string {
        $allowed = [ 'month', 'quarter', 'semester', 'year' ];
        $value   = isset( $_REQUEST['mad_ci_granularity'] ) ? sanitize_key( $_REQUEST['mad_ci_granularity'] ) : 'month';
        return in_array( $value, $allowed, true ) ? $value : 'month';
    }

    /** Si está activo el comparador "mismo mes, año anterior" en la gráfica. */
    private function get_current_compare(): bool {
        return ! empty( $_REQUEST['mad_ci_compare'] );
    }

    /** Segmento RFM por el que se filtra la tabla de clientes ('' = todos). */
    private function get_current_segment(): string {
        $value = isset( $_REQUEST['mad_ci_segment'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mad_ci_segment'] ) ) : '';
        $valid = array_keys( MAD_CI_Dashboard_Data::rfm_segment_definitions() );
        return in_array( $value, $valid, true ) ? $value : '';
    }

    /** Cuántos clientes mostrar como máximo en la tabla "Clientes". */
    private function get_current_customers_limit(): int {
        $allowed = [ 25, 50, 100, 300, 1000 ];
        $value   = isset( $_REQUEST['mad_ci_customers_limit'] ) ? (int) $_REQUEST['mad_ci_customers_limit'] : 100;
        return in_array( $value, $allowed, true ) ? $value : 100;
    }

    private function current_dashboard_query_args(): array {
        [ $from, $to ] = $this->get_current_date_range();
        return [
            'mad_ci_from'             => $from,
            'mad_ci_to'               => $to,
            'mad_ci_granularity'      => $this->get_current_granularity(),
            'mad_ci_compare'          => $this->get_current_compare() ? 1 : 0,
            'mad_ci_segment'          => $this->get_current_segment(),
            'mad_ci_customers_limit'  => $this->get_current_customers_limit(),
        ];
    }
};
