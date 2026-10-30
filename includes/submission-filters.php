<?php
/**
 * Shared, validated submission filters for lists and private exports.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accept only supported text filters, actual calendar dates and fixed sort modes.
 *
 * @param array $input Unslashed GET/POST input or trusted server-side filters.
 * @return array|WP_Error Normalized known filters, never arbitrary query arguments.
 */
function adf_parse_submission_filters( array $input ) {
	$filters = array(
		'adf_search' => '',
		'adf_status' => 'all',
		'adf_from'   => '',
		'adf_to'     => '',
		'adf_order'  => 'newest',
	);
	foreach ( $filters as $key => $default ) {
		if ( array_key_exists( $key, $input ) ) {
			if ( ! is_string( $input[ $key ] ) ) {
				return new WP_Error( 'adf_invalid_filters', __( 'Submission filters must be plain text.', 'advanced-divi-forms' ) );
			}
			$filters[ $key ] = $input[ $key ];
		}
	}
	$filters['adf_search'] = trim( sanitize_text_field( $filters['adf_search'] ) );
	if ( ! preg_match( '/^.{0,200}$/usD', $filters['adf_search'] ) || ! in_array( $filters['adf_status'], array( 'all', 'read', 'unread' ), true ) || ! in_array( $filters['adf_order'], array( 'newest', 'oldest' ), true ) ) {
		return new WP_Error( 'adf_invalid_filters', __( 'Use a search of at most 200 characters and choose a valid status and date sort order.', 'advanced-divi-forms' ) );
	}
	foreach ( array( 'adf_from', 'adf_to' ) as $key ) {
		$date = $filters[ $key ];
		if ( '' !== $date && ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) ) {
			return new WP_Error( 'adf_invalid_dates', __( 'Choose valid dates in YYYY-MM-DD format.', 'advanced-divi-forms' ) );
		}
	}
	if ( '' !== $filters['adf_from'] && '' !== $filters['adf_to'] && $filters['adf_from'] > $filters['adf_to'] ) {
		return new WP_Error( 'adf_invalid_dates', __( 'The start date must not be later than the end date.', 'advanced-divi-forms' ) );
	}
	return $filters;
}

/**
 * Build one form's query using WordPress's escaping/prepared query builders.
 *
 * @param int   $form_id Registered form ID.
 * @param array $input   Optional unslashed filters.
 * @return array|WP_Error Fixed-scope query arguments or invalid-filter error.
 */
function adf_submission_query_args( $form_id, array $input = array() ) {
	$filters = adf_parse_submission_filters( $input );
	if ( is_wp_error( $filters ) ) {
		return $filters;
	}
	$meta = array( 'relation' => 'AND' );
	if ( '' !== $filters['adf_search'] ) {
		$meta[] = array(
			'key'     => '_adf_fields',
			'value'   => $filters['adf_search'],
			'compare' => 'LIKE',
		);
	}
	if ( 'read' === $filters['adf_status'] ) {
		$meta[] = array(
			'key'     => '_adf_read',
			'value'   => '1',
			'compare' => '=',
		);
	}
	if ( 'unread' === $filters['adf_status'] ) {
		$meta[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_adf_read',
				'value'   => '0',
				'compare' => '=',
			),
			array(
				'key'     => '_adf_read',
				'value'   => '',
				'compare' => '=',
			),
			array(
				'key'     => '_adf_read',
				'compare' => 'NOT EXISTS',
			),
		);
	}
	$args  = array(
		'post_type'   => 'adf_submission',
		'post_status' => 'private',
		'post_parent' => absint( $form_id ),
		'orderby'     => array(
			'date' => 'oldest' === $filters['adf_order'] ? 'ASC' : 'DESC',
			'ID'   => 'oldest' === $filters['adf_order'] ? 'ASC' : 'DESC',
		),
		'meta_query'  => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Internal private fields; WP_Meta_Query escapes LIKE values and scopes the form.
	);
	$dates = array(
		'column'    => 'post_date',
		'inclusive' => true,
	);
	foreach ( array(
		'adf_from' => 'after',
		'adf_to'   => 'before',
	) as $key => $boundary ) {
		if ( '' !== $filters[ $key ] ) {
			$parts              = array_map( 'intval', explode( '-', $filters[ $key ] ) );
			$dates[ $boundary ] = array(
				'year'  => $parts[0],
				'month' => $parts[1],
				'day'   => $parts[2],
			);
		}
	}
	if ( isset( $dates['after'] ) || isset( $dates['before'] ) ) {
		$args['date_query'] = array( $dates );
	}
	return $args;
}
