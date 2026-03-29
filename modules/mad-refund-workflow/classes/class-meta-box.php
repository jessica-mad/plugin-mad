<?php
/**
 * Meta Box for Pre-Refund Product Selection
 *
 * Provides the admin interface for selecting products to include in pre-refund.
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

class MAD_Refund_Meta_Box {

    /**
     * Parent module reference
     *
     * @var object
     */
    private $module;

    /**
     * Meta box ID
     *
     * @var string
     */
    const META_BOX_ID = 'mad-refund-metabox';

    /**
     * Constructor
     *
     * @param object $module Parent module instance
     */
    public function __construct($module) {
        $this->module = $module;
    }

    /**
     * Initialize hooks
     */
    public function init() {
        // Add meta box to order edit screen
        add_action('add_meta_boxes', [$this, 'add_meta_box'], 30);

        // HPOS compatibility - add meta box to new orders screen
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'add_order_data_section']);

        // Add action to order actions meta box
        add_action('woocommerce_order_actions', [$this, 'add_order_actions']);

        // Handle order action
        add_action('woocommerce_order_action_generate_prerefund_pdf', [$this, 'handle_generate_pdf_action']);

        // Add PDF download link to meta box
        add_action('mad_refund_metabox_after_totals', [$this, 'render_pdf_actions']);
    }

    /**
     * Add meta box to order edit screen
     */
    public function add_meta_box() {
        // Get screens for both legacy and HPOS
        $screens = ['shop_order'];

        // Add HPOS screen if available
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        // Also add the woocommerce_page_wc-orders screen directly
        $screens[] = 'woocommerce_page_wc-orders';

        // Remove duplicates and empty values
        $screens = array_unique(array_filter($screens));

        foreach ($screens as $screen) {
            add_meta_box(
                self::META_BOX_ID,
                __('Pre-Refund Items Selection', 'mad-suite'),
                [$this, 'render_meta_box'],
                $screen,
                'normal',
                'high'
            );
        }
    }

    /**
     * Render the meta box content
     *
     * @param WP_Post|WC_Order $post_or_order Post or Order object
     */
    public function render_meta_box($post_or_order) {
        // Get order object
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'mad-suite') . '</p>';
            return;
        }

        $order_status = $order->get_status();
        $is_pending_refund = $order_status === 'pending-refund';
        $refund_data = $order->get_meta('_pending_refund_data');

        // Check if we should show the meta box
        if (!$is_pending_refund && empty($refund_data)) {
            $this->render_status_notice($order);
            return;
        }

        // Render the full interface
        $this->render_items_table($order, $refund_data, $is_pending_refund);
    }

    /**
     * Render notice when order is not in pending-refund status
     *
     * @param WC_Order $order Order object
     */
    private function render_status_notice($order) {
        $settings = $this->module->get_settings();
        $status_name = !empty($settings['status_name']) ? $settings['status_name'] : __('Pending Refund', 'mad-suite');

        ?>
        <div class="mad-refund-notice">
            <p>
                <?php
                printf(
                    /* translators: %s: status name */
                    esc_html__('To create a pre-refund invoice, change the order status to "%s".', 'mad-suite'),
                    esc_html($status_name)
                );
                ?>
            </p>
            <p class="description">
                <?php esc_html_e('The pre-refund workflow allows you to generate return documentation for customs and transport before processing the actual refund.', 'mad-suite'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the items selection table
     *
     * @param WC_Order $order Order object
     * @param array|null $refund_data Existing refund data
     * @param bool $is_pending_refund Whether order is in pending-refund status
     */
    private function render_items_table($order, $refund_data = null, $is_pending_refund = false) {
        $items = $order->get_items();
        $currency = $order->get_currency();
        $saved_items = isset($refund_data['items']) ? $refund_data['items'] : [];
        $include_shipping = isset($refund_data['include_shipping']) ? $refund_data['include_shipping'] : false;
        $notes = isset($refund_data['notes']) ? $refund_data['notes'] : '';

        ?>
        <div id="mad-refund-container" class="mad-refund-container" data-order-id="<?php echo esc_attr($order->get_id()); ?>">

            <?php if (!empty($refund_data)) : ?>
                <div class="mad-refund-saved-notice">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php
                    $created_user = get_userdata($refund_data['created_by']);
                    printf(
                        /* translators: %1$s: date, %2$s: user name */
                        esc_html__('Pre-refund data saved on %1$s by %2$s', 'mad-suite'),
                        esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $refund_data['created_date'])),
                        esc_html($created_user ? $created_user->display_name : __('Unknown', 'mad-suite'))
                    );
                    ?>
                </div>
            <?php endif; ?>

            <table class="mad-refund-items-table widefat">
                <thead>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" id="mad-refund-select-all" title="<?php esc_attr_e('Select all', 'mad-suite'); ?>">
                        </th>
                        <th class="item-column"><?php esc_html_e('Product', 'mad-suite'); ?></th>
                        <th class="sku-column"><?php esc_html_e('SKU', 'mad-suite'); ?></th>
                        <th class="qty-original-column"><?php esc_html_e('Ordered', 'mad-suite'); ?></th>
                        <th class="qty-refund-column"><?php esc_html_e('Refund Qty', 'mad-suite'); ?></th>
                        <th class="price-column"><?php esc_html_e('Unit Price', 'mad-suite'); ?></th>
                        <th class="subtotal-column"><?php esc_html_e('Subtotal', 'mad-suite'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item_id => $item) :
                        $product = $item->get_product();
                        $sku = $product ? $product->get_sku() : '';
                        $quantity = $item->get_quantity();
                        $subtotal = $item->get_subtotal();
                        $unit_price = $subtotal / max(1, $quantity);
                        $tax = $item->get_subtotal_tax();

                        // Check if this item has saved data
                        $saved_qty = isset($saved_items[$item_id]) ? $saved_items[$item_id]['quantity'] : 0;
                        $is_selected = $saved_qty > 0;
                    ?>
                        <tr class="mad-refund-item <?php echo $is_selected ? 'selected' : ''; ?>" data-item-id="<?php echo esc_attr($item_id); ?>">
                            <td class="check-column">
                                <input type="checkbox"
                                       class="mad-refund-item-check"
                                       name="refund_items[<?php echo esc_attr($item_id); ?>][selected]"
                                       <?php checked($is_selected); ?>>
                            </td>
                            <td class="item-column">
                                <strong><?php echo esc_html($item->get_name()); ?></strong>
                                <?php if ($product && $product->is_type('variation')) : ?>
                                    <div class="variation-data">
                                        <?php
                                        $variation_data = $item->get_formatted_meta_data('_', true);
                                        foreach ($variation_data as $meta) {
                                            echo '<small>' . esc_html($meta->display_key) . ': ' . wp_kses_post($meta->display_value) . '</small><br>';
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="sku-column">
                                <code><?php echo esc_html($sku ?: '—'); ?></code>
                            </td>
                            <td class="qty-original-column">
                                <span class="qty-badge"><?php echo esc_html($quantity); ?></span>
                            </td>
                            <td class="qty-refund-column">
                                <input type="number"
                                       class="mad-refund-qty-input"
                                       name="refund_items[<?php echo esc_attr($item_id); ?>][quantity]"
                                       value="<?php echo esc_attr($saved_qty); ?>"
                                       min="0"
                                       max="<?php echo esc_attr($quantity); ?>"
                                       step="1"
                                       data-max="<?php echo esc_attr($quantity); ?>"
                                       data-unit-price="<?php echo esc_attr($unit_price); ?>"
                                       data-unit-tax="<?php echo esc_attr($tax / max(1, $quantity)); ?>"
                                       <?php echo !$is_selected ? 'disabled' : ''; ?>>
                            </td>
                            <td class="price-column">
                                <?php echo wc_price($unit_price, ['currency' => $currency]); ?>
                            </td>
                            <td class="subtotal-column">
                                <span class="mad-refund-item-subtotal" data-item-id="<?php echo esc_attr($item_id); ?>">
                                    <?php echo wc_price($saved_qty > 0 ? ($unit_price * $saved_qty) : 0, ['currency' => $currency]); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Shipping Option -->
            <div class="mad-refund-shipping-option">
                <label>
                    <input type="checkbox"
                           id="mad-refund-include-shipping"
                           name="include_shipping"
                           <?php checked($include_shipping); ?>>
                    <?php esc_html_e('Include shipping costs in refund', 'mad-suite'); ?>
                </label>
                <?php
                $shipping_total = 0;
                $shipping_tax = 0;
                foreach ($order->get_shipping_methods() as $shipping_item) {
                    $shipping_total += $shipping_item->get_total();
                    $shipping_tax += $shipping_item->get_total_tax();
                }
                ?>
                <span class="shipping-amount">
                    (<?php echo wc_price($shipping_total + $shipping_tax, ['currency' => $currency]); ?>)
                </span>
            </div>

            <!-- Notes -->
            <div class="mad-refund-notes">
                <label for="mad-refund-notes"><?php esc_html_e('Internal Notes (optional)', 'mad-suite'); ?></label>
                <textarea id="mad-refund-notes"
                          name="refund_notes"
                          rows="2"
                          placeholder="<?php esc_attr_e('Add any internal notes about this refund...', 'mad-suite'); ?>"><?php echo esc_textarea($notes); ?></textarea>
            </div>

            <!-- Totals -->
            <div class="mad-refund-totals">
                <table class="totals-table">
                    <tr>
                        <th><?php esc_html_e('Products Subtotal:', 'mad-suite'); ?></th>
                        <td id="mad-refund-subtotal"><?php echo wc_price($refund_data['subtotal'] ?? 0, ['currency' => $currency]); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Tax:', 'mad-suite'); ?></th>
                        <td id="mad-refund-tax"><?php echo wc_price($refund_data['tax'] ?? 0, ['currency' => $currency]); ?></td>
                    </tr>
                    <tr class="shipping-row <?php echo !$include_shipping ? 'hidden' : ''; ?>">
                        <th><?php esc_html_e('Shipping:', 'mad-suite'); ?></th>
                        <td id="mad-refund-shipping"><?php echo wc_price(($refund_data['shipping'] ?? 0) + ($refund_data['shipping_tax'] ?? 0), ['currency' => $currency]); ?></td>
                    </tr>
                    <tr class="total-row">
                        <th><?php esc_html_e('Total to Refund:', 'mad-suite'); ?></th>
                        <td id="mad-refund-total"><strong><?php echo wc_price($refund_data['total'] ?? 0, ['currency' => $currency]); ?></strong></td>
                    </tr>
                </table>
            </div>

            <?php
            /**
             * Hook for adding content after totals
             *
             * @param WC_Order $order Order object
             * @param array $refund_data Existing refund data
             */
            do_action('mad_refund_metabox_after_totals', $order, $refund_data);
            ?>

            <!-- Actions -->
            <div class="mad-refund-actions">
                <button type="button" id="mad-refund-save" class="button button-primary">
                    <span class="dashicons dashicons-saved"></span>
                    <?php esc_html_e('Save Refund Data', 'mad-suite'); ?>
                </button>

                <button type="button" id="mad-refund-clear" class="button">
                    <span class="dashicons dashicons-dismiss"></span>
                    <?php esc_html_e('Clear Selection', 'mad-suite'); ?>
                </button>

                <?php if ($is_pending_refund && !empty($refund_data)) : ?>
                    <button type="button" id="mad-refund-cancel" class="button mad-refund-cancel-btn">
                        <span class="dashicons dashicons-undo"></span>
                        <?php esc_html_e('Cancel Pre-Refund', 'mad-suite'); ?>
                    </button>
                <?php endif; ?>

                <span class="mad-refund-status"></span>
            </div>

            <?php if ($is_pending_refund && !empty($refund_data)) : ?>
            <!-- Cancel Pre-Refund Modal -->
            <div id="mad-refund-cancel-modal" class="mad-refund-modal" style="display:none;">
                <div class="mad-refund-modal-overlay"></div>
                <div class="mad-refund-modal-content">
                    <h3><?php esc_html_e('Cancel Pre-Refund', 'mad-suite'); ?></h3>
                    <p><?php esc_html_e('This will remove all pre-refund data and revert the order to the selected status. A record will be saved in the order notes.', 'mad-suite'); ?></p>
                    <div class="mad-refund-modal-field">
                        <label for="mad-refund-new-status"><?php esc_html_e('Revert order to status:', 'mad-suite'); ?></label>
                        <select id="mad-refund-new-status">
                            <?php
                            $statuses = wc_get_order_statuses();
                            // Remove current pending-refund status from options
                            unset($statuses['wc-pending-refund']);
                            foreach ($statuses as $status_key => $status_label) :
                                $clean_key = str_replace('wc-', '', $status_key);
                            ?>
                                <option value="<?php echo esc_attr($clean_key); ?>" <?php selected($clean_key, 'processing'); ?>>
                                    <?php echo esc_html($status_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mad-refund-modal-actions">
                        <button type="button" id="mad-refund-cancel-confirm" class="button button-primary mad-refund-cancel-confirm-btn">
                            <?php esc_html_e('Confirm Cancellation', 'mad-suite'); ?>
                        </button>
                        <button type="button" id="mad-refund-cancel-dismiss" class="button">
                            <?php esc_html_e('Go Back', 'mad-suite'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Render PDF action buttons
     *
     * @param WC_Order $order Order object
     * @param array $refund_data Refund data
     */
    public function render_pdf_actions($order, $refund_data = null) {
        if (empty($refund_data) || empty($refund_data['items'])) {
            return;
        }

        // Check if PDF plugin is available
        $pdf_available = $this->module->is_pdf_plugin_active();

        ?>
        <div class="mad-refund-pdf-actions">
            <h4><?php esc_html_e('Generate Documents', 'mad-suite'); ?></h4>

            <?php if ($pdf_available) : ?>
                <a href="<?php echo esc_url($this->get_pdf_url($order, 'download')); ?>"
                   class="button"
                   target="_blank">
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e('Download Return Invoice PDF', 'mad-suite'); ?>
                </a>

                <a href="<?php echo esc_url($this->get_pdf_url($order, 'view')); ?>"
                   class="button"
                   target="_blank">
                    <span class="dashicons dashicons-visibility"></span>
                    <?php esc_html_e('Preview PDF', 'mad-suite'); ?>
                </a>
            <?php else : ?>
                <p class="description">
                    <span class="dashicons dashicons-warning"></span>
                    <?php
                    printf(
                        /* translators: %s: plugin name */
                        esc_html__('Install %s to generate PDF invoices.', 'mad-suite'),
                        '<strong>WooCommerce PDF Invoices & Packing Slips</strong>'
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get PDF generation URL
     *
     * @param WC_Order $order Order object
     * @param string $action Action type (download/view)
     * @return string URL
     */
    private function get_pdf_url($order, $action = 'download') {
        return wp_nonce_url(
            admin_url('admin-ajax.php?action=mad_refund_generate_pdf&order_id=' . $order->get_id() . '&output=' . $action),
            'mad_refund_pdf_' . $order->get_id()
        );
    }

    /**
     * Add order data section for HPOS
     *
     * @param WC_Order $order Order object
     */
    public function add_order_data_section($order) {
        // This is handled by the meta box in HPOS
    }

    /**
     * Add order actions to the order actions dropdown
     *
     * @param array $actions Order actions
     * @return array Modified actions
     */
    public function add_order_actions($actions) {
        global $theorder;

        if (!$theorder) {
            return $actions;
        }

        // Only show if order has pre-refund data
        $refund_data = $theorder->get_meta('_pending_refund_data');
        if (!empty($refund_data) && $this->module->is_pdf_plugin_active()) {
            $actions['generate_prerefund_pdf'] = __('Generate Pre-Refund PDF', 'mad-suite');
        }

        return $actions;
    }

    /**
     * Handle generate PDF order action
     *
     * @param WC_Order $order Order object
     */
    public function handle_generate_pdf_action($order) {
        // Redirect to PDF generation
        $pdf_url = $this->get_pdf_url($order, 'download');
        wp_redirect($pdf_url);
        exit;
    }

    /**
     * Get stored refund data for an order
     *
     * @param int $order_id Order ID
     * @return array|null Refund data or null
     */
    public function get_refund_data($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        return $order->get_meta('_pending_refund_data');
    }

    /**
     * Clear refund data for an order
     *
     * @param int $order_id Order ID
     * @return bool Success status
     */
    public function clear_refund_data($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $order->delete_meta_data('_pending_refund_data');
        $order->save();

        return true;
    }
}
