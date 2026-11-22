/**
 * Code in a code bin must not be able to escape the preview's sandboxed iframe.
 *
 * Each attack runs in four scenarios:
 *
 * - Code saved by an author, viewed by a logged in admin: the code bin's
 *   author can run code in every visitor's browser, so this is the scenario
 *   that matters.
 * - Code saved by an author, opened in the editor by an admin: another user's
 *   code runs in the editor's preview, with the admin's editor session around it.
 * - Code typed in the editor by an admin, who has the `unfiltered_html`
 *   capability: the code runs in the editor's preview as it's typed.
 * - Code typed by a logged out visitor editing the code on the site.
 *
 * Authors don't have the `unfiltered_html` capability, but their code is
 * stored in post meta, which kses doesn't filter. The saved scenarios check
 * the attack was saved exactly as written, so kses can't have defused it.
 *
 * Each attack logs a marker when it's done, so the tests know it ran rather
 * than failing to start, then the tests check the page is untouched.
 */

/**
 * WordPress dependencies
 */
const {
	test,
	expect,
	RequestUtils,
} = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	META_KEY,
	createCodeBin,
	getBlock,
	getConsole,
} = require( './utils/code-bin' );

// Unique to the run, used in URLs and content the attacks try to create.
const TOKEN = `code-bin-pwned-${ Date.now() }`;

/**
 * Logs whether an attempt to do something the sandbox should block threw.
 *
 * Access across the sandbox throws a SecurityError. Blocked navigations,
 * dialogs and windows fail silently, so the tests check the page for those.
 *
 * Placed before the attack's own JavaScript.
 */
const ATTEMPT = `
function attempt( name, fn ) {
	try {
		fn();
		console.log( name + ': no error' );
	} catch ( error ) {
		console.log( name + ': blocked ' + error.name );
	}
}
`;

/**
 * Write to the parent page, used by attacks to show they got out.
 *
 * Each sets a canary the tests check for on the parent page.
 */
const WRITE_TO_PARENT = `
attempt( 'parent-dom', () => { parent.document.documentElement.dataset.codeBinPwned = '1'; } );
attempt( 'top-dom', () => { top.document.documentElement.dataset.codeBinPwned = '1'; } );
attempt( 'top-title', () => { top.document.title = '${ TOKEN }'; } );
attempt( 'top-global', () => { top.codeBinPwned = 1; } );
`;

// Closing tags in a JavaScript string, with a marker logged if they escape.
const CLOSING_TAGS =
	'</script><script>console.log( "js-escaped" );</script><!--';

