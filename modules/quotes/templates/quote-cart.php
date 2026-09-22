<?php
/**
 * Plantilla del carrito de presupuesto (MAD Quotes).
 *
 * Reemplaza la página de carrito de WooCommerce para usuarios con rol de presupuesto.
 * Muestra los productos como un listado limpio sin precios; el usuario confirma
 * cantidades y procede a solicitar el presupuesto.
 *
 * @package MAD_Suite/Quotes
 */

defined( 'ABSPATH' ) || exit;

$settings    = mad_quotes_get_settings();
$_btn_stored = $settings['quote_submit_button_text'] ?? [];
$_lang       = apply_filters( 'wpml_current_language', null );
$_def_lang   = apply_filters( 'wpml_default_language', null );
if ( is_string( $_btn_stored ) ) {
    $btn_text = $_btn_stored;
} elseif ( is_array( $_btn_stored ) ) {
    $btn_text = ( $_lang && isset( $_btn_stored[ $_lang ] ) && $_btn_stored[ $_lang ] !== '' )
        ? $_btn_stored[ $_lang ]
        : ( ( $_def_lang && isset( $_btn_stored[ $_def_lang ] ) ) ? $_btn_stored[ $_def_lang ] : ( reset( $_btn_stored ) ?: '' ) );
} else {
    $btn_text = '';
}
unset( $_btn_stored, $_lang, $_def_lang );
$btn_label = trim( $btn_text ) !== '' ? $btn_text : __( 'Enviar solicitud de lista de precios', 'mad-suite' );

// Si un rol quedó marcado en ambas listas por error, el de presupuesto
// (precio oculto) manda siempre sobre gestión de tienda.
$is_store_manager = $this->current_user_is_store_manager_role() && ! $this->current_user_is_quote_role();
if ( $is_store_manager ) {
    $btn_label = __( 'Enviar presupuesto al cliente', 'mad-suite' );
}

get_header( 'shop' );
?>

