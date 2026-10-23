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

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, $this->menu_slug() ) === false ) return;

        $url = plugin_dir_url( __FILE__ );
        wp_enqueue_script( 'mad-ci-chartjs', $url . 'assets/js/chart.umd.min.js', [], '4.4.4', true );
        wp_enqueue_script( 'mad-ci-dashboard', $url . 'assets/js/dashboard.js', [ 'mad-ci-chartjs' ], '1.0.0', true );
        wp_enqueue_style( 'mad-ci-admin', $url . 'assets/css/admin.css', [], '1.0.0' );
    }

    // ── Página del dashboard ────────────────────────────────────────────────

    public function render_settings_page(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }

        [ $from, $to ] = $this->get_current_date_range();

        $data              = new MAD_CI_Dashboard_Data();
        $kpis              = $data->get_customer_kpis();
        $login_avg_days    = $data->get_login_to_purchase_avg( $from, $to );
        $monthly           = $data->get_monthly_series( $from, $to );
        $cohort_grid       = $data->get_cohort_grid();
        $rfm_breakdown     = $data->get_rfm_breakdown();
        $rfm_definitions   = MAD_CI_Dashboard_Data::rfm_segment_definitions();
        $rfm_customers     = [];
        foreach ( array_keys( $rfm_breakdown ) as $seg ) {
            $rfm_customers[ $seg ] = $data->get_customers_in_segment( $seg );
        }
        $top_customers  = $data->get_top_customers();
        $at_risk        = $data->get_at_risk_customers();
        $top_products   = $data->get_top_repurchased_products();
        $last_updated   = $data->get_last_recalculated();
        $recalc_status  = MAD_CI_Batch_Collector::get_status();
        $recalc_stuck   = MAD_CI_Batch_Collector::is_stuck();

        $recalc_action_url  = admin_url( 'admin-post.php' );
        $recalc_nonce_field = wp_nonce_field( 'mad_ci_recalculate', '_wpnonce', true, false );
        $recalc_hidden_args = array_merge( $this->current_dashboard_query_args(), [ 'action' => 'mad_ci_recalculate' ] );

        wp_add_inline_script( 'mad-ci-dashboard', 'window.madCIData = ' . wp_json_encode( [
            'monthly' => array_map( static function ( $row ) {
                return [
                    'month'            => wp_date( 'M Y', strtotime( $row['month'] ) ),
                    'new_customers'    => (int) $row['new_customers'],
                    'repeat_customers' => (int) $row['repeat_customers'],
                ];
            }, $monthly ),
            'i18n' => [
                'new'    => __( 'Nuevos', 'mad-suite' ),
                'repeat' => __( 'Recurrentes', 'mad-suite' ),
            ],
        ] ) . ';', 'before' );

        include __DIR__ . '/views/dashboard.php';
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

    private function current_dashboard_query_args(): array {
        [ $from, $to ] = $this->get_current_date_range();
        return [
            'mad_ci_from' => $from,
            'mad_ci_to'   => $to,
        ];
    }
};