const ATTACKS = [
	{
		name: 'cannot access the page from JavaScript',
		code: {
			javascript: `${ ATTEMPT }${ WRITE_TO_PARENT }
attempt( 'parent-local-storage', () => parent.localStorage.getItem( 'x' ) );
attempt( 'opener', () => { window.opener.document.title = '${ TOKEN }'; } );
console.log( 'access-done' );`,
		},
		done: 'access-done',
		expectConsole: [
			'parent-dom: blocked SecurityError',
			'top-dom: blocked SecurityError',
			'top-title: blocked SecurityError',
			'top-global: blocked SecurityError',
			'parent-local-storage: blocked SecurityError',
		],
	},
	{
		name: 'cannot escape from the HTML',
		code: {
			html: `</iframe></div><p class="code-bin-injected">Injected</p>
"><p class="code-bin-injected">Injected</p>
<script>${ ATTEMPT }
attempt( 'script-dom', () => { top.document.documentElement.dataset.codeBinPwned = '1'; } );
</script>
<img src="data:," onerror="try { top.document.documentElement.dataset.codeBinPwned = '1'; console.log( 'img-dom: no error' ); } catch ( error ) { console.log( 'img-dom: blocked ' + error.name ); }">
<svg onload="try { top.codeBinPwned = 1; console.log( 'svg-global: no error' ); } catch ( error ) { console.log( 'svg-global: blocked ' + error.name ); }"></svg>
<script>console.log( 'html-done' );</script>`,
		},
		done: 'html-done',
		expectConsole: [
			'script-dom: blocked SecurityError',
			'img-dom: blocked SecurityError',
			'svg-global: blocked SecurityError',
		],
	},
	{
		name: 'cannot navigate the page',
		code: {
			html: `<a id="top-link" href="/?${ TOKEN }=link" target="_top">Link</a>
<a id="parent-link" href="/?${ TOKEN }=parent-link" target="_parent">Link</a>
<form id="top-form" action="/" method="get" target="_top"><input name="${ TOKEN }" value="form"></form>`,
			javascript: `${ ATTEMPT }
attempt( 'top-location', () => { top.location = '/?${ TOKEN }=location'; } );
attempt( 'top-location-href', () => { top.location.href = '/?${ TOKEN }=href'; } );
attempt( 'parent-location-replace', () => { parent.location.replace( '/?${ TOKEN }=replace' ); } );
attempt( 'top-link', () => document.getElementById( 'top-link' ).click() );
attempt( 'parent-link', () => document.getElementById( 'parent-link' ).click() );
attempt( 'top-form', () => document.getElementById( 'top-form' ).submit() );
console.log( 'navigation-done' );`,
		},
		done: 'navigation-done',
		expectConsole: [
			'top-location: blocked SecurityError',
			'top-location-href: blocked SecurityError',
			'parent-location-replace: blocked SecurityError',
		],
		// Navigations are asynchronous, give any that got through time to start.
		settle: 1500,
	},
	{
		name: 'cannot open dialogs or windows',
		code: {
			javascript: `${ ATTEMPT }
attempt( 'alert', () => alert( 'pwned' ) );
attempt( 'confirm', () => confirm( 'pwned' ) );
attempt( 'prompt', () => prompt( 'pwned' ) );
attempt( 'print', () => print() );
console.log( 'open: ' + ( window.open( '/?${ TOKEN }=open' ) ? 'opened' : 'blocked' ) );
console.log( 'dialogs-done' );`,
		},
		done: 'dialogs-done',
		expectConsole: [ 'open: blocked' ],
		settle: 1000,
	},
	{
		name: 'cannot read cookies or storage',
		code: {
			javascript: `${ ATTEMPT }
attempt( 'cookie', () => document.cookie );
attempt( 'local-storage', () => window.localStorage );
attempt( 'session-storage', () => window.sessionStorage );
console.log( 'storage-done' );`,
		},
		done: 'storage-done',
		expectConsole: [
			'cookie: blocked SecurityError',
			'local-storage: blocked SecurityError',
			'session-storage: blocked SecurityError',
		],
	},
	{
		name: 'closing tags stay in the CSS and JavaScript',
		code: {
			css: `p { color: red; }</style><script>console.log( 'css-escaped' );</script><!--`,
			// Logs the length: logging the text would include the marker.
			javascript: `const text = '${ CLOSING_TAGS }';
console.log( 'js-string-length: ' + text.length );
console.log( 'tags-done' );`,
		},
		done: 'tags-done',
		expectConsole: [ `js-string-length: ${ CLOSING_TAGS.length }` ],
		neverConsole: [ 'css-escaped', 'js-escaped' ],
	},
	{
		name: 'messages sent to the page are shown as text',
		code: {
			javascript: `const channel = document.scripts[ 0 ].textContent.match( /"channel":"([^"]+)"/ )[ 1 ];
const markup = '<img src="data:," onerror="top.codeBinPwned = 1" class="code-bin-injected">';
parent.postMessage( { codeBinPreview: 'wrong-channel', type: 'console', entries: [ { level: 'log', text: 'wrong-channel-message' } ] }, '*' );
parent.postMessage( { codeBinPreview: channel, type: 'console', entries: [ { level: 'log" onmouseover="top.codeBinPwned = 1', text: 'fake: ' + markup } ] }, '*' );
console.log( 'messages-done' );
setTimeout( () => {
	parent.postMessage( { codeBinPreview: channel, type: 'error', message: markup, panel: '<b>javascript</b>', line: '<i>1</i>' }, '*' );
}, 100 );`,
		},
		done: 'messages-done',
		expectConsole: [
			'fake: <img src="data:," onerror="top.codeBinPwned = 1" class="code-bin-injected">',
		],
		neverConsole: [ 'wrong-channel-message' ],
		expectStatus:
			'<img src="data:," onerror="top.codeBinPwned = 1" class="code-bin-injected">',
	},
];

