<?php
/**
 * Storage of Code Bin code in post meta.
 *
 * For users without the `unfiltered_html` capability, WordPress filters every
 * string attribute of every block with kses when the post is saved. That
 * breaks code, eg `a && b` is saved as `a &amp;&amp; b`. Post meta isn't
 * filtered with kses, so the code of each block is stored in the post's meta
 * and the block only saves an ID for it, `codeId`.
 *
 * The meta holds a map of code IDs to code, as a post can contain more than one
 * Code Bin block:
 *
 *     array(
 *         'k3x9q2a7bd1f' => array(
 *             'html'       => '<p>Hello</p>',
 *             'css'        => 'p { color: red; }',
 *             'javascript' => 'console.log( 1 && 2 );',
 *         ),
 *     )
 *
 * Blocks without a meta entry use the code in their attributes. This applies
 * to blocks saved before code was stored in meta (see migrate.php) and to
 * blocks where the post has no meta of its own, eg in synced patterns and
 * templates.
 *
 * The code isn't filtered: the sandboxed preview iframe is what protects
 * visitors, and the code is always escaped when output.
 *
 * @package CodeBin
 */

namespace CodeBin;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Meta key for the code of the Code Bin blocks in a post.
 *
 * Protected (starts with `_`) so it's not shown in the Custom Fields panel.
 */
const META_KEY = '_code_bin';

/**
 * The code panels stored for each block.
 */
const CODE_PANELS = array( 'html', 'css', 'javascript' );

/**
 * Pattern for valid code IDs. Keep in sync with use-code-storage.js.
 */
const CODE_ID_PATTERN = '/^[a-z0-9]{1,32}$/';

/**
 * Post types that never store code in meta, even if they support custom fields.
 *
 * Synced patterns render inside other posts, which provide the `postId` block
 * context, so the pattern's own meta can't be found when rendering.
 */
const EXCLUDED_POST_TYPES = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );

/**
 * Bootstrap the code storage.
 */
function bootstrap_meta() {
	add_action( 'init', __NAMESPACE__ . '\\register_code_meta', 20 );
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );
	add_action( 'rest_api_init', __NAMESPACE__ . '\\protect_password_protected_code' );
	add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\prune_code_meta', 10, 2 );
}

/**
 * Whether a post type stores Code Bin code in post meta.
 *
 * The block editor reads and writes the meta through the REST API, which only
 * includes meta for post types supporting custom fields.
 *
 * @param string $post_type Post type.
 * @return bool Whether the post type stores code in meta.
 */
function post_type_supports_code_meta( $post_type ) {
	if ( ! is_string( $post_type ) || in_array( $post_type, EXCLUDED_POST_TYPES, true ) ) {
		return false;
	}

	$post_type_object = get_post_type_object( $post_type );

	return $post_type_object
		&& $post_type_object->show_in_rest
		&& post_type_supports( $post_type, 'custom-fields' );
}

/**
 * Register the meta storing the code.
 *
 * Registered for all post types, as the block can be used in any post type.
 * Runs after post types are registered on `init`.
 */
function register_code_meta() {
	$panel_properties = array();
	foreach ( CODE_PANELS as $panel ) {
		$panel_properties[ $panel ] = array( 'type' => 'string' );
	}

	register_post_meta(
		'',
		META_KEY,
		array(
			'type'              => 'object',
			'description'       => __( 'Code of the Code Bin blocks in the post, keyed by the block\'s code ID.', 'code-bin' ),
			'single'            => true,
			'default'           => array(),
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_code_meta',
			'auth_callback'     => __NAMESPACE__ . '\\can_edit_code_meta',
			'revisions_enabled' => true,
			'show_in_rest'      => array(
				'schema' => array(
					'type'                 => 'object',
					'patternProperties'    => array(
						'^[a-z0-9]{1,32}$' => array(
							'type'                 => 'object',
							'properties'           => $panel_properties,
							'additionalProperties' => false,
						),
					),
					'additionalProperties' => false,
				),
			),
		)
	);
}

/**
 * Sanitize the code meta.
 *
 * Only checks the structure: the code itself is stored exactly as written.
 * Invalid code IDs, unknown panels and non-string code are dropped.
 *
 * @param mixed $value Meta value.
 * @return array Sanitized meta value.
 */
