<?php
/**
 * Recoverable submission trash; request controllers authorize access separately.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_untrash_post_status', 'adf_restore_submission_status', 10, 2 );
add_filter( 'pre_delete_post', 'adf_protect_submission_trash', 10, 3 );

/**
 * Core's scheduled empty-trash job does not pass an explicit force-delete flag.
 *
 * Preserve this plugin's trashed answers; an authorized permanent-delete action
 * must call wp_delete_post with force=true after its own nonce/confirmation checks.
 * Other post types and explicit trusted server-side deletions remain unchanged.
 *
 * @param mixed   $delete Existing delete-filter result.
 * @param WP_Post $post   Post selected for deletion.
 * @param bool    $force  Explicit permanent-delete intent.
 * @return mixed
 */
function adf_protect_submission_trash( $delete, $post, $force ) {
	if ( 'adf_submission' === $post->post_type && 'trash' === $post->post_status && ! $force ) {
		return false;
	}
	return $delete;
}

/**
 * Never let a restored private answer become a draft/public record.
 *
 * @param string $status   Core-selected restored status.
 * @param int    $id       Post ID.
 * @return string
 */
function adf_restore_submission_status( $status, $id ) {
	return 'adf_submission' === get_post_type( $id ) ? 'private' : $status;
}

/**
 * Restore only a stored trashed answer, preserving its original metadata.
 *
 * @param int $id Submission already authorized and scoped by its caller.
 * @return true|WP_Error
 */
function adf_restore_submission( $id ) {
	$post = get_post( $id );
	if ( ! $post || 'adf_submission' !== $post->post_type || 'trash' !== $post->post_status ) {
		return new WP_Error( 'adf_invalid_selection', __( 'Only trashed submissions can be restored.', 'advanced-divi-forms' ) );
	}
	$result = wp_untrash_post( $id );
	if ( ! $result || is_wp_error( $result ) || 'private' !== get_post_status( $id ) ) {
		return new WP_Error( 'adf_restore_failed', __( 'A submission could not be restored. Reload before retrying; some selected records may already have been processed.', 'advanced-divi-forms' ) );
	}
	return true;
}

/**
 * Move only an active stored answer to trash, even if WordPress trash is disabled.
 *
 * Trusted server-side helper: callers must authorize and scope the request first.
 *
 * @param int $id Submission ID already checked against its form.
 * @return true|WP_Error
 */
function adf_trash_submission( $id ) {
	$post = get_post( $id );
	if ( ! $post || 'adf_submission' !== $post->post_type || 'private' !== $post->post_status ) {
		return new WP_Error( 'adf_invalid_selection', __( 'Only active submissions can be moved to Trash.', 'advanced-divi-forms' ) );
	}
	if ( EMPTY_TRASH_DAYS ) {
		$result = wp_trash_post( $id );
	} else {
		// Core wp_trash_post() permanently deletes when EMPTY_TRASH_DAYS is zero.
		$result = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'trash',
				'meta_input'  => array(
					'_wp_trash_meta_status' => 'private',
					'_wp_trash_meta_time'   => time(),
				),
			),
			true
		);
	}
	if ( ! $result || is_wp_error( $result ) || 'trash' !== get_post_status( $id ) ) {
		return new WP_Error( 'adf_trash_failed', __( 'A submission could not be moved to Trash. Reload before retrying; some selected records may already have been processed.', 'advanced-divi-forms' ) );
	}
	return true;
}