/**
 * Code a visitor replaces when typing an attack.
 */
const PLACEHOLDER = {
	html: '<p>Placeholder</p>',
	css: 'p { color: blue; }',
	javascript: 'console.log( "placeholder" );',
};

const PANEL_LABELS = { html: 'HTML', css: 'CSS', javascript: 'JavaScript' };

// Name of the editor canvas iframe.
const CANVAS_FRAME = 'editor-canvas';

// The author saving the attacks, without the `unfiltered_html` capability.
const AUTHOR = {
	username: 'code-bin-author',
	email: 'code-bin-author@example.com',
	first_name: 'Code',
	last_name: 'Author',
	password: 'password',
	roles: [ 'author' ],
};

// Request utils logged in as the author, set up before the tests.
const author = { id: 0, requestUtils: null };

/**
 * Save an attack as the author and check it was saved exactly as written.
 *
 * @param {Object} requestUtils Request utils logged in as the admin.
 * @param {Object} code         Attack code.
 * @return {Promise<{id: number, link: string}>} The code bin's ID and URL.
 */
async function saveAsAuthor( requestUtils, code ) {
	const codeBin = await createCodeBin( author.requestUtils, code );

	const saved = await requestUtils.rest( {
		path: `/wp/v2/code-bins/${ codeBin.id }`,
		params: { context: 'edit' },
	} );
	expect( saved.author, 'The author should save the attack.' ).toBe(
		author.id
	);
	expect(
		saved.meta[ META_KEY ][ codeBin.codeId ],
		'The attack should be saved exactly as written.'
	).toEqual( {
		html: code.html ?? '',
		css: code.css ?? '',
		javascript: code.javascript ?? '',
	} );

	return codeBin;
}