function sanitize_code_meta( $value ) {
	if ( is_object( $value ) ) {
		$value = get_object_vars( $value );
	}

	if ( ! is_array( $value ) ) {
		return array();
	}

	$sanitized = array();
	foreach ( $value as $code_id => $code ) {
		if ( ! is_valid_code_id( $code_id ) ) {
			continue;
		}

		if ( is_object( $code ) ) {
			$code = get_object_vars( $code );
		}

		if ( ! is_array( $code ) ) {
			continue;
		}

		$entry = array();
		foreach ( CODE_PANELS as $panel ) {
			$entry[ $panel ] = isset( $code[ $panel ] ) && is_scalar( $code[ $panel ] ) ? (string) $code[ $panel ] : '';
		}
		$sanitized[ (string) $code_id ] = $entry;
	}

	return $sanitized;
}

/**
 * Whether a code ID is valid.
 *
 * @param mixed $code_id Code ID.
 * @return bool Whether the code ID is valid.
 */
function is_valid_code_id( $code_id ) {
	return ( is_string( $code_id ) || is_int( $code_id ) ) && 1 === preg_match( CODE_ID_PATTERN, (string) $code_id );
}

/**
 * Whether the current user can change a post's code meta.
 *
 * Anyone who can edit the post can change its code, including users without
 * the `unfiltered_html` capability: that's the point of storing it in meta.
 *
 * @param bool   $allowed  Whether the user can change the meta.
 * @param string $meta_key Meta key.
 * @param int    $post_id  Post ID.
 * @return bool Whether the user can change the meta.
 */
function can_edit_code_meta( $allowed, $meta_key, $post_id ) {
	return current_user_can( 'edit_post', $post_id );
}

/**
 * Generate a code ID.
 *
 * Keep the format in sync with generateCodeId() in use-code-storage.js.
 *
 * @return string Code ID.
 */
function generate_code_id() {
	$characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$code_id    = '';
	for ( $i = 0; $i < 12; $i++ ) {
		$code_id .= $characters[ random_int( 0, strlen( $characters ) - 1 ) ];
	}

	return $code_id;
}

/**
 * Get the code meta of a post.
 *
 * @param int $post_id Post ID.
 * @return array Code meta, keyed by code ID.
 */
function get_code_meta( $post_id ) {
	return sanitize_code_meta( get_post_meta( $post_id, META_KEY, true ) );
}

/**
 * Get the code of a Code Bin block.
 *
 * The code is read from the meta of the post the block is in, if the post type
 * stores code in meta and has an entry for the block's code ID. Otherwise it's
 * read from the block's attributes.
 *
 * Code is only ever read from the post the block is rendered in, so a block
 * can't show the code of another post.
 *
 * @param array    $attributes Block attributes.
 * @param int|null $post_id    ID of the post the block is in, from the block's `postId` context.
 * @return array Code, keyed by panel.
 */
function get_block_code( $attributes, $post_id = null ) {
	$code = array();
	foreach ( CODE_PANELS as $panel ) {
		$code[ $panel ] = isset( $attributes[ $panel ] ) ? (string) $attributes[ $panel ] : '';
	}

	$code_id = $attributes['codeId'] ?? '';
	if ( ! $post_id || ! is_valid_code_id( $code_id ) || ! post_type_supports_code_meta( get_post_type( $post_id ) ) ) {
		return $code;
	}

	$meta = get_code_meta( $post_id );
	if ( ! isset( $meta[ $code_id ] ) ) {
		return $code;
	}

	return $meta[ $code_id ];
}

/**
 * Find the code IDs of the Code Bin blocks in some blocks, including inner blocks.
 *
 * @param array $blocks Parsed blocks.
 * @return string[] Code IDs.
 */
function find_code_ids( $blocks ) {
	$code_ids = array();
	foreach ( $blocks as $block ) {
		if ( BLOCK_NAME === $block['blockName'] && is_valid_code_id( $block['attrs']['codeId'] ?? '' ) ) {
			$code_ids[] = (string) $block['attrs']['codeId'];
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$code_ids = array_merge( $code_ids, find_code_ids( $block['innerBlocks'] ) );
		}
	}

	return $code_ids;
}

/**
 * Remove code for blocks that are no longer in a post.
 *
 * Runs after the post's meta is saved: the REST API saves meta after the post
 * itself, so `save_post` would see the old meta.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 */
