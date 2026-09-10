<?php
/** Vista: URLs que dieron 404 y todavía no tienen redirección. */
defined( 'ABSPATH' ) || exit;

$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page = 30;

$data  = $this->get_404_log( $per_page, $paged );
$rows  = $data['rows'];
$total = $data['total'];
$pages = (int) ceil( $total / $per_page );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Redirecciones SEO', 'mad-suite' ); ?></h1>

    <nav class="nav-tab-wrapper" style="margin: 16px 0;">
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug() ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab">
            <?php esc_html_e( 'Listado', 'mad-suite' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'import' ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab">
            <?php esc_html_e( 'Importar CSV', 'mad-suite' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'log404' ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab nav-tab-active">
            <?php esc_html_e( '404 sin redirigir', 'mad-suite' ); ?>
        </a>
    </nav>

    <?php if ( isset( $_GET['cleared'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log limpiado.', 'mad-suite' ); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php esc_html_e( 'URLs que dieron 404 en el sitio y todavía no tienen una redirección cargada. Útil para detectar, después de la migración, qué URLs viejas siguen sin mapear. Se ignoran archivos estáticos (imágenes, CSS, JS, etc.).', 'mad-suite' ); ?>
    </p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'URL', 'mad-suite' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'Veces', 'mad-suite' ); ?></th>
                <th style="width:160px;"><?php esc_html_e( 'Primera vez', 'mad-suite' ); ?></th>
                <th style="width:160px;"><?php esc_html_e( 'Última vez', 'mad-suite' ); ?></th>
                <th style="width:150px;"><?php esc_html_e( 'Acciones', 'mad-suite' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $rows ) ) : ?>
            <tr><td colspan="5"><?php esc_html_e( 'Sin registros — ninguna URL dio 404 todavía (o ya están todas redirigidas).', 'mad-suite' ); ?></td></tr>
        <?php else : foreach ( $rows as $row ) :
            $create_url = add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'new', 'from' => $row['url'] ], admin_url( 'admin.php' ) );
        ?>
            <tr>
                <td><code><?php echo esc_html( $row['url'] ); ?></code></td>
                <td><?php echo (int) $row['hits']; ?></td>
                <td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $row['first_seen'] ) ); ?></td>
                <td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $row['last_seen'] ) ); ?></td>
                <td><a href="<?php echo esc_url( $create_url ); ?>" class="button button-small"><?php esc_html_e( 'Crear redirección', 'mad-suite' ); ?></a></td>
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

    <p style="margin-top:20px;">
        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'mad_redirect_clear_404log' ], admin_url( 'admin-post.php' ) ), 'mad_redirect_clear_404log' ) ); ?>"
           class="button"
           onclick="return confirm('<?php echo esc_js( __( '¿Vaciar todo el log de 404? Esta acción no se puede deshacer.', 'mad-suite' ) ); ?>');">
            <?php esc_html_e( 'Vaciar log', 'mad-suite' ); ?>
        </a>
    </p>
</div>
