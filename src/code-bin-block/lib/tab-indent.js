/**
 * Tab and Shift+Tab indent code in the code textareas, as in CodeMirror.
 *
 * Textareas don't handle Tab: it moves focus to the next element. Code needs
 * indenting, so Tab inserts a tab, or indents the selected lines, and
 * Shift+Tab outdents them.
 *
 * To avoid a keyboard trap (WCAG 2.1.2), Tab can still move focus, as in
 * CodeMirror's tab focus mode:
 *
 * - Escape, then Tab within two seconds, moves focus. In the block editor
 *   Escape also selects the block, as usual.
 * - Ctrl+M (Shift+Alt+M on macOS) switches Tab between indenting and moving
 *   focus.
 *
 * The textareas describe this to assistive technology, see getTabHint().
 *
 * @see https://codemirror.net/examples/tab/
 */
import { __ } from '@wordpress/i18n';

const INDENT = '\t';

// Spaces removed by Shift+Tab when a line is indented with spaces.
const TAB_SIZE = 4;

// How long Tab moves focus for after Escape, in milliseconds.
const ESCAPE_TIMEOUT = 2000;

const MODIFIER_KEYS = [ 'Shift', 'Control', 'Alt', 'Meta' ];

/**
 * Tab focus mode of each textarea.
 *
 * `true` when switched on, a timestamp when Escape was pressed, until which
 * Tab moves focus.
 *
 * @type {WeakMap<HTMLTextAreaElement, true|number>}
 */
const tabFocusMode = new WeakMap();

/**
 * Whether the browser is on an Apple platform.
 *
 * Uses `navigator.platform`, as CodeMirror does.
 *
 * @param {Window} view Window.
 * @return {boolean} Whether on an Apple platform.
 */
function isApple( view ) {
	return /mac|iphone|ipad|ipod/i.test( view.navigator.platform || '' );
}

/**
 * Whether a key press switches tab focus mode: Ctrl+M, or Shift+Alt+M on
 * macOS, as in CodeMirror.
 *
 * @param {KeyboardEvent} event Keydown event.
 * @return {boolean} Whether the key press switches tab focus mode.
 */
function isToggle( event ) {
	if ( event.code !== 'KeyM' || event.metaKey ) {
		return false;
	}

	return isApple( event.view ?? window )
		? event.shiftKey && event.altKey && ! event.ctrlKey
		: event.ctrlKey && ! event.shiftKey && ! event.altKey;
}

/**
 * Get the hint describing the keyboard behaviour of the code textareas.
 *
 * @param {Window} [view] Window, used to describe the shortcut for the platform.
 * @return {string} Hint.
 */
export function getTabHint( view = window ) {
	return isApple( view )
		? __(
				'Tab inserts a tab and Shift+Tab removes one. To move focus, press Escape then Tab, or press Shift+Option+M to switch Tab between indenting and moving focus.',
				'code-bin'
			)
		: __(
				'Tab inserts a tab and Shift+Tab removes one. To move focus, press Escape then Tab, or press Control+M to switch Tab between indenting and moving focus.',
				'code-bin'
			);
}

/**
 * Replace text in a textarea as if typed.
 *
 * Uses `insertText` so the change is part of the browser's undo history and
 * fires an `input` event, falling back to setRangeText().
 *
 * @param {HTMLTextAreaElement} textarea Textarea.
 * @param {number}              from     Start of the text to replace.
 * @param {number}              to       End of the text to replace.
 * @param {string}              text     Replacement.
 */
function replaceText( textarea, from, to, text ) {
	textarea.setSelectionRange( from, to );

	const inserted =
		text !== '' &&
		textarea.ownerDocument.execCommand( 'insertText', false, text );

	if ( ! inserted ) {
		textarea.setRangeText( text, from, to, 'end' );
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}
}

/**
 * Get the range of whole lines containing the selection.
 *
 * A selection ending at the start of a line doesn't include that line.
 *
 * @param {string} value Text.
 * @param {number} start Selection start.
 * @param {number} end   Selection end.
 * @return {{from: number, to: number}} Start of the first line and end of the last.
 */
