<?php
/**
 * Synthetic-only template preview; no submission persistence or mail delivery.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_adf_preview_template', 'adf_handle_template_preview' );

/**
 * Authorize an ephemeral editor preview; never save or dispatch any mail.
 *
 * @return void
 */
function adf_handle_template_preview() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to preview template HTML.', 'advanced-divi-forms' ) ), 403 );
	}
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'post' !== sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Use POST for a synthetic template preview.', 'advanced-divi-forms' ) ), 405 );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Canonical ID validation precedes its dedicated nonce.
	$value = isset( $_POST['template_id'] ) && ( is_int( $_POST['template_id'] ) || is_string( $_POST['template_id'] ) ) ? (string) wp_unslash( $_POST['template_id'] ) : '';
	$id    = preg_match( '/^[1-9][0-9]*$/D', $value ) && (string) (int) $value === $value ? (int) $value : 0;
	if ( ! $id ) {
		wp_send_json_error( array( 'message' => __( 'Choose a valid template ID.', 'advanced-divi-forms' ) ), 400 );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact nonce verification; malformed tokens are not repaired.
	$nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? wp_unslash( $_POST['nonce'] ) : '';
	if ( ! wp_verify_nonce( $nonce, 'adf_preview_template_' . $id ) ) {
		wp_send_json_error( array( 'message' => __( 'The preview security token expired. Reload the editor.', 'advanced-divi-forms' ) ), 403 );
	}
	$post = get_post( $id );
	if ( ! $post || 'adf_mail_template' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
		wp_send_json_error( array( 'message' => __( 'The template is not available for preview.', 'advanced-divi-forms' ) ), 404 );
	}
	$input = array();
	foreach ( array( 'subject', 'html', 'fields_json' ) as $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Subject, HTML and test-data JSON must all be supplied as text.', 'advanced-divi-forms' ) ), 400 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Bounded content parser and renderer validate/sanitize without executing markup.
		$input[ $key ] = wp_unslash( $_POST[ $key ] );
	}
	$result = adf_build_template_preview( $input['subject'], $input['html'], $input['fields_json'] );
	if ( is_wp_error( $result ) ) {
		$report = $result->get_error_data();
		wp_send_json_error(
			array_merge(
				is_array( $report ) ? $report : array(),
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				)
			),
			400
		);
	}
	wp_send_json_success( $result );
}

/**
 * Parse a small object of field IDs and scalar/flat-multiselect test values.
 *
 * @param string $json Unslashed test-data JSON, never a filename or URL.
 * @return array|WP_Error Normalized synthetic fields, preserving literal text.
 */
function adf_parse_preview_fields( $json ) {
	if ( ! is_string( $json ) || strlen( $json ) > 50000 ) {
		return new WP_Error( 'adf_preview_data_limit', __( 'Use a test-data JSON object of at most 50,000 bytes.', 'advanced-divi-forms' ) );
	}
	$object = json_decode( $json, false, 8 );
	if ( JSON_ERROR_NONE !== json_last_error() || ! $object instanceof stdClass || count( get_object_vars( $object ) ) > 50 ) {
		return new WP_Error( 'adf_preview_invalid_json', __( 'Use a JSON object with at most 50 field IDs and plain-text test values or flat selection arrays.', 'advanced-divi-forms' ) );
	}
	$fields = array();
	$seen   = array();
	foreach ( get_object_vars( $object ) as $id => $value ) {
		$id = (string) $id;
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $id ) || isset( $seen[ strtolower( $id ) ] ) ) {
			return new WP_Error( 'adf_preview_invalid_field', __( 'Use distinct field IDs containing letters, digits, underscores or hyphens; IDs are matched case-insensitively.', 'advanced-divi-forms' ) );
		}
		$seen[ strtolower( $id ) ] = true;
		$values                    = is_array( $value ) ? $value : array( $value );
		if ( is_object( $value ) || count( $values ) > 50 ) {
			return new WP_Error( 'adf_preview_invalid_value', __( 'Test values must be text, numbers or a flat array of selections.', 'advanced-divi-forms' ) );
		}
		foreach ( $values as $part ) {
			if ( ( ! is_scalar( $part ) && null !== $part ) || strlen( (string) $part ) > 5000 ) {
				return new WP_Error( 'adf_preview_invalid_value', __( 'Use flat test values of at most 5,000 bytes each.', 'advanced-divi-forms' ) );
			}
		}
		$fields[ $id ] = adf_field_text( $value );
	}
	return $fields;
}

/**
 * Explain supported, missing and misplaced tokens without resolving real data.
 *
 * @param string $subject Unsaved subject.
 * @param string $html    Unsaved HTML.
 * @param array  $fields  Validated synthetic fields.
 * @return array Plain-text diagnostic lists.
 */
