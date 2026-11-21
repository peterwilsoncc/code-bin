/**
 * The live preview on the site: running the code, the console, loop and error
 * protection, and visitors editing the code.
 */

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	createCodeBin,
	getBlock,
	getConsole,
	getPreview,
} = require( './utils/code-bin' );

/**
 * Open a code bin and wait for the live preview to run.
 *
 * The server renders the preview with scripts disabled. The view script
 * enables them when it runs the code.
 *
 * @param {Object} page         Page.
 * @param {Object} requestUtils Request utils.
 * @param {Object} attributes   Block attributes.
 * @return {Promise<Object>} Block locator.
 */
async function openCodeBin( page, requestUtils, attributes ) {
	const { link } = await createCodeBin( requestUtils, attributes );
	await page.goto( link );

	const block = getBlock( page );
	await expect( block.locator( 'iframe.code-bin__preview' ) ).toHaveAttribute(
		'sandbox',
		'allow-scripts'
	);
	return block;
}

/**
 * Edit a panel's code as a visitor.
 *
 * @param {Object} block Block locator.
 * @param {string} label Panel label: HTML, CSS or JavaScript.
 * @param {string} code  Code.
 */
async function editPanel( block, label, code ) {
	await block.getByRole( 'tab', { name: label, exact: true } ).click();
	await block
		.getByRole( 'textbox', { name: label, exact: true } )
		.fill( code );
}

