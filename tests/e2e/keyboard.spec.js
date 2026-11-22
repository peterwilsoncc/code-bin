/**
 * Keyboard use of the Code Bin block's tab lists, on the site and in the editor.
 *
 * The tabs follow the ARIA tabs pattern with automatic activation: arrow keys
 * move between tabs, wrapping at the ends, Home and End move to the first and
 * last tabs, and only the selected tab is in the tab order.
 *
 * @see https://www.w3.org/WAI/ARIA/apg/patterns/tabs/
 */

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { createCodeBin, getBlock } = require( './utils/code-bin' );

const CODE = {
	html: '<p>Keyboard</p>',
	css: 'p { color: red; }',
	javascript: 'console.log( "Keyboard" );',
};

/**
 * Check which tab in a tab list is selected.
 *
 * The selected tab should be focused, be the only tab in the tab order, and
 * show its panel. The other tabs' panels should be hidden.
 *
 * @param {Object}               root     Locator for the document the tabs are in: the page or editor canvas.
 * @param {Object}               tablist  Tab list locator.
 * @param {number}               selected Index of the selected tab.
 * @param {Array<string|RegExp>} names    Accessible names of the tabs, in order.
 */
async function expectSelected( root, tablist, selected, names ) {
	const tabs = tablist.getByRole( 'tab' );
	await expect( tabs ).toHaveCount( names.length );

	for ( const [ index, name ] of names.entries() ) {
		const tab = tabs.nth( index );
		const isSelected = index === selected;
		await expect( tab ).toHaveAccessibleName( name );
		await expect( tab ).toHaveAttribute(
			'aria-selected',
			String( isSelected )
		);
		// Only the selected tab is in the tab order. Core's TabPanel, used for the
		// editor's code tabs, leaves the selected tab without a tabindex.
		if ( isSelected ) {
			await expect( tab ).not.toHaveAttribute( 'tabindex', '-1' );
		} else {
			await expect( tab ).toHaveAttribute( 'tabindex', '-1' );
		}

		// Some panels are removed rather than hidden, eg the editor's code panels.
		const panelId = await tab.getAttribute( 'aria-controls' );
		const panel = root.locator( `[id="${ panelId }"]` );
		if ( isSelected ) {
			await expect( tab ).toBeFocused();
			await expect( panel ).toBeVisible();
		} else if ( panelId && ( await panel.count() ) ) {
			await expect( panel ).toBeHidden();
		}
	}
}

/**
 * Test the keys of a tab list.
 *
 * Starts with the first tab selected.
 *
 * @param {Object}               page    Page.
 * @param {Object}               root    Locator for the document the tabs are in.
 * @param {Object}               tablist Tab list locator.
 * @param {Array<string|RegExp>} names   Accessible names of the tabs, in order.
 */
async function testTablistKeys( page, root, tablist, names ) {
	const last = names.length - 1;
	const first = tablist.getByRole( 'tab' ).first();

	await first.focus();
	await expectSelected( root, tablist, 0, names );

	for ( let index = 1; index <= last; index++ ) {
		await page.keyboard.press( 'ArrowRight' );
		await expectSelected( root, tablist, index, names );
	}

	await page.keyboard.press( 'ArrowRight' );
	await expectSelected( root, tablist, 0, names );

	await page.keyboard.press( 'ArrowLeft' );
	await expectSelected( root, tablist, last, names );

	await page.keyboard.press( 'ArrowLeft' );
	await expectSelected( root, tablist, last - 1, names );

	await page.keyboard.press( 'Home' );
	await expectSelected( root, tablist, 0, names );

	await page.keyboard.press( 'End' );
	await expectSelected( root, tablist, last, names );

	// Tab leaves the tab list rather than moving to another tab.
	await page.keyboard.press( 'Tab' );
	await expect( tablist.getByRole( 'tab' ).last() ).not.toBeFocused();
	for ( let index = 0; index < names.length; index++ ) {
		await expect(
			tablist.getByRole( 'tab' ).nth( index )
		).not.toBeFocused();
	}
}

test.describe( 'Code Bin keyboard', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts( 'code-bins' );
	} );

	test.describe( 'on the site', () => {
		test.beforeEach( async ( { page, requestUtils } ) => {
			const { link } = await createCodeBin( requestUtils, CODE );
			await page.goto( link );

			// The view script sets up the tabs as it runs the code.
			await expect(
				getBlock( page ).locator( 'iframe.code-bin__preview' )
			).toHaveAttribute( 'sandbox', 'allow-scripts' );
		} );

		test( 'code tabs', async ( { page } ) => {
			await testTablistKeys(
				page,
				page,
				getBlock( page ).getByRole( 'tablist', { name: 'Code' } ),
				[ 'HTML', 'CSS', 'JavaScript' ]
			);
		} );

		test( 'output tabs', async ( { page } ) => {
			await testTablistKeys(
				page,
				page,
				getBlock( page ).getByRole( 'tablist', { name: 'Output' } ),
				// The console tab's name includes the number of messages.
				[ 'Result', /^Console/ ]
			);
		} );
	} );

	test.describe( 'in the editor', () => {
		test.beforeEach( async ( { admin, editor, requestUtils } ) => {
			const { id } = await createCodeBin( requestUtils, CODE );
			await admin.editPost( id );
			await expect( getBlock( editor.canvas ) ).toBeVisible();
		} );

		test( 'code tabs', async ( { editor, page } ) => {
			// The tab list has no name: it's the one containing the HTML tab.
			const tablist = getBlock( editor.canvas )
				.getByRole( 'tablist' )
				.filter( {
					has: editor.canvas.getByRole( 'tab', { name: /^HTML/ } ),
				} );

			await testTablistKeys( page, editor.canvas, tablist, [
				'HTML (contains code)',
				'CSS (contains code)',
				'JavaScript (contains code)',
			] );
		} );

		test( 'output tabs', async ( { editor, page } ) => {
			await testTablistKeys(
				page,
				editor.canvas,
				getBlock( editor.canvas ).getByRole( 'tablist', {
					name: 'Output',
				} ),
				// The console tab's name includes the number of messages.
				[ 'Result', /^Console/ ]
			);
		} );
	} );
} );
