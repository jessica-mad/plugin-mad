<?php
/**
 * QuoteCartUX — Modifica la UX del carrito WooCommerce para usuarios en modo
 * cotización: oculta precios, renombra textos de botones.
 *
 * @package MAD_Suite
 * @subpackage Quotation
 */

namespace MADSuite\Modules\Quotation;

if ( ! defined('ABSPATH') ) exit;

class QuoteCartUX {

    private $role_manager;
    private $module;

    public function __construct( RoleManager $role_manager, $module ) {
        $this->role_manager = $role_manager;
        $this->module       = $module;
    }

    public function init() {
        // Ocultar precios dentro del carrito
        add_filter( 'woocommerce_cart_item_price',    [ $this, 'hide_cart_price' ],    20, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'hide_cart_subtotal' ], 20, 3 );

        // Ocultar en mini-carrito
        add_filter( 'woocommerce_widget_cart_item_quantity', [ $this, 'hide_minicart_price' ], 20, 3 );

        // Renombrar textos WC (gettext)
        add_filter( 'gettext', [ $this, 'rename_wc_strings' ], 10, 3 );

        // Ocultar sección de pago en checkout + renombrar botón submit
        add_action( 'woocommerce_review_order_before_payment', [ $this, 'hide_payment_section' ] );
        add_filter( 'woocommerce_order_button_text',            [ $this, 'rename_place_order_btn' ] );

        // Añadir clase CSS al body en modo cotización para facilitar estilos del tema
        add_filter( 'body_class', [ $this, 'add_quote_body_class' ] );
    }

    /* ===== Ocultar precios en carrito ===== */

    public function hide_cart_price( string $price_html, array $cart_item, string $cart_item_key ): string {
        if ( ! $this->role_manager->is_professional() ) {
            return '<span class="mad-hidden-price">—</span>';
        }
        return $price_html;
    }

    public function hide_cart_subtotal( string $subtotal, array $cart_item, string $cart_item_key ): string {
        if ( ! $this->role_manager->is_professional() ) {
            return '<span class="mad-hidden-price">—</span>';
        }
        return $subtotal;
    }

    public function hide_minicart_price( string $product_quantity, array $cart_item, string $cart_item_key ): string {
        if ( ! $this->role_manager->is_professional() ) {
            // Devolver solo la cantidad, sin precio
            $product_quantity = preg_replace( '/<span[^>]*class="[^"]*woocommerce-Price-amount[^"]*"[^>]*>.*?<\/span>/s', '', $product_quantity );
        }
        return $product_quantity;
    }

    /* ===== Renombrar textos WC ===== */

    public function rename_wc_strings( string $translated, string $original, string $domain ): string {
        if ( $domain !== 'woocommerce' || ! is_callable( [ $this, 'is_quote_context' ] ) ) {
            return $translated;
        }
        if ( $this->role_manager->is_professional() ) return $translated;

        $s = $this->module->get_settings();

        $map = [
            'View cart'           => ! empty( $s['text_view_cart'] ) ? $s['text_view_cart'] : __('Ver mi lista de cotización', 'mad-suite'),
            'Proceed to checkout' => ! empty( $s['text_proceed'] )   ? $s['text_proceed']   : __('Finalizar lista de cotización', 'mad-suite'),
            'Cart'                => __('Lista de cotización', 'mad-suite'),
            'Your cart'           => __('Tu lista de cotización', 'mad-suite'),
        ];

        return isset( $map[ $original ] ) ? $map[ $original ] : $translated;
    }

    /* ===== Checkout: ocultar pago ===== */

    public function hide_payment_section() {
        if ( $this->role_manager->is_professional() ) return;
        // Inyectar CSS para ocultar la sección de pago en el checkout
        echo '<style>#payment, .woocommerce-checkout #payment { display:none !important; }</style>';
    }

    public function rename_place_order_btn( string $text ): string {
        if ( $this->role_manager->is_professional() ) return $text;
        $s = $this->module->get_settings();
        return ! empty( $s['text_place_order'] ) ? $s['text_place_order'] : __('Enviar solicitud de cotización', 'mad-suite');
    }

    /* ===== Body class ===== */

    public function add_quote_body_class( array $classes ): array {
        if ( ! $this->role_manager->is_professional() ) {
            $classes[] = 'mad-quote-mode';
        }
        return $classes;
    }
}
