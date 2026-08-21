/* global mad_proof_params, jQuery */
/**
 * MAD Quotes – Subida de comprobante de transferencia bancaria.
 */
( function ( $ ) {
    'use strict';

    var params = mad_proof_params || {};

    $( '#mad-proof-upload-form' ).on( 'submit', function ( e ) {
        e.preventDefault();

        var fileInput = $( '#mad_proof_file' )[ 0 ];
        var file = fileInput.files[ 0 ];

        if ( ! file ) {
            showResult( 'error', params.i18n.file_error );
            return;
        }

        var allowed = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf' ];
        if ( allowed.indexOf( file.type ) === -1 ) {
            showResult( 'error', params.i18n.file_error );
            return;
        }

        var $btn = $( '#mad-proof-submit' );
        var originalText = $btn.text();

        $btn.prop( 'disabled', true ).text( params.i18n.uploading );
        $( '#mad-proof-result' ).hide();

        var fd = new FormData();
        fd.append( 'action',     'mad_upload_payment_proof' );
        fd.append( 'nonce',      params.nonce );
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
                showResult( 'success', response.data.message || params.i18n.success );
                $( '#mad-proof-upload-form' ).find( 'p.form-row, p.woocommerce-form-row' ).hide();
                $btn.hide();
                if ( response.data.reload ) {
                    setTimeout( function () { location.reload(); }, 3000 );
                }
            } else {
                var msg = ( response && response.data && response.data.message )
                    ? response.data.message
                    : params.i18n.error;
                showResult( 'error', msg );
                $btn.prop( 'disabled', false ).text( originalText );
            }
        } )
        .fail( function () {
            showResult( 'error', params.i18n.error );
            $btn.prop( 'disabled', false ).text( originalText );
        } );
    } );

    function showResult( type, message ) {
        var $r  = $( '#mad-proof-result' );
        var cls = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
        $r.removeClass( 'woocommerce-message woocommerce-error' )
          .addClass( cls )
          .text( message )
          .show();
    }

} )( jQuery );
