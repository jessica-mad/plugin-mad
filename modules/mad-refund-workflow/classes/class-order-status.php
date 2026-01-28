<?php
/**
 * Custom Order Status for Pre-Refund
 *
 * Registers the wc-pending-refund order status in WooCommerce.
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

class MAD_Refund_Order_Status {

    /**
     * Parent module reference
     *
     * @var object
     */
    private $module;

    /**
     * Status slug
     *
     * @var string
     */
    const STATUS_SLUG = 'wc-pending-refund';

    /**
     * Status key (without wc- prefix)
     *
     * @var string
     */
    const STATUS_KEY = 'pending-refund';

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
        // Register custom post status
        add_action('init', [$this, 'register_order_status'], 20);

        // Add to WooCommerce order statuses
        add_filter('wc_order_statuses', [$this, 'add_order_status']);

        // Add status to bulk actions
        add_filter('bulk_actions-edit-shop_order', [$this, 'add_bulk_action']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'add_bulk_action']);

        // Handle bulk action
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_bulk_action'], 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle_bulk_action'], 10, 3);

        // Add status color styling
        add_action('admin_head', [$this, 'add_status_styles']);

        // Make status editable (not read-only)
        add_filter('woocommerce_order_is_editable', [$this, 'make_status_editable'], 10, 2);

        // Add action buttons to order list
        add_filter('woocommerce_admin_order_actions', [$this, 'add_order_actions'], 10, 2);

        // Register status change email trigger
        add_action('woocommerce_order_status_pending-refund', [$this, 'trigger_status_change'], 10, 2);

        // Add status to reports
        add_filter('woocommerce_reports_order_statuses', [$this, 'add_to_reports']);

        // HPOS compatibility
        add_filter('woocommerce_order_list_table_order_css_classes', [$this, 'add_hpos_row_class'], 10, 2);
    }

    /**
     * Register custom post status
     */
    public function register_order_status() {
        $settings = $this->module->get_settings();
        $status_name = !empty($settings['status_name']) ? $settings['status_name'] : __('Pending Refund', 'mad-suite');

        register_post_status(self::STATUS_SLUG, [
            'label' => $status_name,
            'public' => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list' => true,
            'exclude_from_search' => false,
            /* translators: %s: number of orders */
            'label_count' => _n_noop(
                $status_name . ' <span class="count">(%s)</span>',
                $status_name . ' <span class="count">(%s)</span>',
                'mad-suite'
            ),
        ]);
    }

    /**
     * Add status to WooCommerce order statuses list
     *
     * @param array $statuses Existing order statuses
     * @return array Modified statuses
     */
    public function add_order_status($statuses) {
        $settings = $this->module->get_settings();
        $status_name = !empty($settings['status_name']) ? $settings['status_name'] : __('Pending Refund', 'mad-suite');

        // Insert after 'wc-on-hold' or at a logical position
        $new_statuses = [];
        foreach ($statuses as $key => $value) {
            $new_statuses[$key] = $value;
            if ($key === 'wc-on-hold') {
                $new_statuses[self::STATUS_SLUG] = $status_name;
            }
        }

        // If wc-on-hold not found, add at the end before refunded
        if (!isset($new_statuses[self::STATUS_SLUG])) {
            $temp = [];
            foreach ($statuses as $key => $value) {
                if ($key === 'wc-refunded') {
                    $temp[self::STATUS_SLUG] = $status_name;
                }
                $temp[$key] = $value;
            }
            $new_statuses = !empty($temp) ? $temp : array_merge($statuses, [self::STATUS_SLUG => $status_name]);
        }

        return $new_statuses;
    }

    /**
     * Add bulk action for changing status
     *
     * @param array $actions Existing bulk actions
     * @return array Modified actions
     */
    public function add_bulk_action($actions) {
        $settings = $this->module->get_settings();
        $status_name = !empty($settings['status_name']) ? $settings['status_name'] : __('Pending Refund', 'mad-suite');

        $actions['mark_pending-refund'] = sprintf(
            /* translators: %s: status name */
            __('Change status to %s', 'mad-suite'),
            $status_name
        );

        return $actions;
    }

    /**
     * Handle bulk action
     *
     * @param string $redirect_to Redirect URL
     * @param string $action Action being performed
     * @param array $ids Order IDs
     * @return string Redirect URL
     */
    public function handle_bulk_action($redirect_to, $action, $ids) {
        if ($action !== 'mark_pending-refund') {
            return $redirect_to;
        }

        $changed = 0;
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if ($order) {
                $order->update_status(self::STATUS_KEY, __('Status changed via bulk action.', 'mad-suite'));
                $changed++;
            }
        }

        return add_query_arg([
            'bulk_action' => 'marked_pending_refund',
            'changed' => $changed,
        ], $redirect_to);
    }

    /**
     * Add status styling to admin
     */
    public function add_status_styles() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders', 'shop_order'])) {
            return;
        }

        $settings = $this->module->get_settings();
        $color = !empty($settings['status_color']) ? $settings['status_color'] : '#ffba00';
        $text_color = $this->get_contrast_color($color);

        ?>
        <style>
            /* Order list status mark */
            .order-status.status-pending-refund,
            .wc-order-status.status-pending-refund {
                background: <?php echo esc_attr($color); ?>;
                color: <?php echo esc_attr($text_color); ?>;
            }

            /* Order list row highlight */
            .wp-list-table .type-shop_order.status-pending-refund,
            .woocommerce-orders-table .status-pending-refund {
                background-color: <?php echo esc_attr($color); ?>15;
            }

            /* Status badge in order details */
            .order-status-pending-refund {
                background: <?php echo esc_attr($color); ?>;
                color: <?php echo esc_attr($text_color); ?>;
                padding: 2px 8px;
                border-radius: 3px;
                font-weight: 600;
            }

            /* Status select option styling */
            #order_status option[value="wc-pending-refund"] {
                background-color: <?php echo esc_attr($color); ?>;
                color: <?php echo esc_attr($text_color); ?>;
            }

            /* HPOS table styling */
            .woocommerce-page .wp-list-table .order-status-pending-refund,
            .woocommerce_page_wc-orders .order-status.status-pending-refund {
                background: <?php echo esc_attr($color); ?>;
                color: <?php echo esc_attr($text_color); ?>;
            }
        </style>
        <?php
    }

    /**
     * Make pending-refund status editable
     *
     * @param bool $editable Whether order is editable
     * @param WC_Order $order Order object
     * @return bool Modified editable status
     */
    public function make_status_editable($editable, $order) {
        if ($order && $order->get_status() === self::STATUS_KEY) {
            return true;
        }
        return $editable;
    }

    /**
     * Add custom action button to order list
     *
     * @param array $actions Existing actions
     * @param WC_Order $order Order object
     * @return array Modified actions
     */
    public function add_order_actions($actions, $order) {
        // Add action to view pre-refund data if exists
        if ($order->get_status() === self::STATUS_KEY) {
            $refund_data = $order->get_meta('_pending_refund_data');
            if (!empty($refund_data)) {
                $actions['view_prerefund'] = [
                    'url' => admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id() . '#mad-refund-metabox'),
                    'name' => __('View Pre-Refund', 'mad-suite'),
                    'action' => 'view_prerefund',
                ];
            }
        }

        return $actions;
    }

    /**
     * Trigger actions when status changes to pending-refund
     *
     * @param int $order_id Order ID
     * @param WC_Order $order Order object
     */
    public function trigger_status_change($order_id, $order) {
        // Trigger email if enabled
        $settings = $this->module->get_settings();
        if (!empty($settings['email_enabled'])) {
            do_action('mad_refund_send_pending_email', $order_id, $order);
        }

        // Log the status change
        $logger = $this->module->get_logger();
        if ($logger) {
            $logger->log(sprintf(
                'Order #%d status changed to pending-refund',
                $order_id
            ));
        }

        // Add order note
        $order->add_order_note(
            __('Order status changed to Pending Refund. Pre-refund workflow initiated.', 'mad-suite'),
            false,
            true
        );
    }

    /**
     * Add status to WooCommerce reports
     *
     * @param array $statuses Report statuses
     * @return array Modified statuses
     */
    public function add_to_reports($statuses) {
        $statuses[] = self::STATUS_KEY;
        return $statuses;
    }

    /**
     * Add row class for HPOS compatibility
     *
     * @param array $classes CSS classes
     * @param WC_Order $order Order object
     * @return array Modified classes
     */
    public function add_hpos_row_class($classes, $order) {
        if ($order->get_status() === self::STATUS_KEY) {
            $classes[] = 'status-' . self::STATUS_KEY;
        }
        return $classes;
    }

    /**
     * Get contrasting text color for background
     *
     * @param string $hex_color Hex color code
     * @return string Black or white hex color
     */
    private function get_contrast_color($hex_color) {
        $hex = ltrim($hex_color, '#');

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Calculate luminance
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.5 ? '#000000' : '#ffffff';
    }

    /**
     * Get status slug
     *
     * @return string Status slug with wc- prefix
     */
    public function get_status_slug() {
        return self::STATUS_SLUG;
    }

    /**
     * Get status key
     *
     * @return string Status key without prefix
     */
    public function get_status_key() {
        return self::STATUS_KEY;
    }

    /**
     * Check if order has pending refund status
     *
     * @param WC_Order|int $order Order object or ID
     * @return bool True if order has pending refund status
     */
    public function is_pending_refund($order) {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }

        if (!$order) {
            return false;
        }

        return $order->get_status() === self::STATUS_KEY;
    }
}
