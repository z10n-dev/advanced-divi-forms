<?php
/** First recoverable-trash tracer; disposable WordPress only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
function adf_trash_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$original_user = get_current_user_id();
$original_get = $_GET;
$active = 0;
$normal_trash = 0;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$form = adf_register_form( 'trash-test-' . wp_generate_uuid4(), 'Synthetic trash test' );
$foreign_form = adf_register_form( 'trash-foreign-' . wp_generate_uuid4(), 'Foreign synthetic trash test' );
$foreign = adf_store_submission( $foreign_form, array( 'name' => 'Must stay in the other form' ) );
$id = adf_store_submission( $form, array( 'name' => 'Original synthetic answer', 'email' => 'trash-fixture@example.org' ) );
update_post_meta( $id, '_adf_read', 1 );
update_post_meta( $id, '_adf_note', 'Keep this internal note' );
try {
	adf_trash_expect( is_wp_error( adf_apply_submission_action( $form, array( $id, $foreign ), 'trash' ) ) && 'private' === get_post_status( $id ), 'A cross-form trash batch must fail before moving its first valid record.' );
	$result = adf_apply_submission_action( $form, array( $id ), 'trash' );
	adf_trash_expect( ! is_wp_error( $result ) && 1 === $result && 'trash' === get_post_status( $id ), 'Normal deletion must move an answer to recoverable trash instead of rejecting the action or physically deleting it.' );
	adf_trash_expect( 0 === (int) adf_submission_query( $form )->found_posts, 'The normal list must exclude trashed submissions.' );
	$trash_view = adf_submission_query( $form, 1, 20, array( 'adf_view' => 'trash' ) );
	adf_trash_expect( array( $id ) === wp_list_pluck( $trash_view->posts, 'ID' ), 'The separate Trash view must query exactly the selected form’s trashed answers.' );
	adf_trash_expect( is_wp_error( adf_submission_query( $form, 1, 20, array( 'adf_view' => 'any' ) ) ), 'Arbitrary post-status/view overrides must not be accepted.' );
	$_GET = array( 'form_id' => $form, 'adf_view' => 'trash' );
	ob_start(); adf_render_submission_list( get_post( $form ) ); $trash_html = ob_get_clean();
	adf_trash_expect( str_contains( $trash_html, 'value="restore"' ) && str_contains( $trash_html, 'value="delete"' ) && ! str_contains( $trash_html, 'Quick Edit' ) && str_contains( $trash_html, 'Trash (1)' ), 'Trash UI must expose restore and confirmed permanent deletion, accurate totals, and no active-record editor.' );
	ob_start(); adf_render_submission( get_post( $form ), get_post( $id ) ); $detail_html = ob_get_clean();
	adf_trash_expect( str_contains( $detail_html, 'name="adf_restore"' ) && str_contains( $detail_html, 'name="adf_delete"' ), 'A trashed answer detail must offer separate restore and permanent-delete actions.' );
	$_GET = array( 'form_id' => $form );
	ob_start(); adf_render_submission_list( get_post( $form ) ); $active_html = ob_get_clean();
	adf_trash_expect( str_contains( $active_html, 'value="trash"' ) && ! str_contains( $active_html, 'value="delete"' ) && str_contains( $active_html, 'Active (0)' ), 'The normal list must offer recoverable trash rather than permanent deletion.' );

	$normal_export = adf_create_submission_export( $form );
	$zip = new ZipArchive(); $zip->open( $normal_export ); $sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' ); $zip->close(); wp_delete_file( $normal_export );
	adf_trash_expect( 1 === substr_count( $sheet, '<row ' ), 'The standard all-submissions export must exclude Trash.' );
	adf_trash_expect( is_wp_error( adf_create_submission_export( $form, array( $id ) ) ), 'A default active selection must not silently include Trash.' );
	$trash_export = adf_create_submission_export( $form, array( $id ), array( 'adf_view' => 'trash' ) );
	adf_trash_expect( is_string( $trash_export ), 'An explicitly selected Trash-view export must remain scoped and exportable.' );
	$zip->open( $trash_export ); $sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' ); $zip->close(); wp_delete_file( $trash_export );
	adf_trash_expect( 2 === substr_count( $sheet, '<row ' ) && str_contains( $sheet, 'trash-fixture@example.org' ), 'Explicit Trash exports must retain the selected original fields.' );
	$restored = adf_apply_submission_action( $form, array( $id ), 'restore' );
	adf_trash_expect( ! is_wp_error( $restored ) && 1 === $restored && 'private' === get_post_status( $id ), 'Restore must return a trashed answer to private active storage, not a public or draft post.' );
	adf_trash_expect( array( 'name' => 'Original synthetic answer', 'email' => 'trash-fixture@example.org' ) === get_post_meta( $id, '_adf_fields', true ) && '1' === get_post_meta( $id, '_adf_read', true ) && 'Keep this internal note' === get_post_meta( $id, '_adf_note', true ) && (int) get_post( $id )->post_parent === $form, 'Trash and restore must preserve original fields, read status, note and form identity.' );
	adf_apply_submission_action( $form, array( $id ), 'trash' );
	adf_apply_submission_action( $foreign_form, array( $foreign ), 'trash' );
	foreach ( array( 'restore', 'delete' ) as $operation ) {
		$bad_batch = adf_apply_submission_action( $form, array( $id, $foreign ), $operation, array( 'confirm_delete' => '1' ) );
		adf_trash_expect( is_wp_error( $bad_batch ) && 'trash' === get_post_status( $id ) && 'trash' === get_post_status( $foreign ), 'Cross-form restore/delete batches must fail before any selected record changes.' );
	}
	$normal_trash = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'trash', 'post_title' => 'Disposable ordinary core Trash', 'meta_input' => array( '_wp_trash_meta_time' => 1, '_wp_trash_meta_status' => 'draft' ) ) );
	update_post_meta( $id, '_wp_trash_meta_time', 1 );
	wp_scheduled_delete();
	adf_trash_expect( 'trash' === get_post_status( $id ), 'Core scheduled trash cleanup must not physically delete stored answers without an explicit permanent-delete request.' );
	adf_trash_expect( null === get_post( $normal_trash ), 'The protection must not disable native cleanup for unrelated ordinary posts.' );
	wp_set_current_user( 0 );
	foreach ( array( 'trash', 'restore', 'delete' ) as $operation ) {
		$forbidden = adf_apply_submission_action( $form, array( $id ), $operation, array( 'confirm_delete' => '1' ) );
		adf_trash_expect( is_wp_error( $forbidden ) && 'adf_forbidden' === $forbidden->get_error_code() && 'trash' === get_post_status( $id ), 'Unauthorized callers must fail before any trash/restore/delete state change.' );
	}
	wp_set_current_user( $admins[0]->ID );
	$active = adf_store_submission( $form, array( 'name' => 'Must trash first' ) );
	adf_trash_expect( is_wp_error( adf_apply_submission_action( $form, array( $active ), 'delete', array( 'confirm_delete' => '1' ) ) ) && 'private' === get_post_status( $active ), 'Permanent deletion must require the separate Trash step, even with confirmation.' );
	$without_confirmation = adf_apply_submission_action( $form, array( $id ), 'delete' );
	adf_trash_expect( is_wp_error( $without_confirmation ) && 'adf_confirm_delete' === $without_confirmation->get_error_code() && 'trash' === get_post_status( $id ), 'Permanent deletion from Trash requires explicit confirmation.' );
	$pretend_delete = static function ( $delete, $post ) use ( $id ) { return $post->ID === $id ? $post : $delete; };
	add_filter( 'pre_delete_post', $pretend_delete, 11, 2 );
	$failed_delete = adf_apply_submission_action( $form, array( $id ), 'delete', array( 'confirm_delete' => '1' ) );
	remove_filter( 'pre_delete_post', $pretend_delete, 11 );
	adf_trash_expect( is_wp_error( $failed_delete ) && 'trash' === get_post_status( $id ), 'A short-circuited physical deletion must never report success.' );
	$deleted = adf_apply_submission_action( $form, array( $id ), 'delete', array( 'confirm_delete' => '1' ) );
	adf_trash_expect( 1 === $deleted && null === get_post( $id ), 'A separately confirmed permanent-delete action must physically remove exactly its selected trashed answer.' );
	WP_CLI::success( 'Recoverable submission trash tracer passed.' );
} finally {
	if ( 'trash' === get_post_status( $id ) ) { wp_untrash_post( $id ); }
	wp_delete_post( $id, true );
	if ( $active ) { wp_delete_post( $active, true ); }
	wp_delete_post( $foreign, true );
	wp_delete_post( $foreign_form, true );
	if ( $normal_trash ) { wp_delete_post( $normal_trash, true ); }
	wp_delete_post( $form, true );
	$_GET = $original_get;
	wp_set_current_user( $original_user );
}
