<?php
/**
 * PriceController — Oculta precios para no profesionales y muestra precio
 * profesional para usuarios con rol "professional".
 *
 * @package MAD_Suite
 * @subpackage Quotation
 */

namespace MADSuite\Modules\Quotation;

if ( ! defined('ABSPATH') ) exit;

class PriceController {

    private $role_manager;
    private $module;

    public function __construct( RoleManager $role_manager, $module ) {
        $this->role_manager = $role_manager;
        $this->module       = $module;
    }

    public function init() {
        add_filter( 'woocommerce_get_price_html',      [ $this, 'filter_price_html' ],       10, 2 );
        add_filter( 'woocommerce_available_variation', [ $this, 'filter_variation_data' ],    10, 3 );

        // Cambiar el texto del botón "Añadir al carrito" (loop y single)
        add_filter( 'woocommerce_product_add_to_cart_text',        [ $this, 'filter_add_to_cart_text' ],        10, 1 );
        add_filter( 'woocommerce_product_single_add_to_cart_text', [ $this, 'filter_add_to_cart_single_text' ], 10, 1 );
    }

    /* ===== Helpers ===== */

    /**
     * Retorna true si el usuario NO es profesional → modo cotización.
     */
    public function is_quote_context(): bool {
        return ! $this->role_manager->is_professional();
    }

    /* ===== Filtros de precio ===== */

    public function filter_price_html( string $price_html, \WC_Product $product ): string {
        if ( ! $this->is_quote_context() ) {
            // Usuario profesional: mostrar precio profesional si está definido
            $variation_id = 0;
            if ( $product->is_type('variation') ) {
                $variation_id = $product->get_id();
                $product_id   = $product->get_parent_id();
            } else {
                $product_id = $product->get_id();
            }

            $pro_price = $this->role_manager->get_professional_price( $product_id, $variation_id );
            if ( $pro_price !== '' ) {
                return '<span class="mad-professional-price">' . wc_price( (float) $pro_price ) . '</span>';
            }
            // Fallback: precio normal WC
            return $price_html;
        }

        // Modo cotización: ocultar precio
        $settings    = $this->module->get_settings();
        $hidden_text = ! empty( $settings['text_hidden_price'] )
            ? $settings['text_hidden_price']
            : __('Consulta el precio', 'mad-suite');

        return '<span class="mad-hidden-price">' . esc_html( $hidden_text ) . '</span>';
    }

    /**
     * Inyecta el precio profesional en el JSON de variaciones (usado por el
     * JS nativo de WooCommerce para productos variables).
     */
    public function filter_variation_data( array $data, \WC_Product_Variable $product, \WC_Product_Variation $variation ): array {
        if ( $this->is_quote_context() ) {
            $data['price_html'] = $this->filter_price_html( $data['price_html'] ?? '', $variation );
        } else {
            $pro_price = $this->role_manager->get_professional_price( $variation->get_parent_id(), $variation->get_id() );
            if ( $pro_price !== '' ) {
                $data['price_html']         = '<span class="mad-professional-price">' . wc_price( (float) $pro_price ) . '</span>';
                $data['display_price']      = (float) $pro_price;
                $data['display_regular_price'] = (float) $pro_price;
            }
        }
        return $data;
    }

    /* ===== Botón "Añadir al carrito" ===== */

    public function filter_add_to_cart_text( string $text ): string {
        if ( ! $this->is_quote_context() ) return $text;
        $settings = $this->module->get_settings();
        return ! empty( $settings['text_add_btn'] ) ? $settings['text_add_btn'] : __('Añadir a mi lista de cotización', 'mad-suite');
    }

    public function filter_add_to_cart_single_text( string $text ): string {
        return $this->filter_add_to_cart_text( $text );
    }
}
