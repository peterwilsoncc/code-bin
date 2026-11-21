<?php
/**
 * WP-CLI commands for Code Bin.
 *
 * @package CodeBin
 */

namespace CodeBin;

use WP_CLI;
use WP_CLI_Command;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Code Bin blocks.
 */
class CLI_Command extends WP_CLI_Command {

	/**
	 * Move the code of Code Bin blocks from block attributes to post meta.
	 *
	 * Code Bin blocks used to store their code in the block's attributes, which
	 * WordPress filters with kses when a user without the `unfiltered_html`
	 * capability saves the post. Code is now stored in post meta. Blocks that
	 * haven't been migrated keep working, and are migrated when their post is
	 * edited. This command migrates them all at once.
	 *
	 * Only post types that support custom fields and show in the REST API are
	 * migrated. Blocks in other post types, eg synced patterns, keep their code
	 * in their attributes.
	 *
	 * Code already damaged by kses can't be repaired. Blocks whose CSS or
	 * JavaScript contains `&amp;`, `&lt;`, `&gt;` or `&quot;`, which kses adds,
	 * are listed for review.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post-types>]
	 * : Comma separated post types to migrate. Default all post types storing code in meta.
	 *
	 * [--include=<ids>]
	 * : Comma separated IDs of the posts to migrate. Default all posts.
	 *
	 * [--dry-run]
	 * : List the posts and blocks to migrate without changing anything.
	 *
	 * [--preserve-modified]
	 * : Keep the posts' modified dates.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what would be migrated.
	 *     $ wp code-bin migrate --dry-run
	 *
	 *     # Migrate every post.
	 *     $ wp code-bin migrate
	 *
	 *     # Migrate code bins only, keeping their modified dates.
	 *     $ wp code-bin migrate --post_type=code_bin --preserve-modified
	 *
	 *     # Migrate every site in a multisite network.
	 *     $ wp site list --field=url | xargs -I % wp --url=% code-bin migrate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function migrate( $args, $assoc_args ) {
		$dry_run           = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$preserve_modified = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'preserve-modified', false );

		$query = array();
		if ( isset( $assoc_args['post_type'] ) ) {
			$query['post_types'] = array_filter( array_map( 'trim', explode( ',', $assoc_args['post_type'] ) ) );
			foreach ( $query['post_types'] as $post_type ) {
				if ( ! post_type_supports_code_meta( $post_type ) ) {
					WP_CLI::error( sprintf( 'The %s post type does not store code in meta: it must exist, support custom fields and show in the REST API.', $post_type ) );
				}
			}
		}
		if ( isset( $assoc_args['include'] ) ) {
			$query['include'] = array_filter( array_map( 'absint', explode( ',', $assoc_args['include'] ) ) );
		}

		$post_ids = get_posts_to_migrate( $query );
		if ( ! $post_ids ) {
			WP_CLI::success( 'No Code Bin blocks to migrate.' );
			return;
		}

		$posts   = 0;
		$blocks  = 0;
		$errors  = 0;
		$damaged = array();

		foreach ( $post_ids as $post_id ) {
			$result = migrate_post(
				$post_id,
				array(
					'dry_run'           => $dry_run,
					'preserve_modified' => $preserve_modified,
				)
			);

			if ( is_wp_error( $result ) ) {
				++$errors;
				WP_CLI::warning( sprintf( 'Post %d: %s', $post_id, $result->get_error_message() ) );
				continue;
			}

			if ( ! $result['blocks'] ) {
				continue;
			}

			++$posts;
			$blocks += $result['blocks'];
			WP_CLI::log(
				sprintf(
					'%s post %d: %d %s.',
					$dry_run ? 'Would migrate' : 'Migrated',
					$post_id,
					$result['blocks'],
					1 === $result['blocks'] ? 'block' : 'blocks'
				)
			);

			if ( $result['damaged'] ) {
				$damaged[ $post_id ] = $result['damaged'];
			}

			// Long runs would otherwise keep every post in memory.
			wp_cache_flush_runtime();
		}

		if ( $damaged ) {
			WP_CLI::warning( 'The CSS or JavaScript of these blocks contains HTML entities, which kses adds when users without the unfiltered_html capability save code. Check the code is correct:' );
			$rows = array();
			foreach ( $damaged as $post_id => $code_ids ) {
				foreach ( $code_ids as $code_id ) {
					$rows[] = array(
						'post_id'   => $post_id,
						'code_id'   => $code_id,
						'edit_link' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
					);
				}
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'post_id', 'code_id', 'edit_link' ) );
		}

		$summary = sprintf(
			'%s %d %s in %d %s.',
			$dry_run ? 'Would migrate' : 'Migrated',
			$blocks,
			1 === $blocks ? 'block' : 'blocks',
			$posts,
			1 === $posts ? 'post' : 'posts'
		);

		if ( $errors ) {
			WP_CLI::error( sprintf( '%s %d %s failed.', $summary, $errors, 1 === $errors ? 'post' : 'posts' ) );
		}

		WP_CLI::success( $summary );
	}
}
