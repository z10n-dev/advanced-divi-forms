<?php
/**
 * Authorized submission actions; original submitted values are never edited.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_adf_manage_submissions', 'adf_manage_submissions' );

/**
 * Apply an action only after authorizing every selected record.
 *
 * @param int    $form_id Form ID.
 * @param array  $ids     Selected submission IDs.
 * @param string $action  Requested operation.
 * @param array  $options Operation options.
 * @return int|WP_Error Number of processed records or an error.
 */
function adf_apply_submission_action( $form_id, array $ids, $action, array $options = array() ) {
	if ( ! adf_can_manage_submissions() ) {
		return new WP_Error( 'adf_forbidden', __( 'You are not allowed to manage submissions.', 'advanced-divi-forms' ) );
	}
	$form = get_post( $form_id );
	if ( ! $form || 'adf_form' !== $form->post_type || 'private' !== $form->post_status ) {
		return new WP_Error( 'adf_invalid_form', __( 'The form does not exist.', 'advanced-divi-forms' ) );
	}
	if ( ! in_array( $action, array( 'mark_read', 'mark_unread', 'delete', 'quick_edit', 'trash', 'restore' ), true ) || ! $ids || count( $ids ) > 200 ) {
		return new WP_Error( 'adf_invalid_action', __( 'Choose an action and between 1 and 200 submissions.', 'advanced-divi-forms' ) );
	}
	$selected        = array();
	$required_status = in_array( $action, array( 'restore', 'delete' ), true ) ? 'trash' : 'private';
	foreach ( $ids as $id ) {
		if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id ) || (string) (int) $id !== (string) $id ) {
			return new WP_Error( 'adf_invalid_selection', __( 'The submission selection is invalid.', 'advanced-divi-forms' ) );
		}
		$submission = get_post( $id );
		if ( ! $submission || 'adf_submission' !== $submission->post_type || $submission->post_status !== $required_status || (int) $submission->post_parent !== (int) $form_id ) {
			return new WP_Error( 'adf_invalid_selection', __( 'Every selected submission must belong to this form and be in the correct Active or Trash state for this action.', 'advanced-divi-forms' ) );
		}
		$selected[ $submission->ID ] = $submission;
	}
	if ( 'quick_edit' === $action && ( 1 !== count( $selected ) || ! isset( $options['read'], $options['note'] ) || ! in_array( $options['read'], array( '0', '1' ), true ) || ! is_string( $options['note'] ) || ! preg_match( '/^.{0,5000}$/usD', $options['note'] ) ) ) {
		return new WP_Error( 'adf_invalid_edit', __( 'Choose a status and a note of at most 5000 characters for one submission.', 'advanced-divi-forms' ) );
	}
	if ( 'delete' === $action && '1' !== ( $options['confirm_delete'] ?? '' ) ) {
		return new WP_Error( 'adf_confirm_delete', __( 'Confirm permanent deletion before applying this action.', 'advanced-divi-forms' ) );
	}
	foreach ( $selected as $submission ) {
		if ( in_array( $action, array( 'trash', 'restore' ), true ) ) {
			$trashed = 'trash' === $action ? adf_trash_submission( $submission->ID ) : adf_restore_submission( $submission->ID );
			if ( is_wp_error( $trashed ) ) {
				return $trashed;
			}
			continue;
		}
		if ( 'delete' === $action ) {
			if ( ! wp_delete_post( $submission->ID, true ) || null !== get_post( $submission->ID ) ) {
				return new WP_Error( 'adf_delete_failed', __( 'A submission could not be deleted. Some selected records may already have been processed; reload the list before retrying.', 'advanced-divi-forms' ) );
			}
			continue;
		}
		$read = 'quick_edit' === $action ? (int) $options['read'] : (int) ( 'mark_read' === $action );
		update_post_meta( $submission->ID, '_adf_read', $read );
		if ( (string) get_post_meta( $submission->ID, '_adf_read', true ) !== (string) $read ) {
			return new WP_Error( 'adf_update_failed', __( 'The status could not be saved. Some selected records may already have been processed; reload before retrying.', 'advanced-divi-forms' ) );
		}
		if ( 'quick_edit' === $action ) {
			$note = sanitize_textarea_field( $options['note'] );
			update_post_meta( $submission->ID, '_adf_note', wp_slash( $note ) );
			if ( get_post_meta( $submission->ID, '_adf_note', true ) !== $note ) {
				return new WP_Error( 'adf_update_failed', __( 'The internal note could not be saved. Reload before retrying.', 'advanced-divi-forms' ) );
			}
		}
	}
	return count( $selected );
}

/**
 * Handle quick edits, bulk status actions, confirmed deletion or selected export.
 *
 * @return void
 */
function adf_manage_submissions() {
	adf_require_submission_capability( 'manage' );
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'post' !== sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
		wp_die( esc_html__( 'Use a POST request for submission actions.', 'advanced-divi-forms' ), '', array( 'response' => 405 ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict numeric validation; ID scopes the nonce immediately below.
	$form_id = isset( $_POST['form_id'] ) && is_string( $_POST['form_id'] ) && preg_match( '/^[1-9][0-9]*$/D', wp_unslash( $_POST['form_id'] ) ) ? (int) $_POST['form_id'] : 0;
	check_admin_referer( 'adf_manage_submissions_' . $form_id );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The parser validates all known filter values.
	$filters = adf_parse_submission_filters( wp_unslash( $_POST ) );
	if ( is_wp_error( $filters ) ) {
		wp_die( esc_html( $filters->get_error_message() ), '', array( 'response' => 400 ) );
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every ID is strictly validated before any mutation/export; never coerce malformed IDs.
	$ids       = isset( $_POST['submission_ids'] ) && is_array( $_POST['submission_ids'] ) ? wp_unslash( $_POST['submission_ids'] ) : array();
	$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
	$options   = array(
		'read'           => isset( $_POST['read'] ) && is_string( $_POST['read'] ) ? sanitize_text_field( wp_unslash( $_POST['read'] ) ) : '',
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated for size/type and sanitized in adf_apply_submission_action().
		'note'           => isset( $_POST['note'] ) && is_string( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '',
		'confirm_delete' => isset( $_POST['confirm_delete'] ) && '1' === $_POST['confirm_delete'] ? '1' : '',
	);
	if ( 'quick_edit' === $operation && ( ! isset( $_POST['read'], $_POST['note'] ) || ! is_string( $_POST['read'] ) || ! is_string( $_POST['note'] ) ) ) {
		wp_die( esc_html__( 'Status and note must both be supplied as text.', 'advanced-divi-forms' ), '', array( 'response' => 400 ) );
	}
	if ( 'export' === $operation ) {
		// Validate the selection without mutating records or falling back to all.
		$result = $ids ? adf_create_submission_export( $form_id, $ids, $filters ) : new WP_Error( 'adf_empty_selection', __( 'Select at least one submission to export.', 'advanced-divi-forms' ) );
		if ( ! is_wp_error( $result ) ) {
			adf_send_submission_export( $result, $form_id );
			return;
		}
	} else {
		$result = adf_apply_submission_action( $form_id, $ids, $operation, $options );
	}
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
