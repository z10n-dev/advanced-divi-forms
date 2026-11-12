<?php
/**
 * Emit Playwright cookies for the disposable accessibility site as JSON.
 * Local/CI audits only; the cookies point at the loopback site URL.
 *
 * Usage: wp eval-file tests/fixtures/accessibility-cookies.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }

$login = getenv( 'ADF_COOKIE_USER' ) ?: 'adf-admin';
$user  = get_user_by( 'login', $login );
if ( ! $user ) { WP_CLI::error( 'User not found: ' . $login ); }

$expiration = time() + 7200;
$token      = WP_Session_Tokens::get_instance( $user->ID )->create( $expiration );
$base       = untrailingslashit( home_url() );

$cookies = array(
	array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token ), 'url' => $base ),
	// The admin cookie is generated for the "auth" scheme: WordPress derives the
	// scheme from the cookie name, so "admin" would hash to an invalid cookie.
	array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user->ID, $expiration, 'auth', $token ), 'url' => $base ),
);

echo wp_json_encode( array( 'base_url' => $base, 'user' => $user->user_login, 'cookies' => $cookies ) ), "\n";
WP_CLI::success( 'Cookies generated for ' . $user->user_login );
