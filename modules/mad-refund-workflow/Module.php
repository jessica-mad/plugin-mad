<?php
/**
 * MAD Refund Workflow Module
 *
 * Pre-refund invoice system for generating return documentation
 * before processing actual WooCommerce refunds.
 *
 * @package MAD_Suite
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

return new class($core) implements MAD_Suite_Module {

    private $core;
    private $option_key;
    private $order_status;
    private $meta_box;
    private $pdf_integration;
    private $email;
    private $logger;

    public function __construct($core) {
        $this->core = $core;
        $this->option_key = MAD_Suite_Core::option_key($this->slug());
    }

    public function slug() {
        return 'mad-refund-workflow';
    }

    public function title() {
        return __('Pre-Refund Invoice System', 'mad-suite');
    }

    public function menu_label() {
        return __('Pre-Refund', 'mad-suite');
    }

    public function menu_slug() {
        return 'mad-refund-workflow';
    }

    public function description() {
        return __('Generate return invoices for customs/transport before processing actual WooCommerce refunds.', 'mad-suite');
    }

    public function required_plugins() {
        return [
            'WooCommerce' => 'woocommerce/woocommerce.php',
        ];
    }

    /**
     * Initialize module - public hooks (front + admin)
     */
    public function init() {
        // Load dependencies
        $this->load_classes();

        // Initialize components
        $this->order_status = new MAD_Refund_Order_Status($this);
        $this->meta_box = new MAD_Refund_Meta_Box($this);
        $this->pdf_integration = new MAD_Refund_PDF_Integration($this);
        $this->email = new MAD_Refund_Email($this);
        $this->logger = new MAD_Refund_Logger();

        // Register custom order status
        $this->order_status->init();

        // Register meta box
        $this->meta_box->init();

        // Register PDF integration
        $this->pdf_integration->init();

        // Register email
        $this->email->init();

        // AJAX handlers
        add_action('wp_ajax_mad_refund_save_data', [$this, 'ajax_save_refund_data']);
        add_action('wp_ajax_mad_refund_calculate_totals', [$this, 'ajax_calculate_totals']);
        add_action('wp_ajax_mad_refund_get_order_items', [$this, 'ajax_get_order_items']);

        // Admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Admin initialization - Settings API registration
     */
    public function admin_init() {
        register_setting(
            $this->option_key . '_group',
            $this->option_key,
            [$this, 'sanitize_settings']
        );

        // General Settings Section
        add_settings_section(
            'mad_refund_general',
            __('General Settings', 'mad-suite'),
            [$this, 'render_general_section'],
            $this->menu_slug()
        );

        add_settings_field(
            'enabled',
            __('Enable Module', 'mad-suite'),
            [$this, 'field_enabled'],
            $this->menu_slug(),
            'mad_refund_general'
        );

        add_settings_field(
            'status_name',
            __('Custom Status Name', 'mad-suite'),
            [$this, 'field_status_name'],
            $this->menu_slug(),
            'mad_refund_general'
        );

        add_settings_field(
            'status_color',
            __('Status Color', 'mad-suite'),
            [$this, 'field_status_color'],
            $this->menu_slug(),
            'mad_refund_general'
        );

        // Email Settings Section
        add_settings_section(
            'mad_refund_email',
            __('Email Notifications', 'mad-suite'),
            [$this, 'render_email_section'],
            $this->menu_slug()
        );

        add_settings_field(
            'email_enabled',
            __('Enable Email Notification', 'mad-suite'),
            [$this, 'field_email_enabled'],
            $this->menu_slug(),
            'mad_refund_email'
        );

        add_settings_field(
            'email_subject',
            __('Email Subject', 'mad-suite'),
            [$this, 'field_email_subject'],
            $this->menu_slug(),
            'mad_refund_email'
        );

        add_settings_field(
            'email_heading',
            __('Email Heading', 'mad-suite'),
            [$this, 'field_email_heading'],
            $this->menu_slug(),
            'mad_refund_email'
        );

        add_settings_field(
            'email_content',
            __('Email Content', 'mad-suite'),
            [$this, 'field_email_content'],
            $this->menu_slug(),
            'mad_refund_email'
        );

        // PDF Settings Section
        add_settings_section(
            'mad_refund_pdf',
            __('PDF Invoice Settings', 'mad-suite'),
            [$this, 'render_pdf_section'],
            $this->menu_slug()
        );

        add_settings_field(
            'pdf_document_type',
            __('Document Type', 'mad-suite'),
            [$this, 'field_pdf_document_type'],
            $this->menu_slug(),
            'mad_refund_pdf'
        );

        add_settings_field(
            'pdf_title',
            __('Document Title', 'mad-suite'),
            [$this, 'field_pdf_title'],
            $this->menu_slug(),
            'mad_refund_pdf'
        );

        add_settings_field(
            'pdf_customs_text',
            __('Customs/Transport Text', 'mad-suite'),
            [$this, 'field_pdf_customs_text'],
            $this->menu_slug(),
            'mad_refund_pdf'
        );

        add_settings_field(
            'pdf_footer_text',
            __('Footer Text', 'mad-suite'),
            [$this, 'field_pdf_footer_text'],
            $this->menu_slug(),
            'mad_refund_pdf'
        );
    }

    /**
     * Load class files
     */
    private function load_classes() {
        require_once __DIR__ . '/classes/class-order-status.php';
        require_once __DIR__ . '/classes/class-meta-box.php';
        require_once __DIR__ . '/classes/class-pdf-integration.php';
        require_once __DIR__ . '/classes/class-email.php';
        require_once __DIR__ . '/classes/class-logger.php';
        require_once __DIR__ . '/classes/class-calculations.php';
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        global $post;

        // Settings page assets
        if (strpos($hook, $this->menu_slug()) !== false) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');

            wp_enqueue_style(
                'mad-refund-settings',
                plugin_dir_url(__FILE__) . 'assets/css/settings.css',
                [],
                '1.0.0'
            );
        }

        // Order edit page assets
        $screen = get_current_screen();
        if ($screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'])) {
            wp_enqueue_style(
                'mad-refund-admin',
                plugin_dir_url(__FILE__) . 'assets/css/admin.css',
                [],
                '1.0.0'
            );

            wp_enqueue_script(
                'mad-refund-admin',
                plugin_dir_url(__FILE__) . 'assets/js/admin.js',
                ['jquery'],
                '1.0.0',
                true
            );

            $order_id = 0;
            if (isset($_GET['id'])) {
                $order_id = absint($_GET['id']);
            } elseif ($post && $post->ID) {
                $order_id = $post->ID;
            }

            wp_localize_script('mad-refund-admin', 'madRefundL10n', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mad_refund_nonce'),
                'orderId' => $order_id,
                'strings' => [
                    'saving' => __('Saving...', 'mad-suite'),
                    'saved' => __('Data saved successfully', 'mad-suite'),
                    'error' => __('Error saving data', 'mad-suite'),
                    'confirmClear' => __('Are you sure you want to clear all refund data?', 'mad-suite'),
                    'maxQuantity' => __('Maximum quantity exceeded', 'mad-suite'),
                    'calculating' => __('Calculating...', 'mad-suite'),
                    'includeShipping' => __('Include shipping', 'mad-suite'),
                    'excludeShipping' => __('Exclude shipping', 'mad-suite'),
                ],
                'currency' => get_woocommerce_currency_symbol(),
                'decimals' => wc_get_price_decimals(),
                'decimalSep' => wc_get_price_decimal_separator(),
                'thousandSep' => wc_get_price_thousand_separator(),
            ]);
        }
    }

    /**
     * Get module settings
     */
    public function get_settings() {
        $defaults = [
            'enabled' => 1,
            'status_name' => __('Pending Refund', 'mad-suite'),
            'status_color' => '#ffba00',
            'email_enabled' => 1,
            'email_subject' => __('Your refund request #{order_number} is being processed', 'mad-suite'),
            'email_heading' => __('Refund Request Received', 'mad-suite'),
            'email_content' => __("Hello {customer_name},\n\nWe have received your refund request for order #{order_number}.\n\nWe are preparing the return documentation. You will receive further instructions shortly.\n\nThank you for your patience.", 'mad-suite'),
            'pdf_document_type' => 'credit-note',
            'pdf_title' => __('Return Invoice', 'mad-suite'),
            'pdf_customs_text' => __('This document is issued for customs and transport purposes only. It does not represent a financial refund until processed.', 'mad-suite'),
            'pdf_footer_text' => '',
        ];

        $settings = get_option($this->option_key, []);
        return wp_parse_args($settings, $defaults);
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];

        $sanitized['enabled'] = isset($input['enabled']) ? 1 : 0;
        $sanitized['status_name'] = sanitize_text_field($input['status_name'] ?? '');
        $sanitized['status_color'] = sanitize_hex_color($input['status_color'] ?? '#ffba00');
        $sanitized['email_enabled'] = isset($input['email_enabled']) ? 1 : 0;
        $sanitized['email_subject'] = sanitize_text_field($input['email_subject'] ?? '');
        $sanitized['email_heading'] = sanitize_text_field($input['email_heading'] ?? '');
        $sanitized['email_content'] = wp_kses_post($input['email_content'] ?? '');
        $sanitized['pdf_document_type'] = sanitize_key($input['pdf_document_type'] ?? 'credit-note');
        $sanitized['pdf_title'] = sanitize_text_field($input['pdf_title'] ?? '');
        $sanitized['pdf_customs_text'] = wp_kses_post($input['pdf_customs_text'] ?? '');
        $sanitized['pdf_footer_text'] = wp_kses_post($input['pdf_footer_text'] ?? '');

        return $sanitized;
    }

    /**
     * AJAX: Save refund data
     */
    public function ajax_save_refund_data() {
        check_ajax_referer('mad_refund_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permission denied', 'mad-suite')]);
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'mad-suite')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'mad-suite')]);
        }

        // Parse items data
        $items_raw = $_POST['items'] ?? [];
        $include_shipping = isset($_POST['include_shipping']) && $_POST['include_shipping'] === 'true';
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        $items = [];
        $calculator = new MAD_Refund_Calculations();

        foreach ($items_raw as $item_id => $item_data) {
            $quantity = absint($item_data['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $order_item = $order->get_item($item_id);
            if (!$order_item) {
                continue;
            }

            // Validate quantity doesn't exceed original
            $original_qty = $order_item->get_quantity();
            if ($quantity > $original_qty) {
                $quantity = $original_qty;
            }

            $product_id = $order_item->get_product_id();
            $variation_id = $order_item->get_variation_id();

            $line_subtotal = $order_item->get_subtotal();
            $line_tax = $order_item->get_subtotal_tax();

            // Calculate proportional amounts
            $ratio = $quantity / $original_qty;
            $refund_subtotal = $line_subtotal * $ratio;
            $refund_tax = $line_tax * $ratio;

            $items[$item_id] = [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $quantity,
                'original_quantity' => $original_qty,
                'subtotal' => round($refund_subtotal, wc_get_price_decimals()),
                'tax' => round($refund_tax, wc_get_price_decimals()),
                'name' => $order_item->get_name(),
                'sku' => $order_item->get_product() ? $order_item->get_product()->get_sku() : '',
            ];
        }

        // Calculate shipping if included
        $shipping_total = 0;
        $shipping_tax = 0;
        if ($include_shipping) {
            foreach ($order->get_shipping_methods() as $shipping_item) {
                $shipping_total += $shipping_item->get_total();
                $shipping_tax += $shipping_item->get_total_tax();
            }
        }

        // Calculate totals
        $items_subtotal = array_sum(array_column($items, 'subtotal'));
        $items_tax = array_sum(array_column($items, 'tax'));
        $total = $items_subtotal + $items_tax + $shipping_total + $shipping_tax;

        $refund_data = [
            'items' => $items,
            'shipping' => round($shipping_total, wc_get_price_decimals()),
            'shipping_tax' => round($shipping_tax, wc_get_price_decimals()),
            'include_shipping' => $include_shipping,
            'subtotal' => round($items_subtotal, wc_get_price_decimals()),
            'tax' => round($items_tax + $shipping_tax, wc_get_price_decimals()),
            'total' => round($total, wc_get_price_decimals()),
            'created_date' => current_time('timestamp'),
            'created_by' => get_current_user_id(),
            'notes' => $notes,
        ];

        // Save to order meta
        $order->update_meta_data('_pending_refund_data', $refund_data);
        $order->save();

        // Log the action
        $this->logger->log(sprintf(
            'Refund data saved for order #%d by user #%d. Total: %s',
            $order_id,
            get_current_user_id(),
            wc_price($total)
        ));

        // Add order note
        $order->add_order_note(sprintf(
            __('Pre-refund data saved. Total: %s (Items: %s, Tax: %s, Shipping: %s)', 'mad-suite'),
            wc_price($total),
            wc_price($items_subtotal),
            wc_price($items_tax + $shipping_tax),
            wc_price($shipping_total + $shipping_tax)
        ));

        wp_send_json_success([
            'message' => __('Refund data saved successfully', 'mad-suite'),
            'data' => $refund_data,
        ]);
    }

    /**
     * AJAX: Calculate totals
     */
    public function ajax_calculate_totals() {
        check_ajax_referer('mad_refund_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permission denied', 'mad-suite')]);
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'mad-suite')]);
        }

        $items_raw = $_POST['items'] ?? [];
        $include_shipping = isset($_POST['include_shipping']) && $_POST['include_shipping'] === 'true';

        $subtotal = 0;
        $tax = 0;
        $item_details = [];

        foreach ($items_raw as $item_id => $item_data) {
            $quantity = absint($item_data['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $order_item = $order->get_item($item_id);
            if (!$order_item) {
                continue;
            }

            $original_qty = $order_item->get_quantity();
            $line_subtotal = $order_item->get_subtotal();
            $line_tax = $order_item->get_subtotal_tax();

            $ratio = $quantity / $original_qty;
            $item_subtotal = $line_subtotal * $ratio;
            $item_tax = $line_tax * $ratio;

            $subtotal += $item_subtotal;
            $tax += $item_tax;

            $item_details[$item_id] = [
                'subtotal' => round($item_subtotal, wc_get_price_decimals()),
                'tax' => round($item_tax, wc_get_price_decimals()),
                'line_total' => round($item_subtotal + $item_tax, wc_get_price_decimals()),
            ];
        }

        $shipping = 0;
        $shipping_tax = 0;
        if ($include_shipping) {
            foreach ($order->get_shipping_methods() as $shipping_item) {
                $shipping += $shipping_item->get_total();
                $shipping_tax += $shipping_item->get_total_tax();
            }
        }

        $total = $subtotal + $tax + $shipping + $shipping_tax;

        wp_send_json_success([
            'subtotal' => round($subtotal, wc_get_price_decimals()),
            'tax' => round($tax + $shipping_tax, wc_get_price_decimals()),
            'shipping' => round($shipping, wc_get_price_decimals()),
            'shipping_tax' => round($shipping_tax, wc_get_price_decimals()),
            'total' => round($total, wc_get_price_decimals()),
            'items' => $item_details,
            'formatted' => [
                'subtotal' => wc_price($subtotal),
                'tax' => wc_price($tax + $shipping_tax),
                'shipping' => wc_price($shipping + $shipping_tax),
                'total' => wc_price($total),
            ],
        ]);
    }

    /**
     * AJAX: Get order items
     */
    public function ajax_get_order_items() {
        check_ajax_referer('mad_refund_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permission denied', 'mad-suite')]);
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'mad-suite')]);
        }

        $items = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $items[$item_id] = [
                'name' => $item->get_name(),
                'sku' => $product ? $product->get_sku() : '',
                'quantity' => $item->get_quantity(),
                'subtotal' => $item->get_subtotal(),
                'tax' => $item->get_subtotal_tax(),
                'total' => $item->get_subtotal() + $item->get_subtotal_tax(),
                'unit_price' => $item->get_subtotal() / max(1, $item->get_quantity()),
            ];
        }

        $shipping_total = 0;
        $shipping_tax = 0;
        foreach ($order->get_shipping_methods() as $shipping_item) {
            $shipping_total += $shipping_item->get_total();
            $shipping_tax += $shipping_item->get_total_tax();
        }

        wp_send_json_success([
            'items' => $items,
            'shipping' => [
                'total' => $shipping_total,
                'tax' => $shipping_tax,
            ],
        ]);
    }

    /**
     * Settings field callbacks
     */
    public function render_general_section() {
        echo '<p>' . esc_html__('Configure the pre-refund workflow general settings.', 'mad-suite') . '</p>';
    }

    public function render_email_section() {
        echo '<p>' . esc_html__('Configure email notifications sent to customers when a pre-refund is created.', 'mad-suite') . '</p>';
    }

    public function render_pdf_section() {
        echo '<p>' . esc_html__('Configure the PDF invoice settings for return documentation. Requires WP Overnight PDF Invoices & Packing Slips plugin.', 'mad-suite') . '</p>';
    }

    public function field_enabled() {
        $settings = $this->get_settings();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[enabled]" value="1"
                   <?php checked($settings['enabled'], 1); ?>>
            <?php esc_html_e('Enable the pre-refund workflow module', 'mad-suite'); ?>
        </label>
        <?php
    }

    public function field_status_name() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[status_name]"
               value="<?php echo esc_attr($settings['status_name']); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('The name displayed for the custom order status.', 'mad-suite'); ?></p>
        <?php
    }

    public function field_status_color() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[status_color]"
               value="<?php echo esc_attr($settings['status_color']); ?>" class="mad-color-picker">
        <p class="description"><?php esc_html_e('Color used to highlight the status in the orders list.', 'mad-suite'); ?></p>
        <?php
    }

    public function field_email_enabled() {
        $settings = $this->get_settings();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[email_enabled]" value="1"
                   <?php checked($settings['email_enabled'], 1); ?>>
            <?php esc_html_e('Send email notification when order status changes to pending refund', 'mad-suite'); ?>
        </label>
        <?php
    }

    public function field_email_subject() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[email_subject]"
               value="<?php echo esc_attr($settings['email_subject']); ?>" class="large-text">
        <p class="description">
            <?php esc_html_e('Available placeholders: {order_number}, {customer_name}, {site_title}', 'mad-suite'); ?>
        </p>
        <?php
    }

    public function field_email_heading() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[email_heading]"
               value="<?php echo esc_attr($settings['email_heading']); ?>" class="large-text">
        <?php
    }

    public function field_email_content() {
        $settings = $this->get_settings();
        ?>
        <textarea name="<?php echo esc_attr($this->option_key); ?>[email_content]"
                  rows="8" class="large-text"><?php echo esc_textarea($settings['email_content']); ?></textarea>
        <p class="description">
            <?php esc_html_e('Available placeholders: {order_number}, {customer_name}, {order_date}, {refund_items}, {refund_total}, {site_title}', 'mad-suite'); ?>
        </p>
        <?php
    }

    public function field_pdf_document_type() {
        $settings = $this->get_settings();
        ?>
        <select name="<?php echo esc_attr($this->option_key); ?>[pdf_document_type]">
            <option value="credit-note" <?php selected($settings['pdf_document_type'], 'credit-note'); ?>>
                <?php esc_html_e('Credit Note (Recommended)', 'mad-suite'); ?>
            </option>
            <option value="return-invoice" <?php selected($settings['pdf_document_type'], 'return-invoice'); ?>>
                <?php esc_html_e('Custom Return Invoice', 'mad-suite'); ?>
            </option>
        </select>
        <p class="description">
            <?php esc_html_e('Select the document type to generate. Credit Note uses WP Overnight\'s built-in format.', 'mad-suite'); ?>
        </p>
        <?php
    }

    public function field_pdf_title() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[pdf_title]"
               value="<?php echo esc_attr($settings['pdf_title']); ?>" class="regular-text">
        <p class="description"><?php esc_html_e('Title displayed on the PDF document.', 'mad-suite'); ?></p>
        <?php
    }

    public function field_pdf_customs_text() {
        $settings = $this->get_settings();
        ?>
        <textarea name="<?php echo esc_attr($this->option_key); ?>[pdf_customs_text]"
                  rows="4" class="large-text"><?php echo esc_textarea($settings['pdf_customs_text']); ?></textarea>
        <p class="description">
            <?php esc_html_e('Additional text for customs/transport purposes displayed on the PDF.', 'mad-suite'); ?>
        </p>
        <?php
    }

    public function field_pdf_footer_text() {
        $settings = $this->get_settings();
        ?>
        <textarea name="<?php echo esc_attr($this->option_key); ?>[pdf_footer_text]"
                  rows="3" class="large-text"><?php echo esc_textarea($settings['pdf_footer_text']); ?></textarea>
        <p class="description">
            <?php esc_html_e('Footer text displayed at the bottom of the PDF.', 'mad-suite'); ?>
        </p>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            return;
        }

        $settings = $this->get_settings();
        include __DIR__ . '/views/settings.php';
    }

    /**
     * Get the logger instance
     */
    public function get_logger() {
        return $this->logger;
    }

    /**
     * Get order status class instance
     */
    public function get_order_status() {
        return $this->order_status;
    }

    /**
     * Check if WP Overnight PDF plugin is active
     */
    public function is_pdf_plugin_active() {
        return class_exists('WPO_WCPDF')
            || function_exists('WPO_WCPDF')
            || class_exists('WooCommerce_PDF_Invoices');
    }
};
