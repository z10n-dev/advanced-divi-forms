<?php
/**
 * Optional tests against the locally supplied Divi theme (not distributed).
 * Run only in a disposable WordPress installation with Divi 5.16.0 active.
 */

use ET\Builder\FrontEnd\BlockParser\BlockParser;
use ET\Builder\FrontEnd\BlockParser\BlockParserStore;
use ET\Builder\Packages\ModuleLibrary\ContactField\ContactFieldModule;
use ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( BlockParser::class ) ) {
	exit( 1 );
}

function adf_live_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$created = array();
$original_user = get_current_user_id();
$original_post = $GLOBALS['post'] ?? null;
$original_query = $GLOBALS['wp_query'];
$original_server = $_SERVER;
$original_post_data = $_POST;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$messages = array();
$capture = static function ( $result, $args ) use ( &$messages ) { $messages[] = $args; return true; };
add_filter( 'pre_wp_mail', $capture, 10, 2 );

try {
	$template = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'Live notification' ), true );
	$confirmation = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'Live confirmation' ), true );
	$created[] = $template;
	$created[] = $confirmation;
	update_post_meta( $template, '_adf_subject', 'Live {{fields.name}}' );
	update_post_meta( $template, '_adf_html', '<h1>{{fields.name}}</h1><p>{{fields.message}}</p>' );
	update_post_meta( $confirmation, '_adf_subject', 'Received {{fields.name}}' );
	update_post_meta( $confirmation, '_adf_html', '<p>Thanks for writing.</p>' );
	$uuid = wp_generate_uuid4();
	$attrs = array(
		'module' => array( 'advanced' => array(
			'uniqueId' => array( 'desktop' => array( 'value' => $uuid ) ),
			'spamProtection' => array( 'desktop' => array( 'value' => array( 'enabled' => 'off', 'useBasicCaptcha' => 'on' ) ) ),
		) ),
		'email' => array( 'advanced' => array( 'receiver' => array( 'desktop' => array( 'value' => 'recipient@example.org' ) ) ) ),
		'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Live Contact' ) ) ),
	);
	foreach ( array( 'notificationTemplate' => $template, 'confirmationTemplate' => $confirmation, 'storeSubmissions' => 'on' ) as $key => $value ) {
		$attrs['adf']['advanced'][ $key ]['desktop']['value'] = (string) $value;
	}
	$content = '<!-- wp:divi/contact-form ' . wp_json_encode( $attrs ) . ' -->';
	foreach ( array( 'name' => 'input', 'email' => 'email', 'message' => 'text' ) as $id => $type ) {
		$field_attrs = array( 'fieldItem' => array(
			'advanced' => array(
				'id' => array( 'desktop' => array( 'value' => $id ) ),
				'type' => array( 'desktop' => array( 'value' => $type ) ),
				'required' => array( 'desktop' => array( 'value' => 'on' ) ),
			),
			'innerContent' => array( 'desktop' => array( 'value' => ucfirst( $id ) ) ),
		) );
		$content .= '<!-- wp:divi/contact-field ' . wp_json_encode( $field_attrs ) . ' /-->';
	}
	$content .= '<!-- /wp:divi/contact-form -->';
	$page = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Live form test', 'post_content' => $content ) ), true );
	$created[] = $page;
	$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $page, 'post_type' => 'page' ) );
	$GLOBALS['post'] = get_post( $page );
	$parser = new BlockParser();
	$blocks = $parser->parse( $content );
	$block = $blocks[0];
	if ( is_array( $block ) ) { $block = (object) $block; }
	$instance = $parser->get_store_instance();
	adf_live_expect( 'divi/contact-form' === $block->blockName, 'The actual Divi parser must recognize the form.' );
	$expected_form = adf_register_divi_form( $page, $uuid, $attrs );
	wp_set_current_user( 0 );
	$runtime_attrs = apply_filters( 'divi_module_library_register_module_attrs', $attrs, array( 'name' => 'divi/contact-form', 'id' => $block->id, 'storeInstance' => $instance ) );
	adf_live_expect( ! empty( $GLOBALS['adf_divi_contexts'][ $uuid ] ), 'The verified runtime attribute hook must resolve a real block.' );
	$form = $GLOBALS['adf_divi_contexts'][ $uuid ]['form_id'];
	adf_live_expect( $expected_form === $form, 'An anonymous render must reuse the indexed form, not create another record.' );
	$created[] = $form;
	wp_set_current_user( 0 );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['HTTP_HOST'] = 'wordpress.test';
	$_POST = array( '_wpnonce-et-pb-contact-form-submitted-' . $uuid => wp_create_nonce( 'et-pb-contact-form-submit-' . $uuid ), 'et_pb_contact_captcha_' . $uuid => 'wrong', 'et_pb_contact_captcha_first_digit_' . $uuid => '2', 'et_pb_contact_captcha_second_digit_' . $uuid => '3' );
	$values = array( 'name' => 'Live A&B', 'email' => 'sender@example.org', 'message' => 'Live message' );
	foreach ( BlockParserStore::get_children( $block->id, $instance ) as $field ) {
		$id = strtolower( $field->attrs['fieldItem']['advanced']['id']['desktop']['value'] );
		$_POST[ ContactFieldModule::get_field_unique_id( $field->id, $instance ) ] = $values[ $id ];
	}
	$handler = new ContactFormHandler( $block->id, $instance, $runtime_attrs );
	adf_live_expect( $handler->get_error()->has_errors() && ! $messages && 0 === adf_submission_query( $form )->found_posts, 'Actual Divi captcha failures must not persist data or send email.' );
	ContactFormHandler::reset_request_scoped_contact_form_state_for_tests();
	$_POST['et_pb_contact_captcha_' . $uuid] = '5';
	$_POST['_wpnonce-et-pb-contact-form-submitted-' . $uuid] = 'invalid';
	$handler = new ContactFormHandler( $block->id, $instance, $runtime_attrs );
	adf_live_expect( $handler->get_error()->has_errors() && ! $messages, 'Actual Divi nonce failures must not send email.' );
	ContactFormHandler::reset_request_scoped_contact_form_state_for_tests();
	$_POST['_wpnonce-et-pb-contact-form-submitted-' . $uuid] = wp_create_nonce( 'et-pb-contact-form-submit-' . $uuid );
	$handler = new ContactFormHandler( $block->id, $instance, $runtime_attrs );
	adf_live_expect( ! $handler->get_error()->has_errors() && $handler->is_mail_sent(), 'Actual Divi must report successful delivery.' );
	adf_live_expect( 2 === count( $messages ) && 'Live Live A&B' === $messages[0]['subject'] && str_contains( $messages[0]['message'], 'Live A&amp;B' ), 'Actual Divi must send one replaced HTML notification and one confirmation.' );
	adf_live_expect( 'sender@example.org' === $messages[1]['to'], 'The actual processed Email field must determine the confirmation recipient.' );
	adf_live_expect( 1 === adf_submission_query( $form )->found_posts, 'Actual validated Divi submissions must be stored once.' );
	foreach ( adf_submission_query( $form )->posts as $submission ) { $created[] = $submission->ID; }
	$duplicate = new ContactFormHandler( $block->id, $instance, $runtime_attrs );
	adf_live_expect( $duplicate->is_mail_sent() && 2 === count( $messages ) && 1 === adf_submission_query( $form )->found_posts, 'Divi duplicate DOM coordination must prevent repeated saves and deliveries.' );
	WP_CLI::success( 'Live Divi ' . wp_get_theme()->get( 'Version' ) . ' parser/handler tests passed: nonce, captcha, storage, HTML replacement, confirmation and duplicate DOM handling.' );
} finally {
	remove_filter( 'pre_wp_mail', $capture, 10 );
	$GLOBALS['adf_divi_pending_mail'] = null;
	$GLOBALS['adf_divi_active_mail'] = null;
	$GLOBALS['adf_divi_contexts'] = array();
	wp_set_current_user( $admins[0]->ID );
	foreach ( array_reverse( $created ) as $id ) { if ( is_int( $id ) ) { wp_delete_post( $id, true ); } }
	wp_set_current_user( $original_user );
	$GLOBALS['post'] = $original_post;
	$GLOBALS['wp_query'] = $original_query;
	$_POST = $original_post_data;
	$_SERVER = $original_server;
}
