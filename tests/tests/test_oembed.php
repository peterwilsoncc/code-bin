<?php
/**
 * Test embedding code bins via oEmbed.
 *
 * @package CodeBin
 */

namespace CodeBin\Tests;

use WP_UnitTestCase;

use function CodeBin\filter_embed_html;
use function CodeBin\filter_embed_template;

use const CodeBin\BLOCK_NAME;
use const CodeBin\PLUGIN_DIRECTORY;
use const CodeBin\POST_TYPE;

/**
 * Test embedding code bins via oEmbed.
 */
class Test_OEmbed extends WP_UnitTestCase {

	/**
	 * The plugin's embed template.
	 */
	const TEMPLATE = PLUGIN_DIRECTORY . '/templates/embed-code-bin.php';

	/**
	 * Create a code bin.
	 *
	 * @param array $code     Block attributes.
	 * @param array $post_arr Additional post fields.
	 * @return int Post ID.
	 */
	private function create_code_bin( $code = array( 'html' => '<p>code-bin-embed-test</p>' ), $post_arr = array() ) {
		$block = serialize_block(
			array(
				'blockName'    => BLOCK_NAME,
				'attrs'        => $code,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => POST_TYPE,
					'post_title'   => 'Embed test',
					'post_content' => wp_slash( $block ),
				),
				$post_arr
			)
		);
	}

	/**
	 * Render the embed template for a post, as WordPress does for its embed URL.
	 *
	 * @param int $post_id Post ID.
	 * @return string Embed page.
	 */
	private function render_embed( $post_id ) {
		$this->go_to( get_post_embed_url( $post_id ) );
		$this->assertTrue( is_embed(), 'The test should be on an embed URL.' );

		ob_start();
		require self::TEMPLATE;
		return ob_get_clean();
	}

	/**
	 * Test the iframe width is changed to 100% for code bins.
	 *
	 * @dataProvider data_embed_html_width
	 *
	 * @param string $html     Embed HTML.
	 * @param string $expected Expected embed HTML.
	 */
	public function test_embed_html_width( $html, $expected ) {
		$this->assertSame( $expected, filter_embed_html( $html, get_post( $this->create_code_bin() ) ) );
	}

	/**
	 * Data provider for test_embed_html_width.
	 *
	 * @return array[] Test data.
	 */
	public function data_embed_html_width() {
		return array(
			'width'                => array(
				'<iframe src="https://example.org/" width="600" height="400"></iframe>',
				'<iframe src="https://example.org/" width="100%" height="400"></iframe>',
			),
			'uppercase'            => array(
				'<IFRAME src="https://example.org/" WIDTH="600" height="400"></IFRAME>',
				'<IFRAME src="https://example.org/" WIDTH="100%" height="400"></IFRAME>',
			),
			'only the first'       => array(
				'<iframe width="600"></iframe><iframe width="500"></iframe>',
				'<iframe width="100%"></iframe><iframe width="500"></iframe>',
			),
			'data-width'           => array(
				'<iframe data-width="600" width="500"></iframe>',
				'<iframe data-width="600" width="100%"></iframe>',
			),
			'width outside iframe' => array(
				'<blockquote width="600"></blockquote><iframe width="500"></iframe>',
				'<blockquote width="600"></blockquote><iframe width="100%"></iframe>',
			),
			'no width'             => array(
				'<iframe src="https://example.org/"></iframe>',
				'<iframe src="https://example.org/"></iframe>',
			),
		);
	}

	/**
	 * Test the iframe width is unchanged for other post types.
	 */
	public function test_embed_html_width_other_post_types() {
		$html = '<iframe src="https://example.org/" width="600" height="400"></iframe>';

		$this->assertSame( $html, filter_embed_html( $html, get_post( self::factory()->post->create() ) ) );
	}

	/**
	 * Test the embed HTML of a code bin fills the available width.
	 */
	public function test_post_embed_html_fills_width() {
		$post_id = $this->create_code_bin();

		$this->assertMatchesRegularExpression( '/<iframe\b[^>]*\swidth="100%"/', get_post_embed_html( 600, 400, $post_id ), 'The embed HTML should fill the width.' );

		$response = get_oembed_response_data( $post_id, 600 );
		$this->assertMatchesRegularExpression( '/<iframe\b[^>]*\swidth="100%"/', $response['html'], 'The oEmbed response HTML should fill the width.' );
	}

	/**
	 * Test the embed HTML of other post types is unchanged.
	 */
	public function test_post_embed_html_other_post_types() {
		$post_id = self::factory()->post->create();

		$this->assertMatchesRegularExpression( '/<iframe\b[^>]*\swidth="600"/', get_post_embed_html( 600, 400, $post_id ) );
	}

	/**
	 * Test the plugin's template is used to embed code bins.
	 *
	 * @dataProvider data_embed_template_for_code_bins
	 *
	 * @param string $template Template found by WordPress.
	 */
	public function test_embed_template_for_code_bins( $template ) {
		$this->go_to( get_post_embed_url( $this->create_code_bin() ) );

		$this->assertSame( self::TEMPLATE, filter_embed_template( $template ) );
	}

	/**
	 * Data provider for test_embed_template_for_code_bins.
	 *
	 * @return array[] Test data.
	 */
	public function data_embed_template_for_code_bins() {
		return array(
			'none'             => array( '' ),
			'theme embed'      => array( '/themes/example/embed.php' ),
			'theme post embed' => array( '/themes/example/embed-post.php' ),
		);
	}

	/**
	 * Test a theme's code bin embed template is kept.
	 *
	 * @dataProvider data_embed_template_from_theme
	 *
	 * @param string $template Template found by WordPress.
	 */
	public function test_embed_template_from_theme( $template ) {
		$this->go_to( get_post_embed_url( $this->create_code_bin() ) );

		$this->assertSame( $template, filter_embed_template( $template ) );
	}

	/**
	 * Data provider for test_embed_template_from_theme.
	 *
	 * @return array[] Test data.
	 */
	public function data_embed_template_from_theme() {
		return array(
			'post type'        => array( '/themes/example/embed-code_bin.php' ),
			'post type format' => array( '/themes/example/embed-code_bin-aside.php' ),
		);
	}

	/**
	 * Test other post types use the template found by WordPress.
	 */
	public function test_embed_template_for_other_post_types() {
		$this->go_to( get_post_embed_url( self::factory()->post->create() ) );

		$this->assertSame( '/themes/example/embed.php', filter_embed_template( '/themes/example/embed.php' ) );
	}

	/**
	 * Test WordPress uses the plugin's template for a code bin's embed URL.
	 */
	public function test_get_embed_template() {
		$this->go_to( get_post_embed_url( $this->create_code_bin() ) );

		$this->assertSame( self::TEMPLATE, get_embed_template() );
	}

	/**
	 * Test the embed shows the code bin.
	 */
	public function test_embed_shows_code_bin() {
		$html = $this->render_embed( $this->create_code_bin() );

		$this->assertStringContainsString( 'wp-block-code-bin-block-code-bin-block', $html, 'The embed should show the block.' );
		$this->assertStringContainsString( '&lt;p&gt;code-bin-embed-test&lt;/p&gt;', $html, 'The embed should show the code.' );
	}

	/**
	 * Test the embed's header and footer are customized.
	 *
	 * WordPress loads the embed header and footer with require_once, so they
	 * are only output by the first embed rendered in the test run. This tests
	 * the hooks the template adds instead.
	 */
	public function test_embed_header_and_footer() {
		$this->render_embed( $this->create_code_bin() );

		$this->assertContains( 'code-bin-embed', get_body_class(), 'The body should have the embed class.' );

		ob_start();
		do_action( 'embed_footer' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$footer = ob_get_clean();

		$this->assertStringContainsString( 'ResizeObserver', $footer, 'The embed should report height changes.' );
		$this->assertStringNotContainsString( 'class="wp-embed-share-dialog', $footer, 'The share dialog should not be shown.' );
	}

	/**
	 * Test the embed doesn't show the default embed content.
	 */
	public function test_embed_omits_default_content() {
		$html = $this->render_embed( $this->create_code_bin() );

		$this->assertStringNotContainsString( 'class="wp-embed-heading"', $html, 'The title should not be shown.' );
	}

	/**
	 * Test the embed only shows Code Bin blocks.
	 *
	 * Code bins created outside the editor can contain other blocks.
	 */
	public function test_embed_only_shows_code_bin_blocks() {
		$post_id = $this->create_code_bin();
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( get_post( $post_id )->post_content . "\n\n<!-- wp:paragraph -->\n<p>code-bin-embed-other-block</p>\n<!-- /wp:paragraph -->" ),
			)
		);

		$html = $this->render_embed( $post_id );

		$this->assertStringContainsString( '&lt;p&gt;code-bin-embed-test&lt;/p&gt;', $html, 'The embed should show the code bin.' );
		$this->assertStringNotContainsString( 'code-bin-embed-other-block', $html, 'The embed should not show other blocks.' );
	}

	/**
	 * Test the embed doesn't show the code of a password protected code bin.
	 */
	public function test_embed_password_protected_code_bin() {
		$post_id = $this->create_code_bin(
			array(
				'html'       => '<p>code-bin-secret-html</p>',
				'css'        => 'p { content: "code-bin-secret-css"; }',
				'javascript' => 'console.log( "code-bin-secret-js" );',
			),
			array( 'post_password' => 'secret' )
		);

		$html = $this->render_embed( $post_id );

		$this->assertStringNotContainsString( 'code-bin-secret', $html, 'The code should not be shown.' );
		$this->assertStringNotContainsString( 'wp-block-code-bin-block-code-bin-block', $html, 'The block should not be shown.' );
		$this->assertStringContainsString( 'class="wp-embed-heading"', $html, 'The default embed content should be shown.' );
	}
}
