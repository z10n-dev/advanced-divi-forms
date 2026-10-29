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
	$subject = get_post_meta( $post->ID, '_adf_subject', true );
	$html    = get_post_meta( $post->ID, '_adf_html', true );
	// No scripts, network requests, forms or navigation to the parent are allowed.
	$preview = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; base-uri \'none\'; form-action \'none\'">' . adf_sanitize_email_html( $html );
	wp_nonce_field( 'adf_save_template', 'adf_template_nonce' );
	?>
	<p><strong><?php esc_html_e( 'Template ID:', 'advanced-divi-forms' ); ?></strong> <?php echo esc_html( $post->ID ); ?></p>
	<p>
		<label for="adf-template-subject"><strong><?php esc_html_e( 'Subject', 'advanced-divi-forms' ); ?></strong></label>
		<input class="widefat" id="adf-template-subject" name="adf_subject" type="text" value="<?php echo esc_attr( $subject ); ?>">
	</p>
	<p><label for="adf-template-html"><strong><?php esc_html_e( 'HTML Content', 'advanced-divi-forms' ); ?></strong></label></p>
	<textarea class="widefat code" id="adf-template-html" name="adf_html" rows="20" aria-describedby="adf-template-help"><?php echo esc_textarea( $html ); ?></textarea>
	<p id="adf-template-help">
		<?php esc_html_e( 'Use inline CSS. Scripts, style blocks and unsafe HTML are removed when saving. Placeholders such as {{fields.name}} use stable field IDs and belong in text content, not HTML attributes. Missing fields become empty text. Insert {{form.summary}} on its own line in the HTML body for a table of all submitted fields.', 'advanced-divi-forms' ); ?>
	</p>
	<p><?php esc_html_e( 'Publish this template, then select it in the Divi 5 Contact Form Email settings. Saving a template does not send email. Reload the Visual Builder after adding or publishing templates.', 'advanced-divi-forms' ); ?></p>
	<h2><?php esc_html_e( 'Saved Preview', 'advanced-divi-forms' ); ?></h2>
	<p><?php esc_html_e( 'Save your changes to refresh the preview. External images are blocked in the preview for privacy.', 'advanced-divi-forms' ); ?></p>
	<iframe title="<?php esc_attr_e( 'Email template preview', 'advanced-divi-forms' ); ?>" sandbox="" referrerpolicy="no-referrer" srcdoc="<?php echo esc_attr( $preview ); ?>" style="width:100%; min-height:350px; border:1px solid #c3c4c7; background:white;"></iframe>
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
	if ( ! $screen || 'post' !== $screen->base || 'adf_mail_template' !== $screen->post_type ) {
		return;
	}
	$settings = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
	if ( $settings ) {
		wp_add_inline_script( 'code-editor', 'jQuery(function(){wp.codeEditor.initialize("adf-template-html",' . wp_json_encode( $settings ) . ');});' );
	}
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
	$labels  = array_change_key_case( $labels, CASE_LOWER );
	$fields  = array_change_key_case( $fields, CASE_LOWER );
	$replace = static function ( $matches ) use ( $fields ) {
		return adf_field_text( $fields[ strtolower( $matches[1] ?? '' ) ] ?? '' );
	};
	$pattern = '/\{\{(?:fields\.([a-zA-Z0-9_-]+)|(form\.summary))\}\}/';
	$subject = preg_replace_callback( $pattern, $replace, get_post_meta( $template_id, '_adf_subject', true ) );
	$html    = get_post_meta( $template_id, '_adf_html', true );
	// Never substitute values into tag names or attribute contexts.
	$html = preg_replace_callback(
		'/<[^>]*>/s',
		static function ( $matches ) use ( $pattern ) {
			return preg_replace( $pattern, '', $matches[0] );
		},
		$html
	);
	$html = preg_replace_callback(
		$pattern,
		static function ( $matches ) use ( $replace, $fields, $labels ) {
			return ! empty( $matches[2] ) ? adf_render_form_summary( $fields, $labels ) : esc_html( $replace( $matches ) );
		},
		$html
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
