<?php
/**
 * Vista del dashboard. Variables disponibles (ver Module::render_settings_page()):
 * $from, $to, $granularity, $compare, $segment, $customers_limit, $kpis,
 * $login_avg_days, $period_series, $cohort_grid, $rfm_breakdown,
 * $rfm_definitions, $store_avg_repurchase_days, $customers_list,
 * $customers_total, $at_risk, $top_products,
 * $last_updated, $recalc_status, $recalc_stuck, $recalc_action_url,
 * $recalc_nonce_field, $recalc_hidden_args.
 * $this = la instancia del módulo (para menu_slug()).
 *
 * @package MAD_Suite/Customer_Insights
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$window_labels = [
    0  => __( 'Todo el histórico', 'mad-suite' ),
    3  => __( 'Últimos 3 meses', 'mad-suite' ),
    6  => __( 'Últimos 6 meses', 'mad-suite' ),
    12 => __( 'Últimos 12 meses', 'mad-suite' ),
];

$granularity_labels = [
    'month'    => __( 'Mensual', 'mad-suite' ),
    'quarter'  => __( 'Trimestral', 'mad-suite' ),
    'semester' => __( 'Semestral', 'mad-suite' ),
    'year'     => __( 'Anual', 'mad-suite' ),
];

$is_running = in_array( $recalc_status['state'], [ 'collecting', 'finalizing' ], true );

$customers_limit_options = [ 25, 50, 100, 300, 1000 ];

/** Construye una URL a esta misma página conservando los filtros actuales, con overrides puntuales. */
$mad_ci_build_url = function ( array $overrides = [] ) use ( $from, $to, $granularity, $compare, $segment, $customers_limit ) {
    $args = array_merge( [
        'page'                   => $this->menu_slug(),
        'mad_ci_from'            => $from,
        'mad_ci_to'              => $to,
        'mad_ci_granularity'     => $granularity,
        'mad_ci_compare'         => $compare ? 1 : 0,
        'mad_ci_segment'         => $segment,
        'mad_ci_customers_limit' => $customers_limit,
    ], $overrides );
    return add_query_arg( $args, admin_url( 'admin.php' ) );
};
?>
<div class="wrap mad-ci-wrap">
    <h1><?php esc_html_e( 'Analítica de Clientes', 'mad-suite' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Solo se cuentan pedidos completados — en proceso, en espera o cancelados no entran en estos números.', 'mad-suite' ); ?></p>

    <?php if ( isset( $_GET['mad_ci_notice'] ) ) : ?>
        <?php if ( 'started' === $_GET['mad_ci_notice'] ) : ?>
            <div class="notice notice-info is-dismissible">
                <p><?php esc_html_e( 'Recálculo iniciado en segundo plano. Puedes seguir usando esta página mientras tanto — los números se actualizarán solos cuando termine.', 'mad-suite' ); ?></p>
            </div>
        <?php elseif ( 'sync_done' === $_GET['mad_ci_notice'] ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Recálculo de prueba terminado — ya puedes ver los números abajo.', 'mad-suite' ); ?></p>
            </div>
        <?php elseif ( 'already_running' === $_GET['mad_ci_notice'] ) : ?>
            <div class="notice notice-warning mad-ci-notice-rate-limited is-dismissible">
                <p><?php esc_html_e( 'Ya hay un recálculo en curso — espera a que termine.', 'mad-suite' ); ?></p>
            </div>
        <?php elseif ( 'rate_limited' === $_GET['mad_ci_notice'] ) : ?>
            <div class="notice notice-warning mad-ci-notice-rate-limited is-dismissible">
                <p><?php esc_html_e( 'Ya se recalculó hace poco — espera unos minutos antes de volver a intentarlo.', 'mad-suite' ); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ( $is_running ) : ?>
        <div class="notice <?php echo $recalc_stuck ? 'notice-error' : 'notice-info'; ?>">
            <p>
                <?php if ( $recalc_stuck ) : ?>
                    <?php esc_html_e( 'Esto lleva varios minutos sin avanzar — probablemente Action Scheduler no está procesando acciones en segundo plano en este sitio. Revisa WooCommerce → Estado → Acciones programadas (busca "mad_ci_process_batch" o "mad_ci_finalize_recalculation") para ver si quedaron en pausa o fallidas.', 'mad-suite' ); ?>
                <?php elseif ( 'collecting' === $recalc_status['state'] ) : ?>
                    <?php
                    printf(
                        /* translators: %s: número de pedidos ya procesados */
                        esc_html__( 'Recalculando en segundo plano — llevamos %s pedidos procesados. Esta página sigue siendo usable mientras tanto; recarga para ver el progreso.', 'mad-suite' ),
                        esc_html( number_format_i18n( (int) $recalc_status['processed_orders'] ) )
                    );
                    ?>
                <?php else : ?>
                    <?php esc_html_e( 'Procesando los últimos datos recolectados — ya casi termina.', 'mad-suite' ); ?>
                <?php endif; ?>
            </p>
            <?php if ( ! $recalc_stuck ) : ?>
                <p>
                    <?php esc_html_e( 'Importante: los números y gráficas de abajo son todavía los del último cálculo que SÍ terminó (o están vacíos, si es la primera vez) — no se actualizan en vivo mientras este recálculo sigue en curso, así que cambiar el rango de fechas, la segmentación o cualquier filtro no va a mostrar nada nuevo hasta que termine. Esto es normal, no es que esté trayendo mal la información.', 'mad-suite' ); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p>
        <?php
        if ( $last_updated ) {
            printf(
                /* translators: %s: fecha y hora del último recálculo */
                esc_html__( 'Datos calculados por última vez: %s', 'mad-suite' ),
                esc_html( wp_date( 'd/m/Y H:i', $last_updated ) )
            );
        } else {
            esc_html_e( 'Todavía no se ha calculado nada.', 'mad-suite' );
        }
        ?>
    </p>

    <form method="post" action="<?php echo esc_url( $recalc_action_url ); ?>" class="mad-ci-recalc-form">
        <?php echo $recalc_nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <?php foreach ( $recalc_hidden_args as $key => $value ) : ?>
            <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
        <?php endforeach; ?>

        <label>
            <?php esc_html_e( 'Rango a recalcular:', 'mad-suite' ); ?>
            <select name="mad_ci_window">
                <?php foreach ( $window_labels as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php if ( $is_running ) : ?>
            <button type="button" class="button button-secondary" disabled><?php esc_html_e( 'Recalculando…', 'mad-suite' ); ?></button>
        <?php else : ?>
            <button type="submit" name="mad_ci_mode" value="background" class="button button-secondary"
                    onclick="return confirm('<?php echo esc_js( __( 'Arranca el recálculo en segundo plano (no bloquea el sitio). Para "Todo el histórico" en una tienda con muchos pedidos puede tardar varios minutos. ¿Continuar?', 'mad-suite' ) ); ?>');">
                <?php esc_html_e( 'Recalcular en segundo plano', 'mad-suite' ); ?>
            </button>
            <button type="submit" name="mad_ci_mode" value="sync" class="button">
                <?php esc_html_e( 'Probar ahora (rango corto, directo)', 'mad-suite' ); ?>
            </button>
        <?php endif; ?>
        <p class="description">
            <?php esc_html_e( '"Probar ahora" corre de inmediato, en la misma petición, sin pasar por el procesamiento en segundo plano — solo funciona con un rango acotado (no con el histórico completo), pensado para probar rápido sin esperar.', 'mad-suite' ); ?>
            <br><?php esc_html_e( 'Aviso: con un rango acotado, un cliente cuya primera compra real fue antes de ese rango puede aparecer incorrectamente como "nuevo".', 'mad-suite' ); ?>
        </p>
    </form>

    <div class="mad-ci-toolbar">
        <form method="get" class="mad-ci-date-form">
            <input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug() ); ?>">
            <input type="hidden" name="mad_ci_segment" value="<?php echo esc_attr( $segment ); ?>">
            <input type="hidden" name="mad_ci_customers_limit" value="<?php echo esc_attr( $customers_limit ); ?>">
            <label><?php esc_html_e( 'Desde', 'mad-suite' ); ?>
                <input type="date" name="mad_ci_from" value="<?php echo esc_attr( $from ); ?>">
            </label>
            <label><?php esc_html_e( 'Hasta', 'mad-suite' ); ?>
                <input type="date" name="mad_ci_to" value="<?php echo esc_attr( $to ); ?>">
            </label>
            <label><?php esc_html_e( 'Agrupar por', 'mad-suite' ); ?>
                <select name="mad_ci_granularity">
                    <?php foreach ( $granularity_labels as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $granularity, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label title="<?php esc_attr_e( 'Solo funciona agrupando por mes — superpone una línea con el mismo mes exacto de hace un año (si no hay pedidos ese mes, no se dibuja ningún punto, no se inventa el dato).', 'mad-suite' ); ?>">
                <input type="checkbox" name="mad_ci_compare" value="1" <?php checked( $compare ); ?>>
                <?php esc_html_e( 'Comparar con el año anterior', 'mad-suite' ); ?>
            </label>
            <button type="submit" class="button"><?php esc_html_e( 'Aplicar', 'mad-suite' ); ?></button>
        </form>
    </div>
    <p class="description">
        <?php esc_html_e( 'Este rango de fechas solo afecta a la gráfica de arriba y al KPI "Login → compra". El resto (RFM, cohortes, clientes, productos con recompra) son datos de toda la vida del cliente, no de un periodo — por ejemplo, el segmento RFM o el valor total de un cliente no cambian según qué mes estés mirando, siempre reflejan su historial completo.', 'mad-suite' ); ?>
    </p>

    <div class="mad-ci-kpis">
        <div class="mad-ci-kpi">
            <span class="value"><?php echo esc_html( number_format_i18n( $kpis['total_customers'] ) ); ?></span>
            <span class="label"><?php esc_html_e( 'Clientes totales', 'mad-suite' ); ?></span>
        </div>
        <div class="mad-ci-kpi">
            <span class="value"><?php echo esc_html( $kpis['repeat_rate'] ); ?>%</span>
            <span class="label"><?php esc_html_e( 'Tasa de recompra', 'mad-suite' ); ?></span>
        </div>
        <div class="mad-ci-kpi">
            <span class="value"><?php echo wp_kses_post( wc_price( $kpis['avg_aov'] ) ); ?></span>
            <span class="label"><?php esc_html_e( 'Ticket promedio (AOV)', 'mad-suite' ); ?></span>
        </div>
        <div class="mad-ci-kpi">
            <span class="value"><?php echo wp_kses_post( wc_price( $kpis['avg_ltv'] ) ); ?></span>
            <span class="label"><?php esc_html_e( 'Valor de vida promedio (CLV)', 'mad-suite' ); ?></span>
        </div>
        <div class="mad-ci-kpi">
            <span class="value">
                <?php
                echo null !== $kpis['avg_frequency_days']
                    ? esc_html( sprintf( __( '%s días', 'mad-suite' ), number_format_i18n( $kpis['avg_frequency_days'], 0 ) ) )
                    : '—';
                ?>
            </span>
            <span class="label"><?php esc_html_e( 'Frecuencia de compra', 'mad-suite' ); ?></span>
        </div>
        <div class="mad-ci-kpi">
            <span class="value">
                <?php
                echo null !== $kpis['avg_days_to_second_order']
                    ? esc_html( sprintf( __( '%s días', 'mad-suite' ), number_format_i18n( $kpis['avg_days_to_second_order'], 0 ) ) )
                    : '—';
                ?>
            </span>
            <span class="label"><?php esc_html_e( 'Nuevo → recurrente', 'mad-suite' ); ?></span>
        </div>
        <div class="mad-ci-kpi">
            <span class="value">
                <?php
                echo null !== $login_avg_days
                    ? esc_html( sprintf( __( '%s días', 'mad-suite' ), number_format_i18n( $login_avg_days, 1 ) ) )
                    : '—';
                ?>
            </span>
            <span class="label">
                <?php esc_html_e( 'Login → compra', 'mad-suite' ); ?>
                <br><small><?php esc_html_e( 'solo usuarios registrados, desde que se activó esta función', 'mad-suite' ); ?></small>
            </span>
        </div>
    </div>

    <div class="mad-ci-section">
        <h2><?php esc_html_e( 'Clientes nuevos vs. recurrentes', 'mad-suite' ); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %s: etiqueta de la granularidad elegida (Mensual, Trimestral…) */
                esc_html__( 'Por cada periodo (%s), cuántos pedidos vinieron de un cliente que compraba por primera vez frente a uno que ya había comprado antes. Para comparar muchos años de un vistazo, agrupa por "Anual" — cada barra es un año completo.', 'mad-suite' ),
                esc_html( $granularity_labels[ $granularity ] )
            );
            ?>
        </p>
        <?php if ( empty( $period_series ) ) : ?>
            <p><?php esc_html_e( 'Sin datos en este rango de fechas.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <div class="mad-ci-chart-wrap">
                <canvas id="mad-ci-monthly-chart" height="90"></canvas>
            </div>
        <?php endif; ?>
    </div>

    <div class="mad-ci-section">
        <h2><?php esc_html_e( 'Retención por cohortes', 'mad-suite' ); ?></h2>
        <p class="description">
            <?php esc_html_e( '% de clientes de cada cohorte (agrupados por el mes de su primera compra) que volvió a comprar en los meses siguientes.', 'mad-suite' ); ?>
        </p>
        <?php if ( empty( $cohort_grid['cohorts'] ) ) : ?>
            <p><?php esc_html_e( 'Todavía no hay suficientes datos para formar cohortes.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <div style="overflow-x:auto;">
                <table class="mad-ci-cohort-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Cohorte', 'mad-suite' ); ?></th>
                            <th><?php esc_html_e( 'Clientes', 'mad-suite' ); ?></th>
                            <?php for ( $p = 0; $p <= $cohort_grid['max_period']; $p++ ) : ?>
                                <th><?php echo esc_html( sprintf( __( 'Mes %d', 'mad-suite' ), $p ) ); ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $cohort_grid['cohorts'] as $month => $cohort ) : ?>
                            <tr>
                                <td><?php echo esc_html( wp_date( 'M Y', strtotime( $month ) ) ); ?></td>
                                <td><?php echo esc_html( $cohort['size'] ); ?></td>
                                <?php for ( $p = 0; $p <= $cohort_grid['max_period']; $p++ ) : ?>
                                    <?php if ( isset( $cohort['periods'][ $p ] ) ) :
                                        $pct   = $cohort['periods'][ $p ]['pct'];
                                        $alpha = max( 0.08, min( 1, $pct / 100 ) );
                                        ?>
                                        <td class="cell" style="background: rgba(34,113,177,<?php echo esc_attr( $alpha ); ?>);">
                                            <?php echo esc_html( $pct ); ?>%
                                        </td>
                                    <?php else : ?>
                                        <td>—</td>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="mad-ci-section">
        <h2><?php esc_html_e( 'Segmentación RFM', 'mad-suite' ); ?></h2>
        <p class="description">
            <?php
            if ( null !== $store_avg_repurchase_days ) {
                printf(
                    /* translators: %s: días promedio entre pedidos de un cliente recurrente */
                    esc_html__( 'En esta tienda, un cliente que repite tarda en promedio %s días en volver a comprar. "A tiempo", "va tarde" o "perdido" se decide comparando a cada cliente contra SU PROPIO ritmo habitual (o este promedio, si solo lleva un pedido) — no contra el resto de clientes, así alguien que compró hace mucho no se cuela como "reciente" solo porque el resto de la base está aún más fría.', 'mad-suite' ),
                    esc_html( number_format_i18n( $store_avg_repurchase_days, 1 ) )
                );
            } else {
                esc_html_e( 'Todavía no hay suficientes clientes recurrentes para calcular un ritmo de recompra de referencia — en cuanto los haya, se usará para decidir quién va "a tiempo", "tarde" o "perdido".', 'mad-suite' );
            }
            ?>
            <br>
            <?php esc_html_e( 'Pasa el ratón sobre el ⓘ para ver qué significa cada segmento, o pulsa "Ver clientes" para bajar al listado ya filtrado (por ejemplo, para armar una campaña de recuperación con los "Perdidos" o "En riesgo").', 'mad-suite' ); ?>
        </p>
        <?php if ( empty( $rfm_breakdown ) ) : ?>
            <p><?php esc_html_e( 'Sin datos todavía.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <div class="mad-ci-rfm-grid">
                <?php foreach ( $rfm_definitions as $label => $description ) : ?>
                    <?php $count = $rfm_breakdown[ $label ] ?? 0; ?>
                    <div class="mad-ci-rfm-card mad-ci-rfm-card--<?php echo esc_attr( sanitize_title( $label ) ); ?>">
                        <div class="mad-ci-rfm-top">
                            <span class="mad-ci-rfm-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
                            <span class="mad-ci-rfm-label">
                                <?php echo esc_html( $label ); ?>
                                <span class="mad-ci-info" tabindex="0">
                                    <span class="mad-ci-info-icon" aria-hidden="true">i</span>
                                    <span class="mad-ci-info-popover"><?php echo esc_html( $description ); ?></span>
                                </span>
                            </span>
                        </div>
                        <?php if ( $count > 0 ) : ?>
                            <a class="mad-ci-rfm-link" href="<?php echo esc_url( $mad_ci_build_url( [ 'mad_ci_segment' => $label ] ) . '#mad-ci-clients' ); ?>">
                                <?php esc_html_e( 'Ver clientes →', 'mad-suite' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="mad-ci-section">
        <h2><?php esc_html_e( 'Productos con mayor tasa de recompra', 'mad-suite' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Mínimo 5 compradores distintos, para que no se cuelen productos con muy pocas ventas. Pulsa una columna para ordenar.', 'mad-suite' ); ?></p>
        <?php if ( empty( $top_products ) ) : ?>
            <p><?php esc_html_e( 'Sin datos todavía.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <table class="widefat striped mad-ci-sortable">
                <thead>
                    <tr>
                        <th data-sort="text"><?php esc_html_e( 'Producto', 'mad-suite' ); ?></th>
                        <th data-sort="num" title="<?php esc_attr_e( 'Clientes distintos que compraron este producto alguna vez', 'mad-suite' ); ?>"><?php esc_html_e( 'Compradores únicos', 'mad-suite' ); ?></th>
                        <th data-sort="num" title="<?php esc_attr_e( 'De esos compradores, cuántos lo pidieron en 2 pedidos distintos o más', 'mad-suite' ); ?>"><?php esc_html_e( 'Lo recompraron', 'mad-suite' ); ?></th>
                        <th data-sort="num" title="<?php esc_attr_e( 'Recompraron ÷ compradores únicos — qué % de quienes lo probaron, repitió', 'mad-suite' ); ?>"><?php esc_html_e( 'Tasa de recompra', 'mad-suite' ); ?></th>
                        <th data-sort="num" title="<?php esc_attr_e( 'Cuántas veces se pidió este producto en total, sumando todos los pedidos de todos los clientes', 'mad-suite' ); ?>"><?php esc_html_e( 'Veces pedido en total', 'mad-suite' ); ?></th>
                        <th data-sort="num" title="<?php esc_attr_e( 'Veces pedido en total ÷ compradores únicos — en promedio, cuántas veces lo pide quien lo pide', 'mad-suite' ); ?>"><?php esc_html_e( 'Promedio por comprador', 'mad-suite' ); ?></th>
                        <th data-sort="num" title="<?php esc_attr_e( 'De todas las veces que se pidió, qué % NO fue la primera vez de ese cliente', 'mad-suite' ); ?>"><?php esc_html_e( '% de pedidos que son recompra', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $top_products as $p ) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url( get_edit_post_link( $p['product_id'] ) ); ?>"><?php echo esc_html( $p['product_name'] ); ?></a></td>
                            <td data-value="<?php echo esc_attr( $p['buyers_count'] ); ?>"><?php echo esc_html( $p['buyers_count'] ); ?></td>
                            <td data-value="<?php echo esc_attr( $p['repeat_buyers_count'] ); ?>"><?php echo esc_html( $p['repeat_buyers_count'] ); ?></td>
                            <td data-value="<?php echo esc_attr( $p['repurchase_rate'] ); ?>"><?php echo esc_html( $p['repurchase_rate'] ); ?>%</td>
                            <td data-value="<?php echo esc_attr( $p['total_purchases'] ); ?>"><?php echo esc_html( $p['total_purchases'] ); ?></td>
                            <td data-value="<?php echo esc_attr( $p['avg_purchases_per_buyer'] ); ?>"><?php echo esc_html( $p['avg_purchases_per_buyer'] ); ?></td>
                            <td data-value="<?php echo esc_attr( $p['share_repeat_purchases'] ); ?>"><?php echo esc_html( $p['share_repeat_purchases'] ); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">
                <?php esc_html_e( 'Ejemplo de lectura: si "Queso de cabra" tiene 40 compradores únicos, 5 lo recompraron (12,5% de tasa de recompra), se pidió 48 veces en total y el promedio por comprador es 1,2 — la mayoría lo compró una sola vez, pero esos 5 que repiten lo piden bastante seguido.', 'mad-suite' ); ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="mad-ci-section" id="mad-ci-clients">
        <h2><?php esc_html_e( 'Clientes', 'mad-suite' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Cada fila es un cliente único (agrupado por email de facturación, incluye compras como invitado). "Ver pedidos" abre su historial real en WooCommerce — ahí se ve el detalle de cada pedido con lo que compró.', 'mad-suite' ); ?>
        </p>
        <form method="get" class="mad-ci-segment-form">
            <input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug() ); ?>">
            <input type="hidden" name="mad_ci_from" value="<?php echo esc_attr( $from ); ?>">
            <input type="hidden" name="mad_ci_to" value="<?php echo esc_attr( $to ); ?>">
            <input type="hidden" name="mad_ci_granularity" value="<?php echo esc_attr( $granularity ); ?>">
            <?php if ( $compare ) : ?><input type="hidden" name="mad_ci_compare" value="1"><?php endif; ?>
            <label><?php esc_html_e( 'Segmento RFM', 'mad-suite' ); ?>
                <select name="mad_ci_segment">
                    <option value=""><?php esc_html_e( 'Todos', 'mad-suite' ); ?></option>
                    <?php foreach ( array_keys( $rfm_definitions ) as $label ) : ?>
                        <option value="<?php echo esc_attr( $label ); ?>" <?php selected( $segment, $label ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e( 'Mostrar', 'mad-suite' ); ?>
                <select name="mad_ci_customers_limit">
                    <?php foreach ( $customers_limit_options as $n ) : ?>
                        <option value="<?php echo esc_attr( $n ); ?>" <?php selected( $customers_limit, $n ); ?>>
                            <?php echo esc_html( sprintf( /* translators: %s: cantidad de clientes */ __( '%s clientes', 'mad-suite' ), number_format_i18n( $n ) ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'mad-suite' ); ?></button>
        </form>
        <?php if ( empty( $customers_list ) ) : ?>
            <p><?php esc_html_e( 'Sin clientes en este segmento.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: clientes mostrados, 2: total de clientes en el segmento/filtro actual */
                    esc_html__( 'Mostrando %1$s de %2$s clientes (ordenados por valor total) — acota con el filtro de arriba para ver los demás.', 'mad-suite' ),
                    esc_html( number_format_i18n( count( $customers_list ) ) ),
                    esc_html( number_format_i18n( $customers_total ) )
                );
                ?>
            </p>
            <table class="widefat striped mad-ci-sortable">
                <thead>
                    <tr>
                        <th data-sort="text"><?php esc_html_e( 'Cliente', 'mad-suite' ); ?></th>
                        <th data-sort="text"><?php esc_html_e( 'Segmento RFM', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Pedidos', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Primera compra', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Última compra', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Valor total', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Ticket promedio', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Pedidos', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $customers_list as $c ) : ?>
                        <tr>
                            <td><?php echo esc_html( $c['customer_email'] ); ?></td>
                            <td><?php echo esc_html( $c['rfm_segment'] ); ?></td>
                            <td data-value="<?php echo esc_attr( $c['order_count'] ); ?>"><?php echo esc_html( $c['order_count'] ); ?></td>
                            <td data-value="<?php echo esc_attr( strtotime( $c['first_order_date'] ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $c['first_order_date'] ) ) ); ?></td>
                            <td data-value="<?php echo esc_attr( strtotime( $c['last_order_date'] ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $c['last_order_date'] ) ) ); ?></td>
                            <td data-value="<?php echo esc_attr( $c['lifetime_value'] ); ?>"><?php echo wp_kses_post( wc_price( $c['lifetime_value'] ) ); ?></td>
                            <td data-value="<?php echo esc_attr( $c['avg_order_value'] ); ?>"><?php echo wp_kses_post( wc_price( $c['avg_order_value'] ) ); ?></td>
                            <td><a href="<?php echo esc_url( MAD_CI_Dashboard_Data::orders_list_url( $c['customer_email'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ver pedidos', 'mad-suite' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="mad-ci-section">
        <h2><?php esc_html_e( 'Clientes en riesgo', 'mad-suite' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Clientes con 2+ pedidos que ya llevan sin comprar más del doble de su intervalo habitual.', 'mad-suite' ); ?></p>
        <?php if ( empty( $at_risk ) ) : ?>
            <p><?php esc_html_e( 'Ninguno por ahora.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <table class="widefat striped mad-ci-sortable">
                <thead>
                    <tr>
                        <th data-sort="text"><?php esc_html_e( 'Cliente', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Pedidos', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Valor total', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Última compra', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Días sin comprar', 'mad-suite' ); ?></th>
                        <th data-sort="num"><?php esc_html_e( 'Su intervalo habitual', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $at_risk as $c ) : ?>
                        <tr>
                            <td><?php echo esc_html( $c['customer_email'] ); ?></td>
                            <td data-value="<?php echo esc_attr( $c['order_count'] ); ?>"><?php echo esc_html( $c['order_count'] ); ?></td>
                            <td data-value="<?php echo esc_attr( $c['lifetime_value'] ); ?>"><?php echo wp_kses_post( wc_price( $c['lifetime_value'] ) ); ?></td>
                            <td data-value="<?php echo esc_attr( strtotime( $c['last_order_date'] ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $c['last_order_date'] ) ) ); ?></td>
                            <td data-value="<?php echo esc_attr( $c['days_since_last_order'] ); ?>"><?php echo esc_html( (int) $c['days_since_last_order'] ); ?></td>
                            <td data-value="<?php echo esc_attr( $c['avg_days_between_orders'] ); ?>"><?php echo esc_html( number_format_i18n( (float) $c['avg_days_between_orders'], 0 ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
