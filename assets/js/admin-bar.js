/**
 * Keeps the admin bar "Static" status fresh through the Heartbeat API.
 */
( function ( $ ) {
	'use strict';

	$( document )
		.on( 'heartbeat-send', function ( event, data ) {
			data.glaciate = 1;
		} )
		.on( 'heartbeat-tick', function ( event, data ) {
			if ( ! data.glaciate ) {
				return;
			}
			const node = document.getElementById( 'wp-admin-bar-glaciate' );
			if ( ! node ) {
				return;
			}
			node.className = node.className.replace( /glaciate-ab-[a-z-]+/, 'glaciate-ab-' + data.glaciate.state );
			const label = node.querySelector( '.ab-label' );
			if ( label ) {
				label.textContent = data.glaciate.label;
			}
		} );
} )( window.jQuery );
