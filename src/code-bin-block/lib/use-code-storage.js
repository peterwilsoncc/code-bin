/**
 * Store a Code Bin block's code in the meta of the post it's in.
 *
 * WordPress filters block attributes with kses when a user without the
 * `unfiltered_html` capability saves a post, which breaks code, eg `a && b` is
 * saved as `a &amp;&amp; b`. Post meta isn't filtered, so the code is stored
 * in the post's `_code_bin` meta, keyed by the block's `codeId` attribute. See
 * inc/meta.php.
 *
 * Where the post has no meta of its own, eg in a synced pattern or a template,
 * the code stays in the block's attributes.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	store as blockEditorStore,
	useBlockEditingMode,
} from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { useRegistry, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/** @typedef {import('./preview').Code} Code */

export const META_KEY = '_code_bin';

const PANELS = [ 'html', 'css', 'javascript' ];

// Keep in sync with CODE_ID_PATTERN in inc/meta.php.
const CODE_ID_PATTERN = /^[a-z0-9]{1,32}$/;

// Keep in sync with EXCLUDED_POST_TYPES in inc/meta.php.
const EXCLUDED_POST_TYPES = [
	'wp_block',
	'wp_template',
	'wp_template_part',
	'wp_navigation',
];

const EMPTY_CODE = { html: '', css: '', javascript: '' };

// Removes the code from the attributes: the defaults aren't saved.
const NO_CODE_ATTRIBUTES = EMPTY_CODE;

/**
 * Client IDs of the blocks mounted in each editor.
 *
 * Keyed by registry as block previews (eg in the inserter) use their own.
 *
 * @type {WeakMap<Object, Set<string>>}
 */
const mountedBlocks = new WeakMap();

/**
 * Generate a code ID.
 *
 * Keep the format in sync with generate_code_id() in inc/meta.php.
 *
 * @return {string} Code ID, eg `k3x9q2a7bd1f`.
 */
export function generateCodeId() {
	const characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
	const values = window.crypto.getRandomValues( new Uint8Array( 12 ) );

	return Array.from(
		values,
		( value ) => characters[ value % characters.length ]
	).join( '' );
}

/**
 * Whether a code ID is valid.
 *
 * @param {unknown} codeId Code ID.
 * @return {boolean} Whether the code ID is valid.
 */
function isValidCodeId( codeId ) {
	return typeof codeId === 'string' && CODE_ID_PATTERN.test( codeId );
}

/**
 * Get the code map from a post's meta.
 *
 * The REST API returns an empty array rather than an object when there's no
 * code.
 *
 * @param {Object|undefined} meta Post meta.
 * @return {Object<string, Code>} Code, keyed by code ID.
 */
function getCodeMap( meta ) {
	const map = meta?.[ META_KEY ];
	return map && typeof map === 'object' && ! Array.isArray( map ) ? map : {};
}

/**
 * Normalize code, eg from meta or the REST API.
 *
 * @param {Object} code Code.
 * @return {Code} Code with every panel.
 */
function normalizeCode( code ) {
	const normalized = { ...EMPTY_CODE };
	for ( const panel of PANELS ) {
		if ( typeof code?.[ panel ] === 'string' ) {
			normalized[ panel ] = code[ panel ];
		}
	}
	return normalized;
}

/**
 * Whether the attributes contain code.
 *
 * @param {Object} attributes Block attributes.
 * @return {boolean} Whether any panel's code is in the attributes.
 */
function attributesHaveCode( attributes ) {
	return PANELS.some(
		( panel ) =>
			typeof attributes[ panel ] === 'string' &&
			attributes[ panel ] !== ''
	);
}

/**
 * Store and read a Code Bin block's code.
 *
 * @param {Object}                       props               Block edit props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Attribute setter.
 * @param {string}                       props.clientId      Block client ID.
 * @param {Object}                       props.context       Block context: `postId` and `postType`.
 * @return {{code: Code, setCode: (panel: string, value: string) => void}} The code and a setter for a panel's code.
 */
