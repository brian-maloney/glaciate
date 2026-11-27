/**
 * The Static Site screen's status poll: it stops while the tab is hidden or idle, and starts again at once (D66).
 *
 * Runs on a fake clock, so ten idle minutes take a moment. Signs in to the tests site with the wp-env default account;
 * it doesn't build or change anything.
 */
import { test, expect } from '@playwright/test';
import { ORIGIN } from './site.mjs';

/** Requests sent over the network take real time, and the next poll is scheduled once the answer is read. */
const SETTLE = 750;

const isStatus = ( r ) => decodeURIComponent( r.url() ).includes( 'glaciate/v1/status' );

/**
 * Makes the page report itself hidden or visible, as switching tabs does.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {boolean}                         hidden Whether the tab is hidden.
 */
function setHidden( page, hidden ) {
	return page.evaluate( ( h ) => {
		Object.defineProperty( document, 'hidden', { configurable: true, get: () => h } );
		Object.defineProperty( document, 'visibilityState', { configurable: true, get: () => ( h ? 'hidden' : 'visible' ) } );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
	}, hidden );
}

test.describe( 'Static Site screen', () => {
	test.beforeEach( async ( { page } ) => {
		await page.goto( ORIGIN + '/wp-login.php' );
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'password' );
		await page.locator( '#wp-submit' ).click();
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );

	test( 'the status poll stops while the tab is idle or hidden', async ( { page } ) => {
		let polls = 0;
		page.on( 'request', ( r ) => {
			if ( isStatus( r ) ) {
				polls++;
			}
		} );
		/** Advances the fake clock and returns how many polls were sent meanwhile. */
		const pollsDuring = async ( ms ) => {
			const before = polls;
			await page.clock.fastForward( ms );
			await page.waitForTimeout( SETTLE );
			return polls - before;
		};

		await page.clock.install();
		const first = page.waitForResponse( isStatus );
		await page.goto( ORIGIN + '/wp-admin/admin.php?page=glaciate' );
		await first;
		await page.waitForTimeout( SETTLE );

		// Polls every 15 seconds while someone is around.
		expect( await pollsDuring( 15_000 ) ).toBe( 1 );

		// Ten minutes without input: the poll under way is the last one.
		await pollsDuring( 10 * 60_000 );
		expect( await pollsDuring( 30 * 60_000 ) ).toBe( 0 );

		// Moving the mouse polls at once, and the poll carries on.
		const resumed = page.waitForResponse( isStatus );
		await page.mouse.move( 400, 300 );
		await resumed;
		await page.waitForTimeout( SETTLE );
		expect( await pollsDuring( 15_000 ) ).toBe( 1 );

		// A hidden tab doesn't poll, and polls at once when it comes back.
		await setHidden( page, true );
		expect( await pollsDuring( 5 * 60_000 ) ).toBe( 0 );
		const back = page.waitForResponse( isStatus );
		await setHidden( page, false );
		await back;
	} );
} );
