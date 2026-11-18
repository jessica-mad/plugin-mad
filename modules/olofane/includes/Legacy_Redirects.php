<?php
/**
 * Redirecciones 301 de las fichas de la web antigua (olofane.es) a la web nueva.
 *
 * URLs antiguas que cubre (con o sin /en/ o /fr/ delante):
 *   /producto/{ID}-{slug}   /novedad/{ID}-{slug}   (ES)
 *   /product/{ID}-{slug}                           (EN)
 *   /produit/{ID}-{slug}                           (FR)
 *
 * Resolución, en este orden:
 *   1. ID antiguo → referencia (tabla data/legacy-catalog.php) → producto nuevo por SKU.
 *   2. Slug antiguo (sin el ID, y sin las medidas finales) → producto nuevo con ese slug,
 *      siempre que su SKU no contradiga la referencia antigua.
 *   3. Categoría nueva equivalente a la categoría antigua del producto.
 *   4. Página de tienda.
 * Todo en el idioma de la URL de origen (WPML).
 *
 * Solo actúa sobre peticiones que ya son 404, así que nunca pisa una URL que funciona.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Olofane_Legacy_Redirects {

    private const STATS_OPTION = 'madsuite_olofane_legacy_redirect_stats';
    private const PATTERN      = '#^/(?:(en|fr)/)?(?:producto|novedad|product|produit)/(\d+)-([^/?]*)#i';

    /** @var array<int,array{0:string,1:string}>|null */
    private $catalog = null;

    public function init(): void {
        // Prioridad 1: antes del redirect_canonical de WordPress (prioridad 10),
        // que intenta "adivinar" la URL por el slug y a veces acierta con el producto equivocado.
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
    }

    public function maybe_redirect(): void {
        if ( ! is_404() ) return;

        $path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
        if ( ! $path || ! preg_match( self::PATTERN, $path, $m ) ) return;

        $lang     = $m[1] ? strtolower( $m[1] ) : $this->current_language();
        $old_id   = (int) $m[2];
        $old_slug = trim( sanitize_title( $m[3] ), '-' );

        [ $url, $type ] = $this->resolve( $old_id, $old_slug, $lang );
        if ( ! $url ) return;

        $this->count( $type );
        wp_safe_redirect( $url, 301, 'MAD Suite – Olofane legacy' );
        exit;
    }

    /**
     * @return array{0:string,1:string} [ url, tipo ]
     */
    public function resolve( int $old_id, string $old_slug, string $lang ): array {
        $entry    = $this->catalog()[ $old_id ] ?? null;
        $ref      = $entry ? $entry[0] : '';
        $cat_slug = $entry ? $entry[1] : '';

        // 1. Por referencia → SKU
        $product_id = $ref !== '' ? $this->product_by_sku( $ref ) : 0;
        if ( $product_id ) {
            return [ $this->product_url( $product_id, $lang ), 'sku' ];
        }

        // 2. Por slug
        $product_id = $this->product_by_slug( $old_slug, $ref, $cat_slug );
        if ( $product_id ) {
            return [ $this->product_url( $product_id, $lang ), 'slug' ];
        }

        // 3. Categoría
        if ( $cat_slug !== '' ) {
            $url = $this->category_url( $cat_slug, $lang );
            if ( $url ) return [ $url, 'category' ];
        }

        // 4. Tienda
        $shop_id = (int) wc_get_page_id( 'shop' );
        if ( $shop_id > 0 ) {
            $shop_id = (int) apply_filters( 'wpml_object_id', $shop_id, 'page', true, $lang );
            return [ get_permalink( $shop_id ), 'shop' ];
        }

        return [ home_url( '/' ), 'home' ];
    }

    // ── Búsquedas ─────────────────────────────────────────────────────────────

    private function product_by_sku( string $ref ): int {
        $candidates = array_unique( array_filter( [
            $ref,
            preg_replace( '/\s+/', '', $ref ),
            preg_replace( '/\s*cms?$/i', '', $ref ),
        ] ) );

        foreach ( $candidates as $sku ) {
            $id = (int) wc_get_product_id_by_sku( $sku );
            if ( $id && $this->is_live_product( $id ) ) return $this->original_language_id( $id );
        }
        return 0;
    }

    private function product_by_slug( string $slug, string $ref, string $cat_slug ): int {
        if ( $slug === '' ) return 0;

        $candidates = [ $slug ];
        // La web antigua añadía las medidas al final: "...-cerezo-110x76-cms". La nueva no.
        $short = preg_replace( '/(-\d+(?:-\d+)?(?:x\d+(?:-\d+)?)+(?:-cms?)?)+$/', '', $slug );
        if ( $short && $short !== $slug ) $candidates[] = $short;

        foreach ( $candidates as $candidate ) {
            // Nombres genéricos ("mesa", "espejo-sxix") los comparten muchas piezas distintas:
            // mejor mandar a la categoría que a una pieza equivocada.
            if ( substr_count( $candidate, '-' ) < 3 ) continue;

            $post = get_page_by_path( $candidate, OBJECT, 'product' );
            if ( ! $post || ! $this->is_live_product( $post->ID ) ) continue;

            // Si el producto encontrado tiene otra referencia, es otra pieza con el mismo nombre.
            $sku = (string) get_post_meta( $post->ID, '_sku', true );
            if ( $ref !== '' && $sku !== '' && $this->norm( $sku ) !== $this->norm( $ref ) ) continue;

            // Y tiene que seguir en la misma categoría (o una hija de ella).
            if ( $cat_slug !== '' && ! $this->in_category( (int) $post->ID, $cat_slug ) ) continue;

            return $this->original_language_id( (int) $post->ID );
        }
        return 0;
    }

    private function in_category( int $product_id, string $cat_slug ): bool {
        $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'all' ] );
        if ( is_wp_error( $terms ) || ! $terms ) return false;

        $wanted = $this->category_family( $cat_slug );
        foreach ( $terms as $term ) {
            if ( $this->category_family( $term->slug ) === $wanted ) return true;
            foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
                $ancestor = get_term( $ancestor_id, 'product_cat' );
                if ( $ancestor && ! is_wp_error( $ancestor ) && $this->category_family( $ancestor->slug ) === $wanted ) return true;
            }
        }
        return false;
    }

    /** "mesas-en-almacen" y "mesas" son la misma familia (la pieza solo cambió de sitio). */
    private function category_family( string $slug ): string {
        return preg_replace( '/-(en-)?almacen$/', '', $slug );
    }

    private function is_live_product( int $id ): bool {
        return get_post_type( $id ) === 'product' && get_post_status( $id ) === 'publish';
    }

    // ── URLs en el idioma correcto ────────────────────────────────────────────

    private function product_url( int $product_id, string $lang ): string {
        $translated = (int) apply_filters( 'wpml_object_id', $product_id, 'product', true, $lang );
        return (string) get_permalink( $translated ?: $product_id );
    }

    private function category_url( string $slug, string $lang ): string {
        // Los slugs de la tabla son los de la categoría en español: buscar en el idioma original.
        $previous = $this->current_language();
        do_action( 'wpml_switch_language', $this->default_language() );
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        do_action( 'wpml_switch_language', $previous );
        if ( ! $term ) return '';

        $term_id = (int) apply_filters( 'wpml_object_id', $term->term_id, 'product_cat', true, $lang );
        $link    = get_term_link( $term_id ?: (int) $term->term_id, 'product_cat' );
        return is_wp_error( $link ) ? '' : $link;
    }

    private function original_language_id( int $id ): int {
        return (int) apply_filters( 'wpml_object_id', $id, 'product', true, $this->default_language() ) ?: $id;
    }

    private function current_language(): string {
        return (string) ( apply_filters( 'wpml_current_language', null ) ?: $this->default_language() );
    }

    private function default_language(): string {
        return (string) ( apply_filters( 'wpml_default_language', null ) ?: 'es' );
    }

    // ── Datos y estadísticas ──────────────────────────────────────────────────

    private function catalog(): array {
        if ( $this->catalog === null ) {
            $data          = include dirname( __DIR__ ) . '/data/legacy-catalog.php';
            $this->catalog = is_array( $data ) ? $data : [];
        }
        return $this->catalog;
    }

    private function norm( string $s ): string {
        return strtoupper( preg_replace( '/[^0-9a-z]/i', '', $s ) );
    }

    private function count( string $type ): void {
        $stats = get_option( self::STATS_OPTION, [] );
        if ( ! is_array( $stats ) ) $stats = [];
        $stats[ $type ] = ( $stats[ $type ] ?? 0 ) + 1;
        $stats['last']  = current_time( 'mysql' );
        update_option( self::STATS_OPTION, $stats, false );
    }

    public static function get_stats(): array {
        $stats = get_option( self::STATS_OPTION, [] );
        return is_array( $stats ) ? $stats : [];
    }
}
