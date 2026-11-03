<?php
/**
 * Divi integration guard: a site without Divi 5.16 is told instead of silently
 * doing nothing. Disposable WordPress only.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
function adf_divi_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
adf_divi_expect( function_exists( 'adf_evaluate_divi_support' ), 'The integration needs an explicit Divi compatibility check.' );

$cases = array(
	array( null, 'missing' ),
	array( '', 'missing' ),
	array( '4.27.4', 'outdated' ),
	array( '5.15.0', 'outdated' ),
	array( '5.16.0', 'supported' ),
	array( '5.20.1', 'supported' ),
	array( 'unknown-build', 'outdated' ),
);
foreach ( $cases as $case ) {
	$support = adf_evaluate_divi_support( $case[0] );
	adf_divi_expect( is_array( $support ) && array_key_exists( 'status', $support ) && array_key_exists( 'minimum', $support ) && array_key_exists( 'detected', $support ), 'Support evaluation must return status, minimum and detected values.' );
	adf_divi_expect( $case[1] === $support['status'], 'Version ' . var_export( $case[0], true ) . ' must evaluate to ' . $case[1] . ', got ' . $support['status'] . '.' );
	adf_divi_expect( '5.16.0' === $support['minimum'], 'The documented minimum Divi version must stay 5.16.0.' );
}

$detected = adf_detect_divi();
adf_divi_expect( is_array( $detected ) && array_key_exists( 'version', $detected ) && array_key_exists( 'theme', $detected ), 'Detection must always report theme and version keys.' );
$live = adf_evaluate_divi_support( $detected['version'], $detected['theme'] );
adf_divi_expect( 'missing' === $live['status'], 'This disposable site has no Divi theme, so the guard must report a missing integration.' );

$original_user = get_current_user_id();
$created_users = array();
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
try {
	wp_set_current_user( $admin->ID );
	ob_start();
	adf_render_divi_support_notice( adf_evaluate_divi_support( '5.15.0', 'Divi' ) );
	$notice = (string) ob_get_clean();
	adf_divi_expect( false !== strpos( $notice, '5.16' ), 'An outdated Divi must be reported with the required minimum version.' );
	adf_divi_expect( false !== strpos( $notice, '5.15.0' ), 'The notice must name the detected version so the site owner can act.' );
	adf_divi_expect( false !== strpos( $notice, 'notice-warning' ), 'The compatibility warning must use a warning notice class.' );

	ob_start();
	adf_render_divi_support_notice( adf_evaluate_divi_support( null ) );
	$missing_notice = (string) ob_get_clean();
	adf_divi_expect( '' !== trim( $missing_notice ), 'A site without Divi must be warned instead of silently doing nothing.' );

	ob_start();
	adf_render_divi_support_notice( adf_evaluate_divi_support( '<script>alert(1)</script>' ) );
	$escaped = (string) ob_get_clean();
	adf_divi_expect( false === strpos( $escaped, '<script>' ), 'Detected theme data must never be printed unescaped.' );

	ob_start();
	adf_render_divi_support_notice( adf_evaluate_divi_support( '5.16.0', 'Divi' ) );
	adf_divi_expect( '' === trim( (string) ob_get_clean() ), 'A supported Divi must not produce any notice.' );

	$editor = wp_insert_user( array( 'user_login' => 'adf-divi-editor-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'divi-' . wp_generate_uuid4() . '@example.org', 'role' => 'editor' ) );
	$created_users[] = $editor;
	wp_set_current_user( 0 );
	wp_set_current_user( $editor );
	ob_start();
	adf_render_divi_support_notice( adf_evaluate_divi_support( null ) );
	adf_divi_expect( '' === trim( (string) ob_get_clean() ), 'Editors must not see administrator-only compatibility warnings.' );

	wp_set_current_user( $admin->ID );
	$summary = adf_divi_support_summary();
	adf_divi_expect( is_string( $summary ) && '' !== $summary, 'The updates page needs a readable Divi compatibility summary.' );
	adf_divi_expect( false !== stripos( $summary, 'not detected' ), 'Without Divi the summary must state that no Divi theme was detected, got: ' . $summary );

	ob_start();
	adf_render_update_status();
	$updates_page = (string) ob_get_clean();
	adf_divi_expect( false !== strpos( $updates_page, 'Divi integration' ), 'The updates page must show the Divi compatibility row.' );
	adf_divi_expect( false !== stripos( $updates_page, 'not detected' ), 'The updates page must show the detection result for this site.' );
	WP_CLI::success( 'Divi compatibility guard, escaped notice, capability gating and updates summary passed.' );
} finally {
	foreach ( $created_users as $user ) {
		if ( get_userdata( $user ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user ); }
	}
	wp_set_current_user( $original_user );
}
