<?php
/**
 * Code Bin global functions.
 *
 * Functions are in the global namespace so they can be called unqualified,
 * which lets PHPCS recognise them, eg as custom escaping functions.
 *
 * @package CodeBin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Escape code for output in HTML text or an attribute.
 *
 * The functions esc_html() and esc_attr() don't encode entities that are
 * already encoded, so code containing entities as text, eg `&lt;b&gt;`, would
 * be decoded by the browser and show (and run) as `<b>`. This encodes every
 * `&`, so the browser decodes the output to exactly the saved code.
 *
 * Invalid UTF-8 is replaced rather than causing an empty string.
 *
 * @param string $code Code.
 * @return string Escaped code.
 */
function code_bin_escape_code( $code ) {
	return htmlspecialchars( (string) $code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
