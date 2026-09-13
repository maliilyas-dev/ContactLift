/**
 * ContactLift frontend — desktop launcher open/close only. Every action is
 * an ordinary link; nothing here is required for a visitor to place a call,
 * open WhatsApp, send an email or follow a link.
 */
( function () {
	'use strict';

	function initLauncher( root ) {
		var launcher = root.querySelector( '.clift-launcher' );
		var panel    = root.querySelector( '.clift-panel' );

		if ( ! launcher || ! panel ) {
			return;
		}

		function open() {
			panel.hidden = false;
			launcher.setAttribute( 'aria-expanded', 'true' );
		}

		function close( restoreFocus ) {
			panel.hidden = true;
			launcher.setAttribute( 'aria-expanded', 'false' );

			if ( restoreFocus ) {
				launcher.focus();
			}
		}

		launcher.addEventListener( 'click', function () {
			if ( panel.hidden ) {
				open();
			} else {
				close( false );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! panel.hidden ) {
				close( true );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			// [data-clift-overlay]: a full-viewport overlay (e.g. Pro's
			// callback dialog) that renders as a sibling of .clift-desktop,
			// not a descendant — deliberately, since nesting a modal inside
			// the bar's own small positioning context would be wrong — so a
			// click anywhere inside one must not count as "outside the bar."
			var inOverlay = !! ( event.target.closest && event.target.closest( '[data-clift-overlay]' ) );

			if ( ! panel.hidden && ! root.contains( event.target ) && ! inOverlay ) {
				close( false );
			}
		} );
	}

	function init() {
		var roots = document.querySelectorAll( '.clift-desktop' );

		for ( var i = 0; i < roots.length; i++ ) {
			initLauncher( roots[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
