<?php
/**
 * Run with: php tests/github-updater.php
 *
 * WordPress stubs keep the cached-release regression tests offline.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
class WP_Error {
	public function __construct( public $code, public $message, public $data = null ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $message, $domain = '' ) { return $message; }
function get_option( $key, $default = false ) { return $default; }
function update_option( ...$args ) { return true; }
function delete_transient( $key ) {
	if ( 'adf_github_latest_release' === $key ) { $GLOBALS['adf_test_release'] = false; }
	return true;
}
function set_transient( $key, $value, $ttl ) {
	if ( 'adf_github_latest_release' === $key ) { $GLOBALS['adf_test_release'] = $value; }
	return true;
}
function wp_remote_get( ...$args ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $GLOBALS['adf_test_release'] ) ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $name ) { return ''; }

function add_filter( ...$args ) {
	return true;
}

function get_transient( $key ) {
	return 'adf_github_latest_release' === $key ? $GLOBALS['adf_test_release'] : false;
}

require_once __DIR__ . '/../includes/github-updater.php';

function adf_test_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$plugin_file = 'advanced-divi-forms/advanced-divi-forms.php';
$package_url = 'https://github.com/z10n-dev/advanced-divi-forms/releases/download/v0.1.2/advanced-divi-forms.zip';
$release     = array(
	'tag_name' => 'v0.1.2',
	'assets'   => array(
		array(
			'name'                 => 'advanced-divi-forms.zip',
			'browser_download_url' => $package_url,
		),
	),
);
$GLOBALS['adf_test_release'] = $release;

// WordPress needs metadata for both response and no_update entries.
foreach ( array( '0.1.1', '0.1.2', '0.1.3' ) as $installed_version ) {
	$result = adf_check_github_update( false, array( 'Version' => $installed_version ), $plugin_file );
	adf_test_expect( is_array( $result ), "Missing metadata for installed version $installed_version" );
	adf_test_expect( '0.1.2' === $result['version'], 'The actual release version must be returned.' );
	adf_test_expect( $package_url === $result['package'], 'The release ZIP must be preserved.' );
	adf_test_expect( ! isset( $result['autoupdate'] ), 'Auto-updates must remain the user\'s choice.' );
}

$existing = array( 'version' => '9.0.0' );
adf_test_expect(
	$existing === adf_check_github_update( $existing, array(), 'another-plugin/plugin.php' ),
	'Other plugins must be left untouched.'
);

$GLOBALS['adf_test_release']['tag_name'] = 'invalid';
adf_test_expect( false === adf_check_github_update( false, array(), $plugin_file ), 'Invalid tags must be rejected.' );

$GLOBALS['adf_test_release'] = $release;
$GLOBALS['adf_test_release']['assets'] = array();
adf_test_expect( false === adf_check_github_update( false, array(), $plugin_file ), 'A release without the plugin ZIP must be rejected.' );

$GLOBALS['adf_test_release'] = $release;
$GLOBALS['adf_test_release']['assets'][0]['browser_download_url'] = 'https://example.org/plugin.zip';
adf_test_expect( false === adf_check_github_update( false, array(), $plugin_file ), 'Untrusted package URLs must be rejected.' );

foreach ( array(
	array_merge( $release, array( 'draft' => true ) ),
	array_merge( $release, array( 'prerelease' => true ) ),
	array_merge( $release, array( 'assets' => 'not-an-array' ) ),
	array_merge( $release, array( 'assets' => array( 'not-an-asset' ) ) ),
	array_merge( $release, array( 'tag_name' => "v0.1.2\n" ) ),
	null,
) as $invalid_release ) {
	adf_test_expect( is_wp_error( adf_parse_github_release( $invalid_release ) ), 'Malformed, draft and prerelease responses must not become update packages.' );
}
echo "GitHub updater regression tests passed.\n";
