<?php
/**
 * Email Notification for Pre-Refund
 *
 * Handles sending email notifications when order enters pending-refund status.
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

class MAD_Refund_Email {

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
        // Register email class with WooCommerce
        add_filter('woocommerce_email_classes', [$this, 'register_email_class']);

        // Custom trigger for pending refund email
        add_action('mad_refund_send_pending_email', [$this, 'send_pending_refund_email'], 10, 2);

        // Add email actions
        add_filter('woocommerce_email_actions', [$this, 'add_email_actions']);
    }

    /**
     * Register custom email class
     *
     * @param array $emails Existing email classes
     * @return array Modified email classes
     */
    public function register_email_class($emails) {
        require_once __DIR__ . '/class-email-pending-refund.php';
        $emails['MAD_Refund_Email_Pending'] = new MAD_Refund_Email_Pending($this->module);
        return $emails;
    }

    /**
     * Add email actions for triggering
     *
     * @param array $actions Email actions
     * @return array Modified actions
     */
    public function add_email_actions($actions) {
        $actions[] = 'woocommerce_order_status_pending-refund';
        return $actions;
    }

    /**
     * Send pending refund notification email
     *
     * @param int $order_id Order ID
     * @param WC_Order $order Order object
     */
    public function send_pending_refund_email($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            return;
        }

        $settings = $this->module->get_settings();

        // Check if email is enabled
        if (empty($settings['email_enabled'])) {
            return;
        }

        // Get the email instance
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();

        if (isset($emails['MAD_Refund_Email_Pending'])) {
            $emails['MAD_Refund_Email_Pending']->trigger($order_id, $order);
        }
    }

    /**
     * Get email content with placeholders replaced
     *
     * @param string $content Email content template
     * @param WC_Order $order Order object
     * @return string Processed content
     */
    public function process_placeholders($content, $order) {
        $refund_data = $order->get_meta('_pending_refund_data');

        // Build refund items text
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
            '{order_number}' => $order->get_order_number(),
            '{customer_name}' => $order->get_billing_first_name(),
            '{customer_full_name}' => $order->get_formatted_billing_full_name(),
            '{order_date}' => wc_format_datetime($order->get_date_created()),
            '{refund_items}' => $items_text,
            '{refund_total}' => wc_price($refund_data['total'] ?? 0),
            '{site_title}' => get_bloginfo('name'),
            '{site_url}' => home_url(),
        ];

        return str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $content
        );
    }
}
