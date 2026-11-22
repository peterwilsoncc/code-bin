/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Code Bin', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts( 'code-bins' );
	} );

	test( 'creates a code bin and shows it on the site', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			postType: 'code_bin',
			title: 'E2E code bin',
		} );

		// The post type's template adds the block.
		await editor.canvas
			.getByRole( 'textbox', { name: 'HTML', exact: true } )
			.fill( '<p class="greeting">Hello from the code bin</p>' );

		await expect(
			editor.canvas
				.locator( 'iframe.code-bin__preview' )
				.contentFrame()
				.locator( '.greeting' ),
			'The editor should preview the HTML.'
		).toHaveText( 'Hello from the code bin' );

		const postId = await editor.publishPost();
		await page.goto( `/?post_type=code_bin&p=${ postId }` );

		const block = page.locator( '.wp-block-code-bin-block-code-bin-block' );
		await expect(
			block.locator( 'code.language-html' ),
			'The site should show the code.'
		).toHaveText( '<p class="greeting">Hello from the code bin</p>' );
		await expect(
			block
				.locator( 'iframe.code-bin__preview' )
				.contentFrame()
				.locator( '.greeting' ),
			'The site should preview the HTML.'
		).toHaveText( 'Hello from the code bin' );
	} );
} );
