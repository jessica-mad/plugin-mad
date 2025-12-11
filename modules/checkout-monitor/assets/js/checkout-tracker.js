(function($) {
    'use strict';

    var CheckoutTracker = {
        sessionId: null,
        browserData: {},
        jsErrors: [],

        init: function() {
            this.sessionId = checkoutMonitor.sessionId;
            this.collectBrowserData();
            this.setupErrorTracking();
            this.trackCheckoutEvents();
            this.sendBrowserData();
        },

        collectBrowserData: function() {
            var self = this;

            // Basic browser info
            self.browserData.user_agent = navigator.userAgent;
            self.browserData.platform = navigator.platform;
            self.browserData.language = navigator.language;
            self.browserData.languages = navigator.languages || [navigator.language];

            // Screen info
            self.browserData.screen_width = screen.width;
            self.browserData.screen_height = screen.height;
            self.browserData.screen_color_depth = screen.colorDepth;
            self.browserData.viewport_width = window.innerWidth || document.documentElement.clientWidth;
            self.browserData.viewport_height = window.innerHeight || document.documentElement.clientHeight;
            self.browserData.device_pixel_ratio = window.devicePixelRatio || 1;

            // Device detection
            self.browserData.device_type = self.detectDeviceType();
            self.browserData.is_mobile = /Mobile|Android|iPhone/i.test(navigator.userAgent);
            self.browserData.is_tablet = /Tablet|iPad/i.test(navigator.userAgent);
            self.browserData.is_desktop = !self.browserData.is_mobile && !self.browserData.is_tablet;

            // Browser capabilities
            self.browserData.cookies_enabled = navigator.cookieEnabled;
            self.browserData.local_storage = self.checkLocalStorage();
            self.browserData.session_storage = self.checkSessionStorage();

            // Performance timing
            if (window.performance && window.performance.timing) {
                var timing = window.performance.timing;
                self.browserData.performance = {
                    navigation_start: timing.navigationStart,
                    dom_complete: timing.domComplete,
                    load_event_end: timing.loadEventEnd,
                    page_load_time: timing.loadEventEnd - timing.navigationStart
                };
            }

            // Connection info (if available)
            if (navigator.connection || navigator.mozConnection || navigator.webkitConnection) {
                var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                self.browserData.connection = {
                    effective_type: connection.effectiveType || '',
                    downlink: connection.downlink || 0,
                    rtt: connection.rtt || 0,
                    save_data: connection.saveData || false
                };
            }

            // Timezone
            self.browserData.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            self.browserData.timezone_offset = new Date().getTimezoneOffset();

            // Referrer and current page
            self.browserData.referrer = document.referrer;
            self.browserData.page_url = window.location.href;
        },

        detectDeviceType: function() {
            var ua = navigator.userAgent.toLowerCase();

            if (/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test(ua)) {
                return 'tablet';
            }

            if (/Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/.test(ua)) {
                return 'mobile';
            }

            return 'desktop';
        },

        checkLocalStorage: function() {
            try {
                localStorage.setItem('test', 'test');
                localStorage.removeItem('test');
                return true;
            } catch(e) {
                return false;
            }
        },

        checkSessionStorage: function() {
            try {
                sessionStorage.setItem('test', 'test');
                sessionStorage.removeItem('test');
                return true;
            } catch(e) {
                return false;
            }
        },

        setupErrorTracking: function() {
            var self = this;

            // Track JavaScript errors
            window.addEventListener('error', function(event) {
                self.jsErrors.push({
                    message: event.message,
                    source: event.filename,
                    lineno: event.lineno,
                    colno: event.colno,
                    timestamp: Date.now()
                });

                // Send error immediately
                self.sendBrowserData();
            });

            // Track unhandled promise rejections
            window.addEventListener('unhandledrejection', function(event) {
                self.jsErrors.push({
                    message: 'Unhandled Promise Rejection: ' + event.reason,
                    source: 'Promise',
                    lineno: 0,
                    colno: 0,
                    timestamp: Date.now()
                });

                self.sendBrowserData();
            });
        },

        trackCheckoutEvents: function() {
            var self = this;

            // Track when checkout form is submitted
            $(document.body).on('checkout_place_order', function() {
                console.log('Checkout Monitor: Order being placed');
                self.browserData.checkout_initiated = Date.now();
                self.sendBrowserData();
            });

            // Track checkout errors
            $(document.body).on('checkout_error', function(event, error_message) {
                console.log('Checkout Monitor: Error detected', error_message);
                self.jsErrors.push({
                    message: 'Checkout Error: ' + error_message,
                    source: 'WooCommerce',
                    lineno: 0,
                    colno: 0,
                    timestamp: Date.now()
                });
                self.sendBrowserData();
            });

            // Track when checkout updates
            $(document.body).on('updated_checkout', function() {
                console.log('Checkout Monitor: Checkout updated');
            });
        },

        sendBrowserData: function() {
            var self = this;

            // Add JS errors to browser data
            if (self.jsErrors.length > 0) {
                self.browserData.js_errors = self.jsErrors;
            }

            $.ajax({
                url: checkoutMonitor.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'checkout_monitor_track_browser',
                    nonce: checkoutMonitor.nonce,
                    session_id: self.sessionId,
                    browser_data: self.browserData
                },
                success: function(response) {
                    console.log('Checkout Monitor: Browser data sent', response);
                },
                error: function(xhr, status, error) {
                    console.error('Checkout Monitor: Failed to send browser data', error);
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if (typeof checkoutMonitor !== 'undefined') {
            CheckoutTracker.init();
        }
    });

})(jQuery);
