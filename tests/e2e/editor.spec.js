/**
 * The Code Bin block in the editor: the post type's locked template, the code
 * tabs, the settings, the generated anchor and the preview.
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
	getBlock,
	getConsole,
	getPreview,
} = require( './utils/code-bin' );

// See generateAnchor() in use-generated-anchor.js.
const ANCHOR_PATTERN = /^code-bin-[a-z0-9]{1,10}$/;

/**
 * Create a new code bin in the editor.
 *
 * @param {Object} admin  Admin utils.
 * @param {Object} editor Editor utils.
 * @return {Promise<Object>} Block locator.
 */
async function createCodeBinInEditor( admin, editor ) {
	await admin.createNewPost( {
		postType: 'code_bin',
		title: 'E2E code bin',
	} );

	// The post type's template adds the block.
	const block = getBlock( editor.canvas );
	await expect( block ).toBeVisible();
	return block;
}

/**
 * Edit a panel's code in the editor.
 *
 * @param {Object} block Block locator.
 * @param {string} label Panel label: HTML, CSS or JavaScript.
 * @param {string} code  Code.
 */
async function editPanel( block, label, code ) {
	// The tab's name changes once the panel contains code.
	await block
		.getByRole( 'tab', { name: new RegExp( `^${ label }( |$)` ) } )
		.click();
	await block
		.getByRole( 'textbox', { name: label, exact: true } )
		.fill( code );
}

/**
 * Get the anchors of the blocks in the editor, once each block has one.
 *
 * @param {Object} editor Editor utils.
 * @param {number} count  Number of blocks expected.
 * @return {Promise<string[]>} Anchors.
 */
async function getAnchors( editor, count = 1 ) {
	let anchors;
	await expect( async () => {
		const blocks = await editor.getBlocks();
		anchors = blocks.map( ( block ) => block.attributes.anchor );
		expect( anchors ).toHaveLength( count );
		for ( const anchor of anchors ) {
			expect( anchor ).toMatch( ANCHOR_PATTERN );
		}
	} ).toPass();
	return anchors;
}

