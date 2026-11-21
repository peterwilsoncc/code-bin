/**
 * Code is stored in post meta, so kses doesn't change it when users without
 * the `unfiltered_html` capability save it. See inc/meta.php.
 */

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	BLOCK_NAME,
	META_KEY,
	createCodeBin,
	getBlock,
	getConsole,
} = require( './utils/code-bin' );

const PANEL_LABELS = { html: 'HTML', css: 'CSS', javascript: 'JavaScript' };

/**
 * Code that kses changes, from the investigation of the bug.
 *
 * The JavaScript logs markers the tests check for, so it must run as written.
 */
const KSES_CODE = {
	html: `<p>Tom & Jerry</p>
<button onclick="go()">Go</button>
<iframe src="https://example.org"></iframe>
<p style="color: red; position: fixed">Styled</p>`,
	css: `a > b { color: red; }
p::after { content: "&"; }`,
	javascript: `const a = 1, b = 2, c = 3, d = 0;
if ( a && b ) { console.log( 'and-ok' ); }
if ( a < b ) { console.log( 'less-ok' ); }
if ( a < b && c > d ) { console.log( 'compare-ok' ); }
const button = "<button onclick=\\"go()\\">Go</button>";
const strong = "<strong class=\\"x\\">Hi</strong>";
console.log( 'strings-ok ' + button.length + ' ' + strong.length );`,
};

const AUTHOR = {
	username: 'code-bin-storage-author',
	email: 'code-bin-storage-author@example.com',
	password: 'password',
	roles: [ 'author' ],
};

/**
 * Edit a panel's code in the editor.
 *
 * @param {Object} block Block locator.
 * @param {string} panel Panel: html, css or javascript.
 * @param {string} code  Code.
 */
async function editPanel( block, panel, code ) {
	const label = PANEL_LABELS[ panel ];
	await block
		.getByRole( 'tab', { name: new RegExp( `^${ label }( |$)` ) } )
		.click();
	await block
		.getByRole( 'textbox', { name: label, exact: true } )
		.fill( code );
}

/**
 * Get the code shown in a block's panels in the editor.
 *
 * @param {Object} block Block locator.
 * @return {Promise<Object>} Code, keyed by panel.
 */
async function getEditorCode( block ) {
	const code = {};
	for ( const [ panel, label ] of Object.entries( PANEL_LABELS ) ) {
		await block
			.getByRole( 'tab', { name: new RegExp( `^${ label }( |$)` ) } )
			.click();
		code[ panel ] = await block
			.getByRole( 'textbox', { name: label, exact: true } )
			.inputValue();
	}
	return code;
}

/**
 * Get a post as it's saved, via the REST API.
 *
 * @param {Object} requestUtils Request utils.
 * @param {string} restBase     Post type's REST base.
 * @param {number} id           Post ID.
 * @return {Promise<Object>} Post, in the edit context.
 */
function getSavedPost( requestUtils, restBase, id ) {
	return requestUtils.rest( {
		path: `/wp/v2/${ restBase }/${ id }`,
		params: { context: 'edit' },
	} );
}

/**
 * Get the code bin blocks' attributes from saved content.
 *
 * @param {string} content Post content.
 * @return {Object[]} Attributes of each Code Bin block.
 */
function getSavedAttributes( content ) {
	const pattern = new RegExp(
		`<!-- wp:${ BLOCK_NAME }( (\\{.*?\\}))? /-->`,
		'g'
	);
	return [ ...content.matchAll( pattern ) ].map( ( match ) =>
		match[ 2 ] ? JSON.parse( match[ 2 ] ) : {}
	);
}

/**
 * Save the post being edited.
 *
 * @param {Object} page Page.
 */
async function savePost( page ) {
	await page
		.getByRole( 'region', { name: 'Editor top bar' } )
		.getByRole( 'button', { name: 'Save', exact: true } )
		.click();
	await expect(
		page
			.getByRole( 'button', { name: 'Dismiss this notice' } )
			.filter( { hasText: /updated|saved/i } )
			.first()
	).toBeVisible();
}

/**
 * Open a preview of the post being edited.
 *
 * `editor.openPreviewPage()` looks for the menu item's older label.
 *
 * @param {Object} page Page.
 * @return {Promise<Object>} Preview page.
 */
