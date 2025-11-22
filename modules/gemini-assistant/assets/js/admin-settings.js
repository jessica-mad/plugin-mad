/**
 * JavaScript para configuración de MAD Gemini Assistant
 *
 * Maneja el test de conexión API y validaciones de formulario.
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initSettings();
    });

    /**
     * Inicializar configuración
     */
    function initSettings() {
        // Test de conexión
        $('#test-connection-btn').on('click', testConnection);

        // Validar API key al escribir
        $('#api_key').on('input', function() {
            const apiKey = $(this).val();
            const $btn = $('#test-connection-btn');

            if (apiKey.length > 10 && apiKey !== '••••••••••••••••') {
                $btn.prop('disabled', false);
            } else {
                $btn.prop('disabled', true);
            }
        });

        // Limpiar placeholder cuando se enfoca
        $('#api_key').on('focus', function() {
            if ($(this).val() === '••••••••••••••••') {
                $(this).val('');
                $(this).attr('type', 'text');
            }
        });
    }

    /**
     * Test de conexión API
     */
    function testConnection(e) {
        e.preventDefault();

        const $btn = $(this);
        const $result = $('#test-result');
        const apiKey = $('#api_key').val();

        if (!apiKey || apiKey === '••••••••••••••••') {
            $result.html('<span class="error">Por favor ingresa una API Key válida</span>');
            return;
        }

        // Mostrar loading
        $btn.prop('disabled', true).text(madGeminiSettings.strings.testing);
        $result.html('<span class="testing"><span class="spinner is-active" style="float:none;"></span></span>');

        $.ajax({
            url: madGeminiSettings.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_gemini_test_connection',
                nonce: madGeminiSettings.nonce,
                api_key: apiKey
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Probar Conexión');

                if (response.success) {
                    $result.html('<span class="success"><span class="dashicons dashicons-yes"></span> ' +
                        madGeminiSettings.strings.success + ' (' + response.data.model + ')</span>');

                    // Auto-ocultar después de 5 segundos
                    setTimeout(function() {
                        $result.fadeOut();
                    }, 5000);
                } else {
                    $result.html('<span class="error"><span class="dashicons dashicons-no"></span> ' +
                        response.data.message + '</span>');
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).text('Probar Conexión');
                $result.html('<span class="error"><span class="dashicons dashicons-no"></span> ' +
                    madGeminiSettings.strings.error + ': ' + error + '</span>');
            }
        });
    }

})(jQuery);
