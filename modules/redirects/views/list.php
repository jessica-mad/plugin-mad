<?php
/** Vista: listado de redirecciones. */
defined( 'ABSPATH' ) || exit;

$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page = 20;

$data  = $this->get_redirects( $search, $per_page, $paged );
$rows  = $data['rows'];
$total = $data['total'];
$pages = (int) ceil( $total / $per_page );

$status_labels = [ 301 => '301', 302 => '302', 307 => '307', 410 => '410 (Gone)' ];
?>
<div class="wrap">
    <h1>
        <?php esc_html_e( 'Redirecciones SEO', 'mad-suite' ); ?>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'new' ], admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
            <?php esc_html_e( '+ Nueva redirección', 'mad-suite' ); ?>
        </a>
    </h1>

    <nav class="nav-tab-wrapper" style="margin: 16px 0;">
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug() ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab nav-tab-active">
            <?php esc_html_e( 'Listado', 'mad-suite' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'import' ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab">
            <?php esc_html_e( 'Importar CSV', 'mad-suite' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'log404' ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab">
            <?php esc_html_e( '404 sin redirigir', 'mad-suite' ); ?>
        </a>
    </nav>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirección guardada.', 'mad-suite' ); ?></p></div>
    <?php elseif ( isset( $_GET['error'] ) ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'No se pudo guardar: revisá que la URL antigua y la nueva no estén vacías ni sean iguales.', 'mad-suite' ); ?></p></div>
    <?php elseif ( isset( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirección eliminada.', 'mad-suite' ); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php esc_html_e( 'Si RankMath o Yoast ya gestionan redirecciones en este sitio, evitá cargar la misma URL acá también — el primer sistema que la encuentre es el que redirige, y si apuntan a destinos distintos es confuso de mantener. Usá uno solo por URL.', 'mad-suite' ); ?>
    </p>

    <form method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug() ); ?>">
        <p class="search-box">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Buscar por URL origen o destino…', 'mad-suite' ); ?>">
            <button type="submit" class="button"><?php esc_html_e( 'Buscar', 'mad-suite' ); ?></button>
        </p>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:60px;"><?php esc_html_e( 'Estado', 'mad-suite' ); ?></th>
                <th><?php esc_html_e( 'URL antigua', 'mad-suite' ); ?></th>
                <th><?php esc_html_e( 'URL nueva', 'mad-suite' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Código', 'mad-suite' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'Tipo', 'mad-suite' ); ?></th>
                <th style="width:70px;"><?php esc_html_e( 'Hits', 'mad-suite' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'Acciones', 'mad-suite' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $rows ) ) : ?>
            <tr><td colspan="7"><?php esc_html_e( 'Todavía no hay redirecciones cargadas.', 'mad-suite' ); ?></td></tr>
        <?php else : foreach ( $rows as $row ) :
            $toggle_url = wp_nonce_url( add_query_arg( [ 'action' => 'mad_redirect_toggle', 'id' => $row['id'] ], admin_url( 'admin-post.php' ) ), 'mad_redirect_toggle' );
            $delete_url = wp_nonce_url( add_query_arg( [ 'action' => 'mad_redirect_delete', 'id' => $row['id'] ], admin_url( 'admin-post.php' ) ), 'mad_redirect_delete' );
            $edit_url   = add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'edit', 'id' => $row['id'] ], admin_url( 'admin.php' ) );
        ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url( $toggle_url ); ?>" title="<?php echo $row['is_active'] ? esc_attr__( 'Desactivar', 'mad-suite' ) : esc_attr__( 'Activar', 'mad-suite' ); ?>">
                        <?php if ( $row['is_active'] ) : ?>
                            <span style="color:green;font-size:20px;">●</span>
                        <?php else : ?>
                            <span style="color:#ccc;font-size:20px;">○</span>
                        <?php endif; ?>
                    </a>
                </td>
                <td><code><?php echo esc_html( $row['source_path'] ); ?></code></td>
                <td><code><?php echo esc_html( $row['destination'] ?: '—' ); ?></code></td>
                <td><?php echo esc_html( $status_labels[ (int) $row['status_code'] ] ?? $row['status_code'] ); ?></td>
                <td><?php echo 'wildcard' === $row['match_type'] ? '🔤 *' : esc_html__( 'Exacta', 'mad-suite' ); ?></td>
                <td><?php echo (int) $row['hits']; ?></td>
                <td>
                    <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Editar', 'mad-suite' ); ?></a>
                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar esta redirección?', 'mad-suite' ) ); ?>');">🗑️</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ( $pages > 1 ) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $pages,
                    'prev_text' => '‹',
                    'next_text' => '›',
                ] );
                ?>
            </div>
        </div>
    <?php endif; ?>

    <p class="description">
        <?php
        printf(
            /* translators: %d: total de redirecciones */
            esc_html__( 'Total: %d redirecciones.', 'mad-suite' ),
            (int) $total
        );
        ?>
        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'mad_redirect_export_csv' ], admin_url( 'admin-post.php' ) ), 'mad_redirect_export' ) ); ?>">
            <?php esc_html_e( 'Exportar todo a CSV →', 'mad-suite' ); ?>
        </a>
    </p>
</div>
