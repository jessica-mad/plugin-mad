/**
 * Discounts Tab - JavaScript Mejorado
 * 
 * Gestión interactiva de descuentos con vista previa en tiempo real
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

(function($) {
    'use strict';
    
    let editingDiscountId = null;
    
    $(document).ready(function() {
        
        console.log('✅ Discounts Tab JS cargado');
        
        // ==========================================
        // MODAL: Abrir/Cerrar
        // ==========================================
        
        $('#add-discount-btn, #add-first-discount-btn').on('click', function() {
            openDiscountModal();
        });
        
        $('.mads-ps-modal-close, .cancel-modal').on('click', function() {
            closeDiscountModal();
        });
        
        $(window).on('click', function(e) {
            if ($(e.target).hasClass('mads-ps-modal')) {
                closeDiscountModal();
            }
        });
        
        // ==========================================
        // CAMBIO DE TIPO (Categoría/Etiqueta)
        // ==========================================
        
        $('input[name="type"]').on('change', function() {
            const type = $(this).val();
            
            if (type === 'category') {
                $('#category-row').show();
                $('#tag-row').hide();
                $('#target_category').prop('required', true);
                $('#target_tag').prop('required', false);
            } else {
                $('#category-row').hide();
                $('#tag-row').show();
                $('#target_category').prop('required', false);
                $('#target_tag').prop('required', true);
            }
        });
        
        // ==========================================
        // VISTA PREVIA EN TIEMPO REAL
        // ==========================================
        
        $('#amount, #amount_type').on('input change', function() {
            updatePreview();
        });
        
        function updatePreview() {
            const amount = parseFloat($('#amount').val()) || 0;
            const type = $('#amount_type').val();
            
            if (amount <= 0) {
                $('.preview-section').hide();
                return;
            }
            
            const basePrice = 100;
            let discount = 0;
            let finalPrice = basePrice;
            
            if (type === 'percentage') {
                discount = basePrice * (amount / 100);
                finalPrice = basePrice - discount;
            } else {
                discount = amount;
                finalPrice = Math.max(0, basePrice - amount);
            }
            
            $('.preview-value.discount').text('-' + formatPrice(discount));
            $('.preview-value.final').text(formatPrice(finalPrice));
            $('.preview-section').show();
        }
        
        // ==========================================
        // GUARDAR DESCUENTO
        // ==========================================
        
        $('#discount-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $button = $('#save-discount-btn');
            const originalText = $button.html();
            
            // Validar
            const type = $('input[name="type"]:checked').val();
            const target = type === 'category' ? $('#target_category').val() : $('#target_tag').val();
            const amount = parseFloat($('#amount').val());
            const amount_type = $('#amount_type').val();
            
            if (!target) {
                alert('Por favor selecciona una categoría o etiqueta');
                return;
            }
            
            if (!amount || amount <= 0) {
                alert('Por favor ingresa una cantidad válida');
                return;
            }
            
            // Validar porcentaje
            if (amount_type === 'percentage' && amount > 100) {
                alert('El porcentaje no puede ser mayor a 100%');
                return;
            }
            
            // Obtener roles seleccionados
            const roles = [];
            $('input[name="roles[]"]:checked').each(function() {
                roles.push($(this).val());
            });
            
            // Deshabilitar botón
            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update-alt spin"></span> Guardando...'
            );
            
            // Enviar AJAX
            $.ajax({
                url: madsPrivateStore.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_ps_save_discount',
                    nonce: madsPrivateStore.nonce,
                    discount_id: editingDiscountId !== null ? editingDiscountId : '',
                    type: type,
                    target: target,
                    amount: amount,
                    amount_type: amount_type,
                    roles: roles
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        closeDiscountModal();
                        
                        // Recargar página después de 1 segundo
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotice(response.data.message || 'Error al guardar', 'error');
                    }
                },
                error: function() {
                    showNotice('Error de conexión', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // ==========================================
        // EDITAR DESCUENTO
        // ==========================================
        
        $('.edit-discount-btn').on('click', function() {
            const discountId = $(this).data('discount-id');
            editDiscount(discountId);
        });
        
        function editDiscount(discountId) {
            // Obtener datos del descuento via AJAX o desde data attributes
            $.ajax({
                url: madsPrivateStore.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_ps_get_discount',
                    nonce: madsPrivateStore.nonce,
                    discount_id: discountId
                },
                success: function(response) {
                    if (response.success && response.data.discount) {
                        const discount = response.data.discount;
                        
                        // Llenar formulario
                        editingDiscountId = discountId;
                        $('#modal-title').text('Editar Descuento');
                        $('#discount_id').val(discountId);
                        
                        // Tipo
                        $('input[name="type"][value="' + discount.type + '"]').prop('checked', true).trigger('change');
                        
                        // Target
                        if (discount.type === 'category') {
                            $('#target_category').val(discount.target);
                        } else {
                            $('#target_tag').val(discount.target);
                        }
                        
                        // Cantidad
                        $('#amount').val(discount.amount);
                        $('#amount_type').val(discount.amount_type);
                        
                        // Roles
                        $('input[name="roles[]"]').prop('checked', false);
                        if (discount.roles && discount.roles.length > 0) {
                            discount.roles.forEach(function(role) {
                                $('input[name="roles[]"][value="' + role + '"]').prop('checked', true);
                            });
                        }
                        
                        updatePreview();
                        $('#discount-modal').fadeIn(200);
                    } else {
                        showNotice('No se pudo cargar el descuento', 'error');
                    }
                },
                error: function() {
                    showNotice('Error de conexión', 'error');
                }
            });
        }
        
        // ==========================================
        // ELIMINAR DESCUENTO
        // ==========================================
        
        $('.delete-discount-btn').on('click', function() {
            const discountId = $(this).data('discount-id');
            
            if (!confirm(madsPrivateStore.strings.confirmDelete || '¿Estás seguro?')) {
                return;
            }
            
            const $button = $(this);
            $button.prop('disabled', true);
            
            $.ajax({
                url: madsPrivateStore.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_ps_delete_discount',
                    nonce: madsPrivateStore.nonce,
                    discount_id: discountId
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        
                        // Remover fila
                        $('tr[data-discount-id="' + discountId + '"]').fadeOut(300, function() {
                            $(this).remove();
                            
                            // Si no quedan descuentos, recargar
                            if ($('#discounts-list tr').length === 0) {
                                location.reload();
                            }
                        });
                    } else {
                        showNotice(response.data.message || 'Error al eliminar', 'error');
                        $button.prop('disabled', false);
                    }
                },
                error: function() {
                    showNotice('Error de conexión', 'error');
                    $button.prop('disabled', false);
                }
            });
        });
        
        // ==========================================
        // ELIMINAR TODOS
        // ==========================================
        
        $('#clear-all-discounts-btn').on('click', function() {
            if (!confirm('¿Estás seguro de eliminar TODOS los descuentos? Esta acción no se puede deshacer.')) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.html();
            
            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update-alt spin"></span> Eliminando...'
            );
            
            $.ajax({
                url: madsPrivateStore.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_ps_clear_all_discounts',
                    nonce: madsPrivateStore.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotice(response.data.message || 'Error al eliminar', 'error');
                        $button.prop('disabled', false).html(originalText);
                    }
                },
                error: function() {
                    showNotice('Error de conexión', 'error');
                    $button.prop('disabled', false).html(originalText);
                }
            });
        });
        
        // ==========================================
        // HELPERS
        // ==========================================
        
        function openDiscountModal() {
            editingDiscountId = null;
            $('#discount-form')[0].reset();
            $('#modal-title').text('Nuevo Descuento VIP');
            $('#discount_id').val('');
            $('.preview-section').hide();
            $('input[name="type"][value="category"]').prop('checked', true).trigger('change');
            $('#discount-modal').fadeIn(200);
        }
        
        function closeDiscountModal() {
            $('#discount-modal').fadeOut(200);
        }
        
        function showNotice(message, type) {
            const noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
            const $notice = $('<div>')
                .addClass(`notice ${noticeClass} is-dismissible`)
                .html(`<p>${escapeHtml(message)}</p>`);
            
            $('.wrap').prepend($notice);
            
            // Auto-dismiss después de 5 segundos
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
        
        function formatPrice(amount) {
            return amount.toFixed(2) + ' €';
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
    });
    
})(jQuery);