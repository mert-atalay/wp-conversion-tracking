'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { LCT, makeWindow, addConsentApi } = require( './helpers.js' );

function fieldValue( win, selector ) {
	return win.document.querySelector( selector ).value;
}

function setPendingCookie( win, pending ) {
	win.document.cookie = 'lct_pending=' + encodeURIComponent( JSON.stringify( pending ) ) + '; path=/';
}

/* Consent gating */

test( 'without consent nothing is stored, filled or pushed', () => {
	const win = makeWindow();
	const lct = LCT.create( win, {} );

	lct.init();

	assert.equal( lct.hasConsent(), false );
	assert.equal( win.document.cookie, '' );
	assert.equal( win.localStorage.length, 0 );
	assert.equal( lct.capture(), null );
	assert.equal( lct.getAttribution(), null );
	assert.equal( fieldValue( win, '#plain [name="lct_last_utm_source"]' ), '' );
	assert.equal( fieldValue( win, '#plain [name="lct_event_id"]' ), '' );
	assert.equal( win.document.querySelectorAll( '[data-lct-injected]' ).length, 0 );
	assert.equal( lct.trackLead( win.document.querySelector( '#auto' ), { formId: 1, formType: 'cf7' } ), null );
	assert.equal( win.dataLayer, undefined );
} );

test( 'a pending server cookie is not pushed without consent', () => {
	const win = makeWindow();
	setPendingCookie( win, { event_id: 'server-id-0001', form_id: '5', form_type: 'wpforms' } );

	LCT.create( win, {} ).init();

	assert.equal( win.dataLayer, undefined );
} );

test( 'the WP Consent API decides when it is present, even if the fallback says yes', () => {
	const win = makeWindow();
	addConsentApi( win, false );
	const lct = LCT.create( win, { consentFallback: true } );

	lct.init();

	assert.equal( lct.hasConsent(), false );
	assert.equal( win.document.cookie, '' );
} );

test( 'consentApi without the browser function means no consent', () => {
	const win = makeWindow();
	const lct = LCT.create( win, { consentApi: true, consentFallback: true } );

	assert.equal( lct.hasConsent(), false );
} );

test( 'consent given later on the same page stores the landing page touch', () => {
	const win = makeWindow();
	const setConsent = addConsentApi( win, false );
	const lct = LCT.create( win, {} );

	lct.init();
	assert.equal( win.document.cookie, '' );

	setConsent( true );

	const attribution = lct.getAttribution();
	assert.equal( attribution.first.utm_source, 'google' );
	assert.equal( attribution.first.gclid, 'abc123' );
	assert.equal( attribution.first.landing_page, '/landing/' );
	assert.equal( attribution.first.referrer, 'https://www.google.com/search' );
	assert.match( win.document.cookie, /lct_attr=/ );
	assert.ok( win.localStorage.getItem( 'lct_attr' ) );
	assert.equal( fieldValue( win, '#plain [name="lct_last_utm_source"]' ), 'google' );
} );

test( 'withdrawing consent deletes storage and empties the form fields', () => {
	const win = makeWindow();
	const setConsent = addConsentApi( win, true );
	const lct = LCT.create( win, {} );

	lct.init();
	assert.match( win.document.cookie, /lct_attr=/ );
	assert.ok( win.document.querySelectorAll( '[data-lct-injected]' ).length > 0 );

	setConsent( false );

	assert.equal( win.document.cookie, '' );
	assert.equal( win.localStorage.getItem( 'lct_attr' ), null );
	assert.equal( fieldValue( win, '#plain [name="lct_last_utm_source"]' ), '' );
	assert.equal( fieldValue( win, '#plain [name="lct_event_id"]' ), '' );
	assert.equal( win.document.querySelectorAll( '[data-lct-injected]' ).length, 0 );
	assert.equal( fieldValue( win, '#plain [name="email"]' ), 'person@example.com' );
} );

