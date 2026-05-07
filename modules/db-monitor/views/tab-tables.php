<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Variables: $tables (array from analyzer), $cleanable_config (array), $module
 */
$max_mb = ! empty( $tables ) ? max( 0.001, $tables[0]['total_mb'] ) : 1;
?>
<div class="mad-dbm-tables">

    <p class="description">
        <?php esc_html_e( 'Tablas ordenadas de mayor a menor tamaño. Las acciones destructivas generan un backup automático antes de ejecutarse.', 'mad-suite' ); ?>
    </p>

    <!-- Search bar -->
    <div class="mad-dbm-search-bar">
        <span class="dashicons dashicons-search" style="line-height:30px;color:#646970"></span>
        <input type="search"
               id="mad-dbm-table-search"
               class="regular-text"
               placeholder="<?php esc_attr_e( 'Buscar tabla…', 'mad-suite' ); ?>">
        <span id="mad-dbm-search-count" class="description" style="line-height:30px"></span>
    </div>

    <table class="wp-list-table widefat fixed striped mad-dbm-table-list" id="mad-dbm-table-list">
        <thead>
            <tr>
                <th style="width:26%"><?php esc_html_e( 'Tabla', 'mad-suite' ); ?></th>
                <th style="width:9%"><?php esc_html_e( 'Registros', 'mad-suite' ); ?></th>
                <th style="width:12%"><?php esc_html_e( 'Total MB', 'mad-suite' ); ?></th>
                <th style="width:9%"><?php esc_html_e( 'Datos MB', 'mad-suite' ); ?></th>
                <th style="width:9%"><?php esc_html_e( 'Índices MB', 'mad-suite' ); ?></th>
                <th style="width:7%"><?php esc_html_e( 'Motor', 'mad-suite' ); ?></th>
                <th style="width:8%"><?php esc_html_e( 'Estado', 'mad-suite' ); ?></th>
                <th><?php esc_html_e( 'Acciones', 'mad-suite' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $tables as $t ) :
            $pct         = min( 100, round( $t['total_mb'] / $max_mb * 100, 1 ) );
            $cfg_entry   = $cleanable_config[ $t['name'] ] ?? null;
            $default_days = (int) ( $cfg_entry['default_days'] ?? 30 );
            $action_type  = $cfg_entry['actions'][0] ?? 'clean_old';
            $action_label = esc_attr( $cfg_entry['action_label'] ?? 'Registros antiguos' );
        ?>
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

                <td>
                    <div class="mad-dbm-size-bar-wrap">
                        <span><?php echo esc_html( $t['total_mb'] ); ?></span>
                        <div class="mad-dbm-size-bar" title="<?php echo esc_attr( $pct ); ?>% del total">
                            <div class="mad-dbm-size-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
                        </div>
                    </div>
                </td>

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

                    <!-- Export via AJAX (no page refresh) -->
                    <button type="button"
                            class="button button-small mad-dbm-btn-export"
                            data-table="<?php echo esc_attr( $t['name'] ); ?>"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'mad_dbm_nonce' ) ); ?>">
                        <?php esc_html_e( 'Exportar', 'mad-suite' ); ?>
                    </button>

                    <?php if ( $t['is_cleanable'] ) : ?>

                        <button type="button"
                                class="button button-small mad-dbm-btn-clean"
                                data-table="<?php echo esc_attr( $t['name'] ); ?>"
                                data-action="<?php echo esc_attr( $action_type ); ?>"
                                data-action-label="<?php echo esc_attr( $action_label ); ?>"
                                data-default-days="<?php echo esc_attr( $default_days ); ?>">
                            <?php esc_html_e( 'Limpiar antiguos', 'mad-suite' ); ?>
                        </button>

                        <?php if ( in_array( 'truncate', $cfg_entry['actions'] ?? [], true ) ) : ?>
                        <button type="button"
                                class="button button-small button-link-delete mad-dbm-btn-truncate"
                                data-table="<?php echo esc_attr( $t['name'] ); ?>">
                            <?php esc_html_e( 'Vaciar', 'mad-suite' ); ?>
                        </button>
                        <?php endif; ?>

                    <?php endif; ?>

                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p id="mad-dbm-no-results" style="display:none;color:#646970;font-style:italic">
        <?php esc_html_e( 'No se encontraron tablas que coincidan con la búsqueda.', 'mad-suite' ); ?>
    </p>

