<?php
/** Filter query regressions. Run only in disposable WordPress through WP-CLI. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
function adf_filter_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$original_user = get_current_user_id();
$original_get = $_GET;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$form = adf_register_form( 'filters-' . wp_generate_uuid4(), 'Filter test' );
$other_form = adf_register_form( 'filters-other-' . wp_generate_uuid4(), 'Other filter test' );
$alice = adf_store_submission( $form, array( 'name' => 'Alice', 'email' => 'alice@example.org', 'message' => 'North%_1' ) );
$bob = adf_store_submission( $form, array( 'name' => 'Bob', 'email' => 'bob@example.org', 'message' => 'Needle project' ) );
$charlie = adf_store_submission( $form, array( 'name' => 'Charlie', 'email' => 'charlie@example.org', 'message' => 'Needle project' ) );
$foreign = adf_store_submission( $other_form, array( 'email' => 'alice@example.org' ) );
wp_update_post( array( 'ID' => $alice, 'post_date' => '2026-10-01 10:00:00', 'post_date_gmt' => get_gmt_from_date( '2026-10-01 10:00:00' ) ) );
foreach ( array( $bob, $charlie ) as $id ) { wp_update_post( array( 'ID' => $id, 'post_date' => '2026-10-02 10:00:00', 'post_date_gmt' => get_gmt_from_date( '2026-10-02 10:00:00' ) ) ); }
update_post_meta( $bob, '_adf_read', 1 );
delete_post_meta( $charlie, '_adf_read' );
try {
	$query = adf_submission_query( $form, 1, 20, array( 'adf_search' => 'alice@example.org' ) );
	adf_filter_expect( ! is_wp_error( $query ) && 1 === (int) $query->found_posts && $alice === $query->posts[0]->ID, 'Search must match submitted email values and never cross the selected form.' );
	$unread = adf_submission_query( $form, 1, 20, array( 'adf_status' => 'unread' ) );
	adf_filter_expect( array( $charlie, $alice ) === wp_list_pluck( $unread->posts, 'ID' ), 'Unread status must include legacy records without read metadata and exclude read records.' );
	$read = adf_submission_query( $form, 1, 20, array( 'adf_status' => 'read' ) );
	adf_filter_expect( array( $bob ) === wp_list_pluck( $read->posts, 'ID' ), 'Read status must return only read submissions.' );
	$dated = adf_submission_query( $form, 1, 20, array( 'adf_from' => '2026-10-02', 'adf_to' => '2026-10-02' ) );
	adf_filter_expect( array( $charlie, $bob ) === wp_list_pluck( $dated->posts, 'ID' ), 'Date ranges must include the whole selected local day, excluding previous days.' );
	foreach ( array( array( 'adf_from' => '2026-02-31' ), array( 'adf_from' => '2026-10-03', 'adf_to' => '2026-10-01' ), array( 'adf_search' => array( 'bad' ) ), array( 'adf_status' => 'injected' ), array( 'adf_order' => 'RAND()' ) ) as $invalid ) {
		adf_filter_expect( is_wp_error( adf_submission_query( $form, 1, 20, $invalid ) ), 'Invalid filter types, dates, status or sorting must fail closed before querying.' );
	}
	$oldest = adf_submission_query( $form, 1, 1, array( 'adf_order' => 'oldest' ) );
	$second = adf_submission_query( $form, 2, 1, array( 'adf_order' => 'oldest' ) );
	adf_filter_expect( $alice === $oldest->posts[0]->ID && $bob === $second->posts[0]->ID && 3 === (int) $oldest->found_posts, 'Oldest-first pagination must use a stable date/ID tie-breaker.' );
	$_GET = array( 'form_id' => (string) $form, 'adf_search' => 'Needle', 'adf_status' => 'unread', 'adf_from' => '2026-10-02', 'adf_to' => '2026-10-02', 'adf_order' => 'oldest' );
	ob_start(); adf_render_submission_list( get_post( $form ) ); $html = ob_get_clean();
	$dom = new DOMDocument(); @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html ); $xpath = new DOMXPath( $dom );
	adf_filter_expect( 1 === $xpath->query( '//tr[@data-adf-submission]' )->length && $xpath->query( '//tr[@data-adf-submission]' )->item(0)->getAttribute( 'data-adf-submission' ) === (string) $charlie, 'The rendered list must apply all filters together.' );
	adf_filter_expect( str_contains( $html, 'name="adf_search"' ) && str_contains( $html, 'value="filtered"' ) && str_contains( $html, 'Export filtered to Excel' ), 'Filter controls and distinct filtered/all export scopes must be visible.' );
	$_GET['adf_paged'] = '2';
	ob_start(); adf_render_submission( get_post( $form ), get_post( $charlie ) ); $detail_html = ob_get_clean();
	$detail_dom = new DOMDocument(); @$detail_dom->loadHTML( '<?xml encoding="UTF-8">' . $detail_html ); $detail_xpath = new DOMXPath( $detail_dom );
	$back = $detail_xpath->query( '//a' )->item(0)->getAttribute( 'href' );
	parse_str( wp_parse_url( $back, PHP_URL_QUERY ), $context );
	adf_filter_expect( 'Needle' === ( $context['adf_search'] ?? '' ) && 'unread' === ( $context['adf_status'] ?? '' ) && '2026-10-02' === ( $context['adf_from'] ?? '' ) && 'oldest' === ( $context['adf_order'] ?? '' ) && '2' === (string) ( $context['adf_paged'] ?? '' ), 'Returning from a detail must preserve search/status/dates/sort and the original page.' );
	$file = adf_create_submission_export( $form, array(), array( 'adf_search' => 'Needle', 'adf_status' => 'unread', 'adf_from' => '2026-10-02', 'adf_to' => '2026-10-02' ) );
	adf_filter_expect( is_string( $file ), 'A filtered export must produce a private XLSX.' );
	$zip = new ZipArchive(); $zip->open( $file ); $sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' ); $zip->close(); wp_delete_file( $file );
	adf_filter_expect( 2 === substr_count( $sheet, '<row ' ) && str_contains( $sheet, 'charlie@example.org' ) && ! str_contains( $sheet, 'bob@example.org' ), 'Filtered Excel exports must include exactly the same matches as the list, not all form answers.' );
	foreach ( array( 'Alice', 'alice@example.org', 'North%_1', '%', '_' ) as $term ) {
		$literal = adf_submission_query( $form, 1, 20, array( 'adf_search' => $term, 'post_parent' => $other_form, 'post_status' => 'any' ) );
		adf_filter_expect( array( $alice ) === wp_list_pluck( $literal->posts, 'ID' ), 'Search must match literal submitted text, escape LIKE wildcards and ignore foreign query overrides. Term: ' . $term . ' Matched IDs: ' . wp_json_encode( wp_list_pluck( $literal->posts, 'ID' ) ) );
	}
	$injection = adf_submission_query( $form, 1, 20, array( 'adf_search' => "%' OR 1=1 --" ) );
	adf_filter_expect( 0 === (int) $injection->found_posts && 3 === (int) adf_submission_query( $form )->found_posts, 'SQL-looking search text must stay a literal and never widen the form query or alter records.' );
	$empty_file = adf_create_submission_export( $form, array(), array( 'adf_search' => 'No matching synthetic value' ) );
	$zip->open( $empty_file ); $empty_sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' ); $zip->close(); wp_delete_file( $empty_file );
	adf_filter_expect( 1 === substr_count( $empty_sheet, '<row ' ), 'An empty filtered export must contain only headers, not silently export all.' );
	$selected_file = adf_create_submission_export( $form, array( $alice ), array( 'adf_search' => 'Needle', 'adf_status' => 'read' ) );
	$zip->open( $selected_file ); $selected_sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' ); $zip->close(); wp_delete_file( $selected_file );
	adf_filter_expect( 2 === substr_count( $selected_sheet, '<row ' ) && str_contains( $selected_sheet, 'alice@example.org' ), 'Explicit selections must not disappear because a status edit made the old filters stale.' );
	adf_filter_expect( is_wp_error( adf_create_submission_export( $form, array(), array( 'adf_from' => '2026-02-31' ) ) ), 'Exports must reject invalid date filters before generating data.' );
	WP_CLI::success( 'Submission filter integration tests passed.' );
} finally {
	foreach ( array( $alice, $bob, $charlie, $foreign, $form, $other_form ) as $id ) { wp_delete_post( $id, true ); }
	$_GET = $original_get;
	wp_set_current_user( $original_user );
}
