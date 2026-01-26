<?php
/**
 * PDF Integration with WP Overnight PDF Invoices
 *
 * Integrates pre-refund data with WP Overnight PDF Invoices & Packing Slips plugin.
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

class MAD_Refund_PDF_Integration {

    /**
     * Parent module reference
     *
     * @var object
     */
    private $module;

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
        // Check if WP Overnight plugin is active
        if (!$this->is_plugin_active()) {
            return;
        }

        // Register custom document type
        add_filter('wpo_wcpdf_document_types', [$this, 'register_document_type']);

        // Filter order items for our document type
        add_filter('wpo_wcpdf_order_items_data', [$this, 'filter_order_items'], 10, 3);

        // Filter totals for our document type
        add_filter('wpo_wcpdf_get_totals', [$this, 'filter_totals'], 10, 3);

        // Add custom data to document
        add_action('wpo_wcpdf_after_order_details', [$this, 'add_customs_notice'], 10, 2);

        // Add document type settings
        add_filter('wpo_wcpdf_document_settings_tabs', [$this, 'add_settings_tab'], 10, 2);

        // Filter document title
        add_filter('wpo_wcpdf_document_title', [$this, 'filter_document_title'], 10, 2);

        // Add button to generate PDF in order actions
        add_filter('wpo_wcpdf_listing_actions', [$this, 'add_listing_action'], 10, 2);
        add_filter('wpo_wcpdf_myaccount_actions', [$this, 'filter_myaccount_actions'], 10, 2);

        // AJAX handler for PDF generation
        add_action('wp_ajax_mad_refund_generate_pdf', [$this, 'ajax_generate_pdf']);

        // Customize PDF output
        add_filter('wpo_wcpdf_template_file', [$this, 'custom_template_file'], 10, 3);

        // Add reference to original order
        add_action('wpo_wcpdf_before_order_details', [$this, 'add_order_reference'], 10, 2);

        // Filter document number
        add_filter('wpo_wcpdf_document_number', [$this, 'filter_document_number'], 10, 4);
    }

    /**
     * Check if WP Overnight PDF plugin is active
     *
     * @return bool
     */
    public function is_plugin_active() {
        return class_exists('WPO_WCPDF') || class_exists('WooCommerce_PDF_Invoices');
    }

    /**
     * Register custom document type
     *
     * @param array $types Document types
     * @return array Modified types
     */
    public function register_document_type($types) {
        $types['return-invoice'] = [
            'class' => 'MAD_Refund_PDF_Document',
            'title' => __('Return Invoice', 'mad-suite'),
        ];

        return $types;
    }

    /**
     * Filter order items for return invoice
     *
     * @param array $items Order items
     * @param WC_Order $order Order object
     * @param string $document_type Document type
     * @return array Filtered items
     */
    public function filter_order_items($items, $order, $document_type) {
        // Only filter for our document type
        if ($document_type !== 'return-invoice') {
            return $items;
        }

        $refund_data = $order->get_meta('_pending_refund_data');

        // If no refund data, return empty
        if (empty($refund_data['items'])) {
            return [];
        }

        $filtered_items = [];

        foreach ($refund_data['items'] as $item_id => $item_data) {
            // Find matching item in original items
            foreach ($items as $key => $item) {
                if (isset($item['item_id']) && $item['item_id'] == $item_id) {
                    // Modify quantities and totals
                    $original_qty = $item_data['original_quantity'];
                    $refund_qty = $item_data['quantity'];
                    $ratio = $refund_qty / max(1, $original_qty);

                    $item['quantity'] = $refund_qty;

                    // Recalculate prices
                    if (isset($item['line_total'])) {
                        $item['line_total'] = wc_price($item_data['subtotal'], ['currency' => $order->get_currency()]);
                    }
                    if (isset($item['line_tax'])) {
                        $item['line_tax'] = wc_price($item_data['tax'], ['currency' => $order->get_currency()]);
                    }

                    // Add return info
                    $item['meta'][] = [
                        'label' => __('Return Quantity', 'mad-suite'),
                        'value' => sprintf('%d / %d', $refund_qty, $original_qty),
                    ];

                    $filtered_items[$key] = $item;
                    break;
                }
            }
        }

        return $filtered_items;
    }

    /**
     * Filter totals for return invoice
     *
     * @param array $totals Document totals
     * @param WC_Order $order Order object
     * @param string $document_type Document type
     * @return array Filtered totals
     */
    public function filter_totals($totals, $order, $document_type) {
        if ($document_type !== 'return-invoice') {
            return $totals;
        }

        $refund_data = $order->get_meta('_pending_refund_data');

        if (empty($refund_data)) {
            return $totals;
        }

        $currency = $order->get_currency();
        $new_totals = [];

        // Subtotal
        $new_totals['subtotal'] = [
            'label' => __('Subtotal', 'mad-suite'),
            'value' => wc_price($refund_data['subtotal'], ['currency' => $currency]),
        ];

        // Tax
        if (!empty($refund_data['tax']) && $refund_data['tax'] > 0) {
            $new_totals['tax'] = [
                'label' => __('Tax', 'mad-suite'),
                'value' => wc_price($refund_data['tax'], ['currency' => $currency]),
            ];
        }

        // Shipping
        if (!empty($refund_data['include_shipping']) && !empty($refund_data['shipping'])) {
            $shipping_total = $refund_data['shipping'] + ($refund_data['shipping_tax'] ?? 0);
            $new_totals['shipping'] = [
                'label' => __('Shipping', 'mad-suite'),
                'value' => wc_price($shipping_total, ['currency' => $currency]),
            ];
        }

        // Total
        $new_totals['total'] = [
            'label' => __('Total to Refund', 'mad-suite'),
            'value' => '<strong>' . wc_price($refund_data['total'], ['currency' => $currency]) . '</strong>',
        ];

        return $new_totals;
    }

    /**
     * Add customs notice to document
     *
     * @param string $document_type Document type
     * @param WC_Order $order Order object
     */
    public function add_customs_notice($document_type, $order) {
        if ($document_type !== 'return-invoice') {
            return;
        }

        $settings = $this->module->get_settings();
        $customs_text = $settings['pdf_customs_text'] ?? '';

        if (empty($customs_text)) {
            return;
        }

        ?>
        <div class="customs-notice" style="margin-top: 20px; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">
            <h3 style="margin: 0 0 10px; font-size: 14px;"><?php esc_html_e('Important Notice', 'mad-suite'); ?></h3>
            <p style="margin: 0; font-size: 12px;"><?php echo wp_kses_post($customs_text); ?></p>
        </div>
        <?php
    }

    /**
     * Add order reference section
     *
     * @param string $document_type Document type
     * @param WC_Order $order Order object
     */
    public function add_order_reference($document_type, $order) {
        if ($document_type !== 'return-invoice') {
            return;
        }

        ?>
        <div class="order-reference" style="margin-bottom: 20px; padding: 10px; background: #f5f5f5;">
            <p style="margin: 0;">
                <strong><?php esc_html_e('Reference Order:', 'mad-suite'); ?></strong>
                #<?php echo esc_html($order->get_order_number()); ?>
                <br>
                <strong><?php esc_html_e('Original Order Date:', 'mad-suite'); ?></strong>
                <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Filter document title
     *
     * @param string $title Document title
     * @param object $document Document object
     * @return string Modified title
     */
    public function filter_document_title($title, $document) {
        if (!$document || $document->get_type() !== 'return-invoice') {
            return $title;
        }

        $settings = $this->module->get_settings();
        return !empty($settings['pdf_title']) ? $settings['pdf_title'] : __('Return Invoice', 'mad-suite');
    }

    /**
     * Filter document number
     *
     * @param string $number Document number
     * @param string $number_type Number type
     * @param object $document Document object
     * @param WC_Order $order Order object
     * @return string Modified number
     */
    public function filter_document_number($number, $number_type, $document, $order) {
        if (!$document || $document->get_type() !== 'return-invoice') {
            return $number;
        }

        return sprintf('RI-%s', $order->get_order_number());
    }

    /**
     * Add listing action for return invoice
     *
     * @param array $actions Listing actions
     * @param WC_Order $order Order object
     * @return array Modified actions
     */
    public function add_listing_action($actions, $order) {
        $refund_data = $order->get_meta('_pending_refund_data');

        if (!empty($refund_data)) {
            $actions['return-invoice'] = [
                'url' => $this->get_pdf_url($order),
                'alt' => __('Return Invoice', 'mad-suite'),
                'class' => 'return-invoice',
            ];
        }

        return $actions;
    }

    /**
     * Filter My Account actions
     *
     * @param array $actions Account actions
     * @param WC_Order $order Order object
     * @return array Modified actions
     */
    public function filter_myaccount_actions($actions, $order) {
        // Don't show return invoice to customers
        unset($actions['return-invoice']);
        return $actions;
    }

    /**
     * Get PDF generation URL
     *
     * @param WC_Order $order Order object
     * @return string URL
     */
    public function get_pdf_url($order) {
        if (function_exists('WPO_WCPDF')) {
            $document = wcpdf_get_document('return-invoice', $order);
            if ($document) {
                return $document->get_pdf_link();
            }
        }

        // Fallback URL
        return wp_nonce_url(
            admin_url('admin-ajax.php?action=mad_refund_generate_pdf&order_id=' . $order->get_id()),
            'mad_refund_pdf_' . $order->get_id()
        );
    }

    /**
     * AJAX handler for PDF generation
     */
    public function ajax_generate_pdf() {
        $order_id = absint($_GET['order_id'] ?? 0);

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'mad_refund_pdf_' . $order_id)) {
            wp_die(__('Security check failed', 'mad-suite'));
        }

        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Permission denied', 'mad-suite'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Order not found', 'mad-suite'));
        }

        $refund_data = $order->get_meta('_pending_refund_data');
        if (empty($refund_data)) {
            wp_die(__('No pre-refund data found for this order', 'mad-suite'));
        }

        // Generate PDF using WP Overnight if available
        if (function_exists('WPO_WCPDF')) {
            try {
                $document = wcpdf_get_document('return-invoice', $order);
                if ($document) {
                    $output = isset($_GET['output']) && $_GET['output'] === 'view' ? 'inline' : 'download';
                    $document->output_pdf($output);
                    exit;
                }
            } catch (Exception $e) {
                $this->module->get_logger()->error('PDF generation failed: ' . $e->getMessage(), [
                    'order_id' => $order_id,
                ]);
            }
        }

        // Fallback: Generate simple HTML/PDF
        $this->generate_fallback_pdf($order, $refund_data);
    }

    /**
     * Generate fallback PDF if WP Overnight is not available
     *
     * @param WC_Order $order Order object
     * @param array $refund_data Refund data
     */
    private function generate_fallback_pdf($order, $refund_data) {
        $settings = $this->module->get_settings();

        // Set headers for HTML display
        header('Content-Type: text/html; charset=utf-8');

        include dirname(__DIR__) . '/templates/pdf-return-invoice.php';
        exit;
    }

    /**
     * Add settings tab
     *
     * @param array $tabs Settings tabs
     * @param string $document_type Document type
     * @return array Modified tabs
     */
    public function add_settings_tab($tabs, $document_type) {
        if ($document_type === 'return-invoice') {
            $tabs['mad_refund'] = __('Return Invoice Settings', 'mad-suite');
        }
        return $tabs;
    }

    /**
     * Custom template file
     *
     * @param string $file Template file
     * @param string $template Template name
     * @param object $document Document object
     * @return string Modified file path
     */
    public function custom_template_file($file, $template, $document) {
        if (!$document || $document->get_type() !== 'return-invoice') {
            return $file;
        }

        // Check for custom template in our plugin
        $custom_file = dirname(__DIR__) . '/templates/' . $template;
        if (file_exists($custom_file)) {
            return $custom_file;
        }

        return $file;
    }
}
