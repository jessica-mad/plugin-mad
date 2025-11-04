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
