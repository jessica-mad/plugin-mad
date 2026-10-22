<?php
/**
 * Vista del dashboard. Variables disponibles (ver Module::render_settings_page()):
 * $segment, $from, $to, $kpis, $login_avg_days, $monthly, $cohort_grid,
 * $rfm_breakdown, $top_customers, $at_risk, $top_products, $last_updated,
 * $recalc_status, $recalc_url. $this = la instancia del módulo (para menu_slug()).
 *
 * @package MAD_Suite/Customer_Insights
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$segment_labels = [
    'all'          => __( 'Todos', 'mad-suite' ),
    'public'       => __( 'Público', 'mad-suite' ),
    'professional' => __( 'Profesionales', 'mad-suite' ),
];

$base_url  = remove_query_arg( [ 'mad_ci_segment', 'mad_ci_notice' ] );
$is_running = in_array( $recalc_status['state'], [ 'collecting', 'finalizing' ], true );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Analítica de Clientes', 'mad-suite' ); ?></h1>

    <?php if ( isset( $_GET['mad_ci_notice'] ) ) : ?>
        <?php if ( 'started' === $_GET['mad_ci_notice'] ) : ?>
            <div class="notice notice-info is-dismissible">
                <p><?php esc_html_e( 'Recálculo iniciado en segundo plano. Puedes seguir usando esta página mientras tanto — los números se actualizarán solos cuando termine.', 'mad-suite' ); ?></p>
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
        <div class="notice notice-info">
            <p>
                <?php if ( 'collecting' === $recalc_status['state'] ) : ?>
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
            esc_html_e( 'Todavía no se ha calculado nada — pulsa "Recalcular ahora" o espera al proceso nocturno.', 'mad-suite' );
        }
        ?>
        &nbsp;
        <?php if ( $is_running ) : ?>
            <button type="button" class="button button-secondary" disabled>
                <?php esc_html_e( 'Recalculando…', 'mad-suite' ); ?>
            </button>
        <?php else : ?>
            <a href="<?php echo esc_url( $recalc_url ); ?>" class="button button-secondary"
               onclick="return confirm('<?php echo esc_js( __( 'Esto arranca el recálculo en lotes pequeños en segundo plano — no bloquea el sitio, pero puede tardar varios minutos en tiendas con muchos pedidos. ¿Continuar?', 'mad-suite' ) ); ?>');">
                <?php esc_html_e( 'Recalcular ahora', 'mad-suite' ); ?>
            </a>
        <?php endif; ?>
    </p>

    <div class="mad-ci-toolbar">
        <span class="button-group">
            <?php foreach ( $segment_labels as $key => $label ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'mad_ci_segment', $key, $base_url ) ); ?>"
                   class="button<?php echo $segment === $key ? ' button-primary' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </span>

        <form method="get" style="display:inline-flex;align-items:center;gap:6px;">
            <input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug() ); ?>">
            <input type="hidden" name="mad_ci_segment" value="<?php echo esc_attr( $segment ); ?>">
            <label><?php esc_html_e( 'Desde', 'mad-suite' ); ?>
                <input type="date" name="mad_ci_from" value="<?php echo esc_attr( $from ); ?>">
            </label>
            <label><?php esc_html_e( 'Hasta', 'mad-suite' ); ?>
                <input type="date" name="mad_ci_to" value="<?php echo esc_attr( $to ); ?>">
            </label>
            <button type="submit" class="button"><?php esc_html_e( 'Aplicar', 'mad-suite' ); ?></button>
        </form>
    </div>

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
        <?php if ( empty( $monthly ) ) : ?>
            <p><?php esc_html_e( 'Sin datos en este rango de fechas.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <canvas id="mad-ci-monthly-chart" height="90"></canvas>
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
        <p class="description"><?php esc_html_e( 'Recencia, Frecuencia y Valor monetario — en qué grupo cae cada cliente.', 'mad-suite' ); ?></p>
        <?php if ( empty( $rfm_breakdown ) ) : ?>
            <p><?php esc_html_e( 'Sin datos todavía.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <div class="mad-ci-rfm-list">
                <?php foreach ( $rfm_breakdown as $label => $count ) : ?>
                    <span class="mad-ci-rfm-pill"><?php echo esc_html( $label ); ?><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="mad-ci-section">
        <h2><?php esc_html_e( 'Productos con mayor tasa de recompra', 'mad-suite' ); ?></h2>
        <p class="description"><?php esc_html_e( 'De los clientes que compraron el producto, qué % volvió a comprarlo (mínimo 5 compradores).', 'mad-suite' ); ?></p>
        <?php if ( empty( $top_products ) ) : ?>
            <p><?php esc_html_e( 'Sin datos todavía.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Producto', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Compradores', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Recompraron', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Tasa de recompra', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $top_products as $p ) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url( get_edit_post_link( $p['product_id'] ) ); ?>"><?php echo esc_html( $p['product_name'] ); ?></a></td>
                            <td><?php echo esc_html( $p['buyers_count'] ); ?></td>
                            <td><?php echo esc_html( $p['repeat_buyers_count'] ); ?></td>
                            <td><?php echo esc_html( $p['repurchase_rate'] ); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="mad-ci-section">
        <h2><?php esc_html_e( 'Top clientes por valor', 'mad-suite' ); ?></h2>
        <?php if ( empty( $top_customers ) ) : ?>
            <p><?php esc_html_e( 'Sin datos todavía.', 'mad-suite' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Cliente', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Pedidos', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Valor total', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Última compra', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $top_customers as $c ) : ?>
                        <tr>
                            <td><?php echo esc_html( $c['customer_email'] ); ?></td>
                            <td><?php echo esc_html( $c['order_count'] ); ?></td>
                            <td><?php echo wp_kses_post( wc_price( $c['lifetime_value'] ) ); ?></td>
                            <td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $c['last_order_date'] ) ) ); ?></td>
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
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Cliente', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Pedidos', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Valor total', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Última compra', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Días sin comprar', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Su intervalo habitual', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $at_risk as $c ) : ?>
                        <tr>
                            <td><?php echo esc_html( $c['customer_email'] ); ?></td>
                            <td><?php echo esc_html( $c['order_count'] ); ?></td>
                            <td><?php echo wp_kses_post( wc_price( $c['lifetime_value'] ) ); ?></td>
                            <td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $c['last_order_date'] ) ) ); ?></td>
                            <td><?php echo esc_html( (int) $c['days_since_last_order'] ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( (float) $c['avg_days_between_orders'], 0 ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
