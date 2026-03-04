/**
 * quote-payment.js — Página de revisión y pago de la cotización.
 *
 * Gestiona:
 * - Cálculo dinámico del total según checkboxes y cantidades seleccionadas
 * - Envío AJAX de la confirmación y redirección al checkout de pago WC
 */
(function ($) {
    'use strict';

    var cfg = window.madQuotePayment || {};

    /* ===== Actualizar total dinámico ===== */

    function updateTotal() {
        var total = 0;

        $('.mad-qp-item').each(function () {
            var $row    = $(this);
            var checked = $row.find('.mad-qp-check').is(':checked');
            if (!checked) return;

            var price = parseFloat($row.data('price')) || 0;
            var qty   = parseInt($row.find('.mad-qp-qty').val(), 10) || 1;
            total += price * qty;
        });

        var formatted = cfg.currencySymbol
            ? cfg.currencySymbol + total.toFixed(2)
            : total.toFixed(2);

        $('#mad-qp-total').text(formatted);
    }

    /* ===== Handlers ===== */

    $(document).ready(function () {
        // Inicializar total
        updateTotal();

        // Checkbox / cantidad cambian → recalcular
        $(document).on('change', '.mad-qp-check', function () {
            var $row = $(this).closest('.mad-qp-item');
            $row.find('.mad-qp-qty').prop('disabled', !this.checked);
            updateTotal();
        });

        $(document).on('input change', '.mad-qp-qty', function () {
            var val = parseInt($(this).val(), 10);
            if (isNaN(val) || val < 1) $(this).val(1);
            updateTotal();
        });

        /* ===== Submit: confirmar selección y redirigir a pago ===== */

        $('#mad-qp-confirm-btn').on('click', function (e) {
            e.preventDefault();

            var $btn     = $(this);
            var selected = [];
            var quantities = {};

            $('.mad-qp-item').each(function () {
                var $row   = $(this);
                var itemId = $row.data('item-id');
                if ($row.find('.mad-qp-check').is(':checked')) {
                    selected.push(itemId);
                    quantities['qty_' + itemId] = parseInt($row.find('.mad-qp-qty').val(), 10) || 1;
                }
            });

            if (selected.length === 0) {
                alert(cfg.strings && cfg.strings.selectOne ? cfg.strings.selectOne : 'Selecciona al menos un producto.');
                return;
            }

            $btn.prop('disabled', true).text('...');

            $.ajax({
                url:    cfg.ajaxurl,
                method: 'POST',
                data:   {
                    action:     'mad_quote_confirm',
                    nonce:      cfg.nonce,
                    order_id:   cfg.order_id,
                    order_key:  cfg.order_key,
                    items:      selected,
                    quantities: quantities,
                },
                success: function (res) {
                    if (res.success && res.data && res.data.redirect) {
                        window.location.href = res.data.redirect;
                    } else {
                        var msg = res.data && res.data.message
                            ? res.data.message
                            : (cfg.strings && cfg.strings.error ? cfg.strings.error : 'Error.');
                        alert(msg);
                        $btn.prop('disabled', false).text($btn.data('original-text') || 'Confirmar y pagar');
                    }
                },
                error: function () {
                    alert(cfg.strings && cfg.strings.error ? cfg.strings.error : 'Error de conexión.');
                    $btn.prop('disabled', false).text($btn.data('original-text') || 'Confirmar y pagar');
                },
            });
        });

        // Guardar texto original del botón
        var $btn = $('#mad-qp-confirm-btn');
        if ($btn.length) {
            $btn.data('original-text', $btn.text());
        }
    });

})(jQuery);
