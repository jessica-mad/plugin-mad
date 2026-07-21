<?php
/**
 * Formulario de subida de comprobante de transferencia bancaria.
 * Se muestra en Mi Cuenta → Ver pedido para presupuestos en estado on-hold.
 *
 * @var WC_Order $order
 * @var string   $existing_proof  URL del comprobante ya subido (vacía si no existe).
 */

defined( 'ABSPATH' ) || exit;
?>

<section class="woocommerce-order-payment-proof" style="margin-top:2em;">
    <h2 class="woocommerce-order-details__title">
        <?php esc_html_e( 'Comprobante de transferencia', 'mad-suite' ); ?>
    </h2>

    <?php if ( $existing_proof ) : ?>

        <div class="woocommerce-message">
            <?php esc_html_e( 'Hemos recibido tu comprobante y estamos verificando el pago. Te notificaremos cuando tu pedido pase a procesamiento.', 'mad-suite' ); ?>
        </div>

    <?php else : ?>

        <p><?php esc_html_e( 'Si ya has realizado la transferencia bancaria, sube aquí el comprobante para agilizar la verificación.', 'mad-suite' ); ?></p>

        <form id="mad-proof-upload-form" enctype="multipart/form-data" novalidate>
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="mad_proof_file">
                    <?php esc_html_e( 'Adjuntar comprobante', 'mad-suite' ); ?>
                    <span class="required">*</span>
                </label>
                <input type="file"
                       id="mad_proof_file"
                       name="proof_file"
                       accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                       required>
                <span class="description" style="font-size:0.85em;color:#767676;">
                    <?php esc_html_e( 'Formatos admitidos: JPG, PNG, WebP, GIF, PDF · Máximo 10 MB', 'mad-suite' ); ?>
                </span>
            </p>

            <p class="form-row">
                <button type="submit" class="button wp-element-button" id="mad-proof-submit">
                    <?php esc_html_e( 'Enviar comprobante', 'mad-suite' ); ?>
                </button>
            </p>

            <div id="mad-proof-result" role="alert" style="display:none;margin-top:1em;"></div>
        </form>

    <?php endif; ?>
</section>
