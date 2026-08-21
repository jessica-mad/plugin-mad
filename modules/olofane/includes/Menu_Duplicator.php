<?php
/**
 * Menu Duplicator — adds a "Duplicate" action to each nav menu in Appearance.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Olofane_Menu_Duplicator {

    public function init(): void {
        add_action( 'admin_menu',  [ $this, 'add_submenu' ] );
        add_action( 'admin_init',  [ $this, 'handle_duplicate' ] );
        add_action( 'admin_notices', [ $this, 'show_notice' ] );
    }

    // ── Admin page ────────────────────────────────────────────────────────────

    public function add_submenu(): void {
        add_submenu_page(
            'themes.php',
            __( 'Duplicar Menú', 'mad-suite' ),
            __( 'Duplicar Menú', 'mad-suite' ),
            'edit_theme_options',
            'mad-menu-duplicator',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }

        $menus = wp_get_nav_menus();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Duplicar Menú', 'mad-suite' ); ?></h1>
            <p><?php esc_html_e( 'Selecciona un menú para crear una copia exacta con todos sus ítems y niveles de profundidad.', 'mad-suite' ); ?></p>

            <?php if ( empty( $menus ) ) : ?>
                <p><?php esc_html_e( 'No hay menús de navegación creados todavía.', 'mad-suite' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Nombre del menú', 'mad-suite' ); ?></th>
                            <th><?php esc_html_e( 'Ítems', 'mad-suite' ); ?></th>
                            <th><?php esc_html_e( 'Acción', 'mad-suite' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $menus as $menu ) :
                        $items = wp_get_nav_menu_items( $menu->term_id );
                        $count = is_array( $items ) ? count( $items ) : 0;
                        $url   = wp_nonce_url(
                            add_query_arg(
                                [
                                    'action'  => 'mad_duplicate_menu',
                                    'menu_id' => $menu->term_id,
                                ],
                                admin_url( 'admin.php' )
                            ),
                            'mad_duplicate_menu_' . $menu->term_id
                        );
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html( $menu->name ); ?></strong></td>
                            <td><?php echo (int) $count; ?></td>
                            <td>
                                <a href="<?php echo esc_url( $url ); ?>" class="button">
                                    <?php esc_html_e( 'Duplicar', 'mad-suite' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Handle action ─────────────────────────────────────────────────────────

    public function handle_duplicate(): void {
        if (
            ! isset( $_GET['action'], $_GET['menu_id'] ) ||
            $_GET['action'] !== 'mad_duplicate_menu'
        ) {
            return;
        }

        if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }

        $menu_id = (int) $_GET['menu_id'];
        check_admin_referer( 'mad_duplicate_menu_' . $menu_id );

        $result = $this->duplicate_menu( $menu_id );

        $redirect = add_query_arg(
            [
                'page'        => 'mad-menu-duplicator',
                'mad_duped'   => is_wp_error( $result ) ? '0' : '1',
                'mad_duped_id'=> is_wp_error( $result ) ? '' : $result,
            ],
            admin_url( 'themes.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    public function show_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'appearance_page_mad-menu-duplicator' ) {
            return;
        }

        if ( isset( $_GET['mad_duped'] ) ) {
            if ( $_GET['mad_duped'] === '1' && ! empty( $_GET['mad_duped_id'] ) ) {
                $new_id   = (int) $_GET['mad_duped_id'];
                $new_menu = wp_get_nav_menu_object( $new_id );
                $edit_url = admin_url( 'nav-menus.php?action=edit&menu=' . $new_id );
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
                    sprintf(
                        /* translators: %s: menu name */
                        esc_html__( 'Menú duplicado correctamente: "%s".', 'mad-suite' ),
                        esc_html( $new_menu ? $new_menu->name : '' )
                    ),
                    esc_url( $edit_url ),
                    esc_html__( 'Editar menú duplicado', 'mad-suite' )
                );
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' .
                    esc_html__( 'Error al duplicar el menú. Inténtalo de nuevo.', 'mad-suite' ) .
                    '</p></div>';
            }
        }
    }

    // ── Duplication logic ─────────────────────────────────────────────────────

    /**
     * Duplicates a nav menu and all its items, preserving parent-child hierarchy.
     *
     * @param  int $menu_id  Source menu term ID.
     * @return int|\WP_Error New menu term ID on success, WP_Error on failure.
     */
    private function duplicate_menu( $menu_id ) {
        $source = wp_get_nav_menu_object( $menu_id );
        if ( ! $source ) {
            return new WP_Error( 'not_found', __( 'Menú de origen no encontrado.', 'mad-suite' ) );
        }

        // Create new menu with "(Copia)" suffix, ensuring unique name
        $new_name   = $source->name . ' ' . __( '(Copia)', 'mad-suite' );
        $new_menu_id = wp_create_nav_menu( $new_name );
        if ( is_wp_error( $new_menu_id ) ) {
            return $new_menu_id;
        }

        // Fetch all items (including drafts)
        $items = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'publish,draft' ] );
        if ( empty( $items ) ) {
            return $new_menu_id;
        }

        // First pass: create items without parent so IDs are known
        $id_map = []; // old_id => new_id
        foreach ( $items as $item ) {
            $args = [
                'menu-item-object-id'   => $item->object_id,
                'menu-item-object'      => $item->object,
                'menu-item-type'        => $item->type,
                'menu-item-title'       => $item->title,
                'menu-item-url'         => $item->url,
                'menu-item-description' => $item->description,
                'menu-item-attr-title'  => $item->attr_title,
                'menu-item-target'      => $item->target,
                'menu-item-classes'     => implode( ' ', (array) $item->classes ),
                'menu-item-xfn'         => $item->xfn,
                'menu-item-position'    => $item->menu_order,
                'menu-item-status'      => $item->post_status,
            ];

            $new_item_id = wp_update_nav_menu_item( $new_menu_id, 0, $args );
            if ( ! is_wp_error( $new_item_id ) ) {
                $id_map[ $item->ID ] = $new_item_id;
            }
        }

        // Second pass: restore parent-child relationships using mapped IDs
        foreach ( $items as $item ) {
            $old_parent = (int) $item->menu_item_parent;
            if ( $old_parent && isset( $id_map[ $old_parent ], $id_map[ $item->ID ] ) ) {
                update_post_meta(
                    $id_map[ $item->ID ],
                    '_menu_item_menu_item_parent',
                    (string) $id_map[ $old_parent ]
                );
            }
        }

        return $new_menu_id;
    }
}
