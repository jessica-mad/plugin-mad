/* global mad_quotes_front, jQuery */
( function ( $ ) {
	'use strict';

	var replacements = ( typeof mad_quotes_front !== 'undefined' && mad_quotes_front.replacements )
		? mad_quotes_front.replacements
		: [];

	if ( ! replacements.length ) return;

	function applyReplacement( selector, newText ) {
		$( selector ).each( function () {
			var $el = $( this );
			if ( $el.children().length > 0 ) {
				$el.contents().filter( function () {
					return this.nodeType === 3 && $.trim( this.nodeValue ) !== '';
				} ).each( function () {
					this.nodeValue = newText;
				} );
			} else {
				$el.text( newText );
			}
		} );
	}

	function applyAll() {
		$.each( replacements, function ( i, pair ) {
			if ( pair.selector && pair.text ) {
				applyReplacement( pair.selector, pair.text );
			}
		} );
	}

	$( applyAll );

	$( document.body ).on( 'wc_fragments_refreshed wc_fragments_loaded', applyAll );

}( jQuery ) );
