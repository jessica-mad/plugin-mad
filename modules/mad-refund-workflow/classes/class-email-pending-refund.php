<?php
/**
 * WooCommerce Email Class for Pending Refund Notification
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

if (!class_exists('WC_Email')) {
    return;
}

class MAD_Refund_Email_Pending extends WC_Email {

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
    public function __construct($module = null) {
        $this->module = $module;

        $this->id = 'mad_pending_refund';
        $this->customer_email = true;
        $this->title = __('Pending Refund Notification', 'mad-suite');
        $this->description = __('This email is sent to the customer when an order is marked as pending refund.', 'mad-suite');

        $this->template_html = 'emails/pending-refund.php';
        $this->template_plain = 'emails/plain/pending-refund.php';

        $this->placeholders = [
            '{order_date}' => '',
            '{order_number}' => '',
        ];

        // Triggers
        add_action('woocommerce_order_status_pending-refund_notification', [$this, 'trigger'], 10, 2);

        parent::__construct();
    }

    /**
     * Get default email subject
     *
     * @return string
     */
    public function get_default_subject() {
        return __('Your refund request for order #{order_number} is being processed', 'mad-suite');
    }

    /**
     * Get default email heading
     *
     * @return string
     */
    public function get_default_heading() {
        return __('Refund Request Received', 'mad-suite');
    }

    /**
     * Trigger the email
     *
     * @param int $order_id Order ID
     * @param WC_Order|false $order Order object
     */
    public function trigger($order_id, $order = false) {
        $this->setup_locale();

        if ($order_id && !is_a($order, 'WC_Order')) {
            $order = wc_get_order($order_id);
        }

        if (is_a($order, 'WC_Order')) {
            $this->object = $order;
            $this->recipient = $this->object->get_billing_email();
            $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
            $this->placeholders['{order_number}'] = $this->object->get_order_number();
        }

        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }

        $this->restore_locale();
    }

    /**
     * Get email subject from module settings
     *
     * @return string
     */
    public function get_subject() {
        if ($this->module) {
            $settings = $this->module->get_settings();
            if (!empty($settings['email_subject'])) {
                return $this->format_string($settings['email_subject']);
            }
        }
        return $this->format_string($this->get_default_subject());
    }

    /**
     * Get email heading from module settings
     *
     * @return string
     */
    public function get_heading() {
        if ($this->module) {
            $settings = $this->module->get_settings();
            if (!empty($settings['email_heading'])) {
                return $this->format_string($settings['email_heading']);
            }
        }
        return $this->format_string($this->get_default_heading());
    }

    /**
     * Get content HTML
     *
     * @return string
     */
    public function get_content_html() {
        return $this->get_custom_content('html');
    }

    /**
     * Get content plain text
     *
     * @return string
     */
    public function get_content_plain() {
        return $this->get_custom_content('plain');
    }

    /**
     * Get custom content from module settings
     *
     * @param string $type html or plain
     * @return string
     */
    private function get_custom_content($type = 'html') {
        $content = '';

        if ($this->module) {
            $settings = $this->module->get_settings();
            $content = $settings['email_content'] ?? '';
        }

        if (empty($content)) {
            $content = $this->get_default_content();
        }

        // Process placeholders
        $content = $this->process_content_placeholders($content);

        // Wrap in email template
        ob_start();

        do_action('woocommerce_email_header', $this->get_heading(), $this);

        if ($type === 'html') {
            echo wpautop(wptexturize($content));
        } else {
            echo esc_html($content);
        }

        // Add order details
        if ($this->object) {
            $this->render_order_details($type);
        }

        do_action('woocommerce_email_footer', $this);

        return ob_get_clean();
    }

    /**
     * Get default email content
     *
     * @return string
     */
    private function get_default_content() {
        return __(
            "Hello {customer_name},\n\nWe have received your refund request for order #{order_number}.\n\nWe are preparing the return documentation. You will receive further instructions shortly.\n\nThank you for your patience.",
            'mad-suite'
        );
    }

    /**
     * Process content placeholders
     *
     * @param string $content Content to process
     * @return string Processed content
     */
    private function process_content_placeholders($content) {
        if (!$this->object) {
            return $content;
        }

        $refund_data = $this->object->get_meta('_pending_refund_data');

        // Build items text
        $items_text = '';
        if (!empty($refund_data['items'])) {
            foreach ($refund_data['items'] as $item) {
                $items_text .= sprintf(
                    "- %s x %d: %s\n",
                    $item['name'],
                    $item['quantity'],
                    wc_price($item['subtotal'] + $item['tax'])
                );
            }
        }

        $placeholders = [
            '{customer_name}' => $this->object->get_billing_first_name(),
            '{customer_full_name}' => $this->object->get_formatted_billing_full_name(),
            '{order_number}' => $this->object->get_order_number(),
            '{order_date}' => wc_format_datetime($this->object->get_date_created()),
            '{refund_items}' => $items_text,
            '{refund_total}' => wc_price($refund_data['total'] ?? 0),
            '{site_title}' => get_bloginfo('name'),
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $content);
    }

    /**
     * Render order details section
     *
     * @param string $type html or plain
     */
    private function render_order_details($type = 'html') {
        $refund_data = $this->object->get_meta('_pending_refund_data');

        if (empty($refund_data['items'])) {
            return;
        }

        if ($type === 'html') {
            ?>
            <h2><?php esc_html_e('Items for Return', 'mad-suite'); ?></h2>
            <table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1">
                <thead>
                    <tr>
                        <th style="text-align: left;"><?php esc_html_e('Product', 'mad-suite'); ?></th>
                        <th style="text-align: center;"><?php esc_html_e('Quantity', 'mad-suite'); ?></th>
                        <th style="text-align: right;"><?php esc_html_e('Total', 'mad-suite'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($refund_data['items'] as $item) : ?>
                        <tr>
                            <td style="text-align: left;"><?php echo esc_html($item['name']); ?></td>
                            <td style="text-align: center;"><?php echo esc_html($item['quantity']); ?></td>
                            <td style="text-align: right;"><?php echo wc_price($item['subtotal'] + $item['tax']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align: right;"><?php esc_html_e('Total to Refund:', 'mad-suite'); ?></th>
                        <td style="text-align: right;"><strong><?php echo wc_price($refund_data['total']); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
            <?php
        } else {
            echo "\n" . __('Items for Return', 'mad-suite') . ":\n";
            echo str_repeat('-', 40) . "\n";
            foreach ($refund_data['items'] as $item) {
                printf(
                    "%s x %d - %s\n",
                    $item['name'],
                    $item['quantity'],
                    strip_tags(wc_price($item['subtotal'] + $item['tax']))
                );
            }
            echo str_repeat('-', 40) . "\n";
            printf(__('Total to Refund: %s', 'mad-suite') . "\n", strip_tags(wc_price($refund_data['total'])));
        }
    }

    /**
     * Initialize form fields for WooCommerce settings
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'mad-suite'),
                'type' => 'checkbox',
                'label' => __('Enable this email notification', 'mad-suite'),
                'default' => 'yes',
            ],
        ];
    }
}
