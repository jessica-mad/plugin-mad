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
 * NOTA sobre el enganche: inicialmente se interceptaba vía los filtros
 * nativos de shortcodes de WordPress (pre_do_shortcode_tag /
 * do_shortcode_tag), pero el envío real del email de FunnelKit NO pasa
 * por do_shortcode() — llama al callback del bloque directamente. Por
 * eso esos filtros nunca se disparaban en un envío real (solo, quizás,
 * en alguna vista previa). Se reemplaza en su lugar el propio callback
 * registrado del shortcode (bwfbe_multi_product) por un wrapper propio,
 * que sí se ejecuta sin importar cómo FunnelKit termine invocándolo.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Olofane_FK_Destacados {

    /** true mientras se renderiza un bloque marcado. */
    private $active = false;

    public function init(): void {
        // Se reemplaza el callback del shortcode en cuanto la clase del
        // bloque esté disponible — en 'init' (tarde) para dar tiempo a que
        // FunnelKit ya se haya cargado, sin depender de en qué hook exacto
        // decida registrar su propio shortcode.
        add_action( 'init', [ $this, 'maybe_override_shortcode' ], 20 );

        // Consulta de productos.
        add_action( 'pre_get_posts', [ $this, 'modify_query' ], 999 );

        // TEMPORAL: diagnóstico — quitar una vez confirmado que el override funciona.
        add_filter( 'the_posts', [ $this, 'log_final_post_count' ], 999, 2 );
    }

    /** Reemplaza el callback de [bwfbe_multi_product] por nuestro wrapper. */
    public function maybe_override_shortcode(): void {
        if ( ! class_exists( 'BWFBE_WC_Multi_Product_Template' ) || ! class_exists( 'BWFCRM_Block_Editor' ) ) {
            return;
        }
        // get_instance() crea la instancia si todavía no existe (su propio
        // constructor registra el shortcode original); la reemplazamos acto
        // seguido por nuestro wrapper, que al final llama al método real.
        $instance = BWFBE_WC_Multi_Product_Template::get_instance();
        add_shortcode( 'bwfbe_multi_product', function ( $atts, $content = '', $tag = '' ) use ( $instance ) {
            return $this->render_wrapped_block( $instance, $atts, $content, $tag );
        } );

        // TEMPORAL: diagnóstico — quitar una vez confirmado que el override funciona.
        $this->log( 'maybe_override_shortcode: wrapper instalado sobre bwfbe_multi_product' );
    }

    /** Detecta el marcador, delega al render original con el flag activo, y lo limpia al terminar. */
    private function render_wrapped_block( $instance, $atts, $content, $tag ) {
        $settings     = json_decode( BWFCRM_Block_Editor::decode_content( (string) $content ), true );
        $this->active = is_array( $settings ) && $this->is_marked(
            $settings['productFeedType'] ?? '',
            $settings['sortBy'] ?? ''
        );

        // TEMPORAL: diagnóstico — quitar una vez confirmado que el override funciona.
        $this->log( sprintf(
            'render_wrapped_block: wrapper ejecutado — feed=%s sort=%s active=%s',
            var_export( $settings['productFeedType'] ?? null, true ),
            var_export( $settings['sortBy'] ?? null, true ),
            $this->active ? 'true' : 'false'
        ) );

        $output = $instance->multi_products_block( $atts, $content, $tag );

        $this->active = false;

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

    /** TEMPORAL: diagnóstico — quitar junto con las demás llamadas de log una vez confirmado el fix. */
    public function log_final_post_count( $posts, $query ) {
        $pt = (array) $query->get( 'post_type' );
        if ( in_array( 'product', $pt, true ) ) {
            $this->log( 'the_posts: post_type=product, active=' . ( $this->active ? 'true' : 'false' ) . ', total devueltos = ' . count( (array) $posts ) . ', posts_per_page final = ' . var_export( $query->get( 'posts_per_page' ), true ) );
        }
        return $posts;
    }

    /** TEMPORAL: log de diagnóstico — quitar junto con las llamadas de arriba una vez confirmado el fix. */
    private function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[MAD FK Destacados] ' . $message );
        }
    }
}
