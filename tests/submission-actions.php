<?php
/**
 * Submission-management regressions in a disposable WordPress installation.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
$implementation = __DIR__ . '/../includes/submission-actions.php';
if ( ! function_exists( 'adf_apply_submission_action' ) && is_file( $implementation ) ) {
	require_once $implementation;
}
function adf_actions_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
adf_actions_expect( function_exists( 'adf_apply_submission_action' ), 'Submission-management API must exist.' );
$created = array();
$original_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
try {
	$form = adf_register_form( 'actions-' . wp_generate_uuid4(), 'Actions test' );
	$created[] = $form;
	$other = adf_register_form( 'actions-' . wp_generate_uuid4(), 'Other form' );
	$created[] = $other;
	$values = array( 'name' => 'Original', 'message' => "O'Brien C:\\work" );
	$first = adf_store_submission( $form, $values );
	$second = adf_store_submission( $form, $values );
	$foreign = adf_store_submission( $other, $values );
	array_push( $created, $first, $second, $foreign );
	$result = adf_apply_submission_action( $form, array( $first, $second ), 'mark_read' );
	adf_actions_expect( 2 === $result && '1' === get_post_meta( $first, '_adf_read', true ) && '1' === get_post_meta( $second, '_adf_read', true ), 'Bulk read must update all selected submissions.' );
	$result = adf_apply_submission_action( $form, array( $first ), 'quick_edit', array( 'read' => '0', 'note' => "O'Brien C:\\work\nFollow up <script>alert(1)</script>" ) );
	adf_actions_expect( 1 === $result && '0' === get_post_meta( $first, '_adf_read', true ), 'Quick Edit must set read or unread.' );
	adf_actions_expect( "O'Brien C:\\work\nFollow up" === get_post_meta( $first, '_adf_note', true ), 'Quick Edit must save a plain-text note preserving quotes and backslashes.' );
	adf_actions_expect( $values === get_post_meta( $first, '_adf_fields', true ), 'Quick Edit must never alter original submitted values.' );
	$block_write = static function ( $check, $object_id, $meta_key ) { return '_adf_read' === $meta_key ? false : $check; };
	add_filter( 'update_post_metadata', $block_write, 10, 3 );
	$failed = adf_apply_submission_action( $form, array( $first ), 'mark_read' );
	remove_filter( 'update_post_metadata', $block_write, 10 );
	adf_actions_expect( is_wp_error( $failed ), 'A failed metadata write must not report a successful update.' );
	adf_actions_expect( function_exists( 'adf_manage_submissions' ), 'A nonce-protected POST controller must exist.' );
	$result = adf_apply_submission_action( $form, array( $first, $second ), 'mark_unread' );
	adf_actions_expect( 2 === $result && '0' === get_post_meta( $second, '_adf_read', true ), 'Bulk unread must update all selected submissions.' );
	$result = adf_apply_submission_action( $form, array( $first, $foreign ), 'mark_read' );
	adf_actions_expect( is_wp_error( $result ) && '0' === get_post_meta( $first, '_adf_read', true ), 'Cross-form selections must fail before any mutation.' );
	foreach ( array( array(), array( -$first ), array( (string) $first . 'garbage' ), array( array( $first ) ), array( 0 ) ) as $invalid ) {
		adf_actions_expect( is_wp_error( adf_apply_submission_action( $form, $invalid, 'mark_read' ) ), 'Malformed and empty selections must fail.' );
	}
	adf_actions_expect( is_wp_error( adf_apply_submission_action( $form, array( $first ), 'quick_edit', array( 'read' => '1', 'note' => str_repeat( 'x', 5001 ) ) ) ), 'Oversized notes must be rejected without changes.' );
	adf_actions_expect( is_wp_error( adf_apply_submission_action( $form, array( $first ), 'quick_edit', array( 'read' => 'yes', 'note' => '' ) ) ), 'Unknown read status must be rejected.' );
	wp_set_current_user( 0 );
	adf_actions_expect( is_wp_error( adf_apply_submission_action( $form, array( $first ), 'mark_read' ) ), 'Anonymous callers must not manage submissions.' );
	wp_set_current_user( $admins[0]->ID );
	adf_actions_expect( is_wp_error( adf_apply_submission_action( $form, array( $first ), 'delete' ) ) && get_post( $first ), 'Bulk deletion must require explicit confirmation.' );
	adf_actions_expect( 2 === adf_apply_submission_action( $form, array( $first, $first, $second ), 'delete', array( 'confirm_delete' => '1' ) ) && ! get_post( $first ) && ! get_post( $second ) && get_post( $foreign ), 'Confirmed deletion must remove each selected record once without touching another form.' );
	WP_CLI::success( 'Submission-management tests passed.' );
} finally {
	wp_set_current_user( $admins[0]->ID );
	foreach ( array_reverse( $created ) as $id ) {
		wp_delete_post( $id, true );
	}
	wp_set_current_user( $original_user );
}