test( 'the lct:consent event works for sites without the WP Consent API', () => {
	const win = makeWindow();
	const lct = LCT.create( win, {} );

	lct.init();
	win.document.dispatchEvent( new win.CustomEvent( 'lct:consent', { detail: { granted: true } } ) );
	assert.equal( lct.getAttribution().last.utm_medium, 'cpc' );

	win.document.dispatchEvent( new win.CustomEvent( 'lct:consent', { detail: { granted: false } } ) );
	assert.equal( lct.getAttribution(), null );
	assert.equal( win.document.cookie, '' );
} );

/* Storage: first and last touch across page views */

test( 'first touch survives a later visit and last touch follows the new campaign', () => {
	const first = makeWindow();
	LCT.create( first, { consentFallback: true } ).init();
	const cookie = first.document.cookie;

	const second = makeWindow( {
		url: 'https://example.com/offer/?utm_source=newsletter&utm_medium=email',
		referrer: '',
	} );
	second.document.cookie = cookie + '; path=/';
	const lct = LCT.create( second, { consentFallback: true } );
	lct.init();

	const attribution = lct.getAttribution();
	assert.equal( attribution.first.utm_source, 'google' );
	assert.equal( attribution.first.gclid, 'abc123' );
	assert.equal( attribution.last.utm_source, 'newsletter' );
	assert.equal( attribution.last.gclid, undefined );
	assert.equal( attribution.last.landing_page, '/offer/' );
} );

test( 'localStorage is used when the cookie is gone', () => {
	const first = makeWindow();
	LCT.create( first, { consentFallback: true } ).init();
	const saved = first.localStorage.getItem( 'lct_attr' );

	const second = makeWindow( { url: 'https://example.com/contact/', referrer: 'https://example.com/' } );
	second.localStorage.setItem( 'lct_attr', saved );
	const lct = LCT.create( second, { consentFallback: true } );
	lct.init();

	assert.equal( lct.getAttribution().first.utm_source, 'google' );
	assert.equal( lct.getAttribution().last.utm_source, 'google' );
} );

test( 'an expired record is ignored and the lifetime follows cookieDays', () => {
	const win = makeWindow( { url: 'https://example.com/', referrer: '' } );
	const expired = { first: { utm_source: 'old', ts: 1 }, last: { utm_source: 'old', ts: 1 }, exp: Date.now() - 1000 };
	win.localStorage.setItem( 'lct_attr', JSON.stringify( expired ) );

	const lct = LCT.create( win, { consentFallback: true, cookieDays: 30 } );
	lct.init();

	assert.equal( lct.getAttribution().first.utm_source, undefined );

	const record = JSON.parse( win.localStorage.getItem( 'lct_attr' ) );
	const days = ( record.exp - Date.now() ) / 86400000;
	assert.ok( days > 29.9 && days <= 30 );
} );

/* Hidden fields */

test( 'fields are filled by name, by data-lct and by a class on a wrapper', () => {
	const win = makeWindow();
	LCT.create( win, { consentFallback: true } ).init();

	assert.equal( fieldValue( win, '#plain [name="lct_last_utm_source"]' ), 'google' );
	assert.equal( fieldValue( win, '#plain [data-lct="first_gclid"]' ), 'abc123' );
	assert.equal( fieldValue( win, '#plain .lct-last_utm_campaign input' ), 'spring' );
	assert.ok( LCT.isValidEventId( fieldValue( win, '#plain [name="lct_event_id"]' ) ) );
} );

test( 'a form that is not in the selector gets no injected fields', () => {
	const win = makeWindow();
	LCT.create( win, { consentFallback: true } ).init();

	assert.equal( win.document.querySelectorAll( '#plain [data-lct-injected]' ).length, 0 );
	assert.equal( fieldValue( win, '#plain [name="email"]' ), 'person@example.com' );
} );

