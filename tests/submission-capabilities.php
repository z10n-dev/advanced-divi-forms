<?php
/** Dedicated submission capabilities and their strict separation. Disposable WP only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
function adf_caps_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
adf_caps_expect( function_exists( 'adf_can_view_submissions' ), 'Submissions need dedicated capabilities instead of full administrator rights.' );
$created_users = array();
$created_posts = array();
$original_user = get_current_user_id();
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$reject_mail = static function () { throw new RuntimeException( 'Capability tests may not send email.' ); };
add_filter( 'pre_wp_mail', $reject_mail );
try {
	adf_install_submission_capabilities();
	$admin_after = new WP_User( $admin->ID );
	adf_caps_expect( $admin_after->has_cap( 'adf_view_submissions' ) && $admin_after->has_cap( 'adf_manage_submissions' ) && $admin_after->has_cap( 'adf_export_submissions' ), 'Existing administrators must keep access after the upgrade.' );
	$editor = wp_insert_user( array( 'user_login' => 'adf-editor-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'editor-' . wp_generate_uuid4() . '@example.org', 'role' => 'editor' ) );
	$created_users[] = $editor;
	$editor_user = new WP_User( $editor );
	adf_caps_expect( ! $editor_user->has_cap( 'adf_view_submissions' ) && ! $editor_user->has_cap( 'adf_manage_submissions' ) && ! $editor_user->has_cap( 'adf_export_submissions' ), 'Editors must never be granted submission capabilities implicitly.' );
	wp_set_current_user( $editor );
	adf_caps_expect( ! adf_can_view_submissions() && ! adf_can_export_submissions(), 'An unprivileged editor must not read or export stored answers.' );
	adf_caps_expect( is_wp_error( adf_apply_submission_action( 1, array( 1 ), 'mark_read' ) ), 'Direct bulk handlers must reject users without the manage capability.' );
	adf_caps_expect( is_wp_error( adf_create_submission_export( 1 ) ), 'Direct export calls must reject users without the export capability.' );

	$reader = wp_insert_user( array( 'user_login' => 'adf-reader-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'reader-' . wp_generate_uuid4() . '@example.org', 'role' => 'subscriber' ) );
	$created_users[] = $reader;
	$reader_user = new WP_User( $reader );
	$reader_user->add_cap( 'adf_view_submissions' );
	wp_set_current_user( $reader );
	adf_caps_expect( adf_can_view_submissions() && ! adf_can_manage_submissions() && ! adf_can_export_submissions(), 'A read-only role must see answers but not change or export them.' );
	$form = adf_register_form( 'caps-' . wp_generate_uuid4(), 'Capability test' );
	$created_posts[] = $form;
	$id = adf_store_submission( $form, array( 'email' => 'caps@example.org' ) );
	$created_posts[] = $id;
	adf_caps_expect( is_wp_error( adf_apply_submission_action( $form, array( $id ), 'mark_read' ) ), 'Read-only access must not allow status changes.' );
	adf_caps_expect( is_wp_error( adf_create_submission_export( $form ) ), 'Read-only access must not allow exports.' );

	$reader_user->add_cap( 'adf_export_submissions' );
	wp_set_current_user( 0 );
	wp_set_current_user( $reader );
	adf_caps_expect( adf_can_export_submissions(), 'An administrator-granted export capability must be honoured.' );
	adf_caps_expect( is_string( adf_create_submission_export( $form ) ), 'Granted export access must work without manage_options.' );

	$reader_user->add_cap( 'adf_manage_submissions' );
	wp_set_current_user( 0 );
	wp_set_current_user( $reader );
	adf_caps_expect( 1 === adf_apply_submission_action( $form, array( $id ), 'mark_read' ), 'Granted manage access must allow status changes.' );
	adf_caps_expect( ! current_user_can( 'manage_options' ) && ! current_user_can( 'unfiltered_html' ), 'Capability tests must not accidentally promote the test user.' );

	$template = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'Caps template' ) );
	$created_posts[] = $template;
	$_POST['adf_template_nonce'] = wp_create_nonce( 'adf_save_template' );
	$_POST['adf_subject']       = 'Injected subject';
	adf_save_template_fields( $template );
	unset( $_POST['adf_template_nonce'], $_POST['adf_subject'] );
	adf_caps_expect( '' === get_post_meta( $template, '_adf_subject', true ), 'Submission capabilities must never grant HTML template editing.' );
	adf_caps_expect( ! adf_can_manage_plugin_settings(), 'Submission managers must not inherit plugin/template settings rights.' );
	wp_set_current_user( $admin->ID );
	adf_caps_expect( adf_can_manage_plugin_settings(), 'Administrators keep settings and template rights.' );
	WP_CLI::success( 'Dedicated submission capabilities, separation and direct-handler boundaries passed.' );
} finally {
	foreach ( array_reverse( $created_posts ) as $item ) { if ( is_int( $item ) && get_post( $item ) ) { wp_delete_post( $item, true ); } }
	foreach ( $created_users as $user ) { if ( get_userdata( $user ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user ); } }
	remove_filter( 'pre_wp_mail', $reject_mail );
	wp_set_current_user( $original_user );
}
