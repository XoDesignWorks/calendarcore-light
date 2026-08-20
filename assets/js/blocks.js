/**
 * Editor registration for both CalendarCore blocks.
 *
 * Written in plain JavaScript on purpose: no build step, no JSX transform, and
 * nothing to break when the editor bundle changes. Both blocks preview through
 * the same server side renderer that the front end uses.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var ServerSideRender = wp.serverSideRender;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var Placeholder = wp.components.Placeholder;

	var sharedAttributes = {
		date: { type: 'string', default: '' },
		venue: { type: 'string', default: '' },
		organizer: { type: 'string', default: '' },
		limit: { type: 'number', default: 12 },
		showToolbar: { type: 'boolean', default: true },
		showPast: { type: 'boolean', default: false },
		onePerEvent: { type: 'boolean', default: false },
		showImages: { type: 'boolean', default: true },
		showExcerpt: { type: 'boolean', default: true }
	};

	/**
	 * Shallow object merge, kept local to avoid an Object.assign polyfill debate.
	 *
	 * @param {Object} a First object.
	 * @param {Object} b Second object.
	 * @return {Object} Merged copy.
	 */
	function merge( a, b ) {
		var out = {};
		var key;

		for ( key in a ) {
			if ( Object.prototype.hasOwnProperty.call( a, key ) ) {
				out[ key ] = a[ key ];
			}
		}

		for ( key in b ) {
			if ( Object.prototype.hasOwnProperty.call( b, key ) ) {
				out[ key ] = b[ key ];
			}
		}

		return out;
	}

	/**
	 * Inspector panel shared by both blocks.
	 *
	 * @param {Object}  props     Block props.
	 * @param {boolean} withViews Whether the view picker is shown.
	 * @return {Object} Element.
	 */
	function inspector( props, withViews ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var controls = [];

		if ( withViews ) {
			controls.push(
				el( SelectControl, {
					key: 'view',
					label: __( 'Default view', 'calendarcore' ),
					value: a.view,
					options: [
						{ label: __( 'Month', 'calendarcore' ), value: 'month' },
						{ label: __( 'Week', 'calendarcore' ), value: 'week' },
						{ label: __( 'Day', 'calendarcore' ), value: 'day' },
						{ label: __( 'List', 'calendarcore' ), value: 'list' }
					],
					onChange: function ( value ) {
						set( { view: value } );
					}
				} )
			);
		}

		controls.push(
			el( TextControl, {
				key: 'venue',
				label: __( 'Venue slugs', 'calendarcore' ),
				help: __( 'Comma separated. Leave empty for all venues.', 'calendarcore' ),
				value: a.venue,
				onChange: function ( value ) {
					set( { venue: value } );
				}
			} ),
			el( TextControl, {
				key: 'organizer',
				label: __( 'Organizer slugs', 'calendarcore' ),
				help: __( 'Comma separated. Leave empty for all organizers.', 'calendarcore' ),
				value: a.organizer,
				onChange: function ( value ) {
					set( { organizer: value } );
				}
			} ),
			el( RangeControl, {
				key: 'limit',
				label: __( 'Events per page', 'calendarcore' ),
				value: a.limit,
				min: 1,
				max: 50,
				onChange: function ( value ) {
					set( { limit: value } );
				}
			} ),
			el( ToggleControl, {
				key: 'toolbar',
				label: __( 'Show navigation toolbar', 'calendarcore' ),
				checked: !! a.showToolbar,
				onChange: function ( value ) {
					set( { showToolbar: value } );
				}
			} ),
			el( ToggleControl, {
				key: 'past',
				label: __( 'Include past events', 'calendarcore' ),
				checked: !! a.showPast,
				onChange: function ( value ) {
					set( { showPast: value } );
				}
			} ),
			el( ToggleControl, {
				key: 'one',
				label: __( 'Only the next date of recurring events', 'calendarcore' ),
				checked: !! a.onePerEvent,
				onChange: function ( value ) {
					set( { onePerEvent: value } );
				}
			} ),
			el( ToggleControl, {
				key: 'images',
				label: __( 'Show images', 'calendarcore' ),
				checked: !! a.showImages,
				onChange: function ( value ) {
					set( { showImages: value } );
				}
			} ),
			el( ToggleControl, {
				key: 'excerpt',
				label: __( 'Show short description', 'calendarcore' ),
				checked: !! a.showExcerpt,
				onChange: function ( value ) {
					set( { showExcerpt: value } );
				}
			} )
		);

		return el(
			InspectorControls,
			{ key: 'inspector' },
			el( PanelBody, { title: __( 'Events', 'calendarcore' ), initialOpen: true }, controls )
		);
	}

	/**
	 * Builds an edit component for a block name.
	 *
	 * @param {boolean} withViews Whether the view picker is shown.
	 * @return {Function} Edit component.
	 */
	function makeEdit( withViews ) {
		return function ( props ) {
			var preview;

			if ( ServerSideRender ) {
				preview = el( ServerSideRender, {
					block: props.name,
					attributes: props.attributes,
					httpMethod: 'POST'
				} );
			} else {
				preview = el(
					Placeholder,
					{ label: __( 'CalendarCore', 'calendarcore' ) },
					__( 'The preview is unavailable, but the block renders on the front end.', 'calendarcore' )
				);
			}

			return el(
				Fragment,
				{},
				inspector( props, withViews ),
				el( 'div', useBlockProps(), preview )
			);
		};
	}

	wp.blocks.registerBlockType( 'xodw-cc/calendar', {
		apiVersion: 3,
		title: __( 'Event calendar', 'calendarcore' ),
		description: __( 'A month, week, day or list view of your events.', 'calendarcore' ),
		category: 'xodw-cc',
		icon: 'calendar-alt',
		keywords: [ __( 'events', 'calendarcore' ), __( 'calendar', 'calendarcore' ) ],
		supports: { html: false, align: [ 'wide', 'full' ] },
		attributes: merge( sharedAttributes, { view: { type: 'string', default: 'month' } } ),
		edit: makeEdit( true ),
		save: function () {
			return null;
		}
	} );

	wp.blocks.registerBlockType( 'xodw-cc/event-list', {
		apiVersion: 3,
		title: __( 'Event list', 'calendarcore' ),
		description: __( 'Upcoming events as a compact list.', 'calendarcore' ),
		category: 'xodw-cc',
		icon: 'list-view',
		keywords: [ __( 'events', 'calendarcore' ), __( 'upcoming', 'calendarcore' ) ],
		supports: { html: false, align: [ 'wide' ] },
		attributes: merge( sharedAttributes, {
			limit: { type: 'number', default: 5 },
			showToolbar: { type: 'boolean', default: false },
			onePerEvent: { type: 'boolean', default: true },
			showImages: { type: 'boolean', default: false }
		} ),
		edit: makeEdit( false ),
		save: function () {
			return null;
		}
	} );
} )( window.wp );
