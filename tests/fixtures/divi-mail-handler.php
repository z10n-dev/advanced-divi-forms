<?php
/**
 * Original test double for Divi 5.16.0's verified hook/caller contract.
 * Contains no vendor source. Not included in release ZIPs.
 */

namespace ET\Builder\Packages\ModuleLibrary\ContactForm;

class ContactFormHandler {
	public static function process( $fields, $error, $info, $nested_mail = false ) {
		\do_action( 'et_pb_contact_form_submit', $fields, $error, $info );
		if ( $error ) {
			return false;
		}
		if ( $nested_mail ) {
			$nested_mail();
		}
		return \wp_mail( 'recipient@example.org', 'Divi default', 'Original plain message', array( 'Reply-To: sender@example.org' ) );
	}
}
