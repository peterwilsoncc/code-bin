/**
 * Code bins embedded in posts via oEmbed: the embed's width, and its height
 * following the code bin as it changes size.
 *
 * WordPress embeds posts in an iframe of their `/embed/` URL. The embedded
 * page reports its height to the embedding page, which sets the iframe's
 * height, limited to 200px–1000px (see wp-includes/js/wp-embed.js). The
 * plugin's embed template reports the height whenever the code bin changes
 * size, not only on load, and keeps the code bin within 1000px.
 */

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { createCodeBin, getBlock } = require( './utils/code-bin' );

/**
 * Lines of code.
 *
 * @param {number} count Number of lines.
 * @return {string} Code.
 */
function lines( count ) {
	return Array.from(
		{ length: count },
		( value, index ) => `<p>Line ${ index + 1 }</p>`
	).join( '\n' );
}

/**
 * Change a code textarea's code as a visitor typing would, by firing `input`.
 *
 * Playwright's fill() focuses and scrolls the textarea into view, which can
 * make the embed report its height by itself: changing the code without it
 * tests the embed reports its height because the code bin changed size.
 *
 * @param {Object} textarea Textarea locator.
 * @param {string} code     Code.
 */
async function setCode( textarea, code ) {
	await textarea.evaluate( ( element, value ) => {
		element.value = value;
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}, code );
}

/**
 * Embed a code bin in a post and view the post.
 *
 * @param {Object} page         Page.
 * @param {Object} requestUtils Request utils.
 * @param {Object} attributes   Code bin block attributes.
 * @return {Promise<{iframe: Object, frame: Object}>} The embed iframe, and a frame locator for its content.
 */
async function viewEmbed( page, requestUtils, attributes ) {
	const { link } = await createCodeBin( requestUtils, {
		editable: true,
		...attributes,
	} );
	const post = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/posts',
		data: {
			title: 'Embedded code bin',
			status: 'publish',
			content: `<!-- wp:embed {"url":"${ link }","type":"wp-embed","providerNameSlug":"code-bin-block"} -->
<figure class="wp-block-embed is-type-wp-embed is-provider-code-bin-block wp-block-embed-code-bin-block"><div class="wp-block-embed__wrapper">
${ link }
</div></figure>
<!-- /wp:embed -->`,
		},
	} );
	await page.goto( post.link );

	const iframe = page.locator( 'iframe.wp-embedded-content' );

	// The embedding page shows the iframe when it receives the embed's height.
	await expect( iframe ).toBeVisible();

	const frame = iframe.contentFrame();
	await expect(
		getBlock( frame ).locator( 'iframe.code-bin__preview' )
	).toHaveAttribute( 'sandbox', 'allow-scripts' );

	/*
	 * Core's embed script reports the height when the embedded page loads,
	 * which can be after the preview runs. Wait for it, so later changes in
	 * height can only be reported by the plugin's embed template.
	 */
	await frame
		.locator( 'body' )
		.evaluate(
			( body ) =>
				body.ownerDocument.readyState === 'complete' ||
				new Promise( ( resolve ) =>
					body.ownerDocument.defaultView.addEventListener(
						'load',
						resolve,
						{ once: true }
					)
				)
		);

	return { iframe, frame };
}

/**
 * Get the height of the embedded page's content, as the embed reports it.
 *
 * @param {Object} frame Frame locator for the embed.
 * @return {Promise<number>} Height in pixels.
 */
function getContentHeight( frame ) {
	return frame
		.locator( 'body' )
		.evaluate( ( body ) =>
			Math.ceil( body.ownerDocument.documentElement.offsetHeight )
		);
}

/**
 * Check the embed's iframe is as tall as its content, within the limits set
 * by the embedding page.
 *
 * @param {Object} iframe Embed iframe locator.
 * @param {Object} frame  Frame locator for the embed.
 */
async function expectFittedHeight( iframe, frame ) {
	await expect( async () => {
		const content = await getContentHeight( frame );
		const height = Number( await iframe.getAttribute( 'height' ) );
		const expected = Math.min( Math.max( content, 200 ), 1000 );
		expect( Math.abs( height - expected ) ).toBeLessThanOrEqual( 2 );
		// Without a timeout toPass() retries until the test times out.
	} ).toPass( { timeout: 5000 } );
}