const SCENARIOS = [
	{
		name: 'code saved by an author viewed by a logged in admin',
		/**
		 * Save the attack as the author and view it as the admin.
		 *
		 * @param {Object} args              Arguments.
		 * @param {Object} args.page         Page logged in as the admin.
		 * @param {Object} args.requestUtils Request utils.
		 * @param {Object} args.code         Attack code.
		 * @return {Promise<Attack>} The attack.
		 */
		async run( { page, requestUtils, code } ) {
			const { link } = await saveAsAuthor( requestUtils, code );
			await watchPage( page );
			await page.goto( link );

			// The attacks need the admin's session to be worth anything.
			await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
			return {
				page,
				block: getBlock( page ),
				frames: [ page.mainFrame() ],
			};
		},
	},
	{
		name: 'code saved by an author opened in the editor by an admin',
		/**
		 * Save the attack as the author and edit it as the admin.
		 *
		 * @param {Object} args              Arguments.
		 * @param {Object} args.page         Page logged in as the admin.
		 * @param {Object} args.admin        Admin utils.
		 * @param {Object} args.editor       Editor utils.
		 * @param {Object} args.requestUtils Request utils.
		 * @param {Object} args.code         Attack code.
		 * @return {Promise<Attack>} The attack.
		 */
		async run( { page, admin, editor, requestUtils, code } ) {
			const { id } = await saveAsAuthor( requestUtils, code );
			await watchPage( page );
			await admin.editPost( id );

			const block = getBlock( editor.canvas );
			await expect( block ).toBeVisible();

			// The preview's parent is the editor canvas, its top the editor.
			const canvas = page.frame( { name: CANVAS_FRAME } );
			expect(
				canvas,
				'The editor canvas should be an iframe.'
			).toBeTruthy();
			return { page, block, frames: [ page.mainFrame(), canvas ] };
		},
	},
	{
		name: 'code typed in the editor by an admin',
		/**
		 * Type the attack into a new code bin in the editor and save it.
		 *
		 * @param {Object} args              Arguments.
		 * @param {Object} args.page         Page logged in as the admin.
		 * @param {Object} args.admin        Admin utils.
		 * @param {Object} args.editor       Editor utils.
		 * @param {Object} args.requestUtils Request utils.
		 * @param {Object} args.code         Attack code.
		 * @return {Promise<Attack>} The attack.
		 */
		async run( { page, admin, editor, requestUtils, code } ) {
			await watchPage( page );
			await admin.createNewPost( {
				postType: 'code_bin',
				title: 'Admin attack',
			} );
			const block = getBlock( editor.canvas );

			for ( const [ panel, label ] of Object.entries( PANEL_LABELS ) ) {
				// The tab's name changes once the panel contains code.
				await block
					.getByRole( 'tab', {
						name: new RegExp( `^${ label }( |$)` ),
					} )
					.click();
				await block
					.getByRole( 'textbox', { name: label, exact: true } )
					.fill( code[ panel ] ?? '' );
			}

			const postId = await editor.publishPost();
			const saved = await requestUtils.rest( {
				path: `/wp/v2/code-bins/${ postId }`,
				params: { context: 'edit' },
			} );
			expect(
				Object.values( saved.meta[ META_KEY ] ),
				'The attack should be saved exactly as written.'
			).toEqual( [
				{
					html: code.html ?? '',
					css: code.css ?? '',
					javascript: code.javascript ?? '',
				},
			] );

			// Run the saved code from the start.
			await block
				.getByRole( 'button', { name: 'Run', exact: true } )
				.click();

			const canvas = page.frame( { name: CANVAS_FRAME } );
			expect(
				canvas,
				'The editor canvas should be an iframe.'
			).toBeTruthy();
			return { page, block, frames: [ page.mainFrame(), canvas ] };
		},
	},
	{
		name: 'code typed by a logged out visitor',
		/**
		 * Type the attack into a code bin as a logged out visitor.
		 *
		 * @param {Object} args              Arguments.
		 * @param {Object} args.browser      Browser.
		 * @param {Object} args.requestUtils Request utils.
		 * @param {Object} args.code         Attack code.
		 * @return {Promise<Attack>} The attack.
		 */
		async run( { browser, requestUtils, code } ) {
			const { link } = await createCodeBin( requestUtils, {
				...PLACEHOLDER,
				editable: true,
			} );
			const context = await browser.newContext( {
				storageState: { cookies: [], origins: [] },
			} );
			const page = await context.newPage();
			await watchPage( page );
			await page.goto( link );

			const block = getBlock( page );
			await expect( getConsole( block ) ).toContainText( 'placeholder' );

			for ( const [ panel, label ] of Object.entries( PANEL_LABELS ) ) {
				await block
					.getByRole( 'tab', { name: label, exact: true } )
					.click();
				await block
					.getByRole( 'textbox', { name: label, exact: true } )
					.fill( code[ panel ] ?? '' );
			}
			await block
				.getByRole( 'button', { name: 'Run', exact: true } )
				.click();

			return { page, block, frames: [ page.mainFrame() ] };
		},
	},
];

/**
 * An attack running in a scenario.
 *
 * @typedef {Object} Attack
 * @property {Object}   page   Page showing the attack.
 * @property {Object}   block  Block locator.
 * @property {Object[]} frames Frames the code must not escape to: the page and,
 *                             in the editor, the editor canvas.
 */

/**
 * Record anything the attacks might trigger on the page.
 *
 * Navigations are recorded for the page and the editor canvas: the preview's
 * own document is replaced each time the code runs.
 *
 * @param {Object} page Page.
 */
async function watchPage( page ) {
	page.codeBinEvents = { navigations: [], dialogs: [], popups: [] };
	page.on( 'framenavigated', ( frame ) => {
		if ( frame === page.mainFrame() || frame.name() === CANVAS_FRAME ) {
			page.codeBinEvents.navigations.push( frame.url() );
		}
	} );
	page.on( 'dialog', ( dialog ) => {
		page.codeBinEvents.dialogs.push( dialog.type() );
		dialog.dismiss().catch( () => {} );
	} );
	page.context().on( 'page', ( popup ) => {
		page.codeBinEvents.popups.push( popup.url() );
	} );
}

/**
 * Get what an attack might change in a frame.
 *
 * @param {Object} frame Frame.
 * @return {Promise<Object>} The frame's URL, title and any canaries.
 */
function inspectFrame( frame ) {
	return frame.evaluate( () => ( {
		url: window.location.href,
		title: document.title,
		global: window.codeBinPwned ?? null,
		attribute: document.documentElement.dataset.codeBinPwned ?? null,
		injected: document.querySelectorAll( '.code-bin-injected' ).length,
	} ) );
}

