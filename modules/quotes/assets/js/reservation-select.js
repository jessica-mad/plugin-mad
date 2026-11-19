/* global mad_reserve_params, jQuery */
/**
 * MAD Quotes – Reservas con plazo: página de selección ([mad_quote_purchase]).
 */
( function ( $ ) {
    'use strict';

    var params = ( typeof mad_reserve_params !== 'undefined' ) ? mad_reserve_params : {};
    var $root  = $( '.mad-quote-purchase--selection' );

    if ( ! $root.length ) return;

    function updateReserveButtonState() {
        var anyChecked = $root.find( '.mad-qp__checkbox:checked' ).length > 0;
        var $terms     = $root.find( '#mad-qp-terms' );
        var termsOk    = ! $terms.length || $terms.is( ':checked' );
        $root.find( '#mad-qp-reserve' ).prop( 'disabled', ! ( anyChecked && termsOk ) );
    }

    $root.on( 'change', '.mad-qp__checkbox, #mad-qp-terms', updateReserveButtonState );
    updateReserveButtonState();

    $root.on( 'click', '#mad-qp-reserve', function () {
        var $btn      = $( this );
        var selected  = $root.find( '.mad-qp__checkbox:checked' ).map( function () {
            return $( this ).val();
        } ).get();

        if ( ! selected.length ) {
            showResult( 'error', params.i18n.select_one );
            return;
        }

        var $terms = $root.find( '#mad-qp-terms' );
        if ( $terms.length && ! $terms.is( ':checked' ) ) {
            showResult( 'error', params.i18n.accept_terms );
            return;
        }

        var originalText = $btn.text();
        $btn.prop( 'disabled', true ).text( params.i18n.reserving );
        $( '#mad-qp-result' ).hide();

        $.post( params.ajax_url, {
            action        : 'mad_quote_reserve',
            nonce         : params.nonce,
            quote_key     : params.quote_key,
            product_ids   : selected,
            accepts_terms : $terms.length ? ( $terms.is( ':checked' ) ? 1 : 0 ) : 1
        } )
        .done( function ( response ) {
            if ( response && response.success && response.data && response.data.redirect ) {
                window.location.href = response.data.redirect;
                return;
            }
            var msg = ( response && response.data && response.data.message ) ? response.data.message : params.i18n.error;
            showResult( 'error', msg );
            $btn.prop( 'disabled', false ).text( originalText );
            updateReserveButtonState();
        } )
        .fail( function () {
            showResult( 'error', params.i18n.error );
            $btn.prop( 'disabled', false ).text( originalText );
            updateReserveButtonState();
        } );
    } );

    function showResult( type, message ) {
        var $r = $( '#mad-qp-result' );
        if ( ! $r.length ) return;
        var cls = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
        $r.removeClass( 'woocommerce-message woocommerce-error' ).addClass( cls ).text( message ).show();
    }

} )( jQuery );
