<?php
/**
 * Vista: crear un presupuesto desde cero desde el admin (sin que el cliente
 * haya pasado por el carrito). Al enviar, crea el pedido y redirige a la
 * pantalla del pedido, donde el editor de precios ya existente permite
 * fijar precios y enviar el presupuesto — ese paso no se duplica acá.
 */
defined( 'ABSPATH' ) || exit;

$errors = [
    'email' => __( 'Ingresá un email válido para el destinatario.', 'mad-suite' ),
    'items' => __( 'Agregá al menos un producto.', 'mad-suite' ),
];
$error = isset( $_GET['mad_error'] ) ? sanitize_key( $_GET['mad_error'] ) : '';
?>
<div class="wrap">
    <h1>
        <?php esc_html_e( 'Crear presupuesto', 'mad-suite' ); ?>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => $this->menu_slug() ], admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
            <?php esc_html_e( '← Volver a ajustes', 'mad-suite' ); ?>
        </a>
    </h1>

    <?php if ( $error && isset( $errors[ $error ] ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $errors[ $error ] ); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php esc_html_e( 'Armá el presupuesto vos mismo: elegí (o escribí) a quién va dirigido y agregá los productos. Al crearlo vas a caer en la pantalla del pedido, donde ya podés ajustar los precios finales y apretar "Enviar presupuesto" como siempre.', 'mad-suite' ); ?>
    </p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mad-new-quote-form" style="max-width:780px;">
        <input type="hidden" name="action" value="mad_quotes_create_manual">
        <input type="hidden" name="customer_id" id="mad_nq_customer_id" value="0">
        <?php wp_nonce_field( 'mad_quotes_create_manual' ); ?>

        <div class="card" style="max-width:100%;padding:20px;background:#fff;border:1px solid #ccd0d4;margin-bottom:20px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Destinatario', 'mad-suite' ); ?></h2>

            <p>
                <label for="mad_nq_customer_search"><strong><?php esc_html_e( 'Cliente existente (opcional)', 'mad-suite' ); ?></strong></label><br>
                <input type="text" id="mad_nq_customer_search" class="regular-text" autocomplete="off"
                       placeholder="<?php esc_attr_e( 'Buscar por nombre o email…', 'mad-suite' ); ?>">
                <span id="mad_nq_customer_results" class="mad-nq-results"></span>
                <span id="mad_nq_customer_selected" style="display:none;margin-left:6px;">
                    <span id="mad_nq_customer_selected_text" style="background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:3px 8px;font-size:12px;"></span>
                    <a href="#" id="mad_nq_customer_clear" style="color:#b32d2e;text-decoration:none;margin-left:4px;">&times;</a>
                </span>
                <br><span class="description"><?php esc_html_e( 'Si lo dejás vacío, el presupuesto queda como invitado (solo con el email de abajo).', 'mad-suite' ); ?></span>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="mad_nq_email"><?php esc_html_e( 'Email', 'mad-suite' ); ?></label></th>
                    <td><input type="email" name="billing_email" id="mad_nq_email" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mad_nq_first_name"><?php esc_html_e( 'Nombre', 'mad-suite' ); ?></label></th>
                    <td><input type="text" name="billing_first_name" id="mad_nq_first_name" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mad_nq_last_name"><?php esc_html_e( 'Apellido', 'mad-suite' ); ?></label></th>
                    <td><input type="text" name="billing_last_name" id="mad_nq_last_name" class="regular-text"></td>
                </tr>
            </table>
        </div>

        <div class="card" style="max-width:100%;padding:20px;background:#fff;border:1px solid #ccd0d4;margin-bottom:20px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Productos', 'mad-suite' ); ?></h2>

            <p>
                <input type="text" id="mad_nq_product_search" class="regular-text" autocomplete="off"
                       placeholder="<?php esc_attr_e( 'Buscar producto por nombre o SKU…', 'mad-suite' ); ?>">
                <span id="mad_nq_product_results" class="mad-nq-results"></span>
            </p>

            <table class="wp-list-table widefat striped" id="mad_nq_items_table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Producto', 'mad-suite' ); ?></th>
                        <th style="width:110px;"><?php esc_html_e( 'Cantidad', 'mad-suite' ); ?></th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody id="mad_nq_items_body">
                    <tr id="mad_nq_items_empty"><td colspan="3"><?php esc_html_e( 'Todavía no agregaste productos.', 'mad-suite' ); ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="card" style="max-width:100%;padding:20px;background:#fff;border:1px solid #ccd0d4;margin-bottom:20px;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Nota interna (opcional)', 'mad-suite' ); ?></h2>
            <textarea name="admin_notes" rows="3" style="width:100%;max-width:600px;" placeholder="<?php esc_attr_e( 'Se guarda como nota del pedido, no la ve el cliente todavía.', 'mad-suite' ); ?>"></textarea>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary button-hero" id="mad_nq_submit">
                <?php esc_html_e( 'Crear presupuesto →', 'mad-suite' ); ?>
            </button>
        </p>
    </form>
</div>

<style>
.mad-nq-results {
    position: relative;
    display: inline-block;
}
.mad-nq-results .mad-nq-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    z-index: 999;
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 2px 6px rgba(0,0,0,.1);
    min-width: 320px;
    max-height: 260px;
    overflow-y: auto;
}
.mad-nq-dropdown div {
    padding: 6px 10px;
    cursor: pointer;
    font-size: 13px;
    border-bottom: 1px solid #f0f0f1;
}
.mad-nq-dropdown div:hover {
    background: #f0f6fc;
}
.mad-nq-dropdown .mad-nq-empty {
    color: #888;
    cursor: default;
}
#mad_nq_items_table input[type="number"] {
    width: 80px;
}
</style>
