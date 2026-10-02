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
 * NOTA sobre el enganche: "Preview and Test" del editor de FunnelKit NO
 * ejecuta PHP — el editor externo (app.wpmailkit.com, en un iframe) arma
 * el HTML del bloque en el navegador (ya recortado a columns×rows) y lo
 * manda tal cual a /wp-json/autonami-app/send-test-email/. Por eso
 * ningún hook de este archivo se dispara nunca en una prueba; eso es
 * esperado, no un bug. El ENVÍO REAL de un broadcast/automatización sí
 * ejecuta el shortcode [bwfbe_multi_product] en el servidor (vía
 * do_shortcode(), dos veces por contacto), que es donde interviene este
 * código: se detecta dentro de pre_get_posts, en el momento exacto en
 * que BWFBE_WC_Multi_Product_Template ejecuta su WP_Query, inspeccionando
 * la pila de llamadas (debug_backtrace) y leyendo sus settings privados
 * (feed, sort) por Reflection directamente de la instancia en curso —
 * así no depende de en qué hook ni en qué momento FunnelKit decida
 * registrar o invocar el shortcode.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Olofane_FK_Destacados {

    public function init(): void {
        add_action( 'pre_get_posts', [ $this, 'modify_query' ], 999 );
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

    /**
     * Si la pila de llamadas actual viene de
     * BWFBE_WC_Multi_Product_Template (su método get_product_data(),
     * llamado desde multi_products_block()), lee su propiedad privada
     * $settings por Reflection, directamente de la instancia en curso.
     * Devuelve null si no estamos dentro de ese flujo.
     */
    private function get_active_block_settings(): ?array {
        if ( ! class_exists( 'BWFBE_WC_Multi_Product_Template' ) ) {
            return null;
        }

        $in_block = false;
        foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 ) as $frame ) {
            if ( isset( $frame['class'] ) && 'BWFBE_WC_Multi_Product_Template' === $frame['class'] ) {
                $in_block = true;
                break;
            }
        }
        if ( ! $in_block ) {
            return null;
        }

        try {
            $instance = BWFBE_WC_Multi_Product_Template::get_instance();
            $prop     = new ReflectionProperty( $instance, 'settings' );
            $prop->setAccessible( true );
            $settings = $prop->getValue( $instance );
            return is_array( $settings ) ? $settings : null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    public function modify_query( $query ): void {
        $pt = (array) $query->get( 'post_type' );
        if ( ! in_array( 'product', $pt, true ) ) {
            return;
        }

        if ( $this->is_editor_preview() ) {
            $marked = true;
        } else {
            $settings = $this->get_active_block_settings();
            $marked   = null !== $settings && $this->is_marked(
                $settings['productFeedType'] ?? '',
                $settings['sortBy'] ?? ''
            );

            // TEMPORAL: diagnóstico — quitar una vez confirmado en un envío real.
            // Solo loguea cuando de verdad estamos dentro del bloque (evita el
            // ruido de otras consultas de producto del sitio que no tienen
            // nada que ver con esto).
            if ( null !== $settings ) {
                $this->log( sprintf(
                    'modify_query: en_bloque=true feed=%s sort=%s marcado=%s',
                    var_export( $settings['productFeedType'] ?? null, true ),
                    var_export( $settings['sortBy'] ?? null, true ),
                    $marked ? 'true' : 'false'
                ) );
            }
        }

        if ( ! $marked ) {
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

        // TEMPORAL: diagnóstico — quitar una vez confirmado en un envío real.
        $this->log( 'modify_query: override aplicado — posts_per_page=-1' );
    }

    /** TEMPORAL: log de diagnóstico — quitar junto con las llamadas de arriba una vez confirmado el fix. */
    private function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[MAD FK Destacados] ' . $message );
        }
    }
}
