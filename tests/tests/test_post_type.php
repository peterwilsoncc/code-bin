<?php
/**
 * Test the Code Bin post type, block registration and editor filters.
 *
 * @package CodeBin
 */

namespace CodeBin\Tests;

use WP_Block_Editor_Context;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

use function CodeBin\filter_allowed_block_types;
use function CodeBin\filter_block_editor_settings;

use const CodeBin\BLOCK_NAME;
use const CodeBin\POST_TYPE;

/**
 * Test the Code Bin post type, block registration and editor filters.
 */
class Test_Post_Type extends WP_UnitTestCase {

	/**
	 * Get a block editor context.
	 *
	 * @param string|null $post_type Post type of the post being edited, null for no post (eg the site editor).
	 * @return WP_Block_Editor_Context Block editor context.
	 */
	private function get_context( $post_type ) {
		if ( null === $post_type ) {
			return new WP_Block_Editor_Context( array( 'name' => 'core/edit-site' ) );
		}

		return new WP_Block_Editor_Context( array( 'post' => get_post( self::factory()->post->create( array( 'post_type' => $post_type ) ) ) ) );
	}

	/**
	 * Test the post type is registered.
	 */
	public function test_post_type_is_registered() {
		$this->assertTrue( post_type_exists( POST_TYPE ) );
	}

	/**
	 * Test the post type's settings.
	 */
	public function test_post_type_settings() {
		$post_type = get_post_type_object( POST_TYPE );

		$this->assertTrue( $post_type->public, 'The post type should be public.' );
		$this->assertTrue( $post_type->has_archive, 'The post type should have an archive.' );
		$this->assertSame( 'code-bins', $post_type->rewrite['slug'], 'The post type should use the plural rewrite slug.' );
		$this->assertTrue( $post_type->show_in_rest, 'The post type should be in the REST API for the block editor.' );
		$this->assertSame( 'code-bins', $post_type->rest_base, 'The post type should use the plural REST base.' );
		$this->assertSame( 'dashicons-editor-code', $post_type->menu_icon, 'The post type should use the code icon.' );
	}

	/**
	 * Test the post type's labels.
	 */
	public function test_post_type_labels() {
		$labels = get_post_type_object( POST_TYPE )->labels;

		$this->assertSame( 'Code Bins', $labels->name );
		$this->assertSame( 'Code Bin', $labels->singular_name );
		$this->assertSame( 'Add New Code Bin', $labels->add_new_item );
	}

	/**
	 * Test the post type supports features.
	 *
	 * @dataProvider data_post_type_supports
	 *
	 * @param string $feature  Feature.
	 * @param bool   $expected Whether the feature is expected to be supported.
	 */
	public function test_post_type_supports( $feature, $expected ) {
		$this->assertSame( $expected, post_type_supports( POST_TYPE, $feature ) );
	}

	/**
	 * Data provider for test_post_type_supports.
	 *
	 * @return array[] Test data.
	 */
	public function data_post_type_supports() {
		return array(
			'title'         => array( 'title', true ),
			'editor'        => array( 'editor', true ),
			'author'        => array( 'author', true ),
			'revisions'     => array( 'revisions', true ),
			'comments'      => array( 'comments', false ),
			'thumbnail'     => array( 'thumbnail', false ),
			'excerpt'       => array( 'excerpt', false ),
			'custom-fields' => array( 'custom-fields', true ),
		);
	}

	/**
	 * Test the post type's template is a single, locked Code Bin block.
	 */
	public function test_post_type_template() {
		$post_type = get_post_type_object( POST_TYPE );

		$this->assertSame( array( array( BLOCK_NAME ) ), $post_type->template, 'The template should be a single Code Bin block.' );
		$this->assertSame( 'all', $post_type->template_lock, 'The template should be locked.' );
	}

