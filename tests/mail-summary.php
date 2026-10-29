<?php
/**
 * Form summary rendering tests in a disposable WordPress installation.
 * Run with WP-CLI eval-file; never use a live site's database.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

function adf_summary_expect( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$template = wp_insert_post( array( 'post_type' => 'adf_mail_template', 'post_status' => 'publish', 'post_title' => 'Summary test' ), true );
if ( is_wp_error( $template ) ) {
	WP_CLI::error( $template->get_error_message() );
}
try {
	update_post_meta( $template, '_adf_subject', 'Hello {{fields.name}}' );
	update_post_meta( $template, '_adf_html', '<h2>Summary</h2>{{form.summary}}<p>End</p>' );
	$rendered = adf_render_mail_template( $template, array( 'name' => 'Alice', 'message' => 'Hello' ) );
	adf_summary_expect( is_array( $rendered ) && str_contains( $rendered['html'], '<table' ) && ! str_contains( $rendered['html'], '{{form.summary}}' ), 'form.summary must insert an HTML table.' );
	adf_summary_expect( str_contains( $rendered['html'], 'Alice' ) && str_contains( $rendered['html'], 'Hello' ) && 'Hello Alice' === $rendered['subject'], 'The summary must include every field and preserve individual placeholders.' );
	adf_summary_expect( strpos( $rendered['html'], '</h2>' ) < strpos( $rendered['html'], '<table' ) && strpos( $rendered['html'], '</table>' ) < strpos( $rendered['html'], '<p>End' ), 'The summary must appear at the placeholder position.' );
	$rendered = adf_render_mail_template( $template, array( 'name' => 'Alice', 'message' => 'Hello' ), array( 'name' => 'Your name', 'message' => '' ) );
	adf_summary_expect( str_contains( $rendered['html'], '>Your name</th>' ) && str_contains( $rendered['html'], '>message</th>' ), 'Summary rows must use supplied labels and fall back to field IDs for empty labels.' );
	// Exercise the public rendering API with real WordPress sanitization.
	update_post_meta( $template, '_adf_subject', 'Hello {{fields.name}} {{form.summary}}' );
	update_post_meta( $template, '_adf_html', '<h2>{{fields.name}}</h2>{{form.summary}}<p>{{fields.all}}</p><a title="{{form.summary}}" href="https://example.org/{{fields.name}}">Link</a>' );
	$fields = array( 'Name' => 'A&B <script>alert(1)</script>', 'message' => "Line one\r\nLine two\n{{fields.name}} {{form.summary}}", 'choices' => array( 'One', 'Two' ), 'all' => 'Real field named all', 'empty' => '', 'zero' => '0' );
	$rendered = adf_render_mail_template( $template, $fields, array( 'NAME' => 'Your & my name', 'message' => '<img src=x onerror=alert(1)>Message' ) );
	adf_summary_expect( str_contains( $rendered['html'], '>Your &amp; my name</th>' ) && ! str_contains( $rendered['html'], '<script' ) && ! str_contains( $rendered['html'], '<img' ), 'Labels and values must be safe HTML text.' );
	adf_summary_expect( str_contains( $rendered['html'], 'Line one<br' ) && str_contains( $rendered['html'], 'Line two<br' ) && str_contains( $rendered['html'], 'One, Two' ), 'Summary values must preserve line breaks and flatten multiselects.' );
	adf_summary_expect( str_contains( $rendered['html'], '{{fields.name}} {{form.summary}}' ) && 1 === substr_count( $rendered['html'], '<table' ), 'Placeholders inside submitted values must remain literal and never expand recursively.' );
	adf_summary_expect( str_contains( $rendered['html'], '<p>Real field named all</p>' ), 'The summary must not reserve the existing field ID all.' );
	adf_summary_expect( str_contains( $rendered['html'], '>empty</th><td valign="top"></td>' ) && str_contains( $rendered['html'], '>zero</th><td valign="top">0</td>' ), 'Empty submitted fields and zero values must not disappear.' );
	adf_summary_expect( ! str_contains( $rendered['subject'], '{{form.summary}}' ) && ! str_contains( $rendered['subject'], '<table' ), 'The summary is body-only and must not inject markup into subjects.' );
	adf_summary_expect( str_contains( $rendered['html'], 'title=""' ) && str_contains( $rendered['html'], 'href="https://example.org/"' ), 'Neither summary nor individual placeholders may expand in HTML attributes.' );
	$rendered = adf_render_mail_template( $template, array() );
	adf_summary_expect( is_array( $rendered ) && ! str_contains( $rendered['html'], '<table' ) && ! str_contains( $rendered['html'], '{{form.summary}}' ), 'No submitted fields must produce no empty table.' );
	WP_CLI::success( 'Form summary rendering tests passed.' );
} finally {
	wp_delete_post( $template, true );
}
