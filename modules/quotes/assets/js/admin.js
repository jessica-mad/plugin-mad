/* global mad_quotes_admin_params, jQuery */
/**
 * MAD Quotes – Admin JS
 *
 * Handles the "Quote Complete" and "Send Quote" buttons on the
 * WooCommerce order edit screen.
 */
( function ( $ ) {
    'use strict';

    var params = ( typeof mad_quotes_admin_params !== 'undefined' ) ? mad_quotes_admin_params : {};

    // ------------------------------------------------------------------ //
    //  "Quote Complete" button
    // ------------------------------------------------------------------ //
    $( document ).on( 'click', '#mad_quote_complete', function () {
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( params.i18n_updating || 'Actualizando…' );

        $.post( params.ajax_url, {
            action  : 'mad_quotes_update_status',
            order_id: params.order_id,
            status  : 'quote-complete',
            nonce   : params.nonce_update_status,
        } )
        .done( function () {
            location.reload();
        } )
        .fail( function () {
            $btn.prop( 'disabled', false ).text( params.i18n_complete || 'Presupuesto completo' );
            alert( params.i18n_error || 'Error. Inténtalo de nuevo.' );
        } );
    } );

    // ------------------------------------------------------------------ //
    //  "Send Quote" / "Resend Quote" button
    // ------------------------------------------------------------------ //
    $( document ).on( 'click', '#mad_send_quote', function () {
        var $btn  = $( this );
        var $note = $( '#mad_quote_admin_note' );
        $btn.prop( 'disabled', true ).text( params.i18n_sending || 'Enviando…' );

        $.post( params.ajax_url, {
            action    : 'mad_quotes_send_quote',
            order_id  : params.order_id,
            admin_note: $note.val() || '',
            nonce     : params.nonce_send_quote,
        } )
        .done( function ( response ) {
            if ( response === 'quote-sent' ) {
                $btn.text( params.i18n_resend || 'Reenviar presupuesto' ).prop( 'disabled', false );
                $( '#mad_quote_msg' ).text( params.i18n_sent || '✔ Presupuesto enviado' );
            } else {
                $btn.prop( 'disabled', false );
                alert( params.i18n_error || 'Error. Inténtalo de nuevo.' );
            }
        } )
        .fail( function () {
            $btn.prop( 'disabled', false );
            alert( params.i18n_error || 'Error. Inténtalo de nuevo.' );
        } );
    } );

} )( jQuery );
