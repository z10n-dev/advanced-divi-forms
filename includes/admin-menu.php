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
add_action( 'admin_post_adf_update_submission', 'adf_update_submission' );

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
		'dashicons-feedback'
	);
	add_submenu_page(
		'advanced-divi-forms',
		__( 'All Forms', 'advanced-divi-forms' ),
		__( 'All Forms', 'advanced-divi-forms' ),
		'manage_options',
		'advanced-divi-forms',
		'adf_render_forms_page',
		0
	);
}

/**
 * Require administrator access before reading any stored submissions.
 *
 * @return void
 */
function adf_require_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( 'You are not allowed to access this page.', 'advanced-divi-forms' ),
			'',
			array( 'response' => 403 )
		);
	}
}

/**
 * Read a non-negative ID from a read-only admin query parameter.
 *
 * @param string $key Query parameter name.
 * @return int
 */
function adf_admin_query_id( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation; no state changes.
	return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0;
}

/**
 * Build a URL within the Forms screen.
 *
 * @param array $args Additional query parameters.
 * @return string
 */
function adf_forms_url( array $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'advanced-divi-forms' ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Query a single form's submissions, newest first.
 *
 * @param int $form_id  Registered form ID.
 * @param int $page     Page number.
 * @param int $per_page Number of results.
 * @return WP_Query
 */
function adf_submission_query( $form_id, $page = 1, $per_page = 20 ) {
	return new WP_Query(
		array(
			'post_type'      => 'adf_submission',
			'post_status'    => 'private',
			'post_parent'    => absint( $form_id ),
			'posts_per_page' => $per_page,
			'paged'          => max( 1, $page ),
			'orderby'        => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
		)
	);
}

/**
 * Render accessible pagination, preserving the current form.
 *
 * @param WP_Query $query Result set.
 * @param array    $args  Current navigation context.
 * @return void
 */
function adf_render_pagination( $query, array $args = array() ) {
	$links = paginate_links(
		array(
			'base'    => add_query_arg( 'adf_paged', '%#%', adf_forms_url( $args ) ),
			'format'  => '',
			'current' => max( 1, adf_admin_query_id( 'adf_paged' ) ),
			'total'   => $query->max_num_pages,
		)
	);
	if ( $links ) {
		echo '<nav class="tablenav" aria-label="' . esc_attr__( 'Pagination', 'advanced-divi-forms' ) . '">' . wp_kses_post( $links ) . '</nav>';
	}
}

/**
 * Route between the overview, a form's answers and an individual answer.
 *
 * @return void
 */
function adf_render_forms_page() {
	adf_require_admin();
	$form_id       = adf_admin_query_id( 'form_id' );
	$submission_id = adf_admin_query_id( 'submission_id' );
	if ( ! $form_id ) {
		adf_render_forms_overview();
		return;
	}
	$form = get_post( $form_id );
	if ( ! $form || 'adf_form' !== $form->post_type || 'private' !== $form->post_status ) {
		wp_die( esc_html__( 'The form does not exist.', 'advanced-divi-forms' ), '', array( 'response' => 404 ) );
	}
	if ( $submission_id ) {
		$submission = get_post( $submission_id );
		if ( ! $submission || 'adf_submission' !== $submission->post_type || 'private' !== $submission->post_status || $form_id !== (int) $submission->post_parent ) {
			wp_die( esc_html__( 'The submission does not exist for this form.', 'advanced-divi-forms' ), '', array( 'response' => 404 ) );
		}
		adf_render_submission( $form, $submission );
		return;
	}
	adf_render_submissions( $form );
}

/**
 * Render the paginated form directory.
 *
 * @return void
 */
function adf_render_forms_overview() {
	adf_require_admin();
	$query = new WP_Query(
		array(
			'post_type'      => 'adf_form',
			'post_status'    => 'private',
			'posts_per_page' => 20,
			'paged'          => max( 1, adf_admin_query_id( 'adf_paged' ) ),
			'orderby'        => array(
				'title' => 'ASC',
				'ID'    => 'ASC',
			),
		)
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'All Forms', 'advanced-divi-forms' ); ?></h1>
		<p><?php esc_html_e( 'Divi 5 forms are registered when their source page is saved or rendered. Discover existing forms below. Saving answers must be explicitly enabled in each form’s Email settings.', 'advanced-divi-forms' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adf_discover_forms">
			<input type="hidden" name="scan_page" value="<?php echo esc_attr( max( 1, adf_admin_query_id( 'scan_next' ) ) ); ?>">
			<?php wp_nonce_field( 'adf_discover_forms' ); ?>
			<?php submit_button( adf_admin_query_id( 'scan_next' ) ? __( 'Continue discovery', 'advanced-divi-forms' ) : __( 'Discover existing forms', 'advanced-divi-forms' ), 'secondary' ); ?>
		</form>
		<?php adf_render_discovery_report(); ?>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Form', 'advanced-divi-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Source Page', 'advanced-divi-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Submissions', 'advanced-divi-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last Submission', 'advanced-divi-forms' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $query->posts ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No forms have been registered yet.', 'advanced-divi-forms' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $query->posts as $form ) : ?>
				<?php
				$submissions = adf_submission_query( $form->ID, 1, 1 );
				$page_id     = (int) get_post_meta( $form->ID, '_adf_page_id', true );
				$source      = $page_id ? get_post( $page_id ) : null;
				?>
				<tr>
					<td>
						<a href="<?php echo esc_url( adf_forms_url( array( 'form_id' => $form->ID ) ) ); ?>"><?php echo esc_html( $form->post_title ); ?></a>
						<?php if ( 'shortcode' === get_post_meta( $form->ID, '_adf_divi_source_format', true ) ) : ?>
							<p class="description"><?php esc_html_e( 'Legacy Divi shortcode. Convert the source to Divi 5 before using template selection and answer capture.', 'advanced-divi-forms' ); ?></p>
						<?php endif; ?>
						<?php if ( get_post_meta( $form->ID, '_adf_storage_error', true ) ) : ?>
							<p class="description"><?php esc_html_e( 'The last submission could not be stored. Check the database before relying on capture.', 'advanced-divi-forms' ); ?></p>
						<?php endif; ?>
					</td>
					<td><?php echo $source ? esc_html( $source->post_title ) : esc_html__( 'Not available', 'advanced-divi-forms' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $submissions->found_posts ) ); ?></td>
					<td><?php echo $submissions->posts ? esc_html( get_the_date( 'Y-m-d H:i', $submissions->posts[0] ) ) : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php adf_render_pagination( $query ); ?>
	</div>
	<?php
}

