<?php
/**
 * Migrate Code Bin blocks from storing code in attributes to post meta.
 *
 * Used by the `wp code-bin migrate` command, see class-cli-command.php. Blocks
 * that haven't been migrated keep working: the code in their attributes is
 * used until they're migrated, see get_block_code(). The editor also migrates
 * blocks when a post is edited.
 *
 * @package CodeBin
 */

namespace CodeBin;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Entities that kses adds to code when it's saved by a user without the
 * `unfiltered_html` capability. Used to report code that may be damaged.
 */
const DAMAGED_CODE_PATTERN = '/&(amp|lt|gt|quot);/';

/**
 * Get the IDs of the posts containing Code Bin blocks with code to migrate.
 *
 * @param array $args {
 *     Optional. Arguments.
 *
 *     @type string[] $post_types Post types to search. Default all post types storing code in meta.
 *     @type int[]    $include    Only search these post IDs. Default all posts.
 * }
 * @return int[] Post IDs.
 */
function get_posts_to_migrate( $args = array() ) {
	global $wpdb;

	$post_types = $args['post_types'] ?? get_post_types();
	$post_types = array_values( array_filter( (array) $post_types, __NAMESPACE__ . '\\post_type_supports_code_meta' ) );
	if ( ! $post_types ) {
		return array();
	}

	// Blocks with code in their attributes, eg `<!-- wp:code-bin-block/code-bin-block {"html":"...`.
	$like = '%' . $wpdb->esc_like( '<!-- wp:' . BLOCK_NAME . ' {' ) . '%';

	$where  = $wpdb->prepare( 'post_content LIKE %s', $like );
	$where .= ' AND post_type IN (' . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
	$where  = $wpdb->prepare( $where, $post_types ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
	$where .= " AND post_status NOT IN ( 'auto-draft', 'inherit' )";

	if ( ! empty( $args['include'] ) ) {
		$include = array_map( 'absint', (array) $args['include'] );
		$where  .= ' AND ID IN (' . implode( ', ', $include ) . ')';
	}

	/*
	 * The query also matches migrated blocks, which have other attributes, so
	 * the content is checked too. In batches, to limit memory use.
	 */
	$post_ids   = array();
	$last_id    = 0;
	$batch_size = 100;
	do {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above, one off migration.
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE {$where} AND ID > %d ORDER BY ID ASC LIMIT %d", $last_id, $batch_size ) );
		$row_count = count( $rows );
		foreach ( $rows as $row ) {
			$last_id = (int) $row->ID;
			if ( count_blocks_to_migrate( parse_blocks( $row->post_content ) ) ) {
				$post_ids[] = $last_id;
			}
		}
	} while ( $row_count === $batch_size );

	return $post_ids;
}

/**
 * Count the Code Bin blocks with code in their attributes, including inner blocks.
 *
 * @param array $blocks Parsed blocks.
 * @return int Number of blocks.
 */
function count_blocks_to_migrate( $blocks ) {
	$count = 0;
	foreach ( $blocks as $block ) {
		if ( BLOCK_NAME === $block['blockName'] && attributes_have_code( $block['attrs'] ) ) {
			++$count;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$count += count_blocks_to_migrate( $block['innerBlocks'] );
		}
	}

	return $count;
}

/**
 * Whether a block's attributes contain code.
 *
 * @param array $attributes Block attributes.
 * @return bool Whether the attributes contain any of the code panels.
 */
function attributes_have_code( $attributes ) {
	foreach ( CODE_PANELS as $panel ) {
		if ( array_key_exists( $panel, $attributes ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Move the code of Code Bin blocks from their attributes to a code meta map.
 *
 * Walks inner blocks too. Blocks without a valid code ID, or with one already
 * used by another block in the post, get a new code ID.
 *
 * If the meta already has code for a block's code ID, eg from an interrupted
 * migration or the editor, the meta is kept and the attributes discarded: the
 * meta is the code shown on the site.
 *
 * @param array $blocks  Parsed blocks, changed in place.
 * @param array $meta    Code meta, keyed by code ID, changed in place.
 * @param array $used    Code IDs already used by blocks in the post, changed in place.
 * @param array $results Results, see migrate_post(), changed in place.
 */
function migrate_blocks( &$blocks, &$meta, &$used, &$results ) {
	foreach ( $blocks as &$block ) {
		if ( ! empty( $block['innerBlocks'] ) ) {
			migrate_blocks( $block['innerBlocks'], $meta, $used, $results );
		}

		if ( BLOCK_NAME !== $block['blockName'] || ! attributes_have_code( $block['attrs'] ) ) {
			continue;
		}

		$code = array();
		foreach ( CODE_PANELS as $panel ) {
			$code[ $panel ] = isset( $block['attrs'][ $panel ] ) && is_scalar( $block['attrs'][ $panel ] ) ? (string) $block['attrs'][ $panel ] : '';
			unset( $block['attrs'][ $panel ] );
		}

		$code_id = $block['attrs']['codeId'] ?? '';
		if ( ! is_valid_code_id( $code_id ) || isset( $used[ $code_id ] ) ) {
			do {
				$code_id = generate_code_id();
			} while ( isset( $used[ $code_id ] ) || isset( $meta[ $code_id ] ) );
		}
		$code_id                  = (string) $code_id;
		$used[ $code_id ]         = true;
		$block['attrs']['codeId'] = $code_id;

		if ( ! isset( $meta[ $code_id ] ) ) {
			$meta[ $code_id ] = $code;

			foreach ( array( 'css', 'javascript' ) as $panel ) {
				if ( preg_match( DAMAGED_CODE_PATTERN, $code[ $panel ] ) ) {
					$results['damaged'][] = $code_id;
					break;
				}
			}
		}

		++$results['blocks'];
	}
	unset( $block );
}

/**
 * Migrate the Code Bin blocks in a post to store their code in meta.
 *
 * The meta is saved before the post, so if the post fails to save the blocks
 * still show the right code: the meta is used, the attributes ignored.
 *
 * The post is saved with kses disabled, as WP-CLI runs as a user without the
 * `unfiltered_html` capability. The content is the same as before apart from
 * the code being removed from the blocks' attributes.
 *
 * @param int   $post_id Post ID.
 * @param array $args {
 *     Optional. Arguments.
 *
 *     @type bool $dry_run           Find the blocks to migrate without saving. Default false.
 *     @type bool $preserve_modified Keep the post's modified date. Default false.
 * }
 * @return array|WP_Error {
 *     Results, or an error if the post can't be migrated.
 *
 *     @type int      $blocks  Number of blocks migrated.
 *     @type string[] $damaged Code IDs of blocks whose CSS or JavaScript contains
 *                             entities, which kses may have added.
 * }
 */
function migrate_post( $post_id, $args = array() ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'code_bin_post_not_found', __( 'Post not found.', 'code-bin' ) );
	}

	if ( ! post_type_supports_code_meta( $post->post_type ) ) {
		return new WP_Error(
			'code_bin_unsupported_post_type',
			/* translators: %s: Post type. */
			sprintf( __( 'The %s post type does not store code in meta: it must support custom fields and show in the REST API.', 'code-bin' ), $post->post_type )
		);
	}

	$results = array(
		'blocks'  => 0,
		'damaged' => array(),
	);

	$blocks = parse_blocks( $post->post_content );
	$meta   = get_code_meta( $post->ID );

	// Code IDs of blocks that are already migrated: blocks being migrated can't reuse them.
	$used = array_fill_keys( find_migrated_code_ids( $blocks ), true );

	migrate_blocks( $blocks, $meta, $used, $results );

	if ( ! $results['blocks'] || ! empty( $args['dry_run'] ) ) {
		return $results;
	}

	update_post_meta( $post->ID, META_KEY, wp_slash( $meta ) );

	$preserve_modified = static function ( $data, $postarr ) use ( $post ) {
		if ( (int) $postarr['ID'] === $post->ID ) {
			$data['post_modified']     = $post->post_modified;
			$data['post_modified_gmt'] = $post->post_modified_gmt;
		}
		return $data;
	};
	if ( ! empty( $args['preserve_modified'] ) ) {
		add_filter( 'wp_insert_post_data', $preserve_modified, 10, 2 );
	}

	$has_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
	kses_remove_filters();

	$updated = wp_update_post(
		wp_slash(
			array(
				'ID'           => $post->ID,
				'post_content' => serialize_blocks( $blocks ),
			)
		),
		true
	);

	if ( $has_kses ) {
		kses_init_filters();
	}
	remove_filter( 'wp_insert_post_data', $preserve_modified, 10 );

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	return $results;
}

/**
 * Find the code IDs of migrated Code Bin blocks: those without code in their attributes.
 *
 * @param array $blocks Parsed blocks.
 * @return string[] Code IDs.
 */
function find_migrated_code_ids( $blocks ) {
	$code_ids = array();
	foreach ( $blocks as $block ) {
		if ( BLOCK_NAME === $block['blockName'] && ! attributes_have_code( $block['attrs'] ) && is_valid_code_id( $block['attrs']['codeId'] ?? '' ) ) {
			$code_ids[] = (string) $block['attrs']['codeId'];
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$code_ids = array_merge( $code_ids, find_migrated_code_ids( $block['innerBlocks'] ) );
		}
	}

	return $code_ids;
}
