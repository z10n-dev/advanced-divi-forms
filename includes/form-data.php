<?php
/**
 * Private storage for registered forms and their submissions.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'adf_register_form_storage' );

/**
 * Shared access restrictions for internal post types.
 *
 * @return array
 */
function adf_private_post_type_args() {
	return array(
		'public'              => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'show_ui'             => false,
		'show_in_rest'        => false,
		'rewrite'             => false,
		'query_var'           => false,
		'can_export'          => false,
		'delete_with_user'    => false,
		'map_meta_cap'        => false,
		'capabilities'        => array_fill_keys(
			array(
				'edit_post',
				'read_post',
				'delete_post',
				'edit_posts',
				'edit_others_posts',
				'publish_posts',
				'read_private_posts',
				'delete_posts',
				'delete_private_posts',
				'delete_published_posts',
				'delete_others_posts',
				'edit_private_posts',
				'edit_published_posts',
				'create_posts',
				'read',
			),
			'manage_options'
		),
		'supports'            => array( 'title' ),
	);
}

/**
 * Register non-public storage, without extra admin menus or REST endpoints.
 *
 * @return void
 */
function adf_register_form_storage() {
	register_post_type( 'adf_form', adf_private_post_type_args() );
	register_post_type( 'adf_submission', adf_private_post_type_args() );
}

/**
 * Register or update a form from a trusted server-side integration.
 *
 * No public submission endpoint or unverified Divi hook is registered here.
 *
 * @param string $source_key Stable integration key, such as page ID plus module ID.
 * @param string $name       Display name.
 * @param int    $page_id    Source WordPress page or post ID.
 * @return int|WP_Error
 */
function adf_register_form( $source_key, $name, $page_id = 0 ) {
	$source_key = sanitize_text_field( $source_key );
	$name       = sanitize_text_field( $name );
	if ( '' === $source_key || '' === $name ) {
		return new WP_Error( 'adf_invalid_form', __( 'A form needs a stable source key and a name.', 'advanced-divi-forms' ) );
	}

	$existing = get_posts(
		array(
			'post_type'      => 'adf_form',
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_adf_source_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Internal stable form identity.
			'meta_value'     => $source_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Internal stable form identity.
		)
	);
	if ( $existing ) {
		$post = get_post( $existing[0] );
		if ( $post->post_title === $name && absint( $page_id ) === (int) get_post_meta( $post->ID, '_adf_page_id', true ) ) {
			return $post->ID;
		}
	}

	return wp_insert_post(
		wp_slash(
			array(
				'ID'          => $existing ? $existing[0] : 0,
				'post_type'   => 'adf_form',
				'post_status' => 'private',
				'post_title'  => $name,
				'meta_input'  => array(
					'_adf_source_key' => $source_key,
					'_adf_page_id'    => absint( $page_id ),
				),
			)
		),
		true
	);
}

/**
 * Normalize submitted field values to plain text, including multiselects.
 *
 * @param mixed $value Submitted value.
 * @return string
 */
function adf_field_text( $value ) {
	if ( is_array( $value ) ) {
		return implode( ', ', array_map( 'adf_field_text', $value ) );
	}
	return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
}

/**
 * Resolve one verified address: the caller's validated value wins, otherwise
 * exactly one distinct valid address across email-like field IDs. Ambiguity
 * returns an empty string rather than assigning the wrong person's data.
 *
 * @param array  $fields Sanitized field values keyed by stable ID.
 * @param string $email  Optional address already validated by the caller.
 * @return string Normalised address or ''.
 */
function adf_resolve_fields_email( array $fields, $email = '' ) {
	$email = is_string( $email ) ? strtolower( trim( $email ) ) : '';
	if ( is_email( $email ) ) {
		return $email;
	}
	$candidates = array();
	foreach ( $fields as $key => $value ) {
		if ( ! preg_match( '/(^|[_-])e-?mail([_-]|$)/i', (string) $key ) || ! is_scalar( $value ) ) {
			continue;
		}
		$value = strtolower( trim( (string) $value ) );
		if ( is_email( $value ) ) {
			$candidates[ $value ] = true;
		}
	}
	return 1 === count( $candidates ) ? (string) array_key_first( $candidates ) : '';
}

