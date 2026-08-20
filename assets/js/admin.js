/**
 * Event editor helpers: show only the recurrence fields that matter, and keep
 * the end date in sync with the start date. Vanilla, no jQuery UI datepicker.
 */
( function () {
	'use strict';

	/**
	 * Wires one meta box.
	 *
	 * @param {Element} root Meta box root.
	 * @return {void}
	 */
	function bind( root ) {
		var freq = root.querySelector( '[data-xodw-cc-freq]' );
		var wrap = root.querySelector( '[data-xodw-cc-recurrence]' );
		var weekly = root.querySelector( '[data-xodw-cc-weekly]' );
		var monthly = root.querySelector( '[data-xodw-cc-monthly]' );
		var unit = root.querySelector( '[data-xodw-cc-unit]' );
		var allDay = root.querySelector( '[data-xodw-cc-allday]' );
		var timeFields = root.querySelectorAll( '[data-xodw-cc-timefield]' );
		var startDate = root.querySelector( '#xodw-cc-start-date' );
		var endDate = root.querySelector( '#xodw-cc-end-date' );
		var units = {
			daily: 'days',
			weekly: 'weeks',
			monthly: 'months',
			yearly: 'years'
		};

		/**
		 * Applies the recurrence visibility rules.
		 *
		 * @return {void}
		 */
		function syncRecurrence() {
			if ( ! freq ) {
				return;
			}

			var value = freq.value;

			if ( wrap ) {
				wrap.hidden = 'none' === value;
			}

			if ( weekly ) {
				weekly.hidden = 'weekly' !== value;
			}

			if ( monthly ) {
				monthly.hidden = 'monthly' !== value;
			}

			if ( unit ) {
				unit.textContent = units[ value ] || '';
			}
		}

		/**
		 * Hides the time inputs for all day events.
		 *
		 * @return {void}
		 */
		function syncAllDay() {
			if ( ! allDay ) {
				return;
			}

			for ( var i = 0; i < timeFields.length; i++ ) {
				timeFields[ i ].hidden = allDay.checked;
			}
		}

		if ( freq ) {
			freq.addEventListener( 'change', syncRecurrence );
		}

		if ( allDay ) {
			allDay.addEventListener( 'change', syncAllDay );
		}

		if ( startDate && endDate ) {
			startDate.addEventListener( 'change', function () {
				if ( ! endDate.value || endDate.value < startDate.value ) {
					endDate.value = startDate.value;
				}

				endDate.min = startDate.value;
			} );

			if ( startDate.value ) {
				endDate.min = startDate.value;
			}
		}

		syncRecurrence();
		syncAllDay();
	}

	/**
	 * Boots every meta box on the screen.
	 *
	 * @return {void}
	 */
	function init() {
		var roots = document.querySelectorAll( '[data-xodw-cc-admin]' );

		for ( var i = 0; i < roots.length; i++ ) {
			bind( roots[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
