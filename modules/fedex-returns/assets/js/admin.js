/**
 * JavaScript del módulo FedEx Returns - Admin
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        /**
         * Test de conexión con FedEx
         */
        $('#test-fedex-connection').on('click', function() {
            var $btn = $(this);
            var $result = $('#test-connection-result');
            var originalText = $btn.text();

            $btn.prop('disabled', true).text(madFedExReturns.strings.processing);
            $result.removeClass('success error').empty();

            $.ajax({
                url: madFedExReturns.ajax_url,
                type: 'POST',
                data: {
                    action: 'mad_fedex_test_connection',
                    nonce: madFedExReturns.test_connection_nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.addClass('success')
                               .html('<strong>' + madFedExReturns.strings.success + ':</strong> ' + response.data.message);
                    } else {
                        $result.addClass('error')
                               .html('<strong>' + madFedExReturns.strings.error + ':</strong> ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $result.addClass('error')
                           .html('<strong>' + madFedExReturns.strings.error + ':</strong> ' + error);
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });

        /**
         * Ver contenido de log
         */
        $('.view-log-btn').on('click', function() {
            var $btn = $(this);
            var filePath = $btn.data('file');
            var $viewer = $('#log-viewer');
            var $content = $('#log-content');

            $btn.prop('disabled', true).text('Cargando...');

            // Hacer petición para leer el archivo (necesitarías un endpoint AJAX para esto)
            $.ajax({
                url: madFedExReturns.ajax_url,
                type: 'POST',
                data: {
                    action: 'mad_fedex_read_log',
                    nonce: madFedExReturns.clear_logs_nonce,
                    file_path: filePath
                },
                success: function(response) {
                    if (response.success) {
                        $content.text(response.data.content);
                        $viewer.show();

                        // Scroll al visor
                        $('html, body').animate({
                            scrollTop: $viewer.offset().top - 100
                        }, 500);
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error: ' + error);
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Ver');
                }
            });
        });

        /**
         * Limpiar logs antiguos
         */
        $('#clear-old-logs').on('click', function() {
            if (!confirm('¿Estás seguro de eliminar los logs antiguos (más de 30 días)?')) {
                return;
            }

            var $btn = $(this);
            var originalText = $btn.text();

            $btn.prop('disabled', true).text('Eliminando...');

            $.ajax({
                url: madFedExReturns.ajax_url,
                type: 'POST',
                data: {
                    action: 'mad_fedex_clear_logs',
                    nonce: madFedExReturns.clear_logs_nonce,
                    days: 30
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error: ' + error);
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });

        /**
         * Validación de formulario de configuración
         */
        $('form[action*="admin-post.php"]').on('submit', function(e) {
            var currentTab = $('input[name="current_tab"]').val();

            if (currentTab === 'api') {
                // Validar credenciales de FedEx
                var apiKey = $('#fedex_api_key').val().trim();
                var apiSecret = $('#fedex_api_secret').val().trim();
                var accountNumber = $('#fedex_account_number').val().trim();

                if (!apiKey || !apiSecret || !accountNumber) {
                    e.preventDefault();
                    alert('Por favor completa todos los campos requeridos de la API de FedEx.');
                    return false;
                }
            }

            if (currentTab === 'defaults') {
                // Validar información del remitente
                var senderName = $('#sender_name').val().trim();
                var senderAddress = $('#sender_address_line1').val().trim();
                var senderCity = $('#sender_city').val().trim();
                var senderPostalCode = $('#sender_postal_code').val().trim();
                var senderCountry = $('#sender_country').val().trim();

                if (!senderName || !senderAddress || !senderCity || !senderPostalCode || !senderCountry) {
                    e.preventDefault();
                    alert('Por favor completa toda la información del remitente (tu almacén).');
                    return false;
                }

                // Validar código de país (2 letras)
                if (senderCountry.length !== 2) {
                    e.preventDefault();
                    alert('El código de país debe tener 2 letras (ej: MX, US, CA).');
                    $('#sender_country').focus();
                    return false;
                }
            }
        });

        /**
         * Mostrar/ocultar contraseña de API
         */
        $('#fedex_api_secret').after('<button type="button" class="button button-small" id="toggle-api-secret" style="margin-left: 5px;">Mostrar</button>');

        $('#toggle-api-secret').on('click', function() {
            var $input = $('#fedex_api_secret');
            var $btn = $(this);

            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $btn.text('Ocultar');
            } else {
                $input.attr('type', 'password');
                $btn.text('Mostrar');
            }
        });
    });

})(jQuery);