</div>

<!-- ── Clean old records modal ── -->
<div id="mad-dbm-clean-modal" class="mad-dbm-modal" style="display:none" role="dialog" aria-modal="true">
    <div class="mad-dbm-modal-inner">
        <button class="mad-dbm-modal-close" aria-label="Cerrar">✕</button>
        <h2><?php esc_html_e( 'Limpiar registros antiguos', 'mad-suite' ); ?></h2>

        <p class="mad-dbm-modal-warning">
            ⚠️ <?php esc_html_e( 'Se creará una copia de seguridad automática antes de ejecutar la limpieza. Si el backup falla, la limpieza se cancelará.', 'mad-suite' ); ?>
        </p>

        <table class="form-table" style="margin:0">
            <tr>
                <th style="padding:6px 10px 6px 0;width:110px"><?php esc_html_e( 'Tabla:', 'mad-suite' ); ?></th>
                <td><strong id="mad-dbm-clean-table-name"></strong></td>
            </tr>
            <tr>
                <th style="padding:6px 10px 6px 0"><?php esc_html_e( 'Limpia:', 'mad-suite' ); ?></th>
                <td><em id="mad-dbm-clean-action-label" style="color:#2271b1"></em></td>
            </tr>
            <tr>
                <th style="padding:6px 10px 6px 0"><?php esc_html_e( 'Más antiguos de:', 'mad-suite' ); ?></th>
                <td>
                    <input type="number" id="mad-dbm-clean-days" value="30" min="1" max="3650" style="width:75px">
                    <?php esc_html_e( 'días', 'mad-suite' ); ?>
                </td>
            </tr>
        </table>

        <div id="mad-dbm-clean-preview" class="mad-dbm-preview-box" style="display:none"></div>

        <div class="mad-dbm-modal-actions">
            <button class="button" id="mad-dbm-preview-btn"><?php esc_html_e( 'Calcular registros a eliminar', 'mad-suite' ); ?></button>
            <button class="button button-primary" id="mad-dbm-clean-confirm-btn" disabled><?php esc_html_e( 'Confirmar limpieza', 'mad-suite' ); ?></button>
            <button class="button mad-dbm-modal-close-btn"><?php esc_html_e( 'Cancelar', 'mad-suite' ); ?></button>
        </div>

        <div id="mad-dbm-clean-result" style="display:none;margin-top:12px"></div>
    </div>
</div>

<!-- ── Truncate modal ── -->
<div id="mad-dbm-truncate-modal" class="mad-dbm-modal" style="display:none" role="dialog" aria-modal="true">
    <div class="mad-dbm-modal-inner">
        <button class="mad-dbm-modal-close" aria-label="Cerrar">✕</button>
        <h2><?php esc_html_e( 'Vaciar tabla completa', 'mad-suite' ); ?></h2>

        <div class="notice notice-error inline" style="margin:0 0 16px">
            <p>⚠️ <?php esc_html_e( 'Esta acción eliminará TODOS los registros de la tabla. Se creará un backup automático antes de proceder.', 'mad-suite' ); ?></p>
        </div>

        <p><?php esc_html_e( 'Tabla:', 'mad-suite' ); ?> <strong id="mad-dbm-truncate-table-name"></strong></p>

        <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer">
            <input type="checkbox" id="mad-dbm-truncate-confirm-check" style="margin-top:3px">
            <span><?php esc_html_e( 'Confirmo que quiero vaciar esta tabla y entiendo que se sobrescribirán todos sus registros.', 'mad-suite' ); ?></span>
        </label>

        <div class="mad-dbm-modal-actions">
            <button class="button button-primary" id="mad-dbm-truncate-confirm-btn" disabled>
                <?php esc_html_e( 'Vaciar tabla', 'mad-suite' ); ?>
            </button>
            <button class="button mad-dbm-modal-close-btn"><?php esc_html_e( 'Cancelar', 'mad-suite' ); ?></button>
        </div>

        <div id="mad-dbm-truncate-result" style="display:none;margin-top:12px"></div>
    </div>
</div>

<div class="mad-dbm-modal-overlay" style="display:none"></div>
