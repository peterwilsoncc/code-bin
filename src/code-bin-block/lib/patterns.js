/**
 * Keep the Code Bin block out of synced patterns.
 *
 * Synced patterns render inside other posts, so the block can't store its code
 * in the pattern's meta (see inc/meta.php). Code kept in the block's attributes
 * would be filtered by kses for users without the `unfiltered_html`
 * capability, breaking it.
 */
import { select } from '@wordpress/data';

import metadata from '../block.json';

/**
 * Whether the post being edited is a pattern.
 *
 * Covers patterns opened in the site editor, which has no post in its block
 * editor context on the server.
 *
 * @return {boolean} Whether a pattern is being edited.
 */
function isEditingPattern() {
	// The editor store isn't registered in every block editor, eg the widgets screen.
	const editor = select( 'core/editor' );
	return editor?.getCurrentPostType?.() === 'wp_block';
}

/**
 * Prevent inserting the Code Bin block in a pattern.
 *
 * Applied to `blockEditor.__unstableCanInsertBlockType`, which is checked when
 * inserting, pasting, moving and transforming blocks.
 *
 * @param {boolean}                                      canInsert                            Whether the block can be inserted.
 * @param {Object|string}                                blockType                            Block type, or its name.
 * @param {string|null}                                  rootClientId                         Client ID of the block it's inserted into.
 * @param {Object}                                       selectors                            Block editor selectors.
 * @param {(clientId: string) => Object|null}            selectors.getBlock                   Get a block.
 * @param {(clientId: string, name: string) => string[]} selectors.getBlockParentsByBlockName Get a block's parents of a block type.
 * @return {boolean} Whether the block can be inserted.
 */
export default function canInsertInPatterns(
	canInsert,
	blockType,
	rootClientId,
	{ getBlock, getBlockParentsByBlockName }
) {
	const name = typeof blockType === 'string' ? blockType : blockType?.name;
	if ( ! canInsert || name !== metadata.name ) {
		return canInsert;
	}

	if ( isEditingPattern() ) {
		return false;
	}

	// Editing a synced pattern inside a post.
	if (
		rootClientId &&
		( getBlock( rootClientId )?.name === 'core/block' ||
			getBlockParentsByBlockName( rootClientId, 'core/block' ).length >
				0 )
	) {
		return false;
	}

	return canInsert;
}
