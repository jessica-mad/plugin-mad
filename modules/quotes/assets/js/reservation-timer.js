/* global mad_reservation_timer_params, jQuery */
/**
 * MAD Quotes – Reservas con plazo: cuenta atrás en vivo + confirmación por
 * AJAX antes de dar nada por libre otra vez. Se pinta siempre en cliente
 * (nunca horneado en el HTML) porque el sitio usa LiteSpeed Cache: una
 * página de producto o de catálogo cacheada no debe quedarse mostrando un
 * estado de reserva ya caducado.
 */
( function ( $ ) {
    'use strict';

    var params = ( typeof mad_reservation_timer_params !== 'undefined' ) ? mad_reservation_timer_params : {};
    if ( ! params.ajax_url ) return;

    function formatMMSS( seconds ) {
        seconds = Math.max( 0, seconds );
        var m = Math.floor( seconds / 60 );
        var s = seconds % 60;
        return m + ':' + ( s < 10 ? '0' : '' ) + s;
    }

    function tickCountdowns() {
        $( '.mad-reservation-countdown[data-mad-until]' ).each( function () {
            var $el   = $( this );
            var until = parseInt( $el.attr( 'data-mad-until' ), 10 );
            var left  = until - Math.floor( Date.now() / 1000 );

            if ( left <= 0 ) {
                $el.text( formatMMSS( 0 ) );
                if ( ! $el.data( 'madExpiredChecked' ) ) {
                    $el.data( 'madExpiredChecked', true );
                    confirmAndRefresh( $el );
                }
                return;
            }

            $el.text( formatMMSS( left ) );
        } );
    }

    function confirmAndRefresh( $el ) {
        var $paymentBox = $el.closest( '.mad-quote-purchase--payment[data-mad-order-key]' );
        if ( $paymentBox.length ) {
            pollOrderState( $paymentBox, true );
            return;
        }

        var $productRow = $el.closest( '[data-mad-product-id]' );
        if ( $productRow.length ) {
            var insideSelection = $productRow.closest( '.mad-quote-purchase--selection' ).length > 0;
            refreshProductState( $productRow.attr( 'data-mad-product-id' ), $productRow, ! insideSelection );
        }
    }

    /**
     * Confirma el estado real de un producto. Si ya está libre: en la
     * página de producto/catálogo recargamos (el botón de compra y el resto
     * de la ficha deben volver a su forma normal); en la página de
     * selección reactivamos la casilla sin perder las demás ya marcadas.
     */
    function refreshProductState( productId, $scope, allowReload ) {
        $.post( params.ajax_url, { action: 'mad_quote_state', product_id: productId } )
            .done( function ( response ) {
                if ( ! response || ! response.success || ! response.data || ! response.data.product ) return;

                var state = response.data.product.state;
                $scope.attr( 'data-mad-state', state );

                if ( 'available' !== state ) return;

                if ( allowReload ) {
                    window.location.reload();
                    return;
                }

                var $checkbox = $scope.find( '.mad-qp__checkbox' );
                if ( $checkbox.length ) {
                    $checkbox.prop( 'disabled', false );
                }
                $scope.find( '.mad-qp__state' ).html(
                    '<span class="mad-qp__badge mad-qp__badge--available">' + ( params.i18n && params.i18n.available ? params.i18n.available : 'Disponible' ) + '</span>'
                );
            } );
    }

    /** Página de pago: si el estado del pedido cambió, recargamos para mostrar el desenlace real. */
    function pollOrderState( $box, forceReloadIfStillReserved ) {
        var orderKey = $box.attr( 'data-mad-order-key' );

        $.post( params.ajax_url, { action: 'mad_quote_state', order_key: orderKey } )
            .done( function ( response ) {
                if ( ! response || ! response.success || ! response.data || ! response.data.order ) return;

                var status   = response.data.order.status;
                var previous = $box.attr( 'data-mad-last-status' );

                if ( previous && previous !== status ) {
                    window.location.reload();
                    return;
                }

                $box.attr( 'data-mad-last-status', status );

                if ( forceReloadIfStillReserved && 'quote-reserved' !== status ) {
                    window.location.reload();
                }
            } );
    }

    /** Filas no disponibles de la página de selección: reintenta cada 20s por si se liberan mientras el cliente sigue mirando. */
    function pollSelectionRows() {
        $( '.mad-quote-purchase--selection .mad-qp__row' ).each( function () {
            var $row = $( this );
            if ( 'available' === $row.attr( 'data-mad-state' ) ) return;
            refreshProductState( $row.attr( 'data-mad-product-id' ), $row, false );
        } );
    }

    /** Página de pago: sondeo periódico aunque no haya llegado a cero todavía (aprobación/rechazo manual del admin). */
    function pollPaymentBox() {
        var $box = $( '.mad-quote-purchase--payment[data-mad-order-key]' );
        if ( $box.length ) pollOrderState( $box, false );
    }

    tickCountdowns();
    setInterval( tickCountdowns, 1000 );
    setInterval( pollSelectionRows, 20000 );
    setInterval( pollPaymentBox, 15000 );

} )( jQuery );
