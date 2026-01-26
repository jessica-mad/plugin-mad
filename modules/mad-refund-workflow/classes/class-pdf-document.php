<?php
/**
 * Custom PDF Document for Return Invoice
 *
 * Extends WP Overnight's document class for pre-refund return invoices.
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

// Check if WP Overnight PDF plugin is active
if (!class_exists('WPO_WCPDF_Documents')) {
    return;
}

if (!class_exists('WPO\\WC\\PDF_Invoices\\Documents\\Order_Document')) {
    // Try alternative class name for older versions
    if (!class_exists('WPO_WCPDF_Order_Document')) {
        return;
    }
}

/**
 * Return Invoice Document Class
 */
class MAD_Refund_PDF_Document extends \WPO\WC\PDF_Invoices\Documents\Order_Document {

    /**
     * Document type
     *
     * @var string
     */
    public $type = 'return-invoice';

    /**
     * Document slug
     *
     * @var string
     */
    public $slug = 'return-invoice';

    /**
     * Constructor
     *
     * @param WC_Order $order Order object
     * @param array $args Additional arguments
     */
    public function __construct($order = null, $args = []) {
        parent::__construct($order, $args);

        $this->type = 'return-invoice';
        $this->slug = 'return-invoice';
    }

    /**
     * Get document type
     *
     * @return string
     */
    public function get_type() {
        return $this->type;
    }

    /**
     * Get document title
     *
     * @return string
     */
    public function get_title() {
        $module_settings = get_option('madsuite_mad-refund-workflow_settings', []);
        $title = $module_settings['pdf_title'] ?? '';

        return !empty($title) ? $title : __('Return Invoice', 'mad-suite');
    }

    /**
     * Get document filename
     *
     * @param string $context Display or download context
     * @param array $order_ids Order IDs
     * @return string Filename
     */
    public function get_filename($context = 'download', $order_ids = []) {
        if (count($order_ids) === 1) {
            $order = wc_get_order($order_ids[0]);
            if ($order) {
                return sanitize_file_name(
                    sprintf(
                        '%s-%s.pdf',
                        __('return-invoice', 'mad-suite'),
                        $order->get_order_number()
                    )
                );
            }
        }

        return sanitize_file_name(
            sprintf(
                '%s-%s.pdf',
                __('return-invoices', 'mad-suite'),
                date('Y-m-d')
            )
        );
    }

    /**
     * Get document number
     *
     * @return string
     */
    public function get_number() {
        if ($this->order) {
            return sprintf(
                'RI-%s',
                $this->order->get_order_number()
            );
        }

        return '';
    }

    /**
     * Get document date
     *
     * @return string
     */
    public function get_date() {
        if ($this->order) {
            $refund_data = $this->order->get_meta('_pending_refund_data');
            if (!empty($refund_data['created_date'])) {
                return date_i18n(get_option('date_format'), $refund_data['created_date']);
            }
        }

        return date_i18n(get_option('date_format'));
    }

    /**
     * Initialize document settings
     */
    public function init_settings() {
        parent::init_settings();

        // Override with our custom settings
        $this->settings = apply_filters('wpo_wcpdf_return_invoice_settings', [
            'display_shipping_address' => 'when_different',
            'display_email' => true,
            'display_phone' => true,
            'display_date' => true,
            'display_number' => true,
        ]);
    }

    /**
     * Get order items data for PDF
     *
     * @return array
     */
    public function get_order_items_data() {
        if (!$this->order) {
            return [];
        }

        $refund_data = $this->order->get_meta('_pending_refund_data');
        if (empty($refund_data) || empty($refund_data['items'])) {
            return parent::get_order_items_data();
        }

        $items_data = [];

        foreach ($refund_data['items'] as $item_id => $item) {
            $order_item = $this->order->get_item($item_id);
            if (!$order_item) {
                continue;
            }

            $product = $order_item->get_product();

            $items_data[$item_id] = [
                'item_id' => $item_id,
                'name' => $item['name'],
                'sku' => $item['sku'] ?? '',
                'quantity' => $item['quantity'],
                'single_price' => wc_price($item['subtotal'] / max(1, $item['quantity']), ['currency' => $this->order->get_currency()]),
                'single_price_value' => $item['subtotal'] / max(1, $item['quantity']),
                'line_total' => wc_price($item['subtotal'], ['currency' => $this->order->get_currency()]),
                'line_total_value' => $item['subtotal'],
                'line_tax' => wc_price($item['tax'], ['currency' => $this->order->get_currency()]),
                'line_tax_value' => $item['tax'],
                'ex_price' => wc_price($item['subtotal'], ['currency' => $this->order->get_currency()]),
                'inc_price' => wc_price($item['subtotal'] + $item['tax'], ['currency' => $this->order->get_currency()]),
                'product' => $product,
                'item' => $order_item,
                'meta' => [
                    [
                        'label' => __('Return Quantity', 'mad-suite'),
                        'value' => sprintf(
                            '%d / %d',
                            $item['quantity'],
                            $item['original_quantity']
                        ),
                    ],
                ],
            ];
        }

        return apply_filters('wpo_wcpdf_order_items_data', $items_data, $this->order, $this->type);
    }

