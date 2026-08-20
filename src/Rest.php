<?php
/**
 * REST endpoints. Reading single events reuses core wp/v2/xodw_cc_event; these
 * routes only cover what core cannot do: rendering a view and taking an RSVP.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XODW\CalendarCore\Frontend\Renderer;

/**
 * Registers the xodw-cc/v1 namespace.
 */
class Rest {

	const NAMESPACE_V1 = 'xodw-cc/v1';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this, 'register_fields' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/view',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_view' ),
				'permission_callback' => '__return_true',
				'args'                => $this->view_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/occurrences',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_occurrences' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'from'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( EventMeta::class, 'sanitize_datetime' ),
					),
					'to'        => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( EventMeta::class, 'sanitize_datetime' ),
					),
					'venue'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'organizer' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'     => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'offset'    => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/rsvp',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_rsvp' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'event_id'   => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'occ'        => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'name'       => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'      => array(
						'type'              => 'string',
						'required'          => true,
						'format'            => 'email',
						'sanitize_callback' => 'sanitize_email',
					),
					'guests'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
						'maximum' => 50,
					),
					'xodw_cc_hp' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/rsvp/count',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_rsvp_count' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'event_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'occ'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Argument schema shared by the view endpoint and the blocks.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function view_args() {
		return array(
			'view'          => array(
				'type'    => 'string',
				'default' => 'month',
				'enum'    => array( 'month', 'week', 'day', 'list' ),
			),
			'date'          => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( Renderer::class, 'sanitize_anchor' ),
			),
			'venue'         => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'organizer'     => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'         => array(
				'type'    => 'integer',
				'default' => 12,
				'minimum' => 1,
				'maximum' => 100,
			),
			'offset'        => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'show_past'     => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'one_per_event' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'show_images'   => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'show_excerpt'  => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'append'        => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * Renders a view server side and returns it with its navigation state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_view( WP_REST_Request $request ) {
		$atts = array(
			'view'          => $request->get_param( 'view' ),
			'date'          => $request->get_param( 'date' ),
			'venue'         => $request->get_param( 'venue' ),
			'organizer'     => $request->get_param( 'organizer' ),
			'limit'         => $request->get_param( 'limit' ),
			'offset'        => $request->get_param( 'offset' ),
			'show_past'     => $request->get_param( 'show_past' ),
			'one_per_event' => $request->get_param( 'one_per_event' ),
			'show_images'   => $request->get_param( 'show_images' ),
			'show_excerpt'  => $request->get_param( 'show_excerpt' ),
		);

		$atts  = Renderer::parse_atts( $atts );
		$range = Renderer::range( $atts );

		$response = new WP_REST_Response(
			array(
				'view'   => $atts['view'],
				'date'   => $range['anchor'],
				'label'  => $range['label'],
				'prev'   => $range['prev'],
				'next'   => $range['next'],
				'today'  => $range['today'],
				'append' => (bool) $request->get_param( 'append' ),
				'html'   => Renderer::body( $atts ),
			)
		);

		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}

	/**
	 * Occurrences as JSON, for custom front ends.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_occurrences( WP_REST_Request $request ) {
		$occurrences = ( new Query() )->get_occurrences(
			array(
				'from'      => '' !== $request->get_param( 'from' ) ? $request->get_param( 'from' ) : gmdate( 'Y-m-d H:i:s' ),
				'to'        => $request->get_param( 'to' ),
				'venue'     => $request->get_param( 'venue' ),
				'organizer' => $request->get_param( 'organizer' ),
				'limit'     => $request->get_param( 'limit' ),
				'offset'    => $request->get_param( 'offset' ),
			)
		);

		$items = array();

		foreach ( $occurrences as $occurrence ) {
			$items[] = $occurrence->to_array();
		}

		return new WP_REST_Response( $items );
	}

	/**
	 * Takes an RSVP.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_rsvp( WP_REST_Request $request ) {
		$result = Rsvp::submit(
			array(
				'event_id'   => $request->get_param( 'event_id' ),
				'occ'        => $request->get_param( 'occ' ),
				'name'       => $request->get_param( 'name' ),
				'email'      => $request->get_param( 'email' ),
				'guests'     => $request->get_param( 'guests' ),
				'xodw_cc_hp' => $request->get_param( 'xodw_cc_hp' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Live attendee counter. Deliberately uncacheable so page caches can serve
	 * the surrounding HTML.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_rsvp_count( WP_REST_Request $request ) {
		$event_id = (int) $request->get_param( 'event_id' );
		$occ      = Rsvp::occurrence_key( $request->get_param( 'occ' ) );

		$response = new WP_REST_Response(
			array(
				'event_id' => $event_id,
				'count'    => Rsvp::count( $event_id, $occ ),
				'seats'    => Rsvp::remaining( $event_id, $occ ),
				'capacity' => Rsvp::capacity( $event_id ),
			)
		);

		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/**
	 * Adds a compact event summary to the core event endpoint.
	 *
	 * @return void
	 */
	public function register_fields() {
		register_rest_field(
			XODW_CC_POST_TYPE,
			'xodw_cc_event_data',
			array(
				'get_callback' => static function ( $post ) {
					$meta       = EventMeta::get( (int) $post['id'] );
					$occurrence = new Occurrence( (int) $post['id'], $meta['start_utc'], $meta['end_utc'] );

					return array(
						'start'     => Timezone::to_iso( $meta['start_utc'] ),
						'end'       => Timezone::to_iso( $meta['end_utc'] ),
						'all_day'   => (bool) $meta['all_day'],
						'timezone'  => $meta['timezone'],
						'recurring' => 'none' !== $meta['recur_freq'],
						'venue'     => $occurrence->venue_label(),
						'organizer' => $occurrence->organizer_label(),
						'ics'       => xodw_cc_module_enabled( 'ics' ) ? xodw_cc_ics_url( (int) $post['id'], $meta['start_utc'] ) : '',
					);
				},
				'schema'       => array(
					'description' => __( 'Resolved event dates and labels.', 'calendarcore' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}
}