/**
 * Read historical labels only; never infer them from a newer form definition.
 *
 * @param int $submission_id Stored submission ID, already authorized by caller.
 * @return array Field IDs mapped to sanitized labels or stable-ID fallback.
 */
function adf_get_submission_labels( $submission_id ) {
	$fields   = (array) get_post_meta( $submission_id, '_adf_fields', true );
	$snapshot = get_post_meta( $submission_id, '_adf_field_labels', true );
	$snapshot = is_array( $snapshot ) ? $snapshot : array();
	$result   = array();
	foreach ( $fields as $key => $unused ) {
		$label          = isset( $snapshot[ $key ] ) && is_scalar( $snapshot[ $key ] ) ? adf_field_text( $snapshot[ $key ] ) : '';
		$result[ $key ] = '' === $label ? (string) $key : $label;
	}
	return $result;
}

/**
 * Show a human label without hiding or merging the stable field identifier.
 *
 * @param int        $submission_id Authorized submission.
 * @param string     $key           Stable field ID.
 * @param array|null $labels        Optional per-record cache for bulk rendering.
 * @return string Plain text for context-specific escaping by the caller.
 */
function adf_submission_field_title( $submission_id, $key, ?array $labels = null ) {
	$labels = null === $labels ? adf_get_submission_labels( $submission_id ) : $labels;
	$label  = $labels[ $key ] ?? (string) $key;
	return $label === (string) $key ? $label : $label . ' [' . $key . ']';
}

/**
 * Store validated fields from a trusted form integration.
 *
 * The caller must validate the submission and spam/consent checks first.
 * Field keys are stable IDs, not labels. No IP or user-agent is collected.
 *
 * @param int    $form_id Registered form ID.
 * @param array  $fields  Map of field IDs to submitted values.
 * @param array  $labels  Optional field-ID label snapshot; does not alter fields.
 * @param string $email  Optional already validated address for exact privacy matching.
 * @return int|WP_Error
 */
function adf_store_submission( $form_id, array $fields, array $labels = array(), $email = '' ) {
	$form = $form_id ? get_post( absint( $form_id ) ) : null;
	if ( ! $form || 'adf_form' !== $form->post_type || 'private' !== $form->post_status ) {
		return new WP_Error( 'adf_invalid_form', __( 'The form does not exist.', 'advanced-divi-forms' ) );
	}

	$clean_fields = array();
	foreach ( $fields as $key => $value ) {
		$key = (string) $key;
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $key ) ) {
			return new WP_Error( 'adf_invalid_field', __( 'Field IDs may contain letters, numbers, underscores and hyphens only.', 'advanced-divi-forms' ) );
		}
		$clean_fields[ $key ] = adf_field_text( $value );
	}
	$clean_labels = array();
	foreach ( $clean_fields as $key => $value ) {
		if ( isset( $labels[ $key ] ) && is_scalar( $labels[ $key ] ) ) {
			$label = adf_field_text( $labels[ $key ] );
			if ( '' !== $label ) {
				$clean_labels[ $key ] = $label; }
		}
	}
	$clean_email = adf_resolve_fields_email( $clean_fields, $email );
	if ( ! $clean_fields ) {
		return new WP_Error( 'adf_empty_submission', __( 'The submission contains no fields.', 'advanced-divi-forms' ) );
	}

	$submission_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'   => 'adf_submission',
				'post_status' => 'private',
				'post_parent' => $form->ID,
				'post_title'  => $form->post_title,
				'meta_input'  => array(
					'_adf_fields'       => $clean_fields,
					'_adf_field_labels' => $clean_labels,
					'_adf_email'        => $clean_email,
					'_adf_read'         => 0,
				),
			)
		),
		true
	);
	if ( is_wp_error( $submission_id ) ) {
		return $submission_id; }
	if ( $clean_labels && get_post_meta( $submission_id, '_adf_field_labels', true ) !== $clean_labels ) {
		return new WP_Error( 'adf_label_snapshot_failed', __( 'The original submission was retained, but its label snapshot could not be stored. Review storage before collecting more answers.', 'advanced-divi-forms' ), array( 'submission_id' => $submission_id ) );
	}
	return $submission_id;
}
