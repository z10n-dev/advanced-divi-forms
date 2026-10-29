<?php
/**
 * Discovery regressions in disposable WordPress: legacy, missing IDs and reports.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

function adf_discovery_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

class ADF_Discovery_Redirect extends RuntimeException {}

$created = array();
$original_user = get_current_user_id();
$original_post = $_POST;
$original_request = $_REQUEST;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$report_key = 'adf_discovery_' . get_current_user_id();
$old_report = get_transient( $report_key );
$redirect = static function ( $url ) { throw new ADF_Discovery_Redirect( $url ); };
add_filter( 'wp_redirect', $redirect, -999 );

try {
	$uuid = wp_generate_uuid4();
	$legacy = '[et_pb_section][et_pb_row][et_pb_column][et_pb_contact_form title="Legacy Contact" _unique_id="' . $uuid . '"][et_pb_contact_field field_id="Email" /][/et_pb_contact_form][/et_pb_column][/et_pb_row][/et_pb_section]';
	$parsed = adf_extract_divi_forms( $legacy );
	adf_discovery_expect( 1 === count( $parsed ) && $uuid === $parsed[0]['uuid'] && 'shortcode' === $parsed[0]['format'], 'Nested legacy Divi shortcodes must be detected without executing them.' );
	adf_discovery_expect( ! adf_extract_divi_forms( '[[et_pb_contact_form title="Example"]]' ), 'Escaped shortcode examples are not forms.' );
	adf_discovery_expect( ! adf_extract_divi_forms( '[et_pb_contact_form_7 /]' ), 'Other shortcode names must not become native Divi forms.' );
	$page = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Legacy discovery fixture', 'post_content' => $legacy ) ), true );
	$created[] = $page;
	$result = adf_sync_divi_post( $page, get_post( $page ) );
	adf_discovery_expect( 1 === $result['found'] && 1 === $result['indexed'] && ! $result['issues'], 'Legacy forms with stable UUIDs must be indexed.' );
	$form = adf_resolve_divi_form( $page, $uuid, array() );
	$created[] = $form;
	adf_discovery_expect( 'Legacy Contact' === get_post( $form )->post_title, 'An empty legacy admin label must not hide the visible form title.' );
	adf_discovery_expect( 'shortcode' === get_post_meta( $form, '_adf_divi_source_format', true ), 'Legacy forms need a format marker, not a false claim of active Divi 5 support.' );
	$attrs = array( 'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Missing UUID Contact' ) ) ) );
	$missing_content = '<!-- wp:divi/section --><!-- wp:divi/contact-form ' . wp_json_encode( $attrs ) . ' /--><!-- /wp:divi/section -->';
	$missing = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'No UUID discovery fixture', 'post_content' => $missing_content ) ), true );
	$created[] = $missing;
	$result = adf_sync_divi_post( $missing, get_post( $missing ) );
	adf_discovery_expect( 1 === $result['found'] && 0 === $result['indexed'] && 'missing_uuid' === $result['issues'][0]['reason'], 'A form without a UUID must be reported instead of silently disappearing.' );
	adf_discovery_expect( $missing_content === get_post( $missing )->post_content, 'Discovery must never assign IDs by rewriting source content.' );
	$old_missing = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Old form without UUID', 'post_content' => '[et_pb_contact_form title="Old Contact"][/et_pb_contact_form]' ), true );
	$created[] = $old_missing;
	$plain = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Unrelated page', 'post_content' => 'Ordinary page content' ), true );
	$created[] = $plain;
	$candidates = adf_divi_discovery_query()->posts;
	$ids = array_map( static function ( $post ) { return $post->ID; }, $candidates );
	adf_discovery_expect( in_array( $page, $ids, true ) && in_array( $missing, $ids, true ) && in_array( $old_missing, $ids, true ) && ! in_array( $plain, $ids, true ), 'The actual source query must find both formats, including drafts, without scanning unrelated content.' );
	$normal = new WP_Query( array( 'post_type' => 'page', 'post__in' => array( $plain ) ) );
	adf_discovery_expect( 1 === $normal->post_count, 'Discovery must remove its SQL filter before unrelated queries run.' );

	$_POST = array( 'scan_page' => '1' );
	$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'adf_discover_forms' ) );
	try { adf_discover_divi_forms(); } catch ( ADF_Discovery_Redirect $done ) {
		adf_discovery_expect( str_contains( $done->getMessage(), 'scan_done=1' ), 'Discovery must return to the admin overview.' );
	}
	$report = get_transient( $report_key );
	adf_discovery_expect( 3 === $report['found'] && 1 === $report['indexed'] && 2 === count( $report['issues'] ), 'The UI report must distinguish found forms from indexed forms.' );
	ob_start();
	adf_render_discovery_report();
	$output = ob_get_clean();
	adf_discovery_expect( str_contains( $output, 'Missing UUID Contact' ) && str_contains( $output, 'Old Contact' ) && str_contains( $output, 'No persistent module UUID' ), 'Forms requiring attention must be visible with an actionable reason.' );

	// The same UUID after migration must retain the existing index and answers.
	$answer = adf_store_submission( $form, array( 'message' => 'Synthetic historical answer' ) );
	$created[] = $answer;
	$attrs['module']['advanced']['uniqueId']['desktop']['value'] = $uuid;
	$converted = '<!-- wp:divi/contact-form ' . wp_json_encode( $attrs ) . ' /-->';
	wp_update_post( wp_slash( array( 'ID' => $page, 'post_content' => $converted ) ) );
	adf_discovery_expect( $form === adf_resolve_divi_form( $page, $uuid, $attrs ) && 1 === adf_submission_query( $form )->found_posts, 'Migration must not create another form or orphan historical answers.' );
	adf_discovery_expect( 'block' === get_post_meta( $form, '_adf_divi_source_format', true ), 'Migrated forms must lose the legacy marker.' );
	WP_CLI::success( 'Discovery regressions passed: actual source query, legacy shortcodes, missing UUID diagnostics and non-destructive migration.' );
} finally {
	remove_filter( 'wp_redirect', $redirect, -999 );
	$_POST = array();
	foreach ( array_reverse( $created ) as $id ) { if ( is_int( $id ) ) { wp_delete_post( $id, true ); } }
	if ( false === $old_report ) { delete_transient( $report_key ); } else { set_transient( $report_key, $old_report, HOUR_IN_SECONDS ); }
	$_POST = $original_post;
	$_REQUEST = $original_request;
	wp_set_current_user( $original_user );
}
