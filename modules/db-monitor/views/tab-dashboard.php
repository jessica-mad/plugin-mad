<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Variables: $summary, $suspicious_tables, $module
 */
?>
<div class="mad-dbm-dashboard">

    <!-- ── Stat cards ── -->
    <div class="mad-dbm-stat-cards">

        <div class="mad-dbm-card">
            <span class="mad-dbm-card-icon dashicons dashicons-database"></span>
            <div class="mad-dbm-card-body">
                <div class="mad-dbm-card-value"><?php echo esc_html( $summary['table_count'] ); ?></div>
                <div class="mad-dbm-card-label"><?php esc_html_e( 'Total de tablas', 'mad-suite' ); ?></div>
            </div>
        </div>

        <div class="mad-dbm-card">
            <span class="mad-dbm-card-icon dashicons dashicons-chart-area"></span>
            <div class="mad-dbm-card-body">
                <div class="mad-dbm-card-value"><?php echo esc_html( $summary['total_mb'] ); ?> MB</div>
                <div class="mad-dbm-card-label"><?php esc_html_e( 'Tamaño total de la BD', 'mad-suite' ); ?></div>
            </div>
        </div>

        <div class="mad-dbm-card">
            <span class="mad-dbm-card-icon dashicons dashicons-warning"></span>
            <div class="mad-dbm-card-body">
                <div class="mad-dbm-card-value mad-dbm-<?php echo $summary['suspicious_count'] > 0 ? 'danger' : 'ok'; ?>">
                    <?php echo esc_html( $summary['suspicious_count'] ); ?>
                </div>
                <div class="mad-dbm-card-label"><?php esc_html_e( 'Tablas sospechosas', 'mad-suite' ); ?></div>
            </div>
        </div>

        <div class="mad-dbm-card">
            <span class="mad-dbm-card-icon dashicons dashicons-arrow-up-alt"></span>
            <div class="mad-dbm-card-body">
                <div class="mad-dbm-card-value"><?php echo esc_html( $summary['heaviest_mb'] ); ?> MB</div>
                <div class="mad-dbm-card-label">
                    <?php esc_html_e( 'Tabla más pesada:', 'mad-suite' ); ?>
                    <code title="<?php echo esc_attr( $summary['heaviest_table'] ); ?>">
                        <?php echo esc_html( $summary['heaviest_table'] ); ?>
                    </code>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Quick actions ── -->
    <div class="mad-dbm-section">
        <h2><?php esc_html_e( 'Acciones rápidas', 'mad-suite' ); ?></h2>
        <div class="mad-dbm-quick-actions">

            <div class="mad-dbm-quick-action-card">
                <span class="dashicons dashicons-trash mad-dbm-qa-icon"></span>
                <div>
                    <strong><?php esc_html_e( 'Limpiar transients expirados', 'mad-suite' ); ?></strong>
                    <p class="description">
                        <?php esc_html_e( 'Elimina transients caducados de wp_options. No requiere backup (son datos ya expirados).', 'mad-suite' ); ?>
                    </p>
                    <button class="button" id="mad-dbm-btn-clean-transients">
                        <?php esc_html_e( 'Limpiar ahora', 'mad-suite' ); ?>
                    </button>
                    <div id="mad-dbm-transients-result" style="margin-top:8px;display:none"></div>
                </div>
            </div>

            <div class="mad-dbm-quick-action-card">
                <span class="dashicons dashicons-database-view mad-dbm-qa-icon"></span>
                <div>
                    <strong><?php esc_html_e( 'Ver todas las tablas', 'mad-suite' ); ?></strong>
                    <p class="description">
                        <?php esc_html_e( 'Accede al listado completo con opciones de exportar y limpiar.', 'mad-suite' ); ?>
                    </p>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => $module->menu_slug(), 'tab' => 'tables' ], admin_url( 'admin.php' ) ) ); ?>"
                       class="button">
                        <?php esc_html_e( 'Ir a Tablas', 'mad-suite' ); ?>
                    </a>
                </div>
            </div>

        </div>
    </div>

    <!-- ── Suspicious tables ── -->
    <?php if ( ! empty( $suspicious_tables ) ) : ?>
    <div class="mad-dbm-section">
        <h2><?php esc_html_e( 'Tablas sospechosas detectadas', 'mad-suite' ); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Tabla', 'mad-suite' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Registros', 'mad-suite' ); ?></th>
                    <th style="width:10%"><?php esc_html_e( 'Tamaño MB', 'mad-suite' ); ?></th>
                    <th><?php esc_html_e( 'Motivo', 'mad-suite' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Acción', 'mad-suite' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $suspicious_tables as $t ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $t['name'] ); ?></code></td>
                    <td><?php echo esc_html( number_format( $t['rows'] ) ); ?></td>
                    <td><?php echo esc_html( $t['total_mb'] ); ?></td>
                    <td>
                        <?php foreach ( $t['suspect_flags'] as $flag ) : ?>
                            <span class="mad-dbm-badge mad-dbm-badge-warning"><?php echo esc_html( $flag ); ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $module->menu_slug(), 'tab' => 'tables' ], admin_url( 'admin.php' ) ) ); ?>"
                           class="button button-small">
                            <?php esc_html_e( 'Ver tabla', 'mad-suite' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else : ?>
    <div class="mad-dbm-section">
        <div class="notice notice-success inline">
            <p>✔ <?php esc_html_e( 'No se detectaron tablas sospechosas con los umbrales actuales.', 'mad-suite' ); ?></p>
        </div>
    </div>
    <?php endif; ?>

</div>
