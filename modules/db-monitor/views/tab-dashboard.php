<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Variables: $summary, $tables (first 5 suspicious)
 */
?>
<div class="mad-dbm-dashboard">
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
                <div class="mad-dbm-card-value" title="<?php echo esc_attr( $summary['heaviest_table'] ); ?>">
                    <?php echo esc_html( $summary['heaviest_mb'] ); ?> MB
                </div>
                <div class="mad-dbm-card-label">
                    <?php esc_html_e( 'Tabla más pesada:', 'mad-suite' ); ?>
                    <code><?php echo esc_html( $summary['heaviest_table'] ); ?></code>
                </div>
            </div>
        </div>

    </div>

    <?php if ( ! empty( $suspicious_tables ) ) : ?>
    <div class="mad-dbm-section">
        <h2><?php esc_html_e( 'Tablas sospechosas detectadas', 'mad-suite' ); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Tabla', 'mad-suite' ); ?></th>
                    <th><?php esc_html_e( 'Registros', 'mad-suite' ); ?></th>
                    <th><?php esc_html_e( 'Tamaño MB', 'mad-suite' ); ?></th>
                    <th><?php esc_html_e( 'Motivo', 'mad-suite' ); ?></th>
                    <th><?php esc_html_e( 'Acción', 'mad-suite' ); ?></th>
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
                        <?php
                        $tables_url = admin_url( 'admin.php?page=' . $module->menu_slug() . '&tab=tables' );
                        ?>
                        <a href="<?php echo esc_url( $tables_url ); ?>" class="button button-small">
                            <?php esc_html_e( 'Ver tabla', 'mad-suite' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="mad-dbm-section">
        <p class="description">
            <?php esc_html_e( 'Ve a la pestaña "Tablas" para ver el listado completo, exportar o limpiar registros.', 'mad-suite' ); ?>
        </p>
    </div>
</div>
