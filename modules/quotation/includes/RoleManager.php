<?php
/**
 * RoleManager — Gestión del rol "professional" y precio profesional por producto.
 *
 * @package MAD_Suite
 * @subpackage Quotation
 */

namespace MADSuite\Modules\Quotation;

if ( ! defined('ABSPATH') ) exit;

class RoleManager {

    const ROLE_SLUG  = 'professional';
    const PRICE_META = '_mad_quotation_professional_price';

    private $logger;

    public function __construct( Logger $logger ) {
        $this->logger = $logger;
    }

    /* ===== Rol ===== */

    /**
     * Crea el rol "professional" si aún no existe.
     * Se llama en init() para asegurar que siempre esté disponible.
     */
    public function create_role_if_not_exists() {
        if ( get_role( self::ROLE_SLUG ) ) return;

        $customer_caps = [
            'read'                   => true,
            'edit_posts'             => false,
            'delete_posts'           => false,
            'upload_files'           => false,
        ];

        add_role(
            self::ROLE_SLUG,
            __('Profesional', 'mad-suite'),
            $customer_caps
        );

        $this->logger->info( 'Rol "professional" creado.' );
    }

    /**
     * Comprueba si el usuario actual (o $user_id) tiene el rol professional.
     */
    public function is_professional( ?int $user_id = null ): bool {
        if ( $user_id ) {
            $user = get_userdata( $user_id );
            if ( ! $user ) return false;
            return in_array( self::ROLE_SLUG, (array) $user->roles, true );
        }

        if ( ! is_user_logged_in() ) return false;
        $current = wp_get_current_user();
        return in_array( self::ROLE_SLUG, (array) $current->roles, true );
    }

    /* ===== Meta precio profesional en productos ===== */

    /**
     * Registra los hooks para añadir el campo "Precio Profesional" en la
     * pantalla de edición de productos de WooCommerce.
     */
    public function register_product_meta_hooks() {
        // Producto simple: pestaña General → sección de precios
        add_action( 'woocommerce_product_options_pricing', [ $this, 'render_simple_price_field' ] );
        add_action( 'woocommerce_process_product_meta',   [ $this, 'save_simple_price_field' ] );

        // Producto variable: campo por variación
        add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_price_field' ], 10, 3 );
        add_action( 'woocommerce_save_product_variation',             [ $this, 'save_variation_price_field' ],   10, 2 );
    }

    public function render_simple_price_field() {
        global $post;
        woocommerce_wp_text_input( [
            'id'          => self::PRICE_META,
            'value'       => get_post_meta( $post->ID, self::PRICE_META, true ),
            'label'       => __('Precio Profesional (€)', 'mad-suite') . ' <span class="woocommerce-help-tip" data-tip="' . esc_attr__('Precio visible únicamente para usuarios con rol Profesional.', 'mad-suite') . '"></span>',
            'placeholder' => __('Ej: 19.99', 'mad-suite'),
            'data_type'   => 'price',
        ] );
    }

    public function save_simple_price_field( int $post_id ) {
        $price = isset( $_POST[ self::PRICE_META ] )
            ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ self::PRICE_META ] ) ) )
            : '';
        update_post_meta( $post_id, self::PRICE_META, $price );
    }

    public function render_variation_price_field( int $loop, array $variation_data, \WP_Post $variation ) {
        woocommerce_wp_text_input( [
            'id'            => self::PRICE_META . '[' . $loop . ']',
            'name'          => self::PRICE_META . '[' . $loop . ']',
            'value'         => get_post_meta( $variation->ID, self::PRICE_META, true ),
            'label'         => __('Precio Profesional (€)', 'mad-suite'),
            'placeholder'   => __('Ej: 19.99', 'mad-suite'),
            'data_type'     => 'price',
            'wrapper_class' => 'form-row form-row-full',
        ] );
    }

    public function save_variation_price_field( int $variation_id, int $loop ) {
        if ( isset( $_POST[ self::PRICE_META ][ $loop ] ) ) {
            $price = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ self::PRICE_META ][ $loop ] ) ) );
            update_post_meta( $variation_id, self::PRICE_META, $price );
        }
    }

    /**
     * Obtiene el precio profesional de un producto (o variación).
     * Retorna cadena vacía si no está definido.
     */
    public function get_professional_price( int $product_id, int $variation_id = 0 ): string {
        $id    = $variation_id ?: $product_id;
        $price = get_post_meta( $id, self::PRICE_META, true );
        return (string) $price;
    }
}
