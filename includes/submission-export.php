<?php
/**
 * Private, administrator-only XLSX downloads without runtime dependencies.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_adf_export_submissions', 'adf_export_submissions' );

/**
 * Encode a literal spreadsheet cell. Never create formulas or hyperlinks.
 *
 * @param string $value Cell value.
 * @return string|WP_Error Inline string cell XML or a size error.
 */
function adf_xlsx_cell( $value ) {
	$value = wp_check_invalid_utf8( (string) $value, true );
	$value = preg_replace( '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
	// Astral characters occupy two UTF-16 code units in Excel.
	$units = preg_match_all( '/./us', $value ) + preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $value );
	if ( $units > 32767 ) {
		return new WP_Error( 'adf_export_cell_size', __( 'A value exceeds Excel’s 32,767-character cell limit. The export was not truncated.', 'advanced-divi-forms' ) );
	}
	return '<c t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</t></is></c>';
}

/**
 * Create the XLSX package using ZipArchive or WordPress's bundled PclZip.
 *
 * @param string $sheet       Worksheet XML.
 * @param bool   $use_native  Whether to prefer ZipArchive when available.
 * @return string|WP_Error Private temporary file path or an error.
 */
function adf_package_submission_xlsx( $sheet, $use_native = true ) {
	$directory = realpath( sys_get_temp_dir() );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Local private temporary file creation; WP_Filesystem may require interactive credentials.
	if ( ! $directory || ! is_writable( $directory ) ) {
		return new WP_Error( 'adf_export_temp', __( 'A private writable system temporary directory is required for Excel exports.', 'advanced-divi-forms' ) );
	}
	$roots = array( ABSPATH, WP_CONTENT_DIR );
	if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Used only to reject web-accessible temporary directories, never as a file destination.
		$roots[] = wp_unslash( $_SERVER['DOCUMENT_ROOT'] );
	}
	foreach ( $roots as $root ) {
		$root = realpath( $root );
		if ( $root && ( $directory === $root || str_starts_with( $directory . '/', rtrim( $root, '/' ) . '/' ) ) ) {
			return new WP_Error( 'adf_export_temp', __( 'The system temporary directory must be outside the website’s public directories.', 'advanced-divi-forms' ) );
		}
	}
	$file = wp_tempnam( 'adf-private-export.xlsx', trailingslashit( $directory ) );
	if ( ! $file || realpath( dirname( $file ) ) !== $directory ) {
		if ( $file ) {
			wp_delete_file( $file );
		}
		return new WP_Error( 'adf_export_temp', __( 'A private temporary export file could not be created.', 'advanced-divi-forms' ) );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrict the private local temporary file before placing submission data in it.
	if ( ! chmod( $file, 0600 ) ) {
		wp_delete_file( $file );
		return new WP_Error( 'adf_export_temp', __( 'Private permissions could not be set on the export file.', 'advanced-divi-forms' ) );
	}
	$parts   = array(
		'[Content_Types].xml'        => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
		'_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
		'xl/workbook.xml'            => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Submissions" sheetId="1" r:id="rId1"/></sheets></workbook>',
		'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
		'xl/worksheets/sheet1.xml'   => $sheet,
	);
	$success = false;
	if ( $use_native && class_exists( 'ZipArchive' ) ) {
		$zip = new ZipArchive();
		if ( true === $zip->open( $file, ZipArchive::OVERWRITE ) ) {
			$success = true;
			foreach ( $parts as $name => $content ) {
				$success = $zip->addFromString( $name, $content ) && $success;
			}
			$success = $zip->close() && $success;
		}
	} else {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$files = array();
		foreach ( $parts as $name => $content ) {
			$files[] = array(
				PCLZIP_ATT_FILE_NAME    => $name,
				PCLZIP_ATT_FILE_CONTENT => $content,
			);
		}
		$zip     = new PclZip( $file );
		$success = 0 !== $zip->create( $files );
	}
	if ( ! $success ) {
		wp_delete_file( $file );
		return new WP_Error( 'adf_export_zip', __( 'The Excel file could not be created.', 'advanced-divi-forms' ) );
	}
	// Keep an in-request allowlist: the download function may never stream arbitrary paths.
	$GLOBALS['adf_private_exports'][ $file ] = true;
	register_shutdown_function( 'wp_delete_file', $file );
	return $file;
}

/**
 * Export one form, including all pages or an explicitly selected subset.
 *
 * @param int   $form_id Form ID.
 * @param array $ids     Empty means all. Controllers must reject empty selections.
 * @param array $filters Filters for all/filtered exports; selections retain only sorting.
 * @return string|WP_Error Private XLSX temporary path or a controlled error.
 */
