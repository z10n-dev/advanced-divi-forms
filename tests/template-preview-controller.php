<?php
/** Authenticated synthetic preview POST contracts, disposable WordPress only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
if ( ! function_exists( 'adf_handle_template_preview' ) ) { throw new RuntimeException( 'A protected synthetic-preview AJAX endpoint must exist.' ); }
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
class ADF_Preview_End extends RuntimeException {}
function adf_endpoint_preview_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function adf_call_preview( array $post, $method = 'POST' ) {
	$_POST = wp_slash( $post ); $_REQUEST = $_POST; $_SERVER['REQUEST_METHOD'] = $method;
	$GLOBALS['adf_preview_test_status'] = 200;
	ob_start();
	try { adf_handle_template_preview(); } catch ( ADF_Preview_End $end ) {}
	finally { $body = ob_get_clean(); }
	return array( 'status' => $GLOBALS['adf_preview_test_status'], 'payload' => json_decode( $body, true ) );
}
$original_user = get_current_user_id(); $original_post = $_POST; $original_request = $_REQUEST; $original_server = $_SERVER;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( $admins[0]->ID );
$template = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'draft', 'post_title' => 'Synthetic preview endpoint test', 'meta_input' => array( '_adf_subject' => 'Saved subject', '_adf_html' => '<p>Saved body</p>' ) ) );
$other = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Not a template' ) );
$subscriber = wp_insert_user( array( 'user_login' => 'preview-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$before = wp_count_posts( 'adf_submission' );
$die = static function () { return static function () { throw new ADF_Preview_End(); }; };
$status = static function ( $header, $code ) { $GLOBALS['adf_preview_test_status'] = $code; return $header; };
$reject_mail = static function () { throw new RuntimeException( 'Preview sent mail.' ); };
$reject_http = static function () { throw new RuntimeException( 'Preview issued a remote request.' ); };
$reject_store = static function () { throw new RuntimeException( 'Preview attempted a post/submission write.' ); };
add_filter( 'wp_die_ajax_handler', $die ); add_filter( 'status_header', $status, 10, 2 );
add_filter( 'pre_wp_mail', $reject_mail ); add_filter( 'pre_http_request', $reject_http ); add_filter( 'wp_insert_post_data', $reject_store );
try {
	$post = array( 'template_id' => (string) $template, 'nonce' => wp_create_nonce( 'adf_preview_template_' . $template ), 'subject' => 'Hello {{fields.name}}', 'html' => '<p>{{fields.name}}</p>{{form.summary}}', 'fields_json' => '{"name":"Ada"}' );
	adf_endpoint_preview_expect( 405 === adf_call_preview( $post, 'GET' )['status'], 'Preview must reject GET before rendering.' );
	$bad = $post; $bad['nonce'] = 'invalid';
	adf_endpoint_preview_expect( 403 === adf_call_preview( $bad )['status'], 'Preview must require a valid item-scoped nonce.' );
	wp_set_current_user( $subscriber );
	adf_endpoint_preview_expect( 403 === adf_call_preview( $post )['status'], 'A content subscriber must not preview HTML or read template data.' );
	wp_set_current_user( $admins[0]->ID );
	$wrong = $post; $wrong['template_id'] = (string) $other; $wrong['nonce'] = wp_create_nonce( 'adf_preview_template_' . $other );
	adf_endpoint_preview_expect( 404 === adf_call_preview( $wrong )['status'], 'Preview must reject records outside the template post type.' );
	foreach ( array( array( 'fields_json' => '{bad' ), array( 'fields_json' => '[]' ), array( 'subject' => array( 'bad' ) ), array( 'template_id' => (string) $template . 'garbage' ), array( 'html' => str_repeat( 'x', 200001 ) ) ) as $bad_input ) {
		adf_endpoint_preview_expect( 400 === adf_call_preview( array_merge( $post, $bad_input ) )['status'], 'Malformed/oversized preview input must fail with a controlled response.' );
	}
	$valid = adf_call_preview( $post );
	adf_endpoint_preview_expect( 200 === $valid['status'] && true === $valid['payload']['success'] && 'Hello Ada' === $valid['payload']['data']['subject'], 'Authorized draft/unsaved preview must return rendered test data.' );
	adf_endpoint_preview_expect( 'Saved subject' === get_post_meta( $template, '_adf_subject', true ) && '<p>Saved body</p>' === get_post_meta( $template, '_adf_html', true ) && $before == wp_count_posts( 'adf_submission' ), 'Preview must not save editor content, synthetic values or real submissions.' );
	ob_start(); adf_render_template_fields( get_post( $template ) ); $editor = ob_get_clean();
	adf_endpoint_preview_expect( str_contains( $editor, 'id="adf-preview-fields"' ) && str_contains( $editor, 'id="adf-preview-run"' ) && str_contains( $editor, 'data-adf-placeholder="{{form.summary}}"' ) && str_contains( $editor, 'sandbox=""' ), 'The editor must expose synthetic JSON input, an explicit preview button and insertable placeholders inside the retained sandbox.' );
	WP_CLI::success( 'Template preview AJAX tests passed: POST/nonce/permissions/type/limits and no writes, mail or network.' );
} finally {
	remove_filter( 'wp_die_ajax_handler', $die ); remove_filter( 'status_header', $status, 10 );
	remove_filter( 'pre_wp_mail', $reject_mail ); remove_filter( 'pre_http_request', $reject_http ); remove_filter( 'wp_insert_post_data', $reject_store );
	wp_set_current_user( $admins[0]->ID ); wp_delete_post( $template, true ); wp_delete_post( $other, true );
	require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $subscriber );
	$_POST = $original_post; $_REQUEST = $original_request; $_SERVER = $original_server; wp_set_current_user( $original_user );
}