test( 'a form in the selector gets hidden fields for known values and an event id', () => {
	const win = makeWindow();
	LCT.create( win, { consentFallback: true } ).init();
	const form = win.document.querySelector( '#auto' );

	assert.equal( fieldValue( win, '#auto [name="lct_first_utm_source"]' ), 'google' );
	assert.equal( fieldValue( win, '#auto [name="lct_last_gclid"]' ), 'abc123' );
	assert.equal( fieldValue( win, '#auto [name="lct_last_landing_page"]' ), '/landing/' );
	assert.equal( form.querySelector( '[name="lct_last_fbclid"]' ), null );
	assert.equal( form.querySelector( '[name="lct_event_id"]' ).type, 'hidden' );
	assert.equal( fieldValue( win, '#auto [name="lct_event_id"]' ), form.getAttribute( 'data-lct-event-id' ) );
} );

test( 'filling twice does not duplicate injected fields', () => {
	const win = makeWindow();
	const lct = LCT.create( win, { consentFallback: true } );
	lct.init();
	lct.fillForms();

	assert.equal( win.document.querySelectorAll( '#auto [name="lct_event_id"]' ).length, 1 );
	assert.equal( win.document.querySelectorAll( '#auto [name="lct_first_utm_source"]' ).length, 1 );
} );

test( 'a form added after page load is filled when it is submitted', () => {
	const win = makeWindow();
	LCT.create( win, { consentFallback: true } ).init();

	const form = win.document.createElement( 'form' );
	form.setAttribute( 'data-lct-form', '' );
	win.document.body.appendChild( form );
	form.dispatchEvent( new win.Event( 'submit', { bubbles: true, cancelable: true } ) );

	assert.equal( form.querySelector( '[name="lct_last_utm_source"]' ).value, 'google' );
} );

/* Conversion event */

test( 'trackLead pushes one generate_lead event with the event id of the form', () => {
	const win = makeWindow();
	const lct = LCT.create( win, { consentFallback: true } );
	lct.init();

	const form = win.document.querySelector( '#auto' );
	const submittedId = form.querySelector( '[name="lct_event_id"]' ).value;
	const evt = lct.trackLead( form, { formId: 42, formType: 'cf7' } );

	assert.equal( win.dataLayer.length, 1 );
	assert.deepEqual( win.dataLayer[ 0 ], evt );
	assert.equal( evt.event, 'generate_lead' );
	assert.equal( evt.event_id, submittedId );
	assert.equal( evt.form_id, '42' );
	assert.equal( evt.form_type, 'cf7' );
	assert.equal( evt.attribution.first.utm_source, 'google' );
	assert.equal( evt.attribution.last.gclid, 'abc123' );
	assert.deepEqual( Object.keys( evt ).sort(), [ 'attribution', 'event', 'event_id', 'form_id', 'form_type' ] );
} );

test( 'the dataLayer event contains no form values', () => {
	const win = makeWindow();
	const lct = LCT.create( win, { consentFallback: true } );
	lct.init();

	lct.trackLead( win.document.querySelector( '#plain' ), { formId: 'plain', formType: 'custom' } );

	const json = JSON.stringify( win.dataLayer );
	assert.ok( ! json.includes( 'person@example.com' ) );
	assert.ok( ! json.includes( 'private' ) );
	assert.ok( ! json.includes( 'secret' ) );
} );

test( 'the form gets a new event id after a lead, and the same id is never pushed twice', () => {
	const win = makeWindow();
	const lct = LCT.create( win, { consentFallback: true } );
	lct.init();

	const form = win.document.querySelector( '#auto' );
	const firstId = form.querySelector( '[name="lct_event_id"]' ).value;

	assert.ok( lct.trackLead( form, { formId: 42, formType: 'cf7', eventId: firstId } ) );
	assert.equal( lct.trackLead( form, { formId: 42, formType: 'cf7', eventId: firstId } ), null );
	assert.equal( win.dataLayer.length, 1 );

	const secondId = form.querySelector( '[name="lct_event_id"]' ).value;
	assert.notEqual( secondId, firstId );
	assert.equal( lct.trackLead( form, { formId: 42, formType: 'cf7' } ).event_id, secondId );
	assert.equal( win.dataLayer.length, 2 );
} );

