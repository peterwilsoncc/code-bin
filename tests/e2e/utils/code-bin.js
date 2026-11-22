/**
 * Helpers for the Code Bin E2E tests.
 */

/**
 * External dependencies
 */
const { randomInt } = require( 'node:crypto' );

const BLOCK_NAME = 'code-bin-block/code-bin-block';

// The meta storing the code, see inc/meta.php.
const META_KEY = '_code_bin';

const PANELS = [ 'html', 'css', 'javascript' ];

/**
 * Generate a code ID, in the format of generateCodeId() in use-code-storage.js.
 *
 * @return {string} Code ID.
 */
function generateCodeId() {
	const characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
	return Array.from(
		{ length: 12 },
		() => characters[ randomInt( characters.length ) ]
	).join( '' );
}

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
 * The code is stored in the post's meta, as the editor saves it. Pass
 * `legacy: true` to store it in the block's attributes, as before 1.5.0.
 *
 * Created as the logged in user of `requestUtils`, the admin by default.
 *
 * @param {Object}  requestUtils     Request utils from `@wordpress/e2e-test-utils-playwright`.
 * @param {Object}  attributes       Block attributes, including the code: `html`, `css` and `javascript`.
 * @param {Object}  [data]           Other post fields, eg `title` or `author`.
 * @param {Object}  [options]        Options.
 * @param {boolean} [options.legacy] Store the code in the block's attributes.
 * @return {Promise<{id: number, link: string, codeId: string|undefined}>} The code bin's ID, URL and code ID.
 */
async function createCodeBin(
	requestUtils,
	attributes,
	data = {},
	{ legacy = false } = {}
) {
	let blockAttributes = attributes;
	let meta;
	let codeId;

	if ( ! legacy ) {
		codeId = generateCodeId();
		blockAttributes = { ...attributes, codeId };
		const code = {};
		for ( const panel of PANELS ) {
			code[ panel ] = attributes[ panel ] ?? '';
			delete blockAttributes[ panel ];
		}
		meta = { [ META_KEY ]: { [ codeId ]: code } };
	}

	const post = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/code-bins',
		data: {
			title: 'E2E code bin',
			status: 'publish',
			content: `<!-- wp:${ BLOCK_NAME } ${ serializeAttributes(
				blockAttributes
			) } /-->`,
			...( meta && { meta } ),
			...data,
		},
	} );

	return { id: post.id, link: post.link, codeId };
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
	META_KEY,
	createCodeBin,
	getBlock,
	getConsole,
	getPreview,
};
