<?php
/**
 * Update Advanced Divi Forms from public GitHub releases.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'update_plugins_github.com', 'adf_check_github_update', 10, 3 );

/**
 * Provide WordPress with release data for this plugin only.
 *
 * @param array|false $update Existing update information.
 * @param array       $plugin_data Plugin headers.
 * @param string      $plugin_file Relative plugin file path.
 * @return array|false
 */
function adf_check_github_update( $update, $plugin_data, $plugin_file ) {
	if ( 'advanced-divi-forms/advanced-divi-forms.php' !== $plugin_file ) {
		return $update;
	}

	$cache_key = 'adf_github_latest_release';
	$release   = get_transient( $cache_key );

	if ( false === $release ) {
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

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $update;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) ) {
			return $update;
		}
		set_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );
	}

	$tag = $release['tag_name'] ?? '';
	if ( ! is_string( $tag ) || ! preg_match( '/^v?(\d+\.\d+\.\d+)$/', $tag, $matches ) ) {
		return $update;
	}

	$version = $matches[1];
	// WordPress compares versions and needs metadata even when no update is available.

	$package_url = '';
	foreach ( $release['assets'] ?? array() as $asset ) {
		if ( isset( $asset['name'], $asset['browser_download_url'] )
			&& 'advanced-divi-forms.zip' === $asset['name']
			&& is_string( $asset['browser_download_url'] )
			&& 0 === strpos( $asset['browser_download_url'], 'https://github.com/z10n-dev/advanced-divi-forms/releases/download/' ) ) {
			$package_url = $asset['browser_download_url'];
			break;
		}
	}

	if ( '' === $package_url ) {
		return $update;
	}

	return array(
		'id'           => 'https://github.com/z10n-dev/advanced-divi-forms',
		'slug'         => 'advanced-divi-forms',
		'version'      => $version,
		'url'          => 'https://github.com/z10n-dev/advanced-divi-forms/releases',
		'package'      => $package_url,
		'requires_php' => '8.2',
	);
}
