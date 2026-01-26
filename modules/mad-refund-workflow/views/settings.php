<?php
/**
 * Settings Page View
 *
 * @package MAD_Suite
 * @subpackage MAD_Refund_Workflow
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

$option_key = MAD_Suite_Core::option_key($this->slug());
?>
<div class="wrap mad-refund-settings-wrap">
    <h1><?php echo esc_html($this->title()); ?></h1>

    <?php settings_errors(); ?>

    <p class="description">
        <?php esc_html_e('Configure the pre-refund workflow system for generating return invoices before processing actual refunds.', 'mad-suite'); ?>
    </p>

    <?php if (!$this->is_pdf_plugin_active()) : ?>
        <div class="pdf-plugin-notice">
            <span class="dashicons dashicons-warning"></span>
            <p>
                <?php
                printf(
                    /* translators: %s: plugin link */
                    wp_kses(
                        __('For full PDF functionality, please install <a href="%s" target="_blank">WooCommerce PDF Invoices & Packing Slips</a> by WP Overnight.', 'mad-suite'),
                        ['a' => ['href' => [], 'target' => []]]
                    ),
                    'https://wordpress.org/plugins/woocommerce-pdf-invoices-packing-slips/'
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php
        settings_fields($option_key . '_group');
        do_settings_sections($this->menu_slug());
        ?>

        <div class="submit-wrap">
            <?php submit_button(__('Save Settings', 'mad-suite'), 'primary', 'submit', false); ?>
        </div>
    </form>

    <hr style="margin-top: 40px;">

    <h2><?php esc_html_e('Workflow Guide', 'mad-suite'); ?></h2>

    <div class="workflow-guide" style="background: #fff; padding: 20px; border: 1px solid #c3c4c7; margin-top: 20px;">
        <h3><?php esc_html_e('How to use the Pre-Refund System', 'mad-suite'); ?></h3>

        <ol style="line-height: 1.8;">
            <li>
                <strong><?php esc_html_e('Step 1: Customer requests refund', 'mad-suite'); ?></strong><br>
                <span class="description"><?php esc_html_e('Change the order status to "Pending Refund" from the order edit page.', 'mad-suite'); ?></span>
            </li>
            <li>
                <strong><?php esc_html_e('Step 2: Select products for return', 'mad-suite'); ?></strong><br>
                <span class="description"><?php esc_html_e('In the Pre-Refund Items Selection meta box, select the products and quantities to be returned.', 'mad-suite'); ?></span>
            </li>
            <li>
                <strong><?php esc_html_e('Step 3: Generate return invoice', 'mad-suite'); ?></strong><br>
                <span class="description"><?php esc_html_e('Save the refund data and generate the PDF return invoice for customs/transport.', 'mad-suite'); ?></span>
            </li>
            <li>
                <strong><?php esc_html_e('Step 4: Receive and verify products', 'mad-suite'); ?></strong><br>
                <span class="description"><?php esc_html_e('Wait for the products to arrive and verify their condition.', 'mad-suite'); ?></span>
            </li>
            <li>
                <strong><?php esc_html_e('Step 5: Process actual refund', 'mad-suite'); ?></strong><br>
                <span class="description"><?php esc_html_e('Once verified, process the actual WooCommerce refund using the same products and quantities.', 'mad-suite'); ?></span>
            </li>
        </ol>
    </div>

    <div class="status-info" style="background: #f0f6fc; padding: 20px; border-left: 4px solid #2271b1; margin-top: 20px;">
        <h4 style="margin-top: 0;">
            <?php esc_html_e('Current Status Preview', 'mad-suite'); ?>
        </h4>
        <p>
            <?php esc_html_e('Status badge appearance:', 'mad-suite'); ?>
            <span class="status-preview-badge" style="background: <?php echo esc_attr($settings['status_color']); ?>; color: <?php echo $this->get_contrast_color($settings['status_color']); ?>; display: inline-block; padding: 4px 10px; border-radius: 3px; margin-left: 10px;">
                <?php echo esc_html($settings['status_name'] ?: __('Pending Refund', 'mad-suite')); ?>
            </span>
        </p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Initialize color picker
    $('.mad-color-picker').wpColorPicker({
        change: function(event, ui) {
            // Update preview
            var color = ui.color.toString();
            var textColor = getLuminance(color) > 0.5 ? '#000000' : '#ffffff';
            $('.status-preview-badge').css({
                'background-color': color,
                'color': textColor
            });
        }
    });

    // Calculate luminance for text color
    function getLuminance(hex) {
        hex = hex.replace('#', '');
        var r = parseInt(hex.substr(0, 2), 16) / 255;
        var g = parseInt(hex.substr(2, 2), 16) / 255;
        var b = parseInt(hex.substr(4, 2), 16) / 255;
        return 0.299 * r + 0.587 * g + 0.114 * b;
    }

    // Update status name preview
    $('input[name$="[status_name]"]').on('input', function() {
        var name = $(this).val() || '<?php echo esc_js(__('Pending Refund', 'mad-suite')); ?>';
        $('.status-preview-badge').text(name);
    });
});
</script>
<?php

/**
 * Helper function to get contrast color
 */
function get_contrast_color($hex) {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance > 0.5 ? '#000000' : '#ffffff';
}
