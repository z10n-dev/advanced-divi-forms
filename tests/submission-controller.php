<?php
/**
 * POST controller authorization and input regressions in disposable WordPress.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
class ADF_Controller_Die extends RuntimeException {}
class ADF_Controller_Redirect extends RuntimeException {}
function adf_controller_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function adf_controller_call( array $post, $method = 'POST', $export = false ) {
	$_POST = wp_slash( $post );
	$_REQUEST = $_POST;
	$_SERVER['REQUEST_METHOD'] = $method;
	try {
		if ( 'detail' === $export ) { adf_update_submission(); } elseif ( $export ) { adf_export_submissions(); } else { adf_manage_submissions(); }
		return 'returned';
	} catch ( ADF_Controller_Die $error ) {
		return $error->getCode();
	} catch ( ADF_Controller_Redirect $redirect ) {
		return $redirect->getMessage();
	}
}
$original_user = get_current_user_id();
$original_post = $_POST;
$original_request = $_REQUEST;
$original_server = $_SERVER;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$die = static function () { return static function ( $message, $title, $args ) { throw new ADF_Controller_Die( wp_strip_all_tags( $message ), $args['response'] ?? 500 ); }; };
$redirect = static function ( $url ) { throw new ADF_Controller_Redirect( $url ); };
add_filter( 'wp_die_handler', $die );
add_filter( 'wp_redirect', $redirect, -999 );
$form = adf_register_form( 'controller-' . wp_generate_uuid4(), 'Controller test' );
$fields = array( 'name' => 'Untouched' );
$id = adf_store_submission( $form, $fields );
try {
	$post = array( 'form_id' => (string) $form, 'submission_ids' => array( (string) $id ), 'operation' => 'quick_edit', 'note' => "O'Brien C:\\work", 'read' => '1', 'return_page' => '2', '_wpnonce' => wp_create_nonce( 'adf_manage_submissions_' . $form ) );
	adf_controller_expect( 405 === adf_controller_call( $post, 'GET' ) && ! get_post_meta( $id, '_adf_read', true ), 'GET must never change a submission.' );
	$invalid = $post;
	$invalid['_wpnonce'] = 'invalid';
	adf_controller_expect( 403 === adf_controller_call( $invalid ) && ! get_post_meta( $id, '_adf_read', true ), 'Invalid nonce must reject edits before mutation.' );
	$missing = $post;
	unset( $missing['note'] );
	adf_controller_expect( 400 === adf_controller_call( $missing ), 'A malformed Quick Edit must not silently clear a note.' );
	$location = adf_controller_call( $post );
	adf_controller_expect( is_string( $location ) && str_contains( $location, 'adf_paged=2' ) && str_contains( $location, 'form_id=' . $form ), 'Successful Quick Edit must redirect to its form and original page.' );
	adf_controller_expect( '1' === get_post_meta( $id, '_adf_read', true ) && "O'Brien C:\\work" === get_post_meta( $id, '_adf_note', true ) && $fields === get_post_meta( $id, '_adf_fields', true ), 'Controller must preserve notes exactly as plain text and never edit original fields.' );
	$filtered = array_merge( $post, array( 'adf_search' => 'Untouched', 'adf_status' => 'read', 'adf_from' => '2020-01-01', 'adf_to' => '2099-12-31', 'adf_order' => 'oldest' ) );
	$location = adf_controller_call( $filtered );
	adf_controller_expect( str_contains( $location, 'adf_search=Untouched' ) && str_contains( $location, 'adf_status=read' ) && str_contains( $location, 'adf_from=2020-01-01' ) && str_contains( $location, 'adf_to=2099-12-31' ) && str_contains( $location, 'adf_order=oldest' ) && str_contains( $location, 'adf_paged=2' ), 'Quick Edit must preserve every validated filter and page in its local redirect.' );
	$bad_filter = $filtered;
	$bad_filter['adf_from'] = '2026-02-31';
	$bad_filter['read'] = '0';
	adf_controller_expect( 400 === adf_controller_call( $bad_filter ) && '1' === get_post_meta( $id, '_adf_read', true ), 'Invalid filter context must be rejected before editing a record.' );
	$empty = $post;
	$empty['operation'] = 'export';
	$empty['submission_ids'] = array();
	adf_controller_expect( 400 === adf_controller_call( $empty ), 'An empty selected export must never fall back to exporting all records.' );
	$invalid['operation'] = 'delete';
	$invalid['_wpnonce'] = $post['_wpnonce'];
	adf_controller_expect( 400 === adf_controller_call( $invalid ) && get_post( $id ), 'Deletion without explicit confirmation must not remove records.' );
	$export = array( 'form_id' => (string) $form, '_wpnonce' => wp_create_nonce( 'adf_export_submissions_' . $form ) );
	adf_controller_expect( 405 === adf_controller_call( $export, 'GET', true ), 'Export-all must reject GET requests.' );
	$bad_export = $export;
	$bad_export['_wpnonce'] = 'invalid';
	adf_controller_expect( 403 === adf_controller_call( $bad_export, 'POST', true ), 'Exports must reject invalid nonces.' );
	$export['submission_ids'] = array();
	adf_controller_expect( 400 === adf_controller_call( $export, 'POST', true ), 'An explicit empty export selection must not export all.' );
	$export['submission_ids'] = array( (string) $form );
	adf_controller_expect( 400 === adf_controller_call( $export, 'POST', true ), 'Exports must reject IDs that are not answers from this form.' );
	$detail = array_merge( $filtered, array( 'submission_id' => (string) $id, 'adf_mark_read' => '1', '_wpnonce' => wp_create_nonce( 'adf_submission_' . $id ) ) );
	adf_controller_expect( 405 === adf_controller_call( $detail, 'GET', 'detail' ), 'Detail actions must reject non-POST requests.' );
	$location = adf_controller_call( $detail, 'POST', 'detail' );
	adf_controller_expect( str_contains( $location, 'adf_search=Untouched' ) && str_contains( $location, 'adf_order=oldest' ) && str_contains( $location, 'adf_paged=2' ), 'Detail actions must preserve the validated list context.' );
	wp_set_current_user( 0 );
	adf_controller_expect( 403 === adf_controller_call( $post ), 'Anonymous callers must not reach the action handler.' );
	WP_CLI::success( 'Submission POST controller tests passed.' );
} finally {
	wp_set_current_user( $admins[0]->ID );
	wp_delete_post( $id, true );
	wp_delete_post( $form, true );
	remove_filter( 'wp_die_handler', $die );
	remove_filter( 'wp_redirect', $redirect, -999 );
	$_POST = $original_post;
	$_REQUEST = $original_request;
	$_SERVER = $original_server;
	wp_set_current_user( $original_user );
}
