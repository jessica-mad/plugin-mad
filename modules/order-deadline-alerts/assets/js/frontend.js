(function($) {
    'use strict';

    const MadsOdaFrontend = {
        container: null,
        countdownInterval: null,
        currentAlert: null,
        countdownFormat: 'hh:mm:ss',

        /**
         * Inicialización
         */
        init: function() {
            this.container = $('#mad-order-deadline-alert-container');

            if (this.container.length === 0) {
                return;
            }

            this.countdownFormat = madsOdaL10n.countdownFormat || 'hh:mm:ss';
            this.checkActiveAlert();

            // Verificar cada minuto si hay una nueva alerta activa
            setInterval(this.checkActiveAlert.bind(this), 60000);
        },

        /**
         * Verificar si hay una alerta activa
         */
        checkActiveAlert: function() {
            const self = this;

            $.ajax({
                url: madsOdaL10n.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mads_oda_get_active_alert'
                },
                success: function(response) {
                    if (response.success && response.data.active) {
                        self.currentAlert = response.data.alert;
                        self.renderAlert();
                        self.startCountdown();
                    } else {
                        self.hideAlert();
                    }
                }
            });
        },

        /**
         * Renderizar alerta
         */
        renderAlert: function() {
            if (!this.currentAlert) {
                return;
            }

            const deadline = new Date(this.currentAlert.deadline);
            const deliveryDate = this.formatDeliveryDate(this.currentAlert.delivery_date);
            const deadlineTime = this.formatTime(deadline);

            // Reemplazar variables en el mensaje
            let message = this.currentAlert.message;
            message = message.replace('{time}', '<strong class="mads-oda-time">' + deadlineTime + '</strong>');
            message = message.replace('{delivery_date}', '<strong class="mads-oda-delivery-date">' + deliveryDate + '</strong>');
            message = message.replace('{countdown}', '<span class="mads-oda-countdown"></span>');

            const html = `
                <div class="mads-oda-alert-box">
                    <div class="mads-oda-alert-content">
                        <div class="mads-oda-alert-message">${message}</div>
                    </div>
                </div>
            `;

            this.container.html(html).show();
        },

        /**
         * Iniciar countdown
         */
        startCountdown: function() {
            // Limpiar interval anterior si existe
            if (this.countdownInterval) {
                clearInterval(this.countdownInterval);
            }

            const self = this;
            const deadline = new Date(this.currentAlert.deadline);

            // Actualizar countdown inmediatamente
            this.updateCountdown(deadline);

            // Actualizar cada segundo
            this.countdownInterval = setInterval(function() {
                self.updateCountdown(deadline);
            }, 1000);
        },

        /**
         * Actualizar countdown
         */
        updateCountdown: function(deadline) {
            const now = new Date();
            const diff = deadline - now;

            // Si ya pasó la hora límite, ocultar alerta
            if (diff <= 0) {
                this.hideAlert();
                return;
            }

            // Calcular horas, minutos y segundos restantes
            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            // Formatear según la configuración
            let countdownText;
            if (this.countdownFormat === 'hh:mm') {
                countdownText = this.pad(hours) + ':' + this.pad(minutes);
            } else {
                countdownText = this.pad(hours) + ':' + this.pad(minutes) + ':' + this.pad(seconds);
            }

            // Actualizar en el DOM
            this.container.find('.mads-oda-countdown').text(countdownText);

            // Añadir clase de urgencia si quedan menos de 30 minutos
            if (diff < 30 * 60 * 1000) {
                this.container.find('.mads-oda-alert-box').addClass('mads-oda-urgent');
            }
        },

        /**
         * Ocultar alerta
         */
        hideAlert: function() {
            if (this.countdownInterval) {
                clearInterval(this.countdownInterval);
                this.countdownInterval = null;
            }

            this.container.fadeOut(300, function() {
                $(this).empty();
            });

            this.currentAlert = null;
        },

        /**
         * Formatear tiempo (HH:MM)
         */
        formatTime: function(date) {
            const hours = this.pad(date.getHours());
            const minutes = this.pad(date.getMinutes());
            return hours + ':' + minutes;
        },

        /**
         * Formatear fecha de entrega
         */
        formatDeliveryDate: function(dateString) {
            const date = new Date(dateString + 'T00:00:00');
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);

            // Normalizar fechas para comparación (ignorar hora)
            const normalizeDate = function(d) {
                return new Date(d.getFullYear(), d.getMonth(), d.getDate());
            };

            const normalizedDate = normalizeDate(date);
            const normalizedToday = normalizeDate(today);
            const normalizedTomorrow = normalizeDate(tomorrow);

            if (normalizedDate.getTime() === normalizedToday.getTime()) {
                return 'hoy';
            } else if (normalizedDate.getTime() === normalizedTomorrow.getTime()) {
                return 'mañana';
            } else {
                // Formatear con nombre del día de la semana
                const options = { weekday: 'long', day: 'numeric', month: 'long' };
                const lang = this.detectLanguage();
                return date.toLocaleDateString(lang, options);
            }
        },

        /**
         * Detectar idioma actual
         */
        detectLanguage: function() {
            // Detectar desde la URL
            const path = window.location.pathname;
            if (path.includes('/en/')) {
                return 'en-US';
            }
            return 'es-ES';
        },

        /**
         * Añadir cero delante si es necesario
         */
        pad: function(num) {
            return num < 10 ? '0' + num : num;
        }
    };

    // Inicializar cuando el documento esté listo
    $(document).ready(function() {
        MadsOdaFrontend.init();
    });

})(jQuery);
