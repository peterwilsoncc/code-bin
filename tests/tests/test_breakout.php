<?php
/**
 * Test code can't break out of the Code Bin block's markup.
 *
 * Code is stored in post meta without kses filtering, for users with and
 * without the `unfiltered_html` capability, so it must be escaped wherever
 * it's output. The browser tests check the code can't escape the preview's
 * sandbox; these check it can't escape the markup rendered on the server.
 *
 * The code is saved each way it can be: through the REST API, as the block
 * editor does, and through the PHP post and meta APIs.
 *
 * @package CodeBin
 */

namespace CodeBin\Tests;

use WP_HTML_Tag_Processor;
use WP_REST_Request;
use WP_UnitTestCase;

use const CodeBin\BLOCK_NAME;
use const CodeBin\META_KEY;
use const CodeBin\POST_TYPE;

/**
 * Test code can't break out of the Code Bin block's markup.
 */
class Test_Breakout extends WP_UnitTestCase {

	/**
	 * Code ID used for the attacks.
	 */
	const CODE_ID = 'breakout1';

	/**
	 * Attacks on the markup, by panel.
	 *
	 * Each tries to close the element the code is output in and add elements
	 * or attributes to the page. Injected elements have the class
	 * `code-bin-injected`. Includes backslashes, which are lost if the code
	 * isn't slashed when saved.
	 */
	const ATTACK = array(
		'html'       => '</code></pre></div></iframe></div><p class="code-bin-injected">Injected</p>'
			. '"><p class="code-bin-injected">Injected</p>\'><p class="code-bin-injected">Injected</p>'
			. '<script class="code-bin-injected">top.codeBinPwned = 1;</script>'
			. '<img class="code-bin-injected" src="data:," onerror="top.codeBinPwned = 1">'
			. '&lt;p class="code-bin-injected"&gt;Entities&lt;/p&gt; &amp;lt; \\" \\\\',
		'css'        => 'p { content: "\\201C"; }</style><script class="code-bin-injected">top.codeBinPwned = 1;</script><!--',
		'javascript' => 'const tags = \'</script><script class="code-bin-injected">top.codeBinPwned = 1;</script><!--\';'
			. ' const re = /\\d+\\/x/; const quote = "\\""; const amp = "&amp;";',
	);

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private static $admin;

	/**
	 * Author user ID.
	 *
	 * @var int
	 */
	private static $author;

