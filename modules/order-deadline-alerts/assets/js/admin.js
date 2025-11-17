(function($) {
    'use strict';

    const MadsOdaAdmin = {
        /**
         * Inicialización
         */
        init: function() {
            this.bindEvents();
            this.initExcludedDates();
        },

        /**
         * Vincular eventos
         */
        bindEvents: function() {
            // Agregar nueva alerta
            $(document).on('click', '#mads-oda-add-alert', this.addNewAlert.bind(this));

            // Editar alerta
            $(document).on('click', '.mads-oda-edit-alert', this.editAlert.bind(this));

            // Cancelar edición
            $(document).on('click', '.mads-oda-cancel-edit-btn', this.cancelEdit.bind(this));

            // Guardar alerta
            $(document).on('click', '.mads-oda-save-alert-btn', this.saveAlert.bind(this));

            // Eliminar alerta
            $(document).on('click', '.mads-oda-delete-alert', this.deleteAlert.bind(this));

            // Activar/Desactivar alerta
            $(document).on('click', '.mads-oda-toggle-alert', this.toggleAlert.bind(this));
        },

        /**
         * Agregar nueva alerta
         */
        addNewAlert: function(e) {
            e.preventDefault();

            const template = $('#mads-oda-alert-template').html();
            const $newAlert = $(template);

            // Generar ID temporal
            const tempId = 'new_' + Date.now();
            $newAlert.attr('data-alert-id', tempId);

            // Mostrar en modo edición
            $newAlert.find('.mads-oda-alert-summary').hide();
            $newAlert.find('.mads-oda-alert-form').show();

            // Eliminar mensaje de "no hay alertas"
            $('.mads-oda-no-alerts').remove();

            // Agregar al listado
            $('#mads-oda-alerts-list').prepend($newAlert);

            // Scroll suave hacia la nueva alerta
            $('html, body').animate({
                scrollTop: $newAlert.offset().top - 100
            }, 500);
        },

        /**
         * Editar alerta existente
         */
        editAlert: function(e) {
            e.preventDefault();
            const $alert = $(e.currentTarget).closest('.mads-oda-alert-item');

            // Ocultar resumen y mostrar formulario
            $alert.find('.mads-oda-alert-summary').hide();
            $alert.find('.mads-oda-alert-form').slideDown();
        },

        /**
         * Cancelar edición
         */
        cancelEdit: function(e) {
            e.preventDefault();
            const $alert = $(e.currentTarget).closest('.mads-oda-alert-item');
            const alertId = $alert.attr('data-alert-id');

            // Si es una nueva alerta (aún no guardada), eliminarla
            if (alertId.startsWith('new_')) {
                $alert.remove();

                // Si no quedan alertas, mostrar mensaje
                if ($('#mads-oda-alerts-list .mads-oda-alert-item').length === 0) {
                    $('#mads-oda-alerts-list').html(
                        '<p class="mads-oda-no-alerts">' +
                        'No hay alertas configuradas. Haz clic en "Agregar Nueva Alerta" para comenzar.' +
                        '</p>'
                    );
                }
            } else {
                // Si es una alerta existente, solo ocultar el formulario
                $alert.find('.mads-oda-alert-form').slideUp();
                $alert.find('.mads-oda-alert-summary').show();
            }
        },

        /**
         * Guardar alerta (crear o actualizar)
         */
        saveAlert: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const $alert = $btn.closest('.mads-oda-alert-item');
            const $form = $alert.find('.mads-oda-alert-form');

            // Recopilar datos
            const data = {
                action: 'mads_oda_save_alert',
                nonce: madsOdaAdminL10n.nonce,
                alert_id: $alert.attr('data-alert-id'),
                name: $form.find('[name="name"]').val(),
                enabled: $alert.hasClass('mads-oda-alert-enabled') || $alert.attr('data-alert-id').startsWith('new_'),
                days: [],
                deadline_time: $form.find('[name="deadline_time"]').val(),
                delivery_day_offset: $form.find('[name="delivery_day_offset"]').val(),
                message_es: $form.find('[name="message_es"]').val(),
                message_en: $form.find('[name="message_en"]').val(),
            };

            // Recopilar días seleccionados
            $form.find('[name="days"]:checked').each(function() {
                data.days.push(parseInt($(this).val()));
            });

            // Validación básica
            if (!data.name || data.days.length === 0 || !data.deadline_time || !data.message_es) {
                alert(madsOdaAdminL10n.strings.requiredFields);
                return;
            }

            // Deshabilitar botón durante la petición
            $btn.prop('disabled', true).text('Guardando...');

            // Enviar petición AJAX
            $.ajax({
                url: madsOdaAdminL10n.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        // Actualizar ID de la alerta si era nueva
                        $alert.attr('data-alert-id', response.data.alert.id);

                        // Actualizar el nombre en el header
                        $alert.find('.mads-oda-alert-name').text(data.name);

                        // Actualizar resumen
                        const daysLabels = {
                            1: 'Lun', 2: 'Mar', 3: 'Mié', 4: 'Jue',
                            5: 'Vie', 6: 'Sáb', 7: 'Dom'
                        };
                        const daysText = data.days.map(d => daysLabels[d]).join(', ');

                        $alert.find('.mads-oda-info-days').text(daysText);
                        $alert.find('.mads-oda-info-time').text(data.deadline_time);
                        $alert.find('.mads-oda-info-message').text(
                            data.message_es.substring(0, 100) + (data.message_es.length > 100 ? '...' : '')
                        );

                        // Asegurar que la alerta esté activa visualmente si es nueva
                        if (!$alert.hasClass('mads-oda-alert-enabled')) {
                            $alert.addClass('mads-oda-alert-enabled');
                            $alert.removeClass('mads-oda-alert-disabled');
                            $alert.find('.mads-oda-alert-status')
                                .removeClass('mads-oda-status-disabled')
                                .addClass('mads-oda-status-enabled')
                                .html('<span class="dashicons dashicons-yes-alt"></span> Activa');
                        }

                        // Ocultar formulario y mostrar resumen
                        $form.slideUp();
                        $alert.find('.mads-oda-alert-summary').show();

                        // Notificación de éxito
                        MadsOdaAdmin.showNotice('success', response.data.message);
                    } else {
                        alert(response.data.message || madsOdaAdminL10n.strings.errorSaving);
                    }
                },
                error: function() {
                    alert(madsOdaAdminL10n.strings.errorSaving);
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Guardar Alerta');
                }
            });
        },

        /**
         * Eliminar alerta
         */
        deleteAlert: function(e) {
            e.preventDefault();

            if (!confirm(madsOdaAdminL10n.strings.confirmDelete)) {
                return;
            }

            const $alert = $(e.currentTarget).closest('.mads-oda-alert-item');
            const alertId = $alert.attr('data-alert-id');

            // Si es una alerta nueva (no guardada), simplemente eliminarla del DOM
            if (alertId.startsWith('new_')) {
                $alert.remove();

                if ($('#mads-oda-alerts-list .mads-oda-alert-item').length === 0) {
                    $('#mads-oda-alerts-list').html(
                        '<p class="mads-oda-no-alerts">' +
                        'No hay alertas configuradas. Haz clic en "Agregar Nueva Alerta" para comenzar.' +
                        '</p>'
                    );
                }
                return;
            }

            // Enviar petición AJAX para eliminar
            $.ajax({
                url: madsOdaAdminL10n.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_oda_delete_alert',
                    nonce: madsOdaAdminL10n.nonce,
                    alert_id: alertId
                },
                success: function(response) {
                    if (response.success) {
                        $alert.fadeOut(300, function() {
                            $(this).remove();

                            if ($('#mads-oda-alerts-list .mads-oda-alert-item').length === 0) {
                                $('#mads-oda-alerts-list').html(
                                    '<p class="mads-oda-no-alerts">' +
                                    'No hay alertas configuradas. Haz clic en "Agregar Nueva Alerta" para comenzar.' +
                                    '</p>'
                                );
                            }
                        });

                        MadsOdaAdmin.showNotice('success', response.data.message);
                    } else {
                        alert(response.data.message || madsOdaAdminL10n.strings.errorDeleting);
                    }
                },
                error: function() {
                    alert(madsOdaAdminL10n.strings.errorDeleting);
                }
            });
        },

        /**
         * Activar/Desactivar alerta
         */
        toggleAlert: function(e) {
            e.preventDefault();

            const $alert = $(e.currentTarget).closest('.mads-oda-alert-item');
            const alertId = $alert.attr('data-alert-id');

            // Si es una alerta nueva, solo cambiar visualmente
            if (alertId.startsWith('new_')) {
                $alert.toggleClass('mads-oda-alert-enabled mads-oda-alert-disabled');
                return;
            }

            // Enviar petición AJAX para alternar
            $.ajax({
                url: madsOdaAdminL10n.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_oda_toggle_alert',
                    nonce: madsOdaAdminL10n.nonce,
                    alert_id: alertId
                },
                success: function(response) {
                    if (response.success) {
                        // Alternar clases y actualizar estado visual
                        const isEnabled = $alert.hasClass('mads-oda-alert-enabled');
                        $alert.toggleClass('mads-oda-alert-enabled mads-oda-alert-disabled');

                        const $status = $alert.find('.mads-oda-alert-status');
                        if (isEnabled) {
                            $status.removeClass('mads-oda-status-enabled')
                                   .addClass('mads-oda-status-disabled')
                                   .html('<span class="dashicons dashicons-dismiss"></span> Inactiva');
                        } else {
                            $status.removeClass('mads-oda-status-disabled')
                                   .addClass('mads-oda-status-enabled')
                                   .html('<span class="dashicons dashicons-yes-alt"></span> Activa');
                        }

                        MadsOdaAdmin.showNotice('success', response.data.message);
                    }
                }
            });
        },

        /**
         * Inicializar gestión de fechas excluidas
         */
        initExcludedDates: function() {
            const self = this;
            let excludedDates = [];

            // Recopilar fechas existentes
            $('#mads-oda-excluded-dates-list .mads-oda-excluded-date-tag').each(function() {
                excludedDates.push($(this).data('date'));
            });

            // Agregar fecha
            $('#mads-oda-add-excluded-date').on('click', function() {
                const date = $('#mads-oda-new-excluded-date').val();

                if (!date) {
                    alert('Por favor, selecciona una fecha.');
                    return;
                }

                if (excludedDates.includes(date)) {
                    alert('Esta fecha ya está en la lista.');
                    return;
                }

                excludedDates.push(date);
                self.renderExcludedDate(date);
                $('#mads-oda-new-excluded-date').val('');
            });

            // Eliminar fecha
            $(document).on('click', '.mads-oda-remove-excluded-date', function(e) {
                e.preventDefault();
                const date = $(this).data('date');
                excludedDates = excludedDates.filter(d => d !== date);
                $(this).closest('.mads-oda-excluded-date-tag').remove();
            });

            // Guardar fechas excluidas
            $('#mads-oda-save-excluded-dates').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true).text('Guardando...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mads_oda_save_settings',
                        nonce: madsOdaAdminL10n.nonce,
                        excluded_dates: excludedDates
                    },
                    success: function(response) {
                        self.showNotice('success', 'Fechas excluidas guardadas correctamente.');
                    },
                    error: function() {
                        alert('Error al guardar las fechas excluidas.');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('Guardar Fechas Excluidas');
                    }
                });
            });
        },

        /**
         * Renderizar fecha excluida
         */
        renderExcludedDate: function(date) {
            const formattedDate = new Date(date + 'T00:00:00').toLocaleDateString('es-ES', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            const html = `
                <span class="mads-oda-excluded-date-tag" data-date="${date}">
                    ${formattedDate}
                    <button type="button" class="mads-oda-remove-excluded-date" data-date="${date}">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </span>
            `;

            $('#mads-oda-excluded-dates-list').append(html);
        },

        /**
         * Mostrar notificación
         */
        showNotice: function(type, message) {
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

            $('.wrap h1').after($notice);

            // Auto-dismiss después de 3 segundos
            setTimeout(function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }
    };

    // Inicializar cuando el documento esté listo
    $(document).ready(function() {
        MadsOdaAdmin.init();
    });

})(jQuery);
