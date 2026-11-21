/**
 * Helpers for the Code Bin E2E tests.
 */

const BLOCK_NAME = 'code-bin-block/code-bin-block';

/**
 * Serialize block attributes for a block comment.
 *
 * Matches the escaping used by the block serializer in `@wordpress/blocks`.
 *
 * @param {Object} attributes Block attributes.
 * @return {string} Serialized attributes.
 */
function serializeAttributes( attributes ) {
	return JSON.stringify( attributes )
		.replace( /--/g, '\\u002d\\u002d' )
		.replace( /</g, '\\u003c' )
		.replace( />/g, '\\u003e' )
		.replace( /&/g, '\\u0026' )
		.replace( /\\"/g, '\\u0022' );
}

/**
 * Create and publish a code bin via the REST API.
 *
 * Created as the logged in user of `requestUtils`, the admin by default. Admins
 * can save any code: for authors, WordPress filters the block attributes with
 * kses.
 *
 * @param {Object} requestUtils Request utils from `@wordpress/e2e-test-utils-playwright`.
 * @param {Object} attributes   Block attributes.
 * @param {string} [title]      Post title.
 * @return {Promise<{id: number, link: string}>} The code bin's ID and URL.
 */
async function createCodeBin(
	requestUtils,
	attributes,
	title = 'E2E code bin'
) {
	const post = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/code-bins',
		data: {
			title,
			status: 'publish',
			content: `<!-- wp:${ BLOCK_NAME } ${ serializeAttributes(
				attributes
			) } /-->`,
		},
	} );

	return { id: post.id, link: post.link };
}

/**
 * Get the Code Bin block on a page.
 *
 * @param {Object} page Page.
 * @return {Object} Block locator.
 */
function getBlock( page ) {
	return page.locator( '.wp-block-code-bin-block-code-bin-block' );
}

/**
 * Get a block's console.
 *
 * @param {Object} block Block locator.
 * @return {Object} Console locator.
 */
function getConsole( block ) {
	return block.locator( '.code-bin__console' );
}

/**
 * Get the document in a block's preview.
 *
 * @param {Object} block Block locator.
 * @return {Object} Frame locator.
 */
function getPreview( block ) {
	return block.locator( 'iframe.code-bin__preview' ).contentFrame();
}

module.exports = {
	BLOCK_NAME,
	createCodeBin,
	getBlock,
	getConsole,
	getPreview,
};
