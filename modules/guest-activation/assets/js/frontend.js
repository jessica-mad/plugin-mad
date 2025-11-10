/**
 * JavaScript Frontend - Guest Activation
 */

(function($) {
    'use strict';

    // Formulario de activación inicial
    $('#mad-activation-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $message = $('#mad-activation-message');
        var $loading = $form.find('.mad-loading');
        var $submitBtn = $form.find('.mad-submit-btn');

        var email = $('#mad-activation-email').val();

        if (!email || !isValidEmail(email)) {
            showMessage($message, 'error', 'Por favor, ingresa un email válido.');
            return;
        }

        // Mostrar loading
        $submitBtn.prop('disabled', true);
        $loading.show();
        $message.hide();

        $.ajax({
            url: madGuestActivation.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_guest_activation_submit',
                nonce: madGuestActivation.activation_nonce,
                email: email
            },
            success: function(response) {
                $loading.hide();
                $submitBtn.prop('disabled', false);

                if (response.success) {
                    if (response.data.found) {
                        showMessage($message, 'success', response.data.message);
                        $form.find('input').val('');
                    } else {
                        showMessage($message, 'info', response.data.message);
                    }
                } else {
                    showMessage($message, 'error', response.data.message || 'Ha ocurrido un error.');
                }
            },
            error: function() {
                $loading.hide();
                $submitBtn.prop('disabled', false);
                showMessage($message, 'error', 'Error de conexión. Por favor, intenta de nuevo.');
            }
        });
    });

    // Formulario de creación de cuenta
    $('#mad-create-account-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $message = $('#mad-create-account-message');
        var $loading = $form.find('.mad-loading');
        var $submitBtn = $form.find('.mad-submit-btn');

        var token = $form.find('input[name="token"]').val();
        var password = $('#mad-password').val();
        var passwordConfirm = $('#mad-password-confirm').val();

        // Validaciones
        if (!password || password.length < 8) {
            showMessage($message, 'error', 'La contraseña debe tener al menos 8 caracteres.');
            return;
        }

        if (password !== passwordConfirm) {
            showMessage($message, 'error', 'Las contraseñas no coinciden.');
            return;
        }

        // Mostrar loading
        $submitBtn.prop('disabled', true);
        $loading.show();
        $message.hide();

        $.ajax({
            url: madGuestActivation.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_guest_activation_create_account',
                nonce: madGuestActivation.create_nonce,
                token: token,
                password: password
            },
            success: function(response) {
                $loading.hide();

                if (response.success) {
                    showMessage($message, 'success', response.data.message);

                    // Redirigir después de 2 segundos
                    if (response.data.redirect_url) {
                        setTimeout(function() {
                            window.location.href = response.data.redirect_url;
                        }, 2000);
                    }
                } else {
                    $submitBtn.prop('disabled', false);
                    showMessage($message, 'error', response.data.message || 'Ha ocurrido un error.');
                }
            },
            error: function() {
                $loading.hide();
                $submitBtn.prop('disabled', false);
                showMessage($message, 'error', 'Error de conexión. Por favor, intenta de nuevo.');
            }
        });
    });

    // Botón "Buscar pedidos previos"
    $('#mad-find-orders-btn').on('click', function() {
        var $btn = $(this);
        var $message = $('#mad-find-orders-message');

        $btn.prop('disabled', true).text('Buscando...');
        $message.removeClass('success info error').hide();

        $.ajax({
            url: madGuestActivation.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_find_previous_orders',
                nonce: madGuestActivation.find_orders_nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).text($btn.data('original-text') || 'Buscar pedidos previos');

                if (response.success) {
                    if (response.data.found) {
                        $message.addClass('success').html(response.data.message).show();

                        // Recargar página después de 2 segundos para mostrar los pedidos
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $message.addClass('info').html(response.data.message).show();
                    }
                } else {
                    $message.addClass('error').html(response.data.message || 'Ha ocurrido un error.').show();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text($btn.data('original-text') || 'Buscar pedidos previos');
                $message.addClass('error').html('Error de conexión. Por favor, intenta de nuevo.').show();
            }
        });
    });

    // Guardar texto original del botón
    if ($('#mad-find-orders-btn').length) {
        $('#mad-find-orders-btn').data('original-text', $('#mad-find-orders-btn').text());
    }

    // Funciones auxiliares
    function showMessage($el, type, message) {
        $el.removeClass('success error info')
            .addClass(type)
            .html(message)
            .show();
    }

    function isValidEmail(email) {
        var regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }

})(jQuery);