test.describe( 'Code Bin live preview', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts( 'code-bins' );
	} );

	test( 'runs the JavaScript', async ( { page, requestUtils } ) => {
		const block = await openCodeBin( page, requestUtils, {
			html: '<p id="output">Not run</p>',
			javascript:
				'document.getElementById( "output" ).textContent = "Run";',
		} );

		await expect( getPreview( block ).locator( '#output' ) ).toHaveText(
			'Run'
		);
	} );

	test( 'shows console messages', async ( { page, requestUtils } ) => {
		const block = await openCodeBin( page, requestUtils, {
			javascript: `console.log( 'Hello', 42, { a: 1 }, [ 1, 2 ] );
console.warn( 'Careful' );
console.error( new TypeError( 'Bad' ) );`,
		} );

		await block.getByRole( 'tab', { name: /^Console/ } ).click();
		const entries = getConsole( block ).locator(
			'.code-bin__console-entry'
		);

		await expect( entries ).toHaveText( [
			'Hello 42 {a: 1} [1, 2]',
			'Careful',
			'TypeError: Bad',
		] );
		await expect( entries.nth( 0 ) ).toHaveClass( /\bis-log\b/ );
		await expect( entries.nth( 1 ) ).toHaveClass( /\bis-warn\b/ );
		await expect( entries.nth( 2 ) ).toHaveClass( /\bis-error\b/ );

		const count = block.locator( '.code-bin__count' );
		await expect( count, 'The tab should show the count.' ).toHaveText(
			'3'
		);
		await expect(
			count,
			'The count should show there are errors.'
		).toHaveClass( /\bhas-errors\b/ );
	} );

	test( 'console.clear() clears the console', async ( {
		page,
		requestUtils,
	} ) => {
		const block = await openCodeBin( page, requestUtils, {
			javascript: `console.log( 'Before' );
setTimeout( () => {
	console.clear();
	console.log( 'After' );
}, 100 );`,
		} );

		await expect(
			getConsole( block ).locator( '.code-bin__console-entry' )
		).toHaveText( [ 'After' ] );
	} );

	test( 'stops infinite loops', async ( { page, requestUtils } ) => {
		const block = await openCodeBin( page, requestUtils, {
			html: '<p>Hello</p>',
			javascript: `console.log( 'Before the loop' );
while ( true ) {}`,
		} );

		await expect( block.locator( '.code-bin__status' ) ).toHaveText(
			'Possible infinite loop (JavaScript line 2). The loop was stopped and live preview is paused.'
		);
		await expect(
			getConsole( block ).locator( '.code-bin__console-entry' )
		).toHaveText( [
			'Before the loop',
			'Possible infinite loop, the loop was stopped. (JavaScript line 2)',
		] );

		// The page is still responsive.
		await block.getByRole( 'tab', { name: /^Console/ } ).click();
		await expect( getConsole( block ) ).toBeVisible();
	} );

	test( 'stops infinite loops in scripts in the HTML', async ( {
		page,
		requestUtils,
	} ) => {
		const block = await openCodeBin( page, requestUtils, {
			html: `<p>Hello</p>
<script>for ( ;; ) {}</script>`,
		} );

		await expect( block.locator( '.code-bin__status' ) ).toHaveText(
			'Possible infinite loop (HTML line 2). The loop was stopped and live preview is paused.'
		);
	} );

	test( 'reports the HTML line of a loop in a later script', async ( {
		page,
		requestUtils,
	} ) => {
		const block = await openCodeBin( page, requestUtils, {
			html: `<p>Hello</p>
<script>
console.log( 'First script' );
</script>
<script type="text/plain">for ( ;; ) {}</script>
<p>World</p>
<script>
let count = 0;
while ( true ) {
	count++;
}
</script>`,
		} );

		await expect( block.locator( '.code-bin__status' ) ).toHaveText(
			'Possible infinite loop (HTML line 9). The loop was stopped and live preview is paused.'
		);
	} );

	test( 'reports the HTML line of a syntax error in a script', async ( {
		page,
		requestUtils,
	} ) => {
		const { link } = await createCodeBin( requestUtils, {
			html: `<p>Hello</p>
<script>
console.log( 'Fine' );
const = ;
</script>`,
		} );
		await page.goto( link );

		await expect(
			getBlock( page ).locator( '.code-bin__status' )
		).toContainText( 'Syntax error (HTML line 4):' );
	} );

	test( 'reports the HTML line of an error in a script', async ( {
		page,
		requestUtils,
	} ) => {
		const block = await openCodeBin( page, requestUtils, {
			html: `<p>Hello</p>
<script>
console.log( 'Fine' );
undefinedFunction();
</script>`,
		} );

		await expect( block.locator( '.code-bin__status' ) ).toContainText(
			'Error (HTML line 4): Uncaught ReferenceError: undefinedFunction is not defined.'
		);
	} );

	test( 'reports errors', async ( { page, requestUtils } ) => {
		const block = await openCodeBin( page, requestUtils, {
			javascript: `console.log( 'Before the error' );
undefinedFunction();`,
		} );

		const status = block.locator( '.code-bin__status' );
		await expect( status ).toContainText(
			'Error (JavaScript line 2): Uncaught ReferenceError: undefinedFunction is not defined.'
		);
		await expect( status ).toContainText( 'Live preview is paused.' );
		await expect( status ).toHaveClass( /\bis-error\b/ );
		await expect(
			getConsole( block ).locator( '.code-bin__console-entry' )
		).toHaveText( [
			'Before the error',
			'Uncaught ReferenceError: undefinedFunction is not defined (JavaScript line 2)',
		] );
	} );

	test( 'reports unhandled promise rejections', async ( {
		page,
		requestUtils,
	} ) => {
		const block = await openCodeBin( page, requestUtils, {
			javascript: `Promise.reject( new Error( 'Nope' ) );`,
		} );

		await expect( block.locator( '.code-bin__status' ) ).toHaveText(
			'Error: Uncaught (in promise) Nope. Live preview is paused.'
		);
	} );

	test( 'does not run JavaScript with a syntax error', async ( {
		page,
		requestUtils,
	} ) => {
		const { link } = await createCodeBin( requestUtils, {
			html: '<p id="output">Not run</p>',
			javascript: `document.getElementById( 'output' ).textContent = 'Run';
const = ;`,
		} );
		await page.goto( link );
		const block = getBlock( page );

		await expect( block.locator( '.code-bin__status' ) ).toContainText(
			'Syntax error (JavaScript line 2):'
		);
		await expect( block.locator( '.code-bin__status' ) ).toContainText(
			'The preview has not been updated.'
		);
		await expect(
			getPreview( block ).locator( '#output' ),
			'The server rendered preview should be kept.'
		).toHaveText( 'Not run' );
	} );

	test( 'keeps entities in the code as text', async ( {
		page,
		requestUtils,
	} ) => {
		const block = await openCodeBin( page, requestUtils, {
			html: '<p id="output">&lt;b&gt;Not bold&lt;/b&gt; &amp;amp;</p>',
			css: '#output::after { content: " &copy;"; }',
			javascript: 'console.log( "&lt;b&gt; &amp;" );',
		} );
		const output = getPreview( block ).locator( '#output' );

		await expect( output ).toHaveText( '<b>Not bold</b> &amp;' );
		await expect( output.locator( 'b' ) ).toHaveCount( 0 );
		expect(
			await output.evaluate(
				( element ) =>
					element.ownerDocument.defaultView.getComputedStyle(
						element,
						'::after'
					).content
			)
		).toBe( '" &copy;"' );
		await expect(
			getConsole( block ).locator( '.code-bin__console-entry' )
		).toHaveText( [ '&lt;b&gt; &amp;' ] );
	} );

	test.describe( 'editing the code', () => {
		const CODE = {
			html: '<p id="output">Saved</p>',
			css: '#output { color: rgb(0, 0, 255); }',
			javascript: 'console.log( "Saved" );',
		};

		test( 'updates the preview as visitors type', async ( {
			page,
			requestUtils,
		} ) => {
			const block = await openCodeBin( page, requestUtils, {
				...CODE,
				editable: true,
			} );
			const output = getPreview( block ).locator( '#output' );
			await expect( output ).toHaveText( 'Saved' );

			await editPanel( block, 'HTML', '<p id="output">Edited</p>' );
			await expect( output ).toHaveText( 'Edited' );

			await editPanel(
				block,
				'CSS',
				'#output { color: rgb(255, 0, 0); }'
			);
			await expect( output ).toHaveCSS( 'color', 'rgb(255, 0, 0)' );

			await editPanel( block, 'JavaScript', 'console.log( "Edited" );' );
			await expect(
				getConsole( block ).locator( '.code-bin__console-entry' )
			).toHaveText( [ 'Edited' ] );

			// The highlighted code follows the textarea.
			await expect(
				block.locator( 'code.language-javascript' )
			).toHaveText( 'console.log( "Edited" );' );
		} );

		test( 'pauses live updates after an error until Run is clicked', async ( {
			page,
			requestUtils,
		} ) => {
			const block = await openCodeBin( page, requestUtils, {
				...CODE,
				editable: true,
			} );
			const output = getPreview( block ).locator( '#output' );
			const status = block.locator( '.code-bin__status' );

			await editPanel( block, 'JavaScript', 'undefinedFunction();' );
			await expect( status ).toContainText( 'Live preview is paused.' );

			await editPanel( block, 'HTML', '<p id="output">Edited</p>' );
			// Longer than the live update delay.
			await page.waitForTimeout( 1000 );
			await expect( output, 'The preview should be paused.' ).toHaveText(
				'Saved'
			);

			await editPanel( block, 'JavaScript', 'console.log( "Fixed" );' );
			await block
				.getByRole( 'button', { name: 'Run', exact: true } )
				.click();
			await expect( output ).toHaveText( 'Edited' );
			await expect( status ).toBeHidden();
		} );

		test( 'Reset restores the saved code', async ( {
			page,
			requestUtils,
		} ) => {
			const block = await openCodeBin( page, requestUtils, {
				...CODE,
				editable: true,
			} );
			const output = getPreview( block ).locator( '#output' );

			await editPanel( block, 'HTML', '<p id="output">Edited</p>' );
			await editPanel( block, 'JavaScript', 'console.log( "Edited" );' );
			await expect( output ).toHaveText( 'Edited' );

			await block
				.getByRole( 'button', { name: 'Reset', exact: true } )
				.click();

			await expect( output ).toHaveText( 'Saved' );
			await expect(
				block.getByRole( 'textbox', {
					name: 'JavaScript',
					exact: true,
				} )
			).toHaveValue( CODE.javascript );
			await block
				.getByRole( 'tab', { name: 'HTML', exact: true } )
				.click();
			await expect(
				block.getByRole( 'textbox', { name: 'HTML', exact: true } )
			).toHaveValue( CODE.html );
			await expect(
				getConsole( block ).locator( '.code-bin__console-entry' )
			).toHaveText( [ 'Saved' ] );
		} );

		test( 'Run reruns the code', async ( { page, requestUtils } ) => {
			const block = await openCodeBin( page, requestUtils, {
				javascript: 'console.log( "Run" );',
			} );
			const entries = getConsole( block ).locator(
				'.code-bin__console-entry'
			);
			await expect( entries ).toHaveText( [ 'Run' ] );

			await block
				.getByRole( 'button', { name: 'Run', exact: true } )
				.click();

			// The console is cleared and the code runs again.
			await expect( entries ).toHaveText( [ 'Run' ] );
			await expect(
				block.locator( 'iframe.code-bin__preview' )
			).toHaveAttribute( 'sandbox', 'allow-scripts' );
		} );

		test( 'the code cannot be edited when editing is turned off', async ( {
			page,
			requestUtils,
		} ) => {
			const block = await openCodeBin( page, requestUtils, {
				...CODE,
				editable: false,
			} );

			await expect( block.getByRole( 'textbox' ) ).toHaveCount( 0 );
			await expect(
				block.getByRole( 'button', { name: 'Reset', exact: true } )
			).toHaveCount( 0 );
			await expect(
				block.getByRole( 'button', { name: 'Run', exact: true } )
			).toBeVisible();
		} );
	} );
} );
