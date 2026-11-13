<?php
/**
 * Accessible-admin fixtures: a form with answers, a published template and an
 * enabled retention policy. Disposable sites only — never run on production.
 *
 * Usage: wp eval-file tests/fixtures/accessibility-seed.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }

$form_id = adf_register_form( 'a11y-form-key', 'A11y contact form', 0 );
if ( ! is_int( $form_id ) ) { WP_CLI::error( 'Form could not be registered.' ); }

$fields = array(
	'name'    => array( 'type' => 'input', 'label' => 'Your name', 'value' => 'Ada Example' ),
	'email'   => array( 'type' => 'email', 'label' => 'Email', 'value' => 'ada@example.org' ),
	'message' => array( 'type' => 'text', 'label' => 'Message', 'value' => "First line\nSecond line" ),
	'choice'  => array( 'type' => 'checkbox', 'label' => 'Interest', 'value' => array( 'A', 'B' ) ),
);
$labels = array( 'name' => 'Your name', 'email' => 'Email', 'message' => 'Message', 'choice' => 'Interest' );
$ids    = array();
for ( $i = 1; $i <= 3; $i++ ) {
	$result = adf_store_submission( $form_id, $fields, $labels, 'ada@example.org' );
	if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
	$ids[] = (int) $result;
}
update_post_meta( $ids[1], '_adf_read', '1' );
update_post_meta( $ids[2], '_adf_note', 'Call back on Monday' );

$template = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'A11y notification' ) );
update_post_meta( $template, '_adf_subject', 'New enquiry from {{fields.name}}' );
update_post_meta( $template, '_adf_html', '<h2>Enquiry</h2>{{form.summary}}' );

update_option( 'adf_retention_days', 30 );
update_option( 'adf_retention_last_run', array( 'time' => time() - 7200, 'removed' => 2, 'days' => 30 ) );

echo wp_json_encode( array( 'form' => $form_id, 'submissions' => $ids, 'template' => $template ) ), "\n";
WP_CLI::success( 'Accessibility fixtures created.' );