export default function useCodeStorage( {
	attributes,
	setAttributes,
	clientId,
	context,
} ) {
	const { postId, postType } = context;
	const registry = useRegistry();
	const blockEditingMode = useBlockEditingMode();

	const { storesInMeta, entry } = useSelect(
		( select ) => {
			if (
				! postId ||
				! postType ||
				EXCLUDED_POST_TYPES.includes( postType ) ||
				select( blockEditorStore ).getBlockParentsByBlockName(
					clientId,
					'core/block'
				).length > 0
			) {
				return { storesInMeta: false };
			}

			const meta = select( coreStore ).getEditedEntityRecord(
				'postType',
				postType,
				postId
			)?.meta;

			// The REST API only includes meta for post types that support custom fields.
			if ( ! meta || ! ( META_KEY in meta ) ) {
				return { storesInMeta: false };
			}

			return {
				storesInMeta: true,
				entry: getCodeMap( meta )[ attributes.codeId ],
			};
		},
		[ postId, postType, clientId, attributes.codeId ]
	);

	/**
	 * Get the code map of the post being edited, including unsaved changes.
	 *
	 * @return {Object<string, Code>} Code, keyed by code ID.
	 */
	const getEditedCodeMap = () =>
		getCodeMap(
			registry
				.select( coreStore )
				.getEditedEntityRecord( 'postType', postType, postId )?.meta
		);

	/**
	 * Store a block's code in the post's meta.
	 *
	 * @param {string}  codeId               Code ID.
	 * @param {Code}    code                 Code.
	 * @param {Object}  [options]            Options.
	 * @param {boolean} [options.undoIgnore] Leave the change out of the undo history.
	 */
	const storeCode = ( codeId, code, { undoIgnore = false } = {} ) => {
		registry.dispatch( coreStore ).editEntityRecord(
			'postType',
			postType,
			postId,
			{
				meta: {
					[ META_KEY ]: {
						...getEditedCodeMap(),
						[ codeId ]: normalizeCode( code ),
					},
				},
			},
			{ undoIgnore }
		);
	};

	/**
	 * Change attributes without adding an undo step: undoing would undo the
	 * code storage, not the author's change.
	 *
	 * @param {Object} changes Attributes.
	 */
	const setAttributesQuietly = ( changes ) => {
		registry
			.dispatch( blockEditorStore )
			.__unstableMarkNextChangeAsNotPersistent();
		setAttributes( changes );
	};

	/**
	 * Copy the code of a block copied from another post.
	 *
	 * @param {number}               fromPost   Post the block was copied from.
	 * @param {string}               fromCodeId Code ID in that post.
	 * @param {(code: Code) => void} onCode     Called with the code.
	 */
	const copyCode = ( fromPost, fromCodeId, onCode ) => {
		apiFetch( {
			path: `/code-bin/v1/posts/${ fromPost }/code/${ fromCodeId }`,
		} )
			.then( ( code ) => onCode( normalizeCode( code ) ) )
			.catch( () => {
				registry
					.dispatch( noticesStore )
					.createWarningNotice(
						__(
							'The code of a copied Code Bin block could not be copied from the post it was copied from.',
							'code-bin'
						),
						{ id: 'code-bin-copy-failed' }
					);
			} );
	};

	useEffect( () => {
		if ( ! mountedBlocks.has( registry ) ) {
			mountedBlocks.set( registry, new Set() );
		}
		const mounted = mountedBlocks.get( registry );

		// Blocks that can't be edited, eg inside a synced pattern's preview, are left alone.
		if ( blockEditingMode !== 'disabled' ) {
			if ( storesInMeta ) {
				setUpMetaStorage( mounted );
			} else {
				setUpAttributeStorage();
			}
		}

		mounted.add( clientId );
		return () => mounted.delete( clientId );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Give the block a code ID and move its code into the post's meta.
	 *
	 * Runs once, when the block mounts:
	 *
	 * - New blocks get a code ID.
	 * - Copies of a block in the same post share its code ID: the copy mounts
	 *   later, so it gets a new code ID and a copy of the code.
	 * - Blocks pasted from another post get their code from that post.
	 * - Blocks saved with their code in their attributes have it moved to meta.
	 *
	 * @param {Set<string>} mounted Client IDs of the other mounted blocks.
	 */
	function setUpMetaStorage( mounted ) {
		const { getBlockAttributes } = registry.select( blockEditorStore );
		const map = getEditedCodeMap();
		const { codeId, codePost } = attributes;

		const hasId = isValidCodeId( codeId );
		const inUse =
			hasId &&
			[ ...mounted ].some(
				( otherClientId ) =>
					getBlockAttributes( otherClientId )?.codeId === codeId
			);

		let newCodeId = codeId;
		if ( ! hasId || inUse ) {
			do {
				newCodeId = generateCodeId();
			} while ( map[ newCodeId ] );
		}

		// The code to store: a copy's code, or code saved in the attributes.
		let code;
		if ( hasId && map[ codeId ] ) {
			// Meta takes precedence over attributes, as on the site.
			code = newCodeId !== codeId ? map[ codeId ] : undefined;
		} else if ( attributesHaveCode( attributes ) ) {
			code = attributes;
		} else if ( hasId && codePost && codePost !== postId ) {
			copyCode( codePost, codeId, ( copied ) => {
				if ( ! getEditedCodeMap()[ newCodeId ] ) {
					storeCode( newCodeId, copied, { undoIgnore: true } );
				}
			} );
		}

		if ( code ) {
			storeCode( newCodeId, code, { undoIgnore: true } );
		}

		const changes = {};
		if ( newCodeId !== codeId ) {
			changes.codeId = newCodeId;
		}
		if ( codePost !== postId ) {
			changes.codePost = postId;
		}
		if ( attributesHaveCode( attributes ) ) {
			Object.assign( changes, NO_CODE_ATTRIBUTES );
		}
		if ( Object.keys( changes ).length ) {
			setAttributesQuietly( changes );
		}
	}

	/**
	 * Keep the block's code in its attributes.
	 *
	 * Blocks copied from a post storing code in meta, eg converted to a synced
	 * pattern, get their code from that post.
	 */
	function setUpAttributeStorage() {
		const { codeId, codePost } = attributes;
		if ( ! isValidCodeId( codeId ) && ! codePost ) {
			return;
		}

		if ( ! attributesHaveCode( attributes ) && codePost ) {
			copyCode( codePost, codeId, ( copied ) =>
				setAttributesQuietly( {
					...copied,
					codeId: undefined,
					codePost: undefined,
				} )
			);
			return;
		}

		setAttributesQuietly( { codeId: undefined, codePost: undefined } );
	}

	/**
	 * Change a panel's code.
	 *
	 * @param {string} panel Panel: html, css or javascript.
	 * @param {string} value Code.
	 */
	const setCode = ( panel, value ) => {
		if ( ! storesInMeta || ! isValidCodeId( attributes.codeId ) ) {
			setAttributes( { [ panel ]: value } );
			return;
		}

		const current = getEditedCodeMap()[ attributes.codeId ] ?? attributes;
		storeCode( attributes.codeId, { ...current, [ panel ]: value } );
		if ( attributesHaveCode( attributes ) ) {
			setAttributesQuietly( NO_CODE_ATTRIBUTES );
		}
	};

	const code = normalizeCode( storesInMeta && entry ? entry : attributes );

	return { code, setCode };
}
