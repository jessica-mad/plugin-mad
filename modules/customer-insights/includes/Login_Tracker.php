<?php
/**
 * Login Tracker — guarda el momento del último login de cada usuario y,
 * al crear un pedido, congela en el propio pedido cuánto tiempo pasó
 * desde ese login hasta la compra.
 *
 * Solo aplica a usuarios registrados que inician sesión de verdad (no
 * invitados) y solo desde que este módulo está activo — no hay forma de
 * recuperar logins anteriores a su instalación.
 *
 * @package MAD_Suite/Customer_Insights
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_CI_Login_Tracker {

    private const LAST_LOGIN_META = '_mad_ci_last_login';
    public const ORDER_META       = '_mad_ci_login_to_order_seconds';

    public function init(): void {
        add_action( 'wp_login', [ $this, 'record_login' ], 10, 2 );

        // Se registra en ambos: cubre checkout clásico y de bloques, y
        // cualquier otra vía que termine creando el pedido (admin, API).
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'record_login_to_order_gap' ], 10, 1 );
        add_action( 'woocommerce_new_order',                [ $this, 'record_login_to_order_gap' ], 10, 1 );
    }

    public function record_login( string $user_login, WP_User $user ): void {
        update_user_meta( $user->ID, self::LAST_LOGIN_META, time() );
    }

    /** Congela en el pedido, en el momento de crearlo, cuánto pasó desde el último login registrado. */
    public function record_login_to_order_gap( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) return;

        // Ya calculado (el hook puede dispararse más de una vez para el mismo pedido).
        if ( '' !== $order->get_meta( self::ORDER_META, true ) ) return;

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return; // Invitado: no hay login que medir.

        $last_login = (int) get_user_meta( $user_id, self::LAST_LOGIN_META, true );
        if ( ! $last_login ) return; // Sin login registrado todavía para este usuario.

        $created = $order->get_date_created();
        if ( ! $created ) return;

        $seconds = $created->getTimestamp() - $last_login;
        if ( $seconds < 0 ) return; // Reloj inconsistente o login posterior al pedido — descartar.

        $order->update_meta_data( self::ORDER_META, $seconds );
        $order->save_meta_data();
    }
}
