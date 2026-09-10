<?php
/** Vista: importar redirecciones masivas desde un archivo CSV. */
defined( 'ABSPATH' ) || exit;

$import_results = null;
$import_error   = '';

if ( isset( $_POST['mad_redirect_import_submit'] ) && isset( $_FILES['csv_file'] ) ) {
    check_admin_referer( 'mad_redirect_import_csv' );

    $file = $_FILES['csv_file'];

    if ( UPLOAD_ERR_OK !== $file['error'] ) {
        $import_error = __( 'No se pudo subir el archivo.', 'mad-suite' );
    } elseif ( 'csv' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
        $import_error = __( 'Solo se permiten archivos .csv', 'mad-suite' );
    } else {
        $import_results = $this->import_csv( $file['tmp_name'] );
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Redirecciones SEO', 'mad-suite' ); ?></h1>

    <nav class="nav-tab-wrapper" style="margin: 16px 0;">
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug() ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab">
            <?php esc_html_e( 'Listado', 'mad-suite' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'import' ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab nav-tab-active">
            <?php esc_html_e( 'Importar CSV', 'mad-suite' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'log404' ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab">
            <?php esc_html_e( '404 sin redirigir', 'mad-suite' ); ?>
        </a>
    </nav>

    <?php if ( $import_error ) : ?>
        <div class="notice notice-error"><p><strong><?php esc_html_e( 'Error:', 'mad-suite' ); ?></strong> <?php echo esc_html( $import_error ); ?></p></div>
    <?php endif; ?>

    <?php if ( $import_results ) : ?>
        <div class="notice notice-success">
            <h3><?php esc_html_e( 'Importación completada', 'mad-suite' ); ?></h3>
            <ul style="list-style:disc;padding-left:20px;">
                <li style="color:#27ae60;"><strong><?php echo (int) $import_results['imported']; ?></strong> <?php esc_html_e( 'redirecciones nuevas', 'mad-suite' ); ?></li>
                <li style="color:#2271b1;"><strong><?php echo (int) $import_results['updated']; ?></strong> <?php esc_html_e( 'actualizadas (ya existían con esa URL antigua)', 'mad-suite' ); ?></li>
                <li style="color:#f39c12;"><strong><?php echo (int) $import_results['skipped']; ?></strong> <?php esc_html_e( 'omitidas', 'mad-suite' ); ?></li>
                <li style="color:#e74c3c;"><strong><?php echo (int) $import_results['errors']; ?></strong> <?php esc_html_e( 'errores', 'mad-suite' ); ?></li>
            </ul>
            <?php if ( ! empty( $import_results['details'] ) ) : ?>
                <details style="margin-top:15px;">
                    <summary style="cursor:pointer;font-weight:600;padding:10px;background:#f0f0f1;border:1px solid #ddd;"><?php esc_html_e( 'Ver detalle fila por fila', 'mad-suite' ); ?></summary>
                    <div style="max-height:400px;overflow-y:auto;background:#fff;padding:15px;border:1px solid #ddd;border-top:0;">
                        <ul style="list-style:none;padding:0;font-size:13px;font-family:monospace;">
                            <?php foreach ( $import_results['details'] as $detail ) : ?>
                                <li style="margin:3px 0;"><?php echo esc_html( $detail ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </details>
            <?php endif; ?>
            <p><a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug() ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Ver listado completo →', 'mad-suite' ); ?></a></p>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width:800px;margin-top:20px;padding:20px;background:#fff;border:1px solid #ccd0d4;">
        <h2><?php esc_html_e( 'Formato del archivo CSV', 'mad-suite' ); ?></h2>
        <div style="background:#f9f9f9;padding:15px;border-left:4px solid #2271b1;margin:15px 0;">
            <ol style="line-height:1.8;">
                <li><?php esc_html_e( 'Columna 1: URL o ruta antigua (obligatoria).', 'mad-suite' ); ?></li>
                <li><?php esc_html_e( 'Columna 2: URL o ruta nueva (obligatoria, salvo que la columna 3 sea 410).', 'mad-suite' ); ?></li>
                <li><?php esc_html_e( 'Columna 3: código de estado — 301, 302, 307 o 410 (opcional, si se omite se usa 301).', 'mad-suite' ); ?></li>
                <li><?php esc_html_e( 'La primera fila puede ser un encabezado (old_url, new_url, status_code) — se detecta sola y se salta.', 'mad-suite' ); ?></li>
                <li><?php esc_html_e( 'Si una URL antigua ya existe en el sistema, se actualiza con los nuevos datos en vez de duplicarse — podés reimportar el mismo archivo corregido sin problema.', 'mad-suite' ); ?></li>
            </ol>
        </div>
        <div style="background:#fff;padding:15px;border:1px solid #ddd;border-radius:4px;margin:15px 0;">
            <h4 style="margin-top:0;"><?php esc_html_e( 'Ejemplo:', 'mad-suite' ); ?></h4>
            <pre style="background:#f5f5f5;padding:15px;border:1px solid #ddd;border-radius:4px;font-family:monospace;font-size:13px;">old_url,new_url,status_code
/producto-viejo-1,/producto/sofa-milano,301
/categoria-antigua/*,/categoria-nueva/$1,301
/liquidacion-2023,,410</pre>
        </div>
        <p>
            <a href="#" onclick="madRedirectDownloadTemplate(); return false;" class="button button-secondary">
                <?php esc_html_e( 'Descargar plantilla CSV', 'mad-suite' ); ?>
            </a>
        </p>

        <hr style="margin:30px 0;">

        <h2><?php esc_html_e( 'Subir archivo', 'mad-suite' ); ?></h2>
        <form method="post" enctype="multipart/form-data" style="margin-top:15px;">
            <?php wp_nonce_field( 'mad_redirect_import_csv' ); ?>
            <input type="file" name="csv_file" accept=".csv" required>
            <p class="submit">
                <button type="submit" name="mad_redirect_import_submit" class="button button-primary button-hero">
                    <?php esc_html_e( 'Importar redirecciones', 'mad-suite' ); ?>
                </button>
            </p>
        </form>
    </div>
</div>

<script>
function madRedirectDownloadTemplate() {
    var csv = 'old_url,new_url,status_code\n/producto-viejo-1,/producto/sofa-milano,301\n/categoria-antigua/*,/categoria-nueva/$1,301\n/liquidacion-2023,,410\n';
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'plantilla-redirecciones.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
