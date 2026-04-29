<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Variables disponibles: $module, $current_tab, $tabs
 */
$base_url = admin_url( 'admin.php?page=' . esc_attr( $module->menu_slug() ) );
?>
<div class="wrap mad-dbm-wrap">
    <h1><?php esc_html_e( 'Monitor y Limpieza de Base de Datos', 'mad-suite' ); ?></h1>

    <?php if ( isset( $_GET['mad_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
        <div class="notice notice-<?php echo esc_attr( sanitize_key( $_GET['mad_notice'] ) ); ?> is-dismissible">
            <p><?php echo esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['mad_msg'] ?? '' ) ) ) ); ?></p>
        </div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper">
        <?php foreach ( $tabs as $slug => $label ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
               class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="mad-dbm-tab-content">
        <?php
        $view_file = __DIR__ . '/tab-' . $current_tab . '.php';
        if ( file_exists( $view_file ) ) {
            include $view_file;
        } else {
            echo '<p>' . esc_html__( 'Sección no encontrada.', 'mad-suite' ) . '</p>';
        }
        ?>
    </div>
</div>
