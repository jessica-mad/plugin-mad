(function ($) {
    'use strict';

    var params = window.mad_quotes_new_order_params || {};
    var itemIndex = 0;

    function closeDropdown($container) {
        $container.find('.mad-nq-dropdown').remove();
    }

    function showDropdown($container, items, onPick) {
        closeDropdown($container);
        var $dd = $('<div class="mad-nq-dropdown"></div>');

        if (!items.length) {
            $dd.append('<div class="mad-nq-empty">' + (params.i18n_no_results || 'No results') + '</div>');
        } else {
            items.forEach(function (item) {
                var $row = $('<div></div>').text(item.text);
                $row.on('click', function () {
                    onPick(item);
                    closeDropdown($container);
                });
                $dd.append($row);
            });
        }

        $container.append($dd);
    }

    function search(action, term, cb) {
        $.get(params.ajax_url, {
            action: action,
            nonce: params.nonce,
            q: term
        }, function (res) {
            cb((res && res.results) ? res.results : []);
        });
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(null, args); }, wait);
        };
    }

    $(function () {
        // ── Buscador de cliente existente ──────────────────────────────
        var $customerSearch  = $('#mad_nq_customer_search');
        var $customerResults = $('#mad_nq_customer_results');
        var $customerId      = $('#mad_nq_customer_id');
        var $customerSelected = $('#mad_nq_customer_selected');
        var $customerSelectedText = $('#mad_nq_customer_selected_text');

        var doCustomerSearch = debounce(function (term) {
            if (term.length < 2) { closeDropdown($customerResults); return; }
            search('mad_quotes_search_customers_admin', term, function (results) {
                showDropdown($customerResults, results, function (item) {
                    $customerId.val(item.id);
                    $customerSelectedText.text(item.text);
                    $customerSelected.show();
                    $customerSearch.val('').hide();
                    if (item.email) $('#mad_nq_email').val(item.email);
                    if (item.first_name) $('#mad_nq_first_name').val(item.first_name);
                    if (item.last_name) $('#mad_nq_last_name').val(item.last_name);
                });
            });
        }, 400);

        $customerSearch.on('input', function () {
            doCustomerSearch($(this).val().trim());
        });

        $('#mad_nq_customer_clear').on('click', function (e) {
            e.preventDefault();
            $customerId.val(0);
            $customerSelected.hide();
            $customerSearch.val('').show();
        });

        // ── Buscador de productos ───────────────────────────────────────
        var $productSearch  = $('#mad_nq_product_search');
        var $productResults = $('#mad_nq_product_results');
        var $itemsBody       = $('#mad_nq_items_body');
        var $itemsEmpty       = $('#mad_nq_items_empty');

        var doProductSearch = debounce(function (term) {
            if (term.length < 2) { closeDropdown($productResults); return; }
            search('mad_quotes_search_products_admin', term, function (results) {
                showDropdown($productResults, results, function (item) {
                    addItemRow(item);
                    $productSearch.val('');
                });
            });
        }, 400);

        $productSearch.on('input', function () {
            doProductSearch($(this).val().trim());
        });

        function addItemRow(item) {
            $itemsEmpty.hide();
            var idx = itemIndex++;
            var $row = $('<tr></tr>').attr('data-idx', idx);

            var $nameCell = $('<td></td>').text(item.text);
            $nameCell.append($('<input type="hidden">').attr('name', 'product_id[]').val(item.id));

            var $qtyCell = $('<td></td>').append(
                $('<input type="number" min="1" step="1" value="1">').attr('name', 'product_qty[]')
            );

            var $removeBtn = $('<button type="button" class="button button-small">&times;</button>');
            $removeBtn.on('click', function () {
                $row.remove();
                if (!$itemsBody.find('tr').not($itemsEmpty).length) $itemsEmpty.show();
            });
            var $actionCell = $('<td></td>').append($removeBtn);

            $row.append($nameCell, $qtyCell, $actionCell);
            $itemsBody.append($row);
        }

        // Cierra los desplegables al hacer click afuera.
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.mad-nq-results').length) {
                closeDropdown($customerResults);
                closeDropdown($productResults);
            }
        });

        // Validación mínima antes de enviar (el servidor vuelve a validar igual).
        $('#mad-new-quote-form').on('submit', function (e) {
            if (!$itemsBody.find('input[name="product_id[]"]').length) {
                e.preventDefault();
                alert(params.i18n_no_items_alert || 'Agregá al menos un producto.');
            }
        });
    });
})(jQuery);
