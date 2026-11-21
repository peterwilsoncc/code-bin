<?php
/**
 * Test the Code Bin preview's base styles.
 *
 * @package CodeBin
 */

namespace CodeBin\Tests;

use WP_HTML_Tag_Processor;
use WP_UnitTestCase;

use function CodeBin\add_editor_preview_styles;
use function CodeBin\get_preview_styles;
use function CodeBin\get_preview_styles_element;

use const CodeBin\BLOCK_NAME;

/**
 * Test the Code Bin preview's base styles.
 */
class Test_Preview_Styles extends WP_UnitTestCase {

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
	 * Filter the preview styles to return a value.
	 *
	 * @param mixed $styles Value for the filter to return.
	 */
	private function filter_styles( $styles ) {
		add_filter(
			'code_bin_preview_styles',
			function () use ( $styles ) {
				return $styles;
			}
		);
	}

	/**
	 * Get an attribute of the rendered block's wrapper, decoded.
	 *
	 * @param string $html      Rendered block.
	 * @param string $attribute Attribute name.
	 * @return string|true|null Attribute value, null if not found.
	 */
	private function get_wrapper_attribute( $html, $attribute ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		$processor->next_tag( array( 'class_name' => 'wp-block-code-bin-block-code-bin-block' ) );
		return $processor->get_attribute( $attribute );
	}

	/**
	 * Get the rendered block's preview srcdoc, decoded.
	 *
	 * @param string $html Rendered block.
	 * @return string|true|null srcdoc, null if not found.
	 */
	private function get_srcdoc( $html ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		$processor->next_tag( 'iframe' );
		return $processor->get_attribute( 'srcdoc' );
	}

	/**
	 * Test the default styles.
	 */
	public function test_default_styles() {
		$styles = get_preview_styles();

		$this->assertStringContainsString( ':root {', $styles, 'The default styles should style the root element.' );
		$this->assertStringContainsString( 'color-scheme: light;', $styles, 'The default styles should set a light color scheme.' );
		$this->assertStringContainsString( 'max-width: 100%;', $styles, 'The default styles should limit the width of media.' );
	}

	/**
	 * Test the filter replaces the default styles.
	 */
	public function test_filter_replaces_styles() {
		$this->filter_styles( 'body { font-family: serif; }' );

		$this->assertSame( 'body { font-family: serif; }', get_preview_styles() );
	}

	/**
	 * Test the filter receives the default styles.
	 */
	public function test_filter_receives_default_styles() {
		$default = get_preview_styles();
		$this->filter_styles( '' );

		$received = null;
		add_filter(
			'code_bin_preview_styles',
			function ( $styles ) use ( &$received ) {
				$received = $styles;
				return $styles;
			},
			5
		);
		get_preview_styles();

		$this->assertSame( $default, $received );
	}

	/**
	 * Test the filter returning a value that isn't a string removes the styles.
	 *
	 * @dataProvider data_filter_returning_non_string
	 *
	 * @param mixed $styles Filtered value.
	 */
	public function test_filter_returning_non_string( $styles ) {
		$this->filter_styles( $styles );

		$this->assertSame( '', get_preview_styles() );
	}

	/**
	 * Data provider for test_filter_returning_non_string.
	 *
	 * @return array[] Test data.
	 */
	public function data_filter_returning_non_string() {
		return array(
			'null'    => array( null ),
			'false'   => array( false ),
			'integer' => array( 123 ),
			'array'   => array( array( 'body { color: red; }' ) ),
		);
	}

	/**
	 * Test no style element is created when there are no styles.
	 *
	 * @dataProvider data_style_element_empty
	 *
	 * @param string $styles Styles.
	 */
	public function test_style_element_empty( $styles ) {
		$this->assertSame( '', get_preview_styles_element( $styles ) );
	}

	/**
	 * Data provider for test_style_element_empty.
	 *
	 * @return array[] Test data.
	 */
	public function data_style_element_empty() {
		return array(
			'empty'      => array( '' ),
			'whitespace' => array( " \n\t" ),
		);
	}

	/**
	 * Test the styles are wrapped in a cascade layer.
	 */
	public function test_style_element_uses_cascade_layer() {
		$this->assertSame(
			"<style>@layer code-bin-base{\nbody { color: red; }\n}</style>",
			get_preview_styles_element( 'body { color: red; }' )
		);
	}

	/**
	 * Test the styles can't close the style element early.
	 *
	 * @dataProvider data_style_element_cannot_be_closed
	 *
	 * @param string $styles Styles.
	 */
	public function test_style_element_cannot_be_closed( $styles ) {
		$element = get_preview_styles_element( $styles );

		$this->assertSame( 1, substr_count( strtolower( $element ), '</style' ), 'The element should only be closed once.' );
		$this->assertStringEndsWith( '}</style>', $element, 'The element should be closed at the end.' );
	}

