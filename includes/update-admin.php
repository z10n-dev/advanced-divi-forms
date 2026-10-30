<?php
/**
 * Administrator-only update diagnostics and explicit fresh checks.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'adf_register_update_menu' );
add_action( 'admin_post_adf_check_updates', 'adf_handle_update_check' );

/**
 * Add update diagnostics under Forms without granting submission editors access.
 *
 * @return void
 */
function adf_register_update_menu() {
	add_submenu_page( 'advanced-divi-forms', __( 'Plugin Updates', 'advanced-divi-forms' ), __( 'Updates', 'advanced-divi-forms' ), 'manage_options', 'adf-updates', 'adf_render_update_status' );
}

/**
 * Refresh only this plugin's native update entry, preserving unrelated entries.
 *
 * This also keeps GitHub updates discoverable if WordPress.org's check fails.
 *
 * @param array|WP_Error $result Validated GitHub metadata or an error.
 * @return void
 */
function adf_sync_native_update_entry( $result ) {
	$updates = get_site_transient( 'update_plugins' );
	$updates = is_object( $updates ) ? $updates : new stdClass();
	$file    = plugin_basename( ADF_PLUGIN_FILE );
	foreach ( array( 'response', 'no_update', 'checked' ) as $key ) {
		if ( ! isset( $updates->$key ) || ! is_array( $updates->$key ) ) {
			$updates->$key = array();
		}
	}
	unset( $updates->response[ $file ], $updates->no_update[ $file ] );
	$updates->checked[ $file ] = ADF_PLUGIN_VERSION;
	if ( ! is_wp_error( $result ) ) {
		$entry              = (object) $result;
		$entry->plugin      = $file;
		$entry->new_version = $entry->version;
		if ( version_compare( $entry->version, ADF_PLUGIN_VERSION, '>' ) ) {
			$updates->response[ $file ] = $entry;
		} else {
			$updates->no_update[ $file ] = $entry;
		}
	}
	set_site_transient( 'update_plugins', $updates );
}

/**
 * Force a fresh GitHub fetch and a native WordPress plugin-update check.
 *
 * @return array|WP_Error Check metadata or an authorization/network error.
 */
function adf_force_update_check() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'adf_update_forbidden', __( 'You are not allowed to check plugin updates.', 'advanced-divi-forms' ) );
	}
	$result = adf_get_github_update( true );
	// Non-empty extra statistics bypass WordPress's normal recent-check guard.
	wp_update_plugins( array( 'adf_manual_check' => true ) );
	adf_sync_native_update_entry( $result );
	return $result;
}

/**
 * Authorize the cache-bypassing POST before performing any network request.
 *
 * @return void
 */
function adf_handle_update_check() {
	adf_require_admin();
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'post' !== sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
		wp_die( esc_html__( 'Use the update-check form to request a fresh check.', 'advanced-divi-forms' ), '', array( 'response' => 405 ) );
	}
	check_admin_referer( 'adf_check_updates' );
	adf_force_update_check();
	wp_safe_redirect( admin_url( 'admin.php?page=adf-updates' ) );
	exit;
}

/**
 * Show persisted network diagnostics without fetching on every admin page load.
 *
 * @return void
 */
function adf_render_update_status() {
	adf_require_admin();
	$status = get_option( 'adf_github_update_status', array() );
	$status = is_array( $status ) ? $status : array();
	$latest = isset( $status['latest_version'] ) && is_string( $status['latest_version'] ) ? $status['latest_version'] : '';
	$error  = isset( $status['error_message'] ) && is_string( $status['error_message'] ) ? $status['error_message'] : '';
	$rows   = array(
		__( 'Installed version', 'advanced-divi-forms' ) => ADF_PLUGIN_VERSION,
		__( 'Latest stable version (last successful check)', 'advanced-divi-forms' ) => $latest ? $latest : __( 'Not checked successfully yet', 'advanced-divi-forms' ),
		__( 'Last network attempt', 'advanced-divi-forms' ) => ! empty( $status['checked_at'] ) ? wp_date( 'Y-m-d H:i:s T', (int) $status['checked_at'] ) : '—',
		__( 'Last successful check', 'advanced-divi-forms' ) => ! empty( $status['last_success_at'] ) ? wp_date( 'Y-m-d H:i:s T', (int) $status['last_success_at'] ) : '—',
		__( 'Last HTTP status', 'advanced-divi-forms' )  => ! empty( $status['http_status'] ) ? (string) (int) $status['http_status'] : '—',
	);
	if ( ! empty( $status['retry_at'] ) ) {
		$rows[ __( 'GitHub rate-limit reset', 'advanced-divi-forms' ) ] = wp_date( 'Y-m-d H:i:s T', (int) $status['retry_at'] );
	}
	?>
	<div class="wrap adf-update-status">
		<h1><?php esc_html_e( 'Plugin Updates', 'advanced-divi-forms' ); ?></h1>
		<?php if ( $error ) : ?>
			<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'The last update check failed.', 'advanced-divi-forms' ); ?></strong> <?php echo esc_html( $error ); ?></p></div>
		<?php elseif ( $latest && version_compare( $latest, ADF_PLUGIN_VERSION, '>' ) ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'A newer release is available. Open the Plugins screen to install it; no update is installed by this check.', 'advanced-divi-forms' ); ?></p></div>
		<?php elseif ( $latest ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'No newer stable release was found at the last successful check.', 'advanced-divi-forms' ); ?></p></div>
		<?php endif; ?>
		<table class="form-table">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adf_check_updates">
			<?php wp_nonce_field( 'adf_check_updates', '_wpnonce', false ); ?>
			<?php submit_button( __( 'Check GitHub now (bypass cache)', 'advanced-divi-forms' ) ); ?>
		</form>
		<p class="description"><?php esc_html_e( 'This button fetches a fresh GitHub release and refreshes WordPress’s plugin-update results. Routine checks retain the six-hour release cache and a short error cooldown. WordPress’s general Check again link does not clear this plugin’s separate cache.', 'advanced-divi-forms' ); ?></p>
		<p><a href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Open Plugins', 'advanced-divi-forms' ); ?></a></p>
	</div>
	<?php
}
