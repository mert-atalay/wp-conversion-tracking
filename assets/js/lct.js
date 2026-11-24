/**
 * Lead Conversion Tracking: front-end script.
 *
 * Captures campaign attribution after consent, fills hidden form fields and
 * pushes one generate_lead event per successful submission to the dataLayer.
 * No dependencies. Works as a plain script tag and as a CommonJS module (tests).
 */
( function ( root, factory ) {
	'use strict';

	var api = factory();

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}

	if ( root && root.document ) {
		root.LCT = api;
		api.autoInit( root );
	}
} )( typeof window !== 'undefined' ? window : undefined, function () {
	'use strict';

	var PARAMS = [
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'gclid',
		'gbraid',
		'wbraid',
		'fbclid',
		'msclkid',
	];
	var TOUCH_KEYS = PARAMS.concat( [ 'landing_page', 'referrer', 'ts' ] );
	var TOUCHES = [ 'first', 'last' ];
	var MAX_LENGTH = 200;
	var MAX_COOKIE_BYTES = 3800;
	var EVENT_ID_PATTERN = /^[A-Za-z0-9_-]{8,64}$/;

	var DEFAULTS = {
		cookieName: 'lct_attr',
		pendingCookie: 'lct_pending',
		cookieDays: 90,
		eventName: 'generate_lead',
		consentCategory: 'marketing',
		consentApi: false,
		consentFallback: false,
		formSelector: 'form.wpcf7-form, form.wpforms-form, form[data-lct-form]',
	};

	/* ---------- Pure helpers ---------- */

	function clean( value ) {
		return String( value )
			.replace( /[\u0000-\u001f\u007f]/g, '' )
			.trim()
			.slice( 0, MAX_LENGTH );
	}

	/**
	 * Builds a touch from a page URL and a referrer.
	 *
	 * Query strings of the landing page and the referrer are dropped on
	 * purpose. Only the known campaign parameters are kept.
	 */
	function parseTouch( href, referrer, nowMs ) {
		var touch = {};
		var url;

		try {
			url = new URL( href );
		} catch ( e ) {
			return touch;
		}

		PARAMS.forEach( function ( key ) {
			var value = clean( url.searchParams.get( key ) || '' );
			if ( value ) {
				touch[ key ] = value;
			}
		} );

		touch.landing_page = clean( url.pathname );

		if ( referrer ) {
			try {
				var ref = new URL( referrer );
				touch.referrer = clean( ref.origin + ref.pathname );
			} catch ( e ) {
				// Ignore an unparsable referrer.
			}
		}

		touch.ts = Math.floor( ( nowMs || Date.now() ) / 1000 );

		return touch;
	}

	function hasCampaignData( touch ) {
		return PARAMS.some( function ( key ) {
			return Boolean( touch && touch[ key ] );
		} );
	}

	function isExternalReferrer( referrer, host ) {
		if ( ! referrer ) {
			return false;
		}

		try {
			return new URL( referrer ).hostname !== host;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * First touch is written once. Last touch is replaced only by a visit that
	 * carries campaign parameters, a click id or an external referrer, so a
	 * direct return visit does not erase the last known source.
	 */
	function mergeTouches( stored, current, host ) {
		var first = ( stored && stored.first ) || null;
		var last = ( stored && stored.last ) || null;

		if ( ! first ) {
			first = current;
		}

		if ( ! last || hasCampaignData( current ) || isExternalReferrer( current.referrer, host ) ) {
			last = current;
		}

		return { first: first, last: last };
	}

	function copyTouch( touch ) {
		var out = {};

		TOUCH_KEYS.forEach( function ( key ) {
			if ( touch && touch[ key ] !== undefined && touch[ key ] !== '' && touch[ key ] !== null ) {
				out[ key ] = key === 'ts' ? Number( touch[ key ] ) || 0 : clean( touch[ key ] );
			}
		} );

		return out;
	}

	/** Returns { first_utm_source: '...', last_gclid: '...', ... } for form fields. */
	function flattenAttribution( attribution ) {
		var flat = {};

		TOUCHES.forEach( function ( touch ) {
			TOUCH_KEYS.forEach( function ( key ) {
				var value = attribution && attribution[ touch ] ? attribution[ touch ][ key ] : '';
				flat[ touch + '_' + key ] = value === undefined || value === null ? '' : String( value );
			} );
		} );

		return flat;
	}

	function generateEventId( cryptoObj ) {
		var c = cryptoObj || ( typeof crypto !== 'undefined' ? crypto : null );

		if ( c && typeof c.randomUUID === 'function' ) {
			return c.randomUUID();
		}

		var bytes = [];
		var i;

		if ( c && typeof c.getRandomValues === 'function' ) {
			bytes = Array.prototype.slice.call( c.getRandomValues( new Uint8Array( 16 ) ) );
		} else {
			for ( i = 0; i < 16; i++ ) {
				bytes.push( Math.floor( Math.random() * 256 ) );
			}
		}

		bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
		bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;

		var hex = bytes.map( function ( b ) {
			return ( b + 0x100 ).toString( 16 ).slice( 1 );
		} );

		return [
			hex.slice( 0, 4 ).join( '' ),
			hex.slice( 4, 6 ).join( '' ),
			hex.slice( 6, 8 ).join( '' ),
			hex.slice( 8, 10 ).join( '' ),
			hex.slice( 10, 16 ).join( '' ),
		].join( '-' );
	}

	function isValidEventId( value ) {
		return typeof value === 'string' && EVENT_ID_PATTERN.test( value );
	}

	/** The dataLayer event. It carries ids and attribution only, never form values. */
	function buildLeadEvent( eventName, eventId, formId, formType, attribution ) {
		return {
			event: eventName,
			event_id: eventId,
			form_id: String( formId === undefined || formId === null ? '' : formId ),
			form_type: String( formType || 'custom' ),
			attribution: {
				first: copyTouch( attribution && attribution.first ),
				last: copyTouch( attribution && attribution.last ),
			},
		};
	}

	/* ---------- Instance bound to one window ---------- */

	function create( win, userConfig ) {
		var doc = win.document;
		var cfg = {};
		var consentOverride = null;
		var currentTouch = null;
		var pushed = {};
		var started = false;

		Object.keys( DEFAULTS ).forEach( function ( key ) {
			cfg[ key ] = userConfig && userConfig[ key ] !== undefined ? userConfig[ key ] : DEFAULTS[ key ];
		} );

		/* Consent */

		function hasConsent() {
			if ( typeof win.wp_has_consent === 'function' ) {
				return Boolean( win.wp_has_consent( cfg.consentCategory ) );
			}

			if ( cfg.consentApi ) {
				// The site uses the WP Consent API but its script is not on this page. Stay silent.
				return false;
			}

			if ( consentOverride !== null ) {
				return consentOverride;
			}

			return Boolean( cfg.consentFallback );
		}

		/* Cookie and localStorage */

		function readCookie( name ) {
			var parts = ( doc.cookie || '' ).split( '; ' );
			var i;

			for ( i = 0; i < parts.length; i++ ) {
				if ( parts[ i ].indexOf( name + '=' ) === 0 ) {
					try {
						return decodeURIComponent( parts[ i ].slice( name.length + 1 ) );
					} catch ( e ) {
						return '';
					}
				}
			}

			return '';
		}

		function writeCookie( name, value, maxAgeSeconds ) {
			doc.cookie =
				name +
				'=' +
				encodeURIComponent( value ) +
				'; path=/; max-age=' +
				Math.max( 0, Math.floor( maxAgeSeconds ) ) +
				'; SameSite=Lax' +
				( win.location.protocol === 'https:' ? '; Secure' : '' );
		}

		function readLocal() {
			try {
				return win.localStorage.getItem( cfg.cookieName ) || '';
			} catch ( e ) {
				return '';
			}
		}

		function parseRecord( json ) {
			var record;

			if ( ! json ) {
				return null;
			}

			try {
				record = JSON.parse( json );
			} catch ( e ) {
				return null;
			}

			if ( ! record || typeof record !== 'object' || ! record.first || ! record.exp || record.exp <= Date.now() ) {
				return null;
			}

			return { first: copyTouch( record.first ), last: copyTouch( record.last || record.first ), exp: Number( record.exp ) };
		}

		function readStore() {
			return parseRecord( readCookie( cfg.cookieName ) ) || parseRecord( readLocal() );
		}

		function writeStore( record ) {
			var json = JSON.stringify( record );
			var maxAge = ( record.exp - Date.now() ) / 1000;

			if ( encodeURIComponent( json ).length <= MAX_COOKIE_BYTES ) {
				writeCookie( cfg.cookieName, json, maxAge );
			}

			try {
				win.localStorage.setItem( cfg.cookieName, json );
			} catch ( e ) {
				// Storage can be blocked. The cookie is enough.
			}
		}

		function clearStore() {
			writeCookie( cfg.cookieName, '', 0 );
			writeCookie( cfg.pendingCookie, '', 0 );

			try {
				win.localStorage.removeItem( cfg.cookieName );
			} catch ( e ) {
				// Nothing to remove.
			}
		}

		/* Attribution */

		function getCurrentTouch() {
			if ( ! currentTouch ) {
				// Kept in memory only. It is written to storage once consent is given.
				currentTouch = parseTouch( win.location.href, doc.referrer, Date.now() );
			}

			return currentTouch;
		}

		/** Merges this page view into the stored record. Returns null without consent. */
		function capture() {
			if ( ! hasConsent() ) {
				return null;
			}

			var stored = readStore();
			var merged = mergeTouches( stored, getCurrentTouch(), win.location.hostname );
			var days = Number( cfg.cookieDays ) > 0 ? Number( cfg.cookieDays ) : DEFAULTS.cookieDays;
			var record = {
				first: merged.first,
				last: merged.last,
				// The lifetime runs from the first touch and is not extended by later visits.
				exp: stored ? stored.exp : Date.now() + days * 86400000,
			};

			writeStore( record );

			return { first: record.first, last: record.last };
		}

		function getAttribution() {
			var stored;

			if ( ! hasConsent() ) {
				return null;
			}

			stored = readStore();

			return stored ? { first: stored.first, last: stored.last } : null;
		}

		/* Forms */

		function fieldSelector( key ) {
			return '[name="lct_' + key + '"], [data-lct="' + key + '"], .lct-' + key;
		}

		function setFieldValue( el, value ) {
			var tag = el.tagName ? el.tagName.toLowerCase() : '';
			var target = tag === 'input' || tag === 'textarea' ? el : el.querySelector( 'input, textarea' );

			if ( target ) {
				target.value = value;
			}

			return Boolean( target );
		}

		function formEventId( form ) {
			var id = form.getAttribute( 'data-lct-event-id' );

			if ( ! isValidEventId( id ) ) {
				id = generateEventId( win.crypto );
				form.setAttribute( 'data-lct-event-id', id );
			}

			return id;
		}

		/**
		 * Fills the fields of one form. A field is matched by
		 *   name="lct_<key>", data-lct="<key>" or class="lct-<key>"
		 * where the class may also sit on a wrapper around the input.
		 * Keys are event_id and first_/last_ plus a touch key, e.g. last_utm_source.
		 * With inject = true, missing fields are added as hidden inputs.
		 */
		function fillForm( form, inject ) {
			var values;

			if ( ! form || ! hasConsent() ) {
				return false;
			}

			values = flattenAttribution( getAttribution() );
			values.event_id = formEventId( form );

			Object.keys( values ).forEach( function ( key ) {
				var value = values[ key ];
				var nodes = form.querySelectorAll( fieldSelector( key ) );
				var filled = false;
				var input;

				Array.prototype.forEach.call( nodes, function ( node ) {
					filled = setFieldValue( node, value ) || filled;
				} );

				if ( ! filled && inject && value !== '' ) {
					input = doc.createElement( 'input' );
					input.type = 'hidden';
					input.name = 'lct_' + key;
					input.value = value;
					input.setAttribute( 'data-lct-injected', '1' );
					form.appendChild( input );
				}
			} );

			return true;
		}

		function shouldInject( form ) {
			try {
				return Boolean( cfg.formSelector ) && form.matches( cfg.formSelector );
			} catch ( e ) {
				return false;
			}
		}

		function fillForms() {
			Array.prototype.forEach.call( doc.querySelectorAll( 'form' ), function ( form ) {
				fillForm( form, shouldInject( form ) );
			} );
		}

		function emptyForms() {
			var keys = Object.keys( flattenAttribution( null ) ).concat( [ 'event_id' ] );

			Array.prototype.forEach.call( doc.querySelectorAll( 'form' ), function ( form ) {
				keys.forEach( function ( key ) {
					var nodes = form.querySelectorAll( fieldSelector( key ) );

					Array.prototype.forEach.call( nodes, function ( node ) {
						if ( node.getAttribute( 'data-lct-injected' ) ) {
							node.parentNode.removeChild( node );
						} else {
							setFieldValue( node, '' );
						}
					} );
				} );

				form.removeAttribute( 'data-lct-event-id' );
			} );
		}

		/* Conversion event */

		function readPending() {
			var pending;

			try {
				pending = JSON.parse( readCookie( cfg.pendingCookie ) || 'null' );
			} catch ( e ) {
				pending = null;
			}

			return pending && isValidEventId( pending.event_id ) ? pending : null;
		}

		/**
		 * Pushes the lead event once per event id.
		 *
		 * The event id is taken, in this order, from: the cookie the server set
		 * for this submission, info.eventId, the hidden field of the form, or a
		 * new id. Returns the pushed event, or null (no consent or duplicate).
		 */
		function trackLead( form, info ) {
			var pending;
			var eventId;
			var formId;
			var evt;

			info = info || {};

			if ( ! hasConsent() ) {
				return null;
			}

			formId = info.formId === undefined || info.formId === null ? '' : String( info.formId );
			pending = readPending();

			if ( pending && String( pending.form_id ) === formId ) {
				eventId = pending.event_id;
				writeCookie( cfg.pendingCookie, '', 0 );
			} else if ( isValidEventId( info.eventId ) ) {
				eventId = info.eventId;
			} else if ( form ) {
				eventId = formEventId( form );
			} else {
				eventId = generateEventId( win.crypto );
			}

			if ( form ) {
				// The next submission of the same form needs a new id.
				form.setAttribute( 'data-lct-event-id', generateEventId( win.crypto ) );
				fillForm( form, shouldInject( form ) );
			}

			if ( pushed[ eventId ] ) {
				return null;
			}
			pushed[ eventId ] = true;

			evt = buildLeadEvent( cfg.eventName, eventId, formId, info.formType, getAttribution() );

			win.dataLayer = win.dataLayer || [];
			win.dataLayer.push( evt );

			doc.dispatchEvent( new win.CustomEvent( 'lct:lead', { detail: evt } ) );

			return evt;
		}

		/** Pushes the event of a submission that ended in a full page load. */
		function flushPending() {
			var pending = readPending();

			if ( ! pending || ! hasConsent() ) {
				return null;
			}

			return trackLead( null, { formId: pending.form_id, formType: pending.form_type } );
		}

		/* Adapters */

		function onContactForm7( event ) {
			var detail = event.detail || {};
			var eventId = '';

			( detail.inputs || [] ).forEach( function ( input ) {
				if ( input && input.name === 'lct_event_id' ) {
					eventId = input.value;
				}
			} );

			trackLead( event.target && event.target.tagName === 'FORM' ? event.target : null, {
				formId: detail.contactFormId,
				formType: 'cf7',
				eventId: eventId,
			} );
		}

		function onWPForms( event ) {
			var form = event.target;

			if ( form && form.getAttribute ) {
				trackLead( form, { formId: form.getAttribute( 'data-formid' ) || '', formType: 'wpforms' } );
			}
		}

		/* Lifecycle */

		function onGranted() {
			capture();
			fillForms();
			flushPending();
		}

		function onRevoked() {
			clearStore();
			emptyForms();
		}

		function init() {
			if ( started ) {
				return;
			}
			started = true;

			getCurrentTouch();

			// WP Consent API: detail is { <category>: 'allow' | 'deny' }.
			doc.addEventListener( 'wp_listen_for_consent_change', function ( event ) {
				var detail = event.detail || {};

				if ( Object.prototype.hasOwnProperty.call( detail, cfg.consentCategory ) ) {
					if ( detail[ cfg.consentCategory ] === 'allow' && hasConsent() ) {
						onGranted();
					} else if ( detail[ cfg.consentCategory ] !== 'allow' ) {
						onRevoked();
					}
				}
			} );

			// For consent tools without the WP Consent API:
			// document.dispatchEvent( new CustomEvent( 'lct:consent', { detail: { granted: true } } ) );
			doc.addEventListener( 'lct:consent', function ( event ) {
				consentOverride = Boolean( event.detail && event.detail.granted );

				if ( hasConsent() ) {
					onGranted();
				} else {
					onRevoked();
				}
			} );

			// Refill right before a submit so forms added after page load are covered.
			doc.addEventListener(
				'submit',
				function ( event ) {
					if ( event.target && event.target.tagName === 'FORM' ) {
						fillForm( event.target, shouldInject( event.target ) );
					}
				},
				true
			);

			// Contact Form 7 dispatches a DOM event on the form after the mail was sent.
			doc.addEventListener( 'wpcf7mailsent', onContactForm7 );

			// WPForms triggers a jQuery event after an AJAX submit. jQuery events do not
			// reach addEventListener, so jQuery is used here when the page has it.
			if ( typeof win.jQuery === 'function' ) {
				win.jQuery( doc ).on( 'wpformsAjaxSubmitSuccess', onWPForms );
			}

			if ( hasConsent() ) {
				onGranted();
			}
		}

		return {
			init: init,
			hasConsent: hasConsent,
			capture: capture,
			getAttribution: getAttribution,
			fillForm: fillForm,
			fillForms: fillForms,
			trackLead: trackLead,
			flushPending: flushPending,
			clear: onRevoked,
		};
	}

	/** Starts the default instance when the plugin printed window.lctConfig. */
	function autoInit( win ) {
		var instance;

		if ( ! win.lctConfig || win.LCT.instance ) {
			return;
		}

		instance = create( win, win.lctConfig );
		win.LCT.instance = instance;

		if ( win.document.readyState === 'loading' ) {
			win.document.addEventListener( 'DOMContentLoaded', instance.init );
		} else {
			instance.init();
		}
	}

	return {
		PARAMS: PARAMS,
		TOUCH_KEYS: TOUCH_KEYS,
		parseTouch: parseTouch,
		hasCampaignData: hasCampaignData,
		isExternalReferrer: isExternalReferrer,
		mergeTouches: mergeTouches,
		flattenAttribution: flattenAttribution,
		generateEventId: generateEventId,
		isValidEventId: isValidEventId,
		buildLeadEvent: buildLeadEvent,
		create: create,
		autoInit: autoInit,
	};
} );
