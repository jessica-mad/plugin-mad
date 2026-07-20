<?php
/**
 * Campos de facturación en la página order-pay para presupuestos MAD.
 * Variables: $order, $countries, $default_country, $nonce.
 */

defined( 'ABSPATH' ) || exit;

$saved_country = $order->get_billing_country() ?: $default_country;
$fields        = WC()->countries->get_address_fields( $saved_country, 'billing_' );
?>

<div class="woocommerce-billing-fields mad-billing-fields">
    <h3><?php esc_html_e( 'Datos de facturación', 'mad-suite' ); ?></h3>
    <div class="woocommerce-billing-fields__field-wrapper">
        <?php
        foreach ( $fields as $key => $field ) {
            $field['return'] = false;
            $saved_value     = method_exists( $order, 'get_' . $key ) ? $order->{ 'get_' . $key }() : '';
            woocommerce_form_field( $key, $field, $saved_value );
        }
        ?>
        <input type="hidden" name="mad_billing_nonce" value="<?php echo esc_attr( $nonce ); ?>">
    </div>
</div>
