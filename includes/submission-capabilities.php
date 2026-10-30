<?php
/**
 * Dedicated submission capabilities, separate from administrator-only plugin,
 * template and privacy settings. Only administrators receive them by upgrade.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'adf_install_submission_capabilities', 5 );
register_activation_hook( ADF_PLUGIN_FILE, 'adf_install_submission_capabilities' );

/**
 * Canonical capability map; never grant these to editors/authors automatically.
 *
 * @return array
 */
function adf_submission_capabilities() {
	return array(
		'view'   => 'adf_view_submissions',
		'manage' => 'adf_manage_submissions',
		'export' => 'adf_export_submissions',
	);
}

/**
 * Grant the dedicated capabilities to the administrator role once per version.
 *
 * Existing administrators therefore keep access after upgrading. Other roles
 * are untouched; granting them is a deliberate administrator action through
 * WordPress's own role management.
 *
 * @return void
 */
function adf_install_submission_capabilities() {
	if ( get_option( 'adf_capability_version' ) === ADF_PLUGIN_VERSION ) {
		return;
	}
	$role = get_role( 'administrator' );
	if ( $role ) {
		foreach ( adf_submission_capabilities() as $capability ) {
			$role->add_cap( $capability );
		}
	}
	update_option( 'adf_capability_version', ADF_PLUGIN_VERSION, false );
}

/**
 * Read stored answers.
 *
 * @return bool
 */
function adf_can_view_submissions() {
	return current_user_can( adf_submission_capabilities()['view'] );
}

/**
 * Change status/notes, move to Trash, restore or delete answers.
 *
 * @return bool
 */
function adf_can_manage_submissions() {
	return current_user_can( adf_submission_capabilities()['manage'] );
}

/**
 * Download stored answers as Excel.
 *
 * @return bool
 */
function adf_can_export_submissions() {
	return current_user_can( adf_submission_capabilities()['export'] );
}

/**
 * Plugin settings, retention and template HTML stay administrator-only.
 *
 * @return bool
 */
function adf_can_manage_plugin_settings() {
	return current_user_can( 'manage_options' );
}

/**
 * Stop a request that lacks a specific submission capability.
 *
 * @param string $which view, manage or export.
 * @return void
 */
function adf_require_submission_capability( $which ) {
	$map = adf_submission_capabilities();
	if ( ! isset( $map[ $which ] ) || ! current_user_can( $map[ $which ] ) ) {
		wp_die(
			esc_html__( 'You are not allowed to access these submissions.', 'advanced-divi-forms' ),
			'',
			array( 'response' => 403 )
		);
	}
}
