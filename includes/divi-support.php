<?php
/**
 * Divi compatibility guard.
 *
 * The integration hooks Divi 5 contact-form internals. On a site that runs an
 * older Divi or no Divi theme at all, the plugin would silently store nothing,
 * so administrators get an explicit warning instead of an empty Forms screen.
 *
 * @package AdvancedDiviForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_notices', 'adf_render_divi_support_notice' );

/**
 * Lowest Divi version the integration is verified against.
 *
 * @return string
 */
function adf_divi_minimum_version() {
	return '5.16.0';
}

/**
 * Classify a detected Divi version without guessing.
 *
 * An unreadable version string is treated as outdated: warning a site owner is
 * safer than silently assuming the hooks still match.
 *
 * @param string|null $version Detected theme version, null when Divi is absent.
 * @param string      $theme   Detected theme name.
 * @return array Support state with status, detected, minimum and theme keys.
 */
function adf_evaluate_divi_support( $version, $theme = '' ) {
	$minimum  = adf_divi_minimum_version();
	$detected = is_string( $version ) ? trim( $version ) : '';
	$theme    = is_string( $theme ) ? trim( $theme ) : '';
	if ( '' === $detected ) {
		return array(
			'status'   => 'missing',
			'detected' => null,
			'minimum'  => $minimum,
			'theme'    => $theme,
		);
	}
	$numeric = 1 === preg_match( '/^\d+(?:\.\d+)+/', $detected );
	$status  = ( $numeric && version_compare( $detected, $minimum, '>=' ) ) ? 'supported' : 'outdated';
	return array(
		'status'   => $status,
		'detected' => $detected,
		'minimum'  => $minimum,
		'theme'    => $theme,
	);
}

/**
 * Read the active theme, or its Divi parent for child themes.
 *
 * @return array Theme name and version; version is null when Divi is absent.
 */
function adf_detect_divi() {
	$theme = wp_get_theme();
	if ( ! $theme || ! $theme->exists() ) {
		return array(
			'theme'   => '',
			'version' => null,
		);
	}
	$candidates = array( $theme );
	$parent     = $theme->parent();
	if ( $parent ) {
		$candidates[] = $parent;
	}
	foreach ( $candidates as $candidate ) {
		$name     = trim( (string) $candidate->get( 'Name' ) );
		$template = strtolower( trim( (string) $candidate->get_template() ) );
		if ( 'divi' !== $template && 'divi' !== strtolower( $name ) ) {
			continue;
		}
		return array(
			'theme'   => $name,
			'version' => (string) $candidate->get( 'Version' ),
		);
	}
	return array(
		'theme'   => '',
		'version' => null,
	);
}

/**
 * Warn administrators when the active Divi version cannot support the integration.
 *
 * @param array|null $support Optional pre-computed support state.
 * @return void
 */
function adf_render_divi_support_notice( $support = null ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( null === $support ) {
		$live    = adf_detect_divi();
		$support = adf_evaluate_divi_support( $live['version'], $live['theme'] );
	}
	if ( ! is_array( $support ) || empty( $support['status'] ) || 'supported' === $support['status'] ) {
		return;
	}
	$minimum = isset( $support['minimum'] ) ? (string) $support['minimum'] : adf_divi_minimum_version();
	if ( 'missing' === $support['status'] ) {
		$message = sprintf(
			/* translators: %s: minimum supported Divi version. */
			__( 'Advanced Divi Forms needs Divi %s or newer. No Divi theme is active, so contact-form answers, emails, templates and exports are not handled yet.', 'advanced-divi-forms' ),
			$minimum
		);
	} else {
		$message = sprintf(
			/* translators: 1: minimum supported Divi version, 2: theme name, 3: detected version. */
			__( 'Advanced Divi Forms needs Divi %1$s or newer, but %2$s %3$s is active. Update Divi before relying on the integration.', 'advanced-divi-forms' ),
			$minimum,
			! empty( $support['theme'] ) ? (string) $support['theme'] : 'Divi',
			(string) $support['detected']
		);
	}
	printf(
		'<div class="notice notice-warning adf-divi-notice"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * One-line compatibility state for the plugin diagnostics table.
 *
 * @return string
 */
function adf_divi_support_summary() {
	$live    = adf_detect_divi();
	$support = adf_evaluate_divi_support( $live['version'], $live['theme'] );
	if ( 'missing' === $support['status'] ) {
		return __( 'Not detected - no Divi theme is active', 'advanced-divi-forms' );
	}
	if ( 'supported' === $support['status'] ) {
		/* translators: 1: theme name, 2: version, 3: minimum version. */
		return sprintf( __( '%1$s %2$s (supported, needs %3$s or newer)', 'advanced-divi-forms' ), $live['theme'], $support['detected'], $support['minimum'] );
	}
	/* translators: 1: theme name, 2: version, 3: minimum version. */
	return sprintf( __( '%1$s %2$s (outdated, needs %3$s or newer)', 'advanced-divi-forms' ), $live['theme'], $support['detected'], $support['minimum'] );
}
