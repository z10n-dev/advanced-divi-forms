<?php
/**
 * Administrator-only privacy settings. Submission capabilities deliberately do
 * not grant access to deletion policy or plugin settings.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'adf_register_privacy_menu' );
add_action( 'admin_post_adf_save_privacy', 'adf_save_privacy_request' );

/**
 * Add the settings page beneath Forms.
 *
 * @return void
 */
function adf_register_privacy_menu() {
	add_submenu_page(
		'advanced-divi-forms',
		__( 'Privacy & Retention', 'advanced-divi-forms' ),
		__( 'Privacy & Retention', 'advanced-divi-forms' ),
		'manage_options',
		'adf-privacy',
		'adf_render_privacy_settings'
	);
}

/**
 * Persist an explicit retention decision, then return to the settings screen.
 *
 * @return void
 */
function adf_save_privacy_request() {
	if ( ! adf_can_manage_plugin_settings() ) {
		wp_die( esc_html__( 'You are not allowed to change retention settings.', 'advanced-divi-forms' ), '', array( 'response' => 403 ) );
	}
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'post' !== sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
		wp_die( esc_html__( 'Use a POST request to save privacy settings.', 'advanced-divi-forms' ), '', array( 'response' => 405 ) );
	}
	check_admin_referer( 'adf_save_privacy' );
	if ( ! empty( $_POST['adf_retention_enabled'] ) && ! isset( $_POST['adf_retention_days'] ) ) {
		wp_die( esc_html__( 'Enter a retention period before enabling deletion.', 'advanced-divi-forms' ), '', array( 'response' => 400 ) );
	}
	$days   = isset( $_POST['adf_retention_days'] ) && is_scalar( $_POST['adf_retention_days'] ) ? absint( $_POST['adf_retention_days'] ) : 0;
	$saved  = adf_save_retention_settings(
		array(
			'enabled' => ! empty( $_POST['adf_retention_enabled'] ),
			'days'    => $days,
		)
	);
	$status = is_wp_error( $saved ) ? 'invalid' : 'saved';
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'        => 'adf-privacy',
				'adf_privacy' => $status,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Explain and control the opt-in deletion policy.
 *
 * @return void
 */
function adf_render_privacy_settings() {
	if ( ! adf_can_manage_plugin_settings() ) {
		wp_die( esc_html__( 'You are not allowed to access privacy settings.', 'advanced-divi-forms' ), '', array( 'response' => 403 ) );
	}
	$days     = adf_retention_days();
	$enabled  = adf_retention_enabled();
	$last_run = get_option( 'adf_retention_last_run' );
	$status   = isset( $_GET['adf_privacy'] ) && is_string( $_GET['adf_privacy'] ) ? sanitize_key( wp_unslash( $_GET['adf_privacy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after a nonce-protected redirect.
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Privacy & Retention', 'advanced-divi-forms' ); ?></h1>
		<?php if ( 'saved' === $status ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Privacy settings saved.', 'advanced-divi-forms' ); ?></p></div>
		<?php elseif ( 'invalid' === $status ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Choose a retention period between 1 and 3650 days, or switch automatic deletion off.', 'advanced-divi-forms' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Stored answers are kept until someone deletes them. Automatic deletion is OFF by default and only runs for a period you configure here.', 'advanced-divi-forms' ); ?></p>
		<table class="widefat striped">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Automatic deletion', 'advanced-divi-forms' ); ?></th><td><?php echo $enabled ? esc_html( sprintf( __( 'Enabled after %d days', 'advanced-divi-forms' ), $days ) ) : esc_html__( 'Disabled — nothing is deleted automatically', 'advanced-divi-forms' ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Configured retention period. ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Last cleanup', 'advanced-divi-forms' ); ?></th><td>
					<?php
					if ( is_array( $last_run ) && ! empty( $last_run['time'] ) ) {
						echo esc_html( sprintf( __( '%1$s — %2$d submissions removed (configured period: %3$d days)', 'advanced-divi-forms' ), wp_date( 'Y-m-d H:i', (int) $last_run['time'] ), (int) ( $last_run['removed'] ?? 0 ), (int) ( $last_run['days'] ?? 0 ) ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Cleanup timestamp, removed count and period.
					} else {
						esc_html_e( 'No cleanup has run yet.', 'advanced-divi-forms' );
					}
					?>
				</td></tr>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adf-privacy-form">
			<input type="hidden" name="action" value="adf_save_privacy">
			<?php wp_nonce_field( 'adf_save_privacy' ); ?>
			<p><label><input type="checkbox" name="adf_retention_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Permanently delete stored answers after the period below', 'advanced-divi-forms' ); ?></label></p>
			<p><label for="adf-retention-days"><?php esc_html_e( 'Retention period (days, 1–3650)', 'advanced-divi-forms' ); ?></label>
				<input type="number" id="adf-retention-days" name="adf_retention_days" min="1" max="3650" step="1" value="<?php echo esc_attr( $days ? $days : '' ); ?>"></p>
			<p class="description"><?php esc_html_e( 'This permanently deletes answers in Active and Trash once they are older than the period. It cannot be undone, forms and templates are never deleted, and up to 200 answers are removed per daily run. Only enable it where your privacy policy and consent cover it.', 'advanced-divi-forms' ); ?></p>
			<?php submit_button( __( 'Save privacy settings', 'advanced-divi-forms' ) ); ?>
		</form>
		<h2><?php esc_html_e( 'WordPress privacy tools', 'advanced-divi-forms' ); ?></h2>
		<p><?php esc_html_e( 'Export and erase requests for stored answers are handled under Tools → Export Personal Data and Tools → Erase Personal Data. Only records with the exactly verified email address are returned or erased; older ambiguous records are reported for manual review instead of being guessed.', 'advanced-divi-forms' ); ?></p>
	</div>
	<?php
}
