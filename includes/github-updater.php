<?php
/**
 * Update Advanced Divi Forms from validated public GitHub releases.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'update_plugins_github.com', 'adf_check_github_update', 10, 3 );

/**
 * Validate a GitHub release before exposing an installation package.
 *
 * @param mixed $release Decoded GitHub response or cached release.
 * @return array|WP_Error Native WordPress metadata or a validation error.
 */
function adf_parse_github_release( $release ) {
	if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
		return new WP_Error( 'adf_invalid_release', __( 'GitHub did not return a published stable release.', 'advanced-divi-forms' ) );
	}
	$tag = $release['tag_name'] ?? '';
	if ( ! is_string( $tag ) || ! preg_match( '/^v?(\d+\.\d+\.\d+)$/D', $tag, $matches ) ) {
		return new WP_Error( 'adf_invalid_release', __( 'The release has an invalid version tag.', 'advanced-divi-forms' ) );
	}
	$expected = 'https://github.com/z10n-dev/advanced-divi-forms/releases/download/' . $tag . '/advanced-divi-forms.zip';
	$assets   = $release['assets'] ?? array();
	if ( is_array( $assets ) ) {
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && 'advanced-divi-forms.zip' === ( $asset['name'] ?? '' ) && ( $asset['browser_download_url'] ?? '' ) === $expected ) {
				return array(
					'id'           => 'https://github.com/z10n-dev/advanced-divi-forms',
					'slug'         => 'advanced-divi-forms',
					'version'      => $matches[1],
					'url'          => 'https://github.com/z10n-dev/advanced-divi-forms/releases',
					'package'      => $expected,
					'requires_php' => '8.2',
				);
			}
		}
	}
	return new WP_Error( 'adf_missing_release_zip', __( 'The release is missing its expected plugin ZIP. No package will be offered.', 'advanced-divi-forms' ) );
}

/**
 * Record network-attempt diagnostics without storing personal data or secrets.
 *
 * @param array|WP_Error $result      Check result.
 * @param int            $http_status GitHub HTTP status, or zero for transport errors.
 * @return void
 */
function adf_record_update_check( $result, $http_status ) {
	$previous = get_option( 'adf_github_update_status', array() );
	$previous = is_array( $previous ) ? $previous : array();
	$status   = array(
		'checked_at'      => time(),
		'last_success_at' => (int) ( $previous['last_success_at'] ?? 0 ),
		'latest_version'  => (string) ( $previous['latest_version'] ?? '' ),
		'http_status'     => (int) $http_status,
		'error_code'      => '',
		'error_message'   => '',
		'retry_at'        => 0,
	);
	if ( is_wp_error( $result ) ) {
		$status['error_code']    = $result->get_error_code();
		$status['error_message'] = $result->get_error_message();
		$data                    = $result->get_error_data();
		$status['retry_at']      = is_array( $data ) ? (int) ( $data['retry_at'] ?? 0 ) : 0;
		set_transient( 'adf_github_update_error', $result, MINUTE_IN_SECONDS );
	} else {
		$status['last_success_at'] = $status['checked_at'];
		$status['latest_version']  = $result['version'];
		delete_transient( 'adf_github_update_error' );
	}
	update_option( 'adf_github_update_status', $status, false );
}

/**
 * Read validated update metadata, optionally bypassing both plugin cache layers.
 *
 * @param bool $force Request a fresh GitHub response even if a cache exists.
 * @return array|WP_Error
 */
function adf_get_github_update( $force = false ) {
	if ( $force ) {
		delete_transient( 'adf_github_latest_release' );
		delete_transient( 'adf_github_update_error' );
	}
	$release = get_transient( 'adf_github_latest_release' );
	if ( false !== $release ) {
		$cached = adf_parse_github_release( $release );
		if ( ! is_wp_error( $cached ) ) {
			return $cached;
		}
		delete_transient( 'adf_github_latest_release' );
	}
	$error = get_transient( 'adf_github_update_error' );
	if ( is_wp_error( $error ) ) {
		return $error;
	}
	$response = wp_remote_get(
		'https://api.github.com/repos/z10n-dev/advanced-divi-forms/releases/latest',
		array(
			'timeout' => 8,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Advanced-Divi-Forms-WordPress-Updater',
			),
		)
	);
	$http     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	if ( is_wp_error( $response ) ) {
		$result = new WP_Error( 'adf_update_network', __( 'GitHub could not be reached. Check the server’s network, TLS and proxy configuration.', 'advanced-divi-forms' ) );
	} elseif ( 429 === $http || ( 403 === $http && '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) ) {
		$result = new WP_Error( 'adf_update_rate_limit', __( 'GitHub’s request limit has been reached. Try again after the rate limit resets.', 'advanced-divi-forms' ), array( 'retry_at' => absint( wp_remote_retrieve_header( $response, 'x-ratelimit-reset' ) ) ) );
	} elseif ( 200 !== $http ) {
		$result = new WP_Error( 'adf_update_http', __( 'GitHub returned an unsuccessful HTTP response. Check the status code below and retry later.', 'advanced-divi-forms' ) );
	} else {
		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		$result  = adf_parse_github_release( $release );
		if ( ! is_wp_error( $result ) ) {
			set_transient( 'adf_github_latest_release', $release, 6 * HOUR_IN_SECONDS );
		}
	}
	adf_record_update_check( $result, $http );
	return $result;
}

/**
 * Provide WordPress metadata for this plugin only, including no-update entries.
 *
 * @param array|false $update      Existing update information.
 * @param array       $plugin_data Plugin headers.
 * @param string      $plugin_file Relative plugin filename.
 * @return array|false
 */
function adf_check_github_update( $update, $plugin_data, $plugin_file ) {
	if ( 'advanced-divi-forms/advanced-divi-forms.php' !== $plugin_file ) {
		return $update;
	}
	$result = adf_get_github_update();
	return is_wp_error( $result ) ? $update : $result;
}
