<?php
/**
 * Integration tests, run inside a disposable WordPress with WP-CLI eval-file.
 * Never run against a live site's database.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

function adf_integration_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function adf_integration_output( $callback ) {
	ob_start();
	try {
		$callback();
		return ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

class ADF_Test_Redirect extends RuntimeException {}
class ADF_Test_Denied extends RuntimeException {}

$original_user = get_current_user_id();
$original_get = $_GET;
$original_post = $_POST;
$original_request = $_REQUEST;
$original_server = $_SERVER;
$created = array();
$subscriber_id = 0;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
adf_integration_expect( ! empty( $admins ), 'An administrator is required for the tests.' );
wp_set_current_user( $admins[0]->ID );

$die_handler = static function () {
	return static function ( $message ) {
		throw new ADF_Test_Denied( wp_strip_all_tags( $message ) );
	};
};
$redirect_handler = static function ( $location ) {
	throw new ADF_Test_Redirect( $location );
};
add_filter( 'wp_die_handler', $die_handler, 999 );
add_filter( 'wp_redirect', $redirect_handler, -999 );

try {
	foreach ( array( 'adf_form', 'adf_submission', 'adf_mail_template' ) as $type ) {
		$object = get_post_type_object( $type );
		adf_integration_expect( $object && ! $object->public && ! $object->publicly_queryable && ! $object->show_in_rest, "$type must not expose public or REST data." );
	}
	adf_integration_expect( 'advanced-divi-forms' === get_post_type_object( 'adf_mail_template' )->show_in_menu, 'Templates must live underneath Forms.' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	adf_register_admin_menu();
	_add_post_type_submenus();
	$submenu_slugs = array_column( $GLOBALS['submenu']['advanced-divi-forms'], 2 );
	adf_integration_expect( 'advanced-divi-forms' === $submenu_slugs[0] && in_array( 'edit.php?post_type=adf_mail_template', $submenu_slugs, true ), 'Both Forms subsections must be registered in the native admin menu.' );
	$_GET = array();
	$output = adf_integration_output( 'adf_render_forms_page' );
	adf_integration_expect( str_contains( $output, 'No forms have been registered yet.' ), 'The initial overview needs an honest empty state.' );

	$source = 'integration-test-' . wp_generate_uuid4();
	$form_id = adf_register_form( $source, "Contact O'Brien" );
	adf_integration_expect( is_int( $form_id ), 'Form registration must succeed.' );
	$created[] = $form_id;
	adf_integration_expect( $form_id === adf_register_form( $source, "Contact O'Brien" ), 'The same source key must keep a stable form ID.' );
	$other_form = adf_register_form( $source . '-other', 'Other form' );
	adf_integration_expect( is_int( $other_form ), 'A second form must be registered independently.' );
	$created[] = $other_form;
	adf_integration_expect( is_wp_error( adf_store_submission( 0, array( 'name' => 'Test' ) ) ), 'Invalid form IDs must be rejected.' );
	adf_integration_expect( is_wp_error( adf_store_submission( $form_id, array() ) ), 'Empty submissions must be rejected.' );

	for ( $i = 0; $i < 21; ++$i ) {
		$answer = adf_store_submission( $form_id, array( 'name' => "O'Brien", 'message' => '<img src=x onerror=alert(1)>Hello & goodbye', 'choices' => array( 'One', 'Two' ), 'path' => 'C:\\work' ) );
		adf_integration_expect( is_int( $answer ), 'Submission storage must succeed.' );
		$created[] = $answer;
	}
	$fields = get_post_meta( $answer, '_adf_fields', true );
	adf_integration_expect( "O'Brien" === $fields['name'] && 'C:\\work' === $fields['path'], 'Storage must preserve quotes and backslashes.' );
	adf_integration_expect( 'One, Two' === $fields['choices'], 'Multiselect values must be readable.' );
	adf_integration_expect( 21 === adf_submission_query( $form_id )->found_posts, 'Submission counts must be scoped to the form.' );
	adf_integration_expect( 1 === count( adf_submission_query( $form_id, 2 )->posts ), 'Answer lists must paginate.' );
	adf_integration_expect( 0 === adf_submission_query( $other_form )->found_posts, 'Answers must not leak into another form.' );
	$_GET = array( 'form_id' => $form_id );
	$output = adf_integration_output( 'adf_render_forms_page' );
	adf_integration_expect( str_contains( $output, 'adf_paged=2' ), 'Pagination links must preserve the current form.' );

	$_GET = array( 'form_id' => $form_id, 'submission_id' => $answer );
	$output = adf_integration_output( 'adf_render_forms_page' );
	adf_integration_expect( str_contains( $output, 'Hello &amp; goodbye' ) && ! str_contains( $output, 'onerror=' ), 'Answer values must be escaped.' );
	adf_integration_expect( ! get_post_meta( $answer, '_adf_read', true ), 'A GET request must not change read status.' );
	$_GET['form_id'] = $other_form;
	try {
		adf_render_forms_page();
		throw new RuntimeException( 'Cross-form access was accepted.' );
	} catch ( ADF_Test_Denied $error ) {
		adf_integration_expect( str_contains( $error->getMessage(), 'does not exist for this form' ), 'Cross-form access needs a controlled error.' );
	}

	$template_id = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'Confirmation' ), true );
	adf_integration_expect( is_int( $template_id ), 'Template creation must succeed.' );
	$created[] = $template_id;
	$html = '<html><body><table cellpadding="0"><tr><td style="color:blue" onclick="alert(1)">{{fields.name}}</td></tr></table><p>Customer\'s C:\\temp {{fields.missing}}</p><a href="https://example.org/{{fields.path}}">Link</a><script>alert(1)</script></body></html>';
	$_POST = array( 'adf_subject' => wp_slash( 'Hello {{fields.name}}' ), 'adf_html' => wp_slash( $html ) );
	adf_save_template_fields( $template_id );
	adf_integration_expect( '' === get_post_meta( $template_id, '_adf_html', true ), 'Saving without a nonce must not modify template fields.' );
	$_POST['adf_template_nonce'] = wp_create_nonce( 'adf_save_template' );
	adf_save_template_fields( $template_id );
	$saved_html = get_post_meta( $template_id, '_adf_html', true );
	$columns = adf_template_columns( array( 'title' => 'Title' ) );
	adf_integration_expect( isset( $columns['adf_template_id'], $columns['adf_subject'] ), 'The template list must show stable IDs and subjects.' );
	$subject_column = adf_integration_output( static function () use ( $template_id ) { adf_render_template_column( 'adf_subject', $template_id ); } );
	adf_integration_expect( 'Hello {{fields.name}}' === $subject_column, 'The list must display the saved subject.' );
	adf_integration_expect( str_contains( $saved_html, '<html>' ) && str_contains( $saved_html, 'cellpadding="0"' ) && str_contains( $saved_html, 'color:blue' ), 'Safe document markup and inline CSS must survive.' );
	adf_integration_expect( ! str_contains( $saved_html, '<script' ) && ! str_contains( $saved_html, 'onclick' ), 'Executable HTML must be removed.' );
	adf_integration_expect( str_contains( $saved_html, "Customer's C:\\temp" ), 'Template saves must preserve quotes and backslashes.' );
	$output = adf_integration_output( static function () use ( $template_id ) { adf_render_template_fields( get_post( $template_id ) ); } );
	adf_integration_expect( str_contains( $output, 'sandbox=""' ) && str_contains( $output, 'Content-Security-Policy' ) && str_contains( $output, 'adf_template_nonce' ), 'The editor must include nonce protection and an isolated preview.' );
	$rendered = adf_render_mail_template( $template_id, array( 'name' => "A&B\r\nBcc: nobody@example.org", 'path' => 'private-path' ) );
	adf_integration_expect( is_array( $rendered ) && str_contains( $rendered['html'], 'A&amp;B' ), 'HTML placeholder values must be escaped.' );
	adf_integration_expect( ! str_contains( $rendered['subject'], "\n" ) && ! str_contains( $rendered['subject'], "\r" ), 'Subjects must not allow header injection.' );
	adf_integration_expect( ! str_contains( $rendered['html'], '{{fields.missing}}' ) && ! str_contains( $rendered['html'], 'private-path' ), 'Missing fields must be empty and attribute placeholders must not expand.' );
	wp_update_post( array( 'ID' => $template_id, 'post_status' => 'draft' ) );
	adf_integration_expect( is_wp_error( adf_render_mail_template( $template_id, array() ) ), 'Draft templates must not be available for delivery.' );
	wp_update_post( array( 'ID' => $template_id, 'post_status' => 'publish' ) );

	$subscriber_id = wp_insert_user( array( 'user_login' => 'adf-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	adf_integration_expect( is_int( $subscriber_id ), 'Subscriber fixture creation must succeed.' );
	wp_set_current_user( $subscriber_id );
	adf_integration_expect( ! current_user_can( 'edit_post', $template_id ) && ! current_user_can( 'read_post', $answer ), 'Subscribers must not edit templates or read answers.' );
	$_POST['adf_template_nonce'] = wp_create_nonce( 'adf_save_template' );
	$_POST['adf_html'] = '<p>Unauthorized change</p>';
	adf_save_template_fields( $template_id );
	adf_integration_expect( $saved_html === get_post_meta( $template_id, '_adf_html', true ), 'A subscriber must not save template HTML.' );
	try {
		adf_render_forms_page();
		throw new RuntimeException( 'Unauthorized answer access was accepted.' );
	} catch ( ADF_Test_Denied $error ) {
		adf_integration_expect( str_contains( $error->getMessage(), 'not allowed' ), 'Unauthorized users need an access error.' );
	}

	wp_set_current_user( $admins[0]->ID );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array( 'submission_id' => $answer, 'adf_mark_read' => '1' );
	$_REQUEST = array( '_wpnonce' => 'invalid' );
	try {
		adf_update_submission();
		throw new RuntimeException( 'An invalid submission nonce was accepted.' );
	} catch ( ADF_Test_Denied $error ) {
		adf_integration_expect( ! get_post_meta( $answer, '_adf_read', true ), 'An invalid nonce must not modify the answer.' );
	}
	$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'adf_submission_' . $answer ) );
	try {
		adf_update_submission();
	} catch ( ADF_Test_Redirect $redirect ) {
		adf_integration_expect( str_contains( $redirect->getMessage(), 'form_id=' . $form_id ), 'Submission actions must return to the form.' );
	}
	adf_integration_expect( '1' === get_post_meta( $answer, '_adf_read', true ), 'A valid POST must mark the answer as read.' );
	$_POST = array( 'submission_id' => $answer, 'adf_trash' => '1' );
	try { adf_update_submission(); } catch ( ADF_Test_Redirect $redirect ) {}
	adf_integration_expect( 'trash' === get_post_status( $answer ), 'The normal detail action must use recoverable Trash.' );
	$_POST = array( 'submission_id' => $answer, 'adf_delete' => '1' );
	try {
		adf_update_submission();
	} catch ( ADF_Test_Denied $error ) {
		adf_integration_expect( str_contains( $error->getMessage(), 'Confirm permanent deletion' ), 'Missing permanent-delete confirmation must return a clear error.' );
	}
	adf_integration_expect( null !== get_post( $answer ), 'Deletion requires explicit confirmation.' );
	$_POST['adf_confirm_delete'] = '1';
	try {
		adf_update_submission();
	} catch ( ADF_Test_Redirect $redirect ) {
		// Successful actions redirect instead of rendering another admin page.
	}
	adf_integration_expect( null === get_post( $answer ), 'Confirmed deletion must remove the answer.' );

	WP_CLI::success( 'Forms, submissions, templates, placeholder escaping and access-control integration tests passed.' );
} finally {
	remove_filter( 'wp_die_handler', $die_handler, 999 );
	remove_filter( 'wp_redirect', $redirect_handler, -999 );
	wp_set_current_user( $admins[0]->ID );
	$_POST = array();
	foreach ( array_reverse( $created ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $subscriber_id && ! is_wp_error( $subscriber_id ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $subscriber_id );
	}
	wp_set_current_user( $original_user );
	$_GET = $original_get;
	$_POST = $original_post;
	$_REQUEST = $original_request;
	$_SERVER = $original_server;
}
