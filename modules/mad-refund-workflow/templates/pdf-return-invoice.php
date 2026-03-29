<?php
/**
 * Fallback PDF Template for Return Invoice
 *
 * This template is used when WP Overnight PDF plugin is not available.
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 *
 * @var WC_Order $order Order object
 * @var array $refund_data Refund data
 * @var array $settings Module settings
 */

defined('ABSPATH') || exit;

$shop_name = get_bloginfo('name');
$shop_address = get_option('woocommerce_store_address', '');
$shop_city = get_option('woocommerce_store_city', '');
$shop_postcode = get_option('woocommerce_store_postcode', '');
$shop_country = get_option('woocommerce_default_country', '');

$document_title = $settings['pdf_title'] ?? __('Return Invoice', 'mad-suite');
$customs_text = $settings['pdf_customs_text'] ?? '';
$footer_text = $settings['pdf_footer_text'] ?? '';

$currency = $order->get_currency();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($document_title); ?> - <?php echo esc_html($order->get_order_number()); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            padding: 40px;
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }

        .company-info {
            flex: 1;
        }

        .company-info h1 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #000;
        }

        .document-info {
            text-align: right;
            flex: 1;
        }

        .document-info h2 {
            font-size: 20px;
            color: #c00;
            margin-bottom: 10px;
        }

        .document-info p {
            margin-bottom: 5px;
        }

        .addresses {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }

        .address-block {
            flex: 1;
            padding: 15px;
            background: #f9f9f9;
            margin-right: 15px;
        }

        .address-block:last-child {
            margin-right: 0;
        }

        .address-block h3 {
            font-size: 12px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 10px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }

        .order-reference {
            background: #f5f5f5;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #333;
        }

        .order-reference strong {
            color: #000;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table.items th {
            background: #333;
            color: #fff;
            padding: 10px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
        }

        table.items td {
            padding: 12px 10px;
            border-bottom: 1px solid #ddd;
        }

        table.items tr:nth-child(even) {
            background: #f9f9f9;
        }

        table.items .qty,
        table.items .price {
            text-align: right;
        }

        table.items .sku {
            color: #666;
            font-size: 11px;
        }

        .totals {
            width: 300px;
            margin-left: auto;
            margin-bottom: 30px;
        }

        .totals table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals th,
        .totals td {
            padding: 8px 12px;
            text-align: right;
        }

        .totals th {
            font-weight: normal;
            color: #666;
        }

        .totals td {
            font-weight: bold;
        }

        .totals .total-row {
            border-top: 2px solid #333;
            font-size: 16px;
        }

        .totals .total-row th,
        .totals .total-row td {
            padding-top: 15px;
        }

        .customs-notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
        }

        .customs-notice h4 {
            margin-bottom: 10px;
            color: #856404;
        }

        .customs-notice p {
            margin: 0;
            color: #856404;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #666;
            font-size: 11px;
        }

        .footer p {
            margin-bottom: 5px;
        }

        @media print {
            body {
                padding: 20px;
            }

            .no-print {
                display: none;
            }
        }

        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #2271b1;
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 14px;
            border-radius: 4px;
        }

        .print-button:hover {
            background: #135e96;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        <?php esc_html_e('Print / Save as PDF', 'mad-suite'); ?>
    </button>

    <div class="header">
        <div class="company-info">
            <h1><?php echo esc_html($shop_name); ?></h1>
            <?php if ($shop_address) : ?>
                <p><?php echo esc_html($shop_address); ?></p>
            <?php endif; ?>
            <?php if ($shop_city || $shop_postcode) : ?>
                <p><?php echo esc_html($shop_postcode . ' ' . $shop_city); ?></p>
            <?php endif; ?>
        </div>
        <div class="document-info">
            <h2><?php echo esc_html($document_title); ?></h2>
            <p><strong><?php esc_html_e('Document No.:', 'mad-suite'); ?></strong> RI-<?php echo esc_html($order->get_order_number()); ?></p>
            <p><strong><?php esc_html_e('Date:', 'mad-suite'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format'))); ?></p>
        </div>
    </div>

    <div class="addresses">
        <div class="address-block">
            <h3><?php esc_html_e('Billing Address', 'mad-suite'); ?></h3>
            <?php echo wp_kses_post($order->get_formatted_billing_address()); ?>
            <?php if ($order->get_billing_email()) : ?>
                <p><?php echo esc_html($order->get_billing_email()); ?></p>
            <?php endif; ?>
            <?php if ($order->get_billing_phone()) : ?>
                <p><?php echo esc_html($order->get_billing_phone()); ?></p>
            <?php endif; ?>
        </div>
        <div class="address-block">
            <h3><?php esc_html_e('Shipping Address', 'mad-suite'); ?></h3>
            <?php
            $shipping_address = $order->get_formatted_shipping_address();
            echo $shipping_address ? wp_kses_post($shipping_address) : wp_kses_post($order->get_formatted_billing_address());
            ?>
        </div>
    </div>

    <div class="order-reference">
        <p>
            <strong><?php esc_html_e('Reference Order:', 'mad-suite'); ?></strong> #<?php echo esc_html($order->get_order_number()); ?>
            &nbsp;&nbsp;|&nbsp;&nbsp;
            <strong><?php esc_html_e('Original Order Date:', 'mad-suite'); ?></strong> <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?>
        </p>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 50%;"><?php esc_html_e('Product', 'mad-suite'); ?></th>
                <th style="width: 15%;"><?php esc_html_e('SKU', 'mad-suite'); ?></th>
                <th style="width: 10%;" class="qty"><?php esc_html_e('Qty', 'mad-suite'); ?></th>
                <th style="width: 12%;" class="price"><?php esc_html_e('Unit Price', 'mad-suite'); ?></th>
                <th style="width: 13%;" class="price"><?php esc_html_e('Total', 'mad-suite'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($refund_data['items'] as $item_id => $item) :
                $unit_price = $item['subtotal'] / max(1, $item['quantity']);
                $line_total = $item['subtotal'] + $item['tax'];
            ?>
                <tr>
                    <td>
                        <?php echo esc_html($item['name']); ?>
                        <br>
                        <small class="return-info">
                            <?php
                            printf(
                                esc_html__('Return: %1$d of %2$d', 'mad-suite'),
                                $item['quantity'],
                                $item['original_quantity']
                            );
                            ?>
                        </small>
                    </td>
                    <td class="sku"><?php echo esc_html($item['sku'] ?: '—'); ?></td>
                    <td class="qty"><?php echo esc_html($item['quantity']); ?></td>
                    <td class="price"><?php echo wc_price($unit_price, ['currency' => $currency]); ?></td>
                    <td class="price"><?php echo wc_price($line_total, ['currency' => $currency]); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr>
                <th><?php esc_html_e('Subtotal:', 'mad-suite'); ?></th>
                <td><?php echo wc_price($refund_data['subtotal'], ['currency' => $currency]); ?></td>
            </tr>
            <?php if (!empty($refund_data['tax']) && $refund_data['tax'] > 0) : ?>
                <tr>
                    <th><?php esc_html_e('Tax:', 'mad-suite'); ?></th>
                    <td><?php echo wc_price($refund_data['tax'], ['currency' => $currency]); ?></td>
                </tr>
            <?php endif; ?>
            <?php if (!empty($refund_data['include_shipping']) && !empty($refund_data['shipping'])) :
                $shipping_total = $refund_data['shipping'] + ($refund_data['shipping_tax'] ?? 0);
            ?>
                <tr>
                    <th><?php esc_html_e('Shipping:', 'mad-suite'); ?></th>
                    <td><?php echo wc_price($shipping_total, ['currency' => $currency]); ?></td>
                </tr>
            <?php endif; ?>
            <tr class="total-row">
                <th><?php esc_html_e('Total to Refund:', 'mad-suite'); ?></th>
                <td><?php echo wc_price($refund_data['total'], ['currency' => $currency]); ?></td>
            </tr>
        </table>
    </div>

    <?php if (!empty($customs_text)) : ?>
        <div class="customs-notice">
            <h4><?php esc_html_e('Important Notice', 'mad-suite'); ?></h4>
            <p><?php echo wp_kses_post($customs_text); ?></p>
        </div>
    <?php endif; ?>

    <div class="footer">
        <?php if (!empty($footer_text)) : ?>
            <p><?php echo wp_kses_post($footer_text); ?></p>
        <?php endif; ?>
        <p>
            <?php
            printf(
                esc_html__('Generated on %s', 'mad-suite'),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'))
            );
            ?>
        </p>
        <p><?php echo esc_html($shop_name); ?></p>
    </div>
</body>
</html>
