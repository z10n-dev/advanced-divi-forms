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
 * Store validated fields from a trusted form integration.
 *
 * The caller must validate the submission and spam/consent checks first.
 * Field keys are stable IDs, not labels. No IP or user-agent is collected.
 *
 * @param int   $form_id Registered form ID.
 * @param array $fields  Map of field IDs to submitted values.
 * @return int|WP_Error
 */
function adf_store_submission( $form_id, array $fields ) {
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
	if ( ! $clean_fields ) {
		return new WP_Error( 'adf_empty_submission', __( 'The submission contains no fields.', 'advanced-divi-forms' ) );
	}

	return wp_insert_post(
		wp_slash(
			array(
				'post_type'   => 'adf_submission',
				'post_status' => 'private',
				'post_parent' => $form->ID,
				'post_title'  => $form->post_title,
				'meta_input'  => array(
					'_adf_fields' => $clean_fields,
					'_adf_read'   => 0,
				),
			)
		),
		true
	);
}
