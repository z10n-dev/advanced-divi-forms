<?php
/**
 * Real XLSX-export regressions in a disposable WordPress installation.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
$file = __DIR__ . '/../includes/submission-export.php';
if ( ! function_exists( 'adf_create_submission_export' ) && is_file( $file ) ) {
	require_once $file;
}
function adf_export_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
adf_export_expect( function_exists( 'adf_create_submission_export' ), 'The Excel-export API must exist.' );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$original_user = get_current_user_id();
wp_set_current_user( $admins[0]->ID );
$created = array();
$files = array();
try {
	$form = adf_register_form( 'export-' . wp_generate_uuid4(), 'Export test' );
	$created[] = $form;
	$other = adf_register_form( 'export-' . wp_generate_uuid4(), 'Other export form' );
	$created[] = $other;
	$ids = array();
	for ( $i = 0; $i < 23; ++$i ) {
		$id = adf_store_submission( $form, array( 'name' => 'Person ' . $i, 'code' => '00123', 'formula' => '=1+1', 'message' => "Line one\nLine two" ) );
		$ids[] = $id;
		$created[] = $id;
	}
	$foreign = adf_store_submission( $other, array( 'private' => 'Do not export' ) );
	$created[] = $foreign;
	$file = adf_create_submission_export( $form );
	adf_export_expect( is_string( $file ) && is_file( $file ), 'All-submissions export must return a real private XLSX file.' );
	$files[] = $file;
	$zip = new ZipArchive();
	adf_export_expect( true === $zip->open( $file ), 'The XLSX must be a valid ZIP archive.' );
	$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	adf_export_expect( is_string( $sheet ) && 24 === substr_count( $sheet, '<row ' ), 'Export all must include all 23 answers, not just the visible 20.' );
	adf_export_expect( str_contains( $sheet, '00123' ) && str_contains( $sheet, '=1+1' ) && ! str_contains( $sheet, '<f>' ), 'Leading zeros and formula-looking input must be stored as literal strings.' );
	adf_export_expect( false !== $zip->getFromName( '[Content_Types].xml' ) && false !== $zip->getFromName( 'xl/workbook.xml' ), 'The archive must contain real XLSX package metadata.' );
	$zip->close();
	update_post_meta( $ids[0], '_adf_note', wp_slash( "Follow up O'Brien C:\\work" ) );
	update_post_meta( $ids[0], '_adf_read', 1 );
	update_post_meta( $ids[1], '_adf_fields', array( 'name' => 'Different', 'extra' => 'Additional field' ) );
	$file = adf_create_submission_export( $form, array( $ids[0], $ids[1] ) );
	adf_export_expect( is_string( $file ), 'Selected export must succeed.' );
	$files[] = $file;
	$zip->open( $file );
	$selected = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	$zip->close();
	adf_export_expect( 3 === substr_count( $selected, '<row ' ) && str_contains( $selected, 'Additional field' ) && str_contains( $selected, '>extra<' ) && str_contains( $selected, 'Follow up O&apos;Brien C:\\work' ) && str_contains( $selected, '>Read<' ), 'Selected export must align the field union and include status/internal notes.' );
	adf_export_expect( 0600 === ( fileperms( $file ) & 0777 ), 'Private export files must be readable only by the server user.' );
	foreach ( array( array( $ids[0], $foreign ), array( -1 ), array( (string) $ids[0] . 'junk' ), array( array( $ids[0] ) ), array( str_repeat( '9', 50 ) ) ) as $invalid ) {
		adf_export_expect( is_wp_error( adf_create_submission_export( $form, $invalid ) ), 'Malformed and cross-form selections must be rejected.' );
	}
	adf_export_expect( is_wp_error( adf_create_submission_export( $form, array_fill( 0, 10001, $ids[0] ) ) ), 'The export row safety limit must be explicit, not silently truncated.' );
	adf_export_expect( is_wp_error( adf_xlsx_cell( str_repeat( 'x', 32768 ) ) ), 'Oversized spreadsheet cells must fail explicitly.' );
	adf_export_expect( ! str_contains( adf_xlsx_cell( "a\x01b & < >" ), "\x01" ) && str_contains( adf_xlsx_cell( 'a & b' ), 'a &amp; b' ), 'Invalid XML controls must be removed and XML text escaped.' );
	$fallback = adf_package_submission_xlsx( $selected, false );
	adf_export_expect( is_string( $fallback ), 'WordPress PclZip fallback must produce a file without ZipArchive.' );
	$files[] = $fallback;
	adf_export_expect( true === $zip->open( $fallback ) && $selected === $zip->getFromName( 'xl/worksheets/sheet1.xml' ), 'PclZip must produce the same valid workbook parts.' );
	$zip->close();
	if ( getenv( 'ADF_EXPORT_SAMPLE' ) ) {
		copy( $file, getenv( 'ADF_EXPORT_SAMPLE' ) );
	}
	wp_set_current_user( 0 );
	adf_export_expect( is_wp_error( adf_create_submission_export( $form ) ), 'Anonymous visitors must never export private answers.' );
	wp_set_current_user( $admins[0]->ID );
	WP_CLI::success( 'Submission Excel-export tests passed.' );
} finally {
	foreach ( $files as $file ) {
		if ( is_file( $file ) ) { unlink( $file ); }
	}
	foreach ( array_reverse( $created ) as $id ) { wp_delete_post( $id, true ); }
	wp_set_current_user( $original_user );
}