    /**
     * Get totals data for PDF
     *
     * @return array
     */
    public function get_totals_data() {
        if (!$this->order) {
            return [];
        }

        $refund_data = $this->order->get_meta('_pending_refund_data');
        if (empty($refund_data)) {
            return parent::get_totals_data();
        }

        $totals = [];
        $currency = $this->order->get_currency();

        // Subtotal
        $totals['subtotal'] = [
            'label' => __('Subtotal', 'mad-suite'),
            'value' => wc_price($refund_data['subtotal'], ['currency' => $currency]),
        ];

        // Tax
        if (!empty($refund_data['tax']) && $refund_data['tax'] > 0) {
            $totals['tax'] = [
                'label' => __('Tax', 'mad-suite'),
                'value' => wc_price($refund_data['tax'], ['currency' => $currency]),
            ];
        }

        // Shipping
        if (!empty($refund_data['include_shipping']) && !empty($refund_data['shipping'])) {
            $shipping_total = $refund_data['shipping'] + ($refund_data['shipping_tax'] ?? 0);
            $totals['shipping'] = [
                'label' => __('Shipping', 'mad-suite'),
                'value' => wc_price($shipping_total, ['currency' => $currency]),
            ];
        }

        // Total
        $totals['total'] = [
            'label' => __('Total to Refund', 'mad-suite'),
            'value' => '<strong>' . wc_price($refund_data['total'], ['currency' => $currency]) . '</strong>',
        ];

        return apply_filters('wpo_wcpdf_return_invoice_totals', $totals, $this->order);
    }

    /**
     * Output custom document content
     */
    public function output_document() {
        // Add reference to original order
        ?>
        <div class="document-header">
            <h1><?php echo esc_html($this->get_title()); ?></h1>
            <p class="document-number">
                <?php
                printf(
                    /* translators: %s: document number */
                    __('Document No.: %s', 'mad-suite'),
                    $this->get_number()
                );
                ?>
            </p>
            <p class="document-date">
                <?php
                printf(
                    /* translators: %s: document date */
                    __('Date: %s', 'mad-suite'),
                    $this->get_date()
                );
                ?>
            </p>
        </div>

        <div class="order-reference">
            <p>
                <strong><?php esc_html_e('Reference Order:', 'mad-suite'); ?></strong>
                #<?php echo esc_html($this->order->get_order_number()); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Original Order Date:', 'mad-suite'); ?></strong>
                <?php echo esc_html($this->order->get_date_created()->date_i18n(get_option('date_format'))); ?>
            </p>
        </div>
        <?php

        // Call parent to output standard content
        parent::output_document();
    }

    /**
     * Get template filename
     *
     * @param string $template Template name
     * @return string Template path
     */
    public function get_template_path($template = '') {
        // First check our plugin templates
        $custom_template = dirname(__DIR__) . '/templates/' . $template;
        if (file_exists($custom_template)) {
            return $custom_template;
        }

        // Fall back to WP Overnight templates
        return parent::get_template_path($template);
    }

    /**
     * Output customs notice
     */
    public function output_customs_notice() {
        $module_settings = get_option('madsuite_mad-refund-workflow_settings', []);
        $customs_text = $module_settings['pdf_customs_text'] ?? '';

        if (empty($customs_text)) {
            return;
        }

        ?>
        <div class="customs-notice">
            <h3><?php esc_html_e('Important Notice', 'mad-suite'); ?></h3>
            <p><?php echo wp_kses_post($customs_text); ?></p>
        </div>
        <?php
    }

    /**
     * Output footer content
     */
    public function output_footer() {
        $module_settings = get_option('madsuite_mad-refund-workflow_settings', []);
        $footer_text = $module_settings['pdf_footer_text'] ?? '';

        if (!empty($footer_text)) {
            ?>
            <div class="document-footer-text">
                <?php echo wp_kses_post($footer_text); ?>
            </div>
            <?php
        }

        parent::output_footer();
    }
}
