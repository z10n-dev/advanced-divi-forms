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
add_action( 'load-toplevel_page_advanced-divi-forms', 'adf_validate_forms_route' );
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
 * @param int   $form_id  Registered form ID.
 * @param int   $page     Page number.
 * @param int   $per_page Number of results.
 * @param array $filters  Optional validated submission filters.
 * @return WP_Query|WP_Error
 */
function adf_submission_query( $form_id, $page = 1, $per_page = 20, array $filters = array() ) {
	$args = adf_submission_query_args( $form_id, $filters );
	if ( is_wp_error( $args ) ) {
		return $args;
	}
	$args['posts_per_page'] = $per_page;
	$args['paged']          = max( 1, $page );
	return new WP_Query( $args );
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
			'current' => max( 1, isset( $args['adf_paged'] ) ? (int) $args['adf_paged'] : adf_admin_query_id( 'adf_paged' ) ),
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
	$route = adf_resolve_forms_route();
	if ( ! $route['form'] ) {
		adf_render_forms_overview();
	} elseif ( $route['submission'] ) {
		adf_render_submission( $route['form'], $route['submission'] );
	} else {
		adf_render_submissions( $route['form'] );
	}
}

/**
 * Validate the route before WordPress emits admin HTML and commits HTTP headers.
 *
 * @return void
 */
function adf_validate_forms_route() {
	adf_resolve_forms_route();
}

/**
 * Resolve only records in the chosen form and validated active/trash view.
 *
 * @return array Authorized form and optional submission.
 */