function getLineRange( value, start, end ) {
	const from = value.lastIndexOf( '\n', start - 1 ) + 1;
	const last = end > start && value[ end - 1 ] === '\n' ? end - 1 : end;
	const lineEnd = value.indexOf( '\n', last );

	return { from, to: lineEnd === -1 ? value.length : lineEnd };
}

/**
 * Indent: insert a tab, or indent the selected lines.
 *
 * Matches CodeMirror's `insertTab` command. Empty lines aren't indented.
 *
 * @param {HTMLTextAreaElement} textarea Textarea.
 */
function indent( textarea ) {
	const { value, selectionStart: start, selectionEnd: end } = textarea;

	if ( start === end ) {
		replaceText( textarea, start, end, INDENT );
		return;
	}

	const { from, to } = getLineRange( value, start, end );
	const lines = value.slice( from, to ).split( '\n' );
	const indented = lines.map( ( line ) =>
		line === '' ? line : INDENT + line
	);
	const added = indented.join( '\n' ).length - ( to - from );
	const firstAdded = lines[ 0 ] === '' ? 0 : INDENT.length;

	replaceText( textarea, from, to, indented.join( '\n' ) );
	textarea.setSelectionRange( start + firstAdded, end + added );
}

/**
 * Outdent the lines containing the selection.
 *
 * Removes a tab, or up to TAB_SIZE spaces, from the start of each line.
 * Matches CodeMirror's `indentLess` command.
 *
 * @param {HTMLTextAreaElement} textarea Textarea.
 */
function outdent( textarea ) {
	const { value, selectionStart: start, selectionEnd: end } = textarea;
	const { from, to } = getLineRange( value, start, end );
	const lines = value.slice( from, to ).split( '\n' );

	const removed = lines.map( ( line ) => {
		if ( line.startsWith( '\t' ) ) {
			return 1;
		}
		return line.match( new RegExp( `^ {0,${ TAB_SIZE }}` ) )[ 0 ].length;
	} );
	if ( removed.every( ( count ) => count === 0 ) ) {
		return;
	}

	const outdented = lines.map( ( line, index ) =>
		line.slice( removed[ index ] )
	);
	const total = removed.reduce( ( sum, count ) => sum + count, 0 );

	replaceText( textarea, from, to, outdented.join( '\n' ) );
	textarea.setSelectionRange(
		Math.max( from, start - removed[ 0 ] ),
		Math.max( from, end - total )
	);
}

/**
 * Handle Tab, Shift+Tab, Escape and the tab focus mode shortcut in a code
 * textarea.
 *
 * In the block editor this must run before the editor's own Tab handling,
 * which ignores key presses that have been handled (`defaultPrevented`).
 *
 * @param {KeyboardEvent} event Keydown event on a code textarea.
 */
export function onCodeKeyDown( event ) {
	const textarea = event.target;
	const mode = tabFocusMode.get( textarea );

	if ( isToggle( event ) ) {
		event.preventDefault();
		if ( mode === true ) {
			tabFocusMode.delete( textarea );
		} else {
			tabFocusMode.set( textarea, true );
		}
		return;
	}

	if ( event.key === 'Escape' ) {
		// Not prevented: the block editor uses Escape to select the block.
		if ( mode !== true ) {
			tabFocusMode.set( textarea, Date.now() + ESCAPE_TIMEOUT );
		}
		return;
	}

	if ( event.key !== 'Tab' ) {
		// Any other key ends the focus mode set by Escape.
		if (
			typeof mode === 'number' &&
			! MODIFIER_KEYS.includes( event.key )
		) {
			tabFocusMode.delete( textarea );
		}
		return;
	}

	if ( event.ctrlKey || event.altKey || event.metaKey ) {
		return;
	}

	// In tab focus mode the browser moves focus.
	if ( mode === true ) {
		return;
	}
	if ( typeof mode === 'number' ) {
		tabFocusMode.delete( textarea );
		if ( Date.now() <= mode ) {
			return;
		}
	}

	event.preventDefault();
	if ( event.shiftKey ) {
		outdent( textarea );
	} else {
		indent( textarea );
	}
}
