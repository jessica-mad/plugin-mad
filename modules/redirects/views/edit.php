<?php
/** Vista: alta/edición de una redirección individual. */
defined( 'ABSPATH' ) || exit;

$id  = absint( $_GET['id'] ?? 0 );
$row = $id ? $this->get_redirect( $id ) : null;

// Prefill desde el log de 404 ("Crear redirección" en esa vista).
$prefill_source = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';

$source_path = $row['source_path'] ?? $prefill_source;
$destination = $row['destination'] ?? '';
$status_code = (int) ( $row['status_code'] ?? 301 );
$is_active   = isset( $row['is_active'] ) ? (bool) $row['is_active'] : true;
?>
<div class="wrap">
    <h1><?php echo $row ? esc_html__( 'Editar redirección', 'mad-suite' ) : esc_html__( 'Nueva redirección', 'mad-suite' ); ?></h1>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;margin-top:20px;">
        <input type="hidden" name="action" value="mad_redirect_save">
        <input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
        <?php wp_nonce_field( 'mad_redirect_save' ); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="source_path"><?php esc_html_e( 'URL o ruta antigua', 'mad-suite' ); ?></label></th>
                <td>
                    <input type="text" name="source_path" id="source_path" value="<?php echo esc_attr( $source_path ); ?>" class="regular-text" required>
                    <p class="description">
                        <?php esc_html_e( 'Podés pegar la URL completa o solo la ruta (ej: /producto-viejo/). Usá * para un patrón: /categoria-vieja/* redirige todo lo que empiece así.', 'mad-suite' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="destination"><?php esc_html_e( 'URL o ruta nueva', 'mad-suite' ); ?></label></th>
                <td>
                    <input type="text" name="destination" id="destination" value="<?php echo esc_attr( $destination ); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e( 'Si el origen usa *, podés usar $1 en el destino para insertar lo que haya capturado el patrón (ej: /categoria-vieja/* → /categoria-nueva/$1). Dejá vacío solo si el código es 410 (contenido eliminado a propósito, sin redirigir a ningún lado).', 'mad-suite' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="status_code"><?php esc_html_e( 'Código de estado', 'mad-suite' ); ?></label></th>
                <td>
                    <select name="status_code" id="status_code">
                        <option value="301" <?php selected( $status_code, 301 ); ?>>301 — <?php esc_html_e( 'Movido permanentemente (lo normal para SEO)', 'mad-suite' ); ?></option>
                        <option value="302" <?php selected( $status_code, 302 ); ?>>302 — <?php esc_html_e( 'Movido temporalmente', 'mad-suite' ); ?></option>
                        <option value="307" <?php selected( $status_code, 307 ); ?>>307 — <?php esc_html_e( 'Redirección temporal (preserva método POST)', 'mad-suite' ); ?></option>
                        <option value="410" <?php selected( $status_code, 410 ); ?>>410 — <?php esc_html_e( 'Eliminado a propósito (sin destino)', 'mad-suite' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Activa', 'mad-suite' ); ?></th>
                <td>
                    <label><input type="checkbox" name="is_active" value="1" <?php checked( $is_active ); ?>> <?php esc_html_e( 'Sí, redirigir ya mismo', 'mad-suite' ); ?></label>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar redirección', 'mad-suite' ); ?></button>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug() ], admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Cancelar', 'mad-suite' ); ?></a>
        </p>
    </form>
</div>