function adf_resolve_forms_route() {
	adf_require_admin();
	$form_id       = adf_admin_query_id( 'form_id' );
	$submission_id = adf_admin_query_id( 'submission_id' );
	if ( ! $form_id ) {
		return array(
			'form'       => null,
			'submission' => null,
		);
	}
	$form = get_post( $form_id );
	if ( ! $form || 'adf_form' !== $form->post_type || 'private' !== $form->post_status ) {
		wp_die( esc_html__( 'The form does not exist.', 'advanced-divi-forms' ), '', array( 'response' => 404 ) );
	}
	$filters    = adf_current_submission_filters();
	$submission = null;
	if ( $submission_id ) {
		$status     = 'trash' === $filters['adf_view'] ? 'trash' : 'private';
		$submission = get_post( $submission_id );
		if ( ! $submission || 'adf_submission' !== $submission->post_type || $submission->post_status !== $status || $form_id !== (int) $submission->post_parent ) {
			wp_die( esc_html__( 'The submission does not exist for this form.', 'advanced-divi-forms' ), '', array( 'response' => 404 ) );
		}
	}
	return array(
		'form'       => $form,
		'submission' => $submission,
	);
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
	$filters  = adf_current_submission_filters();
	$in_trash = 'trash' === $submission->post_status;
	$page     = max( 1, adf_admin_query_id( 'adf_paged' ) );
	$fields   = (array) get_post_meta( $submission->ID, '_adf_fields', true );
	$labels   = adf_get_submission_labels( $submission->ID );
	$back_url = adf_forms_url(
		array_merge(
			adf_submission_filter_args( $filters ),
			array(
				'form_id'   => $form->ID,
				'adf_paged' => $page,
			)
		)
	);
	?>
	<div class="wrap adf-submission-detail">
		<h1><?php echo esc_html( $form->post_title ); ?> — <?php esc_html_e( 'Submission', 'advanced-divi-forms' ); ?></h1>
		<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to submissions', 'advanced-divi-forms' ); ?></a></p>
		<p><?php echo esc_html( get_the_date( 'Y-m-d H:i', $submission ) ); ?></p>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e( 'Field / ID', 'advanced-divi-forms' ); ?></th><th scope="col"><?php esc_html_e( 'Value', 'advanced-divi-forms' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $fields as $key => $value ) : ?>
				<tr><th scope="row"><?php echo esc_html( adf_submission_field_title( $submission->ID, $key, $labels ) ); ?></th><td style="white-space:pre-wrap;"><?php echo esc_html( adf_field_text( $value ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form class="adf-submission-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adf_update_submission">
			<input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission->ID ); ?>">
			<input type="hidden" name="return_page" value="<?php echo esc_attr( $page ); ?>">
			<?php adf_render_submission_filter_fields( $filters ); ?>
			<?php wp_nonce_field( 'adf_submission_' . $submission->ID ); ?>
			<?php if ( ! $in_trash && ! get_post_meta( $submission->ID, '_adf_read', true ) ) : ?>
				<div class="adf-single-status-action"><?php submit_button( __( 'Mark as read', 'advanced-divi-forms' ), 'secondary', 'adf_mark_read', false ); ?></div>
			<?php endif; ?>
			<?php if ( $in_trash ) : ?>
				<div class="adf-single-status-action"><?php submit_button( __( 'Restore submission', 'advanced-divi-forms' ), 'secondary', 'adf_restore', false ); ?></div>
			<p class="adf-delete-confirm"><label><input type="checkbox" name="adf_confirm_delete" value="1"><span><?php esc_html_e( 'I confirm that this submission should be permanently deleted.', 'advanced-divi-forms' ); ?></span></label></p>
				<?php submit_button( __( 'Delete permanently', 'advanced-divi-forms' ), 'delete adf-button-danger', 'adf_delete', false ); ?>
			<?php else : ?>
				<?php submit_button( __( 'Move to Trash', 'advanced-divi-forms' ), 'delete adf-button-danger', 'adf_trash', false ); ?>
			<?php endif; ?>
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
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'post' !== sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
		wp_die( esc_html__( 'Use a POST request for submission actions.', 'advanced-divi-forms' ), '', array( 'response' => 405 ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact canonical ID is validated before the item-scoped nonce.
	$value = isset( $_POST['submission_id'] ) && ( is_int( $_POST['submission_id'] ) || is_string( $_POST['submission_id'] ) ) ? (string) wp_unslash( $_POST['submission_id'] ) : '';
	$id    = preg_match( '/^[1-9][0-9]*$/D', $value ) && (string) (int) $value === $value ? (int) $value : 0;
	check_admin_referer( 'adf_submission_' . $id );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The filter parser validates known keys and ignores all other input.
	$filters = adf_parse_submission_filters( wp_unslash( $_POST ) );
	if ( is_wp_error( $filters ) ) {
		wp_die( esc_html( $filters->get_error_message() ), '', array( 'response' => 400 ) );
	}
	$submission = get_post( $id );
	if ( ! $submission || 'adf_submission' !== $submission->post_type || ! in_array( $submission->post_status, array( 'private', 'trash' ), true ) ) {
		wp_die( esc_html__( 'The submission does not exist.', 'advanced-divi-forms' ), '', array( 'response' => 404 ) );
	}
	$operations = array();
	foreach ( array(
		'adf_mark_read' => 'mark_read',
		'adf_trash'     => 'trash',
		'adf_restore'   => 'restore',
		'adf_delete'    => 'delete',
	) as $button => $operation ) {
		if ( isset( $_POST[ $button ] ) ) {
			if ( ! is_scalar( $_POST[ $button ] ) ) {
				wp_die( esc_html__( 'Choose one submission action.', 'advanced-divi-forms' ), '', array( 'response' => 400 ) );
			}
			$operations[] = $operation;
		}
	}
	if ( 1 !== count( $operations ) ) {
		wp_die( esc_html__( 'Choose one submission action.', 'advanced-divi-forms' ), '', array( 'response' => 400 ) );
	}
	$form_id = (int) $submission->post_parent;
	$options = array( 'confirm_delete' => isset( $_POST['adf_confirm_delete'] ) && '1' === $_POST['adf_confirm_delete'] ? '1' : '' );
	$result  = adf_apply_submission_action( $form_id, array( $id ), $operations[0], $options );
	if ( is_wp_error( $result ) ) {
		wp_die(
			esc_html( $result->get_error_message() ),
			'',
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}
	$page = isset( $_POST['return_page'] ) && is_scalar( $_POST['return_page'] ) ? max( 1, absint( $_POST['return_page'] ) ) : 1;
	wp_safe_redirect(
		adf_forms_url(
			array_merge(
				adf_submission_filter_args( $filters ),
				array(
					'form_id'     => $form_id,
					'adf_paged'   => $page,
					'adf_updated' => $result,
				)
			)
		)
	);
	exit;
}
