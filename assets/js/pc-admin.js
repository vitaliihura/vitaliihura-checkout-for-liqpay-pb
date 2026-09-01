/**
 * Admin behaviour for the Plugin Chrome layer.
 *
 * Everything here is progressive: with JavaScript off the settings screen is still a plain
 * form that saves, the index is still a list of anchors, and secrets are still editable.
 */
( function () {
	'use strict';

	var settings = window.pglpAdmin || {};
	var strings = settings.i18n || {};

	function el( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function all( selector, scope ) {
		return Array.prototype.slice.call( ( scope || document ).querySelectorAll( selector ) );
	}

	function post( body ) {
		return window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new window.URLSearchParams( body ).toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function say( node, message, state ) {
		if ( ! node ) {
			return;
		}

		node.textContent = message;
		node.className = 'pc-feedback' + ( state ? ' pc-feedback--' + state : '' );
	}

	/* ------------------------------------------------------------ index --- */

	function initIndex() {
		var index = el( '.pc-index' );

		if ( ! index ) {
			return;
		}

		var links = all( 'a[href^="#"]', index );
		var sections = links.map( function ( link ) {
			return el( link.getAttribute( 'href' ) );
		} );

		function mark( target ) {
			links.forEach( function ( link, i ) {
				var current = sections[ i ] === target;
				link.classList.toggle( 'is-current', current );

				if ( current ) {
					link.setAttribute( 'aria-current', 'true' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
		}

		index.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( 'a[href^="#"]' );

			if ( ! link ) {
				return;
			}

			var target = el( link.getAttribute( 'href' ) );

			if ( ! target ) {
				return;
			}

			event.preventDefault();
			mark( target );
			target.scrollIntoView( { behavior: 'smooth', block: 'start' } );

			// Keep the anchor reachable by keyboard without stealing focus styling.
			target.setAttribute( 'tabindex', '-1' );
			target.focus( { preventScroll: true } );
		} );

		// At the top of the page no section sits inside the observed band, so the
		// first one is the current section until the reader scrolls past it.
		if ( sections[ 0 ] ) {
			mark( sections[ 0 ] );
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		var inBand = [];

		var observer = new window.IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				var at = inBand.indexOf( entry.target );

				if ( entry.isIntersecting && at < 0 ) {
					inBand.push( entry.target );
				} else if ( ! entry.isIntersecting && at > -1 ) {
					inBand.splice( at, 1 );
				}
			} );

			var current = null;

			sections.forEach( function ( section ) {
				if ( ! current && inBand.indexOf( section ) > -1 ) {
					current = section;
				}
			} );

			// An instant jump back to the top never drags a section through the
			// band, so the first one takes over whenever the band is empty and the
			// page sits above it.
			if ( ! current && sections[ 0 ] && sections[ 0 ].getBoundingClientRect().top > 0 ) {
				current = sections[ 0 ];
			}

			if ( current ) {
				mark( current );
			}
		}, { rootMargin: '-15% 0px -75% 0px' } );

		sections.forEach( function ( section ) {
			if ( section ) {
				observer.observe( section );
			}
		} );
	}

	/* --------------------------------------------------------- languages --- */

	function initLanguageSwitches() {
		all( '.pc-seg[data-pc-langs]' ).forEach( function ( seg ) {
			seg.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( 'button' );

				if ( ! button ) {
					return;
				}

				var locale = button.getAttribute( 'data-locale' );
				var group = seg.getAttribute( 'data-pc-langs' );

				all( '.pc-seg[data-pc-langs="' + group + '"] button' ).forEach( function ( other ) {
					other.setAttribute( 'aria-pressed', String( other === button || other.getAttribute( 'data-locale' ) === locale ) );
				} );

				all( '[data-pc-lang-group="' + group + '"]' ).forEach( function ( pane ) {
					pane.hidden = pane.getAttribute( 'data-locale' ) !== locale;
				} );
			} );
		} );
	}

	/* ------------------------------------------------------------ fields --- */

	function initSecrets() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.pc-secret__reveal' );

			if ( ! button ) {
				return;
			}

			var input = el( 'input', button.parentNode );
			var hidden = input.type === 'password';

			input.type = hidden ? 'text' : 'password';
			button.textContent = hidden ? strings.hide : strings.show;
			button.setAttribute( 'aria-pressed', String( hidden ) );
		} );
	}

	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.pc-copy__button' );

			if ( ! button ) {
				return;
			}

			var code = el( 'code', button.parentNode );

			if ( ! code || ! navigator.clipboard ) {
				return;
			}

			navigator.clipboard.writeText( code.textContent.trim() ).then( function () {
				var original = button.textContent;

				button.textContent = strings.copied;
				window.setTimeout( function () {
					button.textContent = original;
				}, 1600 );
			} );
		} );
	}

	function initDependents() {
		var controls = {};

		all( '[data-pc-depends]' ).forEach( function ( pane ) {
			var key = pane.getAttribute( 'data-pc-depends' );
			var wanted = pane.getAttribute( 'data-pc-depends-value' );
			var control = document.getElementById( key );

			if ( ! control ) {
				return;
			}

			controls[ key ] = controls[ key ] || [];
			controls[ key ].push( { pane: pane, wanted: wanted } );
		} );

		Object.keys( controls ).forEach( function ( key ) {
			var control = document.getElementById( key );

			function sync() {
				var value = control.type === 'checkbox' ? ( control.checked ? '1' : '' ) : control.value;

				controls[ key ].forEach( function ( entry ) {
					var wanted = entry.wanted;
					// Mirrors PGLP_UI::dependency_met() so the server and the page never disagree.
					var match = ( wanted === null || wanted === '' )
						? ( Boolean( value ) && value !== 'no' )
						: wanted === value;

					entry.pane.hidden = ! match;
				} );
			}

			control.addEventListener( 'change', sync );
			sync();
		} );
	}

	/* ----------------------------------------------------------- actions --- */

	function initTestConnection() {
		var button = el( '.pc-js-test-connection' );

		if ( ! button ) {
			return;
		}

		var feedback = el( '.pc-js-test-feedback' );

		button.addEventListener( 'click', function () {
			button.disabled = true;
			say( feedback, strings.working, 'busy' );

			var live = ! el( '#woocommerce_pglp_liqpay_test_mode' ) || ! el( '#woocommerce_pglp_liqpay_test_mode' ).checked;
			var publicField = el( live ? '#woocommerce_pglp_liqpay_public_key' : '#woocommerce_pglp_liqpay_sandbox_public_key' );
			var privateField = el( live ? '#woocommerce_pglp_liqpay_private_key' : '#woocommerce_pglp_liqpay_sandbox_private_key' );

			post( {
				action: 'pglp_test_connection',
				nonce: settings.testNonce,
				public_key: publicField ? publicField.value : '',
				private_key: privateField ? privateField.value : ''
			} ).then( function ( response ) {
				button.disabled = false;

				if ( response && response.success ) {
					say( feedback, response.data.message, 'ok' );
				} else {
					say( feedback, ( response && response.data && response.data.message ) || strings.failed, 'err' );
				}
			} ).catch( function () {
				button.disabled = false;
				say( feedback, strings.failed, 'err' );
			} );
		} );
	}

	function orderAction( button, action, extra ) {
		var panel = button.closest( '[data-pc-order]' );

		if ( ! panel ) {
			return;
		}

		var feedback = el( '.pc-feedback', panel );
		var buttons = all( 'button', panel );

		buttons.forEach( function ( node ) {
			node.disabled = true;
		} );

		say( feedback, strings.working, 'busy' );

		var body = {
			action: action,
			nonce: panel.getAttribute( 'data-pc-nonce' ),
			order_id: panel.getAttribute( 'data-pc-order' )
		};

		Object.keys( extra || {} ).forEach( function ( key ) {
			body[ key ] = extra[ key ];
		} );

		post( body ).then( function ( response ) {
			if ( response && response.success ) {
				say( feedback, response.data.message, 'ok' );
				window.setTimeout( function () {
					window.location.reload();
				}, 900 );

				return;
			}

			buttons.forEach( function ( node ) {
				node.disabled = false;
			} );
			say( feedback, ( response && response.data && response.data.message ) || strings.failed, 'err' );
		} ).catch( function () {
			buttons.forEach( function ( node ) {
				node.disabled = false;
			} );
			say( feedback, strings.failed, 'err' );
		} );
	}

	function initOrderPanels() {
		document.addEventListener( 'click', function ( event ) {
			var refresh = event.target.closest( '.pc-js-refresh' );

			if ( refresh ) {
				orderAction( refresh, 'pglp_check_status', {} );
			}
		} );
	}

	function boot() {
		initIndex();
		initLanguageSwitches();
		initSecrets();
		initCopy();
		initDependents();
		initTestConnection();
		initOrderPanels();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
