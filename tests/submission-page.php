<?php
/**
 * Rendered submission-page contracts in a disposable WordPress installation.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
function adf_page_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
$original_user = get_current_user_id();
$original_get = $_GET;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$form = adf_register_form( 'page-test-' . wp_generate_uuid4(), 'Page test' );
$answer = adf_store_submission( $form, array( 'name' => 'A&B', 'message' => 'Original' ) );
update_post_meta( $answer, '_adf_note', '<img src=x onerror=alert(1)>Private & note' );
try {
	$_GET = array( 'form_id' => $form, 'adf_paged' => 2 );
	ob_start();
	adf_render_submissions( get_post( $form ) );
	$html = ob_get_clean();
	adf_page_expect( str_contains( $html, 'adf_export_submissions' ) && str_contains( $html, 'Export all to Excel' ), 'The submission page must provide a scoped Excel-export POST form.' );
	$_GET['adf_paged'] = 1;
	ob_start();
	adf_render_submissions( get_post( $form ) );
	$html = ob_get_clean();
	adf_page_expect( str_contains( $html, 'name="submission_ids[]"' ) && str_contains( $html, 'adf-bulk-form' ), 'Submission rows must have individually scoped selection checkboxes.' );
	adf_page_expect( str_contains( $html, 'value="mark_read"' ) && str_contains( $html, 'value="mark_unread"' ) && str_contains( $html, 'value="export"' ) && str_contains( $html, 'value="delete"' ), 'The bulk toolbar must expose read, unread, export and delete.' );
	adf_page_expect( str_contains( $html, 'Quick Edit' ) && str_contains( $html, 'name="note"' ) && str_contains( $html, 'name="read"' ), 'Quick Edit must provide status and note controls.' );
	adf_page_expect( ! str_contains( $html, 'onerror=' ) && str_contains( $html, 'Private &amp; note' ), 'Internal notes must render as escaped plain text.' );
	$dom = new DOMDocument();
	@$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
	$xpath = new DOMXPath( $dom );
	adf_page_expect( 0 === $xpath->query( '//form//form' )->length, 'Quick-edit forms must never be nested inside the bulk form.' );
	adf_page_expect( 0 === $xpath->query( '//textarea[starts-with(@name,"fields")]' )->length, 'Original submission fields must not be editable.' );
	adf_page_expect( str_contains( $html, 'confirm_delete' ) && str_contains( $html, 'return_page' ), 'Destructive bulk actions need confirmation and must preserve pagination.' );
	WP_CLI::success( 'Submission-page rendering tests passed.' );
} finally {
	wp_delete_post( $answer, true );
	wp_delete_post( $form, true );
	$_GET = $original_get;
	wp_set_current_user( $original_user );
}
