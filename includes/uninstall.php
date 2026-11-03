<?php
/**
 * Plugin removal.
 *
 * Settings, scheduled work and the dedicated capabilities belong to the plugin
 * and are always removed. Stored contact-form answers belong to the site owner,
 * so they are deleted only when the site opts in through
 * ADF_DELETE_ALL_DATA_ON_UNINSTALL in wp-config.php.
 *
 * WordPress does not load the plugin while uninstalling, so this module must not
 * rely on the plugin bootstrap.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove plugin-owned state and, on request, plugin-owned content for one site.
 *
 * @param bool $delete_content Delete stored answers, form records and templates.
 * @return void
 */
function adf_uninstall_site_data( $delete_content = false ) {
	foreach ( array( 'adf_retention_days', 'adf_retention_last_run', 'adf_github_update_status', 'adf_capability_version' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'adf_github_latest_release' );
	delete_transient( 'adf_github_update_error' );
	adf_uninstall_delete_transients();
	wp_clear_scheduled_hook( 'adf_retention_cleanup' );
	adf_uninstall_remove_capabilities();
	if ( $delete_content ) {
		adf_uninstall_delete_content();
	}
}

/**
 * Drop every plugin transient, including the per-user discovery reports.
 *
 * @return void
 */
function adf_uninstall_delete_transients() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup while uninstalling; the options table has no API for pattern deletion.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_adf_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_adf_' ) . '%'
		)
	);
}

/**
 * Remove the dedicated submission capabilities from every role that holds them.
 *
 * @return void
 */
function adf_uninstall_remove_capabilities() {
	$capabilities = function_exists( 'adf_submission_capabilities' )
		? array_values( adf_submission_capabilities() )
		: array( 'adf_view_submissions', 'adf_manage_submissions', 'adf_export_submissions' );
	$roles        = wp_roles();
	foreach ( $roles->roles as $role_name => $role_data ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( $capabilities as $capability ) {
			if ( ! empty( $role_data['capabilities'][ $capability ] ) ) {
				$role->remove_cap( $capability );
			}
		}
	}
}

/**
 * Delete plugin post types in bounded batches, including trashed answers.
 *
 * IDs are read directly because private answers are invisible to a WP_Query run
 * without a logged-in user, which is the situation during a network uninstall.
 *
 * @return void
 */
function adf_uninstall_delete_content() {
	global $wpdb;
	foreach ( array( 'adf_submission', 'adf_form', 'adf_mail_template' ) as $post_type ) {
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall runs without a user; private answers must be found regardless of capabilities.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT 200",
					$post_type
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		} while ( ! empty( $ids ) );
	}
}

/**
 * Remove plugin data for the current site, or every site on multisite.
 *
 * @param bool $delete_content Delete stored answers, form records and templates.
 * @return void
 */
function adf_uninstall_plugin_data( $delete_content = false ) {
	$delete_content = (bool) $delete_content;
	if ( is_multisite() ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			adf_uninstall_site_data( $delete_content );
			restore_current_blog();
		}
		return;
	}
	adf_uninstall_site_data( $delete_content );
}