function prune_code_meta( $post_id, $post ) {
	if ( wp_is_post_revision( $post ) || ! post_type_supports_code_meta( $post->post_type ) ) {
		return;
	}

	$meta = get_code_meta( $post_id );
	if ( ! $meta ) {
		return;
	}

	$pruned = array_intersect_key( $meta, array_flip( find_code_ids( parse_blocks( $post->post_content ) ) ) );
	if ( count( $pruned ) === count( $meta ) ) {
		return;
	}

	if ( $pruned ) {
		update_post_meta( $post_id, META_KEY, wp_slash( $pruned ) );
	} else {
		delete_post_meta( $post_id, META_KEY );
	}
}

/**
 * Hide the code of password protected posts in the REST API.
 *
 * The REST API hides the content of password protected posts unless the
 * password is given, but not their meta.
 */
function protect_password_protected_code() {
	foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $post_type ) {
		if ( post_type_supports_code_meta( $post_type ) ) {
			add_filter( "rest_prepare_{$post_type}", __NAMESPACE__ . '\\filter_rest_password_protected_code', 10, 3 );
		}
	}
}

/**
 * Remove the code from REST responses for password protected posts.
 *
 * The code is kept for users who can edit the post and when the request
 * includes the correct password, matching how the REST API handles content.
 *
 * @param WP_REST_Response $response Response.
 * @param WP_Post          $post     Post.
 * @param WP_REST_Request  $request  Request.
 * @return WP_REST_Response Response.
 */
function filter_rest_password_protected_code( $response, $post, $request ) {
	if ( '' === $post->post_password || ! isset( $response->data['meta'][ META_KEY ] ) ) {
		return $response;
	}

	if ( current_user_can( 'edit_post', $post->ID ) ) {
		return $response;
	}

	$password = $request['password'];
	if ( is_string( $password ) && hash_equals( $post->post_password, $password ) ) {
		return $response;
	}

	$response->data['meta'][ META_KEY ] = array();
	return $response;
}

/**
 * Register the REST API route for copying a block's code from another post.
 *
 * When a Code Bin block is pasted into another post, the editor uses this to
 * copy the code from the post the block was copied from.
 */
function register_rest_routes() {
	register_rest_route(
		'code-bin/v1',
		'/posts/(?P<post>\d+)/code/(?P<code_id>[a-z0-9]{1,32})',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\rest_get_code',
			'permission_callback' => __NAMESPACE__ . '\\rest_can_read_code',
			'args'                => array(
				'post'    => array(
					'description' => __( 'ID of the post containing the code.', 'code-bin' ),
					'type'        => 'integer',
				),
				'code_id' => array(
					'description' => __( 'Code ID of the block.', 'code-bin' ),
					'type'        => 'string',
				),
			),
		)
	);
}

/**
 * Whether the current user can read a block's code via the REST API.
 *
 * Users who can edit the post can read its code. Other users can read the code
 * of published, public posts without a password: anyone can see that code on
 * the site.
 *
 * @param WP_REST_Request $request Request.
 * @return bool|WP_Error Whether the user can read the code.
 */
function rest_can_read_code( $request ) {
	$post = get_post( (int) $request['post'] );
	if ( ! $post || ! post_type_supports_code_meta( $post->post_type ) ) {
		return new WP_Error( 'code_bin_code_not_found', __( 'Code not found.', 'code-bin' ), array( 'status' => 404 ) );
	}

	if ( current_user_can( 'edit_post', $post->ID ) ) {
		return true;
	}

	if ( is_post_publicly_viewable( $post ) && '' === $post->post_password ) {
		return true;
	}

	return new WP_Error(
		'rest_forbidden',
		__( 'Sorry, you are not allowed to read this code.', 'code-bin' ),
		array( 'status' => rest_authorization_required_code() )
	);
}

/**
 * Get a block's code via the REST API.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error Code, keyed by panel.
 */
function rest_get_code( $request ) {
	$meta    = get_code_meta( (int) $request['post'] );
	$code_id = (string) $request['code_id'];

	if ( ! isset( $meta[ $code_id ] ) ) {
		return new WP_Error( 'code_bin_code_not_found', __( 'Code not found.', 'code-bin' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response( $meta[ $code_id ] );
}