	/**
	 * Create the users.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$author = $factory->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();
		\CodeBin\reset_reserved_block_anchors();

		// The test case unregisters meta keys after each test.
		\CodeBin\register_code_meta();

		if ( is_multisite() ) {
			grant_super_admin( self::$admin );
		}
	}

	/**
	 * Get the block markup for the attack's code bin.
	 *
	 * @return string Block markup.
	 */
	private function block() {
		return serialize_block(
			array(
				'blockName'    => BLOCK_NAME,
				'attrs'        => array( 'codeId' => self::CODE_ID ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	/**
	 * Save the attack as a user.
	 *
	 * @param string $method How to save: rest, insert_post, meta_input or update_meta.
	 * @param int    $user   User ID.
	 * @return int Post ID.
	 */
	private function save( $method, $user ) {
		wp_set_current_user( $user );
		$meta = array( self::CODE_ID => self::ATTACK );

		switch ( $method ) {
			case 'rest':
				$request = new WP_REST_Request( 'POST', '/wp/v2/code-bins' );
				$request->set_body_params(
					array(
						'title'   => 'Breakout',
						'status'  => 'publish',
						'content' => $this->block(),
						'meta'    => array( META_KEY => $meta ),
					)
				);
				$response = rest_do_request( $request );
				$this->assertSame( 201, $response->get_status(), 'The code bin should be created.' );
				return $response->get_data()['id'];

			case 'meta_input':
				// wp_insert_post() expects slashed data, including meta_input.
				return wp_insert_post(
					wp_slash(
						array(
							'post_type'    => POST_TYPE,
							'post_status'  => 'publish',
							'post_title'   => 'Breakout',
							'post_content' => $this->block(),
							'meta_input'   => array( META_KEY => $meta ),
						)
					)
				);

			case 'update_meta':
				$post_id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => POST_TYPE,
							'post_status'  => 'publish',
							'post_title'   => 'Breakout',
							'post_content' => $this->block(),
						)
					)
				);
				// update_post_meta() expects slashed data.
				update_post_meta( $post_id, META_KEY, wp_slash( $meta ) );
				return $post_id;

			case 'add_meta':
				$post_id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => POST_TYPE,
							'post_status'  => 'publish',
							'post_title'   => 'Breakout',
							'post_content' => $this->block(),
						)
					)
				);
				// add_post_meta() expects slashed data.
				add_post_meta( $post_id, META_KEY, wp_slash( $meta ), true );
				return $post_id;
		}

		$this->fail( "Unknown save method {$method}." );
	}

	/**
	 * Render a post's blocks.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $feed    Whether to render the post in a feed.
	 * @return string Rendered blocks.
	 */
	private function render( $post_id, $feed = false ) {
		$this->go_to( $feed ? get_post_type_archive_feed_link( POST_TYPE ) : get_permalink( $post_id ) );
		$this->assertSame( $feed, is_feed() );

		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the post being rendered.
		setup_postdata( $GLOBALS['post'] );

		return do_blocks( get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * Get the decoded text of a panel's code element.
	 *
	 * The code is escaped, so the element can't contain `</code>` other than
	 * its own closing tag.
	 *
	 * @param string $html  Rendered block.
	 * @param string $panel Panel name.
	 * @return string|null Decoded code, null if not found.
	 */
	private function get_code_text( $html, $panel ) {
		if ( ! preg_match( '#<code class="language-' . preg_quote( $panel, '#' ) . '"[^>]*>(.*?)</code>#s', $html, $matches ) ) {
			return null;
		}

		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Assert the rendered markup only contains the block's own elements.
	 *
	 * @param string $html Rendered block.
	 */
	private function assert_no_injected_markup( $html ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		$iframes   = array();

		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			if ( $processor->is_tag_closer() ) {
				continue;
			}

			$tag = $processor->get_tag();
			$this->assertNotContains( $tag, array( 'SCRIPT', 'IMG', 'STYLE' ), "The code should not add a {$tag} element." );
			$this->assertFalse( (bool) $processor->has_class( 'code-bin-injected' ), "The code should not add a {$tag} element." );

			foreach ( $processor->get_attribute_names_with_prefix( 'on' ) ?? array() as $attribute ) {
				$this->fail( "The code should not add the {$attribute} attribute to a {$tag} element." );
			}

			if ( 'IFRAME' === $tag ) {
				$iframes[] = array(
					'sandbox' => $processor->get_attribute( 'sandbox' ),
					'srcdoc'  => $processor->get_attribute( 'srcdoc' ),
				);
			}
		}

		$this->assertLessThanOrEqual( 1, count( $iframes ), 'The code should not add an iframe.' );
		foreach ( $iframes as $iframe ) {
			$this->assertSame( '', $iframe['sandbox'], 'The preview should render with scripts disabled.' );
			$this->assertStringContainsString( '<body>' . self::ATTACK['html'] . '</body>', $iframe['srcdoc'], 'The preview should contain the HTML exactly.' );
			$this->assertStringContainsString( '<style>' . self::ATTACK['css'] . '</style>', $iframe['srcdoc'], 'The preview should contain the CSS exactly.' );
		}
	}

	/**
	 * Test the attack is saved exactly and can't break out of the markup.
	 *
	 * @dataProvider data_save
	 *
	 * @param string $method How to save the code.
	 * @param string $role   Role of the user saving: admin or author.
	 */
	public function test_code_cannot_break_out( $method, $role ) {
		$user = 'admin' === $role ? self::$admin : self::$author;
		wp_set_current_user( $user );
		$this->assertSame( 'admin' === $role, current_user_can( 'unfiltered_html' ), 'Only admins should have unfiltered_html.' );

		$post_id = $this->save( $method, $user );

		$this->assertSame(
			array( self::CODE_ID => self::ATTACK ),
			get_post_meta( $post_id, META_KEY, true ),
			'The code should be saved exactly, without kses and with its backslashes.'
		);

		$html = $this->render( $post_id );
		$this->assertStringContainsString( 'iframe', $html, 'The block should render.' );
		$this->assert_no_injected_markup( $html );
		foreach ( self::ATTACK as $panel => $code ) {
			$this->assertSame( $code, $this->get_code_text( $html, $panel ), "The {$panel} panel should show the code exactly." );
		}

		$feed = $this->render( $post_id, true );
		$this->assert_no_injected_markup( $feed );
		foreach ( self::ATTACK as $panel => $code ) {
			$this->assertSame( $code, $this->get_code_text( $feed, $panel ), "The feed should show the {$panel} code exactly." );
		}
	}

	/**
	 * Data provider for test_code_cannot_break_out.
	 *
	 * @return array[] Test data.
	 */
	public function data_save() {
		$data = array();
		foreach ( array( 'rest', 'meta_input', 'update_meta', 'add_meta' ) as $method ) {
			foreach ( array( 'admin', 'author' ) as $role ) {
				$data[ "{$method}, {$role}" ] = array( $method, $role );
			}
		}

		return $data;
	}

	/**
	 * Test unslashed meta loses its backslashes, so the tests would catch it.
	 */
	public function test_unslashed_meta_loses_backslashes() {
		$post_id = self::factory()->post->create( array( 'post_type' => POST_TYPE ) );

		update_post_meta( $post_id, META_KEY, array( self::CODE_ID => self::ATTACK ) );

		$this->assertNotSame( self::ATTACK, get_post_meta( $post_id, META_KEY, true )[ self::CODE_ID ] );
	}
}
