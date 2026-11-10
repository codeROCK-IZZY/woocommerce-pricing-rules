/* Pricing Rules for WooCommerce: rule editor behaviour (show fields for the chosen type, add and remove tiers). */
( function () {
	'use strict';
	var editor = document.querySelector( '.wcpr-editor' );
	if ( ! editor ) {
		return;
	}
	var type = editor.querySelector( '#wcpr-type' );
	function sync() {
		editor.setAttribute( 'data-type', type.value );
		editor.querySelectorAll( '.wcpr-for' ).forEach( function ( row ) {
			row.hidden = ! row.classList.contains( 'wcpr-for-' + type.value );
		} );
	}
	type.addEventListener( 'change', sync );
	sync();

	var body = editor.querySelector( '.wcpr-tier-rows tbody' );
	editor.querySelector( '.wcpr-add-tier' ).addEventListener( 'click', function () {
		var last = body.querySelector( 'tr:last-child' );
		var row = last.cloneNode( true );
		var inputs = row.querySelectorAll( 'input' );
		inputs[ 0 ].value = String( ( parseInt( last.querySelector( 'input' ).value, 10 ) || 0 ) * 2 || 10 );
		inputs[ 1 ].value = '';
		body.appendChild( row );
		inputs[ 1 ].focus();
	} );
	body.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'wcpr-remove-tier' ) && body.rows.length > 1 ) {
			e.target.closest( 'tr' ).remove();
		}
	} );
} )();