	/**
	 * Test the post type's REST route is registered.
	 */
	public function test_post_type_rest_route() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/wp/v2/code-bins', $routes );
		$this->assertArrayHasKey( '/wp/v2/code-bins/(?P<id>[\d]+)', $routes );
	}

	/**
	 * Test the block is registered.
	 */
	public function test_block_is_registered() {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( BLOCK_NAME );

		$this->assertNotNull( $block_type, 'The block should be registered.' );
		$this->assertTrue( $block_type->is_dynamic(), 'The block should be rendered on the server.' );
		$this->assertNotEmpty( $block_type->editor_script_handles, 'The block should have an editor script.' );
		$this->assertNotEmpty( $block_type->view_script_handles, 'The block should have a view script.' );
		$this->assertNotEmpty( $block_type->style_handles, 'The block should have a style.' );
	}

	/**
	 * Test the block's attributes.
	 *
	 * @dataProvider data_block_attributes
	 *
	 * @param string $attribute Attribute name.
	 * @param string $type      Expected type.
	 * @param mixed  $expected  Expected default.
	 */
	public function test_block_attributes( $attribute, $type, $expected ) {
		$attributes = WP_Block_Type_Registry::get_instance()->get_registered( BLOCK_NAME )->attributes;

		$this->assertArrayHasKey( $attribute, $attributes, 'The attribute should be registered.' );
		$this->assertSame( $type, $attributes[ $attribute ]['type'], 'The attribute type is incorrect.' );
		$this->assertSame( $expected, $attributes[ $attribute ]['default'], 'The attribute default is incorrect.' );
	}

	/**
	 * Data provider for test_block_attributes.
	 *
	 * @return array[] Test data.
	 */
	public function data_block_attributes() {
		return array(
			'html'          => array( 'html', 'string', '' ),
			'css'           => array( 'css', 'string', '' ),
			'javascript'    => array( 'javascript', 'string', '' ),
			'editable'      => array( 'editable', 'boolean', true ),
			'previewHeight' => array( 'previewHeight', 'number', 300 ),
		);
	}

	/**
	 * Test the block's supports.
	 */
	public function test_block_supports() {
		$supports = WP_Block_Type_Registry::get_instance()->get_registered( BLOCK_NAME )->supports;

		$this->assertFalse( $supports['html'], 'Editing the block as HTML should be disabled.' );
		$this->assertTrue( $supports['anchor'], 'The block should support anchors.' );
		$this->assertSame( array( 'wide', 'full' ), $supports['align'], 'The block should support wide and full alignment.' );
	}

	/**
	 * Test only the Code Bin block is allowed when editing a code bin.
	 *
	 * @dataProvider data_allowed_block_types
	 *
	 * @param bool|string[] $allowed Allowed block types before filtering.
	 */
	public function test_allowed_block_types_for_code_bins( $allowed ) {
		$this->assertSame( array( BLOCK_NAME ), filter_allowed_block_types( $allowed, $this->get_context( POST_TYPE ) ) );
	}

	/**
	 * Test the allowed blocks are unchanged for other post types.
	 *
	 * @dataProvider data_allowed_block_types
	 *
	 * @param bool|string[] $allowed Allowed block types before filtering.
	 */
	public function test_allowed_block_types_for_other_post_types( $allowed ) {
		$this->assertSame( $allowed, filter_allowed_block_types( $allowed, $this->get_context( 'post' ) ) );
	}

	/**
	 * Test the allowed blocks are unchanged in editors without a post, eg the site editor.
	 *
	 * @dataProvider data_allowed_block_types
	 *
	 * @param bool|string[] $allowed Allowed block types before filtering.
	 */
	public function test_allowed_block_types_without_post( $allowed ) {
		$this->assertSame( $allowed, filter_allowed_block_types( $allowed, $this->get_context( null ) ) );
	}

	/**
	 * Data provider for the allowed block types tests.
	 *
	 * @return array[] Test data.
	 */
	public function data_allowed_block_types() {
		return array(
			'all blocks'  => array( true ),
			'no blocks'   => array( false ),
			'some blocks' => array( array( 'core/paragraph', 'core/heading' ) ),
		);
	}

	/**
	 * Test WordPress applies the allowed block types filter.
	 */
	public function test_get_allowed_block_types() {
		$this->assertSame( array( BLOCK_NAME ), get_allowed_block_types( $this->get_context( POST_TYPE ) ), 'Code bins should only allow the Code Bin block.' );
		$this->assertTrue( get_allowed_block_types( $this->get_context( 'post' ) ), 'Posts should allow all blocks.' );
	}

	/**
	 * Test block locking and the code editor are disabled when editing a code bin.
	 */
	public function test_editor_settings_for_code_bins() {
		$settings = array(
			'canLockBlocks'      => true,
			'codeEditingEnabled' => true,
			'titlePlaceholder'   => 'Add title',
		);

		$this->assertSame(
			array(
				'canLockBlocks'      => false,
				'codeEditingEnabled' => false,
				'titlePlaceholder'   => 'Add title',
			),
			filter_block_editor_settings( $settings, $this->get_context( POST_TYPE ) )
		);
	}

	/**
	 * Test the editor settings are unchanged for other post types and editors without a post.
	 *
	 * @dataProvider data_editor_settings_unchanged
	 *
	 * @param string|null $post_type Post type, null for no post.
	 */
	public function test_editor_settings_unchanged( $post_type ) {
		$settings = array(
			'canLockBlocks'      => true,
			'codeEditingEnabled' => true,
		);

		$this->assertSame( $settings, filter_block_editor_settings( $settings, $this->get_context( $post_type ) ) );
	}

	/**
	 * Data provider for test_editor_settings_unchanged.
	 *
	 * @return array[] Test data.
	 */
	public function data_editor_settings_unchanged() {
		return array(
			'post'    => array( 'post' ),
			'page'    => array( 'page' ),
			'no post' => array( null ),
		);
	}

	/**
	 * Test WordPress applies the editor settings filter.
	 */
	public function test_get_block_editor_settings() {
		// Core doesn't always set these, the editor defaults to allowing them.
		$custom   = array(
			'canLockBlocks'      => true,
			'codeEditingEnabled' => true,
		);
		$code_bin = get_block_editor_settings( $custom, $this->get_context( POST_TYPE ) );
		$post     = get_block_editor_settings( $custom, $this->get_context( 'post' ) );

		$this->assertFalse( $code_bin['canLockBlocks'], 'Code bins should not allow block locking.' );
		$this->assertFalse( $code_bin['codeEditingEnabled'], 'Code bins should not allow the code editor.' );
		$this->assertTrue( $post['canLockBlocks'], 'Posts should allow block locking.' );
		$this->assertTrue( $post['codeEditingEnabled'], 'Posts should allow the code editor.' );
	}
}
