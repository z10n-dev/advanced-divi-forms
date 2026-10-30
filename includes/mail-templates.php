<?php
/**
 * HTML mail templates, edited with the native WordPress post management UI.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'adf_register_mail_templates' );
add_action( 'add_meta_boxes_adf_mail_template', 'adf_add_template_meta_boxes' );
add_action( 'save_post_adf_mail_template', 'adf_save_template_fields' );
add_action( 'admin_enqueue_scripts', 'adf_enqueue_template_editor' );
add_filter( 'manage_adf_mail_template_posts_columns', 'adf_template_columns' );
add_action( 'manage_adf_mail_template_posts_custom_column', 'adf_render_template_column', 10, 2 );

/**
 * Include the stable template ID and subject in the native template list.
 *
 * @param array $columns Existing list columns.
 * @return array
 */
function adf_template_columns( $columns ) {
	$columns['adf_template_id'] = __( 'Template ID', 'advanced-divi-forms' );
	$columns['adf_subject']     = __( 'Subject', 'advanced-divi-forms' );
	return $columns;
}

/**
 * Render additional template columns without exposing raw HTML.
 *
 * @param string $column  Column key.
 * @param int    $post_id Template ID.
 * @return void
 */
function adf_render_template_column( $column, $post_id ) {
	if ( 'adf_template_id' === $column ) {
		echo esc_html( $post_id );
	} elseif ( 'adf_subject' === $column ) {
		echo esc_html( get_post_meta( $post_id, '_adf_subject', true ) );
	}
}

/**
 * Register an admin-only template library underneath Forms.
 *
 * @return void
 */
function adf_register_mail_templates() {
	register_post_type(
		'adf_mail_template',
		array_merge(
			adf_private_post_type_args(),
			array(
				'show_ui'      => true,
				'show_in_menu' => 'advanced-divi-forms',
				'labels'       => array(
					'name'          => __( 'Mail Templates', 'advanced-divi-forms' ),
					'singular_name' => __( 'Mail Template', 'advanced-divi-forms' ),
					'menu_name'     => __( 'Mail Templates', 'advanced-divi-forms' ),
					'add_new_item'  => __( 'Add Mail Template', 'advanced-divi-forms' ),
					'edit_item'     => __( 'Edit Mail Template', 'advanced-divi-forms' ),
					'new_item'      => __( 'New Mail Template', 'advanced-divi-forms' ),
					'search_items'  => __( 'Search Mail Templates', 'advanced-divi-forms' ),
					'not_found'     => __( 'No mail templates found.', 'advanced-divi-forms' ),
				),
			)
		)
	);
}

/**
 * Allow document wrappers as well as safe, inline-styled email markup.
 *
 * Scripts, iframes, event handlers, embedded forms and style blocks are excluded.
 * WordPress also filters unsafe CSS declarations and URL protocols.
 *
 * @param string $html Template HTML.
 * @return string
 */
function adf_sanitize_email_html( $html ) {
	// Strip dynamic tokens from their original tag context before KSES can repair
	// malformed/quoted markup into plain body text during a save or preview.
	$html             = adf_map_html_regions(
		$html,
		static function ( $text ) {
			return $text; },
		static function ( $markup ) {
			return preg_replace( '/\{\{(?:fields\.[a-zA-Z0-9_-]+|form\.summary)\}\}/', '', $markup );
		}
	);
	$html             = preg_replace( '@<(script|style)\b[^>]*>.*?</\1\s*>@si', '', $html );
	$allowed          = wp_kses_allowed_html( 'post' );
	$allowed['html']  = array(
		'lang' => true,
		'dir'  => true,
	);
	$allowed['head']  = array();
	$allowed['title'] = array();
	$allowed['body']  = array(
		'style' => true,
		'dir'   => true,
	);
	foreach ( array( 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ) as $tag ) {
		$allowed[ $tag ] = array_merge(
			$allowed[ $tag ] ?? array(),
			array_fill_keys( array( 'style', 'width', 'height', 'align', 'valign', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'role' ), true )
		);
	}
	foreach ( array( 'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta' ) as $tag ) {
		unset( $allowed[ $tag ] );
	}
	return wp_kses( $html, $allowed );
}

/**
 * Add the HTML editor and a sandboxed preview.
 *
 * @return void
 */
function adf_add_template_meta_boxes() {
	add_meta_box( 'adf-template-content', __( 'Email Content', 'advanced-divi-forms' ), 'adf_render_template_fields', 'adf_mail_template', 'normal', 'high' );
}

/**
 * Render template fields; preview only the saved, sanitized HTML.
 *
 * @param WP_Post $post Template post.
 * @return void
 */
