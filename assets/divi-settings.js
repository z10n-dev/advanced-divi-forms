/* global adfDiviSettings */
( function () {
	'use strict';
	const hooks = window.vendor && window.vendor.wp && window.vendor.wp.hooks;
	if ( ! hooks || ! window.adfDiviSettings ) {
		return;
	}

	// Verified in Divi 5.16.0 module-library.js; uses Divi's own hooks instance.
	hooks.addFilter(
		'divi.moduleLibrary.moduleAttributes.divi.contact-form',
		'advanced-divi-forms/settings',
		function ( attributes ) {
			return Object.assign( {}, attributes, { adf: adfDiviSettings.attribute } );
		}
	);
}() );
