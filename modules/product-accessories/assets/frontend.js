/**
 * MAD Suite - Product Accessories Frontend
 * Maneja la interactividad de accesorios en el carrito
 */

(function($) {
    'use strict';

    // Botones +/- en página de producto
    $('.mad-product-accessories').on('click', '.quantity button', function(e) {
        e.preventDefault();
        var $input = $(this).siblings('input[type="number"]');
        var currentVal = parseInt($input.val()) || 0;
        var min = parseInt($input.attr('min')) || 0;
        var max = parseInt($input.attr('max')) || 999;
        
        if ($(this).hasClass('plus')) {
            if (currentVal < max) {
                $input.val(currentVal + 1).trigger('change');
            }
        } else if ($(this).hasClass('minus')) {
            if (currentVal > min) {
                $input.val(currentVal - 1).trigger('change');
            }
        }
    });

    // Actualizar accesorios en carrito (si se implementa control inline)
    $(document).on('click', '.mad-update-accessory-qty', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var cartItemKey = $button.data('cart-item-key');
        var action = $button.data('action'); // 'increase' o 'decrease'
        var $quantityDisplay = $button.closest('.accessory-controls').find('.accessory-qty-display');
        var currentQty = parseInt($quantityDisplay.text()) || 0;
        var min = parseInt($button.data('min')) || 0;
        var max = parseInt($button.data('max')) || 999;
        
        var newQty = currentQty;
        if (action === 'increase' && currentQty < max) {
            newQty = currentQty + 1;
        } else if (action === 'decrease' && currentQty > min) {
            newQty = currentQty - 1;
        }
        
        if (newQty !== currentQty) {
            updateAccessoryQuantity(cartItemKey, newQty);
        }
    });

    // Eliminar accesorio individual
    $(document).on('click', '.mad-remove-accessory', function(e) {
        e.preventDefault();
        
        var cartItemKey = $(this).data('cart-item-key');
        
        if (confirm('¿Eliminar este accesorio?')) {
            updateAccessoryQuantity(cartItemKey, 0);
        }
    });

    // Función AJAX para actualizar cantidad
    function updateAccessoryQuantity(cartItemKey, quantity) {
        $.ajax({
            url: madAccessories.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_update_accessory_qty',
                nonce: madAccessories.nonce,
                cart_item_key: cartItemKey,
                quantity: quantity
            },
            beforeSend: function() {
                $('body').addClass('loading');
            },
            success: function(response) {
                if (response.success) {
                    // Recargar carrito de WooCommerce
                    $(document.body).trigger('wc_update_cart');
                    
                    // O recargar página si no funciona el trigger
                    setTimeout(function() {
                        window.location.reload();
                    }, 500);
                }
            },
            error: function() {
                alert('Error al actualizar. Por favor, recarga la página.');
            },
            complete: function() {
                $('body').removeClass('loading');
            }
        });
    }

    // Prevenir que se agrupen accesorios de diferentes productos padre
    // (esto es más a nivel PHP, pero agregamos validación frontend)
    
    // Agregar estilos inline para mejorar visualización
    if ($('.mad-cart-item-accessory').length > 0) {
        var styles = `
            <style>
                .mad-cart-item-accessory {
                    background-color: #f9f9f9;
                }
                .mad-cart-item-accessory td:first-child {
                    padding-left: 30px !important;
                }
                .mad-cart-item-accessory .product-name::before {
                    content: "└─ ";
                    color: #999;
                    margin-right: 5px;
                }
                .mad-product-accessories {
                    background: #f9f9f9;
                    padding: 20px;
                    margin: 20px 0;
                    border-radius: 5px;
                }
                .mad-accessory-item {
                    margin: 15px 0;
                    padding: 15px;
                    background: white;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
                .mad-accessory-item label {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    cursor: pointer;
                }
                .mad-accessory-item img {
                    width: 60px;
                    height: 60px;
                    object-fit: cover;
                    border-radius: 4px;
                }
                .mad-accessory-item .quantity {
                    margin-top: 10px;
                    display: flex;
                    align-items: center;
                    gap: 5px;
                }
                .mad-accessory-item .quantity button {
                    background: #333;
                    color: white;
                    border: none;
                    width: 30px;
                    height: 30px;
                    border-radius: 3px;
                    cursor: pointer;
                    font-size: 16px;
                    line-height: 1;
                }
                .mad-accessory-item .quantity button:hover {
                    background: #555;
                }
                .mad-accessory-item .quantity input {
                    width: 80px;
                    text-align: center;
                    padding: 5px;
                    border: 1px solid #ddd;
                    border-radius: 3px;
                }
            </style>
        `;
        $('head').append(styles);
    }

    // Agregar botones +/- a los campos de cantidad en página de producto
    $('.mad-accessory-item .quantity').each(function() {
        var $quantity = $(this);
        var $input = $quantity.find('input[type="number"]');
        
        // Solo agregar botones si no existen
        if ($quantity.find('button').length === 0) {
            $input.before('<button type="button" class="minus">−</button>');
            $input.after('<button type="button" class="plus">+</button>');
        }
    });

})(jQuery);