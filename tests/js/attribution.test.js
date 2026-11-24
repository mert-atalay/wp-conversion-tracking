'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { LCT } = require( './helpers.js' );

const NOW = 1790000000000;

test( 'parseTouch reads UTM parameters and click ids', () => {
	const touch = LCT.parseTouch(
		'https://example.com/pricing/?utm_source=google&utm_medium=cpc&utm_campaign=spring&utm_term=crm&utm_content=ad1' +
			'&gclid=g1&gbraid=gb1&wbraid=wb1&fbclid=fb1&msclkid=ms1',
		'',
		NOW
	);

	assert.deepEqual( touch, {
		utm_source: 'google',
		utm_medium: 'cpc',
		utm_campaign: 'spring',
		utm_term: 'crm',
		utm_content: 'ad1',
		gclid: 'g1',
		gbraid: 'gb1',
		wbraid: 'wb1',
		fbclid: 'fb1',
		msclkid: 'ms1',
		landing_page: '/pricing/',
		ts: 1790000000,
	} );
} );

test( 'parseTouch drops unknown parameters and the query strings of landing page and referrer', () => {
	const touch = LCT.parseTouch(
		'https://example.com/contact?email=person%40example.com&utm_source=newsletter',
		'https://mail.example.org/inbox?token=abc',
		NOW
	);

	assert.equal( touch.utm_source, 'newsletter' );
	assert.equal( touch.landing_page, '/contact' );
	assert.equal( touch.referrer, 'https://mail.example.org/inbox' );
	assert.ok( ! JSON.stringify( touch ).includes( 'person' ) );
	assert.ok( ! JSON.stringify( touch ).includes( 'token' ) );
} );

test( 'parseTouch trims, decodes and caps values at 200 characters', () => {
	const touch = LCT.parseTouch( 'https://example.com/?utm_source=%20my+source%20&gclid=' + 'x'.repeat( 500 ), '', NOW );

	assert.equal( touch.utm_source, 'my source' );
	assert.equal( touch.gclid.length, 200 );
} );

test( 'parseTouch returns an empty object for an invalid URL and ignores a bad referrer', () => {
	assert.deepEqual( LCT.parseTouch( 'not a url', '', NOW ), {} );
	assert.equal( LCT.parseTouch( 'https://example.com/', 'nonsense', NOW ).referrer, undefined );
} );

test( 'hasCampaignData is true for UTM values and click ids only', () => {
	assert.equal( LCT.hasCampaignData( { utm_source: 'x' } ), true );
	assert.equal( LCT.hasCampaignData( { msclkid: 'x' } ), true );
	assert.equal( LCT.hasCampaignData( { landing_page: '/', referrer: 'https://a.test/', ts: 1 } ), false );
	assert.equal( LCT.hasCampaignData( null ), false );
} );

test( 'isExternalReferrer compares host names', () => {
	assert.equal( LCT.isExternalReferrer( 'https://www.google.com/', 'example.com' ), true );
	assert.equal( LCT.isExternalReferrer( 'https://example.com/about', 'example.com' ), false );
	assert.equal( LCT.isExternalReferrer( '', 'example.com' ), false );
	assert.equal( LCT.isExternalReferrer( 'nonsense', 'example.com' ), false );
} );

test( 'mergeTouches: the first visit sets both first and last touch', () => {
	const current = { utm_source: 'google', landing_page: '/', ts: 1 };

	assert.deepEqual( LCT.mergeTouches( null, current, 'example.com' ), { first: current, last: current } );
} );

test( 'mergeTouches: a later campaign visit replaces last touch and keeps first touch', () => {
	const first = { utm_source: 'google', landing_page: '/', ts: 1 };
	const current = { utm_source: 'newsletter', landing_page: '/offer', ts: 2 };
	const merged = LCT.mergeTouches( { first, last: first }, current, 'example.com' );

	assert.deepEqual( merged.first, first );
	assert.deepEqual( merged.last, current );
} );

test( 'mergeTouches: a direct or internal page view does not overwrite last touch', () => {
	const first = { utm_source: 'google', landing_page: '/', ts: 1 };
	const stored = { first, last: first };

	const direct = { landing_page: '/contact', ts: 2 };
	const internal = { landing_page: '/contact', referrer: 'https://example.com/', ts: 3 };

	assert.deepEqual( LCT.mergeTouches( stored, direct, 'example.com' ).last, first );
	assert.deepEqual( LCT.mergeTouches( stored, internal, 'example.com' ).last, first );
} );

test( 'mergeTouches: an external referrer without parameters counts as a new last touch', () => {
	const first = { utm_source: 'google', landing_page: '/', ts: 1 };
	const current = { landing_page: '/blog', referrer: 'https://news.example.org/story', ts: 2 };

	assert.deepEqual( LCT.mergeTouches( { first, last: first }, current, 'example.com' ).last, current );
} );

test( 'flattenAttribution gives every first_ and last_ key, empty when unknown', () => {
	const flat = LCT.flattenAttribution( { first: { utm_source: 'a', ts: 5 }, last: { gclid: 'b' } } );

	assert.equal( flat.first_utm_source, 'a' );
	assert.equal( flat.first_ts, '5' );
	assert.equal( flat.last_gclid, 'b' );
	assert.equal( flat.last_utm_source, '' );
	assert.equal( Object.keys( flat ).length, LCT.TOUCH_KEYS.length * 2 );
	assert.equal( LCT.flattenAttribution( null ).first_utm_source, '' );
} );

test( 'generateEventId returns valid, distinct ids, also without crypto.randomUUID', () => {
	const a = LCT.generateEventId();
	const b = LCT.generateEventId();
	const fallback = LCT.generateEventId( {} );

	assert.ok( LCT.isValidEventId( a ) );
	assert.notEqual( a, b );
	assert.match( fallback, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/ );
} );

test( 'isValidEventId rejects short, long and unsafe values', () => {
	assert.equal( LCT.isValidEventId( 'abc' ), false );
	assert.equal( LCT.isValidEventId( 'a'.repeat( 65 ) ), false );
	assert.equal( LCT.isValidEventId( '<script>alert(1)</script>' ), false );
	assert.equal( LCT.isValidEventId( 12345678 ), false );
} );
