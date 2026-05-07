<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Variables: $logs (array), $total_logs, $current_page, $per_page, $module
 */
$total_pages = $per_page > 0 ? ceil( $total_logs / $per_page ) : 1;
$base_url    = admin_url( 'admin.php?page=' . $module->menu_slug() . '&tab=audit' );

$action_labels = [
    'export'             => [ 'label' => 'Exportación', 'color' => 'mad-dbm-badge-cleanable' ],
    'download'           => [ 'label' => 'Descarga', 'color' => 'mad-dbm-badge-manual' ],
    'auto_backup'        => [ 'label' => 'Backup automático', 'color' => 'mad-dbm-badge-cleanable' ],
    'backup_failed'      => [ 'label' => 'Backup fallido', 'color' => 'mad-dbm-badge-protected' ],
    'backup_verify_failed' => [ 'label' => 'Verificación fallida', 'color' => 'mad-dbm-badge-protected' ],
    'clean_old'          => [ 'label' => 'Limpiar antiguos', 'color' => 'mad-dbm-badge-warning' ],
    'truncate'           => [ 'label' => 'Vaciar tabla', 'color' => 'mad-dbm-badge-protected' ],
    'clean_old_completed'=> [ 'label' => 'Limpiar completados', 'color' => 'mad-dbm-badge-warning' ],
    'clean_expired_transients' => [ 'label' => 'Transients expirados', 'color' => 'mad-dbm-badge-neutral' ],
    'restore'            => [ 'label' => 'Restauración', 'color' => 'mad-dbm-badge-protected' ],
    'delete_export'      => [ 'label' => 'Eliminar exportación', 'color' => 'mad-dbm-badge-neutral' ],
];
?>
<div class="mad-dbm-audit">

    <h2><?php esc_html_e( 'Registro de auditoría', 'mad-suite' ); ?></h2>
    <p class="description">
        <?php printf(
            esc_html__( 'Total de entradas: %d', 'mad-suite' ),
            $total_logs
        ); ?>
    </p>

    <?php if ( empty( $logs ) ) : ?>
        <p><?php esc_html_e( 'No hay entradas de auditoría aún.', 'mad-suite' ); ?></p>
    <?php else : ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:13%"><?php esc_html_e( 'Fecha / Hora', 'mad-suite' ); ?></th>
                <th style="width:10%"><?php esc_html_e( 'Usuario', 'mad-suite' ); ?></th>
                <th style="width:10%"><?php esc_html_e( 'IP', 'mad-suite' ); ?></th>
                <th style="width:22%"><?php esc_html_e( 'Tabla', 'mad-suite' ); ?></th>
                <th style="width:13%"><?php esc_html_e( 'Acción', 'mad-suite' ); ?></th>
                <th style="width:7%"><?php esc_html_e( 'Resultado', 'mad-suite' ); ?></th>
                <th><?php esc_html_e( 'Detalles', 'mad-suite' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $logs as $log ) :
            $action_info = $action_labels[ $log['action'] ] ?? [ 'label' => $log['action'], 'color' => 'mad-dbm-badge-neutral' ];
        ?>
            <tr>
                <td><?php echo esc_html( date_i18n( 'd/m/Y H:i:s', strtotime( $log['created_at'] ) ) ); ?></td>
                <td><?php echo esc_html( $log['user_login'] ); ?></td>
                <td><?php echo esc_html( $log['ip_address'] ); ?></td>
                <td><code><?php echo esc_html( $log['table_name'] ); ?></code></td>
                <td>
                    <span class="mad-dbm-badge <?php echo esc_attr( $action_info['color'] ); ?>">
                        <?php echo esc_html( $action_info['label'] ); ?>
                    </span>
                </td>
                <td>
                    <?php if ( $log['result'] === 'success' ) : ?>
                        <span style="color:#46b450">✔ <?php esc_html_e( 'OK', 'mad-suite' ); ?></span>
                    <?php else : ?>
                        <span style="color:#dc3232">✘ <?php echo esc_html( $log['result'] ); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $log['details'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ( $total_pages > 1 ) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            echo paginate_links( [
                'base'    => add_query_arg( 'paged', '%#%', $base_url ),
                'format'  => '',
                'current' => $current_page,
                'total'   => $total_pages,
            ] );
            ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>
