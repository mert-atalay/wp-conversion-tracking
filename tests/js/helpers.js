'use strict';

const { JSDOM } = require( 'jsdom' );
const LCT = require( '../../assets/js/lct.js' );

const FORM_HTML = `
	<form id="plain">
		<input type="hidden" name="lct_last_utm_source">
		<input type="hidden" data-lct="first_gclid">
		<div class="lct-last_utm_campaign"><input type="hidden" name="wpforms[fields][7]"></div>
		<input type="hidden" name="lct_event_id">
		<input type="email" name="email" value="person@example.com">
	</form>
	<form id="auto" class="wpcf7-form"><input type="text" name="your-name"></form>
`;

/** Creates a jsdom window. Options: url, referrer, html. */
function makeWindow( options = {} ) {
	const settings = {
		url: options.url ?? 'https://example.com/landing/?utm_source=google&utm_medium=cpc&utm_campaign=spring&gclid=abc123&secret=1',
	};
	const referrer = options.referrer ?? 'https://www.google.com/search?q=private+query';

	if ( referrer ) {
		settings.referrer = referrer;
	}

	const dom = new JSDOM( `<!doctype html><html><body>${ options.html ?? FORM_HTML }</body></html>`, settings );

	return dom.window;
}

/** Adds a fake WP Consent API to a window. Returns a function that changes consent. */
function addConsentApi( win, initial ) {
	let granted = initial;

	win.wp_has_consent = ( category ) => category === 'marketing' && granted;

	return ( value ) => {
		granted = value;
		win.document.dispatchEvent(
			new win.CustomEvent( 'wp_listen_for_consent_change', { detail: { marketing: value ? 'allow' : 'deny' } } )
		);
	};
}

module.exports = { LCT, makeWindow, addConsentApi };
