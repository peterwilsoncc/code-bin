<?php
/**
 * Test rendering the Code Bin block on the site and in feeds.
 *
 * @package CodeBin
 */

namespace CodeBin\Tests;

use WP_HTML_Tag_Processor;
use WP_UnitTestCase;

use const CodeBin\BLOCK_NAME;

/**
 * Test rendering the Code Bin block.
 */
class Test_Render extends WP_UnitTestCase {

	/**
	 * Start each test without any anchors reserved.
	 */
	public function set_up() {
		parent::set_up();
		\CodeBin\reset_reserved_block_anchors();
	}

	/**
	 * Render a Code Bin block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered block.
	 */
	private function render( $attributes ) {
		return render_block(
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
	 * Get the decoded text of a panel's code element.
	 *
	 * This is what the view script reads via `textContent`.
	 *
	 * @param string $html  Rendered block.
	 * @param string $panel Panel name.
	 * @return string|null Decoded code, null if the element isn't found.
	 */
	private function get_code_text( $html, $panel ) {
		if ( ! preg_match( '#<code class="language-' . preg_quote( $panel, '#' ) . '"[^>]*>(.*?)</code>#s', $html, $matches ) ) {
			return null;
		}

		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Get the value of the first matching tag's attribute.
	 *
	 * @param string $html      HTML.
	 * @param array  $query     Tag processor query.
	 * @param string $attribute Attribute name.
	 * @return string|true|null Attribute value, null if not found.
	 */
	private function get_attribute( $html, $query, $attribute ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( $query ) ) {
			return null;
		}

		return $processor->get_attribute( $attribute );
	}

	/**
	 * Set up the global query and post for rendering in a feed.
	 *
	 * @return int Post ID.
	 */
	private function go_to_feed() {
		$post_id = self::factory()->post->create();
		$this->go_to( get_feed_link() );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );

		$this->assertTrue( is_feed(), 'The test should be running in a feed.' );
		return $post_id;
	}

	/**
	 * Test the block renders nothing when it contains no code.
	 *
	 * @dataProvider data_empty_block_renders_nothing
	 *
	 * @param array $attributes Block attributes.
	 */
	public function test_empty_block_renders_nothing( $attributes ) {
		$this->assertSame( '', $this->render( $attributes ) );
	}

	/**
	 * Test the block renders nothing in feeds when it contains no code.
	 *
	 * @dataProvider data_empty_block_renders_nothing
	 *
	 * @param array $attributes Block attributes.
	 */
	public function test_empty_block_renders_nothing_in_feed( $attributes ) {
		$this->go_to_feed();
		$this->assertSame( '', $this->render( $attributes ) );
	}

	/**
	 * Data provider for:
	 * - test_empty_block_renders_nothing
	 * - test_empty_block_renders_nothing_in_feed
	 *
	 * Empty blocks.
	 *
	 * @return array[] Test data.
	 */
	public function data_empty_block_renders_nothing() {
		return array(
			'no attributes'   => array( array() ),
			'empty strings'   => array(
				array(
					'html'       => '',
					'css'        => '',
					'javascript' => '',
				),
			),
			'only whitespace' => array(
				array(
					'html'       => "  \n",
					'css'        => "\t",
					'javascript' => ' ',
				),
			),
		);
	}

	/**
	 * Test code is escaped in the code panels and is shown exactly as saved.
	 *
	 * @dataProvider data_code
	 *
	 * @param string $panel Panel name.
	 * @param string $code  Code.
	 */
	public function test_code_is_escaped_in_panel( $panel, $code ) {
		$html = $this->render( array( $panel => $code ) );

		$this->assertStringNotContainsString( '<script>alert', $html, 'The code should be escaped.' );
		$this->assertStringNotContainsString( '</code></pre><b>', $html, 'The code should not close the code element.' );
		$this->assertSame( $code, $this->get_code_text( $html, $panel ), 'The code panel should contain the code exactly as saved.' );
	}

	/**
	 * Test code is escaped in feeds and is shown exactly as saved.
	 *
	 * @dataProvider data_code
	 *
	 * @param string $panel Panel name.
	 * @param string $code  Code.
	 */
	public function test_code_is_escaped_in_feed( $panel, $code ) {
		$this->go_to_feed();
		$html = $this->render( array( $panel => $code ) );

		$this->assertStringNotContainsString( '<script>alert', $html, 'The code should be escaped.' );
		$this->assertStringNotContainsString( '</code></pre><b>', $html, 'The code should not close the code element.' );
		$this->assertSame( $code, $this->get_code_text( $html, $panel ), 'The feed should contain the code exactly as saved.' );
	}

	/**
	 * Data provider for:
	 * - test_code_is_escaped_in_panel
	 * - test_code_is_escaped_in_feed
	 *
	 * Code that needs escaping.
	 *
	 * @return array[] Test data.
	 */
	public function data_code() {
		$data = array();
		foreach ( array( 'html', 'css', 'javascript' ) as $panel ) {
			$data[ "{$panel}: script element" ]   = array( $panel, '<script>alert( "hi" );</script>' );
			$data[ "{$panel}: closes code" ]      = array( $panel, '</code></pre><b>bold</b>' );
			$data[ "{$panel}: quotes" ]           = array( $panel, 'a = "double" + \'single\';' );
			$data[ "{$panel}: entities as text" ] = array( $panel, '&lt;b&gt; &amp; &quot; &#039; &copy;' );
		}
		return $data;
	}

	/**
	 * Test the HTML and CSS can't break out of the preview's srcdoc attribute.
	 *
	 * The srcdoc must decode back to exactly the document built from the code.
	 *
	 * @dataProvider data_srcdoc_contains_code_exactly
	 *
	 * @param string $html_code HTML code.
	 * @param string $css_code  CSS code.
	 */
	public function test_srcdoc_contains_code_exactly( $html_code, $css_code ) {
		$html = $this->render(
			array(
				'html' => $html_code,
				'css'  => $css_code,
			)
		);

		$srcdoc = $this->get_attribute( $html, array( 'tag_name' => 'iframe' ), 'srcdoc' );
		$this->assertIsString( $srcdoc, 'The preview should have a srcdoc.' );
		$this->assertStringContainsString( '<style>' . $css_code . '</style>', $srcdoc, 'The srcdoc should contain the CSS exactly.' );
		$this->assertStringEndsWith( '<body>' . $html_code . '</body></html>', $srcdoc, 'The srcdoc should contain the HTML exactly.' );

		$processor = new WP_HTML_Tag_Processor( $html );
		$this->assertTrue( $processor->next_tag( 'iframe' ) );
		$this->assertFalse( $processor->next_tag( array( 'class_name' => 'injected' ) ), 'The code should not add elements outside the preview.' );
	}

	/**
	 * Data provider for test_srcdoc_contains_code_exactly.
	 *
	 * @return array[] Test data.
	 */
	public function data_srcdoc_contains_code_exactly() {
		return array(
			'double quotes'    => array( '"><p class="injected">Injected</p>', 'p::before { content: "\""; }' ),
			'single quotes'    => array( '\'><p class="injected">Injected</p>', 'p::before { content: \'\\\'\'; }' ),
			'entities as text' => array( '<p>&lt;b&gt;not bold&lt;/b&gt; &amp; &quot;</p>', 'p::after { content: "&amp;"; }' ),
			'closing iframe'   => array( '</iframe><p class="injected">Injected</p>', 'p { color: red; }' ),
		);
	}

	/**
	 * Test the preview is rendered with an empty sandbox, so scripts can't run.
	 */
	public function test_preview_sandbox_is_empty() {
		$html = $this->render( array( 'html' => '<p>Hello</p>' ) );

		$this->assertSame( '', $this->get_attribute( $html, array( 'tag_name' => 'iframe' ), 'sandbox' ) );
	}

	/**
	 * Test the JavaScript isn't included in the srcdoc.
	 *
	 * The view script adds it once loop protection is in place.
	 */
	public function test_javascript_is_not_in_srcdoc() {
		$html = $this->render(
			array(
				'html'       => '<p>Hello</p>',
				'javascript' => 'console.log( "code-bin-unique-js" );',
			)
		);

		$srcdoc = $this->get_attribute( $html, array( 'tag_name' => 'iframe' ), 'srcdoc' );
		$this->assertStringNotContainsString( 'code-bin-unique-js', $srcdoc );
		$this->assertStringNotContainsString( '<script', $srcdoc );
	}

	/**
	 * Test a style element is only added to the srcdoc for CSS containing code.
	 *
	 * The preview's base styles are in their own style element, wrapped in a
	 * cascade layer.
	 *
	 * @dataProvider data_style_element_only_added_for_css
	 *
	 * @param string $css      CSS code.
	 * @param bool   $expected Whether the code bin's style element is expected.
	 */
	public function test_style_element_only_added_for_css( $css, $expected ) {
		$html   = $this->render(
			array(
				'html' => '<p>Hello</p>',
				'css'  => $css,
			)
		);
		$srcdoc = $this->get_attribute( $html, array( 'tag_name' => 'iframe' ), 'srcdoc' );

		$this->assertSame( $expected ? 2 : 1, substr_count( $srcdoc, '<style>' ) );
	}

	/**
	 * Data provider for test_style_element_only_added_for_css.
	 *
	 * @return array[] Test data.
	 */
	public function data_style_element_only_added_for_css() {
		return array(
			'no CSS'          => array( '', false ),
			'whitespace only' => array( " \n\t", false ),
			'CSS'             => array( 'p { color: red; }', true ),
		);
	}

	/**
	 * Test the first panel containing code is shown.
	 *
	 * @dataProvider data_active_panel
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $expected   Expected active panel.
	 */
	public function test_active_panel( $attributes, $expected ) {
		$html = $this->render( $attributes );

		foreach ( array( 'html', 'css', 'javascript' ) as $panel ) {
			$is_active = $panel === $expected;

			$this->assertSame(
				$is_active ? 'true' : 'false',
				$this->get_tab_selected( $html, $panel ),
				"The {$panel} tab's selected state is incorrect."
			);
			$this->assertSame(
				$is_active ? null : true,
				$this->get_panel_hidden( $html, $panel ),
				"The {$panel} panel's hidden state is incorrect."
			);
		}
	}

	/**
	 * Data provider for test_active_panel.
	 *
	 * @return array[] Test data.
	 */
	public function data_active_panel() {
		return array(
			'all panels'               => array(
				array(
					'html'       => '<p>Hi</p>',
					'css'        => 'p {}',
					'javascript' => 'x();',
				),
				'html',
			),
			'CSS only'                 => array( array( 'css' => 'p {}' ), 'css' ),
			'JavaScript only'          => array( array( 'javascript' => 'x();' ), 'javascript' ),
			'CSS and JavaScript'       => array(
				array(
					'css'        => 'p {}',
					'javascript' => 'x();',
				),
				'css',
			),
			'whitespace HTML then CSS' => array(
				array(
					'html' => "\n ",
					'css'  => 'p {}',
				),
				'css',
			),
		);
	}

	/**
	 * Get the aria-selected value of a code tab.
	 *
	 * @param string $html  Rendered block.
	 * @param string $panel Panel name.
	 * @return string|null aria-selected value.
	 */
	private function get_tab_selected( $html, $panel ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'button' ) ) {
			if ( str_ends_with( (string) $processor->get_attribute( 'id' ), "-tab-{$panel}" ) ) {
				return $processor->get_attribute( 'aria-selected' );
			}
		}
		return null;
	}

	/**
	 * Get the hidden attribute of a code panel.
	 *
	 * @param string $html  Rendered block.
	 * @param string $panel Panel name.
	 * @return true|null True if hidden, null if not.
	 */
	private function get_panel_hidden( $html, $panel ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( array( 'class_name' => 'code-bin__panel' ) ) ) {
			if ( $panel === $processor->get_attribute( 'data-panel' ) ) {
				return $processor->get_attribute( 'hidden' );
			}
		}
		$this->fail( "The {$panel} panel was not found." );
	}

	/**
	 * Test the preview height.
	 *
	 * @dataProvider data_preview_height
	 *
	 * @param mixed  $height   previewHeight attribute, null to omit it.
	 * @param string $expected Expected height in pixels.
	 */
	public function test_preview_height( $height, $expected ) {
		$attributes = array( 'html' => '<p>Hi</p>' );
		if ( null !== $height ) {
			$attributes['previewHeight'] = $height;
		}
		$html = $this->render( $attributes );

		$this->assertSame( "height: {$expected}px", $this->get_attribute( $html, array( 'tag_name' => 'iframe' ), 'style' ), 'The preview height is incorrect.' );
		$this->assertSame( "height: {$expected}px", $this->get_attribute( $html, array( 'class_name' => 'code-bin__console' ), 'style' ), 'The console height is incorrect.' );
	}

	/**
	 * Data provider for test_preview_height.
	 *
	 * @return array[] Test data.
	 */
	public function data_preview_height() {
		return array(
			'default'  => array( null, '300' ),
			'set'      => array( 450, '450' ),
			'float'    => array( 450.7, '450' ),
			'negative' => array( -50, '50' ),
			'string'   => array( 'abc', '300' ),
		);
	}

	/**
	 * Test the editable data attribute.
	 *
	 * @dataProvider data_editable
	 *
	 * @param bool|null $editable Editable attribute, null to omit it.
	 * @param string    $expected Expected data attribute value.
	 */
	public function test_editable( $editable, $expected ) {
		$attributes = array( 'html' => '<p>Hi</p>' );
		if ( null !== $editable ) {
			$attributes['editable'] = $editable;
		}
		$html = $this->render( $attributes );

		$this->assertSame( $expected, $this->get_attribute( $html, array( 'class_name' => 'code-bin' ), 'data-code-bin-editable' ) );
	}

	/**
	 * Data provider for test_editable.
	 *
	 * @return array[] Test data.
	 */
	public function data_editable() {
		return array(
			'default' => array( null, 'true' ),
			'true'    => array( true, 'true' ),
			'false'   => array( false, 'false' ),
		);
	}

	/**
	 * Test two blocks on the same page get unique tab and panel IDs.
	 */
	public function test_ids_are_unique_per_block() {
		$attributes = array( 'html' => '<p>Hi</p>' );
		$html       = $this->render( $attributes ) . $this->render( $attributes );

		$ids           = array();
		$tab_panel_ids = array();
		$processor     = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$id = $processor->get_attribute( 'id' );
			if ( is_string( $id ) ) {
				$ids[] = $id;

				if ( in_array( $processor->get_attribute( 'role' ), array( 'tab', 'tabpanel' ), true ) ) {
					$tab_panel_ids[] = $id;
				}
			}

			$controls = $processor->get_attribute( 'aria-controls' );
			if ( is_string( $controls ) ) {
				$this->assertStringContainsString( 'id="' . $controls . '"', $html, 'aria-controls should reference an element.' );
			}
		}

		// Two blocks, each with 5 tabs and 5 panels.
		$this->assertCount( 20, $tab_panel_ids, 'Each tab and panel should have an ID.' );
		// Includes the first block's anchor: the second block has identical code so doesn't get one.
		$this->assertSame( $ids, array_unique( $ids ), 'The IDs should be unique.' );
	}

	/**
	 * Test feeds only include the panels containing code, with a label.
	 */
	public function test_feed_only_includes_panels_with_code() {
		$this->go_to_feed();
		$html = $this->render(
			array(
				'html'       => '<p>Hi</p>',
				'css'        => '  ',
				'javascript' => 'x();',
			)
		);

		$this->assertStringContainsString( '<p><strong>HTML</strong></p><pre><code class="language-html">', $html );
		$this->assertStringContainsString( '<p><strong>JavaScript</strong></p><pre><code class="language-javascript">', $html );
		$this->assertStringNotContainsString( 'language-css', $html );
		$this->assertStringNotContainsString( '<strong>CSS</strong>', $html );
	}

	/**
	 * Test feeds don't include the interactive markup.
	 */
	public function test_feed_excludes_interactive_markup() {
		$this->go_to_feed();
		$html = $this->render( array( 'html' => '<p>Hi</p>' ) );

		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( 'role="tablist"', $html );
		$this->assertStringNotContainsString( 'code-bin__', $html );
	}

	/**
	 * Test feeds link to the block on the site using the block's anchor.
	 *
	 * @dataProvider data_feed_links_to_block_anchor
	 *
	 * @param array $attributes Block attributes.
	 */
	public function test_feed_links_to_block_anchor( $attributes ) {
		$post_id = $this->go_to_feed();
		$feed    = $this->render( $attributes );
		$anchor  = \CodeBin\get_block_anchor( $attributes );

		$this->assertSame(
			get_permalink( $post_id ) . '#' . $anchor,
			$this->get_attribute( $feed, array( 'tag_name' => 'a' ), 'href' ),
			'The feed should link to the block on the site.'
		);

		// Render the same block on the site.
		$this->go_to( get_permalink( $post_id ) );
		$site = $this->render( $attributes );

		// Saved anchors are output by the anchor block support, others by render.php.
		$this->assertSame( $anchor, $this->get_attribute( $site, array( 'class_name' => 'code-bin' ), 'id' ), 'The block on the site should have the anchor the feed links to.' );
		$this->assertSame( 1, substr_count( $site, 'id="' . $anchor . '"' ), 'The anchor should be output once.' );
	}

	/**
	 * Data provider for test_feed_links_to_block_anchor.
	 *
	 * @return array[] Test data.
	 */
	public function data_feed_links_to_block_anchor() {
		return array(
			'code based anchor' => array( array( 'html' => '<p>Hi</p>' ) ),
			'saved anchor'      => array(
				array(
					'html'   => '<p>Hi</p>',
					'anchor' => 'my-code-bin',
				),
			),
		);
	}
}
