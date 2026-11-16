/**
 * ContactLift Pro — local click reporting. "Use sendBeacon or keepalive
 * fetch without delaying navigation. Reporting failure must never block
 * contact." (spec) — every failure here is swallowed silently; a contact
 * link always works whether or not its click was counted.
 */
( function () {
	'use strict';

	window.ContactLift = window.ContactLift || {};

	var consentGiven   = false;
	var runtimeContext = null;
	var lastSentAt      = {};
	var DEBOUNCE_MS      = 1500;

	function onReady( fn ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function deviceBucket() {
		return window.matchMedia && window.matchMedia( '(min-width: 768px)' ).matches ? 'desktop' : 'mobile';
	}

	function globalPrivacyControl() {
		return !! ( navigator.globalPrivacyControl );
	}

	/**
	 * "Consent required waits for the site integration to call
	 * window.ContactLift.setAnalyticsConsent(true); default is denied and
	 * revocation stops subsequent events." (spec)
	 */
	window.ContactLift.setAnalyticsConsent = function ( granted ) {
		consentGiven = !! granted;
	};

	function shouldTrack() {
		if ( 'off' === clift_tracking_i18n.mode ) {
			return false;
		}

		if ( globalPrivacyControl() ) {
			return false;
		}

		if ( 'consent_required' === clift_tracking_i18n.mode && ! consentGiven ) {
			return false;
		}

		return true;
	}

	/**
	 * rest_url() is ".../wp-json/contactlift/v1/" with pretty permalinks but
	 * ".../index.php?rest_route=/contactlift/v1/" with plain ones — that form
	 * already contains a query string, so extra arguments have to be added
	 * through URL, never by appending "?..." to the string (doing so folded
	 * them into the rest_route value and every request 404'd).
	 */
	function restEndpoint( route, query ) {
		var url = new URL( clift_tracking_i18n.restUrl + route, window.location.href );

		Object.keys( query || {} ).forEach( function ( key ) {
			url.searchParams.set( key, query[ key ] );
		} );

		return url.toString();
	}

	function fetchRuntimeContext() {
		if ( null !== runtimeContext ) {
			return Promise.resolve( runtimeContext );
		}

		var query = {
			source_type: clift_tracking_i18n.sourceType,
			device: deviceBucket(),
		};

		// Home and archive pages have no content ID. The route types
		// source_id as an integer, so an empty "source_id=" is rejected with
		// a 400 — leave the argument out entirely instead.
		if ( clift_tracking_i18n.sourceId ) {
			query.source_id = clift_tracking_i18n.sourceId;
		}

		return fetch( restEndpoint( 'runtime', query ), { credentials: 'omit' } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				runtimeContext = data && data.context ? data : false;
				return runtimeContext;
			} )
			.catch( function () {
				runtimeContext = false;
				return false;
			} );
	}

	function sendEvent( actionId ) {
		if ( ! actionId || ! shouldTrack() ) {
			return;
		}

		var now = Date.now();
		if ( lastSentAt[ actionId ] && ( now - lastSentAt[ actionId ] ) < DEBOUNCE_MS ) {
			return;
		}
		lastSentAt[ actionId ] = now;

		fetchRuntimeContext().then( function ( ctx ) {
			// Consent can be revoked while the runtime context is loading;
			// "revocation stops subsequent events" has to win over a send
			// that was already queued by an earlier click.
			if ( ! ctx || ! ctx.context || ! shouldTrack() ) {
				return;
			}

			var body = JSON.stringify( { context: ctx.context, action_id: actionId, device: deviceBucket() } );
			var url  = restEndpoint( 'events' );

			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( url, new Blob( [ body ], { type: 'application/json' } ) );
			} else {
				fetch( url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'omit' } ).catch( function () {} );
			}
		} );
	}

	function init() {
		document.addEventListener( 'click', function ( event ) {
			var el = event.target.closest ? event.target.closest( '[data-action-id]' ) : null;

			if ( el ) {
				sendEvent( el.getAttribute( 'data-action-id' ) );
			}
		} );
	}

	onReady( init );
} )();
