<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Variables: $tables (array from analyzer), $module
 */
$clean_url  = admin_url( 'admin-post.php' );
$export_url = admin_url( 'admin-post.php' );
?>
<div class="mad-dbm-tables">

    <p class="description">
        <?php esc_html_e( 'Tablas ordenadas de mayor a menor tamaño. Las acciones destructivas requieren confirmación y generan un backup automático antes de ejecutarse.', 'mad-suite' ); ?>
    </p>

    <table class="wp-list-table widefat fixed striped mad-dbm-table-list" id="mad-dbm-table-list">
        <thead>
            <tr>
                <th style="width:28%"><?php esc_html_e( 'Tabla', 'mad-suite' ); ?></th>
                <th style="width:9%"><?php esc_html_e( 'Registros', 'mad-suite' ); ?></th>
                <th style="width:9%"><?php esc_html_e( 'Total MB', 'mad-suite' ); ?></th>
                <th style="width:9%"><?php esc_html_e( 'Datos MB', 'mad-suite' ); ?></th>
                <th style="width:9%"><?php esc_html_e( 'Índices MB', 'mad-suite' ); ?></th>
                <th style="width:7%"><?php esc_html_e( 'Motor', 'mad-suite' ); ?></th>
                <th style="width:8%"><?php esc_html_e( 'Estado', 'mad-suite' ); ?></th>
                <th><?php esc_html_e( 'Acciones', 'mad-suite' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $tables as $t ) : ?>
            <tr class="<?php echo $t['is_suspicious'] ? 'mad-dbm-row-suspect' : ''; ?>">
                <td>
                    <code class="mad-dbm-table-name"><?php echo esc_html( $t['name'] ); ?></code>
                    <?php if ( $t['is_suspicious'] ) : ?>
                        <br>
                        <?php foreach ( $t['suspect_flags'] as $flag ) : ?>
                            <span class="mad-dbm-badge mad-dbm-badge-warning"><?php echo esc_html( $flag ); ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( number_format( $t['rows'] ) ); ?></td>
                <td><?php echo esc_html( $t['total_mb'] ); ?></td>
                <td><?php echo esc_html( $t['data_mb'] ); ?></td>
                <td><?php echo esc_html( $t['index_mb'] ); ?></td>
                <td><?php echo esc_html( $t['engine'] ); ?></td>
                <td>
                    <?php if ( $t['is_protected'] ) : ?>
                        <span class="mad-dbm-badge mad-dbm-badge-protected"><?php esc_html_e( 'Protegida', 'mad-suite' ); ?></span>
                    <?php elseif ( $t['is_cleanable'] ) : ?>
                        <span class="mad-dbm-badge mad-dbm-badge-cleanable"><?php esc_html_e( 'Limpiable', 'mad-suite' ); ?></span>
                    <?php else : ?>
                        <span class="mad-dbm-badge mad-dbm-badge-neutral"><?php esc_html_e( 'Solo lectura', 'mad-suite' ); ?></span>
                    <?php endif; ?>
                </td>
                <td class="mad-dbm-actions">

                    <!-- Export button — always available -->
                    <form method="post" action="<?php echo esc_url( $export_url ); ?>" style="display:inline">
                        <?php wp_nonce_field( 'mad_dbm_export_' . $t['name'], 'mad_dbm_nonce' ); ?>
                        <input type="hidden" name="action" value="mad_dbm_export_table">
                        <input type="hidden" name="mad_table" value="<?php echo esc_attr( $t['name'] ); ?>">
                        <button type="submit" class="button button-small">
                            <?php esc_html_e( 'Exportar', 'mad-suite' ); ?>
                        </button>
                    </form>

                    <?php if ( $t['is_cleanable'] ) : ?>
                        <!-- Clean old records -->
                        <button type="button"
                                class="button button-small mad-dbm-btn-clean"
                                data-table="<?php echo esc_attr( $t['name'] ); ?>"
                                data-action="clean_old">
                            <?php esc_html_e( 'Limpiar antiguos', 'mad-suite' ); ?>
                        </button>

                        <!-- Truncate -->
                        <button type="button"
                                class="button button-small button-link-delete mad-dbm-btn-truncate"
                                data-table="<?php echo esc_attr( $t['name'] ); ?>">
                            <?php esc_html_e( 'Vaciar tabla', 'mad-suite' ); ?>
                        </button>
                    <?php endif; ?>

                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

