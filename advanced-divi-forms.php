<?php
/**
 * Plugin Name: Advanced Divi Forms
 * Plugin URI: https://github.com/z10n-dev/advanced-divi-forms
 * Description: Custom enhancements for Divi contact forms.
 * Version: 0.13.0
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * Author: z10n-dev
 * License: GPL-2.0-or-later
 * Text Domain: advanced-divi-forms
 * Update URI: https://github.com/z10n-dev/advanced-divi-forms
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ADF_PLUGIN_FILE', __FILE__ );
define( 'ADF_PLUGIN_VERSION', '0.13.0' );

/**
 * Load the bundled translations.
 *
 * Language packs installed in `WP_LANG_DIR/plugins` are loaded by WordPress on
 * its own; this call adds the `.mo` files shipped in the plugin's `languages`
 * directory. Hooked to `init` because WordPress 6.7+ warns about translation
 * loading that happens too early.
 *
 * @return void
 */
function adf_load_textdomain() {
	load_plugin_textdomain(
		'advanced-divi-forms',
		false,
		dirname( plugin_basename( ADF_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'adf_load_textdomain' );

require_once __DIR__ . '/includes/github-updater.php';
require_once __DIR__ . '/includes/form-data.php';
require_once __DIR__ . '/includes/divi-support.php';
require_once __DIR__ . '/includes/submission-capabilities.php';
require_once __DIR__ . '/includes/submission-filters.php';
require_once __DIR__ . '/includes/submission-filter-ui.php';
require_once __DIR__ . '/includes/submission-trash.php';
require_once __DIR__ . '/includes/mail-templates.php';
require_once __DIR__ . '/includes/template-preview.php';
require_once __DIR__ . '/includes/submission-privacy.php';
require_once __DIR__ . '/includes/admin-menu.php';
require_once __DIR__ . '/includes/privacy-admin.php';
require_once __DIR__ . '/includes/update-admin.php';
require_once __DIR__ . '/includes/submission-export.php';
require_once __DIR__ . '/includes/submission-actions.php';
require_once __DIR__ . '/includes/submission-list.php';
require_once __DIR__ . '/includes/divi-integration.php';
