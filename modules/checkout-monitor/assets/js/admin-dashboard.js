(function($) {
    'use strict';

    var CheckoutMonitorAdmin = {
        currentPage: 1,
        perPage: 20,
        filters: {},
        autoRefreshInterval: null,
        autoRefreshEnabled: true,
        lastUpdateTime: null,

        init: function() {
            this.setupTabs();
            this.setupFilters();
            this.setupAutoRefresh();
            this.loadSessions();
            this.setupModal();
            this.setupCleanup();
            this.setupSettings();
            this.setupLogsSorting();
            this.setupLogViewer();
        },

        setupTabs: function() {
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();

                var target = $(this).attr('href');

                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');

                $('.tab-panel').removeClass('active');
                $(target).addClass('active');
            });
        },

        setupFilters: function() {
            var self = this;

            $('#session-apply-filters').on('click', function() {
                self.filters = {
                    search: $('#session-search').val(),
                    status: $('#session-status-filter').val(),
                    has_errors: $('#session-errors-filter').val(),
                    date_from: $('#session-date-from').val(),
                    date_to: $('#session-date-to').val(),
                    order_by: $('#session-order-by').val()
                };

                self.currentPage = 1;
                self.loadSessions();
            });

            // También recargar cuando cambia el orden
            $('#session-order-by').on('change', function() {
                $('#session-apply-filters').click();
            });

            // Enter key on search
            $('#session-search').on('keypress', function(e) {
                if (e.which === 13) {
                    $('#session-apply-filters').click();
                }
            });
        },

        setupAutoRefresh: function() {
            var self = this;

            // Botón de refresh manual
            $('#session-refresh-btn').on('click', function() {
                $(this).addClass('spinning');
                self.loadSessions();
                setTimeout(function() {
                    $('#session-refresh-btn').removeClass('spinning');
                }, 1000);
            });

            // Toggle auto-refresh
            $('#session-auto-refresh-toggle').on('change', function() {
                self.autoRefreshEnabled = $(this).is(':checked');
                if (self.autoRefreshEnabled) {
                    self.startAutoRefresh();
                } else {
                    self.stopAutoRefresh();
                }
            });

            // Iniciar auto-refresh si está habilitado
            if (self.autoRefreshEnabled) {
                self.startAutoRefresh();
            }
        },

        startAutoRefresh: function() {
            var self = this;

            // Limpiar intervalo existente
            if (self.autoRefreshInterval) {
                clearInterval(self.autoRefreshInterval);
            }

            // Actualizar cada 10 segundos
            self.autoRefreshInterval = setInterval(function() {
                self.loadSessions(true); // true = silent refresh
            }, 10000);
        },

        stopAutoRefresh: function() {
            if (this.autoRefreshInterval) {
                clearInterval(this.autoRefreshInterval);
                this.autoRefreshInterval = null;
            }
        },

        updateLastRefreshTime: function() {
            var now = new Date();
            var timeString = now.toLocaleTimeString('es-ES');
            $('#last-update-time').text(timeString);
            this.lastUpdateTime = now;
        },

        loadSessions: function(silent) {
            var self = this;

            // Solo mostrar spinner si no es silent refresh
            if (!silent) {
                $('#sessions-tbody').html('<tr><td colspan="11" style="text-align: center;"><span class="spinner is-active" style="float: none;"></span> Cargando sesiones...</td></tr>');
            }

            $.ajax({
                url: checkoutMonitorAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'checkout_monitor_get_sessions',
                    nonce: checkoutMonitorAdmin.nonce,
                    page: self.currentPage,
                    per_page: self.perPage,
                    filters: self.filters
                },
                success: function(response) {
                    if (response.success) {
                        self.renderSessions(response.data);
                        self.updateLastRefreshTime();
                    } else {
                        if (!silent) {
                            self.showError('Error al cargar sesiones: ' + response.data.message);
                        }
                    }
                },
                error: function() {
                    if (!silent) {
                        self.showError('Error de conexión al cargar sesiones');
                    }
                }
            });
        },

        renderSessions: function(data) {
            var self = this;
            var $tbody = $('#sessions-tbody');

            $tbody.empty();

            if (data.sessions.length === 0) {
                $tbody.html('<tr><td colspan="11" style="text-align: center;">No se encontraron sesiones</td></tr>');
                return;
            }

            $.each(data.sessions, function(index, session) {
                var statusClass = self.getStatusClass(session.status);
                var errorBadge = session.has_errors == 1 ? '<span class="error-badge">⚠️ ' + session.error_count + '</span>' : '-';
                var deviceInfo = self.getDeviceInfo(session.browser_data);

                var row = '<tr>' +
                    '<td><code>' + self.escapeHtml(session.session_id.substring(0, 16)) + '...</code></td>' +
                    '<td>' + (session.order_id ? '<a href="post.php?post=' + session.order_id + '&action=edit">#' + session.order_id + '</a>' : '-') + '</td>' +
                    '<td><span class="status-badge ' + statusClass + '">' + session.status + '</span></td>' +
                    '<td>' + self.formatDate(session.started_at) + '</td>' +
                    '<td>' + (session.duration_ms ? session.duration_ms + 'ms' : '-') + '</td>' +
                    '<td>' + (session.payment_method || '-') + '</td>' +
                    '<td>' + (session.total_amount ? '€' + parseFloat(session.total_amount).toFixed(2) : '-') + '</td>' +
                    '<td>' + session.hook_count + '</td>' +
                    '<td>' + errorBadge + '</td>' +
                    '<td>' + deviceInfo + '</td>' +
                    '<td><button class="button button-small view-session" data-session-id="' + session.session_id + '">Ver Detalle</button></td>' +
                    '</tr>';

                $tbody.append(row);
            });

            // Setup view buttons
            $('.view-session').on('click', function() {
                var sessionId = $(this).data('session-id');
                self.loadSessionDetail(sessionId);
            });

            // Update pagination
            this.renderPagination(data);
        },

        renderPagination: function(data) {
            var self = this;

            $('#sessions-count').text('Mostrando ' + data.sessions.length + ' de ' + data.total + ' sesiones');

            var $pagination = $('#sessions-pagination');
            $pagination.empty();

            if (data.total_pages <= 1) return;

            // Previous
            if (data.page > 1) {
                $pagination.append('<a class="button page-btn" data-page="' + (data.page - 1) + '">‹</a> ');
            }

            // Page numbers
            for (var i = 1; i <= data.total_pages; i++) {
                if (i === data.page) {
                    $pagination.append('<span class="current-page">' + i + '</span> ');
                } else if (Math.abs(i - data.page) <= 2 || i === 1 || i === data.total_pages) {
                    $pagination.append('<a class="button page-btn" data-page="' + i + '">' + i + '</a> ');
                } else if (Math.abs(i - data.page) === 3) {
                    $pagination.append('<span>...</span> ');
                }
            }

            // Next
            if (data.page < data.total_pages) {
                $pagination.append('<a class="button page-btn" data-page="' + (data.page + 1) + '">›</a>');
            }

            // Click handlers
            $('.page-btn').on('click', function() {
                self.currentPage = parseInt($(this).data('page'));
                self.loadSessions();
            });
        },

        loadSessionDetail: function(sessionId) {
            var self = this;

            $('#session-detail-content').html('<div style="text-align: center; padding: 50px;"><span class="spinner is-active" style="float: none;"></span> Cargando detalles...</div>');
            $('#session-detail-modal').fadeIn();

            $.ajax({
                url: checkoutMonitorAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'checkout_monitor_get_session_detail',
                    nonce: checkoutMonitorAdmin.nonce,
                    session_id: sessionId
                },
                success: function(response) {
                    if (response.success) {
                        self.renderSessionDetail(response.data);
                    } else {
                        self.showError('Error al cargar detalles: ' + response.data.message);
                    }
                },
                error: function() {
                    self.showError('Error de conexión al cargar detalles');
                }
            });
        },

        renderSessionDetail: function(data) {
            var self = this;
            var session = data.session;
            var events = data.events;
            var serverLogs = data.server_logs;
            var browserData = data.browser_data;

            var html = '<h2>Detalle de Sesión: ' + session.session_id + '</h2>';

            // Session Info
            html += '<div class="session-detail-section">';
            html += '<h3>Información General</h3>';
            html += '<table class="detail-table">';
            html += '<tr><th>Session ID:</th><td><code>' + session.session_id + '</code></td></tr>';
            html += '<tr><th>Order ID:</th><td>' + (session.order_id ? '#' + session.order_id : '-') + '</td></tr>';
            html += '<tr><th>Estado:</th><td><span class="status-badge ' + self.getStatusClass(session.status) + '">' + session.status + '</span></td></tr>';
            html += '<tr><th>Inicio:</th><td>' + session.started_at + '</td></tr>';
            html += '<tr><th>Finalización:</th><td>' + (session.completed_at || '-') + '</td></tr>';
            html += '<tr><th>Duración:</th><td>' + (session.duration_ms ? session.duration_ms + 'ms' : '-') + '</td></tr>';
            html += '<tr><th>Método de Pago:</th><td>' + (session.payment_method || '-') + '</td></tr>';
            html += '<tr><th>Total:</th><td>' + (session.total_amount ? '€' + parseFloat(session.total_amount).toFixed(2) : '-') + '</td></tr>';
            html += '<tr><th>Hooks Ejecutados:</th><td>' + session.hook_count + '</td></tr>';
            html += '<tr><th>Errores:</th><td>' + session.error_count + '</td></tr>';
            html += '<tr><th>IP:</th><td>' + (session.ip_address || '-') + '</td></tr>';
            html += '</table>';
            html += '</div>';

            // Browser Data
            if (browserData) {
                html += '<div class="session-detail-section">';
                html += '<h3>Datos del Navegador</h3>';
                html += '<table class="detail-table">';
                html += '<tr><th>User Agent:</th><td><code>' + (browserData.user_agent || '-') + '</code></td></tr>';
                html += '<tr><th>Plataforma:</th><td>' + (browserData.platform || '-') + '</td></tr>';
                html += '<tr><th>Idioma:</th><td>' + (browserData.language || '-') + '</td></tr>';
                html += '<tr><th>Tipo de Dispositivo:</th><td>' + (browserData.device_type || '-') + '</td></tr>';
                html += '<tr><th>Pantalla:</th><td>' + (browserData.screen_width || '-') + ' x ' + (browserData.screen_height || '-') + '</td></tr>';
                html += '<tr><th>Viewport:</th><td>' + (browserData.viewport_width || '-') + ' x ' + (browserData.viewport_height || '-') + '</td></tr>';
                html += '<tr><th>Pixel Ratio:</th><td>' + (browserData.device_pixel_ratio || '-') + '</td></tr>';

                if (browserData.connection) {
                    html += '<tr><th>Conexión:</th><td>' + (browserData.connection.effective_type || '-') + ' (' + (browserData.connection.downlink || '-') + ' Mbps)</td></tr>';
                }

                html += '</table>';
                html += '</div>';
            }

            // Events Timeline
            html += '<div class="session-detail-section">';
            html += '<h3>Timeline de Eventos (' + events.length + ')</h3>';
            html += '<div class="timeline">';

            $.each(events, function(index, event) {
                var errorClass = event.has_error == 1 ? 'timeline-item-error' : '';
                var iconClass = event.has_error == 1 ? '❌' : '✓';

                html += '<div class="timeline-item ' + errorClass + '">';
                html += '<div class="timeline-icon">' + iconClass + '</div>';
                html += '<div class="timeline-content">';
                html += '<div class="timeline-header">';
                html += '<strong>' + (event.hook_name || event.event_type) + '</strong>';
                html += '<span class="timeline-time">' + event.started_at + '</span>';
                html += '</div>';
                html += '<div class="timeline-body">';

                if (event.callback_name) {
                    html += '<div><strong>Callback:</strong> <code>' + event.callback_name + '</code></div>';
                }

                if (event.plugin_name) {
                    html += '<div><strong>Plugin:</strong> ' + event.plugin_name + '</div>';
                }

                if (event.file_path) {
                    html += '<div><strong>Archivo:</strong> <code>' + event.file_path + (event.line_number ? ':' + event.line_number : '') + '</code></div>';
                }

                if (event.execution_time_ms) {
                    html += '<div><strong>Tiempo de ejecución:</strong> ' + parseFloat(event.execution_time_ms).toFixed(2) + 'ms</div>';
                }

                if (event.has_error == 1 && event.error_message) {
                    html += '<div class="error-message"><strong>Error:</strong> ' + event.error_message + '</div>';

                    // Si hay información adicional del error (campos faltantes)
                    if (event.event_data) {
                        try {
                            var errorData = typeof event.event_data === 'string' ? JSON.parse(event.event_data) : event.event_data;

                            // Mostrar campos faltantes
                            if (errorData.missing_fields && Object.keys(errorData.missing_fields).length > 0) {
                                html += '<div class="missing-fields-info">';
                                html += '<strong>⚠️ Campos vacíos detectados:</strong><ul>';
                                $.each(errorData.missing_fields, function(field, label) {
                                    html += '<li><code>' + field + '</code>: ' + label + '</li>';
                                });
                                html += '</ul></div>';
                            }

                            // Mostrar información del checkout
                            if (errorData.checkout_data) {
                                html += '<div class="checkout-context-info">';
                                html += '<strong>Contexto del checkout:</strong><br>';
                                if (errorData.checkout_data.payment_method) {
                                    html += '💳 Método de pago: <code>' + errorData.checkout_data.payment_method + '</code><br>';
                                }
                                if (errorData.checkout_data.ship_to_different_address) {
                                    html += '📦 Envío a dirección diferente: ' + errorData.checkout_data.ship_to_different_address + '<br>';
                                }
                                if (errorData.filled_fields_count) {
                                    html += '✓ Campos completados: ' + errorData.filled_fields_count;
                                }
                                html += '</div>';
                            }

                            // Mostrar origen del error (plugin que causó la validación)
                            if (errorData.caller_plugin && errorData.caller_plugin !== 'unknown') {
                                html += '<div class="error-source-info">';
                                html += '<strong>🔍 Validación ejecutada por:</strong> <code>' + errorData.caller_plugin + '</code>';
                                if (errorData.caller_function) {
                                    html += ' → <code>' + errorData.caller_function + '</code>';
                                }
                                html += '</div>';
                            }
                        } catch (e) {
                            // Silently ignore JSON parse errors
                        }
                    }
                }

                html += '</div>';
                html += '</div>';
                html += '</div>';
            });

            html += '</div>';
            html += '</div>';

            // Server Logs
            if (serverLogs && serverLogs.length > 0) {
                html += '<div class="session-detail-section">';
                html += '<h3>Logs del Servidor (' + serverLogs.length + ')</h3>';
                html += '<div class="server-logs">';

                $.each(serverLogs, function(index, log) {
                    var levelClass = 'log-level-' + (log.log_level || 'unknown').toLowerCase();

                    html += '<div class="log-entry ' + levelClass + '">';
                    html += '<div class="log-header">';
                    html += '<strong>' + log.log_source + '</strong>';
                    html += '<span class="log-level">' + log.log_level + '</span>';
                    html += '<span class="log-time">' + log.timestamp + '</span>';
                    html += '</div>';
                    html += '<pre class="log-content">' + self.escapeHtml(log.log_content) + '</pre>';
                    html += '</div>';
                });

                html += '</div>';
                html += '</div>';
            }

            $('#session-detail-content').html(html);
        },

        setupModal: function() {
            var $modal = $('#session-detail-modal');

            $('.close', $modal).on('click', function() {
                $modal.fadeOut();
            });

            $(window).on('click', function(e) {
                if ($(e.target).is($modal)) {
                    $modal.fadeOut();
                }
            });
        },

        setupCleanup: function() {
            $('#cleanup-old-logs').on('click', function() {
                if (!confirm('¿Estás seguro de que quieres eliminar los logs antiguos?')) {
                    return;
                }

                var days = parseInt($('#cleanup_days').val()) || 30;

                $.ajax({
                    url: checkoutMonitorAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'checkout_monitor_delete_old_logs',
                        nonce: checkoutMonitorAdmin.nonce,
                        days: days
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Se eliminaron ' + response.data.deleted + ' sesiones antiguas.');
                            CheckoutMonitorAdmin.loadSessions();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    }
                });
            });
        },

        getStatusClass: function(status) {
            var classes = {
                'initiated': 'status-initiated',
                'processing': 'status-processing',
                'completed': 'status-completed',
                'failed': 'status-failed'
            };
            return classes[status] || '';
        },

        getDeviceInfo: function(browserDataJson) {
            if (!browserDataJson) return '-';

            try {
                var data = JSON.parse(browserDataJson);
                var icon = '💻';

                if (data.device_type === 'mobile') icon = '📱';
                else if (data.device_type === 'tablet') icon = '📱';

                return icon + ' ' + (data.device_type || 'unknown');
            } catch(e) {
                return '-';
            }
        },

        formatDate: function(dateString) {
            var date = new Date(dateString);
            return date.toLocaleString('es-ES');
        },

        escapeHtml: function(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        },

        setupSettings: function() {
            $('#checkout-monitor-settings-form').on('submit', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var cleanup_days = parseInt($('#cleanup_days').val()) || 30;

                $.ajax({
                    url: checkoutMonitorAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'checkout_monitor_save_settings',
                        nonce: checkoutMonitorAdmin.nonce,
                        cleanup_days: cleanup_days
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.settings-saved-message').fadeIn().delay(2000).fadeOut();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Error al guardar: ' + error);
                    }
                });

                return false;
            });
        },

        setupLogsSorting: function() {
            var currentSort = 'modified';
            var currentOrder = 'desc';

            $('.sortable-column').on('click', function() {
                var sortBy = $(this).data('sort');

                // Toggle order if same column
                if (sortBy === currentSort) {
                    currentOrder = currentOrder === 'desc' ? 'asc' : 'desc';
                } else {
                    currentSort = sortBy;
                    currentOrder = 'desc';
                }

                // Update UI
                $('.sortable-column').removeClass('sorted-asc sorted-desc');
                $('.sortable-column .sort-arrow').text('');

                $(this).addClass('sorted-' + currentOrder);
                $(this).find('.sort-arrow').text(currentOrder === 'desc' ? '▼' : '▲');

                // Sort table rows
                var $tbody = $('#logs-table tbody');
                var $rows = $tbody.find('tr').not(':has(td[colspan])');

                $rows.sort(function(a, b) {
                    var aVal = parseInt($(a).data(sortBy));
                    var bVal = parseInt($(b).data(sortBy));

                    if (currentOrder === 'desc') {
                        return bVal - aVal;
                    } else {
                        return aVal - bVal;
                    }
                });

                $tbody.append($rows);
            });
        },

        setupLogViewer: function() {
            var self = this;
            var $modal = $('#log-viewer-modal');

            // Click handler for view log buttons
            $(document).on('click', '.view-log-btn', function(e) {
                e.preventDefault();
                var logPath = $(this).data('log-path');
                self.loadLogFile(logPath);
            });

            // Close modal handlers
            $('.close', $modal).on('click', function() {
                $modal.fadeOut();
            });

            $(window).on('click', function(e) {
                if ($(e.target).is($modal)) {
                    $modal.fadeOut();
                }
            });
        },

        loadLogFile: function(logPath) {
            var self = this;

            // Show modal with loading state
            $('#log-viewer-title').text('Cargando log...');
            $('#log-viewer-size').text('');
            $('#log-viewer-lines').text('');
            $('#log-viewer-text').html('<div style="text-align: center; padding: 50px;"><span class="spinner is-active" style="float: none;"></span> Cargando archivo de log...</div>');
            $('#log-viewer-modal').fadeIn();

            $.ajax({
                url: checkoutMonitorAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'checkout_monitor_view_log',
                    nonce: checkoutMonitorAdmin.nonce,
                    log_path: logPath
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        $('#log-viewer-title').text(data.file_name);
                        $('#log-viewer-size').text('Tamaño: ' + self.formatFileSize(data.file_size));
                        $('#log-viewer-lines').text('Mostrando últimas ' + data.showing_lines + ' de ' + data.total_lines + ' líneas');
                        $('#log-viewer-text').text(data.content);
                    } else {
                        $('#log-viewer-title').text('Error');
                        $('#log-viewer-text').html('<div style="color: red; padding: 20px;">Error: ' + response.data.message + '</div>');
                    }
                },
                error: function() {
                    $('#log-viewer-title').text('Error');
                    $('#log-viewer-text').html('<div style="color: red; padding: 20px;">Error al cargar el archivo de log</div>');
                }
            });
        },

        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        },

        showError: function(message) {
            $('#sessions-tbody').html('<tr><td colspan="11" style="text-align: center; color: red;">' + message + '</td></tr>');
        }
    };

    $(document).ready(function() {
        if (typeof checkoutMonitorAdmin !== 'undefined') {
            CheckoutMonitorAdmin.init();
        }
    });

})(jQuery);