</div>

<!-- Clean modal -->
<div id="mad-dbm-clean-modal" class="mad-dbm-modal" style="display:none">
    <div class="mad-dbm-modal-inner">
        <h2><?php esc_html_e( 'Limpiar registros antiguos', 'mad-suite' ); ?></h2>
        <p class="mad-dbm-modal-warning">
            ⚠️ <?php esc_html_e( 'Antes de ejecutar la limpieza se creará automáticamente una copia de seguridad de la tabla. Si el backup falla, la limpieza se cancelará.', 'mad-suite' ); ?>
        </p>
        <p><?php esc_html_e( 'Tabla:', 'mad-suite' ); ?> <strong id="mad-dbm-clean-table-name"></strong></p>
        <label>
            <?php esc_html_e( 'Eliminar registros más antiguos de', 'mad-suite' ); ?>
            <input type="number" id="mad-dbm-clean-days" value="30" min="1" max="3650" style="width:70px"> <?php esc_html_e( 'días', 'mad-suite' ); ?>
        </label>
        <div id="mad-dbm-clean-preview" class="mad-dbm-preview-box" style="display:none"></div>
        <div class="mad-dbm-modal-actions">
            <button class="button" id="mad-dbm-preview-btn"><?php esc_html_e( 'Ver cuántos se eliminarían', 'mad-suite' ); ?></button>
            <button class="button button-primary" id="mad-dbm-clean-confirm-btn" disabled><?php esc_html_e( 'Confirmar limpieza', 'mad-suite' ); ?></button>
            <button class="button" id="mad-dbm-clean-cancel-btn"><?php esc_html_e( 'Cancelar', 'mad-suite' ); ?></button>
        </div>
        <div id="mad-dbm-clean-result" style="display:none"></div>
    </div>
</div>

<!-- Truncate modal -->
<div id="mad-dbm-truncate-modal" class="mad-dbm-modal" style="display:none">
    <div class="mad-dbm-modal-inner">
        <h2><?php esc_html_e( 'Vaciar tabla completa', 'mad-suite' ); ?></h2>
        <p class="mad-dbm-modal-warning">
            ⚠️ <?php esc_html_e( 'Esta acción eliminará TODOS los registros de la tabla. No se puede deshacer fácilmente. Se creará un backup automático antes de proceder.', 'mad-suite' ); ?>
        </p>
        <p><?php esc_html_e( 'Tabla:', 'mad-suite' ); ?> <strong id="mad-dbm-truncate-table-name"></strong></p>
        <p>
            <label>
                <input type="checkbox" id="mad-dbm-truncate-confirm-check">
                <?php esc_html_e( 'Confirmo que quiero vaciar esta tabla y que se ha creado un backup antes.', 'mad-suite' ); ?>
            </label>
        </p>
        <div class="mad-dbm-modal-actions">
            <button class="button button-primary" id="mad-dbm-truncate-confirm-btn" disabled>
                <?php esc_html_e( 'Vaciar tabla', 'mad-suite' ); ?>
            </button>
            <button class="button" id="mad-dbm-truncate-cancel-btn"><?php esc_html_e( 'Cancelar', 'mad-suite' ); ?></button>
        </div>
        <div id="mad-dbm-truncate-result" style="display:none"></div>
    </div>
</div>
<div class="mad-dbm-modal-overlay" style="display:none"></div>