async function openPreview( page ) {
	await page
		.getByRole( 'region', { name: 'Editor top bar' } )
		.getByRole( 'button', { name: 'View', exact: true } )
		.click();
	const [ preview ] = await Promise.all( [
		page.context().waitForEvent( 'page' ),
		page
			.getByRole( 'menuitem', {
				name: /^Preview (in new tab|\(opens in a new tab\))/,
			} )
			.click(),
	] );
	return preview;
}

test.describe( 'Code Bin code storage', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts( 'code-bins' );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'keeps code saved by an author exactly as written', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await requestUtils.createUser( AUTHOR );
		await page.context().clearCookies();
		await page.goto( '/wp-login.php' );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( AUTHOR.username );
		await page
			.getByLabel( 'Password', { exact: true } )
			.fill( AUTHOR.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.waitForURL( /wp-admin/ );

		await admin.createNewPost( {
			postType: 'code_bin',
			title: 'Author code bin',
		} );
		const block = getBlock( editor.canvas );
		for ( const [ panel, code ] of Object.entries( KSES_CODE ) ) {
			await editPanel( block, panel, code );
		}
		const postId = await editor.publishPost();

		const post = await getSavedPost( requestUtils, 'code-bins', postId );
		const [ attributes ] = getSavedAttributes( post.content.raw );
		expect(
			attributes,
			'The code should not be in the block attributes.'
		).not.toHaveProperty( 'javascript' );
		expect(
			post.meta[ META_KEY ][ attributes.codeId ],
			'The code should be saved exactly as written.'
		).toEqual( KSES_CODE );

		await page.reload();
		expect(
			await getEditorCode( getBlock( editor.canvas ) ),
			'The editor should show the saved code after a reload.'
		).toEqual( KSES_CODE );

		await page.goto( post.link );
		const siteBlock = getBlock( page );
		for ( const panel of Object.keys( KSES_CODE ) ) {
			await expect(
				siteBlock.locator( `code.language-${ panel }` )
			).toHaveText( KSES_CODE[ panel ] );
		}
		const consoleView = getConsole( siteBlock );
		for ( const marker of [
			'and-ok',
			'less-ok',
			'compare-ok',
			'strings-ok 34 29',
		] ) {
			await expect(
				consoleView,
				'The JavaScript should run as written.'
			).toContainText( marker );
		}
	} );

	test( 'moves code saved in attributes to meta when edited', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const legacyCode = {
			html: '<p id="output">Legacy</p>',
			css: '',
			javascript: 'console.log( 1 && 2 );',
		};
		const { id, link } = await createCodeBin(
			requestUtils,
			legacyCode,
			{},
			{ legacy: true }
		);

		// Unmigrated code bins work on the site.
		await page.goto( link );
		await expect( getConsole( getBlock( page ) ) ).toHaveText( '2' );

		await admin.editPost( id );
		const block = getBlock( editor.canvas );
		expect( await getEditorCode( block ) ).toEqual( legacyCode );
		await editPanel( block, 'css', 'p { color: red; }' );
		await savePost( page );

		const post = await getSavedPost( requestUtils, 'code-bins', id );
		const [ attributes ] = getSavedAttributes( post.content.raw );
		expect( attributes ).not.toHaveProperty( 'html' );
		expect( attributes.codeId ).toMatch( /^[a-z0-9]{12}$/ );
		expect( post.meta[ META_KEY ] ).toEqual( {
			[ attributes.codeId ]: { ...legacyCode, css: 'p { color: red; }' },
		} );

		await page.goto( link );
		await expect( getConsole( getBlock( page ) ) ).toHaveText( '2' );
	} );

	test.describe( 'previews', () => {
		test( 'show unsaved code in a draft', async ( {
			admin,
			editor,
			page,
			requestUtils,
		} ) => {
			await admin.createNewPost( {
				postType: 'code_bin',
				title: 'Draft preview',
			} );
			const block = getBlock( editor.canvas );
			await editPanel( block, 'html', '<p>First draft</p>' );
			await editor.saveDraft();

			await editPanel( block, 'html', '<p>Unsaved draft</p>' );
			const preview = await openPreview( page );

			await expect(
				getBlock( preview ).locator( 'code.language-html' )
			).toHaveText( '<p>Unsaved draft</p>' );
			await preview.close();

			// Previewing a draft saves it.
			const postId = await editor.canvas
				.locator( 'body' )
				.evaluate( () =>
					window.parent.wp.data
						.select( 'core/editor' )
						.getCurrentPostId()
				);
			const post = await getSavedPost(
				requestUtils,
				'code-bins',
				postId
			);
			expect(
				Object.values( post.meta[ META_KEY ] ).map(
					( code ) => code.html
				)
			).toEqual( [ '<p>Unsaved draft</p>' ] );
		} );

		test( 'show unsaved code without changing a published code bin', async ( {
			admin,
			editor,
			page,
			requestUtils,
		} ) => {
			const { id, link } = await createCodeBin( requestUtils, {
				html: '<p>Published</p>',
			} );
			await admin.editPost( id );
			const block = getBlock( editor.canvas );
			await editPanel( block, 'html', '<p>Unsaved change</p>' );

			const preview = await openPreview( page );
			await expect(
				getBlock( preview ).locator( 'code.language-html' ),
				'The preview should show the unsaved code.'
			).toHaveText( '<p>Unsaved change</p>' );
			await preview.close();

			await page.goto( link );
			await expect(
				getBlock( page ).locator( 'code.language-html' ),
				'The published code bin should be unchanged.'
			).toHaveText( '<p>Published</p>' );
		} );
	} );

	test( 'copies the code of a duplicated block', async ( {
		admin,
		editor,
		page,
		pageUtils,
		requestUtils,
	} ) => {
		await admin.createNewPost( { title: 'Duplicated code bins' } );
		await editor.insertBlock( { name: BLOCK_NAME } );
		await editPanel(
			getBlock( editor.canvas ),
			'html',
			'<p>Duplicated</p>'
		);

		await pageUtils.pressKeys( 'primaryShift+d' );
		const blocks = getBlock( editor.canvas );
		await expect( blocks ).toHaveCount( 2 );
		for ( const index of [ 0, 1 ] ) {
			expect(
				( await getEditorCode( blocks.nth( index ) ) ).html,
				'Both blocks should have the code.'
			).toBe( '<p>Duplicated</p>' );
		}

		// Changing the copy doesn't change the original.
		await editPanel( blocks.nth( 1 ), 'html', '<p>Changed</p>' );
		expect( ( await getEditorCode( blocks.nth( 0 ) ) ).html ).toBe(
			'<p>Duplicated</p>'
		);

		const postId = await editor.publishPost();
		const post = await getSavedPost( requestUtils, 'posts', postId );
		const codeIds = getSavedAttributes( post.content.raw ).map(
			( attributes ) => attributes.codeId
		);
		expect( new Set( codeIds ).size ).toBe( 2 );
		expect(
			codeIds.map( ( codeId ) => post.meta[ META_KEY ][ codeId ].html )
		).toEqual( [ '<p>Duplicated</p>', '<p>Changed</p>' ] );

		await page.goto( post.link );
		await expect(
			getBlock( page ).locator( 'code.language-html' )
		).toHaveText( [ '<p>Duplicated</p>', '<p>Changed</p>' ] );
	} );

	test( 'copies the code of a block pasted from another post', async ( {
		admin,
		editor,
		requestUtils,
	} ) => {
		const codeId = 'pastedcode01';
		const source = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: 'Source post',
				status: 'publish',
				content: `<!-- wp:${ BLOCK_NAME } {"codeId":"${ codeId }"} /-->`,
				meta: {
					[ META_KEY ]: {
						[ codeId ]: {
							html: '<p>Pasted</p>',
							css: '',
							javascript: 'console.log( 1 && 2 );',
						},
					},
				},
			},
		} );

		// A block copied from the source post, as it's pasted.
		await admin.createNewPost( { title: 'Pasted code bin' } );
		await editor.insertBlock( {
			name: BLOCK_NAME,
			attributes: { codeId, codePost: source.id },
		} );

		const block = getBlock( editor.canvas );
		await expect( async () => {
			expect( await getEditorCode( block ) ).toEqual( {
				html: '<p>Pasted</p>',
				css: '',
				javascript: 'console.log( 1 && 2 );',
			} );
		} ).toPass();

		const postId = await editor.publishPost();
		const post = await getSavedPost( requestUtils, 'posts', postId );
		const [ attributes ] = getSavedAttributes( post.content.raw );
		expect( attributes.codePost ).toBe( postId );
		expect( post.meta[ META_KEY ][ attributes.codeId ].javascript ).toBe(
			'console.log( 1 && 2 );'
		);
	} );
} );