function adf_render_template_fields( $post ) {
	adf_require_admin();
	if ( 'adf_mail_template' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
		wp_die( esc_html__( 'The template is not available.', 'advanced-divi-forms' ), '', array( 'response' => 404 ) );
	}
	$subject = get_post_meta( $post->ID, '_adf_subject', true );
	$html    = get_post_meta( $post->ID, '_adf_html', true );
	$preview = adf_preview_document( is_string( $html ) ? $html : '' );
	$sample  = array(
		'name'    => 'Example Name',
		'email'   => 'example@example.org',
		'message' => 'Example message',
	);
	$tokens  = array( '{{form.summary}}' );
	foreach ( array_keys( $sample ) as $id ) {
		$tokens[] = '{{fields.' . $id . '}}';
	}
	wp_nonce_field( 'adf_save_template', 'adf_template_nonce' );
	?>
	<p><strong><?php esc_html_e( 'Template ID:', 'advanced-divi-forms' ); ?></strong> <?php echo esc_html( $post->ID ); ?></p>
	<p><label for="adf-template-subject"><strong><?php esc_html_e( 'Subject', 'advanced-divi-forms' ); ?></strong></label>
		<input class="widefat" id="adf-template-subject" name="adf_subject" type="text" value="<?php echo esc_attr( $subject ); ?>"></p>
	<p><label for="adf-template-html"><strong><?php esc_html_e( 'HTML Content', 'advanced-divi-forms' ); ?></strong></label></p>
	<textarea class="widefat code" id="adf-template-html" name="adf_html" rows="20" aria-describedby="adf-template-help"><?php echo esc_textarea( $html ); ?></textarea>
	<p id="adf-template-help"><?php esc_html_e( 'Use inline CSS. Scripts, style blocks and unsafe HTML are removed. Placeholders use stable Divi field IDs in text content, never tag names or attributes. Missing fields become empty text. {{form.summary}} is HTML-body-only.', 'advanced-divi-forms' ); ?></p>
	<p><?php esc_html_e( 'Publish this template to select it in Divi. Saving and previewing do not send email. Preview test values are never stored as submissions.', 'advanced-divi-forms' ); ?></p>
	<section class="adf-template-preview" aria-labelledby="adf-preview-heading">
		<h2 id="adf-preview-heading"><?php esc_html_e( 'Test-data preview', 'advanced-divi-forms' ); ?></h2>
		<p><label for="adf-preview-fields"><strong><?php esc_html_e( 'Synthetic field values (JSON)', 'advanced-divi-forms' ); ?></strong></label></p>
		<textarea id="adf-preview-fields" name="adf_preview_fields" class="widefat code" rows="7" maxlength="50000" aria-describedby="adf-preview-help"><?php echo esc_textarea( wp_json_encode( $sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
		<p id="adf-preview-help"><?php esc_html_e( 'Use only invented test values. Match the keys to your Divi field IDs. This helper lists IDs from these test values, not from real answers. Up to 50 fields and 50,000 JSON bytes. External resources and link navigation are disabled in the preview.', 'advanced-divi-forms' ); ?></p>
		<p><label for="adf-insert-target"><?php esc_html_e( 'Insert placeholder into', 'advanced-divi-forms' ); ?></label>
			<select id="adf-insert-target"><option value="html"><?php esc_html_e( 'HTML body', 'advanced-divi-forms' ); ?></option><option value="subject"><?php esc_html_e( 'Subject', 'advanced-divi-forms' ); ?></option></select></p>
		<div id="adf-placeholder-buttons" class="adf-placeholder-buttons" aria-label="<?php esc_attr_e( 'Placeholders from test data', 'advanced-divi-forms' ); ?>">
			<?php foreach ( $tokens as $token ) : ?>
				<button type="button" class="button" data-adf-placeholder="<?php echo esc_attr( $token ); ?>" aria-controls="adf-template-html"><?php echo esc_html( $token ); ?></button>
			<?php endforeach; ?>
		</div>
		<p><button type="button" class="button button-secondary" id="adf-preview-run" data-template-id="<?php echo esc_attr( $post->ID ); ?>" data-preview-nonce="<?php echo esc_attr( wp_create_nonce( 'adf_preview_template_' . $post->ID ) ); ?>"><?php esc_html_e( 'Render test-data preview', 'advanced-divi-forms' ); ?></button></p>
		<p id="adf-preview-status" role="status" aria-live="polite"><?php esc_html_e( 'Showing saved HTML. Use the button to preview unsaved content with synthetic values; nothing is saved or sent.', 'advanced-divi-forms' ); ?></p>
		<ul id="adf-preview-diagnostics" aria-live="polite"></ul>
		<p><strong><?php esc_html_e( 'Rendered subject:', 'advanced-divi-forms' ); ?></strong> <span id="adf-preview-subject"><?php echo esc_html( $subject ); ?></span></p>
		<iframe id="adf-template-preview" title="<?php esc_attr_e( 'Email template preview', 'advanced-divi-forms' ); ?>" sandbox="" referrerpolicy="no-referrer" srcdoc="<?php echo esc_attr( $preview ); ?>"></iframe>
	</section>
	<?php
}

/**
 * Persist only an explicit, authorized editor submission.
 *
 * @param int $post_id Template ID.
 * @return void
 */
function adf_save_template_fields( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) || 'adf_mail_template' !== get_post_type( $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['adf_template_nonce'] ) || ! is_string( $_POST['adf_template_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['adf_template_nonce'] ) ), 'adf_save_template' ) ) {
		return;
	}
	if ( isset( $_POST['adf_subject'] ) && is_string( $_POST['adf_subject'] ) ) {
		update_post_meta( $post_id, '_adf_subject', wp_slash( sanitize_text_field( wp_unslash( $_POST['adf_subject'] ) ) ) );
	}
	if ( isset( $_POST['adf_html'] ) && is_string( $_POST['adf_html'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by adf_sanitize_email_html(), including WordPress KSES and safe CSS.
		update_post_meta( $post_id, '_adf_html', wp_slash( adf_sanitize_email_html( wp_unslash( $_POST['adf_html'] ) ) ) );
	}
}

/**
 * Enable the WordPress HTML code editor on template editing screens only.
 *
 * @return void
 */
function adf_enqueue_template_editor() {
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->base || 'adf_mail_template' !== $screen->post_type || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
	wp_enqueue_script( 'adf-template-preview', plugins_url( 'assets/template-preview.js', ADF_PLUGIN_FILE ), array( 'jquery', 'code-editor' ), ADF_PLUGIN_VERSION, true );
	wp_enqueue_style( 'adf-template-preview', plugins_url( 'assets/template-preview.css', ADF_PLUGIN_FILE ), array(), ADF_PLUGIN_VERSION );
	wp_localize_script(
		'adf-template-preview',
		'adfTemplatePreviewSettings',
		array(
			'codeEditor' => $settings,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'strings'    => array(
				'working'    => __( 'Rendering synthetic values without saving or sending…', 'advanced-divi-forms' ),
				'done'       => __( 'Preview rendered. Nothing was saved and no email was sent.', 'advanced-divi-forms' ),
				'failed'     => __( 'Preview failed. Check the test data and try again.', 'advanced-divi-forms' ),
				'missing'    => __( 'Missing test-data fields:', 'advanced-divi-forms' ),
				'unknown'    => __( 'Unsupported placeholders:', 'advanced-divi-forms' ),
				'attributes' => __( 'Not expanded in HTML attributes:', 'advanced-divi-forms' ),
				'bodyOnly'   => __( '{{form.summary}} is body-only and is not rendered in the subject.', 'advanced-divi-forms' ),
			),
		)
	);
}

/**
 * Render submitted fields as an email-safe table, in submission order.
 *
 * @param array $fields Submitted values keyed by stable field IDs.
 * @param array $labels Optional labels keyed by stable field IDs.
 * @return string
 */
function adf_render_form_summary( array $fields, array $labels = array() ) {
	if ( ! $fields ) {
		return '';
	}
	$html = '<table style="width:100%;border-collapse:collapse;" cellpadding="8" cellspacing="0" border="1"><thead><tr><th align="left">' . esc_html__( 'Field', 'advanced-divi-forms' ) . '</th><th align="left">' . esc_html__( 'Value', 'advanced-divi-forms' ) . '</th></tr></thead><tbody>';
	foreach ( $fields as $key => $value ) {
		$label = adf_field_text( $labels[ $key ] ?? '' );
		$label = '' !== trim( $label ) ? $label : (string) $key;
		$html .= '<tr><th align="left" valign="top">' . esc_html( $label ) . '</th><td valign="top">' . nl2br( esc_html( adf_field_text( $value ) ) ) . '</td></tr>';
	}
	return $html . '</tbody></table>';
}

/**
 * Render an active template without sending mail.
 *
 * @param int   $template_id Published template ID.
 * @param array $fields      Submitted values keyed by stable field IDs.
 * @param array $labels      Optional display labels keyed by stable field IDs.
 * @return array|WP_Error Subject and HTML, or an invalid-template error.
 */
function adf_render_mail_template( $template_id, array $fields, array $labels = array() ) {
	$template = $template_id ? get_post( absint( $template_id ) ) : null;
	if ( ! $template || 'adf_mail_template' !== $template->post_type || 'publish' !== $template->post_status ) {
		return new WP_Error( 'adf_invalid_template', __( 'The mail template is not available.', 'advanced-divi-forms' ) );
	}
	return adf_render_template_content( get_post_meta( $template_id, '_adf_subject', true ), get_post_meta( $template_id, '_adf_html', true ), $fields, $labels );
}

/**
 * Transform body text separately from complete, duplicate or unfinished markup.
 *
 * This is a conservative lexical boundary scanner, not an HTML evaluator. Quoted
 * greater-than signs never end a tag; unfinished tags/comments consume the tail
 * as markup, so no field value can be expanded there. Replacement output is not
 * scanned again, preserving non-recursive field/summary rendering.
 *
 * @param string   $html   Input HTML.
 * @param callable $text   Plain body-text transformer.
 * @param callable $markup Tag/comment transformer, including incomplete tails.
 * @return string
 */
function adf_map_html_regions( $html, $text, $markup ) {
	$result   = '';
	$cursor   = 0;
	$position = 0;
	$length   = strlen( $html );
	while ( $position < $length ) {
		$start = strpos( $html, '<', $position );
		if ( false === $start ) {
			break;
		}
		if ( $start + 1 >= $length || ! preg_match( '/[a-zA-Z!\/?{]/', $html[ $start + 1 ] ) ) {
			$position = $start + 1;
			continue;
		}
		$result .= $text( substr( $html, $cursor, $start - $cursor ) );
		if ( '<!--' === substr( $html, $start, 4 ) ) {
			$close = strpos( $html, '-->', $start + 4 );
			$end   = false === $close ? $length : $close + 3;
		} else {
			$quote = null;
			for ( $end = $start + 1; $end < $length; ++$end ) {
				$character = $html[ $end ];
				if ( null !== $quote ) {
					if ( $character === $quote ) {
						$quote = null; }
				} elseif ( '"' === $character || "'" === $character ) {
					$quote = $character;
				} elseif ( '>' === $character ) {
					++$end;
					break;
				}
			}
		}
		$result  .= $markup( substr( $html, $start, $end - $start ) );
		$cursor   = $end;
		$position = $end;
	}
	return $result . $text( substr( $html, $cursor ) );
}

/**
 * Render supplied content without reading a template, storing data or sending mail.
 *
 * @param string $subject Unsaved or saved template subject.
 * @param string $html    Unsaved or saved HTML body.
 * @param array  $fields  Synthetic or validated submitted values.
 * @param array  $labels  Optional field labels.
 * @return array|WP_Error Rendered content or a controlled content error.
 */
function adf_render_template_content( $subject, $html, array $fields, array $labels = array() ) {
	if ( ! is_string( $subject ) || ! is_string( $html ) ) {
		return new WP_Error( 'adf_invalid_template_content', __( 'Subject and HTML must be text.', 'advanced-divi-forms' ) );
	}
	$labels  = array_change_key_case( $labels, CASE_LOWER );
	$fields  = array_change_key_case( $fields, CASE_LOWER );
	$replace = static function ( $matches ) use ( $fields ) {
		return adf_field_text( $fields[ strtolower( $matches[1] ?? '' ) ] ?? '' );
	};
	$pattern = '/\{\{(?:fields\.([a-zA-Z0-9_-]+)|(form\.summary))\}\}/';
	$subject = preg_replace_callback( $pattern, $replace, $subject );
	$html    = adf_map_html_regions(
		$html,
		static function ( $text ) use ( $pattern, $replace, $fields, $labels ) {
			return preg_replace_callback(
				$pattern,
				static function ( $matches ) use ( $replace, $fields, $labels ) {
					return ! empty( $matches[2] ) ? adf_render_form_summary( $fields, $labels ) : esc_html( $replace( $matches ) );
				},
				$text
			);
		},
		static function ( $markup ) use ( $pattern ) {
			return preg_replace( $pattern, '', $markup );
		}
	);

	$subject = sanitize_text_field( $subject );
	$html    = adf_sanitize_email_html( $html );
	if ( '' === trim( $subject ) || '' === trim( $html ) ) {
		return new WP_Error( 'adf_empty_template', __( 'The mail template needs a subject and HTML content.', 'advanced-divi-forms' ) );
	}
	return array(
		'subject' => $subject,
		'html'    => $html,
	);
}
