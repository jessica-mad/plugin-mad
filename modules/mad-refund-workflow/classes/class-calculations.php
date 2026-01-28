<?php
/**
 * Calculations Helper for Pre-Refund
 *
 * Handles tax and total calculations for refund items.
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

class MAD_Refund_Calculations {

    /**
     * Calculate refund totals for selected items
     *
     * @param WC_Order $order Order object
     * @param array $items Selected items with quantities
     * @param bool $include_shipping Whether to include shipping
     * @return array Calculated totals
     */
    public function calculate_refund_totals($order, $items, $include_shipping = false) {
        $subtotal = 0;
        $tax_total = 0;
        $item_details = [];

        foreach ($items as $item_id => $item_data) {
            $quantity = absint($item_data['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $order_item = $order->get_item($item_id);
            if (!$order_item) {
                continue;
            }

            $item_calc = $this->calculate_item_refund($order_item, $quantity);

            $subtotal += $item_calc['subtotal'];
            $tax_total += $item_calc['tax'];

            $item_details[$item_id] = $item_calc;
        }

        // Calculate shipping if included
        $shipping_total = 0;
        $shipping_tax = 0;

        if ($include_shipping) {
            $shipping_calc = $this->calculate_shipping_refund($order);
            $shipping_total = $shipping_calc['total'];
            $shipping_tax = $shipping_calc['tax'];
        }

        $total = $subtotal + $tax_total + $shipping_total + $shipping_tax;

        return [
            'subtotal' => round($subtotal, wc_get_price_decimals()),
            'tax' => round($tax_total + $shipping_tax, wc_get_price_decimals()),
            'shipping' => round($shipping_total, wc_get_price_decimals()),
            'shipping_tax' => round($shipping_tax, wc_get_price_decimals()),
            'total' => round($total, wc_get_price_decimals()),
            'items' => $item_details,
        ];
    }

    /**
     * Calculate refund amounts for a single item
     *
     * @param WC_Order_Item_Product $order_item Order item
     * @param int $quantity Quantity to refund
     * @return array Item calculations
     */
    public function calculate_item_refund($order_item, $quantity) {
        $original_qty = $order_item->get_quantity();

        // Prevent division by zero
        if ($original_qty <= 0) {
            return [
                'subtotal' => 0,
                'tax' => 0,
                'line_total' => 0,
                'unit_price' => 0,
                'unit_tax' => 0,
            ];
        }

        // Get original totals
        $line_subtotal = $order_item->get_subtotal();
        $line_tax = $order_item->get_subtotal_tax();

        // Calculate proportional amounts
        $ratio = $quantity / $original_qty;
        $refund_subtotal = $line_subtotal * $ratio;
        $refund_tax = $line_tax * $ratio;

        // Unit prices
        $unit_price = $line_subtotal / $original_qty;
        $unit_tax = $line_tax / $original_qty;

        return [
            'subtotal' => round($refund_subtotal, wc_get_price_decimals()),
            'tax' => round($refund_tax, wc_get_price_decimals()),
            'line_total' => round($refund_subtotal + $refund_tax, wc_get_price_decimals()),
            'unit_price' => round($unit_price, wc_get_price_decimals()),
            'unit_tax' => round($unit_tax, wc_get_price_decimals()),
            'quantity' => $quantity,
            'original_quantity' => $original_qty,
        ];
    }

    /**
     * Calculate shipping refund amounts
     *
     * @param WC_Order $order Order object
     * @return array Shipping calculations
     */
    public function calculate_shipping_refund($order) {
        $shipping_total = 0;
        $shipping_tax = 0;

        foreach ($order->get_shipping_methods() as $shipping_item) {
            $shipping_total += floatval($shipping_item->get_total());
            $shipping_tax += floatval($shipping_item->get_total_tax());
        }

        return [
            'total' => round($shipping_total, wc_get_price_decimals()),
            'tax' => round($shipping_tax, wc_get_price_decimals()),
            'line_total' => round($shipping_total + $shipping_tax, wc_get_price_decimals()),
        ];
    }

    /**
     * Calculate tax breakdown by rate
     *
     * @param WC_Order $order Order object
     * @param array $items Selected items
     * @return array Tax breakdown
     */
    public function calculate_tax_breakdown($order, $items) {
        $tax_rates = [];

        foreach ($items as $item_id => $item_data) {
            $quantity = absint($item_data['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $order_item = $order->get_item($item_id);
            if (!$order_item) {
                continue;
            }

            $original_qty = $order_item->get_quantity();
            $ratio = $quantity / max(1, $original_qty);

            // Get tax data for the item
            $taxes = $order_item->get_taxes();
            if (!empty($taxes['subtotal'])) {
                foreach ($taxes['subtotal'] as $rate_id => $tax_amount) {
                    if (!isset($tax_rates[$rate_id])) {
                        $tax_rates[$rate_id] = [
                            'rate_id' => $rate_id,
                            'label' => WC_Tax::get_rate_label($rate_id),
                            'rate_percent' => WC_Tax::get_rate_percent($rate_id),
                            'amount' => 0,
                        ];
                    }
                    $tax_rates[$rate_id]['amount'] += floatval($tax_amount) * $ratio;
                }
            }
        }

        // Round amounts
        foreach ($tax_rates as &$rate) {
            $rate['amount'] = round($rate['amount'], wc_get_price_decimals());
        }

        return $tax_rates;
    }

    /**
     * Validate refund amounts against order
     *
     * @param WC_Order $order Order object
     * @param array $refund_data Refund data to validate
     * @return array Validation result
     */
    public function validate_refund_amounts($order, $refund_data) {
        $errors = [];
        $warnings = [];

        // Check items
        if (empty($refund_data['items'])) {
            $errors[] = __('No items selected for refund.', 'mad-suite');
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        foreach ($refund_data['items'] as $item_id => $item) {
            $order_item = $order->get_item($item_id);

            if (!$order_item) {
                $errors[] = sprintf(
                    __('Item #%d not found in order.', 'mad-suite'),
                    $item_id
                );
                continue;
            }

            $original_qty = $order_item->get_quantity();
            $refund_qty = absint($item['quantity']);

            if ($refund_qty > $original_qty) {
                $errors[] = sprintf(
                    __('Refund quantity for "%1$s" (%2$d) exceeds ordered quantity (%3$d).', 'mad-suite'),
                    $order_item->get_name(),
                    $refund_qty,
                    $original_qty
                );
            }
        }

        // Check total doesn't exceed order total
        $order_total = $order->get_total();
        $refund_total = floatval($refund_data['total']);

        if ($refund_total > $order_total) {
            $errors[] = sprintf(
                __('Refund total (%1$s) exceeds order total (%2$s).', 'mad-suite'),
                wc_price($refund_total),
                wc_price($order_total)
            );
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
