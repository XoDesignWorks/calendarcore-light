<?php
/**
 * Event post type and its taxonomies.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Registers xodw_cc_event plus the venue and organizer taxonomies.
 */
class PostTypes {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register_post_type' ), 10 );
		add_action( 'init', array( $this, 'register_taxonomies' ), 11 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'pre_get_posts', array( $this, 'order_archive' ) );
	}

	/**
	 * Registers the event post type. show_in_rest is on so the core
	 * wp/v2/xodw_cc_event endpoint can be reused for reading.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Events', 'post type general name', 'calendarcore' ),
			'singular_name'         => _x( 'Event', 'post type singular name', 'calendarcore' ),
			'menu_name'             => _x( 'CalendarCore', 'admin menu', 'calendarcore' ),
			'name_admin_bar'        => _x( 'Event', 'add new on admin bar', 'calendarcore' ),
			'add_new'               => __( 'Add Event', 'calendarcore' ),
			'add_new_item'          => __( 'Add New Event', 'calendarcore' ),
			'new_item'              => __( 'New Event', 'calendarcore' ),
			'edit_item'             => __( 'Edit Event', 'calendarcore' ),
			'view_item'             => __( 'View Event', 'calendarcore' ),
			'view_items'            => __( 'View Events', 'calendarcore' ),
			'all_items'             => __( 'All Events', 'calendarcore' ),
			'search_items'          => __( 'Search Events', 'calendarcore' ),
			'not_found'             => __( 'No events found.', 'calendarcore' ),
			'not_found_in_trash'    => __( 'No events found in Trash.', 'calendarcore' ),
			'archives'              => __( 'Event Archives', 'calendarcore' ),
			'featured_image'        => __( 'Event Image', 'calendarcore' ),
			'set_featured_image'    => __( 'Set event image', 'calendarcore' ),
			'remove_featured_image' => __( 'Remove event image', 'calendarcore' ),
			'item_published'        => __( 'Event published.', 'calendarcore' ),
			'item_updated'          => __( 'Event updated.', 'calendarcore' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'rest_base'          => 'xodw_cc_event',
			'menu_icon'          => 'dashicons-calendar-alt',
			'menu_position'      => 26,
			'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields', 'author' ),
			'has_archive'        => (string) xodw_cc_setting( 'archive_slug', 'events' ),
			'rewrite'            => array(
				'slug'       => (string) xodw_cc_setting( 'single_slug', 'event' ),
				'with_front' => false,
			),
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'hierarchical'       => false,
			'delete_with_user'   => false,
			'template'           => array( array( 'core/paragraph', array( 'placeholder' => __( 'Describe the event…', 'calendarcore' ) ) ) ),
		);

		/**
		 * Filters the event post type arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $args Post type arguments.
		 */
		register_post_type( XODW_CC_POST_TYPE, apply_filters( 'xodw_cc_post_type_args', $args ) );
	}

	/**
	 * Registers the venue and organizer taxonomies.
	 *
	 * @return void
	 */
	public function register_taxonomies() {
		register_taxonomy(
			XODW_CC_TAX_VENUE,
			array( XODW_CC_POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => _x( 'Venues', 'taxonomy general name', 'calendarcore' ),
					'singular_name' => _x( 'Venue', 'taxonomy singular name', 'calendarcore' ),
					'search_items'  => __( 'Search Venues', 'calendarcore' ),
					'all_items'     => __( 'All Venues', 'calendarcore' ),
					'edit_item'     => __( 'Edit Venue', 'calendarcore' ),
					'update_item'   => __( 'Update Venue', 'calendarcore' ),
					'add_new_item'  => __( 'Add New Venue', 'calendarcore' ),
					'new_item_name' => __( 'New Venue Name', 'calendarcore' ),
					'menu_name'     => __( 'Venues', 'calendarcore' ),
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'event-venue' ),
			)
		);

		register_taxonomy(
			XODW_CC_TAX_ORGANIZER,
			array( XODW_CC_POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => _x( 'Organizers', 'taxonomy general name', 'calendarcore' ),
					'singular_name' => _x( 'Organizer', 'taxonomy singular name', 'calendarcore' ),
					'search_items'  => __( 'Search Organizers', 'calendarcore' ),
					'all_items'     => __( 'All Organizers', 'calendarcore' ),
					'edit_item'     => __( 'Edit Organizer', 'calendarcore' ),
					'update_item'   => __( 'Update Organizer', 'calendarcore' ),
					'add_new_item'  => __( 'Add New Organizer', 'calendarcore' ),
					'new_item_name' => __( 'New Organizer Name', 'calendarcore' ),
					'menu_name'     => __( 'Organizers', 'calendarcore' ),
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'event-organizer' ),
			)
		);

		// Address and map data belong to the venue term, not to every event.
		foreach ( array( 'address', 'city', 'country', 'lat', 'lng', 'phone', 'website' ) as $field ) {
			register_term_meta(
				XODW_CC_TAX_VENUE,
				'_xodw_cc_venue_' . $field,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}

		foreach ( array( 'email', 'phone', 'website' ) as $field ) {
			register_term_meta(
				XODW_CC_TAX_ORGANIZER,
				'_xodw_cc_organizer_' . $field,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}
	}

	/**
	 * Adds the public query vars the plugin reads.
	 *
	 * @param array<int,string> $vars Registered query vars.
	 * @return array<int,string>
	 */
	public function query_vars( $vars ) {
		$vars[] = 'xodw_cc_occ';
		$vars[] = 'xodw_cc_ics';
		$vars[] = 'xodw_cc_view';
		$vars[] = 'xodw_cc_date';

		return $vars;
	}

	/**
	 * Event archives are ordered by start date, not by publish date.
	 *
	 * @param \WP_Query $query Current query.
	 * @return void
	 */
	public function order_archive( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! $query->is_post_type_archive( XODW_CC_POST_TYPE ) && ! $query->is_tax( array( XODW_CC_TAX_VENUE, XODW_CC_TAX_ORGANIZER ) ) ) {
			return;
		}

		if ( '' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', '_xodw_cc_start_utc' );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'ASC' );

		if ( xodw_cc_setting( 'hide_past' ) ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => '_xodw_cc_end_utc',
						'value'   => gmdate( 'Y-m-d H:i:s' ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				)
			);
		}
	}
}
