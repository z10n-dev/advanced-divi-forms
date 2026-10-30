<?php
/**
 * Contact Form integration verified against Divi 5.16.0.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'block_type_metadata', 'adf_divi_block_metadata' );
add_filter( 'divi_module_library_register_module_attrs', 'adf_divi_runtime_attrs', 20, 2 );
add_action( 'divi_visual_builder_assets_after_enqueue_scripts', 'adf_enqueue_divi_settings' );
add_action( 'wp_after_insert_post', 'adf_sync_divi_post', 20, 2 );
add_action( 'et_pb_contact_form_submit', 'adf_divi_form_submitted', PHP_INT_MAX, 3 );
add_filter( 'wp_mail', 'adf_divi_mail_args', PHP_INT_MAX );
add_filter( 'pre_wp_mail', 'adf_divi_pre_mail', PHP_INT_MAX );
add_action( 'wp_mail_succeeded', 'adf_divi_mail_succeeded' );
add_action( 'wp_mail_failed', 'adf_divi_mail_failed' );
add_action( 'admin_post_adf_discover_forms', 'adf_discover_divi_forms' );

/**
 * Content editors may select published templates, not edit their HTML or answers.
 *
 * @return bool
 */
function adf_can_use_divi_settings() {
	return current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' );
}

/**
 * Generate the same settings schema for PHP and the Visual Builder.
 *
 * @return array
 */
