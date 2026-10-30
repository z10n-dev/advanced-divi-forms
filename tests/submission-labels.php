<?php
/** Historical labels, independent from immutable original values. Disposable WP only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
function adf_labels_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$created = array();
$original_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$reject_mail = static function () { throw new RuntimeException( 'Label tests may not send email.' ); };
add_filter( 'pre_wp_mail', $reject_mail );
try {
	$form = adf_register_form( 'labels-' . wp_generate_uuid4(), 'Historical labels' ); $created[] = $form;
	$fields = array( 'first' => "Ada O'Brien", 'second' => '00123', 'unlabelled' => '=1+1' );
	$id = adf_store_submission( $form, $fields, array( 'first' => '<b>Shared label</b>', 'second' => 'Shared label', 'foreign' => 'Do not store', 'unlabelled' => array( 'invalid' ) ) ); $created[] = $id;
	adf_labels_expect( array( 'first' => 'Shared label', 'second' => 'Shared label' ) === get_post_meta( $id, '_adf_field_labels', true ), 'Storage must snapshot valid labels separately, keyed only by actually submitted IDs.' );
	adf_labels_expect( $fields === get_post_meta( $id, '_adf_fields', true ), 'Label metadata must never alter original values, stable IDs or slash handling.' );
	adf_labels_expect( function_exists( 'adf_get_submission_labels' ) && function_exists( 'adf_submission_field_title' ), 'Historical display needs a snapshot-only label accessor with legacy ID fallback.' );
	adf_labels_expect( array( 'first' => 'Shared label', 'second' => 'Shared label', 'unlabelled' => 'unlabelled' ) === adf_get_submission_labels( $id ), 'Each ID needs its own label and missing labels must fall back to that ID.' );
	$later = adf_store_submission( $form, $fields, array( 'first' => 'Renamed field', 'second' => 'Another name' ) ); $created[] = $later;
	update_post_meta( $form, '_adf_field_labels', array( 'first' => 'Current form name' ) );
	adf_labels_expect( 'Shared label [first]' === adf_submission_field_title( $id, 'first' ) && 'Renamed field [first]' === adf_submission_field_title( $later, 'first' ), 'Later names must not rewrite a historical label or collapse matching field IDs.' );
	$legacy = adf_store_submission( $form, array( 'first' => 'Legacy value' ) ); $created[] = $legacy;
	delete_post_meta( $legacy, '_adf_field_labels' );
	adf_labels_expect( array( 'first' => 'first' ) === adf_get_submission_labels( $legacy ), 'Legacy rows without snapshots must retain exact ID fallback, never borrow current form names.' );
	ob_start(); adf_render_submission_list( get_post( $form ) ); $list_html = ob_get_clean();
	adf_labels_expect( str_contains( $list_html, 'Shared label [first]' ) && str_contains( $list_html, 'Shared label [second]' ), 'The answer list must show historical labels without hiding duplicate-label field IDs.' );
	ob_start(); adf_render_submission( get_post( $form ), get_post( $id ) ); $detail_html = ob_get_clean();
	adf_labels_expect( str_contains( $detail_html, 'Shared label [first]' ) && str_contains( $detail_html, 'Shared label [second]' ), 'Answer details need stable historical labels plus original IDs.' );
	$before_trash = get_post_meta( $id, '_adf_field_labels', true );
	adf_trash_submission( $id ); adf_restore_submission( $id );
	adf_labels_expect( $before_trash === get_post_meta( $id, '_adf_field_labels', true ) && $fields === get_post_meta( $id, '_adf_fields', true ), 'Trash/restore must preserve new historical metadata and original values together.' );
	$legacy_file = adf_create_submission_export( $form, array( $legacy ) );
	$legacy_zip = new ZipArchive(); $legacy_zip->open( $legacy_file ); $legacy_sheet = $legacy_zip->getFromName( 'xl/worksheets/sheet1.xml' ); $legacy_zip->close(); wp_delete_file( $legacy_file );
	adf_labels_expect( ! str_contains( $legacy_sheet, 'Field label [' ) && 10 === substr_count( $legacy_sheet, '<c ' ), 'Legacy-only exports must retain their original five-column shape and ID headers.' );
	$file = adf_create_submission_export( $form );
	adf_labels_expect( is_string( $file ), 'Historical-label export must return a real workbook.' );
	$zip = new ZipArchive(); $zip->open( $file ); $sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' ); $zip->close();
	adf_labels_expect( str_contains( $sheet, 'Field label [first]' ) && str_contains( $sheet, 'Field label [second]' ) && str_contains( $sheet, 'Shared label' ) && str_contains( $sheet, 'Renamed field' ), 'Excel must preserve per-answer historical names in ID-keyed label columns, not merge equal labels or pick the newest name for old rows.' );
	if ( getenv( 'ADF_LABEL_SAMPLE' ) ) { copy( $file, getenv( 'ADF_LABEL_SAMPLE' ) ); }
	wp_delete_file( $file );
	$deny_label = static function ( $check, $object_id, $key ) { return '_adf_field_labels' === $key ? false : $check; };
	add_filter( 'update_post_metadata', $deny_label, 10, 3 );
	$failed_snapshot = adf_store_submission( $form, array( 'safe' => 'Do not lose original' ), array( 'safe' => 'Readable name' ) );
	remove_filter( 'update_post_metadata', $deny_label, 10 );
	$failed_rows = get_posts( array( 'post_type' => 'adf_submission', 'post_status' => 'private', 'post_parent' => $form, 'posts_per_page' => -1 ) );
	foreach ( $failed_rows as $row ) { if ( ! in_array( $row->ID, $created, true ) ) { $created[] = $row->ID; } }
	adf_labels_expect( is_wp_error( $failed_snapshot ) && 'adf_label_snapshot_failed' === $failed_snapshot->get_error_code(), 'A failed label metadata write must be diagnosed, not falsely reported as a complete snapshot.' );
	adf_labels_expect( array( 'safe' => 'Do not lose original' ) === get_post_meta( $failed_snapshot->get_error_data()['submission_id'], '_adf_fields', true ), 'A failed optional snapshot must never destroy the already received original values.' );
	WP_CLI::success( 'Historical label storage tracer passed.' );
} finally {
	foreach ( array_reverse( $created ) as $item ) { if ( is_int( $item ) ) { wp_delete_post( $item, true ); } }
	remove_filter( 'pre_wp_mail', $reject_mail ); wp_set_current_user( $original_user );
}
