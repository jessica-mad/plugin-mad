<?php
/**
 * PDF Integration with WP Overnight PDF Invoices & Packing Slips
 *
 * Hooks into WP Overnight's Credit Note / Invoice generation to replace
 * item data with pre-refund data when an order has _pending_refund_data.
 *
 * Strategy:
 * - When the meta box "Download PDF" button is clicked, we use our AJAX endpoint
 * - Our AJAX sets a flag and then uses WP Overnight's engine to render the PDF
 * - We intercept order_items_data and totals to inject pre-refund data
 * - The resulting PDF uses WP Overnight's templates but with our content
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
     * Flag: are we currently generating a pre-refund document?
     *
     * @var bool
     */
    private $is_prerefund_render = false;

    /**
     * Cached pre-refund data for current render
     *
     * @var array|null
     */
    private $cached_refund_data = null;

    /**
     * Cached order for current render
     *
     * @var WC_Order|null
     */
    private $cached_order = null;

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
        // Our own AJAX endpoint for PDF generation
        add_action('wp_ajax_mad_refund_generate_pdf', [$this, 'ajax_generate_pdf']);

        // Only add WP Overnight hooks if the plugin is active
        if (!$this->is_plugin_active()) {
            return;
        }

        // --- WP Overnight hooks that run during PDF rendering ---

        // Intercept order items data (items shown in the PDF table)
        add_filter('wpo_wcpdf_order_items_data', [$this, 'filter_order_items_data'], 10, 3);

        // Intercept footer data / totals row
        add_filter('wpo_wcpdf_footer_totals_row', [$this, 'filter_footer_totals'], 10, 3);

        // Add content before order details (header with pre-refund info)
        add_action('wpo_wcpdf_before_order_details', [$this, 'render_before_order_details'], 10, 2);

        // Add content after order details (customs notice, totals)
        add_action('wpo_wcpdf_after_order_details', [$this, 'render_after_order_details'], 10, 2);

        // Filter the document title
        add_filter('wpo_wcpdf_document_title', [$this, 'filter_document_title'], 10, 2);

        // Add our status email to WP Overnight's email attachment options
        add_filter('wpo_wcpdf_wc_email_ids', [$this, 'register_email_id']);

        // Add a PDF button in the WP Overnight column on the orders list
        add_filter('wpo_wcpdf_listing_actions', [$this, 'add_listing_actions'], 20, 2);

        // Add to order actions dropdown (inside order edit)
        add_filter('woocommerce_order_actions', [$this, 'add_order_action']);
        add_action('woocommerce_order_action_mad_generate_prerefund_pdf', [$this, 'handle_order_action']);
    }

    /**
     * Check if WP Overnight PDF plugin is active
     *
     * @return bool
     */
    public function is_plugin_active() {
        return class_exists('WPO_WCPDF') || function_exists('WPO_WCPDF');
    }

    // =========================================================================
    // AJAX PDF GENERATION (our own endpoint)
    // =========================================================================

    /**
     * AJAX handler: Generate pre-refund PDF
     *
     * This is the main entry point when clicking "Download PDF" from the meta box.
     */
    public function ajax_generate_pdf() {
        $order_id = absint($_GET['order_id'] ?? 0);

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'mad_refund_pdf_' . $order_id)) {
            wp_die(__('Security check failed.', 'mad-suite'));
        }

        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Permission denied.', 'mad-suite'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Order not found.', 'mad-suite'));
        }

        $refund_data = $order->get_meta('_pending_refund_data');
        if (empty($refund_data) || empty($refund_data['items'])) {
            wp_die(__('No pre-refund data found. Please save refund items first.', 'mad-suite'));
        }

        $output = (isset($_GET['output']) && $_GET['output'] === 'view') ? 'inline' : 'download';

        // Log the PDF generation
        $this->module->get_logger()->log(sprintf(
            'PDF generation requested for order #%d (%s)',
            $order_id,
            $output
        ));

        // Try WP Overnight engine first
        if ($this->is_plugin_active() && function_exists('wcpdf_get_document')) {
            $this->generate_with_wpo($order, $refund_data, $output);
            // generate_with_wpo exits on success
        }

        // Fallback: render our own HTML template
        $this->generate_fallback_html($order, $refund_data);
    }

    /**
     * Generate PDF using WP Overnight's engine
     *
     * Sets the pre-refund flag, then asks WPO to render an invoice document.
     * Our filters intercept the rendering and inject pre-refund data.
     *
     * @param WC_Order $order Order object
     * @param array $refund_data Pre-refund data
     * @param string $output 'download' or 'inline'
     */
    private function generate_with_wpo($order, $refund_data, $output = 'download') {
        // Activate the pre-refund flag so our filters know to intercept
        $this->is_prerefund_render = true;
        $this->cached_refund_data = $refund_data;
        $this->cached_order = $order;

        try {
            // Use 'invoice' as the base document type
            $document = wcpdf_get_document('invoice', $order);

            if (!$document) {
                // Reset flag and fall through to fallback
                $this->reset_render_state();
                return;
            }

            // Generate the PDF
            $pdf_content = $document->get_pdf();

            if (empty($pdf_content)) {
                $this->reset_render_state();
                return;
            }

            // Build filename
            $filename = sprintf(
                'pre-refund-invoice-%s.pdf',
                $order->get_order_number()
            );

            // Output the PDF
            $disposition = ($output === 'inline') ? 'inline' : 'attachment';

            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf_content));
            header('Cache-Control: no-cache, no-store, must-revalidate');

            echo $pdf_content;

            $this->reset_render_state();
            exit;

        } catch (Exception $e) {
            $this->module->get_logger()->error(
                'WP Overnight PDF generation failed: ' . $e->getMessage()
            );
            $this->reset_render_state();
            // Fall through to fallback
        }
    }

    /**
     * Fallback: generate an HTML page (printable) if WP Overnight is not available
     *
     * @param WC_Order $order Order object
     * @param array $refund_data Pre-refund data
     */
    private function generate_fallback_html($order, $refund_data) {
        $settings = $this->module->get_settings();

        header('Content-Type: text/html; charset=utf-8');
        include dirname(__DIR__) . '/templates/pdf-return-invoice.php';
        exit;
    }

    /**
     * Reset the pre-refund render state
     */
    private function reset_render_state() {
        $this->is_prerefund_render = false;
        $this->cached_refund_data = null;
        $this->cached_order = null;
    }

    // =========================================================================
    // WP OVERNIGHT FILTERS (intercept during PDF rendering)
    // =========================================================================

    /**
     * Filter order items data shown in the PDF
     *
     * When our flag is active, replace the full order items with only
     * the pre-refund items and their adjusted quantities/totals.
     *
     * Hook: wpo_wcpdf_order_items_data
     * Signature in WPO: apply_filters('wpo_wcpdf_order_items_data', $data, $order, $document_type)
     *
     * @param array $data Order items data prepared by WPO
     * @param mixed $order_or_document Order or Document object
     * @param string $document_type Document type string (may not be present in all versions)
     * @return array Filtered items data
     */
    public function filter_order_items_data($data, $order_or_document, $document_type = '') {
        if (!$this->is_prerefund_render || empty($this->cached_refund_data)) {
            return $data;
        }

        $refund_data = $this->cached_refund_data;

        if (empty($refund_data['items'])) {
            return $data;
        }

        // Determine order object
        $order = $this->cached_order;
        if (!$order && is_a($order_or_document, 'WC_Order')) {
            $order = $order_or_document;
        }

        $currency = $order ? $order->get_currency() : get_woocommerce_currency();

        // Build filtered items array
        $filtered = [];

        foreach ($data as $item_id => $item) {
            // Find this item in our pre-refund data
            $pr_item = null;

            foreach ($refund_data['items'] as $pr_id => $pr_data) {
                // Match by item_id field or by array key
                $data_item_id = isset($item['item_id']) ? $item['item_id'] : $item_id;

                if ($data_item_id == $pr_id) {
                    $pr_item = $pr_data;
                    break;
                }
            }

            if (!$pr_item) {
                // This item is not part of the pre-refund, skip it
                continue;
            }

            // Override quantity
            $item['quantity'] = $pr_item['quantity'];

            // Override totals
            if (isset($item['line_total'])) {
                $item['line_total'] = wc_price($pr_item['subtotal'], ['currency' => $currency]);
            }
            if (isset($item['line_tax'])) {
                $item['line_tax'] = wc_price($pr_item['tax'], ['currency' => $currency]);
            }
            if (isset($item['ex_price'])) {
                $item['ex_price'] = wc_price($pr_item['subtotal'], ['currency' => $currency]);
            }
            if (isset($item['price'])) {
                $item['price'] = wc_price($pr_item['subtotal'] + $pr_item['tax'], ['currency' => $currency]);
            }
            if (isset($item['single_price'])) {
                $unit = $pr_item['subtotal'] / max(1, $pr_item['quantity']);
                $item['single_price'] = wc_price($unit, ['currency' => $currency]);
            }

            $filtered[$item_id] = $item;
        }

        return $filtered;
    }

    /**
     * Filter footer totals
     *
     * Hook: wpo_wcpdf_footer_totals_row
     *
     * @param array $totals_row Totals data
     * @param string $table_type Type of totals table
     * @param mixed $document Document object
     * @return array Filtered totals
     */
    public function filter_footer_totals($totals_row, $table_type = '', $document = null) {
        if (!$this->is_prerefund_render || empty($this->cached_refund_data)) {
            return $totals_row;
        }

        // We'll add our own totals in render_after_order_details instead
        // Return empty to avoid double totals display
        return $totals_row;
    }

    /**
     * Render content BEFORE order details table
     *
     * Hook: wpo_wcpdf_before_order_details
     * Signature: do_action('wpo_wcpdf_before_order_details', $document_type, $order)
     *
     * @param string $document_type Document type
     * @param WC_Order $order Order object
     */
    public function render_before_order_details($document_type, $order) {
        if (!$this->is_prerefund_render || empty($this->cached_refund_data)) {
            return;
        }

        $refund_data = $this->cached_refund_data;
        $settings = $this->module->get_settings();
        $title = $settings['pdf_title'] ?? __('Pre-Refund Invoice', 'mad-suite');

        ?>
        <div class="prerefund-header" style="background:#f8f9fa; border:2px solid #dee2e6; padding:12px 15px; margin-bottom:15px;">
            <h2 style="margin:0 0 8px; color:#333; font-size:16px;"><?php echo esc_html($title); ?></h2>
            <table style="font-size:11px; color:#555;">
                <tr>
                    <td style="padding:2px 10px 2px 0;"><strong><?php esc_html_e('Reference Order:', 'mad-suite'); ?></strong></td>
                    <td>#<?php echo esc_html($order->get_order_number()); ?></td>
                </tr>
                <tr>
                    <td style="padding:2px 10px 2px 0;"><strong><?php esc_html_e('Original Order Date:', 'mad-suite'); ?></strong></td>
                    <td><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></td>
                </tr>
                <?php if (!empty($refund_data['created_date'])) : ?>
                <tr>
                    <td style="padding:2px 10px 2px 0;"><strong><?php esc_html_e('Pre-Refund Date:', 'mad-suite'); ?></strong></td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $refund_data['created_date'])); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!empty($refund_data['notes'])) : ?>
                <tr>
                    <td style="padding:2px 10px 2px 0;"><strong><?php esc_html_e('Notes:', 'mad-suite'); ?></strong></td>
                    <td><?php echo esc_html($refund_data['notes']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }

    /**
     * Render content AFTER order details table
     *
     * Hook: wpo_wcpdf_after_order_details
     * Signature: do_action('wpo_wcpdf_after_order_details', $document_type, $order)
     *
     * @param string $document_type Document type
     * @param WC_Order $order Order object
     */
    public function render_after_order_details($document_type, $order) {
        if (!$this->is_prerefund_render || empty($this->cached_refund_data)) {
            return;
        }

        $refund_data = $this->cached_refund_data;
        $settings = $this->module->get_settings();
        $currency = $order->get_currency();

        // Totals table
        ?>
        <table class="prerefund-totals" style="width:100%; margin-top:15px;">
            <tr>
                <td style="width:60%;"></td>
                <td>
                    <table style="width:100%; border-collapse:collapse;">
                        <tr>
                            <th style="text-align:right; padding:4px 8px; font-weight:normal;"><?php esc_html_e('Subtotal:', 'mad-suite'); ?></th>
                            <td style="text-align:right; padding:4px 8px;"><?php echo wc_price($refund_data['subtotal'], ['currency' => $currency]); ?></td>
                        </tr>
                        <?php if (!empty($refund_data['tax']) && $refund_data['tax'] > 0) : ?>
                        <tr>
                            <th style="text-align:right; padding:4px 8px; font-weight:normal;"><?php esc_html_e('Tax:', 'mad-suite'); ?></th>
                            <td style="text-align:right; padding:4px 8px;"><?php echo wc_price($refund_data['tax'], ['currency' => $currency]); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($refund_data['include_shipping']) && !empty($refund_data['shipping'])) : ?>
                        <tr>
                            <th style="text-align:right; padding:4px 8px; font-weight:normal;"><?php esc_html_e('Shipping:', 'mad-suite'); ?></th>
                            <td style="text-align:right; padding:4px 8px;"><?php echo wc_price($refund_data['shipping'] + ($refund_data['shipping_tax'] ?? 0), ['currency' => $currency]); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr style="border-top:2px solid #000;">
                            <th style="text-align:right; padding:8px 8px 4px; font-size:1.1em;"><?php esc_html_e('Total to Refund:', 'mad-suite'); ?></th>
                            <td style="text-align:right; padding:8px 8px 4px; font-size:1.1em; font-weight:bold;"><?php echo wc_price($refund_data['total'], ['currency' => $currency]); ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        <?php

        // Customs / transport notice
        $customs_text = $settings['pdf_customs_text'] ?? '';
        if (!empty($customs_text)) :
        ?>
        <div style="margin-top:20px; padding:10px 14px; border:1px solid #ccc; background:#fafafa;">
            <p style="margin:0 0 4px; font-weight:bold; font-size:11px;"><?php esc_html_e('Important Notice for Customs / Transport', 'mad-suite'); ?></p>
            <p style="margin:0; font-size:10px;"><?php echo wp_kses_post($customs_text); ?></p>
        </div>
        <?php
        endif;

        // Footer text
        $footer_text = $settings['pdf_footer_text'] ?? '';
        if (!empty($footer_text)) :
        ?>
        <div style="margin-top:15px; padding-top:10px; border-top:1px solid #ddd; font-size:9px; color:#777;">
            <?php echo wp_kses_post($footer_text); ?>
        </div>
        <?php
        endif;
    }

    /**
     * Filter the document title shown in the PDF header
     *
     * Hook: wpo_wcpdf_document_title
     *
     * @param string $title Current document title
     * @param object $document Document object
     * @return string Modified title
     */
    public function filter_document_title($title, $document) {
        if (!$this->is_prerefund_render) {
            return $title;
        }

        $settings = $this->module->get_settings();
        return !empty($settings['pdf_title']) ? $settings['pdf_title'] : __('Pre-Refund Invoice', 'mad-suite');
    }

    // =========================================================================
    // ORDER LIST & ORDER ACTIONS INTEGRATION
    // =========================================================================

    /**
     * Register our email ID so WP Overnight can show it in "Attach to:" settings
     *
     * @param array $email_ids List of WC email IDs
     * @return array Modified list
     */
    public function register_email_id($email_ids) {
        $email_ids[] = 'mad_pending_refund';
        return $email_ids;
    }

    /**
     * Add PDF action button in WP Overnight's column on the orders list
     *
     * @param array $listing_actions Existing WPO actions
     * @param WC_Order $order Order object
     * @return array Modified actions
     */
    public function add_listing_actions($listing_actions, $order) {
        $refund_data = $order->get_meta('_pending_refund_data');

        if (empty($refund_data) || empty($refund_data['items'])) {
            return $listing_actions;
        }

        $listing_actions['prerefund_invoice'] = [
            'url'    => $this->get_pdf_url($order),
            'alt'    => __('Pre-Refund Invoice (PDF)', 'mad-suite'),
            'class'  => 'prerefund-invoice',
            'target' => '_blank',
        ];

        return $listing_actions;
    }

    /**
     * Add order action to the Actions dropdown inside the order edit page
     *
     * @param array $actions Existing actions
     * @return array Modified actions
     */
    public function add_order_action($actions) {
        global $theorder;

        if (!$theorder) {
            return $actions;
        }

        $refund_data = $theorder->get_meta('_pending_refund_data');

        if (!empty($refund_data) && !empty($refund_data['items'])) {
            $actions['mad_generate_prerefund_pdf'] = __('Generate Pre-Refund Invoice (PDF)', 'mad-suite');
        }

        return $actions;
    }

    /**
     * Handle order action: generate and download PDF
     *
     * @param WC_Order $order Order object
     */
    public function handle_order_action($order) {
        $url = $this->get_pdf_url($order);
        wp_redirect($url);
        exit;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get the URL to generate a pre-refund PDF
     *
     * @param WC_Order $order Order object
     * @param string $output 'download' or 'view'
     * @return string URL
     */
    public function get_pdf_url($order, $output = 'download') {
        return wp_nonce_url(
            admin_url('admin-ajax.php?action=mad_refund_generate_pdf&order_id=' . $order->get_id() . '&output=' . $output),
            'mad_refund_pdf_' . $order->get_id()
        );
    }

    /**
     * Check if we are currently rendering a pre-refund document
     *
     * @return bool
     */
    public function is_generating_prerefund() {
        return $this->is_prerefund_render;
    }
}
