/* global mad_reservation_timer_params, jQuery */
/**
 * MAD Quotes – Reservas con plazo: cuenta atrás en vivo + confirmación por
 * AJAX antes de dar nada por libre otra vez. Se pinta siempre en cliente
 * (nunca horneado en el HTML) porque el sitio usa LiteSpeed Cache: la
 * ficha de producto y el catálogo nunca deciden en PHP si una pieza está
 * reservada — solo imprimen un marcador vacío con el product_id, y este
 * script resuelve el estado real por AJAX en cada carga de página,
 * incluida una servida desde caché. Lo mismo para la página de pago y la
 * de selección de un presupuesto (sección 3).
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

        var $notice = $el.closest( '.mad-reservation-notice[data-mad-product-id]' );
        if ( $notice.length ) {
            refreshNotice( $notice );
            return;
        }

        // La cuenta atrás de la ficha vive dentro del propio botón de
        // compra (sección 4) — no dentro de .mad-reservation-notice, que
        // se vacía y oculta. Se resuelve por el product_id que
        // applyFichaButtonState() le dejó al botón.
        var btnProductId = $el.closest( '[data-mad-product-id]' ).attr( 'data-mad-product-id' );
        if ( btnProductId ) {
            var $ownerNotice = $( '.mad-reservation-notice[data-mad-product-id="' + btnProductId + '"]' );
            if ( $ownerNotice.length ) {
                refreshNotice( $ownerNotice );
                return;
            }
        }

        var $selectionRow = $el.closest( '.mad-qp__row[data-mad-product-id]' );
        if ( $selectionRow.length ) {
            refreshSelectionRow( $selectionRow );
        }
    }

    /* ────────────────────────────────────────────────────────────────
     * Ficha de producto y catálogo — marcador vacío + AJAX en lote.
     * ──────────────────────────────────────────────────────────────── */

    function allNotices() {
        return $( '.mad-reservation-notice[data-mad-product-id]' );
    }

    /**
     * El aviso aparte ya no se muestra como caja (sección 4 del documento
     * de cambios): el propio botón de compra se convierte en el mensaje —
     * texto + cuenta atrás en el botón, deshabilitado de verdad (bloquea
     * clic y toque), y recupera su texto original sin recargar al liberarse.
     */
    function applyFichaButtonState( $notice, unavailable, message ) {
        var $form = $notice.nextAll( 'form.cart' ).first();
        if ( ! $form.length ) $form = $notice.closest( '.summary, .product' ).find( 'form.cart' ).first();
        var $btn = $form.find( '.single_add_to_cart_button, button[type="submit"]' ).first();
        if ( ! $btn.length ) return;

        if ( unavailable ) {
            if ( undefined === $btn.data( 'madOriginalHtml' ) ) {
                $btn.data( 'madOriginalHtml', $btn.html() );
            }
            $btn.html( message )
                .attr( 'data-mad-product-id', $notice.attr( 'data-mad-product-id' ) )
                .prop( 'disabled', true )
                .addClass( 'mad-reservation-disabled-btn' )
                .attr( 'aria-disabled', 'true' )
                .on( 'click.madReservation touchend.madReservation', function ( e ) {
                    e.preventDefault();
                    e.stopPropagation();
                } );
        } else {
            var original = $btn.data( 'madOriginalHtml' );
            if ( undefined !== original ) $btn.html( original );
            $btn.prop( 'disabled', false )
                .removeClass( 'mad-reservation-disabled-btn' )
                .removeAttr( 'aria-disabled' )
                .off( 'click.madReservation touchend.madReservation' );
        }
    }

    /** Enlace "Avísenme" + su formulario desplegable — visibles solo mientras la pieza no está disponible. */
    function toggleNotifyUi( productId, unavailable ) {
        var $link = $( '.mad-notify-link[data-mad-product-id="' + productId + '"]' );
        var $form = $( '.mad-notify-form[data-mad-product-id="' + productId + '"]' );

        if ( unavailable ) {
            $link.closest( '.mad-notify-link-wrap' ).show();
        } else {
            $link.closest( '.mad-notify-link-wrap' ).hide();
            $form.hide();
        }
    }

    function applyNoticeState( $notice, state, message ) {
        $notice.attr( 'data-mad-state', state ).hide().empty();

        var unavailable = ( 'reserved' === state || 'pending_proof' === state );

        if ( $notice.is( '[data-mad-disable-form]' ) ) {
            applyFichaButtonState( $notice, unavailable, message );
            toggleNotifyUi( $notice.attr( 'data-mad-product-id' ), unavailable );
        }

        if ( $notice.is( '[data-mad-disable-link]' ) ) {
            var $li = $notice.closest( 'li.product, .product' );
            $li.find( '.add_to_cart_button' ).each( function () {
                var $btn = $( this );
                if ( unavailable ) {
                    $btn.addClass( 'disabled mad-reservation-disabled-btn' )
                        .attr( 'aria-disabled', 'true' )
                        .on( 'click.madReservation', function ( e ) { e.preventDefault(); } );
                } else {
                    $btn.removeClass( 'disabled mad-reservation-disabled-btn' )
                        .removeAttr( 'aria-disabled' )
                        .off( 'click.madReservation' );
                }
            } );
        }
    }

    /* ────────────────────────────────────────────────────────────────
     * Enlace "Avísenme si no se completa la compra" (sección 4).
     * ──────────────────────────────────────────────────────────────── */

    $( document ).on( 'click', '.mad-notify-link', function ( e ) {
        e.preventDefault();
        var id = $( this ).attr( 'data-mad-product-id' );
        $( this ).closest( '.mad-notify-link-wrap' ).hide();
        $( '.mad-notify-form[data-mad-product-id="' + id + '"]' ).show();
    } );

    $( document ).on( 'click', '.mad-notify-submit', function () {
        var $form  = $( this ).closest( '.mad-notify-form' );
        var id     = $form.attr( 'data-mad-product-id' );
        var email  = $form.find( '.mad-notify-email' ).val();
        var $msg   = $form.find( '.mad-notify-msg' );

        if ( ! $form.find( '.mad-notify-privacy' ).is( ':checked' ) ) {
            $msg.css( 'color', 'red' ).text( '—' );
            return;
        }

        var $btn = $( this );
        $btn.prop( 'disabled', true );
        $msg.text( '' );

        $.post( params.ajax_url, {
            action    : 'mad_quote_notify_subscribe',
            product_id: id,
            email     : email,
            website   : $form.find( '.mad-notify-trap' ).val(), // campo trampa: un bot lo rellena, un humano nunca lo ve.
            nonce     : $form.find( '.mad-notify-nonce' ).val(),
        } )
        .done( function ( response ) {
            if ( response && response.success ) {
                $form.html( '<span style="font-size:12px;">' + $form.find( '.mad-notify-confirm-text' ).val() + '</span>' );
            } else {
                $btn.prop( 'disabled', false );
                $msg.css( 'color', 'red' ).text( ( response && response.data && response.data.message ) || 'Error.' );
            }
        } )
        .fail( function () {
            $btn.prop( 'disabled', false );
            $msg.css( 'color', 'red' ).text( 'Error.' );
        } );
    } );

    /** Vuelve a consultar UN aviso (cuenta atrás a cero) y lo actualiza, sin recargar la página. */
    function refreshNotice( $notice ) {
        var id = $notice.attr( 'data-mad-product-id' );
        $.post( params.ajax_url, { action: 'mad_quote_state', product_id: id } )
            .done( function ( response ) {
                if ( ! response || ! response.success || ! response.data || ! response.data.product ) return;
                applyNoticeState( $notice, response.data.product.state, response.data.product.message );
            } );
    }

    /** Al cargar la página (incluida una copia cacheada): resuelve de una vez el estado de todas las piezas marcadas. */
    function initNotices() {
        var $notices = allNotices();
        if ( ! $notices.length ) return;

        var ids = [];
        $notices.each( function () {
            var id = $( this ).attr( 'data-mad-product-id' );
            if ( id && -1 === ids.indexOf( id ) ) ids.push( id );
        } );

        $.post( params.ajax_url, { action: 'mad_quote_state', product_ids: ids } )
            .done( function ( response ) {
                if ( ! response || ! response.success || ! response.data || ! response.data.products ) return;

                $notices.each( function () {
                    var $notice = $( this );
                    var info    = response.data.products[ $notice.attr( 'data-mad-product-id' ) ];
                    if ( info ) applyNoticeState( $notice, info.state, info.message );
                } );
            } );
    }

    /* ────────────────────────────────────────────────────────────────
     * Página de pago de una reserva.
     * ──────────────────────────────────────────────────────────────── */

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

    /** Página de pago: sondeo periódico aunque no haya llegado a cero todavía (aprobación/rechazo manual del admin). */
    function pollPaymentBox() {
        var $box = $( '.mad-quote-purchase--payment[data-mad-order-key]' );
        if ( $box.length ) pollOrderState( $box, false );
    }

    /* ────────────────────────────────────────────────────────────────
     * Página de selección de un presupuesto.
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Confirma el estado real de una fila de la página de selección. Si ya
     * está libre, reactivamos la casilla sin perder las demás ya marcadas
     * por el cliente — nunca recargamos esta página.
     */
    function refreshSelectionRow( $row ) {
        var id = $row.attr( 'data-mad-product-id' );

        $.post( params.ajax_url, { action: 'mad_quote_state', product_id: id } )
            .done( function ( response ) {
                if ( ! response || ! response.success || ! response.data || ! response.data.product ) return;

                var state = response.data.product.state;
                $row.attr( 'data-mad-state', state );

                if ( 'available' !== state ) return;

                var $checkbox = $row.find( '.mad-qp__checkbox' );
                if ( $checkbox.length ) $checkbox.prop( 'disabled', false );

                $row.find( '.mad-qp__state' ).html(
                    '<span class="mad-qp__badge mad-qp__badge--available">' + ( params.i18n && params.i18n.available ? params.i18n.available : 'Disponible' ) + '</span>'
                );
            } );
    }

    /** Filas no disponibles de la página de selección: reintenta cada 20s por si se liberan mientras el cliente sigue mirando. */
    function pollSelectionRows() {
        $( '.mad-quote-purchase--selection .mad-qp__row' ).each( function () {
            var $row = $( this );
            if ( 'available' === $row.attr( 'data-mad-state' ) ) return;
            refreshSelectionRow( $row );
        } );
    }

    initNotices();
    tickCountdowns();
    setInterval( tickCountdowns, 1000 );
    setInterval( initNotices, 20000 );
    setInterval( pollSelectionRows, 20000 );
    setInterval( pollPaymentBox, 15000 );

} )( jQuery );
