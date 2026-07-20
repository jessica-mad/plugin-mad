<?php
/**
 * Campos de facturación inyectados en la página order-pay de WooCommerce
 * para pedidos de presupuesto que aún no tienen dirección guardada.
 *
 * Variables disponibles: $order, $countries, $default_country, $nonce.
 */

defined( 'ABSPATH' ) || exit;

$saved = [
    'first_name' => $order->get_billing_first_name(),
    'last_name'  => $order->get_billing_last_name(),
    'company'    => $order->get_billing_company(),
    'address_1'  => $order->get_billing_address_1(),
    'address_2'  => $order->get_billing_address_2(),
    'city'       => $order->get_billing_city(),
    'state'      => $order->get_billing_state(),
    'postcode'   => $order->get_billing_postcode(),
    'country'    => $order->get_billing_country() ?: $default_country,
    'phone'      => $order->get_billing_phone(),
];
?>

<div class="mad-billing-fields" style="margin-bottom:2em;">
    <h3><?php esc_html_e( 'Datos de facturación', 'mad-suite' ); ?></h3>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1em;">

        <p>
            <label for="billing_first_name"><?php esc_html_e( 'Nombre', 'mad-suite' ); ?> <abbr title="<?php esc_attr_e( 'requerido', 'mad-suite' ); ?>">*</abbr></label>
            <input type="text" id="billing_first_name" name="billing_first_name"
                   value="<?php echo esc_attr( $saved['first_name'] ); ?>" required style="width:100%;">
        </p>

        <p>
            <label for="billing_last_name"><?php esc_html_e( 'Apellidos', 'mad-suite' ); ?> <abbr title="<?php esc_attr_e( 'requerido', 'mad-suite' ); ?>">*</abbr></label>
            <input type="text" id="billing_last_name" name="billing_last_name"
                   value="<?php echo esc_attr( $saved['last_name'] ); ?>" required style="width:100%;">
        </p>

        <p style="grid-column:1/-1;">
            <label for="billing_company"><?php esc_html_e( 'Empresa (opcional)', 'mad-suite' ); ?></label>
            <input type="text" id="billing_company" name="billing_company"
                   value="<?php echo esc_attr( $saved['company'] ); ?>" style="width:100%;">
        </p>

        <p style="grid-column:1/-1;">
            <label for="billing_address_1"><?php esc_html_e( 'Dirección', 'mad-suite' ); ?> <abbr title="<?php esc_attr_e( 'requerido', 'mad-suite' ); ?>">*</abbr></label>
            <input type="text" id="billing_address_1" name="billing_address_1"
                   value="<?php echo esc_attr( $saved['address_1'] ); ?>" required style="width:100%;">
        </p>

        <p style="grid-column:1/-1;">
            <label for="billing_address_2"><?php esc_html_e( 'Apartamento, piso, etc. (opcional)', 'mad-suite' ); ?></label>
            <input type="text" id="billing_address_2" name="billing_address_2"
                   value="<?php echo esc_attr( $saved['address_2'] ); ?>" style="width:100%;">
        </p>

        <p>
            <label for="billing_city"><?php esc_html_e( 'Ciudad', 'mad-suite' ); ?> <abbr title="<?php esc_attr_e( 'requerido', 'mad-suite' ); ?>">*</abbr></label>
            <input type="text" id="billing_city" name="billing_city"
                   value="<?php echo esc_attr( $saved['city'] ); ?>" required style="width:100%;">
        </p>

        <p>
            <label for="billing_postcode"><?php esc_html_e( 'Código postal', 'mad-suite' ); ?> <abbr title="<?php esc_attr_e( 'requerido', 'mad-suite' ); ?>">*</abbr></label>
            <input type="text" id="billing_postcode" name="billing_postcode"
                   value="<?php echo esc_attr( $saved['postcode'] ); ?>" required style="width:100%;">
        </p>

        <p>
            <label for="billing_country"><?php esc_html_e( 'País', 'mad-suite' ); ?> <abbr title="<?php esc_attr_e( 'requerido', 'mad-suite' ); ?>">*</abbr></label>
            <select id="billing_country" name="billing_country" required style="width:100%;">
                <?php foreach ( $countries as $code => $name ) : ?>
                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $saved['country'], $code ); ?>>
                        <?php echo esc_html( $name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label for="billing_phone"><?php esc_html_e( 'Teléfono', 'mad-suite' ); ?></label>
            <input type="tel" id="billing_phone" name="billing_phone"
                   value="<?php echo esc_attr( $saved['phone'] ); ?>" style="width:100%;">
        </p>

    </div>

    <input type="hidden" name="mad_billing_nonce" value="<?php echo esc_attr( $nonce ); ?>">
</div>
