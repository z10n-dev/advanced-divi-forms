<?php
/** Retention and privacy contracts. Disposable WordPress only; synthetic data. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
function adf_privacy_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
adf_privacy_expect( function_exists( 'adf_retention_days' ), 'Retention must default to an explicit, readable setting.' );
$created = array();
$privacy_users = array();
$original_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$reject_mail = static function () { throw new RuntimeException( 'Privacy tests may not send email.' ); };
add_filter( 'pre_wp_mail', $reject_mail );
try {
	delete_option( 'adf_retention_days' );
	adf_privacy_expect( 0 === adf_retention_days() && ! adf_retention_enabled(), 'Retention must be OFF when no period has been configured.' );
	$form = adf_register_form( 'privacy-' . wp_generate_uuid4(), 'Privacy test' ); $created[] = $form;
	$old = adf_store_submission( $form, array( 'email' => 'old@example.org' ) ); $created[] = $old;
	wp_update_post( wp_slash( array( 'ID' => $old, 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) ) );
	$new = adf_store_submission( $form, array( 'email' => 'new@example.org' ) ); $created[] = $new;
	adf_run_retention_cleanup();
	adf_privacy_expect( get_post( $old ) && get_post( $new ), 'No record may be deleted while retention is off, however old it is.' );
	update_option( 'adf_retention_days', -5, false );
	adf_privacy_expect( 0 === adf_retention_days() && ! adf_retention_enabled(), 'A negative or corrupt stored period must mean OFF, never a silent short deletion window.' );
	delete_option( 'adf_retention_days' );
	$invalid = adf_save_retention_settings( array( 'enabled' => true, 'days' => 0 ) );
	adf_privacy_expect( is_wp_error( $invalid ) && 0 === adf_retention_days(), 'Enabling retention without a positive period must be rejected.' );
	$saved = adf_save_retention_settings( array( 'enabled' => true, 'days' => 30 ) );
	adf_privacy_expect( true === $saved && 30 === adf_retention_days() && adf_retention_enabled(), 'A validated explicit period must enable retention.' );
	adf_run_retention_cleanup();
	adf_privacy_expect( ! get_post( $old ) && get_post( $new ), 'Configured retention must delete only synthetic records older than the period.' );
	adf_privacy_expect( get_post( $form ) && 'adf_form' === get_post( $form )->post_type, 'Retention must never delete forms.' );
	adf_privacy_expect( false !== wp_next_scheduled( 'adf_retention_cleanup' ), 'Enabling retention must schedule the daily cleanup.' );
	$editor_id = wp_insert_user( array( 'user_login' => 'privacy-editor-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'pe-' . wp_generate_uuid4() . '@example.org', 'role' => 'editor' ) );
	$privacy_users[] = $editor_id;
	wp_set_current_user( $editor_id );
	adf_privacy_expect( is_wp_error( adf_save_retention_settings( array( 'enabled' => true, 'days' => 10 ) ) ) && 30 === adf_retention_days(), 'Editors must not change retention or deletion policy.' );
	wp_set_current_user( $admins[0]->ID );
	$off = adf_save_retention_settings( array( 'enabled' => false, 'days' => 0 ) );
	adf_privacy_expect( true === $off && 0 === adf_retention_days() && false === wp_next_scheduled( 'adf_retention_cleanup' ), 'Switching retention off must remove the scheduled deletion.' );
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	adf_privacy_expect( isset( $exporters['advanced-divi-forms'], $erasers['advanced-divi-forms'] ), 'WordPress privacy tools need this plugin\'s own exporter and eraser.' );
	$person = adf_store_submission( $form, array( 'name' => 'Personal', 'email' => ' Person@Example.org ' ) ); $created[] = $person;
	$other = adf_store_submission( $form, array( 'name' => 'Other', 'email' => 'other@example.org' ) ); $created[] = $other;
	$legacy = adf_store_submission( $form, array( 'name' => 'Legacy', 'email' => 'person@example.org' ) ); $created[] = $legacy;
	delete_post_meta( $legacy, '_adf_email' );
	$ambiguous = adf_store_submission( $form, array( 'name' => 'Ambiguous' ) ); $created[] = $ambiguous;
	update_post_meta( $ambiguous, '_adf_fields', array( 'email' => 'person@example.org', 'e-mail' => 'other@example.org' ) );
	delete_post_meta( $ambiguous, '_adf_email' );
	adf_privacy_expect( 'person@example.org' === get_post_meta( $person, '_adf_email', true ), 'Receipts must store a normalised email only for exact privacy matching.' );
	$exported = call_user_func( $exporters['advanced-divi-forms']['callback'], 'person@example.org', 1 );
	$text = wp_json_encode( $exported['data'] );
	adf_privacy_expect( str_contains( $text, 'Personal' ) && str_contains( $text, 'Legacy' ) && ! str_contains( $text, 'Other' ) && ! str_contains( $text, 'Ambiguous' ), 'The personal-data export must contain only the exact, matched person and never a guessed legacy row.' );
	adf_privacy_expect( str_contains( $text, 'not included' ), 'Unmatched legacy rows must be disclosed transparently.' );
	$erased = call_user_func( $erasers['advanced-divi-forms']['callback'], 'person@example.org', 1 );
	adf_privacy_expect( 2 === $erased['items_removed'] && $erased['done'] && ! get_post( $person ) && ! get_post( $legacy ), 'Erasure must delete exactly the matched records.' );
	adf_privacy_expect( get_post( $other ) && get_post( $ambiguous ), 'Erasure must never delete a different or ambiguous person.' );
	WP_CLI::success( 'Retention, privacy export/erasure and capability-separated policy passed.' );
} finally {
	foreach ( $privacy_users as $user_id ) { if ( get_userdata( $user_id ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user_id ); } }
	foreach ( array_reverse( $created ) as $item ) { if ( is_int( $item ) && get_post( $item ) ) { wp_delete_post( $item, true ); } }
	delete_option( 'adf_retention_days' );
	remove_filter( 'pre_wp_mail', $reject_mail );
	wp_set_current_user( $original_user );
}