<div id="mad-quote-cart" class="woocommerce">

    <?php wc_print_notices(); ?>

    <?php if ( WC()->cart->is_empty() ) : ?>

        <p class="cart-empty">
            <?php esc_html_e( 'Tu solicitud de presupuesto está vacía.', 'mad-suite' ); ?>
        </p>
        <p>
            <a href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
                <?php esc_html_e( 'Ver productos', 'mad-suite' ); ?>
            </a>
        </p>

    <?php else : ?>

        <h1 class="mad-quote-cart__title">
            <?php esc_html_e( 'Tu solicitud de presupuesto', 'mad-suite' ); ?>
        </h1>

        <form class="mad-quote-cart__form"
              action="<?php echo esc_url( wc_get_cart_url() ); ?>"
              method="post">

            <?php if ( $is_store_manager ) : $grand_total = [ 'excl' => 0.0, 'iva' => 0.0, 'incl' => 0.0 ]; endif; ?>
            <table class="mad-quote-cart__table">
                <thead>
                    <tr>
                        <th class="product-remove">&nbsp;</th>
                        <th class="product-thumbnail">&nbsp;</th>
                        <th class="product-name"><?php esc_html_e( 'Producto', 'mad-suite' ); ?></th>
                        <th class="product-quantity"><?php esc_html_e( 'Cantidad', 'mad-suite' ); ?></th>
                        <?php if ( $is_store_manager ) : ?>
                            <th class="product-price"><?php esc_html_e( 'Precio base', 'mad-suite' ); ?></th>
                            <th class="product-iva"><?php esc_html_e( 'IVA', 'mad-suite' ); ?></th>
                            <th class="product-subtotal"><?php esc_html_e( 'Total línea', 'mad-suite' ); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
                    $product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                    $product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
                    if ( ! $product || ! $product->exists() || 0 === $cart_item['quantity'] ) continue;
                    $product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $product->is_visible() ? $product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );

                    if ( $is_store_manager ) {
                        $breakdown = $this->get_store_manager_price_breakdown( $product );
                        if ( $breakdown ) {
                            $qty = (int) $cart_item['quantity'];
                            $grand_total['excl'] += $breakdown['excl'] * $qty;
                            $grand_total['iva']  += $breakdown['iva']  * $qty;
                            $grand_total['incl'] += $breakdown['incl'] * $qty;
                        }
                    }
                ?>
                    <tr class="woocommerce-cart-form__cart-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">

                        <!-- Eliminar -->
                        <td class="product-remove">
                            <?php
                            echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                'woocommerce_cart_item_remove_link',
                                sprintf(
                                    '<a href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
                                    esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
                                    esc_html__( 'Eliminar este artículo', 'mad-suite' ),
                                    esc_attr( $product_id ),
                                    esc_attr( $product->get_sku() )
                                ),
                                $cart_item_key
                            );
                            ?>
                        </td>

                        <!-- Imagen -->
                        <td class="product-thumbnail">
                            <?php
                            $thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $product->get_image(), $cart_item, $cart_item_key );
                            if ( $product_permalink ) {
                                printf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $thumbnail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            } else {
                                echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            }
                            ?>
                        </td>

                        <!-- Nombre -->
                        <td class="product-name" data-title="<?php esc_attr_e( 'Producto', 'mad-suite' ); ?>">
                            <?php if ( $product_permalink ) : ?>
                                <a href="<?php echo esc_url( $product_permalink ); ?>">
                                    <?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $cart_item, $cart_item_key ) ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $cart_item, $cart_item_key ) ); ?>
                            <?php endif; ?>

                            <?php do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key ); ?>
                            <?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </td>

                        <!-- Cantidad -->
                        <td class="product-quantity" data-title="<?php esc_attr_e( 'Cantidad', 'mad-suite' ); ?>">
                            <?php
                            if ( $product->is_sold_individually() ) {
                                echo '1';
                            } else {
                                woocommerce_quantity_input(
                                    [
                                        'input_name'   => "cart[{$cart_item_key}][qty]",
                                        'input_value'  => $cart_item['quantity'],
                                        'max_value'    => $product->get_max_purchase_quantity(),
                                        'min_value'    => '0',
                                        'product_name' => $product->get_name(),
                                    ],
                                    $product
                                );
                            }
                            ?>
                        </td>

                        <?php if ( $is_store_manager ) : ?>
                            <td class="product-price" data-title="<?php esc_attr_e( 'Precio base', 'mad-suite' ); ?>">
                                <?php echo $breakdown ? wc_price( $breakdown['excl'] ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </td>
                            <td class="product-iva" data-title="<?php esc_attr_e( 'IVA', 'mad-suite' ); ?>">
                                <?php echo $breakdown ? wc_price( $breakdown['iva'] ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </td>
                            <td class="product-subtotal" data-title="<?php esc_attr_e( 'Total línea', 'mad-suite' ); ?>">
                                <?php echo $breakdown ? wc_price( $breakdown['incl'] * (int) $cart_item['quantity'] ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </td>
                        <?php endif; ?>

                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ( $is_store_manager ) : ?>
                    <tfoot>
                        <tr class="mad-quote-cart__grand-total">
                            <td colspan="4" style="text-align:right;"><strong><?php esc_html_e( 'Total presupuesto:', 'mad-suite' ); ?></strong></td>
                            <td><?php echo wc_price( $grand_total['excl'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo wc_price( $grand_total['iva'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><strong><?php echo wc_price( $grand_total['incl'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>

            <!-- Botones del formulario -->
            <div class="mad-quote-cart__update">
                <button type="submit"
                        class="mad-quote-cart__btn-update"
                        name="update_cart"
                        value="<?php esc_attr_e( 'Actualizar solicitud', 'mad-suite' ); ?>">
                    <?php esc_html_e( 'Actualizar solicitud', 'mad-suite' ); ?>
                </button>
                <?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
            </div>

        </form>

        <!-- Formulario de solicitud (email + notas) -->
        <div class="mad-quote-cart__actions">
            <form method="post" class="mad-quote-submit-form">
                <?php wp_nonce_field( 'mad_create_quote', 'mad_create_quote_nonce' ); ?>
                <?php $current_user = wp_get_current_user(); ?>

                <?php if ( $is_store_manager ) : ?>
                    <p class="mad-quote-cart__field">
                        <label for="mad-quote-client-name">
                            <?php esc_html_e( 'Nombre del cliente', 'mad-suite' ); ?>
                        </label>
                        <input type="text"
                               id="mad-quote-client-name"
                               name="mad_client_name"
                               required>
                    </p>
                <?php endif; ?>

                <p class="mad-quote-cart__field">
                    <label for="mad-quote-email">
                        <?php $is_store_manager ? esc_html_e( 'Email del cliente', 'mad-suite' ) : esc_html_e( 'Email', 'mad-suite' ); ?>
                    </label>
                    <input type="email"
                           id="mad-quote-email"
                           name="olofane_email"
                           value="<?php echo esc_attr( $is_store_manager ? '' : $current_user->user_email ); ?>"
                           required>
                </p>

                <p class="mad-quote-cart__field">
                    <label for="mad-quote-notas">
                        <?php esc_html_e( 'Notas (opcional)', 'mad-suite' ); ?>
                    </label>
                    <textarea id="mad-quote-notas"
                              name="olofane_notas"
                              rows="4"></textarea>
                </p>

                <button type="submit"
                        name="mad_submit_quote"
                        class="mad-quote-cart__proceed">
                    <?php echo esc_html( $btn_label ); ?>
                </button>
            </form>

            <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
               class="mad-quote-cart__back">
                <?php esc_html_e( 'Seguir viendo productos', 'mad-suite' ); ?>
            </a>
        </div>

    <?php endif; ?>

</div><!-- #mad-quote-cart -->

<?php get_footer( 'shop' ); ?>
