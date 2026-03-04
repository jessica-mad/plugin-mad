/**
 * quote-cart.js — UX del carrito WooCommerce en modo cotización.
 *
 * Solo activo para usuarios en modo cotización (no profesionales).
 * Renombra textos del mini-carrito y del carrito que WooCommerce inyecta
 * via fragmentos AJAX (y por eso no pasan por el filtro gettext de PHP).
 */
(function ($) {
    'use strict';

    if (!window.madQuote || madQuote.isQuoteMode !== '1') return;

    var strings = madQuote.strings || {};
    var txtViewCart = strings.viewCart || 'Ver mi lista de cotización';
    var txtProceed  = strings.proceed  || 'Finalizar lista de cotización';

    /**
     * Renombra los textos de los botones del mini-carrito WooCommerce.
     * WC regenera el widget vía AJAX (fragmentos), por lo que hay que
     * volver a aplicar los textos tras cada actualización.
     */
    function renameCartTexts() {
        // Botón "View cart" en el mini-carrito (widget)
        $('.widget_shopping_cart .buttons a.wc-forward, .woocommerce-mini-cart__buttons a.checkout').each(function () {
            var $el = $(this);
            var href = $el.attr('href') || '';
            // "View cart" link
            if (href.indexOf('cart') !== -1 && href.indexOf('checkout') === -1) {
                $el.text(txtViewCart);
            }
            // "Proceed to checkout" link
            if (href.indexOf('checkout') !== -1) {
                $el.text(txtProceed);
            }
        });

        // Botón "Proceed to checkout" en la página del carrito
        $('.woocommerce-cart .wc-proceed-to-checkout a.checkout-button').text(txtProceed);

        // Ocultar totales de precio en el mini-carrito para modo cotización
        $('.widget_shopping_cart .woocommerce-mini-cart__total, .woocommerce-mini-cart__total').hide();
    }

    // Ejecutar al cargar la página
    $(document).ready(function () {
        renameCartTexts();
    });

    // Re-ejecutar cuando WooCommerce actualiza los fragmentos del carrito
    $(document.body).on('wc_fragments_loaded wc_fragments_refreshed', function () {
        renameCartTexts();
    });

    // Re-ejecutar tras añadir al carrito (AJAX add-to-cart)
    $(document.body).on('added_to_cart', function () {
        renameCartTexts();
    });

})(jQuery);
