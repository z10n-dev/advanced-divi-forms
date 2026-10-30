<?php
/**
 * Opt-in retention. Deletion happens only for a period an administrator
 * explicitly configured; zero days means the feature is off.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'adf_retention_cleanup', 'adf_run_retention_cleanup' );
add_action( 'admin_init', 'adf_sync_retention_schedule' );
register_deactivation_hook( ADF_PLUGIN_FILE, 'adf_unschedule_retention' );
add_filter( 'wp_privacy_personal_data_exporters', 'adf_register_privacy_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'adf_register_privacy_eraser' );
add_action( 'admin_init', 'adf_suggest_privacy_policy_text' );

/**
 * Register the exporter without exposing unrelated records.
 *
 * @param array $exporters Existing exporters.
 * @return array
 */
function adf_register_privacy_exporter( $exporters ) {
	$exporters['advanced-divi-forms'] = array(
		'exporter_friendly_name' => __( 'Advanced Divi Forms submissions', 'advanced-divi-forms' ),
		'callback'               => 'adf_privacy_export_submissions',
	);
	return $exporters;
}

/**
 * Register the eraser for the same exact identity rule as the exporter.
 *
 * @param array $erasers Existing erasers.
 * @return array
 */
function adf_register_privacy_eraser( $erasers ) {
	$erasers['advanced-divi-forms'] = array(
		'eraser_friendly_name' => __( 'Advanced Divi Forms submissions', 'advanced-divi-forms' ),
		'callback'             => 'adf_privacy_erase_submissions',
	);
	return $erasers;
}

/**
 * Resolve one submission's verified address, never guessing an ambiguous row.
 *
 * New receipts store the address already validated for the Divi email field.
 * Legacy receipts only count when exactly one email-like field carries exactly
 * one valid address; anything else stays unmatched instead of being assigned.
 *
 * @param int $submission_id Submission ID.
 * @return string Normalised address or an empty string when not unambiguous.
 */
function adf_submission_privacy_email( $submission_id ) {
	$stored = get_post_meta( $submission_id, '_adf_email', true );
	if ( is_string( $stored ) && '' !== $stored ) {
		return strtolower( $stored );
	}
	return adf_resolve_fields_email( (array) get_post_meta( $submission_id, '_adf_fields', true ) );
}

/**
 * Collect exactly matched submissions plus legacy ambiguity for transparency.
 *
 * @param string $email Normalised request address.
 * @return array ids, unresolved legacy rows, whether the scan was bounded.
 */
