/**
 * Static Site → Publish screen: live status, buttons without page reloads, and build logs.
 *
 * Every button is a normal link to an admin-post action, so the screen also works without JavaScript.
 */
( function ( wp ) {
	'use strict';

	const app = document.getElementById( 'glaciate-app' );
	if ( ! app || ! wp || ! wp.apiFetch ) {
		return;
	}
	const apiFetch = wp.apiFetch;
	const { __, _n, sprintf } = wp.i18n;
	// The admin's language, as WordPress gives it to the page (en_US becomes en-US).
	const locale = ( document.documentElement.lang || 'en' ).replace( '_', '-' );
	const part = ( name ) => app.querySelector( '[data-glaciate="' + name + '"]' );
	let state = app.dataset.state;
	let timer = null;
	const ANNOUNCE = 'glaciate-announce';

	function setDisabled( el, disabled ) {
		if ( ! el ) {
			return;
		}
		if ( disabled ) {
			el.setAttribute( 'aria-disabled', 'true' );
		} else {
			el.removeAttribute( 'aria-disabled' );
		}
	}

	function render( status ) {
		const building = 'building' === status.state;
		const label = part( 'label' );
		if ( label ) {
			label.textContent = status.label;
		}
		const progress = part( 'progress' );
		if ( progress ) {
			progress.hidden = ! building;
			if ( building && status.active ) {
				progress.querySelector( 'progress' ).value = status.active.percent;
				part( 'progress-text' ).textContent = sprintf(
					/* translators: 1: addresses processed, 2: addresses queued. Both are formatted numbers. */
					_n( '%1$s of %2$s address', '%1$s of %2$s addresses', status.active.total, 'glaciate' ),
					status.active.done.toLocaleString( locale ),
					status.active.total.toLocaleString( locale )
				);
			}
		}
		setDisabled( part( 'publish' ), building );
		setDisabled( part( 'rebuild' ), building );
		const cancel = part( 'cancel' );
		if ( cancel ) {
			cancel.hidden = ! building;
		}
		const dot = app.querySelector( '.glaciate-dot' );
		if ( dot ) {
			dot.className = 'glaciate-dot glaciate-dot-' + status.state;
		}

		const finished = 'building' === state && ! building;
		state = status.state;
		app.dataset.state = state;
		if ( finished ) {
			// Refresh the history table and counts. The reload would cut off an announcement, so it is made after.
			remember( status.label );
			window.location.reload();
		}
	}

	function poll() {
		window.clearTimeout( timer );
		apiFetch( { path: '/glaciate/v1/status' } )
			.then( render )
			.catch( () => {} )
			.finally( () => {
				timer = window.setTimeout( poll, 'building' === state ? 2000 : 15000 );
			} );
	}

	function remember( message ) {
		try {
			window.sessionStorage.setItem( ANNOUNCE, message );
		} catch ( e ) {
			// Storage blocked: the result is still on the page.
		}
	}

	function announceRemembered() {
		try {
			const message = window.sessionStorage.getItem( ANNOUNCE );
			if ( message ) {
				window.sessionStorage.removeItem( ANNOUNCE );
				wp.a11y.speak( message );
			}
		} catch ( e ) {
			// Storage blocked: nothing was remembered.
		}
	}

	function act( event, request ) {
		event.preventDefault();
		if ( 'true' === event.currentTarget.getAttribute( 'aria-disabled' ) ) {
			return;
		}
		wp.a11y.speak( __( 'Publishing started.', 'glaciate' ) );
		state = 'building';
		apiFetch( request )
			.then( ( response ) => render( response.status || response ) )
			.catch( ( error ) => window.alert( error.message || __( 'Something went wrong.', 'glaciate' ) ) )
			.finally( poll );
	}

	const publish = part( 'publish' );
	if ( publish ) {
		publish.addEventListener( 'click', ( e ) => act( e, { path: '/glaciate/v1/builds', method: 'POST', data: { kind: 'incremental' } } ) );
	}
	const rebuild = part( 'rebuild' );
	if ( rebuild ) {
		rebuild.addEventListener( 'click', ( e ) => act( e, { path: '/glaciate/v1/builds', method: 'POST', data: { kind: 'full' } } ) );
	}
	const cancel = part( 'cancel' );
	if ( cancel ) {
		cancel.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			apiFetch( { path: '/glaciate/v1/builds/cancel', method: 'POST' } ).finally( () => window.location.reload() );
		} );
	}

	app.querySelectorAll( 'details[data-glaciate-log]' ).forEach( ( details ) => {
		details.addEventListener( 'toggle', () => {
			if ( ! details.open || details.dataset.loaded ) {
				return;
			}
			details.dataset.loaded = '1';
			const pre = details.querySelector( 'pre' );
			apiFetch( { path: '/glaciate/v1/builds/' + details.dataset.glaciateLog + '/log' } )
				.then( ( lines ) => {
					// textContent, never innerHTML: log lines contain data from crawled pages.
					pre.textContent = lines.length
						? lines.map( ( l ) => '[' + l.level + '] ' + l.message ).join( '\n' )
						: __( 'Nothing was logged.', 'glaciate' );
				} )
				.catch( () => {
					pre.textContent = __( 'The log could not be loaded.', 'glaciate' );
				} );
		} );
	} );

	// wp.a11y adds its live regions when the DOM is ready; a region needs a moment in the page before it is read.
	wp.domReady( () => window.setTimeout( announceRemembered, 500 ) );
	poll();
} )( window.wp );
