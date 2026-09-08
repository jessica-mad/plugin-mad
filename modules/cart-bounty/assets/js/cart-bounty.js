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

    $(function () {
        var $panel = $('#mad-cart-bounty-panel');
        if (!$panel.length) return;

        if (window.madCartBounty && window.madCartBounty.alreadyCaptured) return;

        $(document).on('click', '[data-mad-cb-close]', function (e) {
            e.preventDefault();
            closePanel($panel);
        });

        // Disparador: WooCommerce completó el add-to-cart por AJAX. Requiere
        // que "Habilitar AJAX en los botones añadir al carrito" esté activo
        // en WooCommerce → Ajustes → Productos (si no, la página navega y
        // este evento nunca llega a dispararse en la ficha).
        $(document.body).on('added_to_cart', function () {
            openPanel($panel);
        });

        // Fallback: si llegamos a la página de Carrito con productos y sin
        // haber capturado el email todavía (por si el evento de arriba no
        // llegó a dispararse por algún motivo).
        if (window.madCartBounty && window.madCartBounty.isCart && window.madCartBounty.cartHasItems) {
            openPanel($panel);
        }

        watchForSuccess($panel);
    });

})(jQuery);
