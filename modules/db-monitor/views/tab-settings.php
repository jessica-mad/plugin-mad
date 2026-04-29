<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Variables: $settings (array), $module
 */
?>
<div class="mad-dbm-settings-wrap">

    <h2><?php esc_html_e( 'Configuración del módulo', 'mad-suite' ); ?></h2>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'mad_dbm_save_settings', 'mad_dbm_settings_nonce' ); ?>
        <input type="hidden" name="action" value="mad_dbm_save_settings">

        <table class="form-table">

            <tr>
                <th scope="row">
                    <label for="mad-dbm-retention-days">
                        <?php esc_html_e( 'Retención de backups automáticos', 'mad-suite' ); ?>
                    </label>
                </th>
                <td>
                    <select name="mad_dbm_settings[retention_days]" id="mad-dbm-retention-days">
                        <?php foreach ( [ 7, 15, 30, 60, 90 ] as $d ) : ?>
                            <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $settings['retention_days'], $d ); ?>>
                                <?php printf( esc_html__( '%d días', 'mad-suite' ), $d ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Tiempo que se conservan los backups automáticos generados antes de cada limpieza. Pasado este periodo el cron los eliminará.', 'mad-suite' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="mad-dbm-suspicious-size">
                        <?php esc_html_e( 'Umbral de tamaño sospechoso (MB)', 'mad-suite' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number"
                           name="mad_dbm_settings[suspicious_size_mb]"
                           id="mad-dbm-suspicious-size"
                           value="<?php echo esc_attr( $settings['suspicious_size_mb'] ); ?>"
                           min="1" max="10000" class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Las tablas que superen este tamaño se marcarán como sospechosas.', 'mad-suite' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="mad-dbm-suspicious-rows">
                        <?php esc_html_e( 'Umbral de registros sospechosos', 'mad-suite' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number"
                           name="mad_dbm_settings[suspicious_row_count]"
                           id="mad-dbm-suspicious-rows"
                           value="<?php echo esc_attr( $settings['suspicious_row_count'] ); ?>"
                           min="1" class="regular-text">
                    <p class="description">
                        <?php esc_html_e( 'Las tablas con más registros que este valor se marcarán como sospechosas.', 'mad-suite' ); ?>
                    </p>
                </td>
            </tr>

        </table>

        <?php submit_button( __( 'Guardar configuración', 'mad-suite' ) ); ?>
    </form>

    <hr>

    <h2><?php esc_html_e( 'Información del entorno', 'mad-suite' ); ?></h2>
    <table class="form-table">
        <tr>
            <th><?php esc_html_e( 'Carpeta de exportaciones', 'mad-suite' ); ?></th>
            <td>
                <?php
                $export_url = add_query_arg( [ 'page' => $module->menu_slug(), 'tab' => 'exports' ], admin_url( 'admin.php' ) );
                echo '<code>' . esc_html( WP_CONTENT_DIR . '/uploads/mad-db-exports/' ) . '</code>';
                echo ' — <a href="' . esc_url( $export_url ) . '">' . esc_html__( 'Ver exportaciones', 'mad-suite' ) . '</a>';
                ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'PHP memory_limit', 'mad-suite' ); ?></th>
            <td><code><?php echo esc_html( ini_get( 'memory_limit' ) ?: '—' ); ?></code></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'max_execution_time', 'mad-suite' ); ?></th>
            <td><code><?php echo esc_html( ini_get( 'max_execution_time' ) ?: '—' ); ?>s</code></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'upload_max_filesize', 'mad-suite' ); ?></th>
            <td><code><?php echo esc_html( ini_get( 'upload_max_filesize' ) ?: '—' ); ?></code></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Próximo cron de limpieza', 'mad-suite' ); ?></th>
            <td>
                <?php
                $next = wp_next_scheduled( 'mad_dbm_daily_cleanup' );
                echo $next
                    ? esc_html( date_i18n( 'd/m/Y H:i', $next ) )
                    : '<em>' . esc_html__( 'No programado', 'mad-suite' ) . '</em>';
                ?>
            </td>
        </tr>
    </table>

</div>
