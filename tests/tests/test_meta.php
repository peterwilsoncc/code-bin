<?php
/**
 * Test storing Code Bin code in post meta.
 *
 * @package CodeBin
 */

namespace CodeBin\Tests;

use WP_REST_Request;
use WP_UnitTestCase;

use function CodeBin\get_block_code;
use function CodeBin\post_type_supports_code_meta;
use function CodeBin\prune_code_meta;
use function CodeBin\sanitize_code_meta;

use const CodeBin\BLOCK_NAME;
use const CodeBin\META_KEY;
use const CodeBin\POST_TYPE;

/**
 * Test storing Code Bin code in post meta.
 */
class Test_Meta extends WP_UnitTestCase {

	/**
	 * Code that kses changes when saved by a user without `unfiltered_html`.
	 *
	 * The JavaScript includes backslashes, which are lost if the code isn't
	 * slashed when saved.
	 */
	const KSES_CODE = array(
		'html'       => '<p>Tom & Jerry</p><button onclick="go()">Go</button><iframe src="https://example.org"></iframe>',
		'css'        => 'a > b { content: "&"; }',
		'javascript' => 'if ( a < b && c > d ) { console.log( "<strong class=\"x\">Hi</strong>" ); }',
	);

	/**
	 * Author user ID.
	 *
	 * @var int
	 */
	private static $author;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private static $editor;

	/**
	 * Create the users.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$author = $factory->user->create( array( 'role' => 'author' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Start each test without any anchors reserved.
	 */
	public function set_up() {
		parent::set_up();
		\CodeBin\reset_reserved_block_anchors();

		// The test case unregisters meta keys after each test.
		\CodeBin\register_code_meta();

		// A new REST server for each test, so the plugin's REST API hooks are added.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core global.
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
	}

	/**
	 * Remove the REST server.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core global.
		parent::tear_down();
	}

	/**
	 * Serialize a Code Bin block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block markup.
	 */
	private function block( $attributes ) {
		return serialize_block(
			array(
				'blockName'    => BLOCK_NAME,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Create a post containing Code Bin blocks with their code in meta.
	 *
	 * @param array  $code      Code, keyed by code ID.
	 * @param string $post_type Post type.
	 * @param array  $post_arr  Additional post fields.
	 * @return int Post ID.
	 */
	private function create_post( $code, $post_type = POST_TYPE, $post_arr = array() ) {
		$content = '';
		foreach ( array_keys( $code ) as $code_id ) {
			$content .= $this->block( array( 'codeId' => (string) $code_id ) );
		}

		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => $post_type,
					'post_content' => wp_slash( $content ),
				),
				$post_arr
			)
		);
		update_post_meta( $post_id, META_KEY, wp_slash( $code ) );

		return $post_id;
	}

	/**
	 * Test the meta is registered for all post types and shown in the REST API.
	 */
	public function test_meta_is_registered() {
		$registered = get_registered_meta_keys( 'post', '' );

		$this->assertArrayHasKey( META_KEY, $registered );
		$this->assertSame( 'object', $registered[ META_KEY ]['type'] );
		$this->assertTrue( $registered[ META_KEY ]['single'] );
		$this->assertTrue( $registered[ META_KEY ]['revisions_enabled'], 'Revisions should include the code.' );
		$this->assertNotEmpty( $registered[ META_KEY ]['show_in_rest'], 'The code should be in the REST API.' );
		$this->assertTrue( is_protected_meta( META_KEY, 'post' ), 'The meta should not be shown in the Custom Fields panel.' );
	}

	/**
	 * Test which post types store code in meta.
	 *
	 * @dataProvider data_post_type_supports_code_meta
	 *
	 * @param string $post_type Post type.
	 * @param bool   $expected  Whether the post type should store code in meta.
	 */
	public function test_post_type_supports_code_meta( $post_type, $expected ) {
		$this->assertSame( $expected, post_type_supports_code_meta( $post_type ) );
	}

	/**
	 * Data provider for test_post_type_supports_code_meta.
	 *
	 * @return array[] Test data.
	 */
	public function data_post_type_supports_code_meta() {
		return array(
			'code bins'         => array( POST_TYPE, true ),
			'posts'             => array( 'post', true ),
			'pages'             => array( 'page', true ),
			'synced patterns'   => array( 'wp_block', false ),
			'templates'         => array( 'wp_template', false ),
			'attachments'       => array( 'attachment', false ),
			'unknown post type' => array( 'code_bin_unknown', false ),
		);
	}

