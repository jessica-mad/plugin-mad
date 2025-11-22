(function($) {
    'use strict';

    const MadpnAdmin = {
        searchTimeout: null,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Búsqueda de productos
            $(document).on('keyup', '#madpn_product_search', this.handleSearch.bind(this));

            // Seleccionar producto
            $(document).on('click', '.madpn-search-result', this.selectProduct.bind(this));

            // Eliminar producto
            $(document).on('click', '.madpn-remove-product', this.removeProduct.bind(this));

            // Limpiar resultados al hacer clic fuera
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.madpn-excluded-products').length) {
                    $('#madpn_search_results').empty().hide();
                }
            });
        },

        handleSearch: function(e) {
            const self = this;
            const $input = $(e.currentTarget);
            const search = $input.val().trim();

            clearTimeout(this.searchTimeout);

            if (search.length < 2) {
                $('#madpn_search_results').empty().hide();
                return;
            }

            this.searchTimeout = setTimeout(function() {
                $.ajax({
                    url: madpnL10n.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'madpn_search_products',
                        nonce: madpnL10n.nonce,
                        search: search
                    },
                    success: function(response) {
                        if (response.success && response.data.products.length > 0) {
                            self.renderSearchResults(response.data.products);
                        } else {
                            $('#madpn_search_results')
                                .html('<p class="no-results">No se encontraron productos.</p>')
                                .show();
                        }
                    }
                });
            }, 300);
        },

        renderSearchResults: function(products) {
            const html = products.map(function(product) {
                // Verificar si ya está excluido
                const isExcluded = $('#madpn_excluded_list').find('[data-product-id="' + product.id + '"]').length > 0;
                if (isExcluded) return '';

                return `
                    <div class="madpn-search-result"
                         data-product-id="${product.id}"
                         data-product-title="${product.title}"
                         data-product-sku="${product.sku || product.id}">
                        <span class="product-title">${product.title}</span>
                        <span class="product-sku">#${product.sku || product.id}</span>
                    </div>
                `;
            }).join('');

            $('#madpn_search_results').html(html).show();
        },

        selectProduct: function(e) {
            const $result = $(e.currentTarget);
            const productId = $result.data('product-id');
            const productTitle = $result.data('product-title');
            const productSku = $result.data('product-sku');

            // Verificar si ya está excluido
            if ($('#madpn_excluded_list').find('[data-product-id="' + productId + '"]').length > 0) {
                return;
            }

            // Agregar a la lista
            const html = `
                <div class="madpn-excluded-item" data-product-id="${productId}">
                    <span class="product-title">${productTitle}</span>
                    <span class="product-sku">#${productSku}</span>
                    <button type="button" class="madpn-remove-product">×</button>
                    <input type="hidden" name="madsuite_product_notes_settings[excluded_products][]" value="${productId}" />
                </div>
            `;

            $('#madpn_excluded_list').append(html);

            // Limpiar búsqueda
            $('#madpn_product_search').val('');
            $('#madpn_search_results').empty().hide();
        },

        removeProduct: function(e) {
            e.preventDefault();
            $(e.currentTarget).closest('.madpn-excluded-item').remove();
        }
    };

    // Inicializar cuando el documento esté listo
    $(document).ready(function() {
        MadpnAdmin.init();
    });

})(jQuery);
