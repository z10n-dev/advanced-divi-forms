<?php
/**
 * Offline Divi contract tests inside a disposable WordPress installation.
 * Run with WP-CLI eval-file; actual vendor integration is tested separately.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
if ( class_exists( '\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler' ) ) {
	WP_CLI::error( 'Run the contract tests without an active Divi theme; they use a vendor-free test double.' );
}
require_once __DIR__ . '/fixtures/divi-mail-handler.php';

function adf_divi_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$created = array();
$original_user = get_current_user_id();
$original_post = $GLOBALS['post'] ?? null;
$original_mailer = $GLOBALS['phpmailer'] ?? null;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$messages = array();
$delivery_success = true;
$capture = static function ( $result, $args ) use ( &$messages, &$delivery_success ) {
	$messages[] = $args;
	return $delivery_success;
};
add_filter( 'pre_wp_mail', $capture, 10, 2 );

try {
	$template = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'Divi notification' ), true );
	$confirmation = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'Divi confirmation' ), true );
	$created[] = $template;
	$created[] = $confirmation;
	update_post_meta( $template, '_adf_subject', 'Notification for {{fields.name}}' );
	update_post_meta( $template, '_adf_html', '<h1>Hello {{fields.name}}</h1><p>{{fields.message}}</p>{{form.summary}}' );
	update_post_meta( $confirmation, '_adf_subject', 'Thank you {{fields.name}}' );
	update_post_meta( $confirmation, '_adf_html', '<p>Your message has arrived.</p>{{form.summary}}' );
	$metadata = adf_divi_block_metadata( array( 'name' => 'divi/contact-form', 'attributes' => array() ) );
	adf_divi_expect( isset( $metadata['attributes']['adf']['settings']['advanced']['notificationTemplate'] ), 'PHP must register the persisted settings schema.' );
	$other_metadata = array( 'name' => 'core/paragraph' );
	adf_divi_expect( $other_metadata === adf_divi_block_metadata( $other_metadata ), 'Other modules must remain unchanged.' );
	wp_register_script( 'divi-module-library', 'https://example.org/divi.js', array() );
	wp_register_script( 'divi-vendor-wp-hooks', 'https://example.org/hooks.js', array() );
	adf_enqueue_divi_settings();
	adf_divi_expect( in_array( 'adf-divi-settings', wp_scripts()->registered['divi-module-library']->deps, true ), 'The settings hook must execute before module registration.' );

	$uuid = wp_generate_uuid4();
	$attrs = array(
		'module' => array( 'advanced' => array( 'uniqueId' => array( 'desktop' => array( 'value' => $uuid ) ) ) ),
		'title' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Test contact' ) ) ),
	);
	$content = '<!-- wp:divi/contact-form ' . wp_json_encode( $attrs ) . ' /-->';
	$page = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Divi integration test', 'post_content' => $content ) ), true );
	$created[] = $page;
	$form = adf_resolve_divi_form( $page, $uuid, $attrs );
	adf_divi_expect( is_int( $form ), 'Saving a source page must discover its form.' );
	$created[] = $form;
	$copy = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Copied page', 'post_content' => $content ) ), true );
	$created[] = $copy;
	$copied_form = adf_resolve_divi_form( $copy, $uuid, $attrs );
	$created[] = $copied_form;
	adf_divi_expect( is_int( $copied_form ) && $copied_form !== $form, 'Copied UUIDs on different pages must not merge answers.' );
	$info = array( 'module_slug' => 'divi/contact-form', 'contact_form_unique_id' => $uuid, 'post_id' => $page );
	$fields = array(
		'name' => array( 'type' => 'input', 'label' => 'Your name', 'value' => 'A&B' ),
		'email' => array( 'type' => 'email', 'value' => 'sender@example.org' ),
		'message' => array( 'type' => 'text', 'value' => 'Hello from the form' ),
	);
	$GLOBALS['adf_divi_contexts'][ $uuid ] = array( 'form_id' => $form, 'attrs' => $attrs );
	wp_set_current_user( 0 );
	\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler::process( $fields, false, $info );
	adf_divi_expect( 1 === count( $messages ) && 'Divi default' === $messages[0]['subject'], 'Unconfigured forms must keep their native delivery.' );
	adf_divi_expect( 0 === adf_submission_query( $form )->found_posts, 'Storage must be disabled by default.' );
	$messages = array();
	foreach ( array( 'notificationTemplate' => $template, 'confirmationTemplate' => $confirmation, 'storeSubmissions' => 'on' ) as $key => $value ) {
		$attrs['adf']['advanced'][ $key ]['desktop']['value'] = (string) $value;
	}
	$GLOBALS['adf_divi_contexts'][ $uuid ]['attrs'] = $attrs;
	$nested = static function () { wp_mail( 'other@example.org', 'Unrelated mail', 'Leave this alone' ); };
	\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler::process( $fields, false, $info, $nested );
	adf_divi_expect( 3 === count( $messages ), 'Exactly one unrelated message, one notification and one confirmation are expected.' );
	adf_divi_expect( 'Unrelated mail' === $messages[0]['subject'] && 'Leave this alone' === $messages[0]['message'], 'Nested unrelated emails must not consume the form context.' );
	adf_divi_expect( 'Notification for A&B' === $messages[1]['subject'] && str_contains( $messages[1]['message'], 'A&amp;B' ), 'The original notification must become escaped HTML.' );
	adf_divi_expect( 'recipient@example.org' === $messages[1]['to'] && in_array( 'Reply-To: sender@example.org', $messages[1]['headers'], true ), 'Original routing and Reply-To must survive.' );
	adf_divi_expect( in_array( 'Content-Type: text/html; charset=UTF-8', $messages[1]['headers'], true ), 'HTML must be scoped to this message.' );
	adf_divi_expect( 'sender@example.org' === $messages[2]['to'] && 'Thank you A&B' === $messages[2]['subject'], 'Confirmation must go to the validated Email field.' );
	adf_divi_expect( str_contains( $messages[1]['message'], '>Your name</th>' ) && str_contains( $messages[2]['message'], '>Your name</th>' ), 'Both notification and confirmation summaries must use validated field labels.' );
	adf_divi_expect( str_contains( $messages[1]['message'], '>email</th>' ) && ! str_contains( $messages[1]['message'], 'captcha' ), 'Only validated submitted fields belong in the summary, with IDs as fallback.' );
	$submissions = adf_submission_query( $form );
	adf_divi_expect( 1 === $submissions->found_posts, 'A validated opt-in submission must be saved once for an anonymous visitor.' );
	foreach ( $submissions->posts as $submission ) { $created[] = $submission->ID; }
	adf_divi_expect( array( 'name' => 'Your name' ) === get_post_meta( $submissions->posts[0]->ID, '_adf_field_labels', true ), 'Anonymous Divi capture must snapshot only nonempty labels supplied by validated fields.' );
	adf_divi_expect( 'sender@example.org' === get_post_meta( $submissions->posts[0]->ID, '_adf_email', true ), 'Anonymous Divi capture must store the validated email for exact privacy export/erasure.' );
	$messages = array();
	\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler::process( $fields, true, $info );
	adf_divi_expect( ! $messages && 1 === adf_submission_query( $form )->found_posts, 'Validation/captcha errors must not store data or send confirmations.' );
	$attrs['adf']['advanced']['storeSubmissions']['desktop']['value'] = 'off';
	$GLOBALS['adf_divi_contexts'][ $uuid ]['attrs'] = $attrs;
	$delivery_success = false;
	\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler::process( $fields, false, $info );
	adf_divi_expect( 1 === count( $messages ), 'Failed recipient delivery must not trigger a confirmation.' );
	$delivery_success = true;
	$messages = array();
	$fields['email']['type'] = 'input';
	\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler::process( $fields, false, $info );
	adf_divi_expect( 1 === count( $messages ), 'An unvalidated text field must not become a confirmation recipient.' );
	$fields['email']['type'] = 'email';
	wp_set_current_user( $admins[0]->ID );
	wp_trash_post( $template );
	$messages = array();
	wp_set_current_user( 0 );
	\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler::process( $fields, false, $info );
	adf_divi_expect( 'Divi default' === $messages[0]['subject'], 'A missing/trashed template must fall back to the original mail.' );
	wp_mail( 'unrelated@example.org', 'After the form', 'Original text' );
	adf_divi_expect( 'Original text' === end( $messages )['message'], 'Later site mail must not be rewritten.' );

	// Exercise WordPress's actual success/failure hooks without SMTP or sendmail.
	remove_filter( 'pre_wp_mail', $capture, 10 );
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	$mailer = new class( true ) extends \PHPMailer\PHPMailer\PHPMailer {
		public $sent = array();
		public $fail = false;
		public function send() {
			if ( $this->fail ) {
				throw new \PHPMailer\PHPMailer\Exception( 'Intentional dry-run failure' );
			}
			$this->sent[] = array( 'to' => $this->getToAddresses(), 'subject' => $this->Subject, 'type' => $this->ContentType );
			return true;
		}
	};
	$GLOBALS['phpmailer'] = $mailer;
	\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler::process( $fields, false, $info );
	adf_divi_expect( 2 === count( $mailer->sent ) && 'sender@example.org' === $mailer->sent[1]['to'][0][0] && 'text/html' === $mailer->sent[1]['type'], 'The native wp_mail_succeeded hook must send exactly one HTML confirmation.' );
	$mailer->sent = array();
	$mailer->fail = true;
	$result = \ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler::process( $fields, false, $info );
	adf_divi_expect( false === $result && ! $mailer->sent && empty( $GLOBALS['adf_divi_active_mail'] ), 'The native wp_mail_failed hook must discard confirmation state.' );
	WP_CLI::success( 'Divi contract tests passed: discovery, opt-in storage, isolated notification replacement and conditional confirmations.' );
} finally {
	remove_filter( 'pre_wp_mail', $capture, 10 );
	$GLOBALS['adf_divi_pending_mail'] = null;
	$GLOBALS['adf_divi_active_mail'] = null;
	$GLOBALS['adf_divi_contexts'] = array();
	wp_set_current_user( $admins[0]->ID );
	foreach ( array_reverse( $created ) as $id ) { if ( is_int( $id ) ) { wp_delete_post( $id, true ); } }
	wp_set_current_user( $original_user );
	$GLOBALS['post'] = $original_post;
	$GLOBALS['phpmailer'] = $original_mailer;
}