function adf_divi_attribute_schema() {
	$options = array( '0' => array( 'label' => __( 'Default / Disabled', 'advanced-divi-forms' ) ) );
	if ( adf_can_use_divi_settings() ) {
		$templates = get_posts(
			array(
				'post_type'      => 'adf_mail_template',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $templates as $template ) {
			$options[ (string) $template->ID ] = array( 'label' => $template->post_title . ' (#' . $template->ID . ')' );
		}
	}
	$definitions = array(
		'notificationTemplate'   => array(
			'label'       => __( 'Notification Template', 'advanced-divi-forms' ),
			'description' => __( 'Replace the existing recipient email with a published HTML template. Default keeps Divi delivery unchanged.', 'advanced-divi-forms' ),
			'component'   => 'divi/select',
			'props'       => array( 'options' => (object) $options ),
		),
		'confirmationTemplate'   => array(
			'label'       => __( 'Sender Confirmation Template', 'advanced-divi-forms' ),
			'description' => __( 'Optional confirmation, sent only after the recipient email succeeds. Disabled by default.', 'advanced-divi-forms' ),
			'component'   => 'divi/select',
			'props'       => array( 'options' => (object) $options ),
		),
		'confirmationEmailField' => array(
			'label'       => __( 'Sender Email Field ID', 'advanced-divi-forms' ),
			'description' => __( 'Stable ID of an Email-type field. Leave blank for email. A valid Email field is required for confirmations.', 'advanced-divi-forms' ),
			'component'   => 'divi/text',
			'props'       => array(),
		),
		'storeSubmissions'       => array(
			'label'       => __( 'Save Submissions', 'advanced-divi-forms' ),
			'description' => __( 'Off by default. Enable only after defining your consent and retention policy. Validated field values will be stored in Forms.', 'advanced-divi-forms' ),
			'component'   => 'divi/toggle',
			'props'       => array(
				'options' => array(
					'off' => __( 'No', 'advanced-divi-forms' ),
					'on'  => __( 'Yes', 'advanced-divi-forms' ),
				),
			),
		),
	);
	$advanced    = array();
	$priority    = 20;
	foreach ( $definitions as $key => $definition ) {
		$advanced[ $key ] = array(
			'groupType' => 'group-item',
			'item'      => array(
				'groupSlug'   => 'contentEmail',
				'priority'    => $priority,
				'render'      => true,
				'attrName'    => 'adf.advanced.' . $key,
				'label'       => $definition['label'],
				'description' => $definition['description'],
				'category'    => 'configuration',
				'features'    => array(
					'responsive' => false,
					'hover'      => false,
					'sticky'     => false,
					'preset'     => 'content',
				),
				'component'   => array(
					'type'  => 'field',
					'name'  => $definition['component'],
					'props' => $definition['props'],
				),
			),
		);
		$priority        += 5;
	}
	return array(
		'type'     => 'object',
		'settings' => array( 'advanced' => $advanced ),
	);
}

/**
 * Register our persisted attribute without modifying Divi files.
 *
 * @param array $metadata Block metadata.
 * @return array
 */
function adf_divi_block_metadata( $metadata ) {
	if ( 'divi/contact-form' === ( $metadata['name'] ?? '' ) ) {
		$metadata['attributes']['adf'] = adf_divi_attribute_schema();
	}
	return $metadata;
}

/**
 * Install the verified module-attribute filter before module registration runs.
 *
 * @return void
 */
function adf_enqueue_divi_settings() {
	if ( ! adf_can_use_divi_settings() || ! wp_script_is( 'divi-module-library', 'registered' ) ) {
		return;
	}
	wp_enqueue_script( 'adf-divi-settings', plugins_url( 'assets/divi-settings.js', ADF_PLUGIN_FILE ), array( 'divi-vendor-wp-hooks' ), ADF_PLUGIN_VERSION, true );
	wp_localize_script( 'adf-divi-settings', 'adfDiviSettings', array( 'attribute' => adf_divi_attribute_schema() ) );
	$scripts = wp_scripts();
	if ( ! in_array( 'adf-divi-settings', $scripts->registered['divi-module-library']->deps, true ) ) {
		$scripts->registered['divi-module-library']->deps[] = 'adf-divi-settings';
	}
}

/**
 * Read one desktop-only configuration value from a module.
 *
 * @param array  $attrs Module attributes.
 * @param string $key   Setting name.
 * @param string $fallback Default value.
 * @return string
 */
function adf_divi_setting( array $attrs, $key, $fallback = '' ) {
	$value = $attrs['adf']['advanced'][ $key ]['desktop']['value'] ?? $fallback;
	return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $fallback;
}

/**
 * Prefer a non-empty admin label, then the visible title, then a safe default.
 *
 * @param array $attrs Module attributes.
 * @return string
 */
function adf_divi_form_name( array $attrs ) {
	foreach ( array( $attrs['module']['meta']['adminLabel']['desktop']['value'] ?? '', $attrs['title']['innerContent']['desktop']['value'] ?? '' ) as $name ) {
		$name = is_string( $name ) ? sanitize_text_field( $name ) : '';
		if ( '' !== $name ) {
			return $name;
		}
	}
	return __( 'Contact Form', 'advanced-divi-forms' );
}

/**
 * Register a module using page ID plus persistent UUID, never its order index.
 *
 * @param int    $page_id Source page ID.
 * @param string $uuid    Persistent module ID.
 * @param array  $attrs   Module attributes.
 * @return int|WP_Error
 */
function adf_register_divi_form( $page_id, $uuid, array $attrs ) {
	if ( ! $page_id || '' === $uuid ) {
		return new WP_Error( 'adf_divi_identity', __( 'The Divi form has no stable identity.', 'advanced-divi-forms' ) );
	}
	$id = adf_register_form( 'divi5:' . absint( $page_id ) . ':' . $uuid, adf_divi_form_name( $attrs ), $page_id );
	if ( ! is_wp_error( $id ) ) {
		update_post_meta( $id, '_adf_divi_uuid', sanitize_text_field( $uuid ) );
	}
	return $id;
}

/**
 * Read saved Divi form definitions without executing shortcodes or rendering.
 *
 * Divi 5 can still contain legacy shortcode content awaiting migration.
 * A missing UUID is reported, never replaced with an unstable positional ID.
 *
 * @param string $content Saved source content.
 * @return array
 */
function adf_extract_divi_forms( $content ) {
	$forms = array();
	if ( str_contains( $content, 'wp:divi/contact-form' ) ) {
		$parser = new WP_Block_Parser();
		$walk   = static function ( $blocks ) use ( &$walk, &$forms ) {
			foreach ( $blocks as $block ) {
				if ( 'divi/contact-form' === $block['blockName'] ) {
					$attrs   = $block['attrs'];
					$uuid    = $attrs['module']['advanced']['uniqueId']['desktop']['value'] ?? '';
					$forms[] = array(
						'attrs'  => $attrs,
						'uuid'   => is_string( $uuid ) ? trim( $uuid ) : '',
						'format' => 'block',
					);
				}
				$walk( $block['innerBlocks'] );
			}
		};
		$walk( $parser->parse( $content ) );
	}
	if ( str_contains( $content, '[et_pb_contact_form' ) ) {
		preg_match_all( '/' . get_shortcode_regex( array( 'et_pb_contact_form' ) ) . '/s', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue; // Escaped shortcode examples are not forms.
			}
			$legacy = shortcode_parse_atts( $match[3] );
			$legacy = is_array( $legacy ) ? $legacy : array();
			$uuid   = $legacy['_unique_id'] ?? '';

			$forms[] = array(
				'attrs'  => array(
					'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => $legacy['admin_label'] ?? '' ) ) ) ),
					'title'  => array( 'innerContent' => array( 'desktop' => array( 'value' => $legacy['title'] ?? '' ) ) ),
				),
				'uuid'   => is_string( $uuid ) ? trim( $uuid ) : '',
				'format' => 'shortcode',
			);
		}
	}
	return $forms;
}

