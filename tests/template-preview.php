<?php
/** Pure unsaved rendering tests, inside disposable WordPress via WP-CLI. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
if ( ! function_exists( 'adf_render_template_content' ) ) {
	throw new RuntimeException( 'Unsaved test-data preview needs a pure content renderer independent of published template records.' );
}
function adf_preview_expect( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$reject_mail = static function () { throw new RuntimeException( 'Preview must never call wp_mail.' ); };
$reject_http = static function () { throw new RuntimeException( 'Rendering test data must never issue remote requests.' ); };

$reject_store = static function () { throw new RuntimeException( 'Preview attempted a post/submission write.' ); };
$shortcode_calls = 0;
$previous_shortcode = $GLOBALS['shortcode_tags']['adf_preview_probe'] ?? null;
add_shortcode( 'adf_preview_probe', static function () use ( &$shortcode_calls ) { ++$shortcode_calls; return 'MUST NOT RUN'; } );
$marker = sys_get_temp_dir() . '/adf-preview-php-' . wp_generate_uuid4();
add_filter( 'wp_insert_post_data', $reject_store );
add_filter( 'pre_wp_mail', $reject_mail );
add_filter( 'pre_http_request', $reject_http );
try {
	$result = adf_render_template_content( 'Hello {{fields.name}}', '<p>{{fields.message}}</p>{{form.summary}}', array( 'name' => 'Ada', 'message' => 'Literal {{fields.name}}' ) );
	adf_preview_expect( ! is_wp_error( $result ) && 'Hello Ada' === $result['subject'] && str_contains( $result['html'], 'Literal {{fields.name}}' ) && str_contains( $result['html'], '<table' ), 'Unsaved rendering must use supplied synthetic values and summary without recursive evaluation.' );
	$boundary = adf_render_template_content( 'Subject', '<p title="before > {{fields.secret}}">Body only</p>', array( 'secret' => 'MustNeverAppearInAttributes' ) );
	adf_preview_expect( ! is_wp_error( $boundary ) && ! str_contains( $boundary['html'], 'MustNeverAppearInAttributes' ), 'Quoted angle brackets must not trick the renderer into expanding private field values inside attributes.' );
	adf_preview_expect( function_exists( 'adf_parse_preview_fields' ), 'Preview needs a bounded JSON-object parser for freely entered synthetic fields.' );
	$fields = adf_parse_preview_fields( '{"name":"Ada","choices":["A","B"],"code":"00123"}' );
	adf_preview_expect( ! is_wp_error( $fields ) && 'Ada' === $fields['name'] && 'A, B' === $fields['choices'] && '00123' === $fields['code'], 'Synthetic JSON must retain literal field values and supported multiselects.' );
	foreach ( array( '{broken', '[1,2]', '{"bad.id":"x"}', '{"field":{"nested":"object"}}', '{"Name":"a","name":"b"}' ) as $invalid ) {
		adf_preview_expect( is_wp_error( adf_parse_preview_fields( $invalid ) ), 'Malformed JSON, field IDs, nested structures and ambiguous case-collisions must fail without storage.' );
	}
	adf_preview_expect( function_exists( 'adf_build_template_preview' ), 'Synthetic preview needs missing/unknown placeholder diagnostics and an offline sandbox document.' );
	$preview = adf_build_template_preview( 'Hello {{fields.name}}', '<p>{{fields.name}} {{fields.missing}} {{unknown.token}}</p><a href="https://example.org/{{fields.name}}">Disabled link</a><img src="https://example.org/preview-pixel">{{form.summary}}', '{"name":"Ada"}' );
	adf_preview_expect( ! is_wp_error( $preview ) && 'Hello Ada' === $preview['subject'] && in_array( '{{fields.missing}}', $preview['missing_fields'], true ) && in_array( '{{unknown.token}}', $preview['unknown_placeholders'], true ), 'Preview must explain missing test-data IDs and unsupported syntax.' );
	adf_preview_expect( in_array( '{{fields.name}}', $preview['attribute_placeholders'], true ) && str_contains( $preview['document'], 'Content-Security-Policy' ) && ! str_contains( $preview['document'], 'https://example.org' ), 'Preview must explain blocked attribute placeholders and remove external resource/navigation URLs.' );
	foreach ( array( '<p title="a > x" title="{{fields.secret}}">Body</p>', '<p title="a > {{fields.secret}}"' ) as $malformed_html ) {
		$malformed = adf_render_template_content( 'Subject', $malformed_html, array( 'secret' => 'MustNeverAppearInAttributes' ) );
		adf_preview_expect( is_wp_error( $malformed ) || ! str_contains( $malformed['html'], 'MustNeverAppearInAttributes' ), 'Duplicate or incomplete quoted tags must fail closed rather than expand private values into markup.' );
	}
	$too_large = adf_build_template_preview( 'Subject', str_repeat( '{{form.summary}}', 50 ), wp_json_encode( array( 'value' => str_repeat( '&', 5000 ) ) ) );
	adf_preview_expect( is_wp_error( $too_large ) && 'adf_preview_render_limit' === $too_large->get_error_code(), 'Preview must bound expanded output before repeated summaries can exhaust memory.' );
	$saved_boundary = adf_sanitize_email_html( '<p title="before > {{fields.secret}}">Body</p>' );
	$saved_render = adf_render_template_content( 'Subject', $saved_boundary, array( 'secret' => 'MustNeverAppearInAttributes' ) );
	adf_preview_expect( ! is_wp_error( $saved_render ) && ! str_contains( $saved_render['html'], 'MustNeverAppearInAttributes' ), 'Saving/sanitizing must not relocate an attribute token into body text before delivery.' );
	$code_sample = '<p>[adf_preview_probe]</p><?php file_put_contents(' . var_export( $marker, true ) . ', "executed"); ?><script>window.previewExecuted=true</script><p>Safe text</p>';
	$no_execution = adf_build_template_preview( 'Subject', $code_sample, '{}' );
	adf_preview_expect( ! is_wp_error( $no_execution ) && 0 === $shortcode_calls && ! file_exists( $marker ) && str_contains( $no_execution['document'], '[adf_preview_probe]' ) && ! str_contains( $no_execution['document'], '<script' ), 'Preview must leave shortcodes inert and never execute PHP or browser scripts.' );
	WP_CLI::success( 'Pure unsaved-template rendering tracer passed with zero mail/network calls.' );
} finally {
	remove_filter( 'wp_insert_post_data', $reject_store );
	if ( $previous_shortcode ) { add_shortcode( 'adf_preview_probe', $previous_shortcode ); } else { remove_shortcode( 'adf_preview_probe' ); }
	if ( file_exists( $marker ) ) { wp_delete_file( $marker ); }
	remove_filter( 'pre_wp_mail', $reject_mail );
	remove_filter( 'pre_http_request', $reject_http );
}
