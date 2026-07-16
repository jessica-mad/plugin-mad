<?php
/**
 * Módulo: Divano Toscano — Generador de SKU para WooCommerce
 *
 * Genera y asigna SKUs automáticamente siguiendo el formato:
 *   CATEGORIA-NOMBREPRODUCTO-ATRIBUTO1-ATRIBUTO2
 *
 * - Categoría:      primeras 3 letras del nombre de la categoría.
 * - Nombre:         primeras 3 letras de cada palabra (se ignoran palabras ≤ 2 letras).
 * - Atributo:       primeras 3 letras del tipo + primeras 3 letras del valor.
 *
 * @package MAD_Suite/DiVanoToscano
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** @var MAD_Suite_Core $core */

return new class( $core ) implements MAD_Suite_Module {

    private $core;
    private $slug     = 'divano-toscano';
    private $opt_key  = 'madsuite_divano_toscano';
    private $nonce    = 'mads_divano_save';

    public function __construct( $core ) {
        $this->core = $core;
    }

    /* ================================================================ */
    /*  Interface                                                        */
    /* ================================================================ */

    public function slug()       { return $this->slug; }
    public function title()      { return __( 'Divano Toscano — Generador de SKU', 'mad-suite' ); }
    public function menu_label() { return __( 'SKU Automático', 'mad-suite' ); }
    public function menu_slug()  { return MAD_Suite_Core::MENU_SLUG_ROOT . '-divano-toscano'; }
    public function description() {
        return __( 'Genera y asigna SKUs automáticamente para productos WooCommerce siguiendo el formato Categoría-Nombre-Atributo1-Atributo2.', 'mad-suite' );
    }
    public function required_plugins() {
        return [ 'WooCommerce' => 'woocommerce/woocommerce.php' ];
    }

    /* ================================================================ */
    /*  init()                                                           */
    /* ================================================================ */

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        $settings = $this->get_settings();
        if ( empty( $settings['auto_generate'] ) ) return;

        // Variaciones: se dispara cuando se guarda cada variación.
        add_action( 'woocommerce_save_product_variation', [ $this, 'on_variation_save' ], 20, 2 );

        // Productos simples: se dispara al guardar el meta del producto.
        add_action( 'woocommerce_process_product_meta', [ $this, 'on_simple_product_save' ], 20 );

        // Meta box "Regenerar SKU" en el editor de producto.
        add_action( 'add_meta_boxes', [ $this, 'add_regenerate_meta_box' ] );
        add_action( 'wp_ajax_mad_dt_regenerate_sku', [ $this, 'ajax_regenerate_single' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_meta_box_js' ] );
    }

    /* ================================================================ */
    /*  admin_init()                                                     */
    /* ================================================================ */

    public function admin_init() {
        register_setting( $this->opt_key, $this->opt_key, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'mad_dt_main',
            __( 'Configuración general', 'mad-suite' ),
            '__return_false',
            $this->opt_key
        );

        add_settings_field(
            'auto_generate',
            __( 'Generar SKU automáticamente', 'mad-suite' ),
            [ $this, 'field_auto_generate' ],
            $this->opt_key,
            'mad_dt_main'
        );

        add_settings_field(
            'overwrite',
            __( 'Sobreescribir SKUs existentes', 'mad-suite' ),
            [ $this, 'field_overwrite' ],
            $this->opt_key,
            'mad_dt_main'
        );

        // Acción para regenerar en bulk desde la página de ajustes.
        if (
            isset( $_POST['mad_dt_bulk_action'] ) &&
            check_admin_referer( 'mad_dt_bulk_regenerate', 'mad_dt_bulk_nonce' )
        ) {
            $this->bulk_regenerate();
        }
    }

    /* ================================================================ */
    /*  Hooks de autoguardado                                            */
    /* ================================================================ */

    /**
     * Genera y asigna SKU cuando se guarda una variación.
     *
     * @param int $variation_id
     * @param int $menu_order
     */
    public function on_variation_save( int $variation_id, int $menu_order ): void {
        $variation = wc_get_product( $variation_id );
        if ( ! $variation ) return;

        $settings = $this->get_settings();
        if ( ! $settings['overwrite'] && $variation->get_sku() ) return;

        $sku = $this->generate_variation_sku( $variation );
        if ( $sku ) {
            $sku = $this->ensure_unique_sku( $sku, $variation_id );
            $variation->set_sku( $sku );
            $variation->save();
        }
    }

    /**
     * Genera y asigna SKU cuando se guarda un producto simple.
     *
     * @param int $product_id
     */
    public function on_simple_product_save( int $product_id ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;
        if ( $product->is_type( 'variable' ) ) return; // las variaciones se gestionan por separado

        $settings = $this->get_settings();
        if ( ! $settings['overwrite'] && $product->get_sku() ) return;

        $sku = $this->generate_product_sku( $product );
        if ( $sku ) {
            $sku = $this->ensure_unique_sku( $sku, $product_id );
            // Guardar sin volver a disparar el hook
            remove_action( 'woocommerce_process_product_meta', [ $this, 'on_simple_product_save' ], 20 );
            $product->set_sku( $sku );
            $product->save();
            add_action( 'woocommerce_process_product_meta', [ $this, 'on_simple_product_save' ], 20 );
        }
    }

    /* ================================================================ */
    /*  Meta box: Regenerar SKU                                          */
    /* ================================================================ */

    public function add_regenerate_meta_box(): void {
        add_meta_box(
            'mad_dt_sku_box',
            __( 'SKU Automático (Divano Toscano)', 'mad-suite' ),
            [ $this, 'render_regenerate_meta_box' ],
            'product',
            'side',
            'default'
        );
    }

    public function render_regenerate_meta_box( WP_Post $post ): void {
        $product = wc_get_product( $post->ID );
        if ( ! $product ) return;

        if ( $product->is_type( 'variable' ) ) {
            $preview = __( 'Los SKUs se generan por variación. Usa el botón para regenerar todas.', 'mad-suite' );
        } else {
            $preview = $this->generate_product_sku( $product );
        }
        ?>
        <p style="word-break:break-all;"><strong><?php esc_html_e( 'Vista previa:', 'mad-suite' ); ?></strong><br>
            <code><?php echo esc_html( $preview ?: __( '(vacío — asigna una categoría)', 'mad-suite' ) ); ?></code>
        </p>
        <button type="button" class="button button-secondary" id="mad-dt-regenerate"
                data-product-id="<?php echo esc_attr( $post->ID ); ?>"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'mad_dt_regenerate_' . $post->ID ) ); ?>">
            <?php esc_html_e( 'Regenerar SKU(s) ahora', 'mad-suite' ); ?>
        </button>
        <p class="description" id="mad-dt-regen-msg" style="margin-top:6px;"></p>
        <?php
    }

    public function enqueue_meta_box_js( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
        if ( get_post_type() !== 'product' ) return;

        wp_add_inline_script( 'jquery', "
        jQuery(function($){
            $('#mad-dt-regenerate').on('click', function(){
                var btn = $(this);
                btn.prop('disabled', true);
                $.post(ajaxurl, {
                    action:     'mad_dt_regenerate_sku',
                    product_id: btn.data('product-id'),
                    nonce:      btn.data('nonce')
                }, function(res){
                    $('#mad-dt-regen-msg').text(res.data || res);
                    btn.prop('disabled', false);
                });
            });
        });
        " );
    }

    public function ajax_regenerate_single(): void {
        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( ! $product_id ) wp_send_json_error( __( 'ID inválido.', 'mad-suite' ) );
        if ( ! check_ajax_referer( 'mad_dt_regenerate_' . $product_id, 'nonce', false ) ) {
            wp_send_json_error( __( 'Nonce inválido.', 'mad-suite' ) );
        }
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( __( 'Sin permisos.', 'mad-suite' ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) wp_send_json_error( __( 'Producto no encontrado.', 'mad-suite' ) );

        $count = 0;

        if ( $product->is_type( 'variable' ) ) {
            foreach ( $product->get_children() as $vid ) {
                $v   = wc_get_product( $vid );
                $sku = $this->generate_variation_sku( $v );
                if ( $sku ) {
                    $sku = $this->ensure_unique_sku( $sku, $vid );
                    $v->set_sku( $sku );
                    $v->save();
                    $count++;
                }
            }
            wp_send_json_success( sprintf( __( '%d variación(es) actualizadas.', 'mad-suite' ), $count ) );
        } else {
            $sku = $this->generate_product_sku( $product );
            if ( $sku ) {
                $sku = $this->ensure_unique_sku( $sku, $product_id );
                $product->set_sku( $sku );
                $product->save();
                wp_send_json_success( sprintf( __( 'SKU asignado: %s', 'mad-suite' ), $sku ) );
            }
            wp_send_json_error( __( 'No se pudo generar el SKU (¿tiene categoría?).', 'mad-suite' ) );
        }
    }

    /* ================================================================ */
    /*  Generación de SKU                                                */
    /* ================================================================ */

    /**
     * Genera el SKU para una variación de producto.
     *
     * @param  WC_Product_Variation $variation
     * @return string
     */
    public function generate_variation_sku( WC_Product_Variation $variation ): string {
        $parent = wc_get_product( $variation->get_parent_id() );
        if ( ! $parent ) return '';

        $parts = [];

        $cat = $this->category_code( $parent );
        if ( $cat ) $parts[] = $cat;

        $name = $this->name_code( $parent->get_name() );
        if ( $name ) $parts[] = $name;

        foreach ( $variation->get_attributes() as $key => $value ) {
            if ( ! $value ) continue;
            [ $attr_name, $attr_value ] = $this->resolve_attribute( $parent, $key, $value );
            $code = $this->attr_code( $attr_name, $attr_value );
            if ( $code ) $parts[] = $code;
        }

        return implode( '-', $parts );
    }

    /**
     * Genera el SKU para un producto simple.
     *
     * @param  WC_Product $product
     * @return string
     */
    public function generate_product_sku( WC_Product $product ): string {
        $parts = [];

        $cat = $this->category_code( $product );
        if ( $cat ) $parts[] = $cat;

        $name = $this->name_code( $product->get_name() );
        if ( $name ) $parts[] = $name;

        return implode( '-', $parts );
    }

    /* ================================================================ */
    /*  Helpers de codificación                                          */
    /* ================================================================ */

    /**
     * Normaliza un texto: quita acentos, mayúsculas, solo A-Z0-9, corta a $length.
     *
     * @param  string $str
     * @param  int    $length Máximo de caracteres a devolver.
     * @return string
     */
    private function normalize( string $str, int $length = 3 ): string {
        $str = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $str );
        $str = strtoupper( $str );
        $str = preg_replace( '/[^A-Z0-9]/', '', $str );
        return substr( $str, 0, $length );
    }

    /**
     * Código de categoría: primeras 3 letras de la categoría principal del producto.
     *
     * @param  WC_Product $product
     * @return string
     */
    private function category_code( WC_Product $product ): string {
        $ids = $product->get_category_ids();
        if ( empty( $ids ) ) return '';

        $term = get_term( $ids[0], 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) return '';

        return $this->normalize( $term->name );
    }

    /**
     * Código de nombre: 3 letras por cada palabra > 2 letras, concatenadas.
     *
     * @param  string $name
     * @return string
     */
    private function name_code( string $name ): string {
        $words  = preg_split( '/\s+/', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
        $chunks = [];
        foreach ( $words as $word ) {
            $clean = $this->normalize( $word, 99 );
            if ( strlen( $clean ) > 2 ) {
                $chunks[] = substr( $clean, 0, 3 );
            }
        }
        return implode( '', $chunks );
    }

    /**
     * Código de atributo: 3 letras del tipo + 3 letras del valor.
     *
     * @param  string $attr_name   Nombre legible del atributo (ej. "Color").
     * @param  string $attr_value  Valor legible del atributo (ej. "Beige").
     * @return string              Ej. "COLBEI"
     */
    private function attr_code( string $attr_name, string $attr_value ): string {
        return $this->normalize( $attr_name ) . $this->normalize( $attr_value );
    }

    /**
     * Resuelve el nombre legible del atributo y su valor a partir de la clave interna de WooCommerce.
     *
     * @param  WC_Product $parent
     * @param  string     $key    Clave del atributo (ej. "pa_color" o "acabado").
     * @param  string     $value  Valor del atributo (slug o texto).
     * @return array{0:string, 1:string} [nombre_atributo, valor_atributo]
     */
    private function resolve_attribute( WC_Product $parent, string $key, string $value ): array {
        if ( taxonomy_exists( $key ) ) {
            // Atributo global (taxonomía pa_*)
            $attr_name  = wc_attribute_label( $key, $parent );
            $term       = get_term_by( 'slug', $value, $key );
            $attr_value = $term ? $term->name : $value;
        } else {
            // Atributo personalizado
            $parent_attrs = $parent->get_attributes();
            if ( isset( $parent_attrs[ $key ] ) ) {
                $attr_name = $parent_attrs[ $key ]->get_name();
            } else {
                $attr_name = str_replace( [ 'pa_', '_', '-' ], [ '', ' ', ' ' ], $key );
            }
            $attr_value = $value;
        }
        return [ (string) $attr_name, (string) $attr_value ];
    }

    /**
     * Garantiza que el SKU sea único. Si ya existe en otro producto, añade un sufijo numérico.
     *
     * @param  string $sku
     * @param  int    $exclude_id  ID del producto/variación actual (se excluye de la búsqueda).
     * @return string
     */
    private function ensure_unique_sku( string $sku, int $exclude_id ): string {
        $base    = $sku;
        $counter = 1;

        while ( $this->sku_exists( $sku, $exclude_id ) ) {
            $sku = $base . '-' . $counter;
            $counter++;
        }

        return $sku;
    }

    /**
     * Comprueba si un SKU ya existe en la base de datos (para otro producto).
     *
     * @param  string $sku
     * @param  int    $exclude_id
     * @return bool
     */
    private function sku_exists( string $sku, int $exclude_id ): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_sku' AND meta_value = %s AND post_id != %d
             LIMIT 1",
            $sku,
            $exclude_id
        ) );
        return (bool) $found;
    }

    /* ================================================================ */
    /*  Bulk regeneration                                                */
    /* ================================================================ */

    /**
     * Regenera SKUs para todos los productos publicados.
     * Llamado desde admin_init() cuando se envía el formulario bulk.
     */
    private function bulk_regenerate(): void {
        $settings  = $this->get_settings();
        $overwrite = ! empty( $_POST['bulk_overwrite'] );

        $product_ids = wc_get_products( [
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        ] );

        $count = 0;

        foreach ( $product_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;

            if ( $product->is_type( 'variable' ) ) {
                foreach ( $product->get_children() as $vid ) {
                    $v = wc_get_product( $vid );
                    if ( ! $v ) continue;
                    if ( ! $overwrite && $v->get_sku() ) continue;
                    $sku = $this->generate_variation_sku( $v );
                    if ( $sku ) {
                        $sku = $this->ensure_unique_sku( $sku, $vid );
                        $v->set_sku( $sku );
                        $v->save();
                        $count++;
                    }
                }
            } else {
                if ( ! $overwrite && $product->get_sku() ) continue;
                $sku = $this->generate_product_sku( $product );
                if ( $sku ) {
                    $sku = $this->ensure_unique_sku( $sku, $pid );
                    $product->set_sku( $sku );
                    $product->save();
                    $count++;
                }
            }
        }

        // Guardar resultado en transient para mostrarlo en la página.
        set_transient( 'mad_dt_bulk_result', $count, 60 );
        wp_safe_redirect( add_query_arg( 'mad_dt_done', 1, $this->settings_url() ) );
        exit;
    }

    /* ================================================================ */
    /*  Settings page                                                    */
    /* ================================================================ */

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $bulk_result = get_transient( 'mad_dt_bulk_result' );
        delete_transient( 'mad_dt_bulk_result' );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->title() ); ?></h1>

            <?php if ( false !== $bulk_result ) : ?>
                <div class="notice notice-success"><p>
                    <?php printf(
                        esc_html__( 'Regeneración completada: %d SKU(s) actualizados.', 'mad-suite' ),
                        (int) $bulk_result
                    ); ?>
                </p></div>
            <?php endif; ?>

            <!-- Ajustes generales -->
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->opt_key );
                do_settings_sections( $this->opt_key );
                submit_button( __( 'Guardar ajustes', 'mad-suite' ) );
                ?>
            </form>

            <hr>

            <!-- Vista previa del formato -->
            <h2><?php esc_html_e( 'Formato del SKU', 'mad-suite' ); ?></h2>
            <table class="widefat" style="max-width:680px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Segmento', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Regla', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Ejemplo', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Categoría', 'mad-suite' ); ?></strong></td>
                        <td><?php esc_html_e( 'Primeras 3 letras del nombre de la categoría principal', 'mad-suite' ); ?></td>
                        <td><code>Sofás → SOF</code></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Nombre', 'mad-suite' ); ?></strong></td>
                        <td><?php esc_html_e( 'Primeras 3 letras de cada palabra con más de 2 letras, concatenadas', 'mad-suite' ); ?></td>
                        <td><code>Mesa de Cuero → MESCUE</code></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Atributo', 'mad-suite' ); ?></strong></td>
                        <td><?php esc_html_e( '3 letras del tipo de atributo + 3 letras del valor', 'mad-suite' ); ?></td>
                        <td><code>Color=Beige → COLBEI</code></td>
                    </tr>
                    <tr>
                        <td colspan="2"><strong><?php esc_html_e( 'Resultado final (variación):', 'mad-suite' ); ?></strong></td>
                        <td><code>SOF-MESCUE-COLBEI-TAMGRA</code></td>
                    </tr>
                </tbody>
            </table>

            <hr>

            <!-- Regeneración masiva -->
            <h2><?php esc_html_e( 'Regenerar SKUs en masa', 'mad-suite' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Recorre todos los productos publicados y genera (o sobreescribe) sus SKUs. Úsalo con cuidado: puede tardar en catálogos grandes.', 'mad-suite' ); ?>
            </p>
            <form method="post" onsubmit="return confirm('<?php esc_attr_e( '¿Seguro? Esta acción modificará los SKUs de todos los productos.', 'mad-suite' ); ?>')">
                <?php wp_nonce_field( 'mad_dt_bulk_regenerate', 'mad_dt_bulk_nonce' ); ?>
                <label>
                    <input type="checkbox" name="bulk_overwrite" value="1">
                    <?php esc_html_e( 'Sobreescribir SKUs existentes', 'mad-suite' ); ?>
                </label>
                <br><br>
                <input type="hidden" name="mad_dt_bulk_action" value="1">
                <?php submit_button( __( 'Regenerar todos los SKUs', 'mad-suite' ), 'secondary' ); ?>
            </form>
        </div>
        <?php
    }

    /* ================================================================ */
    /*  Settings fields                                                  */
    /* ================================================================ */

    public function field_auto_generate(): void {
        $v = $this->get_settings()['auto_generate'];
        echo '<label><input type="checkbox" name="' . esc_attr( $this->opt_key ) . '[auto_generate]" value="1" ' . checked( 1, $v, false ) . '> ' .
             esc_html__( 'Generar SKU al guardar cada producto o variación', 'mad-suite' ) . '</label>';
    }

    public function field_overwrite(): void {
        $v = $this->get_settings()['overwrite'];
        echo '<label><input type="checkbox" name="' . esc_attr( $this->opt_key ) . '[overwrite]" value="1" ' . checked( 1, $v, false ) . '> ' .
             esc_html__( 'Sobreescribir el SKU aunque ya exista uno asignado', 'mad-suite' ) . '</label>';
    }

    /* ================================================================ */
    /*  Helpers                                                          */
    /* ================================================================ */

    public function sanitize_settings( $input ): array {
        return [
            'auto_generate' => ! empty( $input['auto_generate'] ) ? 1 : 0,
            'overwrite'     => ! empty( $input['overwrite'] ) ? 1 : 0,
        ];
    }

    private function get_settings(): array {
        $defaults = [ 'auto_generate' => 1, 'overwrite' => 0 ];
        $saved    = get_option( $this->opt_key, [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
    }

    private function settings_url(): string {
        return admin_url( 'admin.php?page=' . $this->menu_slug() );
    }
};