test.describe( 'Code Bin preview security', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		author.id = ( await requestUtils.createUser( AUTHOR ) ).id;
		author.requestUtils = await RequestUtils.setup( {
			user: { username: AUTHOR.username, password: AUTHOR.password },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await author.requestUtils?.request.dispose();
		await requestUtils.deleteAllPosts( 'code-bins' );
		await requestUtils.deleteAllUsers();
	} );

	for ( const scenario of SCENARIOS ) {
		test.describe( scenario.name, () => {
			for ( const attack of ATTACKS ) {
				test(
					attack.name,
					async ( {
						page,
						browser,
						admin,
						editor,
						requestUtils,
					} ) => {
						const {
							page: attackPage,
							block,
							frames,
						} = await scenario.run( {
							page,
							browser,
							admin,
							editor,
							requestUtils,
							code: attack.code,
						} );
						const consoleView = getConsole( block );

						// Navigations after this point are the attack's.
						const before = await Promise.all(
							frames.map( inspectFrame )
						);
						attackPage.codeBinEvents.navigations.length = 0;

						await expect(
							consoleView,
							'The attack should run.'
						).toContainText( attack.done );

						for ( const text of attack.expectConsole ) {
							await expect( consoleView ).toContainText( text );
						}

						if ( attack.expectStatus ) {
							await expect(
								block.locator( '.code-bin__status' )
							).toContainText( attack.expectStatus );
						}

						if ( attack.settle ) {
							// Wait for anything the attack set off.
							await attackPage.waitForTimeout( attack.settle );
						}

						for ( const text of attack.neverConsole ?? [] ) {
							await expect( consoleView ).not.toContainText(
								text
							);
						}

						/*
						 * The editor replaces its URL with the same URL once it
						 * loads, which counts as a navigation. The attacks
						 * navigate to URLs with the token in them.
						 */
						const startUrls = before.map( ( frame ) => frame.url );
						const { navigations, ...events } =
							attackPage.codeBinEvents;
						expect(
							{
								...events,
								navigations: navigations.filter(
									( url ) => ! startUrls.includes( url )
								),
							},
							'The page should not navigate, open dialogs or open windows.'
						).toEqual( {
							navigations: [],
							dialogs: [],
							popups: [],
						} );

						const after = await Promise.all(
							frames.map( inspectFrame )
						);
						expect(
							after,
							'The page should be untouched.'
						).toEqual(
							before.map( ( frame ) => ( {
								...frame,
								global: null,
								attribute: null,
								injected: 0,
							} ) )
						);
					}
				);
			}

			test( 'cannot use the logged in session', async ( {
				page,
				browser,
				admin,
				editor,
				requestUtils,
			} ) => {
				const { block } = await scenario.run( {
					page,
					browser,
					admin,
					editor,
					requestUtils,
					code: {
						javascript: `( async () => {
	for ( const [ name, url, options ] of [
		[ 'users-me', '/wp-json/wp/v2/users/me?context=edit', {} ],
		[ 'create-post', '/wp-json/wp/v2/posts', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { title: '${ TOKEN }', status: 'publish' } ),
		} ],
	] ) {
		try {
			const response = await fetch( url, { credentials: 'include', ...options } );
			console.log( name + ': ' + response.status );
		} catch ( error ) {
			console.log( name + ': failed ' + error.name );
		}
	}
	console.log( 'session-done' );
} )();`,
					},
				} );
				const consoleView = getConsole( block );

				await expect(
					consoleView,
					'The attack should run.'
				).toContainText( 'session-done' );

				// Unauthenticated (401) or blocked by the browser, never authenticated.
				await expect( consoleView ).toContainText(
					/users-me: (401|failed TypeError)/
				);
				await expect( consoleView ).toContainText(
					/create-post: (401|failed TypeError)/
				);

				const posts = await requestUtils.rest( {
					path: '/wp/v2/posts',
					params: { search: TOKEN, status: 'any' },
				} );
				expect( posts, 'No post should be created.' ).toEqual( [] );
			} );
		} );
	}
} );
