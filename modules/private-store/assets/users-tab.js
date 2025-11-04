/**
 * Users Tab - JavaScript
 * 
 * Funcionalidad para gestión avanzada de usuarios VIP
 * - Búsqueda y selector múltiple
 * - Importación CSV
 * - Exportación CSV
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

(function($) {
    'use strict';
    
    // Variables globales
    let searchTimeout = null;
    let selectedUsers = [];
    let selectedFile = null;
    
    $(document).ready(function() {
        
        // Verificar que madsPrivateStore está disponible
        if (typeof madsPrivateStore === 'undefined') {
            console.error('madsPrivateStore no está definido. El módulo no funcionará correctamente.');
            return;
        }
        
        // ==========================================
        // MODAL: Abrir/Cerrar
        // ==========================================
        
        // Abrir modal agregar usuarios
        $('#add-users-btn, #add-first-user-btn').on('click', function() {
            selectedUsers = [];
            $('#selected-users-list').empty();
            $('.selected-users-wrapper').hide();
            $('#user-search-input').val('');
            $('#user-search-results').empty();
            $('#confirm-add-users').prop('disabled', true);
            $('#add-users-modal').fadeIn(200);
        });
        
        // Abrir modal importar CSV
        $('#import-csv-btn').on('click', function() {
            selectedFile = null;
            $('#csv-file-input').val('');
            $('#selected-file-name').hide();
            $('#csv-import-results').hide().empty();
            $('#confirm-import-csv').prop('disabled', true);
            $('#import-csv-modal').fadeIn(200);
        });
        
        // Cerrar modales
        $('.mads-ps-modal-close, .cancel-modal').on('click', function() {
            $('.mads-ps-modal').fadeOut(200);
        });
        
        // Cerrar modal al hacer click fuera
        $(window).on('click', function(e) {
            if ($(e.target).hasClass('mads-ps-modal')) {
                $(e.target).fadeOut(200);
            }
        });
        
        // ==========================================
        // BÚSQUEDA DE USUARIOS
        // ==========================================
        
        $('#user-search-input').on('keyup', function() {
            const search = $(this).val().trim();
            
            // Limpiar timeout anterior
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            // Si hay menos de 2 caracteres, limpiar resultados
            if (search.length < 2) {
                $('#user-search-results').empty();
                return;
            }
            
            // Mostrar loading
            $('.search-loading').show();
            
            // Buscar después de 500ms
            searchTimeout = setTimeout(function() {
                searchUsers(search);
            }, 500);
        });
        
        function searchUsers(search) {
            $.ajax({
                url: madsPrivateStore.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_ps_search_users',
                    nonce: madsPrivateStore.nonce,
                    search: search
                },
                success: function(response) {
                    $('.search-loading').hide();
                    
                    if (response.success && response.data.users) {
                        renderSearchResults(response.data.users);
                    } else {
                        $('#user-search-results').html(
                            '<div style="padding: 20px; text-align: center; color: #666;">' +
                            'No se encontraron usuarios' +
                            '</div>'
                        );
                    }
                },
                error: function() {
                    $('.search-loading').hide();
                    $('#user-search-results').html(
                        '<div style="padding: 20px; text-align: center; color: #dc3232;">' +
                        'Error en la búsqueda' +
                        '</div>'
                    );
                }
            });
        }
        
        function renderSearchResults(users) {
            const $results = $('#user-search-results');
            $results.empty();
            
            if (users.length === 0) {
                $results.html(
                    '<div style="padding: 20px; text-align: center; color: #666;">' +
                    'No se encontraron usuarios' +
                    '</div>'
                );
                return;
            }
            
            users.forEach(function(user) {
                const isSelected = selectedUsers.some(u => u.id === user.id);
                const isVip = user.is_vip;
                
                let statusHtml = '';
                let itemClass = 'user-result-item';
                
                if (isVip) {
                    statusHtml = '<span class="user-result-status is-vip">Ya es VIP</span>';
                    itemClass += ' is-vip';
                } else if (isSelected) {
                    statusHtml = '<span class="user-result-status" style="color: #2196F3;">✓ Seleccionado</span>';
                }
                
                const $item = $('<div>', {
                    class: itemClass,
                    'data-user-id': user.id,
                    'data-username': user.display_name,
                    'data-email': user.email,
                    html: `
                        <div class="user-result-info">
                            <div class="user-result-name">${escapeHtml(user.display_name)}</div>
                            <div class="user-result-email">${escapeHtml(user.email)}</div>
                            ${statusHtml}
                        </div>
                    `
                });
                
                // Solo permitir click si no es VIP
                if (!isVip) {
                    $item.on('click', function() {
                        toggleUserSelection(user);
                    });
                }
                
                $results.append($item);
            });
        }
        
        function toggleUserSelection(user) {
            const index = selectedUsers.findIndex(u => u.id === user.id);
            
            if (index > -1) {
                // Remover
                selectedUsers.splice(index, 1);
            } else {
                // Agregar
                selectedUsers.push(user);
            }
            
            updateSelectedUsers();
        }
        
        function updateSelectedUsers() {
            const $list = $('#selected-users-list');
            $list.empty();
            
            if (selectedUsers.length === 0) {
                $('.selected-users-wrapper').hide();
                $('#confirm-add-users').prop('disabled', true);
                return;
            }
            
            $('.selected-users-wrapper').show();
            $('#confirm-add-users').prop('disabled', false);
            
            selectedUsers.forEach(function(user) {
                const $chip = $('<div>', {
                    class: 'selected-user-chip',
                    html: `
                        ${escapeHtml(user.display_name)}
                        <span class="remove-chip" data-user-id="${user.id}">✕</span>
                    `
                });
                
                $list.append($chip);
            });
            
            // Handler para remover chips
            $('.remove-chip').on('click', function() {
                const userId = parseInt($(this).data('user-id'));
                const index = selectedUsers.findIndex(u => u.id === userId);
                if (index > -1) {
                    selectedUsers.splice(index, 1);
                    updateSelectedUsers();
                    // Actualizar resultados de búsqueda
                    const search = $('#user-search-input').val().trim();
                    if (search.length >= 2) {
                        searchUsers(search);
                    }
                }
            });
        }
        
        // ==========================================
        // CONFIRMAR AGREGAR USUARIOS
        // ==========================================
        
        $('#confirm-add-users').on('click', function() {
            if (selectedUsers.length === 0) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.html();
            
            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update-alt spin"></span> Agregando...'
            );
            
            const userIds = selectedUsers.map(u => u.id);
            
            $.ajax({
                url: madsPrivateStore.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_ps_add_vip_users_bulk',
                    nonce: madsPrivateStore.nonce,
                    user_ids: userIds
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        $('#add-users-modal').fadeOut(200);
                        
                        // Mostrar detalles si hay
                        if (response.data.results && response.data.results.details) {
                            showResultsDetails(response.data.results.details);
                        }
                        
                        // Recargar página después de 2 segundos
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        showNotice(response.data.message || 'Error al agregar usuarios', 'error');
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
        // CSV: SELECCIONAR ARCHIVO
        // ==========================================
        
        $('#select-csv-file').on('click', function() {
            $('#csv-file-input').click();
        });
        
        $('#csv-file-input').on('change', function(e) {
            const file = e.target.files[0];
            
            if (!file) {
                selectedFile = null;
                $('#selected-file-name').hide();
                $('#confirm-import-csv').prop('disabled', true);
                return;
            }
            
            // Validar extensión
            if (!file.name.endsWith('.csv')) {
                alert('Por favor selecciona un archivo CSV');
                $(this).val('');
                return;
            }
            
            selectedFile = file;
            $('#selected-file-name')
                .html(`<strong>Archivo seleccionado:</strong> ${escapeHtml(file.name)}`)
                .show();
            $('#confirm-import-csv').prop('disabled', false);
        });
        
        // ==========================================
        // CSV: DESCARGAR PLANTILLA
        // ==========================================
        
        $('#download-csv-template').on('click', function(e) {
            e.preventDefault();
            
            const csvContent = 'email\nusuario@ejemplo.com\notro@ejemplo.com\n';
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'plantilla-usuarios-vip.csv');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
        
        // ==========================================
        // CSV: CONFIRMAR IMPORTACIÓN
        // ==========================================
        
        $('#confirm-import-csv').on('click', function() {
            if (!selectedFile) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.html();
            
            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update-alt spin"></span> Importando...'
            );
            
            const formData = new FormData();
            formData.append('action', 'mads_ps_import_users_csv');
            formData.append('nonce', madsPrivateStore.nonce);
            formData.append('csv_file', selectedFile);
            
            $.ajax({
  url: madsPrivateStore.ajaxUrl,   // ✅ usar la URL localizada
  method: 'POST',
  data: {
    action: 'mads_ps_import_users',
    nonce: madsPrivateStore.nonce,
    file: file
  },
  success: function(response) {
    console.log(response);
  }
});

        });
        
        function renderImportResults(results) {
            const $resultsDiv = $('#csv-import-results');
            
            let html = '<h4>Resultados de la importación:</h4>';
            html += '<ul>';
            html += `<li class="import-result-success">✓ Exitosos: ${results.success}</li>`;
            html += `<li class="import-result-error">✗ Errores: ${results.errors}</li>`;
            html += `<li class="import-result-skipped">⊘ Omitidos: ${results.skipped}</li>`;
            html += '</ul>';
            
            if (results.details && results.details.length > 0) {
                html += '<h5>Detalles:</h5>';
                html += '<div style="max-height: 200px; overflow-y: auto; background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
                html += '<ul style="margin: 0; padding-left: 20px;">';
                results.details.forEach(function(detail) {
                    html += `<li style="font-size: 12px; margin: 3px 0;">${escapeHtml(detail)}</li>`;
                });
                html += '</ul>';
                html += '</div>';
            }
            
            $resultsDiv.html(html).show();
        }
        
        // ==========================================
        // EXPORTAR CSV
        // ==========================================
        
        $('#export-csv-btn').on('click', function() {
            const $button = $(this);
            const originalHtml = $button.html();
            
            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update-alt spin"></span> Exportando...'
            );
            
            // Crear form temporal para descarga
            const form = $('<form>', {
                method: 'POST',
                action: madsPrivateStore.ajaxUrl,
                style: 'display: none;'
            });
            
            form.append($('<input>', {
                type: 'hidden',
                name: 'action',
                value: 'mads_ps_export_vip_users'
            }));
            
            form.append($('<input>', {
                type: 'hidden',
                name: 'nonce',
                value: madsPrivateStore.nonce
            }));
            
            $('body').append(form);
            form.submit();
            form.remove();
            
            // Restaurar botón después de 2 segundos
            setTimeout(function() {
                $button.prop('disabled', false).html(originalHtml);
            }, 2000);
        });
        
        // ==========================================
        // REMOVER USUARIO VIP
        // ==========================================
        
        $(document).on('click', '.remove-vip-user', function() {
            const userId = $(this).data('user-id');
            const username = $(this).data('username');
            
            if (!confirm(`¿Quitar acceso VIP a ${username}?`)) {
                return;
            }
            
            // Redirigir usando admin-post.php
            const nonceValue = madsPrivateStore.nonce;
            const adminPostUrl = madsPrivateStore.ajaxUrl.replace('/admin-ajax.php', '/admin-post.php');
            
            window.location.href = adminPostUrl + 
                '?action=mads_toggle_vip_access' +
                '&user_id=' + userId +
                '&toggle=remove' +
                '&_wpnonce=' + nonceValue;
        });
        
        // ==========================================
        // HELPERS
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
        
        function showResultsDetails(details) {
            if (!details || details.length === 0) return;
            
            let html = '<div class="notice notice-info" style="margin-top: 20px;"><h4>Detalles:</h4><ul>';
            details.forEach(function(detail) {
                html += '<li>' + escapeHtml(detail) + '</li>';
            });
            html += '</ul></div>';
            
            $('.mads-private-store-settings h1').after(html);
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
        
    });
    
    // CSS para animación de spin
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            .spin {
                animation: spin 1s linear infinite;
            }
        `)
        .appendTo('head');
    
})(jQuery);