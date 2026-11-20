<?php
/**
 * Sección 7 — distinguir presupuestos y compras en la pantalla de Pedidos:
 * numeración P-/C-, columna "Tipo" + filtro, caja "Pedidos relacionados",
 * búsqueda por el número con prefijo, exclusión de Analytics y pestaña
 * "Mis presupuestos" en Mi cuenta.
 *
 * Todos los hooks de columna/filtro se registran para el CPT clásico Y
 * para HPOS a la vez — solo el que corresponde al modo de
 * almacenamiento activo llega a dispararse.
 *
 * @package MAD_Suite/Quotes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Order_Types {

    public function init(): void {
        add_filter( 'woocommerce_order_number', [ $this, 'prefix_order_number' ], 10, 2 );

        // Columna "Tipo" — CPT clásico y HPOS.
        add_filter( 'manage_shop_order_posts_columns',                  [ $this, 'add_type_column' ] );
        add_action( 'manage_shop_order_posts_custom_column',            [ $this, 'render_type_column_classic' ], 10, 2 );
        add_filter( 'woocommerce_shop_order_list_table_columns',        [ $this, 'add_type_column' ] );
        add_action( 'woocommerce_shop_order_list_table_custom_column',  [ $this, 'render_type_column_hpos' ], 10, 2 );

        // Filtro desplegable "Tipo" — CPT clásico y HPOS.
        add_action( 'restrict_manage_posts', [ $this, 'render_type_filter' ], 10, 2 );
        add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_type_filter' ], 10, 2 );

        // Aplicar el filtro elegido a la consulta — CPT clásico y HPOS.
        add_action( 'pre_get_posts', [ $this, 'filter_classic_query' ] );
        add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ $this, 'filter_hpos_query_args' ] );

        // Búsqueda "P-1234"/"C-1234": se quita el prefijo antes de que
        // WooCommerce intente emparejarlo contra el ID/número de pedido.
        add_action( 'current_screen', [ $this, 'maybe_strip_search_prefix' ] );

        // Caja "Pedidos relacionados" en la ficha del pedido.
        add_action( 'add_meta_boxes', [ $this, 'register_related_orders_meta_box' ] );

        // Analytics: los presupuestos y las reservas no son ventas.
        add_filter( 'woocommerce_analytics_excluded_order_statuses', [ $this, 'exclude_from_analytics' ] );

        // Mi cuenta: pestaña "Mis presupuestos" + excluir presupuestos de "Pedidos".
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_my_quotes_tab' ] );
        add_action( 'init', [ $this, 'add_my_quotes_endpoint' ] );
        add_action( 'woocommerce_account_mad-quotes_endpoint', [ $this, 'render_my_quotes_tab' ] );
        add_filter( 'woocommerce_my_account_my_orders_query', [ $this, 'exclude_quotes_from_my_orders' ] );
    }

    /* ────────────────────────────────────────────────────────────────
     * Numeración P-/C-.
     * ──────────────────────────────────────────────────────────────── */

    public function prefix_order_number( $order_number, $order ) {
        if ( ! $order instanceof WC_Order ) return $order_number;

        $type = (string) $order->get_meta( '_mad_order_type' );
        if ( 'quote' === $type ) return 'P-' . $order_number;
        if ( in_array( $type, [ 'quote_purchase', 'pro_purchase' ], true ) ) return 'C-' . $order_number;

        return $order_number;
    }

    /* ────────────────────────────────────────────────────────────────
     * Columna "Tipo".
     * ──────────────────────────────────────────────────────────────── */

    public function add_type_column( array $columns ): array {
        if ( isset( $columns['mad_order_type'] ) ) return $columns;

        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'order_status' === $key ) {
                $new['mad_order_type'] = __( 'Tipo', 'mad-suite' );
            }
        }
        if ( ! isset( $new['mad_order_type'] ) ) {
            $new['mad_order_type'] = __( 'Tipo', 'mad-suite' );
        }
        return $new;
    }

    public function render_type_column_classic( string $column, $post_id ): void {
        if ( 'mad_order_type' !== $column ) return;
        $order = wc_get_order( $post_id );
        if ( $order ) echo $this->type_badge_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function render_type_column_hpos( string $column, $order ): void {
        if ( 'mad_order_type' !== $column ) return;
        if ( $order instanceof WC_Order ) echo $this->type_badge_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function type_badge_html( WC_Order $order ): string {
        $type = (string) $order->get_meta( '_mad_order_type' );

        $labels = [
            'quote'          => [ __( 'Presupuesto', 'mad-suite' ), '#2980b9' ],
            'quote_purchase' => [ __( 'Compra', 'mad-suite' ), '#27ae60' ],
            'pro_purchase'   => [ __( 'Compra profesional', 'mad-suite' ), '#8e44ad' ],
        ];

        if ( ! isset( $labels[ $type ] ) ) {
            return '<span style="color:#999;">' . esc_html__( 'Tienda', 'mad-suite' ) . '</span>';
        }

        [ $label, $color ] = $labels[ $type ];
        $html = sprintf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:%s;color:#fff;font-size:0.85em;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );

        if ( 'quote' !== $type ) {
            $parent_id = (int) $order->get_meta( '_mad_quote_parent' );
            $parent    = $parent_id ? wc_get_order( $parent_id ) : null;
            if ( $parent instanceof WC_Order ) {
                $html .= '<br><a href="' . esc_url( $parent->get_edit_order_url() ) . '">'
                    . esc_html( sprintf(
                        /* translators: %s: número del presupuesto de origen */
                        __( 'de %s', 'mad-suite' ),
                        $parent->get_order_number()
                    ) )
                    . '</a>';
            }
        }

        if ( $this->needs_proof_review( $order ) ) {
            $html .= '<br><span style="color:#e65100;font-weight:600;" title="'
                . esc_attr__( 'Tiene un comprobante subido a la espera de que se apruebe o rechace el pago.', 'mad-suite' )
                . '">⚠ ' . esc_html__( 'Comprobante por revisar', 'mad-suite' ) . '</span>';
        }

        return $html;
    }

    /** "En espera" + comprobante subido = nadie ha pulsado todavía "Aprobar pago"/"Rechazar" (sección 5, puntos 4 y 5 del documento de cambios). */
    private function needs_proof_review( WC_Order $order ): bool {
        return 'on-hold' === $order->get_status() && (bool) $order->get_meta( '_mad_payment_proof_url' );
    }

    /* ────────────────────────────────────────────────────────────────
     * Filtro desplegable "Tipo" sobre la lista de pedidos.
     * ──────────────────────────────────────────────────────────────── */

    private function type_filter_options(): array {
        return [
            ''               => __( 'Todos', 'mad-suite' ),
            'quote'          => __( 'Presupuestos', 'mad-suite' ),
            'quote_purchase' => __( 'Compras de presupuesto', 'mad-suite' ),
            'pro_purchase'   => __( 'Compras profesionales', 'mad-suite' ),
            'store'          => __( 'Pedidos de tienda', 'mad-suite' ),
            'proof_review'   => __( 'Comprobante por revisar', 'mad-suite' ),
        ];
    }

    /**
     * Comparte el mismo método para restrict_manage_posts ($post_type, $which)
     * y woocommerce_order_list_table_restrict_manage_orders ($order_type, $which)
     * — misma forma de argumentos en ambos.
     */
    public function render_type_filter( $order_type = '', $which = '' ): void {
        if ( 'shop_order' !== $order_type ) return;
        if ( '' !== $which && 'top' !== $which ) return;

        $current = isset( $_GET['mad_order_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['mad_order_type_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        echo '<select name="mad_order_type_filter">';
        foreach ( $this->type_filter_options() as $value => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $value ),
                selected( $current, $value, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    public function filter_classic_query( $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) return;
        if ( 'shop_order' !== $query->get( 'post_type' ) ) return;

        $type = isset( $_GET['mad_order_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['mad_order_type_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( '' === $type ) return;

        if ( 'store' === $type ) {
            $query->set( 'meta_query', [ [ 'key' => '_mad_order_type', 'compare' => 'NOT EXISTS' ] ] );
            return;
        }

        if ( 'proof_review' === $type ) {
            $query->set( 'post_status', [ 'wc-on-hold' ] );
            $query->set( 'meta_query', [ [ 'key' => '_mad_payment_proof_url', 'compare' => 'EXISTS' ] ] );
            return;
        }

        $query->set( 'meta_key', '_mad_order_type' );
        $query->set( 'meta_value', $type );
    }

    public function filter_hpos_query_args( array $args ): array {
        $type = isset( $_GET['mad_order_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['mad_order_type_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( '' === $type ) return $args;

        if ( 'store' === $type ) {
            $args['meta_query'] = array_merge( $args['meta_query'] ?? [], [
                [ 'key' => '_mad_order_type', 'compare' => 'NOT EXISTS' ],
            ] );
            return $args;
        }

        if ( 'proof_review' === $type ) {
            $args['status']     = [ 'wc-on-hold' ];
            $args['meta_query'] = array_merge( $args['meta_query'] ?? [], [
                [ 'key' => '_mad_payment_proof_url', 'compare' => 'EXISTS' ],
            ] );
            return $args;
        }

        $args['meta_key']   = '_mad_order_type';
        $args['meta_value'] = $type;
        return $args;
    }

    /** Admite buscar "P-1234"/"C-1234" (o en minúsculas) tal cual aparece en la columna Pedido. */
    public function maybe_strip_search_prefix( $screen ): void {
        if ( ! $screen || ! in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) return;
        if ( empty( $_GET['s'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification

        $s = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( preg_match( '/^[PC]-(\d+)$/i', $s, $m ) ) {
            $_GET['s']     = $m[1];
            $_REQUEST['s'] = $m[1];
        }
    }

    /* ────────────────────────────────────────────────────────────────
     * Caja "Pedidos relacionados".
     * ──────────────────────────────────────────────────────────────── */

    public function register_related_orders_meta_box(): void {
        $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

        add_meta_box(
            'mad_related_orders',
            __( 'Pedidos relacionados', 'mad-suite' ),
            [ $this, 'render_related_orders_meta_box' ],
            $screen,
            'side',
            'default'
        );
    }

    public function render_related_orders_meta_box( $post_or_order ): void {
        $order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : wc_get_order( $post_or_order );
        if ( ! $order instanceof WC_Order ) return;

        $type = (string) $order->get_meta( '_mad_order_type' );

        if ( 'quote' === $type ) {
            $this->render_related_orders_for_quote( $order );
            return;
        }

        if ( in_array( $type, [ 'quote_purchase', 'pro_purchase' ], true ) ) {
            $this->render_related_quote_for_purchase( $order );
            return;
        }

        echo '<p>' . esc_html__( 'Este pedido no está relacionado con presupuestos ni reservas.', 'mad-suite' ) . '</p>';
    }

    private function render_related_orders_for_quote( WC_Order $quote ): void {
        $reservations = new MAD_Reservations();
        $purchase_ids = $reservations->purchase_orders_for_quote( $quote->get_id() );

        if ( empty( $purchase_ids ) ) {
            echo '<p>' . esc_html__( 'Todavía no hay pedidos de compra ligados a este presupuesto.', 'mad-suite' ) . '</p>';
            return;
        }

        echo '<ul style="margin:0;">';
        foreach ( $purchase_ids as $purchase_id ) {
            $purchase = wc_get_order( $purchase_id );
            if ( ! $purchase ) continue;

            printf(
                '<li><a href="%s">%s</a> — %s</li>',
                esc_url( $purchase->get_edit_order_url() ),
                esc_html( $purchase->get_order_number() ),
                esc_html( wc_get_order_status_name( $purchase->get_status() ) )
            );
        }
        echo '</ul>';
    }

    private function render_related_quote_for_purchase( WC_Order $purchase ): void {
        $parent_id = (int) $purchase->get_meta( '_mad_quote_parent' );
        $parent    = $parent_id ? wc_get_order( $parent_id ) : null;

        if ( $parent instanceof WC_Order ) {
            printf(
                '<p>%s <a href="%s">%s</a></p>',
                esc_html__( 'Presupuesto de origen:', 'mad-suite' ),
                esc_url( $parent->get_edit_order_url() ),
                esc_html( $parent->get_order_number() )
            );
        } else {
            echo '<p>' . esc_html__( 'Compra profesional directa (sin presupuesto de origen).', 'mad-suite' ) . '</p>';
        }

        if ( 'quote-reserved' === $purchase->get_status() ) {
            $minutes = (int) $purchase->get_meta( '_mad_reservation_minutes' );
            $created = $purchase->get_date_created();
            $until   = ( $created ? $created->getTimestamp() : time() ) + $minutes * MINUTE_IN_SECONDS;

            printf(
                '<p>%s %s</p>',
                esc_html__( 'Reservada hasta:', 'mad-suite' ),
                esc_html( mad_quotes_madrid_time( $until ) . ' (' . date_i18n( 'd/m/Y', $until ) . ')' )
            );
        }
    }

    /* ────────────────────────────────────────────────────────────────
     * Analytics.
     * ──────────────────────────────────────────────────────────────── */

    public function exclude_from_analytics( array $statuses ): array {
        return array_values( array_unique( array_merge( $statuses, [ 'quote-pending', 'quote-sent', 'quote-reserved' ] ) ) );
    }

    /* ────────────────────────────────────────────────────────────────
     * Mi cuenta: "Mis presupuestos".
     * ──────────────────────────────────────────────────────────────── */

    public function add_my_quotes_tab( array $items ): array {
        if ( isset( $items['mad-quotes'] ) ) return $items;

        $new = [];
        foreach ( $items as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'orders' === $key ) {
                $new['mad-quotes'] = __( 'Mis presupuestos', 'mad-suite' );
            }
        }
        if ( ! isset( $new['mad-quotes'] ) ) {
            $new['mad-quotes'] = __( 'Mis presupuestos', 'mad-suite' );
        }
        return $new;
    }

    public function add_my_quotes_endpoint(): void {
        add_rewrite_endpoint( 'mad-quotes', EP_ROOT | EP_PAGES );
    }

    public function render_my_quotes_tab(): void {
        $customer_id = get_current_user_id();
        if ( ! $customer_id ) return;

        $quotes = wc_get_orders( [
            'customer_id' => $customer_id,
            'meta_key'    => '_mad_order_type',
            'meta_value'  => 'quote',
            'limit'       => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );

        if ( empty( $quotes ) ) {
            echo '<p>' . esc_html__( 'Todavía no tienes ninguna lista de precios.', 'mad-suite' ) . '</p>';
            return;
        }

        echo '<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr>'
            . '<th>' . esc_html__( 'Presupuesto', 'mad-suite' ) . '</th>'
            . '<th>' . esc_html__( 'Fecha', 'mad-suite' ) . '</th>'
            . '<th>' . esc_html__( 'Estado', 'mad-suite' ) . '</th>'
            . '<th>' . esc_html__( 'Total', 'mad-suite' ) . '</th>'
            . '<th>&nbsp;</th>'
            . '</tr></thead><tbody>';

        foreach ( $quotes as $quote ) {
            printf(
                '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td><a class="button" href="%5$s">%6$s</a></td></tr>',
                esc_html( $quote->get_order_number() ),
                esc_html( wc_format_datetime( $quote->get_date_created() ) ),
                esc_html( wc_get_order_status_name( $quote->get_status() ) ),
                wp_kses_post( $quote->get_formatted_order_total() ),
                esc_url( mad_quotes_quote_selection_url( $quote ) ),
                esc_html__( 'Ver', 'mad-suite' )
            );
        }

        echo '</tbody></table>';
    }

    /** El "Pedidos" nativo no debe listar presupuestos — solo compras (de presupuesto, profesionales) y pedidos normales de tienda. */
    public function exclude_quotes_from_my_orders( array $args ): array {
        $args['meta_query']   = $args['meta_query'] ?? [];
        $args['meta_query'][] = [
            'relation' => 'OR',
            [ 'key' => '_mad_order_type', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_mad_order_type', 'value' => 'quote', 'compare' => '!=' ],
        ];
        return $args;
    }
}