test( 'the Contact Form 7 wpcf7mailsent event triggers the push with the submitted id', () => {
	const win = makeWindow();
	LCT.create( win, { consentFallback: true } ).init();

	const form = win.document.querySelector( '#auto' );
	const submittedId = form.querySelector( '[name="lct_event_id"]' ).value;

	form.dispatchEvent(
		new win.CustomEvent( 'wpcf7mailsent', {
			bubbles: true,
			detail: {
				contactFormId: 123,
				inputs: [
					{ name: 'your-email', value: 'person@example.com' },
					{ name: 'lct_event_id', value: submittedId },
				],
			},
		} )
	);

	assert.equal( win.dataLayer.length, 1 );
	assert.equal( win.dataLayer[ 0 ].event_id, submittedId );
	assert.equal( win.dataLayer[ 0 ].form_id, '123' );
	assert.equal( win.dataLayer[ 0 ].form_type, 'cf7' );
	assert.ok( ! JSON.stringify( win.dataLayer ).includes( 'person@example.com' ) );
} );

test( 'the WPForms jQuery event triggers the push when jQuery is on the page', () => {
	const win = makeWindow( { html: '<form id="wpforms-form-9" class="wpforms-form" data-formid="9"></form>' } );
	const handlers = {};
	win.jQuery = () => ( { on: ( name, handler ) => ( handlers[ name ] = handler ) } );

	LCT.create( win, { consentFallback: true } ).init();
	const form = win.document.querySelector( 'form' );
	const submittedId = form.querySelector( '[name="lct_event_id"]' ).value;

	handlers.wpformsAjaxSubmitSuccess( { target: form } );

	assert.equal( win.dataLayer.length, 1 );
	assert.equal( win.dataLayer[ 0 ].event_id, submittedId );
	assert.equal( win.dataLayer[ 0 ].form_id, '9' );
	assert.equal( win.dataLayer[ 0 ].form_type, 'wpforms' );
} );

test( 'a pending server cookie is pushed once on page load and then removed', () => {
	const win = makeWindow( { url: 'https://example.com/thank-you/', referrer: 'https://example.com/contact/' } );
	setPendingCookie( win, { event_id: 'server-id-0001', form_id: '5', form_type: 'wpforms' } );

	const lct = LCT.create( win, { consentFallback: true } );
	lct.init();

	assert.equal( win.dataLayer.length, 1 );
	assert.equal( win.dataLayer[ 0 ].event_id, 'server-id-0001' );
	assert.equal( win.dataLayer[ 0 ].form_id, '5' );
	assert.equal( win.dataLayer[ 0 ].form_type, 'wpforms' );
	assert.ok( ! win.document.cookie.includes( 'lct_pending' ) );
	assert.equal( lct.flushPending(), null );
	assert.equal( win.dataLayer.length, 1 );
} );

test( 'the server id wins over the form id when both exist for the same form', () => {
	const win = makeWindow();
	const lct = LCT.create( win, { consentFallback: true } );
	lct.init();
	setPendingCookie( win, { event_id: 'server-id-0002', form_id: '42', form_type: 'cf7' } );

	const evt = lct.trackLead( win.document.querySelector( '#auto' ), { formId: 42, formType: 'cf7' } );

	assert.equal( evt.event_id, 'server-id-0002' );
	assert.ok( ! win.document.cookie.includes( 'lct_pending' ) );
} );

test( 'a pending cookie with an invalid event id is ignored', () => {
	const win = makeWindow();
	setPendingCookie( win, { event_id: '<bad>', form_id: '5', form_type: 'wpforms' } );

	LCT.create( win, { consentFallback: true } ).init();

	assert.equal( win.dataLayer, undefined );
} );

test( 'a lct:lead DOM event is dispatched with the same payload', () => {
	const win = makeWindow();
	const lct = LCT.create( win, { consentFallback: true } );
	lct.init();

	let received = null;
	win.document.addEventListener( 'lct:lead', ( event ) => ( received = event.detail ) );
	const evt = lct.trackLead( null, { formId: 'x', formType: 'custom' } );

	assert.deepEqual( received, evt );
} );