function adf_privacy_find_submissions( $email ) {
	$email = strtolower( sanitize_email( $email ) );
	if ( ! is_email( $email ) ) {
		return array(
			'ids'        => array(),
			'unresolved' => 0,
			'truncated'  => false,
		);
	}
	$matched    = get_posts(
		array(
			'post_type'      => 'adf_submission',
			'post_status'    => array( 'private', 'trash' ),
			'posts_per_page' => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Exact identity lookup, bounded by a small site-wide match set.
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_adf_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact privacy identity lookup.
			'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact privacy identity lookup.
		)
	);
	$unresolved = 0;
	$scanned    = 0;
	$page       = 1;
	do {
		$legacy = get_posts(
			array(
				'post_type'      => 'adf_submission',
				'post_status'    => array( 'private', 'trash' ),
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded legacy scan batch.
				'paged'          => $page,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded legacy fallback scan.
					array(
						'key'     => '_adf_email',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $legacy as $id ) {
			$resolved = adf_submission_privacy_email( $id );
			if ( $resolved === $email ) {
				$matched[] = $id;
			} elseif ( '' === $resolved ) {
				$has_candidate = false;
				foreach ( (array) get_post_meta( $id, '_adf_fields', true ) as $key => $value ) {
					if ( preg_match( '/(^|[_-])e-?mail([_-]|$)/i', (string) $key ) && is_scalar( $value ) && is_email( trim( (string) $value ) ) ) {
						$has_candidate = true;
						break;
					}
				}
				if ( $has_candidate ) {
					++$unresolved;
				}
			}
			++$scanned;
		}
		$batch_size = count( $legacy );
		++$page;
	} while ( 200 === $batch_size && $scanned < 5000 );
	return array(
		'ids'        => array_values( array_unique( array_map( 'intval', $matched ) ) ),
		'unresolved' => $unresolved,
		'truncated'  => $scanned >= 5000,
	);
}

/**
 * Export only records with an exactly verified address.
 *
 * @param string $email Requested address.
 * @param int    $page  Page number (results are returned in one page).
 * @return array
 */
function adf_privacy_export_submissions( $email, $page = 1 ) {
	$found = adf_privacy_find_submissions( $email );
	$data  = array();
	if ( 1 === (int) $page ) {
		foreach ( $found['ids'] as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'adf_submission' !== $post->post_type ) {
				continue;
			}
			$labels = adf_get_submission_labels( $id );
			$items  = array(
				array(
					'name'  => __( 'Received', 'advanced-divi-forms' ),
					'value' => get_the_date( 'Y-m-d H:i:s', $id ),
				),
			);
			foreach ( (array) get_post_meta( $id, '_adf_fields', true ) as $key => $value ) {
				$items[] = array(
					'name'  => $labels[ $key ] ?? (string) $key,
					'value' => adf_field_text( $value ),
				);
			}
			$data[] = array(
				'group_id'    => 'advanced-divi-forms',
				'group_label' => __( 'Advanced Divi Forms submissions', 'advanced-divi-forms' ),
				'item_id'     => 'adf-submission-' . $id,
				'data'        => $items,
			);
		}
		if ( $found['unresolved'] || $found['truncated'] ) {
			$data[] = array(
				'group_id'    => 'advanced-divi-forms',
				'group_label' => __( 'Advanced Divi Forms submissions', 'advanced-divi-forms' ),
				'item_id'     => 'adf-submission-notice',
				'data'        => array(
					array(
						'name'  => __( 'Note', 'advanced-divi-forms' ),
						'value' => __( 'Some older submissions could not be matched to a verified email address and are not included. Ask the site administrator to review them manually.', 'advanced-divi-forms' ),
					),
				),
			);
		}
	}
	return array(
		'data' => $data,
		'done' => true,
	);
}

/**
 * Delete exactly the records verified for this address.
 *
 * @param string $email Requested address.
 * @param int    $page  Page number (all matches are handled at once).
 * @return array
 */
function adf_privacy_erase_submissions( $email, $page = 1 ) {
	$found    = adf_privacy_find_submissions( $email );
	$removed  = 0;
	$retained = 0;
	if ( 1 === (int) $page ) {
		foreach ( $found['ids'] as $id ) {
			$post = get_post( $id );
			if ( $post && 'adf_submission' === $post->post_type && wp_delete_post( $id, true ) ) {
				++$removed;
			} else {
				++$retained;
			}
		}
	}
	$messages = array();
	if ( $found['unresolved'] || $found['truncated'] ) {
		$messages[] = __( 'Older submissions without a verified email address were retained for manual review; no record was assigned by guesswork.', 'advanced-divi-forms' );
	}
	if ( $retained ) {
		$messages[] = __( 'Some matched submissions could not be deleted and must be reviewed manually.', 'advanced-divi-forms' );
	}
	return array(
		'items_removed'  => $removed,
		'items_retained' => $retained,
		'messages'       => $messages,
		'done'           => true,
	);
}

/**
 * Offer suggested privacy-policy text; it is not legal advice.
 *
 * @return void
 */
function adf_suggest_privacy_policy_text() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	wp_add_privacy_policy_content(
		'Advanced Divi Forms',
		'<p>' . esc_html__( 'When a site administrator enables answer storage for a contact form, the submitted field values are saved privately, together with the available field labels, read status and internal notes. The plugin does not collect IP addresses or browser details. Optional retention permanently deletes stored answers after the configured period; it is off by default. Administrators can export, restore, delete or erase answers, and WordPress privacy export/erasure requests are matched only by an exact verified email address. Older records without a verified address are reviewed manually instead of being guessed.', 'advanced-divi-forms' ) . '</p>'
	);
}