function adf_create_submission_export( $form_id, array $ids = array(), array $filters = array() ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'adf_export_forbidden', __( 'You are not allowed to export submissions.', 'advanced-divi-forms' ) );
	}
	$form = $form_id ? get_post( $form_id ) : null;
	if ( ! $form || 'adf_form' !== $form->post_type || 'private' !== $form->post_status ) {
		return new WP_Error( 'adf_export_form', __( 'The form does not exist.', 'advanced-divi-forms' ) );
	}
	$filters = adf_parse_submission_filters( $filters );
	if ( is_wp_error( $filters ) ) {
		return $filters;
	}
	$required_status = 'trash' === $filters['adf_view'] ? 'trash' : 'private';
	if ( count( $ids ) > 10000 ) {
		return new WP_Error( 'adf_export_limit', __( 'Export at most 10,000 submissions at a time. Nothing has been truncated.', 'advanced-divi-forms' ) );
	}
	$selected = array();
	foreach ( $ids as $id ) {
		if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id ) || (string) (int) $id !== (string) $id ) {
			return new WP_Error( 'adf_export_selection', __( 'The export selection is invalid.', 'advanced-divi-forms' ) );
		}
		$post = get_post( $id );
		if ( ! $post || 'adf_submission' !== $post->post_type || $post->post_status !== $required_status || (int) $post->post_parent !== (int) $form_id ) {
			return new WP_Error( 'adf_export_selection', __( 'Every selected submission must belong to this form.', 'advanced-divi-forms' ) );
		}
		$selected[ (int) $id ] = (int) $id;
	}
	// Explicit selections are not narrowed by a stale list filter after an edit.
	$args                   = adf_submission_query_args(
		$form_id,
		$selected ? array(
			'adf_order' => $filters['adf_order'],
			'adf_view'  => $filters['adf_view'],
		) : $filters
	);
	$args['posts_per_page'] = 10001; // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Detect overflow instead of truncating the export.
	$args['fields']         = 'ids';
	$args['no_found_rows']  = true;
	if ( $selected ) {
		$args['post__in'] = array_values( $selected );
	}
	$query = new WP_Query( $args );
	if ( count( $query->posts ) > 10000 ) {
		return new WP_Error( 'adf_export_limit', __( 'Export at most 10,000 submissions at a time. Nothing has been truncated.', 'advanced-divi-forms' ) );
	}
	$keys       = array();
	$has_labels = false;
	foreach ( $query->posts as $id ) {
		$snapshot   = get_post_meta( $id, '_adf_field_labels', true );
		$has_labels = $has_labels || ( is_array( $snapshot ) && ! empty( $snapshot ) );
		foreach ( (array) get_post_meta( $id, '_adf_fields', true ) as $key => $unused ) {
			$keys[ $key ] = true;
		}
	}
	$column_count = 4 + count( $keys ) * ( $has_labels ? 2 : 1 );
	if ( $column_count > 16384 || $column_count * ( count( $query->posts ) + 1 ) > 250000 ) {
		return new WP_Error( 'adf_export_limit', __( 'The export exceeds the column or 250,000-cell safety limit. Export fewer submissions; nothing has been truncated.', 'advanced-divi-forms' ) );
	}
	$headers = array_merge( array( __( 'Submission ID', 'advanced-divi-forms' ), __( 'Received', 'advanced-divi-forms' ), __( 'Status', 'advanced-divi-forms' ), __( 'Internal note', 'advanced-divi-forms' ) ), array_map( 'strval', array_keys( $keys ) ) );
	if ( $has_labels ) {
		foreach ( $keys as $key => $unused ) {
			$headers[] = sprintf( __( 'Field label [%s]', 'advanced-divi-forms' ), $key ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- Stable field ID, distinct even when human labels repeat.
		}
	}
	$xml    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
	$number = 0;
	// Write one header row followed by one row per answer, using the field union.
	foreach ( array_merge( array( 0 ), $query->posts ) as $id ) {
		if ( 0 === $id ) {
			$values = $headers;
		} else {
			$fields = (array) get_post_meta( $id, '_adf_fields', true );
			$values = array( (string) $id, get_the_date( 'Y-m-d H:i:s', $id ), get_post_meta( $id, '_adf_read', true ) ? __( 'Read', 'advanced-divi-forms' ) : __( 'New', 'advanced-divi-forms' ), adf_field_text( get_post_meta( $id, '_adf_note', true ) ) );
			foreach ( $keys as $key => $unused ) {
				$values[] = adf_field_text( $fields[ $key ] ?? '' );
			}
			if ( $has_labels ) {
				$labels = adf_get_submission_labels( $id );
				foreach ( $keys as $key => $unused ) {
					$values[] = array_key_exists( $key, $fields ) ? ( $labels[ $key ] ?? (string) $key ) : '';
				}
			}
		}
		$xml .= '<row r="' . ( ++$number ) . '">';
		foreach ( $values as $value ) {
			$cell = adf_xlsx_cell( $value );
			if ( is_wp_error( $cell ) ) {
				return $cell;
			}
			$xml .= $cell;
			if ( strlen( $xml ) > 8 * 1024 * 1024 ) {
				return new WP_Error( 'adf_export_limit', __( 'The export exceeds the 8 MiB worksheet safety limit. Export fewer submissions; nothing has been truncated.', 'advanced-divi-forms' ) );
			}
		}
		$xml .= '</row>';
	}
	$column = '';
	for ( $n = count( $headers ); $n > 0; $n = (int) floor( ( $n - 1 ) / 26 ) ) {
		$column = chr( 65 + ( ( $n - 1 ) % 26 ) ) . $column;
	}
	$xml .= '</sheetData><autoFilter ref="A1:' . $column . $number . '"/></worksheet>';
	return adf_package_submission_xlsx( $xml );
}

