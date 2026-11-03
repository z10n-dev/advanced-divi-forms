<?php
/**
 * Uninstall contract: settings, state and capabilities are removed, stored
 * answers survive unless the site opts in to full deletion. Disposable WP only.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
function adf_uninstall_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$plugin_dir = WP_PLUGIN_DIR . '/advanced-divi-forms';
adf_uninstall_expect( is_file( $plugin_dir . '/uninstall.php' ), 'WordPress only runs uninstall.php when the plugin is deleted; it must exist in the release.' );
adf_uninstall_expect( is_file( $plugin_dir . '/includes/uninstall.php' ), 'Uninstall logic must live in a testable module.' );
require_once $plugin_dir . '/includes/uninstall.php';
adf_uninstall_expect( function_exists( 'adf_uninstall_plugin_data' ), 'The uninstall module must expose adf_uninstall_plugin_data().' );

$original_user = get_current_user_id();
$created_posts = array();
$editor_role  = get_role( 'editor' );
$created_posts[] = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Unrelated post' ) );
$form     = adf_register_form( 'uninstall-' . wp_generate_uuid4(), 'Uninstall test form' );
$submission = adf_store_submission( $form, array( 'email' => 'uninstall@example.org' ), array( 'email' => 'E-Mail' ) );
$template = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'Uninstall template' ) );
$created_posts[] = $form;
$created_posts[] = $submission;
$created_posts[] = $template;
adf_uninstall_expect( $submission > 0 && 'adf_submission' === get_post_type( $submission ), 'Fixture submission must exist before uninstall.' );

adf_install_submission_capabilities();
$editor_role->add_cap( 'adf_view_submissions' );
update_option( 'adf_retention_days', 30 );
update_option( 'adf_retention_last_run', time() );
update_option( 'adf_github_update_status', array( 'latest_version' => '9.9.9' ) );
set_transient( 'adf_github_latest_release', array( 'tag_name' => 'v9.9.9' ), HOUR_IN_SECONDS );
if ( ! wp_next_scheduled( 'adf_retention_cleanup' ) ) { wp_schedule_event( time() + 60, 'daily', 'adf_retention_cleanup' ); }

// Default: remove plugin state, keep every stored answer.
adf_uninstall_plugin_data();
adf_uninstall_expect( false === get_option( 'adf_retention_days', false ), 'Uninstall must remove the retention setting.' );
adf_uninstall_expect( false === get_option( 'adf_retention_last_run', false ), 'Uninstall must remove the retention run marker.' );
adf_uninstall_expect( false === get_option( 'adf_github_update_status', false ), 'Uninstall must remove the updater status.' );
adf_uninstall_expect( false === get_option( 'adf_capability_version', false ), 'Uninstall must remove the capability version marker.' );
adf_uninstall_expect( false === get_transient( 'adf_github_latest_release' ), 'Uninstall must remove the cached release.' );
adf_uninstall_expect( false === wp_next_scheduled( 'adf_retention_cleanup' ), 'Uninstall must unschedule the retention cleanup.' );
adf_uninstall_expect( ! get_role( 'administrator' )->has_cap( 'adf_view_submissions' ), 'Uninstall must remove the dedicated capabilities from administrators.' );
adf_uninstall_expect( ! $editor_role->has_cap( 'adf_view_submissions' ), 'Uninstall must remove the dedicated capabilities from every role that has them.' );
adf_uninstall_expect( get_option( 'blogname' ) !== false, 'Uninstall must not touch unrelated WordPress options.' );
adf_uninstall_expect( 'private' === get_post_status( $submission ), 'Uninstall without opt-in must keep stored answers in place.' );
adf_uninstall_expect( 'adf_form' === get_post_type( $form ), 'Uninstall without opt-in must keep form registrations.' );
adf_uninstall_expect( 'publish' === get_post_status( $created_posts[0] ), 'Uninstall must not touch unrelated posts.' );

// Opt-in: the site explicitly asked for complete removal.
update_option( 'adf_retention_days', 14 );
adf_uninstall_plugin_data( true );
adf_uninstall_expect( ! get_post( $submission ), 'Opt-in uninstall must delete stored answers.' );
adf_uninstall_expect( ! get_post( $form ), 'Opt-in uninstall must delete plugin form records.' );
adf_uninstall_expect( ! get_post( $template ), 'Opt-in uninstall must delete plugin mail templates.' );
adf_uninstall_expect( 'publish' === get_post_status( $created_posts[0] ), 'Even the opt-in uninstall must not touch unrelated content.' );
adf_uninstall_expect( false === get_option( 'adf_retention_days', false ), 'Opt-in uninstall must remove plugin settings too.' );

WP_CLI::success( 'Uninstall removes settings, cron and capabilities, keeps answers by default and deletes them only on explicit opt-in.' );

foreach ( $created_posts as $post ) { if ( get_post( $post ) ) { wp_delete_post( $post, true ); } }
wp_set_current_user( $original_user );
