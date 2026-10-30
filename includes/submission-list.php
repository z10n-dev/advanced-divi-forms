<?php
/**
 * Submission list, inline status/note editing and scoped bulk controls.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_enqueue_scripts', 'adf_enqueue_submission_assets' );

/**
 * Load selection helpers on this plugin's admin screen only.
 *
 * @param string $hook Current admin screen hook.
 * @return void
 */
function adf_enqueue_submission_assets( $hook ) {
	if ( 'toplevel_page_advanced-divi-forms' !== $hook || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_enqueue_script( 'adf-submissions', plugins_url( 'assets/submissions.js', ADF_PLUGIN_FILE ), array(), ADF_PLUGIN_VERSION, true );
	wp_enqueue_style( 'adf-submissions', plugins_url( 'assets/submissions.css', ADF_PLUGIN_FILE ), array(), ADF_PLUGIN_VERSION );
}

/**
 * Render a per-record editor; original field values are intentionally read-only.
 *
 * @param WP_Post $submission Submission post.
 * @param int     $page       Current list page.
 * @param array   $filters    Validated list filters.
 * @return void
 */
function adf_render_submission_quick_edit( $submission, $page = 1, array $filters = array() ) {
	if ( 'private' !== $submission->post_status ) {
		return;
	}
	$read = get_post_meta( $submission->ID, '_adf_read', true ) ? '1' : '0';
	$note = adf_field_text( get_post_meta( $submission->ID, '_adf_note', true ) );
	?>
	<details class="adf-quick-edit">
		<summary><?php esc_html_e( 'Quick Edit', 'advanced-divi-forms' ); ?></summary>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adf_manage_submissions">
			<input type="hidden" name="operation" value="quick_edit">
			<input type="hidden" name="form_id" value="<?php echo esc_attr( $submission->post_parent ); ?>">
			<input type="hidden" name="submission_ids[]" value="<?php echo esc_attr( $submission->ID ); ?>">
			<input type="hidden" name="return_page" value="<?php echo esc_attr( $page ); ?>">
			<?php adf_render_submission_filter_fields( $filters ); ?>
			<?php wp_nonce_field( 'adf_manage_submissions_' . $submission->post_parent, '_wpnonce', false ); ?>
			<p>
				<label for="adf-read-<?php echo esc_attr( $submission->ID ); ?>"><?php esc_html_e( 'Status', 'advanced-divi-forms' ); ?></label>
				<select id="adf-read-<?php echo esc_attr( $submission->ID ); ?>" name="read">
					<option value="0" <?php selected( $read, '0' ); ?>><?php esc_html_e( 'New / Unread', 'advanced-divi-forms' ); ?></option>
					<option value="1" <?php selected( $read, '1' ); ?>><?php esc_html_e( 'Read', 'advanced-divi-forms' ); ?></option>
				</select>
			</p>
			<p>
				<label for="adf-note-<?php echo esc_attr( $submission->ID ); ?>"><?php esc_html_e( 'Internal note', 'advanced-divi-forms' ); ?></label>
				<textarea id="adf-note-<?php echo esc_attr( $submission->ID ); ?>" name="note" rows="4" maxlength="5000"><?php echo esc_textarea( $note ); ?></textarea>
			</p>
			<p class="description"><?php esc_html_e( 'Admin-only note. Original submitted values remain unchanged. Notes are included in Excel exports.', 'advanced-divi-forms' ); ?></p>
			<div class="adf-quick-edit-actions">
				<?php submit_button( __( 'Save changes', 'advanced-divi-forms' ), 'secondary', 'save', false ); ?>
				<button type="button" class="button adf-cancel-edit" hidden><?php esc_html_e( 'Cancel', 'advanced-divi-forms' ); ?></button>
			</div>
		</form>
	</details>
	<?php
}

/**
 * Render a paginated submission list with independent, non-nested POST forms.
 *
 * @param WP_Post $form Registered form.
 * @return void
 */
function adf_render_submission_list( $form ) {
	adf_require_admin();
	$filters   = adf_current_submission_filters();
	$in_trash  = 'trash' === $filters['adf_view'];
	$page      = max( 1, adf_admin_query_id( 'adf_paged' ) );
	$query     = adf_submission_query( $form->ID, $page, 20, $filters );
	$last_page = max( 1, (int) $query->max_num_pages );
	if ( $page > $last_page ) {
		$page  = $last_page;
		$query = adf_submission_query( $form->ID, $page, 20, $filters );
	}
	?>
	<div class="wrap adf-submissions">
		<h1><?php echo esc_html( $form->post_title ); ?></h1>
		<p><a href="<?php echo esc_url( adf_forms_url() ); ?>">&larr; <?php esc_html_e( 'All Forms', 'advanced-divi-forms' ); ?></a></p>
		<h2><?php esc_html_e( 'Submissions', 'advanced-divi-forms' ); ?></h2>
		<?php adf_render_submission_views( $form->ID, $filters ); ?>
		<?php adf_render_submission_filters( $form->ID, $filters ); ?>
		<p class="adf-result-count"><?php echo esc_html( sprintf( __( '%d matching submissions', 'advanced-divi-forms' ), $query->found_posts ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Number of submissions matching the current filters. ?></p>
		<?php if ( adf_admin_query_id( 'adf_updated' ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Submission changes saved.', 'advanced-divi-forms' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Select submissions on this page for bulk actions. Export all includes every page of this form. Original submitted values cannot be edited.', 'advanced-divi-forms' ); ?></p>
		<form class="adf-export-all" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adf_export_submissions">
			<input type="hidden" name="form_id" value="<?php echo esc_attr( $form->ID ); ?>">
			<?php wp_nonce_field( 'adf_export_submissions_' . $form->ID, '_wpnonce', false ); ?>
			<?php adf_render_submission_filter_fields( $filters ); ?>
			<button type="submit" class="button" name="scope" value="filtered"><?php echo $in_trash ? esc_html__( 'Export filtered Trash to Excel', 'advanced-divi-forms' ) : esc_html__( 'Export filtered to Excel', 'advanced-divi-forms' ); ?></button>
			<?php
			if ( ! $in_trash ) :
				?>
				<button type="submit" class="button" name="scope" value="all"><?php esc_html_e( 'Export all to Excel', 'advanced-divi-forms' ); ?></button><?php endif; ?>
			<span class="description"><?php esc_html_e( 'XLSX · up to 10,000 submissions per export. Includes internal notes; handle downloads as private data.', 'advanced-divi-forms' ); ?></span>
		</form>
		<form id="adf-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adf_manage_submissions">
			<input type="hidden" name="form_id" value="<?php echo esc_attr( $form->ID ); ?>">
			<input type="hidden" name="return_page" value="<?php echo esc_attr( $page ); ?>">
			<?php adf_render_submission_filter_fields( $filters ); ?>
			<?php wp_nonce_field( 'adf_manage_submissions_' . $form->ID, '_wpnonce', false ); ?>
			<div class="tablenav top">
				<label class="screen-reader-text" for="adf-bulk-operation"><?php esc_html_e( 'Bulk actions', 'advanced-divi-forms' ); ?></label>
				<select name="operation" id="adf-bulk-operation" required>
					<option value=""><?php esc_html_e( 'Bulk actions', 'advanced-divi-forms' ); ?></option>
					<?php if ( $in_trash ) : ?>
						<option value="restore"><?php esc_html_e( 'Restore selected', 'advanced-divi-forms' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete permanently', 'advanced-divi-forms' ); ?></option>
					<?php else : ?>
						<option value="mark_read"><?php esc_html_e( 'Mark as read', 'advanced-divi-forms' ); ?></option>
						<option value="mark_unread"><?php esc_html_e( 'Mark as unread', 'advanced-divi-forms' ); ?></option>
						<option value="trash"><?php esc_html_e( 'Move to Trash', 'advanced-divi-forms' ); ?></option>
					<?php endif; ?>
					<option value="export"><?php esc_html_e( 'Export selected to Excel', 'advanced-divi-forms' ); ?></option>
				</select>
				<?php submit_button( __( 'Apply', 'advanced-divi-forms' ), 'secondary', 'apply', false ); ?>
				<span data-adf-selection-count data-template="<?php echo esc_attr__( '%d selected', 'advanced-divi-forms' ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Number of selected submissions. ?>" aria-live="polite"></span>
			</div>
			<?php if ( $in_trash ) : ?>
			<p class="adf-delete-confirm"><label><input type="checkbox" name="confirm_delete" value="1"><span><?php esc_html_e( 'I confirm permanent deletion of the selected submissions. This cannot be undone.', 'advanced-divi-forms' ); ?></span></label></p>
			<?php endif; ?>
		</form>
		<div class="adf-table-scroll">
		<table class="widefat striped">
			<thead><tr>
				<td class="check-column"><input type="checkbox" id="adf-select-all" data-adf-select-all hidden><label for="adf-select-all" class="screen-reader-text"><?php esc_html_e( 'Select all submissions on this page', 'advanced-divi-forms' ); ?></label></td>
				<th scope="col"><?php esc_html_e( 'Received', 'advanced-divi-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'advanced-divi-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Preview', 'advanced-divi-forms' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Internal note', 'advanced-divi-forms' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $query->posts ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No submissions match these filters.', 'advanced-divi-forms' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $query->posts as $submission ) : ?>
				<?php
				$fields = (array) get_post_meta( $submission->ID, '_adf_fields', true );
				$read   = get_post_meta( $submission->ID, '_adf_read', true );
				$url    = adf_forms_url(
					array_merge(
						adf_submission_filter_args( $filters ),
						array(
							'form_id'       => $form->ID,
							'submission_id' => $submission->ID,
							'adf_paged'     => $page,
						)
					)
				);
				?>
				<tr data-adf-submission="<?php echo esc_attr( $submission->ID ); ?>">
					<th scope="row" class="check-column"><input type="checkbox" form="adf-bulk-form" name="submission_ids[]" value="<?php echo esc_attr( $submission->ID ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select submission #%d', 'advanced-divi-forms' ), $submission->ID ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Submission identifier label. ?>"></th>
					<td><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( get_the_date( 'Y-m-d H:i', $submission ) ); ?></a><br><span class="description">#<?php echo esc_html( $submission->ID ); ?></span></td>
					<td><span class="adf-status <?php echo $read ? 'adf-status-read' : 'adf-status-new'; ?>"><?php echo $read ? esc_html__( 'Read', 'advanced-divi-forms' ) : esc_html__( 'New', 'advanced-divi-forms' ); ?></span></td>
					<td class="adf-preview"><?php echo esc_html( wp_trim_words( implode( ' · ', array_map( 'adf_field_text', $fields ) ), 18 ) ); ?><?php adf_render_submission_quick_edit( $submission, $page, $filters ); ?></td>
					<td class="adf-note-preview"><?php echo esc_html( adf_field_text( get_post_meta( $submission->ID, '_adf_note', true ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
		adf_render_pagination(
			$query,
			array_merge(
				adf_submission_filter_args( $filters ),
				array(
					'form_id'   => $form->ID,
					'adf_paged' => $page,
				)
			)
		);
		?>
	</div>
	<?php
}