/**
 * Stream only an in-request generated private file, then remove it.
 *
 * @param string $file    Generated export file.
 * @param int    $form_id Form ID for the download name.
 * @return void
 */
function adf_send_submission_export( $file, $form_id ) {
	adf_require_admin();
	if ( empty( $GLOBALS['adf_private_exports'][ $file ] ) || ! is_file( $file ) ) {
		wp_die( esc_html__( 'The export file is not available.', 'advanced-divi-forms' ), '', array( 'response' => 400 ) );
	}
	register_shutdown_function( 'wp_delete_file', $file );
	if ( headers_sent() ) {
		wp_delete_file( $file );
		wp_die( esc_html__( 'The download could not start because output was already sent.', 'advanced-divi-forms' ), '', array( 'response' => 500 ) );
	}
	nocache_headers();
	header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
	header( 'Content-Disposition: attachment; filename="submissions-' . absint( $form_id ) . '.xlsx"' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Length: ' . filesize( $file ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Generated private temporary file, allowlisted above; stream the download without WP_Filesystem buffering.
	readfile( $file );
	wp_delete_file( $file );
	unset( $GLOBALS['adf_private_exports'][ $file ] );
	exit;
}

/**
 * Authorize a dedicated export-all or explicit export-selection POST request.
 *
 * @return void
 */
function adf_export_submissions() {
	adf_require_admin();
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'post' !== sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
		wp_die( esc_html__( 'Use a POST request to export submissions.', 'advanced-divi-forms' ), '', array( 'response' => 405 ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict numeric validation before the form-scoped nonce check.
	$form_id = isset( $_POST['form_id'] ) && is_string( $_POST['form_id'] ) && preg_match( '/^[1-9][0-9]*$/D', wp_unslash( $_POST['form_id'] ) ) ? (int) $_POST['form_id'] : 0;
	check_admin_referer( 'adf_export_submissions_' . $form_id );
	$has_ids = array_key_exists( 'submission_ids', $_POST );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only exact allowlisted scopes are accepted below; missing scope preserves legacy callers.
	$scope = array_key_exists( 'scope', $_POST ) ? wp_unslash( $_POST['scope'] ) : ( $has_ids ? 'selected' : 'all' );
	if ( ! is_string( $scope ) || ! in_array( $scope, array( 'selected', 'filtered', 'all' ), true ) || ( 'selected' !== $scope && $has_ids ) ) {
		wp_die( esc_html__( 'Choose an unambiguous selected, filtered or all-submissions export.', 'advanced-divi-forms' ), '', array( 'response' => 400 ) );
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The parser validates each known filter and ignores other query arguments.
	$filters = adf_parse_submission_filters( wp_unslash( $_POST ) );
	if ( is_wp_error( $filters ) ) {
		wp_die( esc_html( $filters->get_error_message() ), '', array( 'response' => 400 ) );
	}
	$ids = array();
	if ( 'selected' === $scope ) {
		if ( ! $has_ids || ! is_array( $_POST['submission_ids'] ) || 0 === count( $_POST['submission_ids'] ) ) {
			wp_die( esc_html__( 'Select at least one submission to export.', 'advanced-divi-forms' ), '', array( 'response' => 400 ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The export API validates every ID and its form before querying.
		$ids = wp_unslash( $_POST['submission_ids'] );
	}
	$query_filters = 'filtered' === $scope ? $filters : ( 'selected' === $scope ? array(
		'adf_order' => $filters['adf_order'],
		'adf_view'  => $filters['adf_view'],
	) : array() );
	$file          = adf_create_submission_export( $form_id, $ids, $query_filters );
	if ( is_wp_error( $file ) ) {
		wp_die(
			esc_html( $file->get_error_message() ),
			'',
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}
	adf_send_submission_export( $file, $form_id );
}
