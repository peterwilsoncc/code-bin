/**
 * Playwright config for the E2E tests.
 *
 * Extends the wp-scripts config, with the tests in tests/e2e.
 */
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...baseConfig,
	testDir: './tests/e2e',
};
