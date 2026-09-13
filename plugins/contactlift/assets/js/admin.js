/**
 * ContactLift admin — bar editor interactivity: the action repeater
 * (add/remove/reorder, per-channel field visibility), colour field sync,
 * the live preview panel, and an unsaved-changes guard. Vanilla JS, no
 * build step, scoped entirely to elements the PHP templates render.
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

	/* ------------------------------------------------------- Action rows */

	function toggleChannelFields( row ) {
		var typeSelect = row.querySelector( '[data-action-type]' );

		if ( ! typeSelect ) {
			return;
		}

		var type = typeSelect.value;

		row.querySelectorAll( '[data-channel-fields]' ).forEach( function ( el ) {
			var allowed = el.getAttribute( 'data-channel-fields' ).split( ',' );
			var hide    = -1 === allowed.indexOf( type );

			el.hidden = hide;

			// Several channels share a field name (e.g. call/whatsapp both
			// post to actions[i][destination]) — a hidden-but-still-enabled
			// duplicate would still submit and silently clobber the visible
			// one, since same-named fields resolve to whichever comes last
			// in the request body. Disabling excludes it from submission.
			el.querySelectorAll( 'input, select, textarea' ).forEach( function ( field ) {
				field.disabled = hide;
			} );
		} );
	}

	function initActionRepeater() {
		var list     = document.getElementById( 'clift-actions' );
		var template = document.getElementById( 'clift-action-template' );
		var addBtn   = document.getElementById( 'clift-add-action' );

		if ( ! list || ! template || ! addBtn ) {
			return;
		}

		function rows() {
			return list.querySelectorAll( '[data-action-row]' );
		}

		function initRow( row ) {
			toggleChannelFields( row );
		}

		rows().forEach( initRow );

		addBtn.addEventListener( 'click', function () {
			var max = parseInt( addBtn.getAttribute( 'data-max' ), 10 ) || 4;

			if ( rows().length >= max ) {
				return;
			}

			var uid  = 'n' + Date.now().toString( 36 ) + Math.floor( Math.random() * 1000 );
			var html = template.innerHTML.split( '__INDEX__' ).join( uid );
			var wrap = document.createElement( 'div' );
			wrap.innerHTML = html.trim();

			var row = wrap.firstElementChild;
			list.appendChild( row );
			initRow( row );
			renderPreview();
		} );

		list.addEventListener( 'click', function ( event ) {
			var removeBtn = event.target.closest( '[data-action-remove]' );
			var moveBtn   = event.target.closest( '[data-action-move]' );

			if ( removeBtn ) {
				var row = removeBtn.closest( '[data-action-row]' );
				if ( row ) {
					row.remove();
					renderPreview();
				}
				return;
			}

			if ( moveBtn ) {
				var current = moveBtn.closest( '[data-action-row]' );
				if ( ! current ) {
					return;
				}

				if ( 'up' === moveBtn.getAttribute( 'data-action-move' ) ) {
					var prev = current.previousElementSibling;
					if ( prev ) {
						list.insertBefore( current, prev );
					}
				} else {
					var next = current.nextElementSibling;
					if ( next ) {
						list.insertBefore( next, current );
					}
				}

				renderPreview();
			}
		} );

		list.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-action-type]' ) ) {
				toggleChannelFields( event.target.closest( '[data-action-row]' ) );
			}
		} );
	}

	/* --------------------------------------------------------- Colours */

	function initColorFields() {
		document.querySelectorAll( '[data-preview-color]' ).forEach( function ( colorInput ) {
			var key = colorInput.getAttribute( 'data-preview-color' );
			var textInput = document.querySelector( '[data-color-text="' + key + '"]' );

			if ( ! textInput ) {
				return;
			}

			colorInput.addEventListener( 'input', function () {
				textInput.value = colorInput.value;
			} );

			textInput.addEventListener( 'input', function () {
				if ( /^#[0-9a-fA-F]{6}$/.test( textInput.value ) ) {
					colorInput.value = textInput.value;
				}
			} );
		} );
	}

	/* ------------------------------------------------------------ Pickers */

	function initPickers() {
		document.querySelectorAll( '[data-clift-picker]' ).forEach( function ( picker ) {
			var search = picker.querySelector( '[data-clift-picker-search]' );
			var list   = picker.querySelector( '[data-clift-picker-list]' );
			var count  = picker.querySelector( '[data-clift-picker-count]' );

			function rows() {
				return list ? list.querySelectorAll( '[data-clift-picker-row]' ) : [];
			}

			function updateRowState( row ) {
				var input = row.querySelector( 'input[type="checkbox"]' );
				row.classList.toggle( 'is-checked', !! ( input && input.checked ) );
			}

			function updateCount() {
				if ( ! count ) {
					return;
				}

				var checked  = list ? list.querySelectorAll( 'input[type="checkbox"]:checked' ).length : 0;
				var template = 1 === checked ? count.getAttribute( 'data-label-one' ) : count.getAttribute( 'data-label-other' );

				count.textContent = ( template || '' ).replace( '%d', String( checked ) );
			}

			rows().forEach( updateRowState );
			updateCount();

			if ( list ) {
				list.addEventListener( 'change', function ( event ) {
					var row = event.target.closest( '[data-clift-picker-row]' );

					if ( row ) {
						updateRowState( row );
					}

					updateCount();
				} );
			}

			if ( search ) {
				search.addEventListener( 'input', function () {
					var term = search.value.trim().toLowerCase();

					rows().forEach( function ( row ) {
						var haystack = row.getAttribute( 'data-search' ) || '';
						row.hidden = '' !== term && -1 === haystack.indexOf( term );
					} );
				} );
			}
		} );
	}

	/* ----------------------------------------------------------- Preview */

	var CHANNEL_ICON = {
		call: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
		whatsapp: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
		email: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="m22 6-10 7L2 6"/></svg>',
		link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
		chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>'
	};

	var DEFAULT_LABEL = { call: 'Call us', whatsapp: 'WhatsApp', email: 'Email us', link: 'Visit link' };

	function collectPreviewActions() {
		var actions = [];

		document.querySelectorAll( '#clift-actions [data-action-row]' ).forEach( function ( row ) {
			var typeEl  = row.querySelector( '[data-action-type]' );
			var labelEl = row.querySelector( '[data-preview-label]' );
			var enabledEl = row.querySelector( '[data-preview-enabled]' );

			if ( ! typeEl || ! enabledEl || ! enabledEl.checked ) {
				return;
			}

			actions.push( {
				type: typeEl.value,
				label: ( labelEl && labelEl.value.trim() ) || DEFAULT_LABEL[ typeEl.value ] || 'Contact us'
			} );
		} );

		return actions.slice( 0, 4 );
	}

	function actionHtml( action, primary ) {
		var icon = CHANNEL_ICON[ action.type ] || CHANNEL_ICON.chat;

		return (
			'<span class="clift-action' + ( primary ? ' clift-action--primary' : '' ) + '">' +
			'<span class="clift-action__icon">' + icon + '</span>' +
			'<span class="clift-action__label">' + escapeHtml( action.label ) + '</span>' +
			'</span>'
		);
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	function styleVars() {
		var get = function ( name, fallback ) {
			var el = document.querySelector( '[name="' + name + '"]' );
			return el && el.value ? el.value : fallback;
		};

		return [
			'--clift-bg:' + get( 'background', '#ffffff' ),
			'--clift-text:' + get( 'text', '#0b1d33' ),
			'--clift-accent:' + get( 'accent', '#ff5c8a' )
		].join( ';' );
	}

	function renderPreview() {
		var page = document.querySelector( '#clift-preview-frame .clift-a-preview__page' );

		if ( ! page ) {
			return;
		}

		var device  = ( document.querySelector( '[name="clift_preview_device"]:checked' ) || {} ).value || 'mobile';
		var frame   = document.getElementById( 'clift-preview-frame' );
		var actions = collectPreviewActions();
		var side    = ( document.querySelector( '[name="desktop_side"]:checked' ) || {} ).value || 'right';

		if ( frame ) {
			frame.classList.toggle( 'is-mobile', 'mobile' === device );
			frame.classList.toggle( 'is-desktop', 'desktop' === device );
		}

		if ( 0 === actions.length ) {
			page.innerHTML = '<div class="clift-a-empty" style="padding-top:60px;"><p>' + 'Enable at least one action to preview it here.' + '</p></div>';
			return;
		}

		var html = '<div class="clift" style="' + styleVars() + '">';

		if ( 'mobile' === device ) {
			html += '<nav class="clift-mobile">';
			actions.forEach( function ( action ) {
				html += actionHtml( action, false );
			} );
			html += '</nav>';
		} else {
			html += '<div class="clift-desktop clift-desktop--' + side + '">';
			html += '<div class="clift-panel">';
			actions.forEach( function ( action, index ) {
				html += actionHtml( action, 0 === index );
			} );
			html += '</div>';
			html += '<span class="clift-launcher" aria-hidden="true">' + CHANNEL_ICON.chat + '</span>';
			html += '</div>';
		}

		html += '</div>';
		page.innerHTML = html;
	}

	function initPreview() {
		var form = document.getElementById( 'clift-bar-form' );

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'input', renderPreview );
		form.addEventListener( 'change', renderPreview );
		renderPreview();
	}

	/* ------------------------------------------------------ Unsaved guard */

	function initUnsavedGuard() {
		var form = document.querySelector( '[data-clift-unsaved-guard]' );

		if ( ! form ) {
			return;
		}

		var dirty = false;

		form.addEventListener( 'input', function () {
			dirty = true;
		} );
		form.addEventListener( 'change', function () {
			dirty = true;
		} );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! dirty ) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		} );
	}

	onReady( function () {
		initActionRepeater();
		initColorFields();
		initPickers();
		initPreview();
		initUnsavedGuard();
	} );
} )();
