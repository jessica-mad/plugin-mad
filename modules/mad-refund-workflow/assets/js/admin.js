/**
 * MAD Refund Workflow - Admin JavaScript
 *
 * Handles real-time calculations and UI interactions for the pre-refund meta box.
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Ensure localized data exists
    if (typeof madRefundL10n === 'undefined') {
        console.warn('MAD Refund: Localized data not found');
        return;
    }

    /**
     * MAD Refund Module
     */
    const MADRefund = {
        /**
         * Configuration
         */
        config: {
            orderId: madRefundL10n.orderId || 0,
            ajaxUrl: madRefundL10n.ajaxUrl,
            nonce: madRefundL10n.nonce,
            currency: madRefundL10n.currency,
            decimals: parseInt(madRefundL10n.decimals, 10) || 2,
            decimalSep: madRefundL10n.decimalSep || '.',
            thousandSep: madRefundL10n.thousandSep || ',',
            strings: madRefundL10n.strings || {}
        },

        /**
         * DOM Elements
         */
        elements: {
            container: null,
            itemsTable: null,
            selectAll: null,
            itemCheckboxes: null,
            qtyInputs: null,
            shippingCheckbox: null,
            notesField: null,
            saveButton: null,
            clearButton: null,
            statusMessage: null,
            subtotalEl: null,
            taxEl: null,
            shippingEl: null,
            totalEl: null
        },

        /**
         * State
         */
        state: {
            calculating: false,
            saving: false,
            lastCalculation: null
        },

        /**
         * Initialize the module
         */
        init: function() {
            this.cacheElements();

            if (!this.elements.container.length) {
                return;
            }

            this.bindEvents();
            this.updateTotals();
        },

        /**
         * Cache DOM elements
         */
        cacheElements: function() {
            this.elements.container = $('#mad-refund-container');
            this.elements.itemsTable = this.elements.container.find('.mad-refund-items-table');
            this.elements.selectAll = this.elements.container.find('#mad-refund-select-all');
            this.elements.itemCheckboxes = this.elements.container.find('.mad-refund-item-check');
            this.elements.qtyInputs = this.elements.container.find('.mad-refund-qty-input');
            this.elements.shippingCheckbox = this.elements.container.find('#mad-refund-include-shipping');
            this.elements.notesField = this.elements.container.find('#mad-refund-notes');
            this.elements.saveButton = this.elements.container.find('#mad-refund-save');
            this.elements.clearButton = this.elements.container.find('#mad-refund-clear');
            this.elements.statusMessage = this.elements.container.find('.mad-refund-status');
            this.elements.subtotalEl = this.elements.container.find('#mad-refund-subtotal');
            this.elements.taxEl = this.elements.container.find('#mad-refund-tax');
            this.elements.shippingEl = this.elements.container.find('#mad-refund-shipping');
            this.elements.totalEl = this.elements.container.find('#mad-refund-total');
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // Select all checkbox
            this.elements.selectAll.on('change', function() {
                self.handleSelectAll($(this).prop('checked'));
            });

            // Individual item checkboxes
            this.elements.container.on('change', '.mad-refund-item-check', function() {
                self.handleItemToggle($(this));
            });

            // Quantity inputs
            this.elements.container.on('input change', '.mad-refund-qty-input', function() {
                self.handleQuantityChange($(this));
            });

            // Shipping checkbox
            this.elements.shippingCheckbox.on('change', function() {
                self.handleShippingToggle($(this).prop('checked'));
            });

            // Save button
            this.elements.saveButton.on('click', function(e) {
                e.preventDefault();
                self.saveRefundData();
            });

            // Clear button
            this.elements.clearButton.on('click', function(e) {
                e.preventDefault();
                self.clearSelection();
            });

            // Prevent form submission on enter in quantity fields
            this.elements.qtyInputs.on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.updateTotals();
                }
            });
        },

        /**
         * Handle select all checkbox
         *
         * @param {boolean} checked Whether checkbox is checked
         */
        handleSelectAll: function(checked) {
            const self = this;

            this.elements.itemCheckboxes.each(function() {
                const $checkbox = $(this);
                const $row = $checkbox.closest('tr');
                const $qtyInput = $row.find('.mad-refund-qty-input');
                const maxQty = parseInt($qtyInput.data('max'), 10);

                $checkbox.prop('checked', checked);
                $row.toggleClass('selected', checked);
                $qtyInput.prop('disabled', !checked);

                if (checked && $qtyInput.val() == 0) {
                    $qtyInput.val(maxQty);
                } else if (!checked) {
                    $qtyInput.val(0);
                }
            });

            this.updateTotals();
        },

        /**
         * Handle individual item toggle
         *
         * @param {jQuery} $checkbox Checkbox element
         */
        handleItemToggle: function($checkbox) {
            const checked = $checkbox.prop('checked');
            const $row = $checkbox.closest('tr');
            const $qtyInput = $row.find('.mad-refund-qty-input');
            const maxQty = parseInt($qtyInput.data('max'), 10);

            $row.toggleClass('selected', checked);
            $qtyInput.prop('disabled', !checked);

            if (checked && $qtyInput.val() == 0) {
                $qtyInput.val(maxQty);
            } else if (!checked) {
                $qtyInput.val(0);
            }

            this.updateSelectAllState();
            this.updateTotals();
        },

        /**
         * Handle quantity change
         *
         * @param {jQuery} $input Quantity input element
         */
        handleQuantityChange: function($input) {
            const maxQty = parseInt($input.data('max'), 10);
            let qty = parseInt($input.val(), 10) || 0;

            // Validate quantity
            if (qty < 0) {
                qty = 0;
            } else if (qty > maxQty) {
                qty = maxQty;
                this.showStatus(this.config.strings.maxQuantity, 'warning');
            }

            $input.val(qty);

            // Update item subtotal
            this.updateItemSubtotal($input);

            // Update totals with debounce
            this.debounceUpdateTotals();
        },

        /**
         * Handle shipping toggle
         *
         * @param {boolean} included Whether shipping is included
         */
        handleShippingToggle: function(included) {
            this.elements.container.find('.shipping-row').toggleClass('hidden', !included);
            this.updateTotals();
        },

        /**
         * Update individual item subtotal display
         *
         * @param {jQuery} $input Quantity input element
         */
        updateItemSubtotal: function($input) {
            const $row = $input.closest('tr');
            const itemId = $row.data('item-id');
            const qty = parseInt($input.val(), 10) || 0;
            const unitPrice = parseFloat($input.data('unit-price')) || 0;
            const unitTax = parseFloat($input.data('unit-tax')) || 0;

            const subtotal = qty * (unitPrice + unitTax);
            const $subtotalEl = $row.find('.mad-refund-item-subtotal');

            $subtotalEl.html(this.formatPrice(subtotal));
        },

        /**
         * Update select all checkbox state
         */
        updateSelectAllState: function() {
            const total = this.elements.itemCheckboxes.length;
            const checked = this.elements.itemCheckboxes.filter(':checked').length;

            this.elements.selectAll.prop({
                'checked': checked === total,
                'indeterminate': checked > 0 && checked < total
            });
        },

        /**
         * Debounced totals update
         */
        debounceUpdateTotals: (function() {
            let timeout;
            return function() {
                const self = this;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    self.updateTotals();
                }, 300);
            };
        })(),

        /**
         * Update totals via AJAX
         */
        updateTotals: function() {
            const self = this;

            if (this.state.calculating) {
                return;
            }

            const items = this.collectItems();
            const includeShipping = this.elements.shippingCheckbox.prop('checked');

            // Quick local calculation for responsiveness
            this.calculateLocally(items, includeShipping);

            // Skip AJAX if no items selected
            if (Object.keys(items).length === 0) {
                return;
            }

            this.state.calculating = true;

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mad_refund_calculate_totals',
                    nonce: this.config.nonce,
                    order_id: this.config.orderId,
                    items: items,
                    include_shipping: includeShipping
                },
                success: function(response) {
                    if (response.success) {
                        self.updateTotalsDisplay(response.data);
                        self.state.lastCalculation = response.data;
                    }
                },
                complete: function() {
                    self.state.calculating = false;
                }
            });
        },

        /**
         * Calculate totals locally for immediate feedback
         *
         * @param {Object} items Selected items
         * @param {boolean} includeShipping Include shipping
         */
        calculateLocally: function(items, includeShipping) {
            let subtotal = 0;
            let tax = 0;

            this.elements.container.find('.mad-refund-item').each(function() {
                const $row = $(this);
                const itemId = $row.data('item-id');
                const $input = $row.find('.mad-refund-qty-input');

                if (!items[itemId]) {
                    return;
                }

                const qty = items[itemId].quantity;
                const unitPrice = parseFloat($input.data('unit-price')) || 0;
                const unitTax = parseFloat($input.data('unit-tax')) || 0;

                subtotal += qty * unitPrice;
                tax += qty * unitTax;
            });

            let shipping = 0;
            let shippingTax = 0;

            if (includeShipping) {
                const shippingText = this.elements.container.find('.shipping-amount').text();
                const shippingMatch = shippingText.match(/[\d.,]+/);
                if (shippingMatch) {
                    shipping = parseFloat(shippingMatch[0].replace(/[^0-9.]/g, '')) || 0;
                }
            }

            const total = subtotal + tax + shipping + shippingTax;

            this.elements.subtotalEl.html(this.formatPrice(subtotal));
            this.elements.taxEl.html(this.formatPrice(tax));
            this.elements.shippingEl.html(this.formatPrice(shipping + shippingTax));
            this.elements.totalEl.html('<strong>' + this.formatPrice(total) + '</strong>');
        },

        /**
         * Update totals display from server response
         *
         * @param {Object} data Response data
         */
        updateTotalsDisplay: function(data) {
            if (data.formatted) {
                this.elements.subtotalEl.html(data.formatted.subtotal);
                this.elements.taxEl.html(data.formatted.tax);
                this.elements.shippingEl.html(data.formatted.shipping);
                this.elements.totalEl.html('<strong>' + data.formatted.total + '</strong>');
            }

            // Update individual item totals if provided
            if (data.items) {
                for (const itemId in data.items) {
                    const $subtotal = this.elements.container.find('.mad-refund-item-subtotal[data-item-id="' + itemId + '"]');
                    $subtotal.html(this.formatPrice(data.items[itemId].line_total));
                }
            }
        },

        /**
         * Collect selected items data
         *
         * @return {Object} Items data
         */
        collectItems: function() {
            const items = {};

            this.elements.container.find('.mad-refund-item').each(function() {
                const $row = $(this);
                const $checkbox = $row.find('.mad-refund-item-check');
                const $qtyInput = $row.find('.mad-refund-qty-input');

                if (!$checkbox.prop('checked')) {
                    return;
                }

                const itemId = $row.data('item-id');
                const qty = parseInt($qtyInput.val(), 10) || 0;

                if (qty > 0) {
                    items[itemId] = {
                        quantity: qty
                    };
                }
            });

            return items;
        },

        /**
         * Save refund data
         */
        saveRefundData: function() {
            const self = this;

            if (this.state.saving) {
                return;
            }

            const items = this.collectItems();

            if (Object.keys(items).length === 0) {
                this.showStatus(this.config.strings.error + ': No items selected', 'error');
                return;
            }

            this.state.saving = true;
            this.showStatus(this.config.strings.saving, 'loading');
            this.elements.saveButton.prop('disabled', true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mad_refund_save_data',
                    nonce: this.config.nonce,
                    order_id: this.config.orderId,
                    items: items,
                    include_shipping: this.elements.shippingCheckbox.prop('checked'),
                    notes: this.elements.notesField.val()
                },
                success: function(response) {
                    if (response.success) {
                        self.showStatus(self.config.strings.saved, 'success');

                        // Refresh page to show updated UI
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        self.showStatus(response.data.message || self.config.strings.error, 'error');
                    }
                },
                error: function() {
                    self.showStatus(self.config.strings.error, 'error');
                },
                complete: function() {
                    self.state.saving = false;
                    self.elements.saveButton.prop('disabled', false);
                }
            });
        },

        /**
         * Clear all selections
         */
        clearSelection: function() {
            if (!confirm(this.config.strings.confirmClear)) {
                return;
            }

            this.elements.selectAll.prop('checked', false);
            this.handleSelectAll(false);
            this.elements.shippingCheckbox.prop('checked', false);
            this.elements.notesField.val('');
            this.handleShippingToggle(false);
        },

        /**
         * Show status message
         *
         * @param {string} message Message to show
         * @param {string} type Message type (success, error, loading, warning)
         */
        showStatus: function(message, type) {
            const $status = this.elements.statusMessage;

            $status.removeClass('success error loading warning').addClass(type);

            let icon = '';
            switch (type) {
                case 'success':
                    icon = '<span class="dashicons dashicons-yes-alt"></span>';
                    break;
                case 'error':
                    icon = '<span class="dashicons dashicons-dismiss"></span>';
                    break;
                case 'loading':
                    icon = '<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>';
                    break;
                case 'warning':
                    icon = '<span class="dashicons dashicons-warning"></span>';
                    break;
            }

            $status.html(icon + message).fadeIn();

            if (type !== 'loading') {
                setTimeout(function() {
                    $status.fadeOut();
                }, 5000);
            }
        },

        /**
         * Format price
         *
         * @param {number} price Price value
         * @return {string} Formatted price
         */
        formatPrice: function(price) {
            price = parseFloat(price) || 0;

            const formatted = price.toFixed(this.config.decimals)
                .replace('.', this.config.decimalSep)
                .replace(/\B(?=(\d{3})+(?!\d))/g, this.config.thousandSep);

            return this.config.currency + formatted;
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        MADRefund.init();
    });

})(jQuery);
