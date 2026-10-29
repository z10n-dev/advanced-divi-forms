'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
let filter;
const attribute = { type: 'object', settings: {} };
const context = {
	window: { vendor: { wp: { hooks: { addFilter( name, namespace, callback ) {
		assert.equal( name, 'divi.moduleLibrary.moduleAttributes.divi.contact-form' );
		assert.equal( namespace, 'advanced-divi-forms/settings' );
		filter = callback;
	} } } }, adfDiviSettings: { attribute } },
	adfDiviSettings: { attribute },
};
vm.runInNewContext( fs.readFileSync( 'assets/divi-settings.js', 'utf8' ), context );
const original = { email: { type: 'object' } };
const result = filter( original );
assert.equal( result.adf, attribute );
assert.equal( result.email, original.email );
assert.equal( original.adf, undefined );
vm.runInNewContext( fs.readFileSync( 'assets/divi-settings.js', 'utf8' ), { window: {} } );
console.log( 'Divi Visual Builder settings tests passed.' );
