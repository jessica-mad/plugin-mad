(function ($) {
    'use strict';

    var DISMISS_KEY = 'madCartBountyDismissed';

    function CartBountyModal($modal) {
        this.$modal = $modal;
        this.$dialog = $modal.find('.mad-cb-dialog');
        this.lastFocused = null;
        this.bind();
    }

    CartBountyModal.prototype.bind = function () {
        var self = this;

        $(document).on('click', '[data-mad-cb-close]', function (e) {
            e.preventDefault();
            self.close();
        });

        $(document).on('keydown', function (e) {
            if (!self.isOpen()) return;
            if (e.key === 'Escape' || e.keyCode === 27) {
                self.close();
            } else if (e.key === 'Tab' || e.keyCode === 9) {
                self.trapFocus(e);
            }
        });
    };

    CartBountyModal.prototype.isOpen = function () {
        return this.$modal.hasClass('mad-cb-open');
    };

    CartBountyModal.prototype.wasDismissed = function () {
        try {
            return sessionStorage.getItem(DISMISS_KEY) === '1';
        } catch (e) {
            return false;
        }
    };

    CartBountyModal.prototype.markDismissed = function () {
        try {
            sessionStorage.setItem(DISMISS_KEY, '1');
        } catch (e) { /* almacenamiento no disponible: seguimos igual, solo sin persistencia */ }
    };

    CartBountyModal.prototype.open = function () {
        if (this.isOpen() || this.wasDismissed()) return;
        if (window.madCartBounty && window.madCartBounty.alreadyCaptured) return;

        this.lastFocused = document.activeElement;
        this.$modal.addClass('mad-cb-open').attr('aria-hidden', 'false');

        var $focusable = this.getFocusable();
        if ($focusable.length) {
            $focusable.first().trigger('focus');
        }
    };

    CartBountyModal.prototype.close = function () {
        if (!this.isOpen()) return;
        this.$modal.removeClass('mad-cb-open').attr('aria-hidden', 'true');
        this.markDismissed();
        if (this.lastFocused && typeof this.lastFocused.focus === 'function') {
            this.lastFocused.focus();
        }
    };

    CartBountyModal.prototype.getFocusable = function () {
        return this.$dialog
            .find('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
            .filter(':visible');
    };

    CartBountyModal.prototype.trapFocus = function (e) {
        var $f = this.getFocusable();
        if (!$f.length) return;

        var first = $f.first()[0];
        var last = $f.last()[0];

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    };

    /**
     * Fluent Forms no tiene un evento JS documentado 100% estable entre
     * versiones para "envío exitoso" — en vez de apostar a un nombre de
     * evento, observamos si aparece el mensaje de éxito que Fluent Forms
     * inyecta en el DOM tras un submit AJAX correcto y cerramos el modal.
     * Si tu versión usa otra clase, avisá para ajustar el selector.
     */
    function watchForSuccess(modal, $formWrap) {
        if (!$formWrap.length || typeof MutationObserver === 'undefined') return;

        var successSelector = '.ff-message-success, .ff_success_message, .fluentform-success, [data-success="true"]';

        var observer = new MutationObserver(function () {
            if ($formWrap.find(successSelector).length) {
                modal.markDismissed();
                setTimeout(function () { modal.close(); }, 1500);
                observer.disconnect();
            }
        });

        observer.observe($formWrap[0], { childList: true, subtree: true });
    }

    $(function () {
        var $modal = $('#mad-cart-bounty-modal');
        if (!$modal.length) return;

        var modal = new CartBountyModal($modal);

        if (window.madCartBounty && window.madCartBounty.alreadyCaptured) return;

        // Disparador principal: WooCommerce completó el add-to-cart por AJAX.
        $(document.body).on('added_to_cart', function () {
            modal.open();
        });

        // Refuerzo: clic en el botón, sin preventDefault — si el sitio NO usa
        // AJAX en la ficha, la página va a navegar de todas formas; esto solo
        // intenta mostrar el modal en el instante antes de esa navegación.
        $(document).on('click', '.single_add_to_cart_button', function () {
            modal.open();
        });

        // Fallback real para el caso sin AJAX: si llegamos a la página de
        // Carrito con productos y sin haber capturado el email todavía.
        if (window.madCartBounty && window.madCartBounty.isCart && window.madCartBounty.cartHasItems) {
            modal.open();
        }

        watchForSuccess(modal, $modal.find('.mad-cb-form'));
    });

})(jQuery);
