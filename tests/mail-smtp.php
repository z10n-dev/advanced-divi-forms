<?php
/**
 * Real SMTP delivery for the rendered Divi notification and confirmation.
 *
 * This suite talks to the local sink started by scripts/test-mail-smtp.sh: no
 * mocked transport, no external provider and no real recipient. It proves that
 * the plugin's rendered HTML survives WordPress's actual PHPMailer path.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
if ( class_exists( '\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler' ) ) {
	WP_CLI::error( 'Run the SMTP suite without an active Divi theme; it uses the vendor-free test double.' );
}
require_once __DIR__ . '/fixtures/divi-mail-handler.php';

function adf_smtp_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$smtp_port    = (int) ( getenv( 'ADF_SMTP_PORT' ) ?: 2525 );
$smtp_capture = (string) getenv( 'ADF_SMTP_CAPTURE' );
adf_smtp_expect( '' !== $smtp_capture && is_dir( $smtp_capture ), 'Set ADF_SMTP_CAPTURE to the sink output directory and start the sink first.' );

$capture_files = static function () use ( $smtp_capture ) {
	$files = glob( rtrim( $smtp_capture, '/' ) . '/msg-*.eml' );
	return is_array( $files ) ? $files : array();
};
$initial_count = count( $capture_files() );

$configure_smtp = static function ( $phpmailer ) use ( $smtp_port ) {
	$phpmailer->isSMTP();
	$phpmailer->Host       = '127.0.0.1';
	$phpmailer->Port       = $smtp_port;
	$phpmailer->SMTPAuth   = false;
	$phpmailer->SMTPAutoTLS = false;
	$phpmailer->Timeout    = 5;
	$phpmailer->SMTPDebug  = 0;
};
add_action( 'phpmailer_init', $configure_smtp, 999 );

$created        = array();
$original_user  = get_current_user_id();
$original_post  = $GLOBALS['post'] ?? null;
$original_mailer = $GLOBALS['phpmailer'] ?? null;
$admins         = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );

try {
	$template = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'SMTP notification' ), true );
	$created[] = $template;
	update_post_meta( $template, '_adf_subject', 'Notification for {{fields.name}}' );
	update_post_meta( $template, '_adf_html', '<h1>Hello {{fields.name}}</h1><p>{{fields.message}}</p>{{form.summary}}' );

	$uuid    = wp_generate_uuid4();
	$attrs   = array(
		'module' => array( 'advanced' => array( 'uniqueId' => array( 'desktop' => array( 'value' => $uuid ) ) ) ),
		'title'  => array( 'innerContent' => array( 'desktop' => array( 'value' => 'SMTP contact' ) ) ),
	);
	$content = '<!-- wp:divi/contact-form ' . wp_json_encode( $attrs ) . ' /-->';
	$page    = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SMTP source', 'post_content' => $content ) ), true );
	$created[] = $page;
	$form      = adf_resolve_divi_form( $page, $uuid, $attrs );
	adf_smtp_expect( is_int( $form ), 'The source page must be discovered before delivery is tested.' );
	$created[] = $form;

	$attrs['adf']['advanced']['notificationTemplate']['desktop']['value'] = (string) $template;
	$GLOBALS['adf_divi_contexts'][ $uuid ] = array( 'form_id' => $form, 'attrs' => $attrs );
	$info   = array( 'module_slug' => 'divi/contact-form', 'contact_form_unique_id' => $uuid, 'post_id' => $page );
	$fields = array(
		'name'    => array( 'type' => 'input', 'label' => 'Your name', 'value' => 'Ada SMTP' ),
		'email'   => array( 'type' => 'email', 'value' => 'sender@example.org' ),
		'message' => array( 'type' => 'text', 'value' => 'Delivered over SMTP' ),
	);

	wp_set_current_user( 0 );
	$delivered = \ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler::process( $fields, false, $info );
	adf_smtp_expect( true === $delivered, 'WordPress must report a successful SMTP delivery.' );

	$deadline = time() + 10;
	while ( count( $capture_files() ) <= $initial_count && time() < $deadline ) {
		usleep( 100000 );
	}
	$messages = array_slice( $capture_files(), $initial_count );
	adf_smtp_expect( 1 === count( $messages ), 'The sink must receive exactly one notification over SMTP, got ' . count( $messages ) . '.' );

	$raw = (string) file_get_contents( $messages[0] );
	adf_smtp_expect( false !== stripos( $raw, 'Subject: Notification for Ada SMTP' ), 'The rendered subject must arrive through SMTP.' );
	adf_smtp_expect( false !== stripos( $raw, 'To: recipient@example.org' ), 'Divi routing must be preserved in the SMTP envelope headers.' );
	adf_smtp_expect( false !== stripos( $raw, 'Reply-To: sender@example.org' ), 'The original Reply-To must survive the SMTP transport.' );
	adf_smtp_expect( false !== stripos( $raw, 'Content-Type: text/html' ), 'The notification must be delivered as HTML.' );
	adf_smtp_expect( false !== strpos( $raw, 'Hello Ada SMTP' ), 'The rendered body must reach the recipient.' );
	adf_smtp_expect( false !== strpos( $raw, 'Delivered over SMTP' ), 'Submitted field values must be rendered into the delivered body.' );
	adf_smtp_expect( false !== strpos( $raw, 'Your name' ) && false !== strpos( $raw, '>email<' ), 'The summary table must contain the validated labels and the ID fallback.' );

	/**
	 * The HTML body is quoted-printable, so "=" separators appear as "=3D" and
	 * long lines are soft-wrapped. Decode before asserting markup.
	 */
	$decoded = quoted_printable_decode( $raw );
	adf_smtp_expect( false !== strpos( $decoded, '<table' ) && false !== strpos( $decoded, '</table>' ), 'The decoded message must contain the summary table markup.' );
	adf_smtp_expect( false === strpos( $decoded, 'Original plain message' ), 'A configured template must replace the Divi default message.' );

	WP_CLI::success( 'Real SMTP delivery passed: subject, routing, HTML body and summary table arrived at the local sink.' );
} finally {
	remove_action( 'phpmailer_init', $configure_smtp, 999 );
	$GLOBALS['adf_divi_pending_mail'] = null;
	$GLOBALS['adf_divi_active_mail']  = null;
	$GLOBALS['adf_divi_contexts']     = array();
	wp_set_current_user( $admins[0]->ID );
	foreach ( array_reverse( $created ) as $id ) {
		if ( is_int( $id ) ) { wp_delete_post( $id, true ); }
	}
	wp_set_current_user( $original_user );
	$GLOBALS['post']     = $original_post;
	$GLOBALS['phpmailer'] = $original_mailer;
}
