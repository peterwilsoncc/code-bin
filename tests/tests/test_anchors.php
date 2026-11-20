<?php
/**
 * Test the Code Bin block's anchors.
 *
 * @package CodeBin
 */

namespace CodeBin\Tests;

use WP_HTML_Tag_Processor;
use WP_UnitTestCase;

use function CodeBin\get_block_anchor;
use function CodeBin\has_saved_anchor;
use function CodeBin\reserve_block_anchor;

use const CodeBin\BLOCK_NAME;

/**
 * Test the Code Bin block's anchors.
 */
class Test_Anchors extends WP_UnitTestCase {

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
	 * Get the IDs of each rendered Code Bin block's wrapper.
	 *
	 * @param string $html Rendered blocks.
	 * @return array<string|null> Wrapper IDs, null for blocks without one.
	 */
	private function get_wrapper_ids( $html ) {
		$ids       = array();
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( array( 'class_name' => 'wp-block-code-bin-block-code-bin-block' ) ) ) {
			$ids[] = $processor->get_attribute( 'id' );
		}
		return $ids;
	}

	/**
	 * Test whether a block has a saved anchor.
	 *
	 * @dataProvider data_has_saved_anchor
	 *
	 * @param array $attributes Block attributes.
	 * @param bool  $expected   Expected result.
	 */
	public function test_has_saved_anchor( $attributes, $expected ) {
		$this->assertSame( $expected, has_saved_anchor( $attributes ) );
	}

	/**
	 * Data provider for test_has_saved_anchor.
	 *
	 * @return array[] Test data.
	 */
	public function data_has_saved_anchor() {
		return array(
			'no anchor'    => array( array(), false ),
			'empty string' => array( array( 'anchor' => '' ), false ),
			'null'         => array( array( 'anchor' => null ), false ),
			'integer'      => array( array( 'anchor' => 123 ), false ),
			'array'        => array( array( 'anchor' => array( 'my-anchor' ) ), false ),
			'string'       => array( array( 'anchor' => 'my-anchor' ), true ),
			'string zero'  => array( array( 'anchor' => '0' ), true ),
		);
	}

	/**
	 * Test a saved anchor is used as is.
	 */
	public function test_saved_anchor_is_used() {
		$this->assertSame(
			'my-anchor',
			get_block_anchor(
				array(
					'html'   => '<p>Hello</p>',
					'anchor' => 'my-anchor',
				)
			)
		);
	}

	/**
	 * Test blocks without a valid saved anchor fall back to a code based anchor.
	 *
	 * @dataProvider data_code_based_anchor_format
	 *
	 * @param array $attributes Block attributes.
	 */
	public function test_code_based_anchor_format( $attributes ) {
		$this->assertMatchesRegularExpression( '/^code-bin-[0-9a-f]{8}$/', get_block_anchor( $attributes ) );
	}

	/**
	 * Data provider for test_code_based_anchor_format.
	 *
	 * @return array[] Test data.
	 */
	public function data_code_based_anchor_format() {
		return array(
			'no anchor'     => array( array( 'html' => '<p>Hello</p>' ) ),
			'empty anchor'  => array(
				array(
					'html'   => '<p>Hello</p>',
					'anchor' => '',
				),
			),
			'integer'       => array(
				array(
					'html'   => '<p>Hello</p>',
					'anchor' => 123,
				),
			),
			'no attributes' => array( array() ),
		);
	}

	/**
	 * Test the code based anchor is the same for identical code.
	 */
	public function test_code_based_anchor_is_stable() {
		$attributes = array(
			'html'       => '<p>Hello</p>',
			'css'        => 'p { color: red; }',
			'javascript' => 'console.log( "Hello" );',
		);

		$this->assertSame( get_block_anchor( $attributes ), get_block_anchor( $attributes ) );
	}

	/**
	 * Test the code based anchor changes when any panel's code changes.
	 *
	 * @dataProvider data_code_based_anchor_changes_with_code
	 *
	 * @param string $panel Panel to change.
	 */
	public function test_code_based_anchor_changes_with_code( $panel ) {
		$attributes = array(
			'html'       => '<p>Hello</p>',
			'css'        => 'p { color: red; }',
			'javascript' => 'console.log( "Hello" );',
		);
		$changed    = $attributes;

		$changed[ $panel ] .= ' ';

		$this->assertNotSame( get_block_anchor( $attributes ), get_block_anchor( $changed ) );
	}

	/**
	 * Data provider for test_code_based_anchor_changes_with_code.
	 *
	 * @return array[] Test data.
	 */
	public function data_code_based_anchor_changes_with_code() {
		return array(
			'html'       => array( 'html' ),
			'css'        => array( 'css' ),
			'javascript' => array( 'javascript' ),
		);
	}

	/**
	 * Test the code based anchor changes when code moves to another panel.
	 */
	public function test_code_based_anchor_depends_on_panel() {
		$this->assertNotSame(
			get_block_anchor( array( 'html' => 'abc' ) ),
			get_block_anchor( array( 'css' => 'abc' ) )
		);
	}

	/**
	 * Test the code based anchor treats missing code and empty code the same.
	 */
	public function test_code_based_anchor_missing_code_is_empty() {
		$this->assertSame(
			get_block_anchor( array( 'html' => '<p>Hello</p>' ) ),
			get_block_anchor(
				array(
					'html'       => '<p>Hello</p>',
					'css'        => '',
					'javascript' => '',
				)
			)
		);
	}

	/**
	 * Test the code based anchor ignores attributes other than the code.
	 */
	public function test_code_based_anchor_ignores_other_attributes() {
		$this->assertSame(
			get_block_anchor( array( 'html' => '<p>Hello</p>' ) ),
			get_block_anchor(
				array(
					'html'          => '<p>Hello</p>',
					'editable'      => false,
					'previewHeight' => 500,
					'align'         => 'wide',
				)
			)
		);
	}

	/**
	 * Test an anchor can be reserved the first time it's used.
	 */
	public function test_reserve_block_anchor_first_use() {
		$this->assertTrue( reserve_block_anchor( 'code-bin-test' ) );
	}

	/**
	 * Test an anchor can't be reserved a second time.
	 */
	public function test_reserve_block_anchor_second_use() {
		$this->assertTrue( reserve_block_anchor( 'code-bin-test' ), 'The first use should reserve the anchor.' );
		$this->assertFalse( reserve_block_anchor( 'code-bin-test' ), 'The second use should not reserve the anchor.' );
	}

	/**
	 * Test reserving an anchor doesn't affect other anchors.
	 */
	public function test_reserve_block_anchor_is_per_anchor() {
		$this->assertTrue( reserve_block_anchor( 'code-bin-test' ), 'The first anchor should be reserved.' );
		$this->assertTrue( reserve_block_anchor( 'code-bin-other' ), 'A different anchor should be reserved.' );
	}

	/**
	 * Test a block without a saved anchor gets its code based anchor.
	 */
	public function test_block_gets_code_based_anchor() {
		$attributes = array( 'html' => '<p>Hello</p>' );

		$this->assertSame( array( get_block_anchor( $attributes ) ), $this->get_wrapper_ids( $this->render( $attributes ) ) );
	}

	/**
	 * Test only the first of several blocks with identical code gets the code based anchor.
	 */
	public function test_identical_code_only_first_block_gets_anchor() {
		$attributes = array( 'html' => '<p>Hello</p>' );
		$html       = $this->render( $attributes ) . $this->render( $attributes );

		$this->assertSame( array( get_block_anchor( $attributes ), null ), $this->get_wrapper_ids( $html ) );
	}

	/**
	 * Test blocks with different code each get their own code based anchor.
	 */
	public function test_different_code_each_block_gets_anchor() {
		$first  = array( 'html' => '<p>Hello</p>' );
		$second = array( 'html' => '<p>Goodbye</p>' );
		$html   = $this->render( $first ) . $this->render( $second );

		$this->assertSame( array( get_block_anchor( $first ), get_block_anchor( $second ) ), $this->get_wrapper_ids( $html ) );
	}

	/**
	 * Test a saved anchor is output once, by block supports.
	 */
	public function test_saved_anchor_is_output_once() {
		$html = $this->render(
			array(
				'html'   => '<p>Hello</p>',
				'anchor' => 'my-anchor',
			)
		);

		$this->assertSame( array( 'my-anchor' ), $this->get_wrapper_ids( $html ) );
		$this->assertSame( 1, substr_count( $html, 'id="my-anchor"' ) );
	}

	/**
	 * Test a saved anchor doesn't reserve the block's code based anchor.
	 *
	 * A block with a saved anchor followed by an identical block without one:
	 * the second block should get the code based anchor.
	 */
	public function test_saved_anchor_does_not_reserve_code_based_anchor() {
		$saved     = array(
			'html'   => '<p>Hello</p>',
			'anchor' => 'my-anchor',
		);
		$code_only = array( 'html' => '<p>Hello</p>' );
		$html      = $this->render( $saved ) . $this->render( $code_only );

		$this->assertSame( array( 'my-anchor', get_block_anchor( $code_only ) ), $this->get_wrapper_ids( $html ) );
	}
}
