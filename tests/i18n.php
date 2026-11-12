<?php
/**
 * Bundled translations: the POT template and the German catalogue must be
 * complete, loadable at runtime and placeholder-compatible. Disposable
 * WordPress only.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
function adf_i18n_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$languages = dirname( ADF_PLUGIN_FILE ) . '/languages';
$pot_path  = $languages . '/advanced-divi-forms.pot';
$mo_path   = $languages . '/advanced-divi-forms-de_DE.mo';
$po_path   = $languages . '/advanced-divi-forms-de_DE.po';

adf_i18n_expect( function_exists( 'adf_load_textdomain' ), 'The plugin must ship a textdomain loader.' );
adf_i18n_expect( false !== has_action( 'init', 'adf_load_textdomain' ), 'Bundled translations must be loaded on init (not before WordPress is ready).' );
adf_i18n_expect( file_exists( $pot_path ), 'The translation template is missing from the packaged plugin.' );
adf_i18n_expect( file_exists( $po_path ), 'The German source catalogue is missing from the packaged plugin.' );
adf_i18n_expect( file_exists( $mo_path ), 'The compiled German catalogue is missing from the packaged plugin.' );

require_once ABSPATH . WPINC . '/pomo/po.php';
require_once ABSPATH . WPINC . '/pomo/mo.php';

$read_template = static function ( $path ) {
	$po = new PO();
	adf_i18n_expect( $po->import_from_file( $path ), 'The template could not be parsed: ' . basename( $path ) );
	return $po;
};

$read_catalogue = static function ( $path ) {
	$mo = new MO();
	adf_i18n_expect( $mo->import_from_file( $path ), 'The compiled catalogue could not be parsed: ' . basename( $path ) );
	return $mo;
};

$pot = $read_template( $pot_path );
$de  = $read_catalogue( $mo_path );

adf_i18n_expect( count( $pot->entries ) >= 200, 'The template must cover the admin interface, got ' . count( $pot->entries ) . ' strings.' );
adf_i18n_expect( count( $de->entries ) === count( $pot->entries ), 'Every template string needs a German translation, got ' . count( $de->entries ) . ' of ' . count( $pot->entries ) . '.' );
adf_i18n_expect( 'de_DE' === ( $de->headers['Language'] ?? '' ), 'The German catalogue must declare its language, got ' . ( $de->headers['Language'] ?? 'none' ) . '.' );

foreach ( array( 'Forms', 'Mail Templates', 'Privacy & Retention', 'Check GitHub now (bypass cache)' ) as $sample ) {
	adf_i18n_expect( isset( $pot->entries[ $sample ] ), 'The template is missing the interface string: ' . $sample );
}

$placeholders = static function ( $text ) {
	preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $matches );
	// Order matters: German may not swap "%1$s" and "%2$s", so compare the
	// sequence instead of a sorted set.
	return $matches[0];
};

$untranslated = array();
$mismatched   = array();
$translated   = 0;
// These strings are identical in German on purpose: plugin name, URI, author and
// two short table words. Anything else must differ from the English source.
$identical_in_german = array( 'Advanced Divi Forms', 'https://github.com/z10n-dev/advanced-divi-forms', 'z10n-dev', 'Format', 'Status' );
foreach ( $pot->entries as $source => $entry ) {
	if ( ! isset( $de->entries[ $source ] ) ) {
		$untranslated[] = $source;
		continue;
	}
	$german = (string) ( $de->entries[ $source ]->translations[0] ?? '' );
	if ( '' === trim( $german ) ) {
		$untranslated[] = $source;
		continue;
	}
	if ( $german === $source && ! in_array( $source, $identical_in_german, true ) ) {
		$untranslated[] = $source;
		continue;
	}
	$translated++;
	if ( $placeholders( $source ) !== $placeholders( $german ) ) {
		$mismatched[] = $source;
	}
}
adf_i18n_expect( array() === $untranslated, 'Untranslated German strings: ' . implode( ' | ', array_slice( $untranslated, 0, 5 ) ) );
adf_i18n_expect( $translated >= count( $pot->entries ) - count( $identical_in_german ), 'The German catalogue is incomplete: ' . $translated . ' of ' . count( $pot->entries ) . ' strings translated.' );

// German keeps the source placeholder order here, including positional ones.
adf_i18n_expect( array() === $mismatched, 'Placeholder mismatch between source and German: ' . implode( ' | ', array_slice( $mismatched, 0, 5 ) ) );

$original     = get_locale();
$force_locale = static function () {
	return 'de_DE';
};
try {
	// A fresh WordPress install has no German language pack, and this suite must
	// stay offline. Forcing the locale through the filter produces exactly the
	// state a German site is in, so the plugin's own catalogue has to load.
	add_filter( 'locale', $force_locale, 99 );
	adf_i18n_expect( 'de_DE' === get_locale(), 'The forced locale must be de_DE, got ' . get_locale() . '.' );
	adf_load_textdomain();
	// WordPress 6.5+ loads translations just in time, so is_textdomain_loaded()
	// can stay false until a string is requested; the translated string itself is
	// the contract that matters.
	adf_i18n_expect( 'Formulare' === __( 'Forms', 'advanced-divi-forms' ), 'A German string must resolve through the bundled catalogue, got: ' . __( 'Forms', 'advanced-divi-forms' ) );
	adf_i18n_expect( 'Papierkorb (3)' === sprintf( __( 'Trash (%d)', 'advanced-divi-forms' ), 3 ), 'Placeholders must be substituted in the German catalogue.' );
	$positional = sprintf( __( 'Advanced Divi Forms needs Divi %1$s or newer, but %2$s %3$s is active. Update Divi before relying on the integration.', 'advanced-divi-forms' ), '5.16.0', 'Divi', '5.15.0' );
	adf_i18n_expect( 'Advanced Divi Forms benötigt Divi 5.16.0 oder neuer, aktiv ist jedoch Divi 5.15.0. Aktualisieren Sie Divi, bevor Sie sich auf die Integration verlassen.' === $positional, 'Positional placeholders must resolve to the German sentence, got: ' . $positional );
} finally {
	remove_filter( 'locale', $force_locale, 99 );
	if ( function_exists( 'unload_textdomain' ) ) {
		unload_textdomain( 'advanced-divi-forms' );
	}
	adf_load_textdomain();
}

adf_i18n_expect( $original === get_locale(), 'The original locale must be restored after the German check, got ' . get_locale() . '.' );
if ( 'de_DE' !== $original ) {
	adf_i18n_expect( 'Forms' === __( 'Forms', 'advanced-divi-forms' ), 'English strings must return after the German check, got: ' . __( 'Forms', 'advanced-divi-forms' ) );
}

WP_CLI::success( 'Translation template, German catalogue, placeholder parity and runtime loading passed.' );
