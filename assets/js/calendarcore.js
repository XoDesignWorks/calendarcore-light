/**
 * CalendarCore front end.
 *
 * No jQuery, no date library: view switching goes through one REST call that
 * returns server rendered markup, and local times come from Intl.DateTimeFormat.
 */
( function () {
	'use strict';

	var config = window.xodwCcConfig || {};
	var i18n = config.i18n || {};
	var locale = config.locale || undefined;
	var visitorTz = '';

	try {
		visitorTz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
	} catch ( e ) {
		visitorTz = '';
	}

	/**
	 * Replaces %s / %d in a translated string.
	 *
	 * @param {string} template Translated string.
	 * @param {*}      value    Replacement.
	 * @return {string} Result.
	 */
	function sprintf( template, value ) {
		return String( template || '' ).replace( /%[sd]/, value );
	}

	/**
	 * Reads the state object of a calendar root.
	 *
	 * @param {Element} root Calendar root.
	 * @return {Object} State.
	 */
	function getState( root ) {
		if ( root.xodwCcState ) {
			return root.xodwCcState;
		}

		var raw = root.getAttribute( 'data-xodw-cc-state' );

		try {
			root.xodwCcState = raw ? JSON.parse( raw ) : {};
		} catch ( e ) {
			root.xodwCcState = {};
		}

		return root.xodwCcState;
	}

	/**
	 * Builds the query string of a view request.
	 *
	 * @param {Object} state  Calendar state.
	 * @param {Object} extras Overrides.
	 * @return {string} Query string.
	 */
	function buildQuery( state, extras ) {
		var params = {
			view: state.view,
			date: state.date,
			venue: state.venue || '',
			organizer: state.organizer || '',
			limit: state.limit || 12,
			offset: 0,
			show_past: state.showPast ? '1' : '0',
			one_per_event: state.onePerEvent ? '1' : '0',
			show_images: state.showImages ? '1' : '0',
			show_excerpt: state.showExcerpt ? '1' : '0'
		};

		var key;

		for ( key in extras ) {
			if ( Object.prototype.hasOwnProperty.call( extras, key ) ) {
				params[ key ] = extras[ key ];
			}
		}

		var pairs = [];

		for ( key in params ) {
			if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
				pairs.push( encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] ) );
			}
		}

		return pairs.join( '&' );
	}

	/**
	 * Loads a view into a calendar.
	 *
	 * @param {Element} root   Calendar root.
	 * @param {Object}  extras Request overrides.
	 * @param {boolean} append Whether to append instead of replace.
	 * @return {void}
	 */
	function load( root, extras, append ) {
		if ( ! config.rest ) {
			return;
		}

		var body = root.querySelector( '[data-xodw-cc-body]' );

		if ( ! body ) {
			return;
		}

		var state = getState( root );
		var url = config.rest + '/view?' + buildQuery( state, extras || {} );

		root.classList.add( 'is-loading' );
		root.setAttribute( 'aria-busy', 'true' );

		var headers = { Accept: 'application/json' };

		if ( config.nonce ) {
			headers['X-WP-Nonce'] = config.nonce;
		}

		window.fetch( url, { headers: headers, credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}

				return response.json();
			} )
			.then( function ( data ) {
				if ( append ) {
					var more = body.querySelector( '[data-xodw-cc-more]' );

					if ( more && more.parentNode ) {
						more.parentNode.removeChild( more );
					}

					body.insertAdjacentHTML( 'beforeend', data.html );
				} else {
					body.innerHTML = data.html;
				}

				state.view = data.view;
				state.date = data.date;
				root.xodwCcState = state;

				var label = root.querySelector( '[data-xodw-cc-label]' );

				if ( label ) {
					label.textContent = data.label;
				}

				updateNav( root, data );
				updateViewButtons( root, data.view );

				root.className = root.className.replace( /xodw-cc--(month|week|day|list)/, 'xodw-cc--' + data.view );

				localizeTimes( body );
				initRsvp( body );
			} )
			.catch( function () {
				var notice = document.createElement( 'p' );
				notice.className = 'xodw-cc__empty';
				notice.textContent = i18n.error || 'Could not load events.';
				body.innerHTML = '';
				body.appendChild( notice );
			} )
			.then( function () {
				root.classList.remove( 'is-loading' );
				root.removeAttribute( 'aria-busy' );
			} );
	}

	/**
	 * Points the prev / today / next links at the new range.
	 *
	 * @param {Element} root Calendar root.
	 * @param {Object}  data Response payload.
	 * @return {void}
	 */
	function updateNav( root, data ) {
		var links = root.querySelectorAll( '[data-xodw-cc-go]' );
		var targets = [ data.prev, data.today, data.next ];

		for ( var i = 0; i < links.length && i < targets.length; i++ ) {
			links[ i ].setAttribute( 'data-xodw-cc-go', targets[ i ] );
			links[ i ].setAttribute( 'href', updateUrl( links[ i ].getAttribute( 'href' ), data.view, targets[ i ] ) );
		}
	}

	/**
	 * Rewrites the navigation arguments of a URL.
	 *
	 * @param {string} href URL.
	 * @param {string} view View slug.
	 * @param {string} date Anchor date.
	 * @return {string} URL.
	 */
	function updateUrl( href, view, date ) {
		if ( ! href ) {
			return href;
		}

		try {
			var url = new URL( href, window.location.origin );
			url.searchParams.set( 'xodw_cc_view', view );
			url.searchParams.set( 'xodw_cc_date', date );

			return url.toString();
		} catch ( e ) {
			return href;
		}
	}

	/**
	 * Moves the active state between view tabs.
	 *
	 * @param {Element} root Calendar root.
	 * @param {string}  view Active view.
	 * @return {void}
	 */
	function updateViewButtons( root, view ) {
		var buttons = root.querySelectorAll( '[data-xodw-cc-view]' );

		for ( var i = 0; i < buttons.length; i++ ) {
			var active = buttons[ i ].getAttribute( 'data-xodw-cc-view' ) === view;
			buttons[ i ].classList.toggle( 'is-active', active );
			buttons[ i ].setAttribute( 'aria-selected', active ? 'true' : 'false' );
		}
	}

	/**
	 * Rewrites server rendered times into the visitor timezone.
	 *
	 * @param {Element} scope Container to scan.
	 * @return {void}
	 */
	function localizeTimes( scope ) {
		if ( ! config.tzAware || ! visitorTz || visitorTz === config.timezone ) {
			return;
		}

		if ( typeof Intl === 'undefined' || ! Intl.DateTimeFormat ) {
			return;
		}

		var nodes = scope.querySelectorAll( 'time[data-xodw-cc-time]' );

		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			var start = new Date( node.getAttribute( 'datetime' ) );

			if ( isNaN( start.getTime() ) ) {
				continue;
			}

			var mode = node.getAttribute( 'data-xodw-cc-time' );
			var timeOptions = { timeZone: visitorTz, hour: 'numeric', minute: '2-digit' };

			if ( typeof config.hour12 === 'boolean' ) {
				timeOptions.hour12 = config.hour12;
			}

			var text;

			if ( 'full' === mode ) {
				var dateOptions = { timeZone: visitorTz, year: 'numeric', month: 'short', day: 'numeric' };
				text = new Intl.DateTimeFormat( locale, dateOptions ).format( start ) + ' ' +
					new Intl.DateTimeFormat( locale, timeOptions ).format( start );

				var endValue = node.getAttribute( 'data-end' );

				if ( endValue ) {
					var end = new Date( endValue );

					if ( ! isNaN( end.getTime() ) ) {
						var sameDay = new Intl.DateTimeFormat( locale, dateOptions ).format( end ) ===
							new Intl.DateTimeFormat( locale, dateOptions ).format( start );

						text += ' – ' + ( sameDay
							? new Intl.DateTimeFormat( locale, timeOptions ).format( end )
							: new Intl.DateTimeFormat( locale, dateOptions ).format( end ) + ' ' +
								new Intl.DateTimeFormat( locale, timeOptions ).format( end ) );
					}
				}
			} else {
				text = new Intl.DateTimeFormat( locale, timeOptions ).format( start );
			}

			node.textContent = text;
			node.setAttribute( 'title', visitorTz );
		}

		var notes = document.querySelectorAll( '[data-xodw-cc-tznote]' );

		for ( var n = 0; n < notes.length; n++ ) {
			notes[ n ].textContent = sprintf( i18n.yourTime || 'Times shown in your local time (%s)', visitorTz );
		}
	}

	/**
	 * Wires the RSVP forms inside a scope.
	 *
	 * @param {Element} scope Container to scan.
	 * @return {void}
	 */
	function initRsvp( scope ) {
		var widgets = scope.querySelectorAll( '[data-xodw-cc-rsvp]' );

		for ( var i = 0; i < widgets.length; i++ ) {
			var widget = widgets[ i ];

			if ( widget.xodwCcReady ) {
				continue;
			}

			widget.xodwCcReady = true;
			refreshCount( widget );
			bindRsvpForm( widget );
		}
	}

	/**
	 * Loads the live attendee counter. Kept out of the cached HTML on purpose.
	 *
	 * @param {Element} widget RSVP widget.
	 * @return {void}
	 */
	function refreshCount( widget ) {
		var target = widget.querySelector( '.xodw-cc-rsvp__count-value' );

		if ( ! target || ! config.rest ) {
			return;
		}

		var url = config.rest + '/rsvp/count?event_id=' + encodeURIComponent( widget.getAttribute( 'data-event' ) ) +
			'&occ=' + encodeURIComponent( widget.getAttribute( 'data-occ' ) || '' );

		window.fetch( url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				if ( ! data ) {
					return;
				}

				var parts = [ sprintf( i18n.going || '%d going', data.count ) ];

				if ( null !== data.seats && typeof data.seats !== 'undefined' ) {
					parts.push( 0 === data.seats ? ( i18n.full || 'Fully booked' ) : sprintf( i18n.seats || '%d seats left', data.seats ) );
				}

				target.textContent = parts.join( ' · ' );
			} )
			.catch( function () {} );
	}

	/**
	 * Submits an RSVP through the REST endpoint.
	 *
	 * @param {Element} widget RSVP widget.
	 * @return {void}
	 */
	function bindRsvpForm( widget ) {
		var form = widget.querySelector( 'form' );

		if ( ! form || ! config.rest ) {
			return;
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var message = widget.querySelector( '[data-xodw-cc-rsvp-message]' );
			var button = form.querySelector( 'button[type="submit"]' );
			var data = new FormData( form );

			var payload = {
				event_id: parseInt( widget.getAttribute( 'data-event' ), 10 ),
				occ: widget.getAttribute( 'data-occ' ) || '',
				name: data.get( 'name' ) || '',
				email: data.get( 'email' ) || '',
				guests: parseInt( data.get( 'guests' ) || '1', 10 ),
				xodw_cc_hp: data.get( 'xodw_cc_hp' ) || ''
			};

			if ( button ) {
				button.disabled = true;
			}

			if ( message ) {
				message.textContent = i18n.sending || 'Sending…';
				message.className = 'xodw-cc-rsvp__message';
			}

			var headers = {
				'Content-Type': 'application/json',
				Accept: 'application/json'
			};

			if ( config.nonce ) {
				headers['X-WP-Nonce'] = config.nonce;
			}

			window.fetch( config.rest + '/rsvp', {
				method: 'POST',
				headers: headers,
				credentials: 'same-origin',
				body: JSON.stringify( payload )
			} )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} )
				.then( function ( result ) {
					if ( ! message ) {
						return;
					}

					if ( result.ok ) {
						message.textContent = result.body.message || '';
						message.className = 'xodw-cc-rsvp__message is-success';
						form.reset();
						refreshCount( widget );
					} else {
						message.textContent = result.body && result.body.message ? result.body.message : ( i18n.error || 'Error' );
						message.className = 'xodw-cc-rsvp__message is-error';
					}
				} )
				.catch( function () {
					if ( message ) {
						message.textContent = i18n.error || 'Error';
						message.className = 'xodw-cc-rsvp__message is-error';
					}
				} )
				.then( function () {
					if ( button ) {
						button.disabled = false;
					}
				} );
		} );
	}

	/**
	 * Click delegation for one calendar.
	 *
	 * @param {Element} root Calendar root.
	 * @return {void}
	 */
	function bindCalendar( root ) {
		if ( root.xodwCcReady ) {
			return;
		}

		root.xodwCcReady = true;

		root.addEventListener( 'click', function ( event ) {
			var go = event.target.closest( '[data-xodw-cc-go]' );

			if ( go && root.contains( go ) ) {
				event.preventDefault();
				load( root, { date: go.getAttribute( 'data-xodw-cc-go' ) }, false );

				return;
			}

			var view = event.target.closest( '[data-xodw-cc-view]' );

			if ( view && root.contains( view ) ) {
				event.preventDefault();
				load( root, { view: view.getAttribute( 'data-xodw-cc-view' ) }, false );

				return;
			}

			var day = event.target.closest( '[data-xodw-cc-day]' );

			if ( day && root.contains( day ) ) {
				var state = getState( root );

				if ( state.views && state.views.indexOf( 'day' ) === -1 ) {
					return;
				}

				event.preventDefault();
				load( root, { view: 'day', date: day.getAttribute( 'data-xodw-cc-day' ) }, false );

				return;
			}

			var more = event.target.closest( '[data-xodw-cc-more]' );

			if ( more && root.contains( more ) ) {
				event.preventDefault();
				more.disabled = true;
				load( root, { offset: parseInt( more.getAttribute( 'data-xodw-cc-more' ), 10 ) || 0, append: '1' }, true );
			}
		} );

		root.addEventListener( 'keydown', function ( event ) {
			if ( event.target !== root && ! event.target.classList.contains( 'xodw-cc-month' ) ) {
				return;
			}

			if ( 'ArrowLeft' === event.key || 'ArrowRight' === event.key ) {
				var selector = 'ArrowLeft' === event.key ? '[rel="prev"]' : '[rel="next"]';
				var link = root.querySelector( selector );

				if ( link ) {
					event.preventDefault();
					load( root, { date: link.getAttribute( 'data-xodw-cc-go' ) }, false );
				}
			}
		} );
	}

	/**
	 * Boots every calendar and RSVP widget on the page.
	 *
	 * @return {void}
	 */
	function init() {
		var roots = document.querySelectorAll( '[data-xodw-cc-calendar]' );

		for ( var i = 0; i < roots.length; i++ ) {
			bindCalendar( roots[ i ] );
		}

		localizeTimes( document );
		initRsvp( document );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