function adf_preview_placeholder_report( $subject, $html, array $fields ) {
	$known  = array_change_key_case( $fields, CASE_LOWER );
	$report = array(
		'missing_fields'         => array(),
		'unknown_placeholders'   => array(),
		'attribute_placeholders' => array(),
		'subject_body_only'      => str_contains( $subject, '{{form.summary}}' ),
	);
	preg_match_all( '/\{\{[^{}]*\}\}/', $subject . '\n' . $html, $tokens );
	foreach ( array_unique( $tokens[0] ) as $token ) {
		if ( preg_match( '/^\{\{fields\.([a-zA-Z0-9_-]+)\}\}$/D', $token, $parts ) ) {
			if ( ! array_key_exists( strtolower( $parts[1] ), $known ) ) {
				$report['missing_fields'][] = $token;
			}
		} elseif ( '{{form.summary}}' !== $token ) {
			$report['unknown_placeholders'][] = $token;
		}
	}
	$tags = new WP_HTML_Tag_Processor( $html );
	while ( $tags->next_tag() ) {
		foreach ( (array) $tags->get_attribute_names_with_prefix( '' ) as $name ) {
			$value = $tags->get_attribute( $name );
			if ( is_string( $value ) ) {
				preg_match_all( '/\{\{[^{}]*\}\}/', $value, $matches );
				$report['attribute_placeholders'] = array_merge( $report['attribute_placeholders'], $matches[0] );
			}
		}
	}
	$report['attribute_placeholders'] = array_values( array_unique( $report['attribute_placeholders'] ) );
	return $report;
}

/**
 * Offline-only iframe document: remove clickable/resource URLs as well as CSP.
 *
 * @param string $html Already rendered email HTML; never evaluated as code.
 * @return string Sandboxed srcdoc content, without link navigation or external assets.
 */
function adf_preview_document( $html ) {
	$tags = new WP_HTML_Tag_Processor( adf_sanitize_email_html( $html ) );
	while ( $tags->next_tag() ) {
		foreach ( array( 'href', 'xlink:href', 'src', 'srcset', 'poster', 'background', 'ping', 'action', 'formaction', 'target', 'download' ) as $attribute ) {
			$tags->remove_attribute( $attribute );
		}
		$style = $tags->get_attribute( 'style' );
		if ( is_string( $style ) && ( preg_match( '/url\s*\(|image-set|@import/i', $style ) || str_contains( $style, chr( 92 ) ) ) ) {
			$tags->remove_attribute( 'style' );
		}
	}
	return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; base-uri \'none\'; form-action \'none\'"></head><body>' . $tags->get_updated_html() . '</body></html>';
}

/**
 * Build an unsaved, synthetic-only preview without database writes or delivery.
 *
 * @param string $subject Unsaved subject, at most 1,000 bytes for a preview.
 * @param string $html    Unsaved HTML, at most 200,000 bytes for a preview.
 * @param string $json    Field test values as a small JSON object.
 * @return array|WP_Error Rendered subject, offline document and diagnostic lists.
 */
function adf_build_template_preview( $subject, $html, $json ) {
	if ( ! is_string( $subject ) || ! is_string( $html ) || strlen( $subject ) > 1000 || strlen( $html ) > 200000 ) {
		return new WP_Error( 'adf_preview_content_limit', __( 'Use a subject of at most 1,000 bytes and HTML of at most 200,000 bytes for preview.', 'advanced-divi-forms' ) );
	}
	$fields = adf_parse_preview_fields( $json );
	if ( is_wp_error( $fields ) ) {
		return $fields;
	}
	$budget       = strlen( $subject ) + strlen( $html );
	$summary_size = strlen( adf_render_form_summary( $fields ) );
	$known        = array_change_key_case( $fields, CASE_LOWER );
	preg_match_all( '/\{\{(?:fields\.([a-zA-Z0-9_-]+)|(form\.summary))\}\}/', $subject . '\n' . $html, $occurrences, PREG_SET_ORDER );
	foreach ( $occurrences as $token ) {
		$budget += ! empty( $token[2] ) ? $summary_size : strlen( esc_html( $known[ strtolower( $token[1] ?? '' ) ] ?? '' ) );
		if ( $budget > 1024 * 1024 ) {
			return new WP_Error( 'adf_preview_render_limit', __( 'The expanded preview is too large. Reduce repeated placeholders or test-data sizes.', 'advanced-divi-forms' ) );
		}
	}
	$report   = adf_preview_placeholder_report( $subject, $html, $fields );
	$rendered = adf_render_template_content( $subject, $html, $fields );
	if ( is_wp_error( $rendered ) ) {
		$rendered->add_data( $report );
		return $rendered;
	}
	$placeholders = array( '{{form.summary}}' );
	foreach ( $fields as $id => $value ) {
		$placeholders[] = '{{fields.' . $id . '}}';
	}
	return array_merge(
		$report,
		array(
			'subject'      => $rendered['subject'],
			'document'     => adf_preview_document( $rendered['html'] ),
			'placeholders' => $placeholders,
		)
	);
}
