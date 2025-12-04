/**
 * Multi-Catalog Sync - Admin Scripts
 */

(function($) {
    'use strict';

    const MCS = {
        init: function() {
            this.initTabs();
            this.initCategorySearch();
            this.initManualSync();
            this.initDashboardRefresh();
        },

        /**
         * Initialize tab switching
         */
        initTabs: function() {
            $('.mcs-tabs .nav-tab').on('click', function(e) {
                e.preventDefault();

                const targetTab = $(this).attr('href');

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
            if (typeof $.fn.autocomplete === 'undefined') {
                return;
            }

            $('.mcs-category-search').autocomplete({
                source: function(request, response) {
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
                            if (data.success && data.data) {
                                response(data.data);
                            } else {
                                response([]);
                            }
                        },
                        error: function() {
                            response([]);
                        }
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    $(this).val(ui.item.label);
                    $('#mcs_google_category_id').val(ui.item.value);
                    return false;
                },
                focus: function(event, ui) {
                    $(this).val(ui.item.label);
                    return false;
                }
            }).autocomplete('instance')._renderItem = function(ul, item) {
                return $('<li>')
                    .append('<div>' + item.label + '<span class="mcs-category-path">' + item.path + '</span></div>')
                    .appendTo(ul);
            };
        },

        /**
         * Initialize manual sync button
         */
        initManualSync: function() {
            $(document).on('click', '.mcs-manual-sync', function(e) {
                e.preventDefault();

                const $button = $(this);
                const $card = $button.closest('.mcs-card');
                const destination = $button.data('destination');

                if ($button.hasClass('disabled') || $button.prop('disabled')) {
                    return;
                }

                // Disable button and show loading
                $button.prop('disabled', true).addClass('disabled');
                $button.html('<span class="mcs-loading"></span> ' + mcsAdmin.strings.syncing);

                $.ajax({
                    url: mcsAdmin.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'mcs_manual_sync',
                        nonce: mcsAdmin.nonce,
                        destination: destination
                    },
                    success: function(response) {
                        if (response.success) {
                            MCS.showNotice('success', mcsAdmin.strings.sync_complete);
                            MCS.updateDashboard();
                        } else {
                            MCS.showNotice('error', response.data.message || mcsAdmin.strings.sync_error);
                        }
                    },
                    error: function() {
                        MCS.showNotice('error', mcsAdmin.strings.sync_error);
                    },
                    complete: function() {
                        $button.prop('disabled', false).removeClass('disabled');
                        $button.text($button.data('original-text') || 'Sincronizar Ahora');
                    }
                });
            });
        },

        /**
         * Initialize dashboard auto-refresh
         */
        initDashboardRefresh: function() {
            if ($('#tab-dashboard').length === 0) {
                return;
            }

            // Refresh every 30 seconds if dashboard tab is active
            setInterval(function() {
                if ($('#tab-dashboard').is(':visible')) {
                    MCS.updateDashboard();
                }
            }, 30000);
        },

        /**
         * Update dashboard data via AJAX
         */
        updateDashboard: function() {
            $.ajax({
                url: mcsAdmin.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'mcs_get_sync_status',
                    nonce: mcsAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        MCS.renderDashboardData(response.data);
                    }
                }
            });
        },

        /**
         * Render updated dashboard data
         */
        renderDashboardData: function(data) {
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
        },

        /**
         * Show admin notice
         */
        showNotice: function(type, message) {
            const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after($notice);

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
        MCS.init();
    });

})(jQuery);
