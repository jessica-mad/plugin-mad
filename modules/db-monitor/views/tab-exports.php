<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Variables: $exports (array), $module
 */
$token_ttl = 300; // seconds
?>
<div class="mad-dbm-exports">

    <h2><?php esc_html_e( 'Exportaciones recientes', 'mad-suite' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Los archivos se almacenan en una carpeta privada. Los enlaces de descarga caducan en 5 minutos y son de un solo uso.', 'mad-suite' ); ?>
    </p>

    <?php if ( empty( $exports ) ) : ?>
        <p><?php esc_html_e( 'No hay exportaciones registradas aún.', 'mad-suite' ); ?></p>
    <?php else : ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:25%"><?php esc_html_e( 'Tabla', 'mad-suite' ); ?></th>
                <th style="width:12%"><?php esc_html_e( 'Tipo', 'mad-suite' ); ?></th>
                <th style="width:10%"><?php esc_html_e( 'Tamaño', 'mad-suite' ); ?></th>
                <th style="width:13%"><?php esc_html_e( 'Fecha', 'mad-suite' ); ?></th>
                <th style="width:10%"><?php esc_html_e( 'Expira', 'mad-suite' ); ?></th>
                <th style="width:8%"><?php esc_html_e( 'Estado', 'mad-suite' ); ?></th>
                <th><?php esc_html_e( 'Acciones', 'mad-suite' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $exports as $ex ) :
            $size_mb   = round( $ex['file_size'] / 1048576, 2 );
            $exp_date  = $ex['expires_at'] ? date_i18n( 'd/m/Y', strtotime( $ex['expires_at'] ) ) : '—';
        ?>
            <tr>
                <td><code><?php echo esc_html( $ex['table_name'] ); ?></code></td>
                <td>
                    <span class="mad-dbm-badge <?php echo $ex['action_type'] === 'manual' ? 'mad-dbm-badge-manual' : 'mad-dbm-badge-auto'; ?>">
                        <?php echo esc_html( $ex['action_type'] === 'manual' ? 'Manual' : 'Automático' ); ?>
                    </span>
                </td>
                <td><?php echo esc_html( $size_mb ); ?> MB</td>
                <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $ex['created_at'] ) ) ); ?></td>
                <td><?php echo esc_html( $exp_date ); ?></td>
                <td>
                    <?php
                    $badge_map = [
                        'available' => 'mad-dbm-badge-cleanable',
                        'expired'   => 'mad-dbm-badge-warning',
                        'deleted'   => 'mad-dbm-badge-protected',
                    ];
                    $badge = $badge_map[ $ex['status'] ] ?? 'mad-dbm-badge-neutral';
                    ?>
                    <span class="mad-dbm-badge <?php echo esc_attr( $badge ); ?>">
                        <?php echo esc_html( ucfirst( $ex['status'] ) ); ?>
                    </span>
                </td>
                <td class="mad-dbm-actions">
                    <?php if ( $ex['status'] === 'available' ) : ?>

                        <!-- Generate download token (shows countdown) -->
                        <button type="button"
                                class="button button-small mad-dbm-btn-get-token"
                                data-export-id="<?php echo esc_attr( $ex['id'] ); ?>"
                                data-ttl="<?php echo esc_attr( $token_ttl ); ?>">
                            <?php esc_html_e( 'Obtener enlace', 'mad-suite' ); ?>
                        </button>

                        <!-- Send by email -->
                        <button type="button"
                                class="button button-small mad-dbm-btn-send-email"
                                data-export-id="<?php echo esc_attr( $ex['id'] ); ?>">
                            <?php esc_html_e( 'Enviar por email', 'mad-suite' ); ?>
                        </button>

                    <?php endif; ?>

                    <!-- Delete export record + file -->
                    <button type="button"
                            class="button button-small button-link-delete mad-dbm-btn-delete-export"
                            data-export-id="<?php echo esc_attr( $ex['id'] ); ?>">
                        <?php esc_html_e( 'Eliminar', 'mad-suite' ); ?>
                    </button>
                </td>
            </tr>

            <!-- Token row (hidden, shown dynamically per export) -->
            <tr id="mad-dbm-token-row-<?php echo esc_attr( $ex['id'] ); ?>" style="display:none" class="mad-dbm-token-row">
                <td colspan="7">
                    <div class="mad-dbm-token-box">
                        <p>
                            <strong><?php esc_html_e( 'Enlace temporal de descarga', 'mad-suite' ); ?></strong> —
                            <span class="mad-dbm-warning"><?php esc_html_e( 'Caduca en', 'mad-suite' ); ?> <span class="mad-dbm-countdown" data-seconds="<?php echo esc_attr( $token_ttl ); ?>"><?php echo esc_html( $token_ttl ); ?></span>s</span>
                        </p>
                        <input type="text" class="mad-dbm-token-url" readonly style="width:60%">
                        <button type="button" class="button mad-dbm-btn-copy-link"><?php esc_html_e( 'Copiar enlace', 'mad-suite' ); ?></button>
                        <a href="#" class="button button-primary mad-dbm-btn-download-now" target="_blank"><?php esc_html_e( 'Descargar ahora', 'mad-suite' ); ?></a>
                        <p class="description mad-dbm-expired-msg" style="display:none; color:#c00">
                            <?php esc_html_e( 'El enlace ha expirado. Genera una nueva exportación o solicita otro enlace.', 'mad-suite' ); ?>
                        </p>
                    </div>
                </td>
            </tr>

        <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>

</div>
