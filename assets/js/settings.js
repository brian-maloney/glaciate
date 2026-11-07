/**
 * Static Site → Settings screen: shows only the chosen destination's settings, and tests a target's connection.
 *
 * Without JavaScript every target's panel is shown and the form still saves; the test button needs the REST API.
 */
( function ( wp ) {
	'use strict';

	const form = document.querySelector( '.glaciate-settings form' );
	const select = document.getElementById( 'glaciate-target' );
	if ( ! form || ! select ) {
		return;
	}
	const { __ } = wp.i18n;

	function showSelected() {
		const target = select.value;
		form.querySelectorAll( '.glaciate-target-row' ).forEach( ( row ) => {
			row.hidden = ! row.classList.contains( 'glaciate-target-row-' + target );
		} );
		const folder = document.getElementById( 'glaciate-local-path' );
		const folderRow = folder ? folder.closest( 'tr' ) : null;
		if ( folderRow ) {
			folderRow.hidden = 'local' !== target;
		}
	}
	select.addEventListener( 'change', showSelected );
	showSelected();

	// Times and days only matter for "at set times"; the weekday only for weekly rebuilds.
	const schedule = form.querySelector( '[data-glaciate-schedule]' );
	const modes = form.querySelectorAll( 'input[name="glaciate_settings[publish_mode]"]' );
	function showSchedule() {
		const chosen = form.querySelector( 'input[name="glaciate_settings[publish_mode]"]:checked' );
		if ( schedule ) {
			schedule.hidden = ! chosen || 'scheduled' !== chosen.value;
		}
	}
	modes.forEach( ( radio ) => radio.addEventListener( 'change', showSchedule ) );
	showSchedule();

	const rebuild = document.getElementById( 'glaciate-full-rebuild' );
	const rebuildDay = form.querySelector( 'select[name="glaciate_settings[full_rebuild_day]"]' );
	const rebuildTime = form.querySelector( 'input[name="glaciate_settings[full_rebuild_time]"]' );
	function showRebuild() {
		if ( rebuild && rebuildDay && rebuildTime ) {
			rebuildDay.closest( 'label' ).hidden = 'weekly' !== rebuild.value;
			rebuildTime.closest( 'label' ).hidden = 'off' === rebuild.value;
		}
	}
	if ( rebuild ) {
		rebuild.addEventListener( 'change', showRebuild );
		showRebuild();
	}

	let dirty = false;
	form.addEventListener( 'input', () => {
		dirty = true;
	} );

	form.querySelectorAll( '[data-glaciate-check]' ).forEach( ( button ) => {
		const id = button.dataset.glaciateCheck;
		const result = form.querySelector( '[data-glaciate-check-result="' + id + '"]' );
		button.addEventListener( 'click', () => {
			if ( ! wp.apiFetch ) {
				return;
			}
			button.disabled = true;
			result.className = 'glaciate-check-result';
			result.textContent = dirty
				? __( 'Testing the saved settings (your unsaved changes are not included)…', 'glaciate' )
				: __( 'Testing…', 'glaciate' );
			wp.apiFetch( { path: '/glaciate/v1/targets/' + encodeURIComponent( id ) + '/check', method: 'POST' } )
				.then( ( response ) => {
					// Plain text from the server: never inserted as HTML.
					result.textContent = response.message;
					result.classList.add( response.ok ? 'is-ok' : 'is-error' );
				} )
				.catch( ( error ) => {
					result.textContent = ( error && error.message ) || __( 'The test could not run.', 'glaciate' );
					result.classList.add( 'is-error' );
				} )
				.finally( () => {
					button.disabled = false;
				} );
		} );
	} );
} )( window.wp );
