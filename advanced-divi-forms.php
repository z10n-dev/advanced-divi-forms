<?php
/**
 * Plugin Name: Advanced Divi Forms
 * Plugin URI: https://github.com/z10n-dev/advanced-divi-forms
 * Description: Custom enhancements for Divi contact forms.
 * Version: 0.5.1
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
define( 'ADF_PLUGIN_VERSION', '0.5.1' );

require_once __DIR__ . '/includes/github-updater.php';
require_once __DIR__ . '/includes/form-data.php';
require_once __DIR__ . '/includes/mail-templates.php';
require_once __DIR__ . '/includes/admin-menu.php';
require_once __DIR__ . '/includes/submission-export.php';
require_once __DIR__ . '/includes/submission-actions.php';
require_once __DIR__ . '/includes/submission-list.php';
require_once __DIR__ . '/includes/divi-integration.php';
