/* global mad_quotes_product_params */
/**
 * MAD Quotes – Product page JS
 *
 * Minimal front-end script for the single product page when quotes are enabled.
 * Can be extended via the mad_quotes_product_params object.
 */
( function () {
    'use strict';

    var params = ( typeof mad_quotes_product_params !== 'undefined' ) ? mad_quotes_product_params : {};

    if ( ! params.quotes_enabled ) return;

    // Nothing extra needed for the basic free version.
    // This file is a placeholder that addons / Pro can hook into.
} )();
