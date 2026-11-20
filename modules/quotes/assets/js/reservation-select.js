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

    // "Soy empresa": muestra razón social + VAT.
    $root.on( 'change', '#mad-billing-is_company', function () {
        $root.find( '#mad-billing-company-row' ).toggle( $( this ).is( ':checked' ) );
    } );

    function billingFields() {
        var ids = [ 'first_name', 'last_name', 'company', 'vat', 'address_1', 'postcode', 'city', 'state', 'country', 'phone', 'email' ];
        var data = {};
        ids.forEach( function ( id ) {
            data[ id ] = $root.find( '#mad-billing-' + id ).val() || '';
        } );
        data.is_company = $root.find( '#mad-billing-is_company' ).is( ':checked' ) ? 1 : 0;
        return data;
    }

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

        var billing = billingFields();
        var missing = [ 'first_name', 'last_name', 'address_1', 'postcode', 'city', 'phone', 'email' ].some( function ( key ) {
            return '' === billing[ key ];
        } );
        if ( missing ) {
            showResult( 'error', params.i18n.billing_required );
            return;
        }

        var originalText = $btn.text();
        $btn.prop( 'disabled', true ).text( params.i18n.reserving );
        $( '#mad-qp-result' ).hide();

        $.post( params.ajax_url, $.extend( {
            action        : 'mad_quote_reserve',
            nonce         : params.nonce,
            quote_key     : params.quote_key,
            product_ids   : selected,
            accepts_terms : $terms.length ? ( $terms.is( ':checked' ) ? 1 : 0 ) : 1
        }, { billing: billing } ) )
        .done( function ( response ) {
            if ( response && response.success && response.data && response.data.redirect ) {
                window.location.href = response.data.redirect;
                return;
            }
            var msg = ( response && response.data && response.data.message ) ? response.data.message : params.i18n.error;
            showResult( 'error', msg );
            $btn.prop( 'disabled', false ).text( originalText );
            updateReserveButtonState();

            // Dos clientes reservando la misma pieza a la vez (sección 3):
            // en vez de esperar al sondeo periódico, confirmamos ya mismo
            // el estado real de la pieza perdida y la marcamos como "en
            // proceso de compra" al instante.
            var pid = response && response.data && response.data.product_id;
            if ( pid ) markRowContested( pid );
        } )
        .fail( function () {
            showResult( 'error', params.i18n.error );
            $btn.prop( 'disabled', false ).text( originalText );
            updateReserveButtonState();
        } );
    } );

    /** Confirma por AJAX el estado real de una pieza que se nos acaba de escapar y actualiza su fila sin esperar al sondeo periódico. */
    function markRowContested( productId ) {
        var $row = $root.find( '.mad-qp__row[data-mad-product-id="' + productId + '"]' );
        if ( ! $row.length ) return;

        $.post( params.ajax_url, { action: 'mad_quote_state', product_id: productId } )
            .done( function ( response ) {
                if ( ! response || ! response.success || ! response.data || ! response.data.product ) return;

                var info = response.data.product;
                $row.attr( 'data-mad-state', info.state );
                $row.find( '.mad-qp__checkbox' ).prop( 'checked', false ).prop( 'disabled', 'available' !== info.state );
                if ( info.message ) {
                    $row.find( '.mad-qp__state' ).html( info.message );
                }
                updateReserveButtonState();
            } );
    }

    function showResult( type, message ) {
        var $r = $( '#mad-qp-result' );
        if ( ! $r.length ) return;
        var cls = type === 'success' ? 'woocommerce-message' : 'woocommerce-error';
        $r.removeClass( 'woocommerce-message woocommerce-error' ).addClass( cls ).text( message ).show();
    }

} )( jQuery );
