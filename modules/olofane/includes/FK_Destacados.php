<?php
/**
 * FK Destacados — en el bloque "Product" de FunnelKit, si Feed = "Specific
 * Categories" y Sort by = "Random", muestra solo los productos destacados
 * en el orden manual del backend (Productos → Ordenar), en vez de un
 * feed aleatorio por categoría.
 *
 * El combo "Specific Categories" + "Random" no tiene sentido real para
 * este bloque — se reutiliza como marcador para activar este
 * comportamiento sin tener que esperar una opción nativa de FunnelKit.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Olofane_FK_Destacados {

    /** true mientras FunnelKit renderiza un bloque marcado. */
    private $active = false;

    public function init(): void {
        // Envío real del email: el bloque se renderiza con el shortcode [bwfbe_multi_product].
        add_filter( 'pre_do_shortcode_tag', [ $this, 'before_shortcode' ], 10, 4 );
        add_filter( 'do_shortcode_tag',     [ $this, 'after_shortcode' ], 10, 2 );

        // Consulta de productos.
        add_action( 'pre_get_posts', [ $this, 'modify_query' ], 999 );
    }

    public function before_shortcode( $return, $tag, $attr, $m ) {
        if ( 'bwfbe_multi_product' !== $tag || ! class_exists( 'BWFCRM_Block_Editor' ) ) {
            return $return;
        }
        $content      = isset( $m[5] ) ? $m[5] : '';
        $settings     = json_decode( BWFCRM_Block_Editor::decode_content( $content ), true );
        $this->active = is_array( $settings ) && $this->is_marked(
            $settings['productFeedType'] ?? '',
            $settings['sortBy'] ?? ''
        );
        return $return;
    }

    public function after_shortcode( $output, $tag ) {
        if ( 'bwfbe_multi_product' === $tag ) {
            $this->active = false;
        }
        return $output;
    }

    /** Marcador: Feed "Specific Categories" + Sort by "Random". */
    private function is_marked( $feed, $sort ): bool {
        return 'category' === $feed && 'random' === $sort;
    }

    /** Vista previa del editor (REST autonami-app/bwf-products). */
    private function is_editor_preview(): bool {
        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
            return false;
        }
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( false === strpos( $uri, 'autonami-app/bwf-products' ) ) {
            return false;
        }
        $type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
        $sort = isset( $_GET['sortby'] ) ? sanitize_text_field( wp_unslash( $_GET['sortby'] ) ) : '';
        return $this->is_marked( $type, $sort );
    }

    public function modify_query( $query ): void {
        if ( ! $this->active && ! $this->is_editor_preview() ) {
            return;
        }
        $pt = (array) $query->get( 'post_type' );
        if ( ! in_array( 'product', $pt, true ) ) {
            return;
        }

        // Solo destacados.
        $tax   = (array) $query->get( 'tax_query' );
        $tax[] = [
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => [ 'featured' ],
            'operator' => 'IN',
        ];
        $query->set( 'tax_query', $tax );

        // Orden personalizado del backend (igual que WooCommerce, vía menu_order).
        $query->set( 'meta_key', '' );
        $query->set( 'orderby', [ 'menu_order' => 'ASC', 'title' => 'ASC' ] );
        $query->set( 'order', 'ASC' );

        // Sin límite: el "número de productos" del bloque de FunnelKit (pensado
        // para un feed aleatorio) no debe recortar la lista de destacados —
        // deben salir todos los que estén marcados como destacado.
        $query->set( 'posts_per_page', -1 );
        $query->set( 'nopaging', true );
    }
}
