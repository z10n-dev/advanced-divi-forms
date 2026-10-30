<?php
/**
 * Read-only filter controls and fixed navigation context for submission screens.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read/validate only known filters on an authorized read-only admin screen.
 *
 * @return array Valid filters, or a controlled 400 response.
 */
function adf_current_submission_filters() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation; the parser validates every known filter.
	$filters = adf_parse_submission_filters( wp_unslash( $_GET ) );
	if ( is_wp_error( $filters ) ) {
		wp_die(
			esc_html( $filters->get_error_message() ),
			'',
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}
	return $filters;
}

/**
 * Keep non-default known filters only; never propagate arbitrary return URLs.
 *
 * @param array $filters Validated filters.
 * @return array Fixed navigation context.
 */
function adf_submission_filter_args( array $filters ) {
	$defaults = array(
		'adf_search' => '',
		'adf_status' => 'all',
		'adf_from'   => '',
		'adf_to'     => '',
		'adf_order'  => 'newest',
		'adf_view'   => 'active',
	);
	$args     = array();
	foreach ( $defaults as $key => $default ) {
		if ( isset( $filters[ $key ] ) && $filters[ $key ] !== $default ) {
			$args[ $key ] = $filters[ $key ];
		}
	}
	return $args;
}

/**
 * Preserve validated context in independent POST forms.
 *
 * @param array $filters Validated filters.
 * @return void
 */
function adf_render_submission_filter_fields( array $filters ) {
	foreach ( adf_submission_filter_args( $filters ) as $key => $value ) {
		echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
	}
}

/**
 * Applying a new filter intentionally resets the page, not the current form.
 *
 * @param int   $form_id Registered form ID.
 * @param array $filters Validated filters.
 * @return void
 */
/**
 * Show per-form active/trash totals; other filters affect only displayed matches.
 *
 * @param int   $form_id Registered form ID.
 * @param array $filters Validated navigation filters.
 * @return void
 */
function adf_render_submission_views( $form_id, array $filters ) {
	echo '<nav class="adf-view-links" aria-label="' . esc_attr__( 'Submission views', 'advanced-divi-forms' ) . '">';
	foreach ( array( 'active', 'trash' ) as $view ) {
		$count = adf_submission_query( $form_id, 1, 1, array( 'adf_view' => $view ) )->found_posts;
		$args  = array_merge(
			adf_submission_filter_args( $filters ),
			array(
				'form_id'  => $form_id,
				'adf_view' => $view,
			)
		);
		$label = 'trash' === $view ? __( 'Trash (%d)', 'advanced-divi-forms' ) : __( 'Active (%d)', 'advanced-divi-forms' ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Total submissions in this form view.
		echo '<a href="' . esc_url( adf_forms_url( $args ) ) . '"' . ( $view === $filters['adf_view'] ? ' aria-current="page"' : '' ) . '>' . esc_html( sprintf( $label, $count ) ) . '</a> ';
	}
	echo '</nav>';
}

/**
 * Render read-only filters, retaining the explicitly chosen submission view.
 *
 * @param int   $form_id Registered form ID.
 * @param array $filters Validated filters.
 * @return void
 */
function adf_render_submission_filters( $form_id, array $filters ) {
	?>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="adf-filter-form">
		<input type="hidden" name="page" value="advanced-divi-forms">
		<input type="hidden" name="adf_view" value="<?php echo esc_attr( $filters['adf_view'] ); ?>">
		<input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
		<label class="adf-filter-field" for="adf-filter-search"><?php esc_html_e( 'Search submitted data', 'advanced-divi-forms' ); ?><input type="search" id="adf-filter-search" name="adf_search" maxlength="200" value="<?php echo esc_attr( $filters['adf_search'] ); ?>"></label>
		<label class="adf-filter-field" for="adf-filter-status"><?php esc_html_e( 'Status', 'advanced-divi-forms' ); ?>
			<select id="adf-filter-status" name="adf_status">
				<option value="all" <?php selected( $filters['adf_status'], 'all' ); ?>><?php esc_html_e( 'All statuses', 'advanced-divi-forms' ); ?></option>
				<option value="unread" <?php selected( $filters['adf_status'], 'unread' ); ?>><?php esc_html_e( 'Unread', 'advanced-divi-forms' ); ?></option>
				<option value="read" <?php selected( $filters['adf_status'], 'read' ); ?>><?php esc_html_e( 'Read', 'advanced-divi-forms' ); ?></option>
			</select>
		</label>
		<label class="adf-filter-field" for="adf-filter-from"><?php esc_html_e( 'From date', 'advanced-divi-forms' ); ?><input type="date" id="adf-filter-from" name="adf_from" value="<?php echo esc_attr( $filters['adf_from'] ); ?>"></label>
		<label class="adf-filter-field" for="adf-filter-to"><?php esc_html_e( 'To date', 'advanced-divi-forms' ); ?><input type="date" id="adf-filter-to" name="adf_to" value="<?php echo esc_attr( $filters['adf_to'] ); ?>"></label>
		<label class="adf-filter-field" for="adf-filter-order"><?php esc_html_e( 'Date order', 'advanced-divi-forms' ); ?>
			<select id="adf-filter-order" name="adf_order">
				<option value="newest" <?php selected( $filters['adf_order'], 'newest' ); ?>><?php esc_html_e( 'Newest first', 'advanced-divi-forms' ); ?></option>
				<option value="oldest" <?php selected( $filters['adf_order'], 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'advanced-divi-forms' ); ?></option>
			</select>
		</label>
		<?php submit_button( __( 'Filter submissions', 'advanced-divi-forms' ), 'secondary', 'filter', false ); ?>
		<a class="button" href="<?php echo esc_url( adf_forms_url( array( 'form_id' => $form_id ) ) ); ?>"><?php esc_html_e( 'Reset filters', 'advanced-divi-forms' ); ?></a>
	</form>
	<?php
}