/**
 * Index saved forms and return non-sensitive discovery diagnostics.
 *
 * @param int     $post_id Source post ID.
 * @param WP_Post $post    Saved post.
 * @return array Counts and issues, without form fields or page content.
 */
function adf_sync_divi_post( $post_id, $post ) {
	$result = array(
		'found'   => 0,
		'indexed' => 0,
		'issues'  => array(),
	);
	if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! adf_divi_source_type( $post->post_type ) || 'trash' === $post->post_status ) {
		return $result;
	}
	foreach ( adf_extract_divi_forms( $post->post_content ) as $form ) {
		++$result['found'];
		$reason = 'missing_uuid';
		if ( '' !== $form['uuid'] ) {
			$id = adf_register_divi_form( $post_id, $form['uuid'], $form['attrs'] );
			if ( ! is_wp_error( $id ) ) {
				++$result['indexed'];
				update_post_meta( $id, '_adf_divi_source_format', $form['format'] );
				continue;
			}
			$reason = 'storage_failed';
		}
		$result['issues'][] = array(
			'post_id' => $post_id,
			'name'    => adf_divi_form_name( $form['attrs'] ),
			'format'  => $form['format'],
			'reason'  => $reason,
		);
	}
	return $result;
}

/**
 * Source types include public content and Divi's saved/theme-builder layouts.
 *
 * @param string $type Post type.
 * @return bool
 */
function adf_divi_source_type( $type ) {
	$object = get_post_type_object( $type );
	return ( $object && $object->public ) || in_array( $type, array( 'et_pb_layout', 'et_header_layout', 'et_body_layout', 'et_footer_layout' ), true );
}

/**
 * Resolve a runtime UUID to a saved source; ambiguous shared layouts fail closed.
 *
 * @param int    $page_id Current page ID.
 * @param string $uuid    Module unique ID.
 * @param array  $attrs   Runtime attributes.
 * @return int|WP_Error
 */
function adf_resolve_divi_form( $page_id, $uuid, array $attrs ) {
	static $indexed = array();
	if ( $page_id && ! isset( $indexed[ $page_id ] ) ) {
		$indexed[ $page_id ] = true;
		adf_sync_divi_post( $page_id, get_post( $page_id ) );
	}
	$matches = get_posts(
		array(
			'post_type'      => 'adf_form',
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'meta_key'       => '_adf_divi_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Resolve a verified runtime module UUID.
			'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Resolve a verified runtime module UUID.
		)
	);
	foreach ( $matches as $match ) {
		if ( (int) get_post_meta( $match->ID, '_adf_page_id', true ) === $page_id ) {
			return $match->ID;
		}
	}
	// A saved shared layout can render on a different page. Never pick arbitrarily.
	if ( 1 === count( $matches ) ) {
		$source_id = (int) get_post_meta( $matches[0]->ID, '_adf_page_id', true );
		if ( in_array( get_post_type( $source_id ), array( 'et_pb_layout', 'et_header_layout', 'et_body_layout', 'et_footer_layout' ), true ) ) {
			return $matches[0]->ID;
		}
	}
	if ( ! $matches && $page_id && adf_divi_source_type( get_post_type( $page_id ) ) ) {
		return adf_register_divi_form( $page_id, $uuid, $attrs );
	}
	return new WP_Error( 'adf_divi_ambiguous', __( 'The source of this Divi form is ambiguous. Save or rediscover its source page first.', 'advanced-divi-forms' ) );
}

/**
 * Capture the final filtered attributes before ContactFormHandler runs.
 *
 * @param array $attrs Final runtime attributes.
 * @param array $args  Divi's verified filter arguments.
 * @return array
 */
function adf_divi_runtime_attrs( $attrs, $args ) {
	if ( 'divi/contact-form' !== ( $args['name'] ?? '' ) || ! class_exists( '\ET\Builder\FrontEnd\BlockParser\BlockParserStore' ) ) {
		return $attrs;
	}
	$block = \ET\Builder\FrontEnd\BlockParser\BlockParserStore::get( $args['id'] ?? '', $args['storeInstance'] ?? null );
	if ( ! $block || ! class_exists( '\ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormUtils' ) ) {
		return $attrs;
	}
	if ( empty( $attrs['module']['advanced']['uniqueId']['desktop']['value'] ) ) {
		// Divi's legacy fallback includes the order index, which is not a stable identity.
		return $attrs;
	}
	$uuid    = \ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormUtils::get_unique_id( $attrs, (array) $block );
	$page_id = absint( function_exists( 'et_core_get_main_post_id' ) ? et_core_get_main_post_id() : get_the_ID() );
	unset( $GLOBALS['adf_divi_contexts'][ $uuid ] );
	$form_id = adf_resolve_divi_form( $page_id, $uuid, $attrs );
	if ( ! is_wp_error( $form_id ) ) {
		$GLOBALS['adf_divi_contexts'][ $uuid ] = array(
			'form_id' => $form_id,
			'attrs'   => $attrs,
		);
	}
	return $attrs;
}