/**
 * Wait for the embed's height to settle before changing the code bin.
 *
 * Core's embed script reports the height 100ms after the embed is resized, so
 * after the embedding page sets the iframe's height a report is pending. A
 * change in that time would be reported by it, rather than because the code
 * bin changed size.
 *
 * @param {Object} page   Page.
 * @param {Object} iframe Embed iframe locator.
 * @param {Object} frame  Frame locator for the embed.
 * @return {Promise<number>} The settled height.
 */
async function settleHeight( page, iframe, frame ) {
	await expectFittedHeight( iframe, frame );
	await page.waitForTimeout( 500 );
	await expectFittedHeight( iframe, frame );
	return Number( await iframe.getAttribute( 'height' ) );
}

test.describe( 'Code Bin embeds', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts( 'code-bins' );
		await requestUtils.deleteAllPosts();
	} );

	test( 'fill the width of the embedding post', async ( {
		page,
		requestUtils,
	} ) => {
		const { iframe } = await viewEmbed( page, requestUtils, {
			html: '<p>Wide</p>',
		} );

		await expect( iframe ).toHaveAttribute( 'width', '100%' );

		const wrapper = page.locator( '.wp-block-embed__wrapper' );
		const [ iframeBox, wrapperBox ] = await Promise.all( [
			iframe.boundingBox(),
			wrapper.boundingBox(),
		] );
		expect(
			Math.abs( iframeBox.width - wrapperBox.width ),
			'The embed should be as wide as the post content.'
		).toBeLessThanOrEqual( 1 );
		// Wider than the 600px WordPress limits embeds to by default.
		expect( iframeBox.width ).toBeGreaterThan( 600 );
	} );

	test( 'are as tall as the code bin', async ( { page, requestUtils } ) => {
		const { iframe, frame } = await viewEmbed( page, requestUtils, {
			html: lines( 3 ),
		} );

		await expectFittedHeight( iframe, frame );
	} );

	test( 'change height with the code bin', async ( {
		page,
		requestUtils,
	} ) => {
		const { iframe, frame } = await viewEmbed( page, requestUtils, {
			html: lines( 3 ),
			previewHeight: 200,
		} );
		const initial = await settleHeight( page, iframe, frame );

		// A visitor adds code, making the code panel taller.
		const textarea = getBlock( frame ).getByRole( 'textbox', {
			name: 'HTML',
			exact: true,
		} );
		await setCode( textarea, lines( 15 ) );
		const grown = await settleHeight( page, iframe, frame );
		expect( grown, 'The embed should grow.' ).toBeGreaterThan(
			initial + 100
		);

		// And removes it again.
		await setCode( textarea, lines( 3 ) );
		await expectFittedHeight( iframe, frame );
		expect(
			Number( await iframe.getAttribute( 'height' ) ),
			'The embed should shrink.'
		).toBeLessThan( grown - 100 );
	} );

	test( 'change height when an error is shown', async ( {
		page,
		requestUtils,
	} ) => {
		const { iframe, frame } = await viewEmbed( page, requestUtils, {
			html: lines( 3 ),
			javascript: 'console.log( "ok" );',
			previewHeight: 200,
		} );
		const initial = await settleHeight( page, iframe, frame );

		// The status message appears above the output.
		const block = getBlock( frame );
		// The JavaScript panel is hidden: its textarea can still be changed.
		await setCode(
			block.locator( '[data-panel="javascript"] textarea' ),
			'throw new Error( "Oops" );'
		);
		await expect( block.locator( '.code-bin__status' ) ).toBeVisible();

		await expectFittedHeight( iframe, frame );
		expect(
			Number( await iframe.getAttribute( 'height' ) )
		).toBeGreaterThan( initial );
	} );

	test( 'stay within 1000px', async ( { page, requestUtils } ) => {
		const { iframe, frame } = await viewEmbed( page, requestUtils, {
			html: lines( 10 ),
			previewHeight: 1200,
		} );

		await expect( iframe ).toHaveAttribute( 'height', '1000' );

		// The code bin fits: the output shrinks to fit and scrolls.
		const block = getBlock( frame );
		const blockBox = await block.boundingBox();
		expect( blockBox.height ).toBeLessThanOrEqual( 1000 );
		await expect(
			block.getByRole( 'button', { name: 'Run' } )
		).toBeInViewport();
		await expect(
			block.locator( '.code-bin__output' ).first()
		).toBeInViewport();
	} );
} );
