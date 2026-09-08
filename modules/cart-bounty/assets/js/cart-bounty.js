(function ($) {
    'use strict';

    var DISMISS_KEY = 'madCartBountyDismissed';

    function wasDismissed() {
        try {
            return sessionStorage.getItem(DISMISS_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function markDismissed() {
        try {
            sessionStorage.setItem(DISMISS_KEY, '1');
        } catch (e) { /* almacenamiento no disponible: seguimos igual, solo sin persistencia */ }
    }

    function openPanel($panel) {
        if ($panel.hasClass('mad-cb-open') || wasDismissed()) return;
        if (window.madCartBounty && window.madCartBounty.alreadyCaptured) return;

        $panel.addClass('mad-cb-open').attr('aria-hidden', 'false');

        var $first = $panel.find('input:not([type="hidden"]), textarea, select').filter(':visible').first();
        if ($first.length) {
            $first.trigger('focus');
        }
    }

    function closePanel($panel) {
        if (!$panel.hasClass('mad-cb-open')) return;
        $panel.removeClass('mad-cb-open').attr('aria-hidden', 'true');
        markDismissed();
    }

    /**
     * Fluent Forms no tiene un evento JS documentado 100% estable entre
     * versiones para "envío exitoso" — en vez de apostar a un nombre de
     * evento, observamos si aparece el mensaje de éxito que Fluent Forms
     * inyecta en el DOM tras un submit AJAX correcto y cerramos el panel.
     * Si tu versión usa otra clase, avisá para ajustar el selector.
     */
    function watchForSuccess($panel) {
        var $formWrap = $panel.find('.mad-cb-form');
        if (!$formWrap.length || typeof MutationObserver === 'undefined') return;

        var successSelector = '.ff-message-success, .ff_success_message, .fluentform-success, [data-success="true"]';

        var observer = new MutationObserver(function () {
            if ($formWrap.find(successSelector).length) {
                markDismissed();
                setTimeout(function () { closePanel($panel); }, 1500);
                observer.disconnect();
            }
        });

        observer.observe($formWrap[0], { childList: true, subtree: true });
    }

    /**
     * Disparador robusto ante temas con AJAX propio: en vez de depender de
     * que la plantilla dispare el evento estándar `added_to_cart` de
     * WooCommerce (algunos temas premium implementan su propio add-to-cart
     * y nunca lo disparan), observamos directamente el bloque de avisos de
     * WooCommerce (".woocommerce-notices-wrapper") — confirmado que SÍ se
     * puebla con el aviso "Se agregó [producto]..." al hacer clic, sin
     * importar qué JS de la plantilla lo haya provocado.
     */
    function watchForAddToCartNotice($panel) {
        if (typeof MutationObserver === 'undefined') return;

        var noticeSelector = '.woocommerce-notices-wrapper .woocommerce-message, .woocommerce-notices-wrapper .woocommerce-info';

        var observer = new MutationObserver(function () {
            if ($(noticeSelector).length) {
                openPanel($panel);
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    /**
     * El panel se imprime en wp_footer (lo único que se dispara siempre,
     * sin importar cómo la plantilla arme la ficha de producto). Acá se
     * reubica junto al formulario real de WooCommerce, que sabemos que
     * existe porque de ahí sale el botón de añadir al carrito que ya
     * funciona. Si no se encuentra ningún ancla razonable, se deja donde
     * está (footer) en vez de no mostrarse nunca.
     */
    function relocatePanel($panel) {
        var $productAnchor = $('form.cart').first();
        if ($productAnchor.length) {
            $panel.insertAfter($productAnchor);
            return;
        }

        var $cartAnchor = $('.woocommerce-cart-form, .cart-collaterals, .wc-block-cart').first();
        if ($cartAnchor.length) {
            $panel.insertBefore($cartAnchor);
        }
    }

    $(function () {
        var $panel = $('#mad-cart-bounty-panel');
        if (!$panel.length) return;

        if (window.madCartBounty && window.madCartBounty.alreadyCaptured) return;

        relocatePanel($panel);

        // El PHP siempre imprime el panel oculto (para evitar el parpadeo
        // en la posición vieja del footer antes de reubicarse). Si el flag
        // de sesión indica que se acaba de agregar un producto, lo abrimos
        // recién ahora, ya en su posición final junto a form.cart.
        if (window.madCartBounty && window.madCartBounty.justAdded) {
            openPanel($panel);
        }

        $(document).on('click', '[data-mad-cb-close]', function (e) {
            e.preventDefault();
            closePanel($panel);
        });

        // Disparador estándar: WooCommerce completó el add-to-cart por AJAX
        // nativo. Requiere que "Habilitar AJAX en los botones añadir al
        // carrito" esté activo en WooCommerce → Ajustes → Productos.
        $(document.body).on('added_to_cart', function () {
            openPanel($panel);
        });

        // Refuerzo: si la plantilla usa su propio AJAX y nunca dispara el
        // evento de arriba, esto igual detecta el aviso de "añadido" apenas
        // aparece en el DOM.
        watchForAddToCartNotice($panel);

        // Fallback: si llegamos a la página de Carrito con productos y sin
        // haber capturado el email todavía (por si nada de lo anterior
        // llegó a dispararse en la ficha).
        if (window.madCartBounty && window.madCartBounty.isCart && window.madCartBounty.cartHasItems) {
            openPanel($panel);
        }

        watchForSuccess($panel);
    });

})(jQuery);