/**
 * Accept only validated Divi 5 submissions, not the similarly named Divi 4 hook.
 *
 * @param array $fields Divi's validated fields (not raw POST data).
 * @param bool  $has_error Divi validation/captcha outcome.
 * @param array $info Server-generated form identity.
 * @return void
 */
function adf_divi_form_submitted( $fields, $has_error, $info ) {
	$GLOBALS['adf_divi_pending_mail'] = null;

	$uuid = $info['contact_form_unique_id'] ?? '';
	if ( $has_error || 'divi/contact-form' !== ( $info['module_slug'] ?? '' ) || ! is_string( $uuid ) || empty( $GLOBALS['adf_divi_contexts'][ $uuid ] ) ) {
		return;
	}
	$context = $GLOBALS['adf_divi_contexts'][ $uuid ];
	$values  = array();
	$labels  = array();
	foreach ( $fields as $key => $field ) {
		if ( is_array( $field ) ) {
			$key            = strtolower( (string) $key );
			$values[ $key ] = adf_field_text( $field['value'] ?? '' );
			$labels[ $key ] = adf_field_text( $field['label'] ?? '' );
		}
	}
	$attrs = $context['attrs'];
	if ( 'on' === adf_divi_setting( $attrs, 'storeSubmissions', 'off' ) ) {
		$result = adf_store_submission( $context['form_id'], $values, $labels );
		if ( is_wp_error( $result ) ) {
			update_post_meta( $context['form_id'], '_adf_storage_error', $result->get_error_message() );
		} else {
			delete_post_meta( $context['form_id'], '_adf_storage_error' );
		}
	}
	$email_key = strtolower( adf_divi_setting( $attrs, 'confirmationEmailField', 'email' ) );
	$email_key = '' !== $email_key ? $email_key : 'email';
	$email     = $values[ $email_key ] ?? '';
	if ( 'email' !== ( $fields[ $email_key ]['type'] ?? '' ) || preg_match( '/[\r\n]/', $email ) || ! is_email( $email ) ) {
		$email = '';
	}
	$GLOBALS['adf_divi_pending_mail'] = array(
		'form_id'      => $context['form_id'],
		'values'       => $values,
		'labels'       => $labels,
		'notification' => absint( adf_divi_setting( $attrs, 'notificationTemplate', '0' ) ),
		'confirmation' => absint( adf_divi_setting( $attrs, 'confirmationTemplate', '0' ) ),
		'email'        => $email,
	);
}

/**
 * Match the direct wp_mail caller, not an unrelated nested message from a hook.
 *
 * @return bool
 */
function adf_is_divi_handler_mail() {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Deliberate caller scoping; no arguments or personal data are collected or logged.
	$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );
	foreach ( $trace as $index => $frame ) {
		if ( 'wp_mail' === ( $frame['function'] ?? '' ) ) {
			$caller = $trace[ $index + 1 ] ?? array();
			return 'ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler' === ( $caller['class'] ?? '' ) && 'process' === ( $caller['function'] ?? '' );
		}
	}
	return false;
}

/**
 * Change only Divi's existing recipient message, leaving routing and SMTP intact.
 *
 * @param array $args WordPress wp_mail arguments.
 * @return array
 */
function adf_divi_mail_args( $args ) {
	if ( empty( $GLOBALS['adf_divi_pending_mail'] ) || ! adf_is_divi_handler_mail() ) {
		return $args;
	}
	$pending = $GLOBALS['adf_divi_pending_mail'];

	$GLOBALS['adf_divi_pending_mail'] = null;
	$GLOBALS['adf_divi_active_mail']  = $pending;
	if ( ! $pending['notification'] ) {
		return $args;
	}
	$template = adf_render_mail_template( $pending['notification'], $pending['values'], $pending['labels'] );
	if ( is_wp_error( $template ) ) {
		// A deleted/draft/empty template must not suppress Divi's original email.
		return $args;
	}
	$headers = $args['headers'];
	if ( ! is_array( $headers ) ) {
		$headers = preg_split( '/\r\n|\r|\n/', $headers );
	}
	$headers = array_filter(
		$headers,
		static function ( $header ) {
			return '' !== trim( $header ) && ! preg_match( '/^Content-Type\s*:/i', trim( $header ) );
		}
	);

	$headers[]       = 'Content-Type: text/html; charset=UTF-8';
	$args['subject'] = $template['subject'];
	$args['message'] = $template['html'];
	$args['headers'] = array_values( $headers );
	return $args;
}

