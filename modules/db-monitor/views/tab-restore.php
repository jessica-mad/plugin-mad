<?php
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="mad-dbm-restore">

    <h2><?php esc_html_e( 'Restaurar tabla desde backup', 'mad-suite' ); ?></h2>

    <div class="notice notice-warning inline">
        <p>
            <strong><?php esc_html_e( 'Importante:', 'mad-suite' ); ?></strong>
            <?php esc_html_e( 'Solo se pueden restaurar archivos .sql.gz exportados por este mismo plugin. Las tablas críticas de WordPress y WooCommerce no pueden restaurarse desde aquí.', 'mad-suite' ); ?>
        </p>
    </div>

    <!-- Step 1: Upload -->
    <div id="mad-dbm-restore-step-1" class="mad-dbm-restore-step">
        <h3><?php esc_html_e( 'Paso 1 — Seleccionar archivo', 'mad-suite' ); ?></h3>
        <form id="mad-dbm-restore-form" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'mad_dbm_restore_upload', 'mad_dbm_restore_nonce' ); ?>
            <input type="hidden" name="action" value="mad_dbm_restore_upload">
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Archivo .sql.gz', 'mad-suite' ); ?></th>
                    <td>
                        <input type="file" name="mad_dbm_restore_file" accept=".gz" required>
                        <p class="description"><?php esc_html_e( 'Solo archivos generados por este plugin (dbm_*.sql.gz).', 'mad-suite' ); ?></p>
                    </td>
                </tr>
            </table>
            <button type="submit" class="button button-primary">
                <?php esc_html_e( 'Subir y verificar archivo', 'mad-suite' ); ?>
            </button>
        </form>
    </div>

    <!-- Step 2: Preview + confirmation -->
    <div id="mad-dbm-restore-step-2" class="mad-dbm-restore-step" style="display:none">
        <h3><?php esc_html_e( 'Paso 2 — Verificar información', 'mad-suite' ); ?></h3>

        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Tabla a restaurar', 'mad-suite' ); ?></th>
                <td><strong id="mad-dbm-restore-table-name">—</strong></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Fecha de exportación', 'mad-suite' ); ?></th>
                <td id="mad-dbm-restore-date">—</td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Tipo de acción origen', 'mad-suite' ); ?></th>
                <td id="mad-dbm-restore-action">—</td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Exportado por', 'mad-suite' ); ?></th>
                <td id="mad-dbm-restore-user">—</td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Registros en el backup', 'mad-suite' ); ?></th>
                <td id="mad-dbm-restore-rows">—</td>
            </tr>
        </table>

        <div class="notice notice-error inline">
            <p>
                ⚠️ <strong><?php esc_html_e( 'Advertencia:', 'mad-suite' ); ?></strong>
                <?php esc_html_e( 'La restauración eliminará los datos actuales de la tabla y los reemplazará con los del backup. Esta acción no puede deshacerse fácilmente.', 'mad-suite' ); ?>
            </p>
        </div>

        <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;margin:12px 0">
            <input type="checkbox" id="mad-dbm-restore-confirm-check" style="margin-top:3px">
            <span><?php esc_html_e( 'Confirmo que quiero restaurar esta tabla y entiendo que los datos actuales serán reemplazados.', 'mad-suite' ); ?></span>
        </label>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="button button-primary" id="mad-dbm-restore-execute-btn" disabled>
                <?php esc_html_e( 'Restaurar tabla', 'mad-suite' ); ?>
            </button>
            <button class="button" id="mad-dbm-restore-cancel-btn">
                <?php esc_html_e( 'Cancelar — subir otro archivo', 'mad-suite' ); ?>
            </button>
        </div>

        <!-- Server-side token (replaces tmp_path in client) -->
        <input type="hidden" id="mad-dbm-restore-upload-token" value="">
        <input type="hidden" id="mad-dbm-restore-expected-table" value="">
        <?php wp_nonce_field( 'mad_dbm_restore_execute', 'mad_dbm_restore_execute_nonce' ); ?>
    </div>

    <!-- Step 3: Result -->
    <div id="mad-dbm-restore-step-3" class="mad-dbm-restore-step" style="display:none">
        <h3><?php esc_html_e( 'Paso 3 — Resultado', 'mad-suite' ); ?></h3>
        <div id="mad-dbm-restore-result"></div>
        <button class="button" id="mad-dbm-restore-again-btn" style="margin-top:12px">
            <?php esc_html_e( 'Restaurar otro archivo', 'mad-suite' ); ?>
        </button>
    </div>

</div>