	/**
	 * Test sanitizing the meta keeps code exactly and drops invalid data.
	 */
	public function test_sanitize_code_meta() {
		$this->assertSame(
			array(
				'abc123' => self::KSES_CODE,
				'def456' => array(
					'html'       => '<p>Hi</p>',
					'css'        => '',
					'javascript' => '42',
				),
			),
			sanitize_code_meta(
				array(
					'abc123'      => self::KSES_CODE,
					'def456'      => array(
						'html'       => '<p>Hi</p>',
						'javascript' => 42,
						'php'        => '<?php',
					),
					'Invalid ID!' => self::KSES_CODE,
					'ghi789'      => 'not an array',
				)
			)
		);
	}

	/**
	 * Test sanitizing invalid meta.
	 */
	public function test_sanitize_code_meta_invalid() {
		$this->assertSame( array(), sanitize_code_meta( 'code' ) );
		$this->assertSame( array(), sanitize_code_meta( null ) );
	}

	/**
	 * Test the block's code is read from meta.
	 */
	public function test_get_block_code_from_meta() {
		$post_id = $this->create_post( array( 'abc123' => self::KSES_CODE ) );

		$this->assertSame(
			self::KSES_CODE,
			get_block_code(
				array(
					'codeId' => 'abc123',
					'html'   => '<p>Old attribute code</p>',
				),
				$post_id
			),
			'Meta should take precedence over attributes.'
		);
	}

	/**
	 * Test the block's code falls back to its attributes.
	 *
	 * @dataProvider data_get_block_code_falls_back_to_attributes
	 *
	 * @param string $code_id   Code ID attribute.
	 * @param string $post_type Post type.
	 * @param bool   $with_post Whether to pass the post ID.
	 */
	public function test_get_block_code_falls_back_to_attributes( $code_id, $post_type, $with_post ) {
		$post_id = $this->create_post( array( 'abc123' => self::KSES_CODE ), $post_type );

		$this->assertSame(
			array(
				'html'       => '<p>Attribute code</p>',
				'css'        => '',
				'javascript' => '',
			),
			get_block_code(
				array(
					'codeId' => $code_id,
					'html'   => '<p>Attribute code</p>',
				),
				$with_post ? $post_id : null
			)
		);
	}

	/**
	 * Data provider for test_get_block_code_falls_back_to_attributes.
	 *
	 * @return array[] Test data.
	 */
	public function data_get_block_code_falls_back_to_attributes() {
		return array(
			'no meta entry'    => array( 'zzz999', POST_TYPE, true ),
			'no code ID'       => array( '', POST_TYPE, true ),
			'no post'          => array( 'abc123', POST_TYPE, false ),
			'unsupported type' => array( 'abc123', 'wp_block', true ),
			'invalid code ID'  => array( '../abc', POST_TYPE, true ),
		);
	}

	/**
	 * Test the block renders the code from the meta of the post it's in.
	 */
	public function test_render_uses_meta() {
		$post_id = $this->create_post( array( 'abc123' => self::KSES_CODE ) );
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$html = do_blocks( get_post_field( 'post_content', $post_id ) );

		$this->assertStringContainsString(
			code_bin_escape_code( self::KSES_CODE['javascript'] ),
			$html,
			'The JavaScript from meta should be output, escaped.'
		);
		$this->assertStringNotContainsString( '&amp;amp;', $html );
	}

	/**
	 * Create an autosave of a post, with code in its meta.
	 *
	 * @param int        $post_id Post ID.
	 * @param array|null $code    Code meta for the autosave, null for none.
	 * @return int Autosave ID.
	 */
	private function create_autosave( $post_id, $code ) {
		$autosave_id = _wp_put_post_revision(
			array(
				'ID'           => $post_id,
				'post_content' => get_post_field( 'post_content', $post_id, 'raw' ),
				'post_title'   => 'Autosave',
			),
			true
		);
		if ( null !== $code ) {
			update_metadata( 'post', $autosave_id, META_KEY, wp_slash( $code ) );
		}

		return $autosave_id;
	}

	/**
	 * Render the blocks of a post as its preview.
	 *
	 * @param int  $post_id    Post ID.
	 * @param bool $nonce_ok   Whether the preview nonce was valid: WordPress only adds its
	 *                         preview meta filter after checking it.
	 * @return string Rendered blocks.
	 */
	private function render_preview( $post_id, $nonce_ok ) {
		$this->go_to( get_permalink( $post_id ) );
		the_post();
		if ( $nonce_ok ) {
			add_filter( 'get_post_metadata', '_wp_preview_meta_filter', 10, 4 );
		}

		$html = do_blocks( get_post_field( 'post_content', $post_id ) );
		remove_filter( 'get_post_metadata', '_wp_preview_meta_filter', 10 );

		return $html;
	}

