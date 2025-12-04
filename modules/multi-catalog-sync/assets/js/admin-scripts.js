/**
 * Multi-Catalog Sync - Admin Scripts
 */

(function($) {
    'use strict';

    const MCS = {
        init: function() {
            console.log('[MCS] Initializing Multi-Catalog Sync...');
            this.initTabs();
            this.initCategorySearch();
            this.initManualSync();
            this.initDashboardRefresh();
            console.log('[MCS] Initialization complete');
        },

        /**
         * Initialize tab switching
         */
        initTabs: function() {
            $('.mcs-tabs .nav-tab').on('click', function(e) {
                e.preventDefault();

                const targetTab = $(this).attr('href');
                console.log('[MCS] Switching to tab:', targetTab);

                // Update active tab
                $('.mcs-tabs .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');

                // Show/hide content
                $('.mcs-tab-content').hide();
                $(targetTab).show();
            });
        },

        /**
         * Initialize Google Category autocomplete search
         */
        initCategorySearch: function() {
            console.log('[MCS] Checking for jQuery UI Autocomplete...');

            if (typeof $.fn.autocomplete === 'undefined') {
                console.error('[MCS] jQuery UI Autocomplete NOT loaded!');
                console.log('[MCS] This is required for category search to work');
                return;
            }

            console.log('[MCS] jQuery UI Autocomplete found ✓');

            // Wait for document ready and check again
            $(document).ready(function() {
                const $fields = $('.mcs-category-search');
                console.log('[MCS] Found ' + $fields.length + ' category search field(s)');

                if ($fields.length === 0) {
                    console.log('[MCS] No category search fields on this page (this is normal if not on category edit screen)');
                    return;
                }

                $fields.each(function(index) {
                    const $field = $(this);
                    const fieldId = $field.attr('id') || 'field-' + index;
                    console.log('[MCS] Initializing autocomplete for:', fieldId);

                    $field.autocomplete({
                        source: function(request, response) {
                            console.log('[MCS] Searching Google taxonomy for:', request.term);

                            $.ajax({
                                url: mcsAdmin.ajaxurl,
                                type: 'POST',
                                dataType: 'json',
                                data: {
                                    action: 'mcs_search_google_category',
                                    nonce: mcsAdmin.nonce,
                                    term: request.term
                                },
                                success: function(data) {
                                    console.log('[MCS] Search response:', data);
                                    if (data.success && data.data && data.data.length > 0) {
                                        console.log('[MCS] Found ' + data.data.length + ' categories');
                                        response(data.data);
                                    } else {
                                        console.log('[MCS] No categories found');
                                        response([{
                                            label: 'No se encontraron resultados',
                                            value: ''
                                        }]);
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.error('[MCS] AJAX error:', status, error);
                                    console.error('[MCS] Response:', xhr.responseText);
                                    response([]);
                                }
                            });
                        },
                        minLength: 2,
                        select: function(event, ui) {
                            if (!ui.item.value) {
                                return false;
                            }
                            console.log('[MCS] Category selected:', ui.item);
                            $(this).val(ui.item.label);
                            $('#mcs_google_category_id').val(ui.item.value);
                            return false;
                        },
                        focus: function(event, ui) {
                            if (!ui.item.value) {
                                return false;
                            }
                            $(this).val(ui.item.label);
                            return false;
                        }
                    });

                    console.log('[MCS] Autocomplete initialized for:', fieldId);
                });
            });
        },

        /**
         * Initialize manual sync button
         */
        initManualSync: function() {
            console.log('[MCS] Initializing manual sync handlers...');

            $(document).on('click', '.mcs-manual-sync', function(e) {
                e.preventDefault();
                e.stopPropagation();

                console.log('[MCS] Sync button clicked!');

                const $button = $(this);
                const destination = $button.data('destination');
                const originalText = $button.html();

                console.log('[MCS] Destination:', destination);
                console.log('[MCS] Original button text:', originalText);

                if ($button.hasClass('disabled') || $button.prop('disabled')) {
                    console.log('[MCS] Button is disabled, ignoring click');
                    return false;
                }

                // Confirm if syncing all
                if (!destination || destination === 'all') {
                    if (!confirm('¿Deseas sincronizar productos a TODOS los destinos habilitados?')) {
                        console.log('[MCS] User cancelled sync');
                        return false;
                    }
                }

                // Store original text
                $button.data('original-text', originalText);

                // Disable button and show loading
                $button.prop('disabled', true).addClass('disabled');
                $button.html('<span class="mcs-loading"></span> ' + (mcsAdmin.strings.syncing || 'Sincronizando...'));

                console.log('[MCS] Starting AJAX sync request...');

                $.ajax({
                    url: mcsAdmin.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'mcs_manual_sync',
                        nonce: mcsAdmin.nonce,
                        destination: destination || 'all'
                    },
                    success: function(response) {
                        console.log('[MCS] Sync response:', response);

                        if (response.success) {
                            const message = response.data && response.data.message ? response.data.message : (mcsAdmin.strings.sync_complete || 'Sincronización completada');
                            console.log('[MCS] Sync successful:', message);
                            MCS.showNotice('success', message);

                            // Refresh dashboard data
                            setTimeout(function() {
                                MCS.updateDashboard();
                            }, 1000);
                        } else {
                            const errorMsg = response.data && response.data.message ? response.data.message : (mcsAdmin.strings.sync_error || 'Error en sincronización');
                            console.error('[MCS] Sync failed:', errorMsg);
                            MCS.showNotice('error', errorMsg);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('[MCS] AJAX error:', status, error);
                        console.error('[MCS] Response:', xhr.responseText);
                        MCS.showNotice('error', 'Error de conexión: ' + error);
                    },
                    complete: function() {
                        console.log('[MCS] Sync request complete, re-enabling button');
                        $button.prop('disabled', false).removeClass('disabled');
                        $button.html($button.data('original-text') || 'Sincronizar Ahora');
                    }
                });

                return false;
            });

            console.log('[MCS] Manual sync handlers initialized');
        },

        /**
         * Initialize dashboard auto-refresh
         */
        initDashboardRefresh: function() {
            if ($('#tab-dashboard').length === 0) {
                console.log('[MCS] No dashboard on this page');
                return;
            }

            console.log('[MCS] Dashboard found, enabling auto-refresh');

            // Refresh every 30 seconds if dashboard tab is active
            setInterval(function() {
                if ($('#tab-dashboard').is(':visible')) {
                    console.log('[MCS] Auto-refreshing dashboard...');
                    MCS.updateDashboard();
                }
            }, 30000);
        },

        /**
         * Update dashboard data via AJAX
         */
        updateDashboard: function() {
            console.log('[MCS] Fetching dashboard status...');

            $.ajax({
                url: mcsAdmin.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'mcs_get_sync_status',
                    nonce: mcsAdmin.nonce
                },
                success: function(response) {
                    console.log('[MCS] Dashboard status:', response);
                    if (response.success && response.data) {
                        MCS.renderDashboardData(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[MCS] Failed to update dashboard:', error);
                }
            });
        },

        /**
         * Render updated dashboard data
         */
        renderDashboardData: function(data) {
            console.log('[MCS] Rendering dashboard data...');

            // Update destination cards
            if (data.destinations) {
                $.each(data.destinations, function(key, dest) {
                    const $card = $('.mcs-card[data-destination="' + key + '"]');
                    if ($card.length) {
                        $card.find('.mcs-stat-value').first().text(dest.items || 0);
                        $card.find('.mcs-stat-value').last().text(dest.errors || 0);

                        const $status = $card.find('.mcs-card-status');
                        if (dest.connected) {
                            $status.removeClass('disconnected').addClass('connected').text('Conectado');
                        } else {
                            $status.removeClass('connected').addClass('disconnected').text('Desconectado');
                        }
                    }
                });
            }

            // Update summary
            if (data.summary) {
                $.each(data.summary, function(key, value) {
                    $('.mcs-summary [data-stat="' + key + '"]').text(value);
                });
            }

            // Update last sync time
            if (data.last_sync) {
                $('.mcs-last-sync').text(data.last_sync);
            }

            console.log('[MCS] Dashboard rendered');
        },

        /**
         * Show admin notice
         */
        showNotice: function(type, message) {
            console.log('[MCS] Showing notice:', type, message);

            const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');

            // Try multiple insertion points
            if ($('.wrap h1').length) {
                $('.wrap h1').first().after($notice);
            } else if ($('.mcs-admin-wrapper').length) {
                $('.mcs-admin-wrapper').prepend($notice);
            } else {
                console.warn('[MCS] Could not find insertion point for notice');
                alert(message); // Fallback to alert
                return;
            }

            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        console.log('[MCS] Document ready, initializing...');
        if (typeof mcsAdmin === 'undefined') {
            console.error('[MCS] mcsAdmin object not found! Scripts may not be enqueued properly.');
            return;
        }
        MCS.init();
    });

})(jQuery);