/**
 * Explain the last discovery run, including found forms that could not be indexed.
 *
 * @return void
 */
function adf_render_discovery_report() {
	adf_require_admin();
	$report = get_transient( 'adf_discovery_' . get_current_user_id() );
	if ( ! is_array( $report ) ) {
		return;
	}
	?>
	<div class="notice notice-info inline">
		<h2><?php esc_html_e( 'Discovery Results', 'advanced-divi-forms' ); ?></h2>
		<ul>
			<li><?php esc_html_e( 'Source posts checked:', 'advanced-divi-forms' ); ?> <?php echo esc_html( number_format_i18n( $report['scanned'] ) ); ?></li>
			<li><?php esc_html_e( 'Form definitions found:', 'advanced-divi-forms' ); ?> <?php echo esc_html( number_format_i18n( $report['found'] ) ); ?></li>
			<li><?php esc_html_e( 'Forms with stable IDs indexed:', 'advanced-divi-forms' ); ?> <?php echo esc_html( number_format_i18n( $report['indexed'] ) ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Counts include all completed batches, including forms already indexed. If more source posts remain, use Continue discovery. These diagnostics expire after one hour.', 'advanced-divi-forms' ); ?></p>
	</div>
	<?php if ( ! empty( $report['issues'] ) ) : ?>
		<h2><?php esc_html_e( 'Detected Forms Requiring Attention', 'advanced-divi-forms' ); ?></h2>
		<p><?php esc_html_e( 'Up to 100 diagnostics are shown. No source page content has been modified.', 'advanced-divi-forms' ); ?></p>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Form', 'advanced-divi-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Source Page', 'advanced-divi-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Format', 'advanced-divi-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Next Step', 'advanced-divi-forms' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $report['issues'] as $issue ) : ?>
				<?php $edit_url = get_edit_post_link( $issue['post_id'], 'raw' ); ?>
				<tr>
					<td><?php echo esc_html( $issue['name'] ); ?></td>
					<td>
						<?php if ( $edit_url ) : ?>
							<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $issue['post_id'] ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( get_the_title( $issue['post_id'] ) ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo 'shortcode' === $issue['format'] ? esc_html__( 'Legacy Divi shortcode', 'advanced-divi-forms' ) : esc_html__( 'Divi 5 block', 'advanced-divi-forms' ); ?></td>
					<td>
						<?php if ( 'missing_uuid' === $issue['reason'] ) : ?>
							<?php esc_html_e( 'No persistent module UUID was saved. Open or migrate this source in the Divi 5 Builder. If saving alone does not assign an ID, test a newly added or duplicated Contact Form on staging. Run discovery again afterward. Positional IDs are not used because they could mix answers after reordering.', 'advanced-divi-forms' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'The form was found, but its index record could not be saved. Check the database and retry.', 'advanced-divi-forms' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

/**
 * Render one form's answer list.
 *
 * @param WP_Post $form Registered form.
 * @return void
 */
function adf_render_submissions( $form ) {
	adf_render_submission_list( $form );
}

/**
 * Display all fields without changing read status through a GET request.
 *
 * @param WP_Post $form       Registered form.
 * @param WP_Post $submission Submitted answer.
 * @return void
 */
function adf_render_submission( $form, $submission ) {
	adf_require_admin();
	$fields = (array) get_post_meta( $submission->ID, '_adf_fields', true );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( $form->post_title ); ?> — <?php esc_html_e( 'Submission', 'advanced-divi-forms' ); ?></h1>
		<p><a href="<?php echo esc_url( adf_forms_url( array( 'form_id' => $form->ID ) ) ); ?>">&larr; <?php esc_html_e( 'Back to submissions', 'advanced-divi-forms' ); ?></a></p>
		<p><?php echo esc_html( get_the_date( 'Y-m-d H:i', $submission ) ); ?></p>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e( 'Field ID', 'advanced-divi-forms' ); ?></th><th scope="col"><?php esc_html_e( 'Value', 'advanced-divi-forms' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $fields as $key => $value ) : ?>
				<tr><th scope="row"><?php echo esc_html( $key ); ?></th><td style="white-space:pre-wrap;"><?php echo esc_html( adf_field_text( $value ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adf_update_submission">
			<input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission->ID ); ?>">
			<?php wp_nonce_field( 'adf_submission_' . $submission->ID ); ?>
			<?php if ( ! get_post_meta( $submission->ID, '_adf_read', true ) ) : ?>
				<?php submit_button( __( 'Mark as read', 'advanced-divi-forms' ), 'secondary', 'adf_mark_read', false ); ?>
			<?php endif; ?>
			<p><label><input type="checkbox" name="adf_confirm_delete" value="1"> <?php esc_html_e( 'I confirm that this submission should be permanently deleted.', 'advanced-divi-forms' ); ?></label></p>
			<?php submit_button( __( 'Delete permanently', 'advanced-divi-forms' ), 'delete', 'adf_delete', false ); ?>
		</form>
	</div>
	<?php
}

/**
 * Authorize and apply a single submission action before redirecting.
 *
 * @return void
 */
function adf_update_submission() {
	adf_require_admin();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ID is needed to verify the item-specific nonce below.
	$id = isset( $_POST['submission_id'] ) && is_scalar( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
	check_admin_referer( 'adf_submission_' . $id );
	$submission = get_post( $id );
	if ( ! $submission || 'adf_submission' !== $submission->post_type || 'private' !== $submission->post_status ) {
		wp_die( esc_html__( 'The submission does not exist.', 'advanced-divi-forms' ), '', array( 'response' => 404 ) );
	}
	$form_id = (int) $submission->post_parent;
	if ( isset( $_POST['adf_mark_read'] ) ) {
		update_post_meta( $id, '_adf_read', 1 );
	} elseif ( isset( $_POST['adf_delete'], $_POST['adf_confirm_delete'] ) && '1' === $_POST['adf_confirm_delete'] ) {
		wp_delete_post( $id, true );
	}
	wp_safe_redirect( adf_forms_url( array( 'form_id' => $form_id ) ) );
	exit;
}