	/**
	 * Data provider for test_style_element_cannot_be_closed.
	 *
	 * @return array[] Test data.
	 */
	public function data_style_element_cannot_be_closed() {
		return array(
			'closing tag'            => array( '</style><script>alert( 1 );</script>' ),
			'uppercase closing tag'  => array( '</STYLE><script>alert( 1 );</script>' ),
			'mixed case'             => array( '</StYlE><script>alert( 1 );</script>' ),
			'closing tag with space' => array( '</style ><script>alert( 1 );</script>' ),
			'repeated'               => array( 'a { } </style> b { } </style>' ),
			'in a CSS string'        => array( 'p::after { content: "</style>"; }' ),
		);
	}

	/**
	 * Test the styles can't break out of the preview in the rendered block.
	 */
	public function test_styles_cannot_break_out_of_srcdoc() {
		$this->filter_styles( '</style><p class="injected">Injected</p>"><p class="injected">Injected</p>' );
		$html = $this->render( array( 'html' => '<p>Hi</p>' ) );

		$this->assertStringNotContainsString( '<p class="injected">', $html, 'The styles should not add elements to the page.' );
		$this->assertStringNotContainsString( '</style><p', $this->get_srcdoc( $html ), 'The styles should not add elements to the preview.' );
	}

	/**
	 * Test the rendered block's preview includes the base styles before the code bin's CSS.
	 */
	public function test_srcdoc_includes_styles_before_css() {
		$this->filter_styles( 'body { color: blue; }' );
		$srcdoc = $this->get_srcdoc(
			$this->render(
				array(
					'html' => '<p>Hi</p>',
					'css'  => 'body { color: red; }',
				)
			)
		);

		$base = strpos( $srcdoc, get_preview_styles_element( 'body { color: blue; }' ) );
		$css  = strpos( $srcdoc, '<style>body { color: red; }</style>' );

		$this->assertIsInt( $base, 'The srcdoc should include the base styles.' );
		$this->assertIsInt( $css, 'The srcdoc should include the CSS.' );
		$this->assertLessThan( $css, $base, 'The base styles should come before the CSS.' );
	}

	/**
	 * Test the rendered block passes the styles to the view script exactly.
	 *
	 * @dataProvider data_wrapper_attribute_contains_styles_exactly
	 *
	 * @param string $styles Styles.
	 */
	public function test_wrapper_attribute_contains_styles_exactly( $styles ) {
		$this->filter_styles( $styles );
		$html = $this->render( array( 'html' => '<p>Hi</p>' ) );

		$this->assertSame( $styles, $this->get_wrapper_attribute( $html, 'data-code-bin-preview-styles' ) );
	}

	/**
	 * Data provider for test_wrapper_attribute_contains_styles_exactly.
	 *
	 * @return array[] Test data.
	 */
	public function data_wrapper_attribute_contains_styles_exactly() {
		return array(
			'plain'            => array( 'body { color: red; }' ),
			'quotes'           => array( 'body { font-family: "Helvetica Neue", \'Arial\'; }' ),
			'entities as text' => array( 'p::after { content: "&amp; &lt;b&gt; &copy;"; }' ),
		);
	}

	/**
	 * Test the rendered block has no styles for the view script when there are none.
	 */
	public function test_wrapper_attribute_omitted_without_styles() {
		$this->filter_styles( '' );
		$html = $this->render( array( 'html' => '<p>Hi</p>' ) );

		$this->assertNull( $this->get_wrapper_attribute( $html, 'data-code-bin-preview-styles' ), 'The data attribute should be omitted.' );
		$this->assertStringNotContainsString( 'code-bin-base', $this->get_srcdoc( $html ), 'The srcdoc should not include a base style element.' );
	}

	/**
	 * Test the editor is passed the styles.
	 *
	 * @dataProvider data_wrapper_attribute_contains_styles_exactly
	 *
	 * @param string $styles Styles.
	 */
	public function test_editor_is_passed_styles( $styles ) {
		$this->filter_styles( $styles );
		add_editor_preview_styles();

		$handle = generate_block_asset_handle( BLOCK_NAME, 'editorScript' );
		$this->assertTrue( wp_script_is( $handle, 'registered' ), 'The editor script should be registered.' );

		$before = wp_scripts()->get_data( $handle, 'before' );
		$this->assertIsArray( $before, 'The editor script should have an inline script.' );

		// Inline scripts are added each time, the last is from this test.
		$script = end( $before );
		$this->assertMatchesRegularExpression( '/^window\.codeBin = Object\.assign\( window\.codeBin \|\| \{\}, (.+) \);$/s', $script );

		preg_match( '/^window\.codeBin = Object\.assign\( window\.codeBin \|\| \{\}, (.+) \);$/s', $script, $matches );
		$this->assertSame( array( 'previewStyles' => $styles ), json_decode( $matches[1], true ) );
	}
}
