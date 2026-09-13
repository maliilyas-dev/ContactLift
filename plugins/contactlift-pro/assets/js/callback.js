/**
 * ContactLift Pro — callback request dialog. Vanilla JS, no dependency on
 * jQuery or WooCommerce's own scripts (spec's performance budget). Loaded
 * only when a callback action is actually present on the page — see
 * Frontend\Assets.
 */
( function () {
	'use strict';

	function onReady( fn ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function focusableElements( root ) {
		var selector = 'a[href], button:not([disabled]), input:not([disabled]):not([tabindex="-1"]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
		return Array.prototype.slice.call( root.querySelectorAll( selector ) );
	}

	function initCallback() {
		var dialog = document.querySelector( '[data-clift-callback-dialog]' );

		if ( ! dialog ) {
			return;
		}

		var panel      = dialog.querySelector( '.clift-cb__panel' );
		var form       = dialog.querySelector( '[data-clift-callback-form]' );
		var statusEl   = dialog.querySelector( '[data-clift-callback-status]' );
		var submitBtn  = dialog.querySelector( '[data-clift-callback-submit]' );
		var restUrl    = dialog.getAttribute( 'data-rest-url' ) || '';
		var sourceType = dialog.getAttribute( 'data-source-type' ) || '';
		var sourceId   = dialog.getAttribute( 'data-source-id' ) || '';

		var lastFocused  = null;
		var challenge    = null;
		var challengeReq = null;

		function requestChallenge( barId, actionId ) {
			var body = {
				source_type: sourceType,
				bar_id: barId,
				action_id: actionId,
			};

			if ( sourceId ) {
				body.source_id = parseInt( sourceId, 10 );
			}

			challengeReq = fetch( restUrl + 'callback-challenge', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( body ),
			} )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( data ) {
					challenge = data;
					return data;
				} )
				.catch( function () {
					challenge = null;
					return null;
				} );

			return challengeReq;
		}

		function setStatus( message, isError ) {
			statusEl.textContent = message;
			statusEl.classList.toggle( 'is-error', !! isError );
		}

		function open( trigger ) {
			lastFocused = trigger;
			challenge   = null;

			form.hidden = false;
			form.reset();
			setStatus( '', false );
			submitBtn.disabled = false;

			dialog.hidden = false;
			document.body.classList.add( 'clift-cb-open' );

			requestChallenge( trigger.getAttribute( 'data-bar-id' ) || '', trigger.getAttribute( 'data-action-id' ) || '' );

			var focusables = focusableElements( panel );
			if ( focusables.length ) {
				focusables[ 0 ].focus();
			}
		}

		function close() {
			dialog.hidden = true;
			document.body.classList.remove( 'clift-cb-open' );

			if ( lastFocused ) {
				lastFocused.focus();
			}
		}

		document.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest ? event.target.closest( '[data-clift-callback]' ) : null;
			if ( trigger ) {
				open( trigger );
			}
		} );

		dialog.querySelectorAll( '[data-clift-callback-close]' ).forEach( function ( el ) {
			el.addEventListener( 'click', close );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( dialog.hidden ) {
				return;
			}

			if ( 'Escape' === event.key ) {
				close();
				return;
			}

			if ( 'Tab' !== event.key ) {
				return;
			}

			var focusables = focusableElements( panel );
			if ( ! focusables.length ) {
				return;
			}

			var first = focusables[ 0 ];
			var last  = focusables[ focusables.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( submitBtn.disabled ) {
				return; // double-submit guard.
			}

			submitBtn.disabled = true;
			setStatus( '', false );

			var data = new FormData( form );

			( challenge ? challengeReq : requestChallenge( '', '' ) )
				.then( function () {
					if ( ! challenge || ! challenge.token ) {
						throw new Error( 'no-challenge' );
					}

					return fetch( restUrl + 'callbacks', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( {
							token: challenge.token,
							name: data.get( 'name' ) || '',
							phone: data.get( 'phone' ) || '',
							email: data.get( 'email' ) || '',
							message: data.get( 'message' ) || '',
							consent: !! data.get( 'consent' ),
							website: data.get( 'website' ) || '',
						} ),
					} );
				} )
				.then( function ( response ) {
					if ( response.ok ) {
						form.hidden = true;
						setStatus( clift_cb_i18n.received, false );
						return;
					}

					return response.json().then( function ( body ) {
						throw new Error( body && body.message ? body.message : clift_cb_i18n.error );
					} );
				} )
				.catch( function ( error ) {
					setStatus( error && error.message && 'no-challenge' !== error.message ? error.message : clift_cb_i18n.error, true );
					submitBtn.disabled = false;
				} );
		} );
	}

	onReady( initCallback );
} )();
