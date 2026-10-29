<?php
/**
 * Admin menu for Advanced Divi Forms.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'adf_register_admin_menu' );

/**
 * Register the Forms menu.
 *
 * @return void
 */
function adf_register_admin_menu() {
	add_menu_page(
		__( 'Advanced Divi Forms', 'advanced-divi-forms' ),
		__( 'Forms', 'advanced-divi-forms' ),
		'manage_options',
		'advanced-divi-forms',
		'adf_render_forms_page',
		'dashicons-feedback',
	);
}

/**
 * Render the Forms admin page.
 *
 * @return void
 */
function adf_render_forms_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( 'You are not allowed to access this page.', 'advanced-divi-forms' )
		);
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Forms', 'advanced-divi-forms' ); ?></h1>
		<p>
			<?php esc_html_e( 'Your forms will appear here.', 'advanced-divi-forms' ); ?>
		</p>
	</div>
	<?php
}
