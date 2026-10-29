<?php
/**
 * Updater integration regressions, only in disposable WordPress via WP-CLI.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
function adf_update_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
adf_update_expect( function_exists( 'adf_get_github_update' ), 'The updater must expose a forced fresh-check API.' );
class ADF_Update_Denied extends RuntimeException {}
class ADF_Update_Redirect extends RuntimeException {}
function adf_test_manual_update( array $post, $method = 'POST' ) {
	$_POST = wp_slash( $post );
	$_REQUEST = $_POST;
	$_SERVER['REQUEST_METHOD'] = $method;
	try { adf_handle_update_check(); } catch ( ADF_Update_Denied $error ) { return $error->getCode(); } catch ( ADF_Update_Redirect $redirect ) { return $redirect->getMessage(); }
	return 'returned';
}
$original_user = get_current_user_id();
$original_post = $_POST;
$original_request = $_REQUEST;
$original_server = $_SERVER;
$original_wp_updates = get_site_transient( 'update_plugins' );
$original_auto_updates = get_site_option( 'auto_update_plugins', array() );
$deny = static function () { return static function ( $message, $title, $args ) { throw new ADF_Update_Denied( wp_strip_all_tags( $message ), $args['response'] ?? 500 ); }; };
$redirect = static function ( $url ) { throw new ADF_Update_Redirect( $url ); };
add_filter( 'wp_die_handler', $deny );
add_filter( 'wp_redirect', $redirect, -999 );
$original_cache = get_transient( 'adf_github_latest_release' );
$original_status = get_option( 'adf_github_update_status', false );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$calls = 0;
$wporg_calls = 0;
$http_status = 200;
$transport_error = null;
$body_override = null;
$headers = array();
$release = array( 'tag_name' => 'v9.0.1', 'assets' => array( array( 'name' => 'advanced-divi-forms.zip', 'browser_download_url' => 'https://github.com/z10n-dev/advanced-divi-forms/releases/download/v9.0.1/advanced-divi-forms.zip' ) ) );
$http = static function ( $preempt, $args, $url ) use ( &$calls, &$release, &$http_status, &$transport_error, &$body_override, &$headers, &$wporg_calls ) {
	if ( 'https://api.github.com/repos/z10n-dev/advanced-divi-forms/releases/latest' === $url ) {
		++$calls;
		if ( $transport_error ) { return $transport_error; }
		return array( 'response' => array( 'code' => $http_status ), 'headers' => $headers, 'body' => null !== $body_override ? $body_override : wp_json_encode( $release ) );
	}
	if ( str_contains( $url, 'api.wordpress.org/plugins/update-check/' ) ) {
		++$wporg_calls;
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'plugins' => array( 'foreign/plugin.php' => array( 'new_version' => '4.0.0', 'slug' => 'foreign' ) ), 'no_update' => array(), 'translations' => array() ) ) );
	}
	return new WP_Error( 'adf_test_no_network', 'This test blocks unrelated network requests.' );
};
add_filter( 'pre_http_request', $http, 10, 3 );
try {
	$old = $release;
	$old['tag_name'] = 'v0.1.0';
	$old['assets'][0]['browser_download_url'] = 'https://github.com/z10n-dev/advanced-divi-forms/releases/download/v0.1.0/advanced-divi-forms.zip';
	set_transient( 'adf_github_latest_release', $old, 6 * HOUR_IN_SECONDS );
	$cached = adf_get_github_update();
	adf_update_expect( '0.1.0' === $cached['version'] && 0 === $calls, 'Routine checks must preserve the existing cache policy.' );
	$fresh = adf_get_github_update( true );
	adf_update_expect( is_array( $fresh ) && '9.0.1' === $fresh['version'] && 1 === $calls, 'A forced check must bypass the six-hour cache and fetch the latest release.' );
	$status = get_option( 'adf_github_update_status' );
	adf_update_expect( '9.0.1' === $status['latest_version'] && $status['last_success_at'] > 0 && '' === $status['error_code'], 'A successful check must persist a clear diagnostic status.' );
	$success_at = $status['last_success_at'];
	$transport_error = new WP_Error( 'http_request_failed', 'Synthetic private transport detail must not be stored.' );
	$failed = adf_get_github_update( true );
	$count_after_failure = $calls;
	adf_update_expect( is_wp_error( $failed ) && 'adf_update_network' === $failed->get_error_code(), 'Transport failures need a controlled diagnostic, not a silent miss.' );
	adf_get_github_update();
	adf_update_expect( $count_after_failure === $calls, 'A short failure cooldown must prevent repeated background requests.' );
	$status = get_option( 'adf_github_update_status' );
	adf_update_expect( $success_at === $status['last_success_at'] && ! str_contains( $status['error_message'], 'Synthetic private' ), 'Failures must retain last successful time without storing private transport details.' );
	$transport_error = null;
	$http_status = 403;
	$headers = array( 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) ( time() + 1200 ) );
	$failed = adf_get_github_update( true );
	adf_update_expect( 'adf_update_rate_limit' === $failed->get_error_code() && get_option( 'adf_github_update_status' )['retry_at'] > time(), 'Rate limiting must show a retry time rather than pretend no update exists.' );
	$http_status = 429;
	adf_update_expect( 'adf_update_rate_limit' === adf_get_github_update( true )->get_error_code(), 'HTTP 429 must be recognized as rate limiting.' );
	$http_status = 500;
	adf_update_expect( 'adf_update_http' === adf_get_github_update( true )->get_error_code(), 'Unsuccessful HTTP responses need explicit diagnostics.' );
	$http_status = 200;
	$headers = array();
	$body_override = '{broken JSON';
	adf_update_expect( 'adf_invalid_release' === adf_get_github_update( true )->get_error_code(), 'Invalid JSON must not be cached as a valid release.' );
	$body_override = null;
	$valid_release = $release;
	$release['assets'] = array();
	adf_update_expect( 'adf_missing_release_zip' === adf_get_github_update( true )->get_error_code() && false === get_transient( 'adf_github_latest_release' ), 'Missing ZIPs must be diagnosed and never enter the success cache.' );
	$release = $valid_release;
	adf_update_expect( '9.0.1' === adf_get_github_update( true )['version'] && '' === get_option( 'adf_github_update_status' )['error_code'], 'A successful forced retry must clear the failure state.' );
	adf_update_expect( function_exists( 'adf_handle_update_check' ), 'A protected manual-check endpoint must exist.' );
	foreach ( array( ADF_PLUGIN_VERSION, '0.0.1', '9.0.1' ) as $version ) {
		$release['tag_name'] = 'v' . $version;
		$release['assets'][0]['browser_download_url'] = 'https://github.com/z10n-dev/advanced-divi-forms/releases/download/v' . $version . '/advanced-divi-forms.zip';
		adf_get_github_update( true );
		wp_update_plugins( array( 'adf_integration_test' => true ) );
		$native = get_site_transient( 'update_plugins' );
		$file = plugin_basename( ADF_PLUGIN_FILE );
		$newer = version_compare( $version, ADF_PLUGIN_VERSION, '>' );
		adf_update_expect( $newer ? isset( $native->response[ $file ] ) : isset( $native->no_update[ $file ] ), 'WordPress must distinguish newer, equal and older release versions.' );
		adf_update_expect( $newer ? ! isset( $native->no_update[ $file ] ) : ! isset( $native->response[ $file ] ), 'Only one native update slot may contain this plugin.' );
		adf_update_expect( '4.0.0' === $native->response['foreign/plugin.php']->new_version, 'Other plugins’ update entries must remain untouched.' );
	}
	$before = $calls;
	$valid_post = array( '_wpnonce' => wp_create_nonce( 'adf_check_updates' ) );
	adf_update_expect( 405 === adf_test_manual_update( $valid_post, 'GET' ), 'GET must not trigger a network/cache mutation.' );
	adf_update_expect( 403 === adf_test_manual_update( array( '_wpnonce' => 'invalid' ) ), 'Manual checks require a valid nonce.' );
	wp_set_current_user( 0 );
	adf_update_expect( 403 === adf_test_manual_update( $valid_post ) && $before === $calls, 'Unauthorized attempts must fail before any network fetch.' );
	wp_set_current_user( $admins[0]->ID );
	$before_wporg = $wporg_calls;
	$location = adf_test_manual_update( $valid_post );
	adf_update_expect( is_string( $location ) && str_contains( $location, 'page=adf-updates' ) && $calls === $before + 1 && $wporg_calls === $before_wporg + 1, 'A valid POST must fetch fresh GitHub metadata and bypass the native WordPress recent-check guard.' );
	adf_update_expect( $original_auto_updates === get_site_option( 'auto_update_plugins', array() ), 'Checking must not change the user’s auto-update choices.' );
	$before = $calls;
	ob_start(); adf_render_update_status(); $html = ob_get_clean();
	adf_update_expect( str_contains( $html, ADF_PLUGIN_VERSION ) && str_contains( $html, '9.0.1' ) && str_contains( $html, 'Last successful check' ) && str_contains( $html, 'adf_check_updates' ) && $before === $calls, 'The diagnostic page must show versions/timestamps and a POST form without network requests on view.' );
	WP_CLI::success( 'Updater integration tests passed: fresh checks, errors, native update/no_update, authorization and diagnostics.' );
} finally {
	remove_filter( 'pre_http_request', $http, 10 );
	remove_filter( 'wp_die_handler', $deny );
	remove_filter( 'wp_redirect', $redirect, -999 );
	$_POST = $original_post;
	$_REQUEST = $original_request;
	$_SERVER = $original_server;
	if ( false === $original_wp_updates ) { delete_site_transient( 'update_plugins' ); } else { set_site_transient( 'update_plugins', $original_wp_updates ); }
	delete_transient( 'adf_github_update_error' );
	if ( false === $original_cache ) { delete_transient( 'adf_github_latest_release' ); } else { set_transient( 'adf_github_latest_release', $original_cache, 6 * HOUR_IN_SECONDS ); }
	if ( false === $original_status ) { delete_option( 'adf_github_update_status' ); } else { update_option( 'adf_github_update_status', $original_status ); }
	wp_set_current_user( $original_user );
}
