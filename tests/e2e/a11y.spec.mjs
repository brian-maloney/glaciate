/**
 * Accessibility: axe-core (WCAG 2.2 A and AA rules) over what Stillpress adds, plus the keyboard and screen-reader
 * behaviour axe can't see. Covers the search dialog and form answers on the static site, and the plugin's admin
 * screens (only the plugin's own content: core's admin chrome is WordPress's to audit).
 *
 * Uses the block theme. Signs in to the tests site with the wp-env default account.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ORIGIN, STATIC, prepare } from './site.mjs';

const themes = ( process.env.THEMES || 'block' ).split( ',' );

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/** A person takes a few seconds; quicker submissions are refused as bots (Protocol::MIN_SECONDS). */
const READING_TIME = 3200;

/**
 * Runs axe on part of the page.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          include Selector of the part to audit.
 * @return {Promise<string>} The violations, one per line ("" when there are none).
 */
async function audit( page, include ) {
	const results = await new AxeBuilder( { page } ).include( include ).withTags( TAGS ).analyze();
	return results.violations
		.map( ( v ) => `${ v.id } (${ v.impact }): ${ v.help }\n    ` + v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( '\n    ' ) )
		.join( '\n' );
}

test.describe( 'accessibility', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( ! themes.includes( 'block' ), 'Runs with the block theme (THEMES=block).' );

	test.beforeAll( () => {
		if ( ! process.env.SKIP_PREPARE ) {
			prepare( 'block', { forms: 'site' } );
		}
	} );

	test( 'the search dialog passes axe and works from the keyboard', async ( { page } ) => {
		await page.goto( STATIC + '/no-such-page/' );
		const field = page.locator( 'form input[name="s"]' ).first();
		await field.fill( 'fixture story' );
		await field.press( 'Enter' );

		const dialog = page.getByRole( 'dialog', { name: 'Search this site' } );
		await expect( dialog.getByRole( 'status' ) ).toHaveText( /\d+ results/ );
		await expect( dialog.getByRole( 'searchbox' ) ).toBeFocused();
		await expect( dialog.getByRole( 'list' ) ).toBeVisible();
		expect( await audit( page, 'dialog.stillpress-search' ) ).toBe( '' );

		// Tab moves through the dialog only (it is modal), with a visible focus ring.
		await page.keyboard.press( 'Tab' );
		await expect( dialog.getByRole( 'button', { name: 'Close' } ) ).toBeFocused();
		const outline = await dialog.getByRole( 'button', { name: 'Close' } ).evaluate( ( el ) => getComputedStyle( el ).outlineStyle );
		expect( outline ).not.toBe( 'none' );
		await page.keyboard.press( 'Tab' );
		await expect( dialog.getByRole( 'link' ).first() ).toBeFocused();

		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( field, 'Focus goes back to where the search started.' ).toBeFocused();
	} );

	test( 'the status region is in the accessibility tree before it has anything to say', async ( { page } ) => {
		await page.goto( STATIC + '/?s=a' );
		const dialog = page.getByRole( 'dialog', { name: 'Search this site' } );
		await expect( dialog ).toBeVisible();
		// One-letter queries search nothing, so the region is empty; it must still be rendered to be announced later.
		await expect( dialog.locator( '[role="status"]' ) ).toBeEmpty();
		expect( await dialog.locator( '[role="status"]' ).evaluate( ( el ) => getComputedStyle( el ).display ) ).not.toBe( 'none' );
	} );

	test( 'form answers and field errors pass axe and are announced', async ( { page } ) => {
		await page.goto( STATIC + '/contact-form/' );
		const form = page.locator( 'form.wpcf7-form' );
		await expect( form.locator( '.stillpress-form-message[role="status"]' ), 'The answer region exists before the form is sent.' ).toBeAttached();
		await page.waitForTimeout( READING_TIME );
		await form.getByLabel( 'Your name' ).fill( 'Ada' );
		await form.getByLabel( 'Your email' ).fill( 'ada@localhost' );
		await form.getByLabel( 'Subject' ).fill( 'Hello' );
		await form.getByRole( 'button', { name: 'Submit' } ).click();

		const email = form.getByLabel( 'Your email' );
		await expect( email ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( email, 'Focus moves to the first field to fix.' ).toBeFocused();
		const described = await email.getAttribute( 'aria-describedby' );
		expect( described ).toMatch( /stillpress-error-/ );
		await expect( page.locator( '#' + described.split( ' ' ).pop() ) ).not.toBeEmpty();
		expect( await audit( page, 'form.wpcf7-form' ) ).toBe( '' );
	} );

	test.describe( 'admin screens', () => {
		test.beforeEach( async ( { page } ) => {
			await page.goto( ORIGIN + '/wp-login.php' );
			await page.locator( '#user_login' ).fill( 'admin' );
			await page.locator( '#user_pass' ).fill( 'password' );
			await page.locator( '#wp-submit' ).click();
			await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		} );

		test( 'the Static Site screen passes axe', async ( { page } ) => {
			await page.goto( ORIGIN + '/wp-admin/admin.php?page=stillpress' );
			await expect( page.locator( '#stillpress-app' ) ).toBeVisible();
			await page.locator( 'details[data-stillpress-log] summary' ).first().click();
			expect( await audit( page, '#wpbody-content' ) ).toBe( '' );
		} );

		test( 'the settings screen passes axe, and every field is named and described', async ( { page } ) => {
			await page.goto( ORIGIN + '/wp-admin/admin.php?page=stillpress-settings' );
			await expect( page.locator( '#stillpress-target' ) ).toBeVisible();
			expect( await audit( page, '#wpbody-content' ) ).toBe( '' );

			// A control inside its label adds its own value to the name ("Weekly rebuild on Sunday").
			await page.locator( '#stillpress-full-rebuild' ).selectOption( 'weekly' );
			await expect( page.getByRole( 'combobox', { name: /^Weekly rebuild on\b/ } ) ).toBeVisible();
			await expect( page.getByLabel( /^Full rebuild at\b/ ) ).toBeAttached();
			for ( const id of [ 'stillpress-public-url', 'stillpress-search', 'stillpress-max-urls', 'stillpress-additional-urls' ] ) {
				const described = await page.locator( '#' + id ).getAttribute( 'aria-describedby' );
				await expect( page.locator( '#' + described ), id ).not.toBeEmpty();
			}
		} );
	} );
} );
