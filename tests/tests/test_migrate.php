<?php
/**
 * Test migrating Code Bin blocks from attributes to post meta.
 *
 * @package CodeBin
 */

namespace CodeBin\Tests;

use WP_UnitTestCase;

use function CodeBin\get_posts_to_migrate;
use function CodeBin\migrate_post;

use const CodeBin\BLOCK_NAME;
use const CodeBin\META_KEY;
use const CodeBin\POST_TYPE;

/**
 * Test migrating Code Bin blocks from attributes to post meta.
 */
class Test_Migrate extends WP_UnitTestCase {

	/**
	 * Code that kses changes when saved by a user without `unfiltered_html`.
	 */
	const CODE = array(
		'html'       => '<p>Tom & Jerry</p><button onclick="go()">Go</button>',
		'css'        => 'a > b { content: "&"; }',
		'javascript' => 'if ( a < b && c > d ) {}',
	);

	/**
	 * Serialize a Code Bin block.
	 *
	 * @param array $attributes   Block attributes.
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
	 * Create a post, bypassing kses.
	 *
	 * @param string $content   Post content.
	 * @param string $post_type Post type.
	 * @param array  $post_arr  Additional post fields.
	 * @return int Post ID.
	 */
	private function create_post( $content, $post_type = POST_TYPE, $post_arr = array() ) {
		kses_remove_filters();
		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => $post_type,
					'post_content' => wp_slash( $content ),
				),
				$post_arr
			)
		);
		kses_init_filters();

		return $post_id;
	}

	/**
	 * Get the Code Bin blocks' attributes from a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Attributes of each Code Bin block, including inner blocks.
	 */
	private function get_attributes( $post_id ) {
		$attributes = array();
		$walk       = static function ( $blocks ) use ( &$walk, &$attributes ) {
			foreach ( $blocks as $block ) {
				if ( BLOCK_NAME === $block['blockName'] ) {
					$attributes[] = $block['attrs'];
				}
				$walk( $block['innerBlocks'] );
			}
		};
		$walk( parse_blocks( get_post_field( 'post_content', $post_id, 'raw' ) ) );

		return $attributes;
	}

	/**
	 * Test a block's code is moved to meta.
	 */
	public function test_migrate_post() {
		$post_id = $this->create_post( $this->block( array_merge( self::CODE, array( 'previewHeight' => 400 ) ) ) );

		$result = migrate_post( $post_id );

		$this->assertSame( 1, $result['blocks'] );
		$attributes = $this->get_attributes( $post_id );
		$this->assertCount( 1, $attributes );
		$this->assertArrayNotHasKey( 'html', $attributes[0], 'The code should be removed from the attributes.' );
		$this->assertSame( 400, $attributes[0]['previewHeight'], 'Other attributes should be kept.' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{12}$/', $attributes[0]['codeId'] );
		$this->assertSame( array( $attributes[0]['codeId'] => self::CODE ), get_post_meta( $post_id, META_KEY, true ) );
	}

	/**
	 * Test code isn't filtered by kses when migrating as a user without `unfiltered_html`.
	 */
	public function test_migrate_post_without_unfiltered_html() {
		$post_id = $this->create_post( $this->block( self::CODE ) );
		wp_set_current_user( 0 );
		kses_init();
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'kses should be active, as in WP-CLI.' );

		migrate_post( $post_id );

		$this->assertSame( array( self::CODE ), array_values( get_post_meta( $post_id, META_KEY, true ) ) );
		$this->assertStringNotContainsString( '&amp;', get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'kses should be restored.' );
		kses_remove_filters();
	}

	/**
	 * Test nested blocks and multiple blocks are migrated with unique code IDs.
	 */
	public function test_migrate_post_nested_and_duplicate_ids() {
		$content  = $this->block(
			array(
				'html'   => 'first',
				'codeId' => 'samecode',
			)
		);
		$content .= '<!-- wp:group --><div class="wp-block-group">';
		$content .= $this->block(
			array(
				'html'   => 'nested',
				'codeId' => 'samecode',
			)
		);
		$content .= '</div><!-- /wp:group -->';
		$post_id  = $this->create_post( $content );

		$this->assertSame( 2, migrate_post( $post_id )['blocks'] );

		$code_ids = wp_list_pluck( $this->get_attributes( $post_id ), 'codeId' );
		$this->assertCount( 2, array_unique( $code_ids ), 'Each block should have its own code ID.' );
		$meta = get_post_meta( $post_id, META_KEY, true );
		$this->assertSame( 'first', $meta[ $code_ids[0] ]['html'] );
		$this->assertSame( 'nested', $meta[ $code_ids[1] ]['html'] );
	}

	/**
	 * Test already migrated blocks are left alone, and their code IDs not reused.
	 */
	public function test_migrate_post_partially_migrated() {
		$content  = $this->block( array( 'codeId' => 'migrated' ) );
		$content .= $this->block(
			array(
				'codeId' => 'migrated',
				'html'   => 'copy',
			)
		);
		$post_id  = $this->create_post( $content );
		update_post_meta( $post_id, META_KEY, array( 'migrated' => array_merge( self::CODE, array( 'html' => 'original' ) ) ) );

		$this->assertSame( 1, migrate_post( $post_id )['blocks'] );

		$code_ids = wp_list_pluck( $this->get_attributes( $post_id ), 'codeId' );
		$this->assertSame( 'migrated', $code_ids[0] );
		$this->assertNotSame( 'migrated', $code_ids[1] );
		$meta = get_post_meta( $post_id, META_KEY, true );
		$this->assertSame( 'original', $meta['migrated']['html'] );
		$this->assertSame( 'copy', $meta[ $code_ids[1] ]['html'] );
	}

	/**
	 * Test meta is kept when a block has code in both its attributes and meta.
	 */
	public function test_migrate_post_meta_takes_precedence() {
		$post_id = $this->create_post(
			$this->block(
				array(
					'codeId' => 'abc123',
					'html'   => 'attribute',
				)
			)
		);
		update_post_meta( $post_id, META_KEY, array( 'abc123' => array_merge( self::CODE, array( 'html' => 'meta' ) ) ) );

		migrate_post( $post_id );

		$this->assertSame( 'meta', get_post_meta( $post_id, META_KEY, true )['abc123']['html'] );
		$this->assertArrayNotHasKey( 'html', $this->get_attributes( $post_id )[0] );
	}

	/**
	 * Test a dry run changes nothing.
	 */
	public function test_migrate_post_dry_run() {
		$content = $this->block( self::CODE );
		$post_id = $this->create_post( $content );

		$result = migrate_post( $post_id, array( 'dry_run' => true ) );

		$this->assertSame( 1, $result['blocks'] );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, META_KEY ) );
	}

	/**
	 * Test migrating twice changes nothing the second time.
	 */
	public function test_migrate_post_is_idempotent() {
		$post_id = $this->create_post( $this->block( self::CODE ) );
		migrate_post( $post_id );
		$content = get_post_field( 'post_content', $post_id, 'raw' );

		$this->assertSame( 0, migrate_post( $post_id )['blocks'] );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'raw' ) );
		$this->assertSame( array(), get_posts_to_migrate( array( 'include' => array( $post_id ) ) ) );
	}

	/**
	 * Test the modified date can be kept.
	 */
	public function test_migrate_post_preserve_modified() {
		$post_id = $this->create_post(
			$this->block( self::CODE ),
			POST_TYPE,
			array(
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			)
		);
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => '2020-01-02 00:00:00',
				'post_modified_gmt' => '2020-01-02 00:00:00',
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		migrate_post( $post_id, array( 'preserve_modified' => true ) );

		$this->assertSame( '2020-01-02 00:00:00', get_post_field( 'post_modified_gmt', $post_id ) );
	}

	/**
	 * Test code that kses may have damaged is reported.
	 */
	public function test_migrate_post_reports_damaged_code() {
		$content  = $this->block(
			array(
				'javascript' => 'if ( a &amp;&amp; b ) {}',
				'codeId'     => 'damaged1',
			)
		);
		$content .= $this->block(
			array(
				'html'   => '<p>Tom &amp; Jerry</p>',
				'codeId' => 'htmlonly',
			)
		);
		$post_id  = $this->create_post( $content );

		$this->assertSame( array( 'damaged1' ), migrate_post( $post_id )['damaged'], 'Only CSS and JavaScript are checked: entities are valid HTML.' );
	}

	/**
	 * Test unsupported post types can't be migrated.
	 */
	public function test_migrate_post_unsupported_post_type() {
		$post_id = $this->create_post( $this->block( self::CODE ), 'wp_block' );

		$this->assertWPError( migrate_post( $post_id ) );
	}

	/**
	 * Test finding the posts to migrate.
	 */
	public function test_get_posts_to_migrate() {
		$code_bin = $this->create_post( $this->block( self::CODE ) );
		$post     = $this->create_post( $this->block( self::CODE ), 'post' );
		$this->create_post( $this->block( self::CODE ), 'wp_block' );
		$this->create_post( $this->block( array() ) );
		$this->create_post( $this->block( self::CODE ), POST_TYPE, array( 'post_status' => 'auto-draft' ) );
		$this->create_post( '<!-- wp:paragraph --><p>No code bins</p><!-- /wp:paragraph -->' );

		$this->assertSame( array( $code_bin, $post ), get_posts_to_migrate() );
		$this->assertSame( array( $post ), get_posts_to_migrate( array( 'post_types' => array( 'post' ) ) ) );
		$this->assertSame( array( $code_bin ), get_posts_to_migrate( array( 'include' => array( $code_bin ) ) ) );
		$this->assertSame( array(), get_posts_to_migrate( array( 'post_types' => array( 'wp_block' ) ) ) );
	}
}
