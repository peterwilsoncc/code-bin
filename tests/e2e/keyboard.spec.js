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

	test.describe( 'Tab in the code', () => {
		const LOCATIONS = [
			{
				name: 'on the site',
				/**
				 * Open a code bin on the site.
				 *
				 * @param {Object} fixtures              Test fixtures.
				 * @param {Object} fixtures.page         Page.
				 * @param {Object} fixtures.requestUtils Request utils.
				 * @return {Promise<Object>} Block locator.
				 */
				async open( { page, requestUtils } ) {
					const { link } = await createCodeBin( requestUtils, {
						...CODE,
						editable: true,
					} );
					await page.goto( link );
					const block = getBlock( page );
					await expect(
						block.locator( 'iframe.code-bin__preview' )
					).toHaveAttribute( 'sandbox', 'allow-scripts' );
					return block;
				},
			},
			{
				name: 'in the editor',
				/**
				 * Open a code bin in the editor.
				 *
				 * @param {Object} fixtures              Test fixtures.
				 * @param {Object} fixtures.admin        Admin utils.
				 * @param {Object} fixtures.editor       Editor utils.
				 * @param {Object} fixtures.requestUtils Request utils.
				 * @return {Promise<Object>} Block locator.
				 */
				async open( { admin, editor, requestUtils } ) {
					const { id } = await createCodeBin( requestUtils, CODE );
					await admin.editPost( id );
					const block = getBlock( editor.canvas );
					await expect( block ).toBeVisible();
					return block;
				},
			},
		];

		/**
		 * Get the HTML textarea, filled with code and with text selected.
		 *
		 * @param {Object} block Block locator.
		 * @param {string} code  Code.
		 * @param {number} start Selection start.
		 * @param {number} [end] Selection end, defaults to the start.
		 * @return {Promise<Object>} Textarea locator.
		 */
		async function getTextarea( block, code, start, end = start ) {
			await block.getByRole( 'tab', { name: /^HTML/ } ).click();
			const textarea = block.getByRole( 'textbox', {
				name: 'HTML',
				exact: true,
			} );
			await textarea.fill( code );
			await textarea.evaluate(
				( element, [ from, to ] ) => {
					element.focus();
					element.setSelectionRange( from, to );
				},
				[ start, end ]
			);
			return textarea;
		}

		/**
		 * Check a textarea's value, selection and highlighted copy.
		 *
		 * The highlighted copy updates on input, so it shows the change was
		 * made as if typed.
		 *
		 * @param {Object} block    Block locator.
		 * @param {Object} textarea Textarea locator.
		 * @param {string} value    Expected value.
		 * @param {number} start    Expected selection start.
		 * @param {number} [end]    Expected selection end, defaults to the start.
		 */
		async function expectCode(
			block,
			textarea,
			value,
			start,
			end = start
		) {
			await expect( textarea ).toHaveValue( value );
			await expect( textarea ).toBeFocused();
			expect(
				await textarea.evaluate( ( element ) => [
					element.selectionStart,
					element.selectionEnd,
				] )
			).toEqual( [ start, end ] );
			await expect
				.poll( () =>
					block
						.locator(
							'.code-bin__panel:not([hidden]) code, .code-bin__editor code'
						)
						.first()
						.textContent()
				)
				.toBe( value + '\n' );
		}

		/**
		 * Press the shortcut switching Tab between indenting and moving focus.
		 *
		 * @param {Object} page Page.
		 */
		async function toggleTabFocus( page ) {
			const isApple = await page.evaluate( () =>
				/mac|iphone|ipad/i.test( window.navigator.platform )
			);
			await page.keyboard.press(
				isApple ? 'Shift+Alt+KeyM' : 'Control+KeyM'
			);
		}

		for ( const location of LOCATIONS ) {
			test.describe( location.name, () => {
				test( 'Tab inserts a tab', async ( {
					page,
					admin,
					editor,
					requestUtils,
				} ) => {
					const fixtures = { page, admin, editor, requestUtils };
					const block = await location.open( fixtures );
					const textarea = await getTextarea( block, 'ab', 1 );

					await page.keyboard.press( 'Tab' );
					await expectCode( block, textarea, 'a\tb', 2 );
				} );

				test( 'Tab and Shift+Tab indent and outdent the selected lines', async ( {
					page,
					admin,
					editor,
					requestUtils,
				} ) => {
					const fixtures = { page, admin, editor, requestUtils };
					const block = await location.open( fixtures );
					const code = 'one\n\ntwo\nthree';
					// From inside "one" to inside "two".
					const textarea = await getTextarea( block, code, 1, 7 );

					await page.keyboard.press( 'Tab' );
					// Empty lines aren't indented.
					await expectCode(
						block,
						textarea,
						'\tone\n\n\ttwo\nthree',
						2,
						9
					);

					await page.keyboard.press( 'Shift+Tab' );
					await expectCode( block, textarea, code, 1, 7 );
				} );

				test( 'Shift+Tab removes indenting spaces', async ( {
					page,
					admin,
					editor,
					requestUtils,
				} ) => {
					const fixtures = { page, admin, editor, requestUtils };
					const block = await location.open( fixtures );
					const textarea = await getTextarea( block, '      x', 7 );

					await page.keyboard.press( 'Shift+Tab' );
					await expectCode( block, textarea, '  x', 3 );
				} );

				test( 'Escape then Tab moves focus', async ( {
					page,
					admin,
					editor,
					requestUtils,
				} ) => {
					const fixtures = { page, admin, editor, requestUtils };
					const block = await location.open( fixtures );
					const textarea = await getTextarea( block, 'ab', 1 );

					await page.keyboard.press( 'Escape' );
					await page.keyboard.press( 'Tab' );

					await expect( textarea ).not.toBeFocused();
					await expect( textarea ).toHaveValue( 'ab' );
				} );

				test( 'the shortcut switches Tab between indenting and moving focus', async ( {
					page,
					admin,
					editor,
					requestUtils,
				} ) => {
					const fixtures = { page, admin, editor, requestUtils };
					const block = await location.open( fixtures );
					const textarea = await getTextarea( block, 'ab', 1 );

					await toggleTabFocus( page );
					await page.keyboard.press( 'Tab' );
					await expect( textarea ).not.toBeFocused();
					await expect( textarea ).toHaveValue( 'ab' );

					await textarea.evaluate( ( element ) => {
						element.focus();
						element.setSelectionRange( 1, 1 );
					} );
					await toggleTabFocus( page );
					await page.keyboard.press( 'Tab' );
					await expectCode( block, textarea, 'a\tb', 2 );
				} );

				test( 'the textarea describes the keys', async ( {
					page,
					admin,
					editor,
					requestUtils,
				} ) => {
					const fixtures = { page, admin, editor, requestUtils };
					const block = await location.open( fixtures );
					await expect(
						block.getByRole( 'textbox', {
							name: 'HTML',
							exact: true,
						} )
					).toHaveAccessibleDescription(
						/^Tab inserts a tab and Shift\+Tab removes one\. To move focus, press Escape then Tab/
					);
				} );
			} );
		}

		test( 'on the site, Tab indents again after another key', async ( {
			page,
			admin,
			editor,
			requestUtils,
		} ) => {
			const fixtures = { page, admin, editor, requestUtils };
			const block = await LOCATIONS[ 0 ].open( fixtures );
			const textarea = await getTextarea( block, 'ab', 1 );

			// Escape only lets the next key press move focus.
			await page.keyboard.press( 'Escape' );
			await page.keyboard.press( 'ArrowRight' );
			await page.keyboard.press( 'Tab' );
			await expectCode( block, textarea, 'ab\t', 3 );
		} );
	} );
} );
