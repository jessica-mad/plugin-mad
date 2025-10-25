<?php
/**
 * Settings Scripts - JavaScript para el panel de administración
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<script>
jQuery(document).ready(function($) {
    
    'use strict';
    
    // ==========================================
    // HELPER: Mostrar notificación
    // ==========================================
    function showNotice(message, type = 'success') {
        const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        const $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.mads-private-store-settings h1').after($notice);
        
        // Auto dismiss después de 5 segundos
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        // Scroll to top
        $('html, body').animate({ scrollTop: 0 }, 300);
    }
    
    // ==========================================
    // HELPER: Deshabilitar botón durante AJAX
    // ==========================================
    function disableButton($button, text) {
        $button.data('original-text', $button.html());
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt" style="animation: spin 1s linear infinite;"></span> ' + text);
    }
    
    function enableButton($button) {
        $button.prop('disabled', false).html($button.data('original-text'));
    }
    
    // ==========================================
    // TAB GENERAL: Guardar configuración
    // ==========================================
    $('#mads-ps-general-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $button = $form.find('button[type="submit"]');
        
        disableButton($button, '<?php echo esc_js(__('Guardando...', 'mad-suite')); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mads_ps_save_general_settings',
                nonce: madsPrivateStore.nonce,
                role_name: $('[name="role_name"]').val(),
                redirect_after_login: $('[name="redirect_after_login"]').is(':checked') ? 1 : 0,
                show_vip_badge: $('[name="show_vip_badge"]').is(':checked') ? 1 : 0,
                enable_logging: $('[name="enable_logging"]').is(':checked') ? 1 : 0,
                custom_css: $('[name="custom_css"]').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                } else {
                    showNotice(response.data.message || '<?php echo esc_js(__('Error al guardar', 'mad-suite')); ?>', 'error');
                }
            },
            error: function() {
                showNotice('<?php echo esc_js(__('Error de conexión', 'mad-suite')); ?>', 'error');
            },
            complete: function() {
                enableButton($button);
            }
        });
    });
    
    // ==========================================
    // TAB GENERAL: Limpiar descuentos
    // ==========================================
    $('#clear-discounts').on('click', function() {
        if (!confirm('<?php echo esc_js(__('¿Estás seguro? Se eliminarán TODOS los descuentos configurados.', 'mad-suite')); ?>')) {
            return;
        }
        
        const $button = $(this);
        disableButton($button, '<?php echo esc_js(__('Eliminando...', 'mad-suite')); ?>');
        
        $.ajax({
            url: ajaxurl,
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
                    }, 1500);
                } else {
                    showNotice(response.data.message, 'error');
                }
            },
            complete: function() {
                enableButton($button);
            }
        });
    });
    
    // ==========================================
    // TAB GENERAL: Restablecer configuración
    // ==========================================
    $('#reset-settings').on('click', function() {
        if (!confirm('<?php echo esc_js(__('¿Restablecer toda la configuración a valores predeterminados?', 'mad-suite')); ?>')) {
            return;
        }
        
        const $button = $(this);
        disableButton($button, '<?php echo esc_js(__('Restableciendo...', 'mad-suite')); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mads_ps_reset_settings',
                nonce: madsPrivateStore.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data.message, 'error');
                }
            },
            complete: function() {
                enableButton($button);
            }
        });
    });
    
    // ==========================================
    // TAB DESCUENTOS: Cambiar tipo de descuento
    // ==========================================
    $('#discount-type').on('change', function() {
        const type = $(this).val();
        
        if (type === 'category') {
            $('#category-select-wrapper').show();
            $('#tag-select-wrapper').hide();
        } else {
            $('#category-select-wrapper').hide();
            $('#tag-select-wrapper').show();
        }
    });
    
    // ==========================================
    // TAB DESCUENTOS: Abrir modal para agregar
    // ==========================================
    $('#add-discount-btn').on('click', function() {
        $('#modal-title').text('<?php echo esc_js(__('Agregar Descuento', 'mad-suite')); ?>');
        $('#discount-form')[0].reset();
        $('#discount-id').val('');
        $('#discount-type').trigger('change');
        $('#discount-modal').fadeIn(200);
    });
    
    // ==========================================
    // TAB DESCUENTOS: Cerrar modal
    // ==========================================
    $('.mads-ps-modal-close, .cancel-discount').on('click', function() {
        $('#discount-modal').fadeOut(200);
    });
    
    // Cerrar modal al hacer click fuera
    $(window).on('click', function(e) {
        if ($(e.target).is('#discount-modal')) {
            $('#discount-modal').fadeOut(200);
        }
    });
    
    // ==========================================
    // TAB DESCUENTOS: Guardar descuento
    // ==========================================
    $('#discount-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $button = $form.find('button[type="submit"]');
        
        const type = $('#discount-type').val();
        const target = type === 'category' ? $('#target-category').val() : $('#target-tag').val();
        
        if (!target) {
            alert('<?php echo esc_js(__('Debes seleccionar una categoría o etiqueta', 'mad-suite')); ?>');
            return;
        }
        
        disableButton($button, '<?php echo esc_js(__('Guardando...', 'mad-suite')); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mads_ps_save_discount',
                nonce: madsPrivateStore.nonce,
                discount_id: $('#discount-id').val(),
                type: type,
                target: target,
                amount: $('#discount-amount').val(),
                amount_type: $('#discount-amount-type').val()
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    $('#discount-modal').fadeOut(200);
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotice(response.data.message, 'error');
                }
            },
            complete: function() {
                enableButton($button);
            }
        });
    });
    
    // ==========================================
    // TAB DESCUENTOS: Eliminar descuento
    // ==========================================
    $(document).on('click', '.delete-discount', function() {
        if (!confirm('<?php echo esc_js(__('¿Eliminar este descuento?', 'mad-suite')); ?>')) {
            return;
        }
        
        const discountId = $(this).data('discount-id');
        const $row = $(this).closest('tr');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mads_ps_delete_discount',
                nonce: madsPrivateStore.nonce,
                discount_id: discountId
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                        
                        // Si no quedan descuentos, mostrar mensaje vacío
                        if ($('#discounts-list tr').length === 0) {
                            $('#discounts-list').html(
                                '<tr><td colspan="5" style="text-align: center; padding: 40px;">' +
                                '<span class="dashicons dashicons-tag" style="font-size: 48px; color: #ccc;"></span>' +
                                '<p><?php echo esc_js(__('No hay descuentos configurados', 'mad-suite')); ?></p>' +
                                '</td></tr>'
                            );
                        }
                    });
                    showNotice(response.data.message, 'success');
                } else {
                    showNotice(response.data.message, 'error');
                }
            }
        });
    });
    
    // ==========================================
    // TAB LOGS: Descargar log
    // ==========================================
    $('#download-log').on('click', function() {
        window.location.href = ajaxurl + '?action=mads_ps_download_log&nonce=' + madsPrivateStore.nonce;
    });
    
    // ==========================================
    // TAB LOGS: Limpiar log
    // ==========================================
    $('#clear-log').on('click', function() {
        if (!confirm('<?php echo esc_js(__('¿Limpiar el log actual?', 'mad-suite')); ?>')) {
            return;
        }
        
        const $button = $(this);
        disableButton($button, '<?php echo esc_js(__('Limpiando...', 'mad-suite')); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mads_ps_clear_log',
                nonce: madsPrivateStore.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotice(response.data.message, 'error');
                }
            },
            complete: function() {
                enableButton($button);
            }
        });
    });
    
    // ==========================================
    // CSS: Animación de spin para loading
    // ==========================================
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
        `)
        .appendTo('head');
    
});
</script>