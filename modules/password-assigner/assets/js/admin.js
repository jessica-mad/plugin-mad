/**
 * Scripts del panel de administración
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Toggle de horario
        const scheduleCheckbox = $('#enable_schedule');
        const scheduleFields = scheduleCheckbox.closest('tr').nextAll('tr').slice(0, 3);

        function toggleScheduleFields() {
            if (scheduleCheckbox.is(':checked')) {
                scheduleFields.show();
            } else {
                scheduleFields.hide();
            }
        }

        // Inicializar
        toggleScheduleFields();

        // Evento de cambio
        scheduleCheckbox.on('change', toggleScheduleFields);

        // Toggle de protección
        const enabledCheckbox = $('#enabled');

        function updateFormState() {
            if (!enabledCheckbox.is(':checked')) {
                // Mostrar advertencia si se desactiva
                const notice = enabledCheckbox.closest('td').find('.mads-disabled-notice');
                if (notice.length === 0) {
                    enabledCheckbox.closest('td').append(
                        '<p class="mads-disabled-notice description" style="color: #d63638; font-weight: 600;">' +
                        '⚠️ La protección está desactivada. El sitio es accesible sin contraseña.' +
                        '</p>'
                    );
                }
            } else {
                enabledCheckbox.closest('td').find('.mads-disabled-notice').remove();
            }
        }

        // Inicializar
        updateFormState();

        // Evento de cambio
        enabledCheckbox.on('change', updateFormState);

        // Validación del formulario antes de enviar
        $('form').on('submit', function(e) {
            const passwordField = $('#password');
            const enabledField = $('#enabled');

            if (enabledField.is(':checked') && passwordField.val().trim() === '') {
                e.preventDefault();
                alert('Por favor, configura una contraseña antes de activar la protección.');
                passwordField.focus();
                return false;
            }
        });

        // Copiar URL de página seleccionada
        $('#redirect_url_page').on('change', function() {
            const pageId = $(this).val();
            if (pageId) {
                // Esta funcionalidad ya está implementada en general.php
                // pero la mantenemos aquí por si necesitamos agregar más lógica
                console.log('Página seleccionada:', pageId);
            }
        });
    });

})(jQuery);
