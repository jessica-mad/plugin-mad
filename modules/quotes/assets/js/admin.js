/* global mad_quotes_admin_params, jQuery */
/**
 * MAD Quotes – Admin JS
 *
 * Handles the "Quote Complete" and "Send / Resend Quote" buttons on the
 * WooCommerce order edit screen, including per-line price editing.
 */
( function ( $ ) {
    'use strict';

    var params = ( typeof mad_quotes_admin_params !== 'undefined' ) ? mad_quotes_admin_params : {};

    // ------------------------------------------------------------------ //
    //  "Quote Complete" button                                             //
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
    //  "Send Quote" / "Resend Quote" button                               //
    //  Collects editable line prices before posting.                      //
    // ------------------------------------------------------------------ //
    $( document ).on( 'click', '#mad_send_quote', function () {
        var $btn  = $( this );
        var $note = $( '#mad_quote_admin_note' );
        var $msg  = $( '#mad_quote_msg' );

        // Collect per-line prices: { item_id: price }
        var line_prices = {};
        $( '.mad-quote-line-price' ).each( function () {
            var item_id = $( this ).data( 'item-id' );
            if ( item_id ) {
                line_prices[ item_id ] = $( this ).val();
            }
        } );

        $btn.prop( 'disabled', true ).text( params.i18n_sending || 'Enviando…' );
        $msg.text( '' );

        $.post( params.ajax_url, {
            action      : 'mad_quotes_send_quote',
            order_id    : params.order_id,
            admin_note  : $note.val() || '',
            line_prices : line_prices,
            nonce       : params.nonce_send_quote,
        } )
        .done( function ( response ) {
            if ( response && response.success ) {
                $msg.text( params.i18n_sent || '✔ Presupuesto enviado' );
                setTimeout( function () { location.reload(); }, 3000 );
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

    // ------------------------------------------------------------------ //
    //  Comprobante de transferencia – subida desde el admin               //
    // ------------------------------------------------------------------ //

    // Mostrar/ocultar formulario al pulsar "Reemplazar"
    $( document ).on( 'click', '#mad-proof-replace-btn', function () {
        $( '#mad-proof-admin-upload' ).slideToggle( 150 );
    } );

    $( document ).on( 'click', '#mad-proof-admin-submit', function () {
        var file = $( '#mad-proof-admin-file' )[ 0 ].files[ 0 ];
        if ( ! file ) return;

        var allowed = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf' ];
        if ( allowed.indexOf( file.type ) === -1 ) {
            $( '#mad-proof-admin-msg' ).css( 'color', 'red' ).text( params.i18n_file_error || 'Tipo no permitido.' );
            return;
        }

        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( params.i18n_uploading || 'Verificando…' );
        $( '#mad-proof-admin-msg' ).text( '' );

        var fd = new FormData();
        fd.append( 'action',     'mad_admin_upload_payment_proof' );
        fd.append( 'nonce',      params.nonce_proof );
        fd.append( 'order_id',   params.order_id );
        fd.append( 'proof_file', file );

        $.ajax( {
            url        : params.ajax_url,
            type       : 'POST',
            data       : fd,
            processData: false,
            contentType: false,
        } )
        .done( function ( response ) {
            if ( response && response.success ) {
                var d = response.data;
                $( '#mad-proof-admin-msg' ).css( 'color', 'green' ).text( d.message );

                // Actualizar vista previa inline sin recargar
                var preview = d.is_pdf
                    ? '<embed src="' + d.proof_url + '" type="application/pdf" width="100%" height="260" style="display:block;border:1px solid #ddd;margin-bottom:4px;">'
                    : '<img src="' + d.proof_url + '" style="max-width:100%;max-height:260px;border:1px solid #ddd;display:block;margin-bottom:4px;">';
                preview += '<a href="' + d.proof_url + '" target="_blank" style="font-size:12px;">↗ Abrir en pestaña nueva</a>';
                if ( d.verified ) {
                    preview += '<span style="color:green;margin-left:10px;font-size:12px;">✔ Verificado por IA</span>';
                }

                var $preview = $( '#mad-proof-preview' );
                if ( $preview.length ) {
                    $preview.html( preview );
                } else {
                    $( '<div id="mad-proof-preview" style="margin-bottom:8px;">' + preview + '</div>' )
                        .insertBefore( '#mad-proof-admin-upload' );
                }

                // Mostrar botón reemplazar si no estaba
                if ( ! $( '#mad-proof-replace-btn' ).length ) {
                    $( '<button type="button" id="mad-proof-replace-btn" class="button button-small" style="margin-bottom:8px;">Reemplazar comprobante</button>' )
                        .insertBefore( '#mad-proof-admin-upload' );
                }

                $( '#mad-proof-admin-upload' ).slideUp( 150 );
            } else {
                var msg = ( response && response.data && response.data.message ) ? response.data.message : ( params.i18n_error || 'Error.' );
                $( '#mad-proof-admin-msg' ).css( 'color', 'red' ).text( msg );
            }
            $btn.prop( 'disabled', false ).text( 'Subir nuevo comprobante' );
        } )
        .fail( function () {
            $( '#mad-proof-admin-msg' ).css( 'color', 'red' ).text( params.i18n_error || 'Error.' );
            $btn.prop( 'disabled', false );
        } );
    } );

} )( jQuery );
