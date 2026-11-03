<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen.
 *
 * Settings, scheduled cleanup and the dedicated capabilities are removed.
 * Stored answers, form records and templates are kept unless the site defines
 * ADF_DELETE_ALL_DATA_ON_UNINSTALL (true) in wp-config.php.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/uninstall.php';

adf_uninstall_plugin_data( defined( 'ADF_DELETE_ALL_DATA_ON_UNINSTALL' ) && ADF_DELETE_ALL_DATA_ON_UNINSTALL );