	/**
	 * Test previews of published posts show the code in the autosave.
	 */
	public function test_preview_uses_autosave_code() {
		$post_id = $this->create_post( array( 'abc123' => array( 'html' => '<p>Published</p>' ) ) );
		wp_set_current_user( self::$editor );
		$this->create_autosave( $post_id, array( 'abc123' => array( 'html' => '<p>Autosaved</p>' ) ) );

		$html = $this->render_preview( $post_id, true );

		$this->assertStringContainsString( '&lt;p&gt;Autosaved&lt;/p&gt;', $html );
		$this->assertStringNotContainsString( 'Published', $html );
	}

	/**
	 * Test the autosave's code isn't shown without a valid preview nonce.
	 */
	public function test_preview_without_nonce_shows_published_code() {
		$post_id = $this->create_post( array( 'abc123' => array( 'html' => '<p>Published</p>' ) ) );
		wp_set_current_user( self::$editor );
		$this->create_autosave( $post_id, array( 'abc123' => array( 'html' => '<p>Autosaved</p>' ) ) );

		$html = $this->render_preview( $post_id, false );

		$this->assertStringContainsString( '&lt;p&gt;Published&lt;/p&gt;', $html );
		$this->assertStringNotContainsString( 'Autosaved', $html );
	}

	/**
	 * Test previews show the post's code when the autosave has none.
	 */
	public function test_preview_autosave_without_code_shows_published_code() {
		$post_id = $this->create_post( array( 'abc123' => array( 'html' => '<p>Published</p>' ) ) );
		wp_set_current_user( self::$editor );
		$this->create_autosave( $post_id, null );

		$this->assertStringContainsString( '&lt;p&gt;Published&lt;/p&gt;', $this->render_preview( $post_id, true ) );
	}