/**
 * Respect delivery plugins that short-circuit wp_mail, including failure.
 *
 * @param null|bool $result Short-circuited mail result.
 * @return null|bool
 */
function adf_divi_pre_mail( $result ) {
	if ( null !== $result && ! empty( $GLOBALS['adf_divi_active_mail'] ) && adf_is_divi_handler_mail() ) {
		if ( true === $result ) {
			adf_divi_mail_succeeded();
		} else {
			adf_divi_mail_failed();
		}
	}
	return $result;
}

/**
 * Send at most one optional confirmation after successful recipient delivery.
 *
 * @return void
 */
function adf_divi_mail_succeeded() {
	if ( empty( $GLOBALS['adf_divi_active_mail'] ) || ! adf_is_divi_handler_mail() ) {
		return;
	}
	$pending = $GLOBALS['adf_divi_active_mail'];

	$GLOBALS['adf_divi_active_mail'] = null;
	if ( ! $pending['confirmation'] || ! $pending['email'] ) {
		return;
	}
	$template = adf_render_mail_template( $pending['confirmation'], $pending['values'], $pending['labels'] );
	if ( ! is_wp_error( $template ) ) {
		wp_mail( $pending['email'], $template['subject'], $template['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}

/**
 * Discard confirmation state when the original recipient email fails.
 *
 * @return void
 */
function adf_divi_mail_failed() {
	if ( adf_is_divi_handler_mail() ) {
		$GLOBALS['adf_divi_active_mail'] = null;
	}
}

/**
 * Discover existing source pages in bounded, administrator-triggered batches.
 *
 * @return void
 */
function adf_discover_divi_forms() {
	adf_require_admin();
	check_admin_referer( 'adf_discover_forms' );
	$page   = isset( $_POST['scan_page'] ) && is_scalar( $_POST['scan_page'] ) ? max( 1, absint( $_POST['scan_page'] ) ) : 1;
	$key    = 'adf_discovery_' . get_current_user_id();
	$report = $page > 1 ? get_transient( $key ) : false;
	if ( ! is_array( $report ) ) {
		$report = array(
			'scanned' => 0,
			'found'   => 0,
			'indexed' => 0,
			'issues'  => array(),
		);
	}
	$query = adf_divi_discovery_query( $page );
	foreach ( $query->posts as $post ) {
		$result             = adf_sync_divi_post( $post->ID, $post );
		$report['found']   += $result['found'];
		$report['indexed'] += $result['indexed'];
		++$report['scanned'];
		// Bound diagnostic storage; counters still cover all scanned source posts.
		$report['issues'] = array_slice( array_merge( $report['issues'], $result['issues'] ), 0, 100 );
	}
	set_transient( $key, $report, HOUR_IN_SECONDS );
	wp_safe_redirect(
		adf_forms_url(
			array(
				'scan_next' => $page < $query->max_num_pages ? $page + 1 : 0,
				'scan_done' => 1,
			)
		)
	);
	exit;
}

/**
 * Search both supported storage formats, without changing unrelated WP queries.
 *
 * @param int $page Discovery batch number.
 * @return WP_Query
 */
function adf_divi_discovery_query( $page = 1 ) {
	$types  = array_filter( get_post_types(), 'adf_divi_source_type' );
	$search = static function ( $sql, $query ) {
		if ( ! $query->get( 'adf_discovery' ) ) {
			return $sql;
		}
		global $wpdb;
		return $sql . $wpdb->prepare(
			' AND (%i.post_content LIKE %s OR %i.post_content LIKE %s) ',
			$wpdb->posts,
			'%' . $wpdb->esc_like( 'wp:divi/contact-form' ) . '%',
			$wpdb->posts,
			'%' . $wpdb->esc_like( '[et_pb_contact_form' ) . '%'
		);
	};
	add_filter( 'posts_search', $search, 10, 2 );
	try {
		return new WP_Query(
			array(
				'post_type'      => array_values( $types ),
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'adf_discovery'  => true,
				'posts_per_page' => 100,
				'paged'          => max( 1, absint( $page ) ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	} finally {
		remove_filter( 'posts_search', $search, 10 );
	}
}