test.describe( 'Code Bin editor', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts( 'code-bins' );
		await requestUtils.deleteAllPosts();
	} );

	test.describe( 'locked template', () => {
		test( 'has one block that cannot be removed, moved or added to', async ( {
			admin,
			editor,
			page,
		} ) => {
			await createCodeBinInEditor( admin, editor );

			const blocks = await editor.getBlocks();
			expect( blocks.map( ( block ) => block.name ) ).toEqual( [
				BLOCK_NAME,
			] );

			const permissions = await page.evaluate( () => {
				const store = window.wp.data.select( 'core/block-editor' );
				const [ clientId ] = store.getBlockOrder();
				return {
					remove: store.canRemoveBlock( clientId ),
					move: store.canMoveBlock( clientId ),
					insertCodeBin: store.canInsertBlockType(
						'code-bin-block/code-bin-block'
					),
					insertParagraph:
						store.canInsertBlockType( 'core/paragraph' ),
				};
			} );
			expect( permissions ).toEqual( {
				remove: false,
				move: false,
				insertCodeBin: false,
				insertParagraph: false,
			} );

			// The inserter opens, but has nothing to insert.
			await page
				.getByRole( 'region', { name: 'Editor top bar' } )
				.getByRole( 'button', { name: 'Block Inserter' } )
				.click();
			const library = page.getByRole( 'region', {
				name: 'Block Library',
			} );
			for ( const tab of [ 'Blocks', 'Patterns' ] ) {
				await library.getByRole( 'tab', { name: tab } ).click();
				await expect(
					library.getByRole( 'tabpanel', { name: tab } ),
					`The inserter should have no ${ tab.toLowerCase() }.`
				).toContainText( 'No results found.' );
			}
		} );

		test( 'has no block options to remove, duplicate or unlock the block', async ( {
			admin,
			editor,
			page,
		} ) => {
			const block = await createCodeBinInEditor( admin, editor );
			await editor.selectBlocks( block );

			const toolbar = page.getByRole( 'toolbar', {
				name: 'Block tools',
			} );
			await expect(
				toolbar.getByRole( 'button', { name: 'Locked' } ),
				'The block should not be unlockable.'
			).toBeDisabled();

			await toolbar.getByRole( 'button', { name: 'Options' } ).click();
			const menu = page.getByRole( 'menu', { name: 'Options' } );
			await expect( menu ).toBeVisible();
			for ( const name of [
				/^Delete/,
				/^Duplicate/,
				/^Lock/,
				/^Unlock/,
				/^Add before/,
				/^Add after/,
				/^Move to/,
				/^Edit as HTML/,
			] ) {
				await expect(
					menu.getByRole( 'menuitem', { name } ),
					`The block options should not include ${ name }.`
				).toHaveCount( 0 );
			}
		} );

		test( 'has no code editor', async ( {
			admin,
			editor,
			page,
			pageUtils,
		} ) => {
			await createCodeBinInEditor( admin, editor );

			await page
				.getByRole( 'region', { name: 'Editor top bar' } )
				.getByRole( 'button', { name: 'Options' } )
				.click();
			await expect(
				page.getByRole( 'menuitemradio', { name: 'Visual editor' } ),
				'The options menu should be open.'
			).toBeVisible();
			await expect(
				page.getByRole( 'menuitemradio', { name: 'Code editor' } )
			).toHaveAttribute( 'aria-disabled', 'true' );
			await page.keyboard.press( 'Escape' );

			// The keyboard shortcut for the code editor does nothing.
			await pageUtils.pressKeys( 'secondary+m' );
			await expect(
				page.getByRole( 'textbox', { name: 'Type text or HTML' } )
			).toHaveCount( 0 );
			await expect(
				getBlock( editor.canvas ).getByRole( 'textbox', {
					name: 'HTML',
					exact: true,
				} )
			).toBeVisible();
		} );
	} );

	test( 'marks tabs that contain code', async ( { admin, editor } ) => {
		const block = await createCodeBinInEditor( admin, editor );

		for ( const label of [ 'HTML', 'CSS', 'JavaScript' ] ) {
			await expect(
				block.getByRole( 'tab', { name: label, exact: true } )
			).toBeVisible();
		}

		await editPanel( block, 'CSS', 'p { color: red; }' );
		await expect(
			block.getByRole( 'tab', { name: 'CSS (contains code)' } )
		).toBeVisible();
		await expect(
			block.getByRole( 'tab', { name: 'HTML', exact: true } ),
			'Tabs without code should not be marked.'
		).toBeVisible();

		await editPanel( block, 'CSS', '' );
		await expect(
			block.getByRole( 'tab', { name: 'CSS', exact: true } )
		).toBeVisible();
	} );

	test( 'sets the preview height', async ( { admin, editor, page } ) => {
		const block = await createCodeBinInEditor( admin, editor );
		await editPanel( block, 'JavaScript', 'console.log( "Hello" );' );

		const preview = block.locator( 'iframe.code-bin__preview' );
		await expect( preview, 'The default height.' ).toHaveCSS(
			'height',
			'300px'
		);

		await editor.selectBlocks( block );
		await editor.openDocumentSettingsSidebar();
		await page
			.getByRole( 'region', { name: 'Editor settings' } )
			.getByRole( 'tab', { name: 'Block' } )
			.click();
		await page
			.getByRole( 'spinbutton', { name: 'Preview height' } )
			.fill( '480' );

		await expect( preview ).toHaveCSS( 'height', '480px' );
		await block.getByRole( 'tab', { name: /^Console/ } ).click();
		await expect( getConsole( block ) ).toHaveCSS( 'height', '480px' );

		const postId = await editor.publishPost();
		await page.goto( `/?post_type=code_bin&p=${ postId }` );

		const siteBlock = getBlock( page );
		await expect(
			siteBlock.locator( 'iframe.code-bin__preview' )
		).toHaveCSS( 'height', '480px' );
		await siteBlock.getByRole( 'tab', { name: /^Console/ } ).click();
		await expect( getConsole( siteBlock ) ).toHaveCSS( 'height', '480px' );
	} );

	test.describe( 'generated anchor', () => {
		test( 'is kept when saved and used on the site and in feeds', async ( {
			admin,
			editor,
			page,
			request,
			requestUtils,
		} ) => {
			const block = await createCodeBinInEditor( admin, editor );
			const [ anchor ] = await getAnchors( editor );

			await editPanel( block, 'HTML', '<p>Anchored</p>' );
			const postId = await editor.publishPost();

			await page.reload();
			await expect( getBlock( editor.canvas ) ).toBeVisible();
			expect(
				await getAnchors( editor ),
				'The anchor should be kept after a reload.'
			).toEqual( [ anchor ] );

			const { link } = await requestUtils.rest( {
				path: `/wp/v2/code-bins/${ postId }`,
			} );
			await page.goto( link );
			await expect(
				getBlock( page ),
				'The site should use the anchor as the block ID.'
			).toHaveAttribute( 'id', anchor );

			const feed = await request.get( '/?post_type=code_bin&feed=rss2' );
			expect( feed.ok() ).toBe( true );
			const hrefs = [
				...( await feed.text() ).matchAll(
					/href="([^"]*#code-bin-[^"]*)"/g
				),
			].map( ( match ) => match[ 1 ].replace( /&#0?38;|&amp;/g, '&' ) );
			expect(
				hrefs,
				'The feed should link to the block on the site.'
			).toContain( `${ link }#${ anchor }` );
		} );

		test( 'is replaced in a copy of the block', async ( {
			admin,
			editor,
			pageUtils,
		} ) => {
			await admin.createNewPost();
			await editor.insertBlock( { name: BLOCK_NAME } );
			const [ anchor ] = await getAnchors( editor );

			await pageUtils.pressKeys( 'primaryShift+d' );
			const anchors = await getAnchors( editor, 2 );

			expect( anchors ).toContain( anchor );
			expect(
				new Set( anchors ).size,
				'The copy should get its own anchor.'
			).toBe( 2 );
		} );
	} );

	test.describe( 'synced patterns', () => {
		/**
		 * Create a synced pattern.
		 *
		 * @param {Object} requestUtils Request utils.
		 * @return {Promise<number>} Pattern ID.
		 */
		async function createPattern( requestUtils ) {
			const pattern = await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/blocks',
				data: {
					title: 'E2E pattern',
					status: 'publish',
					content:
						'<!-- wp:paragraph --><p>Pattern</p><!-- /wp:paragraph -->',
				},
			} );
			return pattern.id;
		}

		/**
		 * Check the inserter doesn't offer the Code Bin block.
		 *
		 * Waits for the core Code block to be found first: the results are
		 * debounced, so checking straight away would find nothing either way.
		 *
		 * @param {Object} page Page.
		 */
		async function expectNotInInserter( page ) {
			await page
				.getByRole( 'button', { name: 'Block Inserter', exact: true } )
				.click();
			await page
				.getByRole( 'region', { name: 'Block Library' } )
				.getByRole( 'searchbox', { name: 'Search' } )
				.fill( 'Code' );
			const results = page.getByRole( 'listbox', { name: 'Blocks' } );
			await expect(
				results.getByRole( 'option', { name: 'Code', exact: true } )
			).toBeVisible();
			await expect(
				results.getByRole( 'option', { name: 'Code Bin', exact: true } )
			).toHaveCount( 0 );
		}

		/**
		 * Whether the Code Bin block can be inserted at the top level.
		 *
		 * @param {Object} page Page.
		 * @return {Promise<boolean>} Whether the block can be inserted.
		 */
		function canInsertCodeBin( page ) {
			return page.evaluate(
				( name ) =>
					window.wp.data
						.select( 'core/block-editor' )
						.canInsertBlockType( name ),
				BLOCK_NAME
			);
		}

		test.afterAll( async ( { requestUtils } ) => {
			await requestUtils.deleteAllBlocks();
		} );

		test( 'cannot create a pattern from the block', async ( {
			admin,
			editor,
			page,
		} ) => {
			await admin.createNewPost();
			await editor.insertBlock( {
				name: 'core/paragraph',
				attributes: { content: 'Paragraph' },
			} );
			await editor.insertBlock( { name: BLOCK_NAME } );
			const toolbar = page.getByRole( 'toolbar', {
				name: 'Block tools',
			} );
			const menu = page.getByRole( 'menu', { name: 'Options' } );

			// Other blocks can be made into patterns.
			await editor.selectBlocks(
				editor.canvas.getByRole( 'document', {
					name: 'Block: Paragraph',
				} )
			);
			await toolbar.getByRole( 'button', { name: 'Options' } ).click();
			await expect(
				menu.getByRole( 'menuitem', { name: 'Create pattern' } )
			).toBeVisible();
			await page.keyboard.press( 'Escape' );

			await editor.selectBlocks( getBlock( editor.canvas ) );
			await toolbar.getByRole( 'button', { name: 'Options' } ).click();
			await expect( menu ).toBeVisible();
			await expect(
				menu.getByRole( 'menuitem', { name: 'Create pattern' } )
			).toHaveCount( 0 );
		} );

		test( 'cannot be inserted in a pattern in the site editor', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const patternId = await createPattern( requestUtils );
			await admin.visitSiteEditor( {
				postId: patternId,
				postType: 'wp_block',
				canvas: 'edit',
			} );

			await expectNotInInserter( page );
			expect( await canInsertCodeBin( page ) ).toBe( false );
		} );

		test( 'cannot be inserted in a pattern in the post editor', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const patternId = await createPattern( requestUtils );
			await admin.visitAdminPage(
				'post.php',
				`post=${ patternId }&action=edit`
			);

			await expectNotInInserter( page );
			expect( await canInsertCodeBin( page ) ).toBe( false );
		} );

		test( 'cannot be inserted in a synced pattern in a post', async ( {
			admin,
			editor,
			page,
			requestUtils,
		} ) => {
			const patternId = await createPattern( requestUtils );
			await admin.createNewPost();
			await editor.insertBlock( {
				name: 'core/block',
				attributes: { ref: patternId },
			} );
			await expect(
				editor.canvas.getByText( 'Pattern', { exact: true } )
			).toBeVisible();

			// Core also locks a synced pattern's blocks when it's in a post.
			const canInsert = await page.evaluate( ( name ) => {
				const store = window.wp.data.select( 'core/block-editor' );
				const [ pattern ] = store.getBlocksByName( 'core/block' );
				const [ inner ] = store.getBlockOrder( pattern );
				return {
					post: store.canInsertBlockType( name ),
					pattern: store.canInsertBlockType( name, pattern ),
					nested: store.canInsertBlockType(
						name,
						store.getBlockParents( inner ).at( -1 ) ?? pattern
					),
				};
			}, BLOCK_NAME );
			expect( canInsert ).toEqual( {
				post: true,
				pattern: false,
				nested: false,
			} );
		} );
	} );

	test.describe( 'preview', () => {
		test( 'previews the code as it is edited', async ( {
			admin,
			editor,
		} ) => {
			const block = await createCodeBinInEditor( admin, editor );

			await editPanel( block, 'HTML', '<p id="output">Not run</p>' );
			await editPanel(
				block,
				'CSS',
				'#output { color: rgb(255, 0, 0); }'
			);
			await editPanel(
				block,
				'JavaScript',
				`document.getElementById( 'output' ).textContent = 'Run';
console.log( 'Hello from the editor' );`
			);

			const output = getPreview( block ).locator( '#output' );
			await expect( output ).toHaveText( 'Run' );
			await expect( output ).toHaveCSS( 'color', 'rgb(255, 0, 0)' );

			await block.getByRole( 'tab', { name: /^Console/ } ).click();
			await expect( getConsole( block ) ).toHaveText(
				'Hello from the editor'
			);
		} );

		test( 'runs the code again with the Run button', async ( {
			admin,
			editor,
		} ) => {
			const block = await createCodeBinInEditor( admin, editor );
			await editPanel(
				block,
				'JavaScript',
				'console.log( "Random " + Math.random() );'
			);

			await block.getByRole( 'tab', { name: /^Console/ } ).click();
			const consoleView = getConsole( block );
			await expect( consoleView ).toHaveText( /^Random [\d.]+$/ );
			const first = await consoleView.textContent();

			await block
				.getByRole( 'button', { name: 'Run', exact: true } )
				.click();
			await expect(
				consoleView,
				'The console should be cleared and the code run again.'
			).toHaveText( /^Random [\d.]+$/ );
			await expect( consoleView ).not.toHaveText( first );
		} );

		test( 'pauses after an error until the Run button is clicked', async ( {
			admin,
			editor,
		} ) => {
			const block = await createCodeBinInEditor( admin, editor );
			const status = block.locator( '.code-bin__status' );
			const output = getPreview( block ).locator( '#output' );

			await editPanel( block, 'HTML', '<p id="output">Not run</p>' );
			await editPanel(
				block,
				'JavaScript',
				'throw new Error( "Oops" );'
			);
			await expect( status ).toContainText( 'Oops' );
			await expect( status ).toContainText( 'Live preview is paused.' );

			await editPanel(
				block,
				'JavaScript',
				`document.getElementById( 'output' ).textContent = 'Fixed';`
			);
			// Give the debounced update time to run, if it isn't paused.
			await block.page().waitForTimeout( 1000 );
			await expect( status ).toContainText( 'Oops' );
			await expect( output ).toHaveText( 'Not run' );

			await block
				.getByRole( 'button', { name: 'Run', exact: true } )
				.click();
			await expect( output ).toHaveText( 'Fixed' );
			await expect( status ).toBeHidden();
		} );
	} );
} );