	/**
	 * Test a block can't render another post's code.
	 */
	public function test_render_ignores_other_posts_code() {
		$this->create_post( array( 'abc123' => array( 'html' => '<p>Secret draft</p>' ) ), POST_TYPE, array( 'post_status' => 'draft' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => POST_TYPE,
				'post_content' => wp_slash( $this->block( array( 'codeId' => 'abc123' ) ) ),
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertStringNotContainsString( 'Secret draft', do_blocks( get_post_field( 'post_content', $post_id ) ) );
	}

	/**
	 * Test an author can save code via the REST API without kses changing it.
	 */
	public function test_rest_author_saves_code_unchanged() {
		wp_set_current_user( self::$author );
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'Authors should not have unfiltered_html.' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/code-bins' );
		$request->set_body_params(
			array(
				'title'   => 'Author code bin',
				'status'  => 'publish',
				'content' => $this->block( array( 'codeId' => 'abc123' ) ),
				'meta'    => array( META_KEY => array( 'abc123' => self::KSES_CODE ) ),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status(), 'The code bin should be created.' );
		$this->assertSame(
			array( 'abc123' => self::KSES_CODE ),
			get_post_meta( $response->get_data()['id'], META_KEY, true )
		);
	}

	/**
	 * Test users can't save code to posts they can't edit.
	 */
	public function test_rest_cannot_save_code_to_others_posts() {
		$post_id = $this->create_post( array( 'abc123' => self::KSES_CODE ), POST_TYPE, array( 'post_author' => self::$editor ) );
		wp_set_current_user( self::$author );

		$request = new WP_REST_Request( 'POST', '/wp/v2/code-bins/' . $post_id );
		$request->set_body_params( array( 'meta' => array( META_KEY => array( 'abc123' => array( 'html' => 'changed' ) ) ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array( 'abc123' => self::KSES_CODE ), get_post_meta( $post_id, META_KEY, true ) );
	}

	/**
	 * Test the REST API rejects invalid code IDs.
	 */
	public function test_rest_rejects_invalid_code_id() {
		wp_set_current_user( self::$author );
		$post_id = $this->create_post( array(), POST_TYPE, array( 'post_author' => self::$author ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/code-bins/' . $post_id );
		$request->set_body_params( array( 'meta' => array( META_KEY => array( 'Not Valid' => array( 'html' => 'x' ) ) ) ) );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * Test the code is in REST API responses.
	 */
	public function test_rest_response_includes_code() {
		$post_id = $this->create_post( array( 'abc123' => self::KSES_CODE ) );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/code-bins/' . $post_id ) )->get_data();

		$this->assertSame( array( 'abc123' => self::KSES_CODE ), $data['meta'][ META_KEY ] );
	}

	/**
	 * Test the code of password protected posts is hidden in the REST API.
	 *
	 * @dataProvider data_rest_password_protected_code
	 *
	 * @param string|null $password Password sent with the request.
	 * @param bool        $as_editor Whether the request is made by a user who can edit the post.
	 * @param bool        $expected Whether the code should be included.
	 */
	public function test_rest_password_protected_code( $password, $as_editor, $expected ) {
		$post_id = $this->create_post( array( 'abc123' => self::KSES_CODE ), POST_TYPE, array( 'post_password' => 'secret' ) );
		if ( $as_editor ) {
			wp_set_current_user( self::$editor );
		}

		$request = new WP_REST_Request( 'GET', '/wp/v2/code-bins/' . $post_id );
		if ( null !== $password ) {
			$request->set_param( 'password', $password );
		}
		$data = rest_do_request( $request )->get_data();

		// A wrong password is an error, without any meta.
		$this->assertSame( $expected ? array( 'abc123' => self::KSES_CODE ) : array(), $data['meta'][ META_KEY ] ?? array() );
	}

	/**
	 * Data provider for test_rest_password_protected_code.
	 *
	 * @return array[] Test data.
	 */
	public function data_rest_password_protected_code() {
		return array(
			'no password'      => array( null, false, false ),
			'wrong password'   => array( 'wrong', false, false ),
			'correct password' => array( 'secret', false, true ),
			'editor'           => array( null, true, true ),
		);
	}

	/**
	 * Test the route for copying code.
	 *
	 * @dataProvider data_rest_copy_code
	 *
	 * @param array  $post_arr Post fields.
	 * @param string $user     User: author, editor or none.
	 * @param int    $expected Expected status.
	 */
	public function test_rest_copy_code( $post_arr, $user, $expected ) {
		$post_id = $this->create_post( array( 'abc123' => self::KSES_CODE ), POST_TYPE, array_merge( array( 'post_author' => self::$editor ), $post_arr ) );
		if ( 'none' !== $user ) {
			wp_set_current_user( 'author' === $user ? self::$author : self::$editor );
		}

		$response = rest_do_request( new WP_REST_Request( 'GET', "/code-bin/v1/posts/{$post_id}/code/abc123" ) );

		$this->assertSame( $expected, $response->get_status() );
		if ( 200 === $expected ) {
			$this->assertSame( self::KSES_CODE, $response->get_data() );
		}
	}

	/**
	 * Data provider for test_rest_copy_code.
	 *
	 * @return array[] Test data.
	 */
	public function data_rest_copy_code() {
		return array(
			'published, logged out'      => array( array(), 'none', 200 ),
			'draft, editor'              => array( array( 'post_status' => 'draft' ), 'editor', 200 ),
			'draft, author'              => array( array( 'post_status' => 'draft' ), 'author', 403 ),
			'draft, logged out'          => array( array( 'post_status' => 'draft' ), 'none', 401 ),
			'password protected, author' => array( array( 'post_password' => 'secret' ), 'author', 403 ),
			'password protected, editor' => array( array( 'post_password' => 'secret' ), 'editor', 200 ),
		);
	}

	/**
	 * Test the route for copying code returns 404 for unknown code.
	 */
	public function test_rest_copy_code_not_found() {
		$post_id = $this->create_post( array( 'abc123' => self::KSES_CODE ) );

		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', "/code-bin/v1/posts/{$post_id}/code/zzz999" ) )->get_status() );
	}

	/**
	 * Test code for blocks no longer in the post is removed when it's saved.
	 */
	public function test_prune_code_meta() {
		$post_id = $this->create_post(
			array(
				'abc123' => self::KSES_CODE,
				'def456' => array( 'html' => '<p>Removed block</p>' ),
			)
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( '<!-- wp:group --><div class="wp-block-group">' . $this->block( array( 'codeId' => 'abc123' ) ) . '</div><!-- /wp:group -->' ),
			)
		);

		$this->assertSame( array( 'abc123' => self::KSES_CODE ), get_post_meta( $post_id, META_KEY, true ), 'Code of nested blocks should be kept.' );
	}

	/**
	 * Test the meta is deleted when no blocks remain.
	 */
	public function test_prune_code_meta_deletes_empty_meta() {
		$post_id = $this->create_post( array( 'abc123' => self::KSES_CODE ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( '<!-- wp:paragraph --><p>No code bins</p><!-- /wp:paragraph -->' ),
			)
		);

		$this->assertFalse( metadata_exists( 'post', $post_id, META_KEY ) );
	}

	/**
	 * Test pruning ignores revisions.
	 */
	public function test_prune_code_meta_ignores_revisions() {
		$post_id     = $this->create_post( array( 'abc123' => self::KSES_CODE ) );
		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
			)
		);
		update_metadata( 'post', $revision_id, META_KEY, wp_slash( array( 'abc123' => self::KSES_CODE ) ) );

		prune_code_meta( $revision_id, get_post( $revision_id ) );

		$this->assertSame( array( 'abc123' => self::KSES_CODE ), get_metadata( 'post', $revision_id, META_KEY, true ) );
	}
}
