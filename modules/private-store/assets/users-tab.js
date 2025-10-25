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
        
        // Verificar que las variables están disponibles
        if (typeof madsPrivateStoreUsers === 'undefined') {
            console.error('❌ madsPrivateStoreUsers no está definido');
            return;
        }
        
        console.log('✅ Users Tab JS cargado correctamente');
        
        // ==========================================
        // MODAL: Abrir/Cerrar
        // ==========================================
        
        // Abrir modal agregar usuarios
        $('#add-users-btn, #add-first-user-btn').on('click', function() {
            console.log('🔵 Abriendo modal agregar usuarios');
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
            console.log('🔵 Abriendo modal importar CSV');
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
            console.log('🔍 Buscando usuarios:', search);
            
            $.ajax({
                url: madsPrivateStoreUsers.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_ps_search_users',
                    nonce: madsPrivateStoreUsers.nonce,
                    search: search
                },
                success: function(response) {
                    $('.search-loading').hide();
                    
                    if (response.success && response.data.users) {
                        renderUserResults(response.data.users);
                    } else {
                        $('#user-search-results').html(
                            '<p style="padding: 15px; text-align: center; color: #666;">No se encontraron usuarios</p>'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    $('.search-loading').hide();
                    console.error('❌ Error en búsqueda:', error);
                    showNotice('Error al buscar usuarios', 'error');
                }
            });
        }
        
        function renderUserResults(users) {
            const $results = $('#user-search-results');
            $results.empty();
            
            if (users.length === 0) {
                $results.html('<p style="padding: 15px; text-align: center; color: #666;">No se encontraron usuarios</p>');
                return;
            }
            
            users.forEach(function(user) {
                const isSelected = selectedUsers.some(u => u.id === user.id);
                const isVip = user.is_vip;
                
                const $item = $('<div>')
                    .addClass('user-result-item')
                    .toggleClass('selected', isSelected)
                    .toggleClass('is-vip', isVip)
                    .attr('data-user-id', user.id)
                    .html(`
                        <div class="user-result-name">${escapeHtml(user.display_name)}</div>
                        <div class="user-result-email">${escapeHtml(user.email)}</div>
                        ${isVip ? '<div class="user-result-status is-vip">⭐ Ya es VIP</div>' : ''}
                    `);
                
                if (!isVip) {
                    $item.on('click', function() {
                        toggleUserSelection(user);
                        $(this).toggleClass('selected');
                    });
                }
                
                $results.append($item);
            });
        }
        
        // ==========================================
        // GESTIÓN DE USUARIOS SELECCIONADOS
        // ==========================================
        
        function toggleUserSelection(user) {
            const index = selectedUsers.findIndex(u => u.id === user.id);
            
            if (index > -1) {
                selectedUsers.splice(index, 1);
            } else {
                selectedUsers.push(user);
            }
            
            updateSelectedUsers();
        }
        
        function updateSelectedUsers() {
            const $list = $('#selected-users-list');
            const $wrapper = $('.selected-users-wrapper');
            
            if (selectedUsers.length === 0) {
                $wrapper.hide();
                $('#confirm-add-users').prop('disabled', true);
                return;
            }
            
            $wrapper.show();
            $list.empty();
            
            selectedUsers.forEach(function(user) {
                const $chip = $('<div>')
                    .addClass('selected-user-chip')
                    .html(`
                        <span>${escapeHtml(user.display_name)}</span>
                        <span class="remove-chip" data-user-id="${user.id}">×</span>
                    `);
                
                $list.append($chip);
            });
            
            $('#confirm-add-users').prop('disabled', false);
            
            // Handler para remover
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
            
            console.log('📤 Enviando usuarios para agregar:', userIds);
            
            $.ajax({
                url: madsPrivateStoreUsers.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_ps_add_vip_users_bulk',
                    nonce: madsPrivateStoreUsers.nonce,
                    user_ids: userIds
                },
                success: function(response) {
                    console.log('✅ Respuesta agregar usuarios:', response);
                    
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
                error: function(xhr, status, error) {
                    console.error('❌ Error AJAX:', error);
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
            formData.append('nonce', madsPrivateStoreUsers.nonce);
            formData.append('csv_file', selectedFile);
            
            console.log('📤 Enviando CSV para importar');
            
            $.ajax({
                url: madsPrivateStoreUsers.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    console.log('✅ Respuesta importar CSV:', response);
                    
                    if (response.success) {
                        renderImportResults(response.data.results);
                        showNotice('Importación completada', 'success');
                        
                        // Recargar después de 3 segundos
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        showNotice(response.data.message || 'Error al importar', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Error AJAX:', error);
                    showNotice('Error de conexión', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalText);
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
            console.log('📥 Exportando usuarios VIP a CSV');
            
            // Crear un formulario temporal
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = madsPrivateStoreUsers.ajaxUrl;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'mads_ps_export_vip_users';
            form.appendChild(actionInput);
            
            const nonceInput = document.createElement('input');
            nonceInput.type = 'hidden';
            nonceInput.name = 'nonce';
            nonceInput.value = madsPrivateStoreUsers.nonce;
            form.appendChild(nonceInput);
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        });
        
        // ==========================================
        // HELPERS
        // ==========================================
        
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
        
        function showResultsDetails(details) {
            if (!details || details.length === 0) return;
            
            const $notice = $('<div>')
                .addClass('notice notice-info is-dismissible')
                .html(`
                    <p><strong>Detalles:</strong></p>
                    <ul style="margin: 5px 0; padding-left: 20px;">
                        ${details.map(d => `<li>${escapeHtml(d)}</li>`).join('')}
                    </ul>
                `);
            
            $('.wrap').prepend($notice);
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