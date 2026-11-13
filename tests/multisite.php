<?php
/**
 * Multisite behaviour on a real network: per-site capabilities, per-site state,
 * per-site answer isolation and a network-wide uninstall pass. Disposable site
 * only; never run this against a production network.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }

function adf_ms_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

adf_ms_expect( is_multisite(), 'Run this suite on a multisite installation (wp core multisite-install).' );
adf_ms_expect( is_plugin_active_for_network( 'advanced-divi-forms/advanced-divi-forms.php' ), 'Network-activate the plugin first: wp plugin activate --network advanced-divi-forms.' );
adf_ms_expect( function_exists( 'adf_uninstall_plugin_data' ) || file_exists( dirname( ADF_PLUGIN_FILE ) . '/includes/uninstall.php' ), 'The removal module must ship with the plugin.' );
require_once dirname( ADF_PLUGIN_FILE ) . '/includes/uninstall.php';

$network_id   = get_current_network_id();
$primary_id   = get_main_site_id();
$admin        = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$original     = get_current_blog_id();
$created_site = 0;
$created_posts = array();

$site_state = static function ( $blog_id ) {
	switch_to_blog( $blog_id );
	try {
		global $wpdb;
		// Uninstall deletes transient rows directly, so read the table instead of
		// the (possibly stale) options cache.
		$transient_rows = static function ( $pattern ) use ( $wpdb ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		};
		$role  = get_role( 'administrator' );
		$state = array(
			'retention'  => get_option( 'adf_retention_days' ),
			'last_run'   => get_option( 'adf_retention_last_run' ),
			'status'     => get_option( 'adf_github_update_status' ),
			'version'    => get_option( 'adf_capability_version' ),
			'release'    => $transient_rows( '_transient_adf_github_latest_release' ),
			'discovery'  => $transient_rows( $wpdb->esc_like( '_transient_adf_discovery_report_' ) . '%' ),
			'cron'       => wp_next_scheduled( 'adf_retention_cleanup' ),
			'view'       => $role ? $role->has_cap( 'adf_view_submissions' ) : null,
			'manage'     => $role ? $role->has_cap( 'adf_manage_submissions' ) : null,
			'export'     => $role ? $role->has_cap( 'adf_export_submissions' ) : null,
			'submissions' => get_posts( array( 'post_type' => 'adf_submission', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ),
			'posts'      => get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ),
		);
		return $state;
	} finally {
		restore_current_blog();
	}
};

$seed_state = static function ( $blog_id ) {
	switch_to_blog( $blog_id );
	try {
		update_option( 'adf_retention_days', 14 );
		update_option( 'adf_retention_last_run', array( 'time' => time() - 3600, 'removed' => 3, 'days' => 14 ) );
		update_option( 'adf_github_update_status', array( 'version' => '9.9.9' ) );
		set_transient( 'adf_github_latest_release', array( 'version' => '9.9.9' ), HOUR_IN_SECONDS );
		set_transient( 'adf_discovery_report_' . get_current_user_id(), array( 'checked' => 1 ), HOUR_IN_SECONDS );
		if ( ! wp_next_scheduled( 'adf_retention_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'adf_retention_cleanup' );
		}
		$submission = wp_insert_post( array( 'post_type' => 'adf_submission', 'post_status' => 'private', 'post_title' => 'Multisite answer ' . $blog_id ) );
		$form       = wp_insert_post( array( 'post_type' => 'adf_form', 'post_status' => 'private', 'post_title' => 'Multisite form ' . $blog_id ) );
		$post       = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Unrelated post ' . $blog_id ) );
		return array( (int) $submission, (int) $form, (int) $post );
	} finally {
		restore_current_blog();
	}
};

try {
	wp_set_current_user( $admin->ID );

	// A second site must be created through the real multisite API.
	$created_site = wp_insert_site(
		array(
			'domain'  => 'wordpress.test',
			'path'    => '/ms-second/',
			'title'   => 'ADF second site',
			'user_id' => $admin->ID,
			'network_id' => $network_id,
		)
	);
	adf_ms_expect( is_int( $created_site ) && $created_site > 0, 'The second site could not be created.' );
	$second_id = (int) $created_site;
	adf_ms_expect( $second_id !== (int) $primary_id, 'The second site must be a different site.' );

	// WordPress grants roles per site, so the plugin must grant its capabilities
	// on every site, not only the one that activated it.
	switch_to_blog( $second_id );
	do_action( 'init' );
	$second_role = get_role( 'administrator' );
	adf_ms_expect( $second_role && $second_role->has_cap( 'adf_view_submissions' ) && $second_role->has_cap( 'adf_manage_submissions' ) && $second_role->has_cap( 'adf_export_submissions' ), 'A new site must receive the submission capabilities.' );
	restore_current_blog();

	$seeded_primary = $seed_state( $primary_id );
	$seeded_second  = $seed_state( $second_id );
	$created_posts[] = array( 'blog' => $primary_id, 'ids' => $seeded_primary );
	$created_posts[] = array( 'blog' => $second_id, 'ids' => $seeded_second );

	$primary_state = $site_state( $primary_id );
	$second_state  = $site_state( $second_id );
	adf_ms_expect( 14 === (int) $primary_state['retention'] && 14 === (int) $second_state['retention'], 'Each site must keep its own retention period.' );
	adf_ms_expect( 1 === count( $primary_state['submissions'] ) && 1 === count( $second_state['submissions'] ), 'Answers must stay isolated per site.' );
	adf_ms_expect( ! in_array( (int) $primary_state['submissions'][0], array_map( 'intval', $second_state['submissions'] ), true ), 'One site must never see the other site\'s answers.' );
	adf_ms_expect( true === $primary_state['view'] && true === $second_state['view'], 'Both sites must carry the submission capabilities before the removal pass.' );
	adf_ms_expect( $primary_state['release'] >= 1 && $second_state['release'] >= 1, 'Each site must seed its own release cache.' );
	adf_ms_expect( $primary_state['discovery'] >= 1 && $second_state['discovery'] >= 1, 'Each site must seed its own discovery report transient.' );

	// Removing the plugin must clean every site while keeping site content.
	adf_uninstall_plugin_data( false );
	adf_ms_expect( (int) $original === (int) get_current_blog_id(), 'The removal pass must restore the original site.' );

	$primary_after = $site_state( $primary_id );
	$second_after  = $site_state( $second_id );
	foreach ( array( $primary_after, $second_after ) as $index => $state ) {
		$label = 0 === $index ? 'primary' : 'second';
		adf_ms_expect( false === $state['retention'], 'The ' . $label . ' site must lose its retention option.' );
		adf_ms_expect( false === $state['last_run'], 'The ' . $label . ' site must lose its cleanup timestamp.' );
		adf_ms_expect( false === $state['status'], 'The ' . $label . ' site must lose its updater status.' );
		adf_ms_expect( false === $state['version'], 'The ' . $label . ' site must lose its capability version marker.' );
		adf_ms_expect( 0 === $state['release'], 'The ' . $label . ' site must lose its release cache.' );
		adf_ms_expect( 0 === $state['discovery'], 'The ' . $label . ' site must lose its discovery report transient.' );
		adf_ms_expect( false === $state['cron'], 'The ' . $label . ' site must lose its retention event.' );
		adf_ms_expect( false === $state['view'] && false === $state['manage'] && false === $state['export'], 'The ' . $label . ' site must lose the submission capabilities.' );
		adf_ms_expect( 1 === count( $state['submissions'] ), 'Stored answers belong to the site owner and must survive a plain removal on the ' . $label . ' site.' );
	}
	adf_ms_expect( in_array( (int) $seeded_primary[2], array_map( 'intval', $primary_after['posts'] ), true ), 'Unrelated posts must never be touched on the primary site.' );
	adf_ms_expect( in_array( (int) $seeded_second[2], array_map( 'intval', $second_after['posts'] ), true ), 'Unrelated posts must never be touched on the second site.' );

	// The plugin is still active, so its init grant must restore capabilities.
	switch_to_blog( $second_id );
	do_action( 'init' );
	$regranted = get_role( 'administrator' );
	adf_ms_expect( $regranted && $regranted->has_cap( 'adf_view_submissions' ), 'An active plugin must re-grant the capabilities after a removal pass.' );
	restore_current_blog();

	// Opt-in content removal applies to every site as well.
	adf_uninstall_plugin_data( true );
	$primary_content = $site_state( $primary_id );
	$second_content  = $site_state( $second_id );
	adf_ms_expect( array() === $primary_content['submissions'] && array() === $second_content['submissions'], 'Opt-in removal must delete answers on every site.' );
	adf_ms_expect( in_array( (int) $seeded_primary[2], array_map( 'intval', $primary_content['posts'] ), true ), 'Opt-in removal must keep unrelated posts on the primary site.' );
	adf_ms_expect( in_array( (int) $seeded_second[2], array_map( 'intval', $second_content['posts'] ), true ), 'Opt-in removal must keep unrelated posts on the second site.' );

	WP_CLI::success( 'Multisite passed: per-site capabilities, isolated answers, network-wide removal and opt-in content deletion.' );
} finally {
	wp_set_current_user( $admin->ID );
	foreach ( $created_posts as $group ) {
		switch_to_blog( (int) $group['blog'] );
		foreach ( (array) $group['ids'] as $post_id ) {
			if ( $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
		}
		restore_current_blog();
	}
	switch_to_blog( $primary_id );
	foreach ( array( 'adf_retention_days', 'adf_retention_last_run', 'adf_github_update_status' ) as $option ) { delete_option( $option ); }
	restore_current_blog();
	if ( $created_site ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wp_delete_site( (int) $created_site );
	}
	switch_to_blog( $original );
	restore_current_blog();
	adf_ms_expect( (int) $original === (int) get_current_blog_id(), 'The suite must leave the original site active.' );
}
