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

	function fetchRuntimeContext() {
		if ( null !== runtimeContext ) {
			return Promise.resolve( runtimeContext );
		}

		var params = new URLSearchParams( {
			source_type: clift_tracking_i18n.sourceType,
			source_id: clift_tracking_i18n.sourceId || '',
			device: deviceBucket(),
		} );

		return fetch( clift_tracking_i18n.restUrl + 'runtime?' + params.toString(), { credentials: 'omit' } )
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
			if ( ! ctx || ! ctx.context ) {
				return;
			}

			var body = JSON.stringify( { context: ctx.context, action_id: actionId, device: deviceBucket() } );
			var url  = clift_tracking_i18n.restUrl + 'events';

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