/**
 * Configured retention period in days; 0 disables deletion entirely.
 *
 * @return int
 */
function adf_retention_days() {
	$raw  = get_option( 'adf_retention_days', 0 );
	$days = is_numeric( $raw ) ? (int) $raw : 0;
	// An invalid stored value means OFF. Never turn a corrupt/negative period
	// into a shorter deletion window nobody explicitly configured.
	return ( $days >= 1 && $days <= 3650 ) ? $days : 0;
}

/**
 * Whether an administrator has deliberately enabled automatic deletion.
 *
 * @return bool
 */
function adf_retention_enabled() {
	return adf_retention_days() > 0;
}

/**
 * Validate and persist an explicitly configured period, then reschedule.
 *
 * @param array $settings Raw settings with enabled and days keys.
 * @return true|WP_Error
 */
function adf_save_retention_settings( array $settings ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'adf_privacy_forbidden', __( 'You are not allowed to change retention settings.', 'advanced-divi-forms' ) );
	}
	$enabled = ! empty( $settings['enabled'] );
	$days    = isset( $settings['days'] ) && is_scalar( $settings['days'] ) ? absint( $settings['days'] ) : 0;
	if ( $enabled && ( $days < 1 || $days > 3650 ) ) {
		return new WP_Error( 'adf_retention_invalid', __( 'Choose a retention period between 1 and 3650 days before enabling automatic deletion.', 'advanced-divi-forms' ) );
	}
	$days = $enabled ? $days : 0;
	update_option( 'adf_retention_days', $days, false );
	adf_sync_retention_schedule();
	return true;
}


/**
 * Remove the scheduled deletion when the plugin is deactivated.
 *
 * @return void
 */
function adf_unschedule_retention() {
	$next = wp_next_scheduled( 'adf_retention_cleanup' );
	if ( $next ) {
		wp_unschedule_event( $next, 'adf_retention_cleanup' );
	}
}

/**
 * Schedule exactly one daily cleanup only while retention is enabled.
 *
 * @return void
 */
function adf_sync_retention_schedule() {
	$next = wp_next_scheduled( 'adf_retention_cleanup' );
	if ( adf_retention_enabled() && ! $next ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'adf_retention_cleanup' );
	} elseif ( ! adf_retention_enabled() && $next ) {
		wp_unschedule_event( $next, 'adf_retention_cleanup' );
	}
}

/**
 * Delete only submissions past the configured period, in bounded batches.
 *
 * Forms, templates and every other post type are never touched. Without an
 * explicit positive period this is a no-op, so upgrades delete nothing.
 *
 * @return int Number of removed submissions.
 */
function adf_run_retention_cleanup() {
	$days = adf_retention_days();
	if ( $days < 1 ) {
		return 0;
	}
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$ids     = get_posts(
		array(
			'post_type'        => 'adf_submission',
			'post_status'      => array( 'private', 'trash' ),
			'posts_per_page'   => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded daily deletion batch.
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'date_query'       => array(
				array(
					'column'    => 'post_date_gmt',
					'before'    => $cutoff,
					'inclusive' => true,
				),
			),
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	$removed = 0;
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( $post && 'adf_submission' === $post->post_type && wp_delete_post( $id, true ) ) {
			++$removed;
		}
	}
	update_option(
		'adf_retention_last_run',
		array(
			'time'    => time(),
			'removed' => $removed,
			'days'    => $days,
		),
		false
	);
	return $removed;
}
