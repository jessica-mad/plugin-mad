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

$settings = mad_quotes_get_settings();
$btn_text = trim( $settings['quote_button_text'] ?? '' );
$btn_label = $btn_text !== '' ? $btn_text : __( 'Solicitar presupuesto', 'mad-suite' );

get_header( 'shop' );
?>

<div id="mad-quote-cart" class="woocommerce">

    <?php wc_print_notices(); ?>

    <?php if ( WC()->cart->is_empty() ) : ?>

        <p class="cart-empty woocommerce-info">
            <?php esc_html_e( 'Tu solicitud de presupuesto está vacía.', 'mad-suite' ); ?>
        </p>
        <p>
            <a href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>"
               class="button wc-backward">
                <?php esc_html_e( 'Ver productos', 'mad-suite' ); ?>
            </a>
        </p>

    <?php else : ?>

        <h1 class="mad-quote-cart__title">
            <?php esc_html_e( 'Tu solicitud de presupuesto', 'mad-suite' ); ?>
        </h1>

        <form class="mad-quote-cart__form woocommerce-cart-form"
              action="<?php echo esc_url( wc_get_cart_url() ); ?>"
              method="post">

            <table class="mad-quote-cart__table shop_table shop_table_responsive">
                <thead>
                    <tr>
                        <th class="product-remove">&nbsp;</th>
                        <th class="product-thumbnail">&nbsp;</th>
                        <th class="product-name"><?php esc_html_e( 'Producto', 'mad-suite' ); ?></th>
                        <th class="product-quantity"><?php esc_html_e( 'Cantidad', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
                    $product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                    $product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
                    if ( ! $product || ! $product->exists() || 0 === $cart_item['quantity'] ) continue;
                    $product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $product->is_visible() ? $product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
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

                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Botones del formulario -->
            <div class="mad-quote-cart__update">
                <button type="submit"
                        class="button"
                        name="update_cart"
                        value="<?php esc_attr_e( 'Actualizar solicitud', 'mad-suite' ); ?>">
                    <?php esc_html_e( 'Actualizar solicitud', 'mad-suite' ); ?>
                </button>
                <?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
            </div>

        </form>

        <!-- Acciones principales -->
        <div class="mad-quote-cart__actions">
            <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>"
               class="button alt mad-quote-cart__proceed">
                <?php echo esc_html( $btn_label ); ?>
            </a>
            <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
               class="button mad-quote-cart__back">
                <?php esc_html_e( 'Seguir viendo productos', 'mad-suite' ); ?>
            </a>
        </div>

    <?php endif; ?>

</div><!-- #mad-quote-cart -->

<?php get_footer( 'shop' ); ?>
