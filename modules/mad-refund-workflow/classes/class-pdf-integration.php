<?php
/**
 * PDF Integration with WP Overnight PDF Invoices
 *
 * Integrates pre-refund data with WP Overnight PDF Invoices & Packing Slips plugin.
 * Uses a simpler approach: intercepts Credit Note generation when order has pre-refund data.
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
     * Flag to track if we're generating a pre-refund document
     *
     * @var bool
     */
    private $is_prerefund_document = false;

    /**
     * Current order being processed
     *
     * @var WC_Order|null
     */
    private $current_order = null;

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
        // AJAX handler for PDF generation (our own endpoint)
        add_action('wp_ajax_mad_refund_generate_pdf', [$this, 'ajax_generate_pdf']);

        // Check if WP Overnight plugin is active for additional integration
        if ($this->is_plugin_active()) {
            // Add our document to the list of bulk actions in orders
            add_filter('wpo_wcpdf_bulk_actions', [$this, 'add_bulk_action']);

            // Add button in order list (WP Overnight style)
            add_filter('wpo_wcpdf_listing_actions', [$this, 'add_listing_action'], 20, 2);

            // Register our document type properly
            add_filter('wpo_wcpdf_document_classes', [$this, 'register_document_class']);

            // Intercept document data when generating our document
            add_action('wpo_wcpdf_init_document', [$this, 'init_document'], 10, 2);

            // Filter items for our document
            add_filter('wpo_wcpdf_order_items_data', [$this, 'filter_order_items'], 10, 3);

            // Add extra content to document
            add_action('wpo_wcpdf_after_order_details', [$this, 'add_extra_content'], 10, 2);
            add_action('wpo_wcpdf_before_order_details', [$this, 'add_prerefund_header'], 10, 2);

            // Filter document title
            add_filter('wpo_wcpdf_document_title', [$this, 'filter_document_title'], 10, 2);
        }
    }

    /**
     * Check if WP Overnight PDF plugin is active
     *
     * @return bool
     */
    public function is_plugin_active() {
        return class_exists('WPO_WCPDF') || function_exists('WPO_WCPDF');
    }

    /**
     * Register our document class
     *
     * @param array $classes Document classes
     * @return array Modified classes
     */
    public function register_document_class($classes) {
        // We'll use credit-note as base but customize it
        return $classes;
    }

    /**
     * Add bulk action for pre-refund invoice
     *
     * @param array $actions Bulk actions
     * @return array Modified actions
     */
    public function add_bulk_action($actions) {
        $actions['prerefund-invoice'] = __('Pre-Refund Invoice (PDF)', 'mad-suite');
        return $actions;
    }

    /**
     * Add listing action button
     *
     * @param array $actions Existing actions
     * @param WC_Order $order Order object
     * @return array Modified actions
     */
    public function add_listing_action($actions, $order) {
        // Only show if order has pre-refund data
        $refund_data = $order->get_meta('_pending_refund_data');

        if (!empty($refund_data) && !empty($refund_data['items'])) {
            $actions['prerefund-invoice'] = [
                'url'   => $this->get_pdf_url($order),
                'alt'   => __('Pre-Refund Invoice', 'mad-suite'),
                'class' => 'prerefund-invoice',
                'target' => '_blank',
            ];
        }

        return $actions;
    }

    /**
     * Initialize document - detect if it's our document type
     *
     * @param object $document Document object
     * @param string $type Document type
     */
    public function init_document($document, $type) {
        // Reset flag
        $this->is_prerefund_document = false;
        $this->current_order = null;

        // Check if this is a pre-refund document request
        if (isset($_REQUEST['prerefund']) && $_REQUEST['prerefund'] === '1') {
            $this->is_prerefund_document = true;

            if ($document && method_exists($document, 'get_order')) {
                $this->current_order = $document->get_order();
            }
        }
    }

    /**
     * Filter order items for pre-refund document
     *
     * @param array $items Order items data
     * @param object $document Document object
     * @param string $context Context
     * @return array Filtered items
     */
    public function filter_order_items($items, $document, $context = '') {
        // Only filter if we're generating a pre-refund document
        if (!$this->is_prerefund_document) {
            return $items;
        }

        $order = null;
        if ($document && method_exists($document, 'get_order')) {
            $order = $document->get_order();
        }

        if (!$order) {
            return $items;
        }

        $refund_data = $order->get_meta('_pending_refund_data');

        if (empty($refund_data) || empty($refund_data['items'])) {
            return $items;
        }

        // Filter to only include pre-refund items with correct quantities
        $filtered_items = [];

        foreach ($items as $item_id => $item) {
            // Check if this item is in our pre-refund data
            $prerefund_item = null;
            foreach ($refund_data['items'] as $pr_item_id => $pr_item) {
                // Match by item_id
                if (isset($item['item_id']) && $item['item_id'] == $pr_item_id) {
                    $prerefund_item = $pr_item;
                    break;
                }
            }

            if ($prerefund_item) {
                // Modify the item with pre-refund quantities
                $item['quantity'] = $prerefund_item['quantity'];

                // Recalculate totals
                $item['line_total'] = wc_price($prerefund_item['subtotal'], ['currency' => $order->get_currency()]);
                $item['line_tax'] = wc_price($prerefund_item['tax'], ['currency' => $order->get_currency()]);

                // Add note about return quantity
                if (!isset($item['meta'])) {
                    $item['meta'] = [];
                }
                $item['meta']['return_qty'] = [
                    'label' => __('Return', 'mad-suite'),
                    'value' => sprintf('%d / %d', $prerefund_item['quantity'], $prerefund_item['original_quantity']),
                ];

                $filtered_items[$item_id] = $item;
            }
        }

        return $filtered_items;
    }

    /**
     * Add pre-refund header to document
     *
     * @param string $document_type Document type
     * @param object $order Order object
     */
    public function add_prerefund_header($document_type, $order) {
        if (!$this->is_prerefund_document) {
            return;
        }

        $refund_data = $order->get_meta('_pending_refund_data');
        if (empty($refund_data)) {
            return;
        }

        ?>
        <div class="prerefund-header" style="background: #f8f9fa; border: 2px solid #dee2e6; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
            <h2 style="margin: 0 0 10px; color: #495057; font-size: 18px;">
                <?php echo esc_html($this->module->get_settings()['pdf_title'] ?? __('Pre-Refund Invoice', 'mad-suite')); ?>
            </h2>
            <p style="margin: 5px 0; color: #6c757d;">
                <strong><?php esc_html_e('Reference Order:', 'mad-suite'); ?></strong> #<?php echo esc_html($order->get_order_number()); ?>
            </p>
            <p style="margin: 5px 0; color: #6c757d;">
                <strong><?php esc_html_e('Original Order Date:', 'mad-suite'); ?></strong>
                <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?>
            </p>
            <p style="margin: 5px 0; color: #6c757d;">
                <strong><?php esc_html_e('Pre-Refund Created:', 'mad-suite'); ?></strong>
                <?php echo esc_html(date_i18n(get_option('date_format'), $refund_data['created_date'])); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Add extra content after order details
     *
     * @param string $document_type Document type
     * @param object $order Order object
     */
    public function add_extra_content($document_type, $order) {
        if (!$this->is_prerefund_document) {
            return;
        }

        $settings = $this->module->get_settings();
        $refund_data = $order->get_meta('_pending_refund_data');

        // Display totals
        if (!empty($refund_data)) {
            $currency = $order->get_currency();
            ?>
            <div class="prerefund-totals" style="margin-top: 20px; text-align: right;">
                <table style="margin-left: auto; border-collapse: collapse; min-width: 250px;">
                    <tr>
                        <th style="text-align: right; padding: 5px 10px;"><?php esc_html_e('Subtotal:', 'mad-suite'); ?></th>
                        <td style="text-align: right; padding: 5px 10px;"><?php echo wc_price($refund_data['subtotal'], ['currency' => $currency]); ?></td>
                    </tr>
                    <?php if (!empty($refund_data['tax'])) : ?>
                    <tr>
                        <th style="text-align: right; padding: 5px 10px;"><?php esc_html_e('Tax:', 'mad-suite'); ?></th>
                        <td style="text-align: right; padding: 5px 10px;"><?php echo wc_price($refund_data['tax'], ['currency' => $currency]); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($refund_data['include_shipping']) && !empty($refund_data['shipping'])) : ?>
                    <tr>
                        <th style="text-align: right; padding: 5px 10px;"><?php esc_html_e('Shipping:', 'mad-suite'); ?></th>
                        <td style="text-align: right; padding: 5px 10px;"><?php echo wc_price($refund_data['shipping'] + ($refund_data['shipping_tax'] ?? 0), ['currency' => $currency]); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="border-top: 2px solid #333; font-size: 1.2em;">
                        <th style="text-align: right; padding: 10px 10px 5px;"><?php esc_html_e('Total to Refund:', 'mad-suite'); ?></th>
                        <td style="text-align: right; padding: 10px 10px 5px; font-weight: bold;"><?php echo wc_price($refund_data['total'], ['currency' => $currency]); ?></td>
                    </tr>
                </table>
            </div>
            <?php
        }

        // Display customs notice if set
        $customs_text = $settings['pdf_customs_text'] ?? '';
        if (!empty($customs_text)) {
            ?>
            <div class="customs-notice" style="margin-top: 30px; padding: 15px; border: 1px solid #ffc107; background: #fff3cd; border-radius: 4px;">
                <h3 style="margin: 0 0 10px; font-size: 14px; color: #856404;"><?php esc_html_e('Important Notice for Customs/Transport', 'mad-suite'); ?></h3>
                <p style="margin: 0; font-size: 12px; color: #856404;"><?php echo wp_kses_post($customs_text); ?></p>
            </div>
            <?php
        }

        // Display footer text if set
        $footer_text = $settings['pdf_footer_text'] ?? '';
        if (!empty($footer_text)) {
            ?>
            <div class="prerefund-footer" style="margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 11px; color: #666;">
                <?php echo wp_kses_post($footer_text); ?>
            </div>
            <?php
        }
    }

    /**
     * Filter document title
     *
     * @param string $title Document title
     * @param object $document Document object
     * @return string Modified title
     */
    public function filter_document_title($title, $document) {
        if (!$this->is_prerefund_document) {
            return $title;
        }

        $settings = $this->module->get_settings();
        return $settings['pdf_title'] ?? __('Pre-Refund Invoice', 'mad-suite');
    }

    /**
     * Get PDF generation URL
     *
     * @param WC_Order $order Order object
     * @param string $output Output type (download/view)
     * @return string URL
     */
    public function get_pdf_url($order, $output = 'download') {
        // If WP Overnight is active, use their endpoint with our flag
        if ($this->is_plugin_active() && function_exists('WPO_WCPDF')) {
            $wcpdf = WPO_WCPDF();
            if ($wcpdf && method_exists($wcpdf, 'get_action')) {
                // Build URL using WP Overnight's system
                $nonce = wp_create_nonce('generate_wpo_wcpdf');
                $url = add_query_arg([
                    'action'        => 'generate_wpo_wcpdf',
                    'document_type' => 'invoice', // Use invoice template as base
                    'order_ids'     => $order->get_id(),
                    'prerefund'     => '1', // Our flag
                    '_wpnonce'      => $nonce,
                ], admin_url('admin-ajax.php'));

                return $url;
            }
        }

        // Fallback to our own AJAX endpoint
        return wp_nonce_url(
            admin_url('admin-ajax.php?action=mad_refund_generate_pdf&order_id=' . $order->get_id() . '&output=' . $output),
            'mad_refund_pdf_' . $order->get_id()
        );
    }

    /**
     * AJAX handler for PDF generation (fallback)
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
            wp_die(__('No pre-refund data found for this order. Please save refund data first.', 'mad-suite'));
        }

        // Try to use WP Overnight if available
        if ($this->is_plugin_active() && function_exists('wcpdf_get_document')) {
            $this->is_prerefund_document = true;
            $this->current_order = $order;

            try {
                // Get invoice document and customize it
                $document = wcpdf_get_document('invoice', $order);
                if ($document) {
                    $document->output_pdf('download');
                    exit;
                }
            } catch (Exception $e) {
                // Log error and fall back to HTML
                $this->module->get_logger()->error('WP Overnight PDF generation failed: ' . $e->getMessage());
            }
        }

        // Fallback: Generate HTML template
        $settings = $this->module->get_settings();
        header('Content-Type: text/html; charset=utf-8');
        include dirname(__DIR__) . '/templates/pdf-return-invoice.php';
        exit;
    }

    /**
     * Get current pre-refund state
     *
     * @return bool
     */
    public function is_generating_prerefund() {
        return $this->is_prerefund_document;
    }
}
