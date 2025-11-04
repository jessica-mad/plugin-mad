/**
 * JavaScript para el Gestor de Roles - MAD Suite
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Inicializar Select2 para búsqueda de usuarios
        initUserSelect();

        // Confirmar eliminación de reglas
        initDeleteConfirmations();

        // Inicializar vista previa de reglas
        initRulePreview();
    });

    /**
     * Inicializa Select2 para búsqueda AJAX de usuarios
     */
    function initUserSelect() {
        var $userSelector = $('#user-selector');

        if ($userSelector.length === 0) {
            return;
        }

        // Verificar si Select2 está disponible
        if (typeof $.fn.select2 === 'undefined') {
            console.warn('Select2 no está disponible. Intentando cargar desde CDN...');
            loadSelect2FromCDN(function() {
                initializeSelect2($userSelector);
            });
        } else {
            initializeSelect2($userSelector);
        }
    }

    /**
     * Inicializa el plugin Select2 en el selector
     */
    function initializeSelect2($element) {
        $element.select2({
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'mads_role_creator_search_users',
                        q: params.term,
                        page: params.page || 1
                    };
                },
                processResults: function(data) {
                    return {
                        results: data
                    };
                },
                cache: true
            },
            minimumInputLength: 2,
            placeholder: madsRoleCreatorL10n.searchPlaceholder || 'Buscar usuarios...',
            language: {
                inputTooShort: function() {
                    return madsRoleCreatorL10n.inputTooShort || 'Escribe al menos 2 caracteres para buscar';
                },
                searching: function() {
                    return madsRoleCreatorL10n.searching || 'Buscando...';
                },
                noResults: function() {
                    return madsRoleCreatorL10n.noResults || 'No se encontraron usuarios';
                },
                errorLoading: function() {
                    return madsRoleCreatorL10n.errorLoading || 'No se pudieron cargar los resultados';
                }
            },
            width: '100%'
        });
    }

    /**
     * Carga Select2 desde CDN si no está disponible
     */
    function loadSelect2FromCDN(callback) {
        var cssUrl = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
        var jsUrl = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';

        // Cargar CSS
        $('<link>')
            .attr('rel', 'stylesheet')
            .attr('href', cssUrl)
            .appendTo('head');

        // Cargar JS
        $.getScript(jsUrl, function() {
            console.log('Select2 cargado desde CDN');
            if (typeof callback === 'function') {
                callback();
            }
        }).fail(function() {
            console.error('No se pudo cargar Select2 desde CDN');
            alert('Error: No se pudo cargar el componente de búsqueda de usuarios.');
        });
    }

    /**
     * Inicializa confirmaciones para eliminación de elementos
     */
    function initDeleteConfirmations() {
        $('.mad-role-creator').on('click', '.button-link-delete', function(e) {
            var confirmMessage = $(this).data('confirm') || '¿Estás seguro de eliminar este elemento?';
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
        });
    }

    /**
     * Muestra un mensaje de carga en un formulario
     */
    function showFormLoading($form, message) {
        message = message || 'Procesando...';
        $form.addClass('loading');
        $form.find('.submit button, .submit input[type="submit"]').prop('disabled', true);
        $form.find('.submit').append('<span class="spinner is-active" style="float: none; margin: 0 10px;"></span>');
    }

    /**
     * Oculta el mensaje de carga en un formulario
     */
    function hideFormLoading($form) {
        $form.removeClass('loading');
        $form.find('.submit button, .submit input[type="submit"]').prop('disabled', false);
        $form.find('.submit .spinner').remove();
    }

    /**
     * Inicializa la funcionalidad de vista previa de reglas
     */
    function initRulePreview() {
        var $previewBtn = $('#preview-rule-btn');
        var $previewContainer = $('#rule-preview-container');
        var $previewContent = $('#rule-preview-content');

        if ($previewBtn.length === 0) {
            return;
        }

        $previewBtn.on('click', function(e) {
            e.preventDefault();

            // Obtener valores del formulario
            var minSpent = parseFloat($('#rule-min-spent').val()) || 0;
            var minOrders = parseInt($('#rule-min-orders').val()) || 0;
            var operator = $('#rule-operator').val() || 'AND';
            var sourceRole = $('#rule-source-role').val() || '';

            // Validar que al menos una condición esté especificada
            if (minSpent <= 0 && minOrders <= 0) {
                alert('Debes especificar al menos una condición (gasto mínimo o cantidad de pedidos).');
                return;
            }

            // Mostrar contenedor de preview con loading
            $previewContainer.show();
            $previewContent.html('<p><span class="spinner is-active" style="float: none; margin-right: 5px;"></span> Analizando usuarios...</p>');

            // Deshabilitar botón mientras carga
            $previewBtn.prop('disabled', true);

            // Hacer llamada AJAX
            $.ajax({
                url: madsRoleCreatorL10n.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_role_creator_preview_rule',
                    nonce: madsRoleCreatorL10n.previewNonce,
                    min_spent: minSpent,
                    min_orders: minOrders,
                    operator: operator,
                    source_role: sourceRole
                },
                success: function(response) {
                    if (response.success) {
                        renderPreviewResults(response.data);
                    } else {
                        $previewContent.html('<p style="color: #dc3232;"><span class="dashicons dashicons-warning"></span> ' + (response.data.message || 'Error al obtener vista previa') + '</p>');
                    }
                },
                error: function() {
                    $previewContent.html('<p style="color: #dc3232;"><span class="dashicons dashicons-warning"></span> Error de conexión. Intenta de nuevo.</p>');
                },
                complete: function() {
                    $previewBtn.prop('disabled', false);
                }
            });
        });
    }

    /**
     * Renderiza los resultados de la vista previa
     */
    function renderPreviewResults(data) {
        var html = '';

        // Resumen
        html += '<div style="background: white; padding: 15px; border-radius: 4px; margin-bottom: 15px;">';
        html += '<h4 style="margin-top: 0; color: #2271b1;">📊 Resumen</h4>';
        html += '<p style="font-size: 16px; margin: 10px 0;"><strong>' + data.formatted.total_text + '</strong></p>';

        if (data.has_filter) {
            html += '<p style="font-size: 14px; margin: 10px 0; color: #666;">' + data.formatted.eligible_text + '</p>';

            if (data.eligible < data.total) {
                var filtered = data.total - data.eligible;
                html += '<p style="font-size: 12px; color: #d63638;"><span class="dashicons dashicons-filter" style="font-size: 14px; vertical-align: middle;"></span> ' + filtered + ' usuarios filtrados por no tener el rol de origen</p>';
            }
        }

        html += '</div>';

        // Usuarios de ejemplo
        if (data.sample_users && data.sample_users.length > 0) {
            html += '<div style="background: white; padding: 15px; border-radius: 4px;">';
            html += '<h4 style="margin-top: 0; color: #2271b1;">👥 Ejemplo de Usuarios Afectados</h4>';
            html += '<table class="widefat" style="font-size: 12px;">';
            html += '<thead><tr>';
            html += '<th>Usuario</th>';
            html += '<th>Email</th>';
            html += '<th>Roles Actuales</th>';
            html += '<th>Pedidos</th>';
            html += '<th>Total Gastado</th>';
            html += '</tr></thead><tbody>';

            $.each(data.sample_users, function(i, user) {
                html += '<tr>';
                html += '<td>' + user.display_name + '</td>';
                html += '<td><small>' + user.email + '</small></td>';
                html += '<td><code style="font-size: 11px;">' + user.roles.join(', ') + '</code></td>';
                html += '<td>' + user.order_count + '</td>';
                html += '<td>$' + user.total_spent.toFixed(2) + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';

            if (data.eligible > data.sample_users.length) {
                html += '<p style="margin-top: 10px; font-size: 12px; color: #666;">Mostrando ' + data.sample_users.length + ' de ' + data.eligible + ' usuarios</p>';
            }

            html += '</div>';
        } else if (data.eligible === 0) {
            html += '<div style="background: #fff8e5; padding: 15px; border-radius: 4px; border-left: 4px solid #ffa500;">';
            html += '<p style="margin: 0;"><span class="dashicons dashicons-info" style="color: #ffa500;"></span> <strong>No hay usuarios que cumplan con estas condiciones.</strong></p>';
            html += '<p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">Considera ajustar las condiciones de la regla.</p>';
            html += '</div>';
        }

        $('#rule-preview-content').html(html);
    }

    // Agregar indicador de carga en formularios al enviar
    $('.mad-role-creator form').on('submit', function() {
        showFormLoading($(this));
    });

})(jQuery);

// Objeto de traducción (se puede sobrescribir desde PHP)
var madsRoleCreatorL10n = madsRoleCreatorL10n || {
    searchPlaceholder: 'Buscar usuarios...',
    inputTooShort: 'Escribe al menos 2 caracteres para buscar',
    searching: 'Buscando...',
    noResults: 'No se encontraron usuarios',
    errorLoading: 'No se pudieron cargar los resultados'
};
