<?php
/**
 * Shortcodes for classic themes and page builders.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Frontend;

defined( 'ABSPATH' ) || exit;

use XODW\CalendarCore\Assets;
use XODW\CalendarCore\Ics;
use XODW\CalendarCore\Rsvp;

/**
 * Thin wrappers around the renderer: no logic lives here.
 */
class Shortcodes {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_shortcode( 'xodw_cc_calendar', array( $this, 'calendar' ) );
		add_shortcode( 'xodw_cc_events', array( $this, 'events' ) );
		add_shortcode( 'xodw_cc_rsvp', array( $this, 'rsvp' ) );
		add_shortcode( 'xodw_cc_add_to_calendar', array( $this, 'add_to_calendar' ) );
	}

	/**
	 * [xodw_cc_calendar view="month" venue="" organizer=""]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function calendar( $atts ) {
		Assets::enqueue_frontend();

		$atts = shortcode_atts(
			array(
				'view'          => (string) xodw_cc_setting( 'default_view', 'month' ),
				'date'          => '',
				'venue'         => '',
				'organizer'     => '',
				'limit'         => (int) xodw_cc_setting( 'events_per_page', 12 ),
				'toolbar'       => 'yes',
				'show_past'     => 'no',
				'one_per_event' => 'no',
				'show_images'   => 'yes',
				'show_excerpt'  => 'yes',
				'class'         => '',
			),
			$atts,
			'xodw_cc_calendar'
		);

		return Renderer::render( $this->normalize( $atts ) );
	}

	/**
	 * [xodw_cc_events limit="5" one_per_event="yes"]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function events( $atts ) {
		Assets::enqueue_frontend();

		$atts = shortcode_atts(
			array(
				'limit'         => 5,
				'date'          => '',
				'venue'         => '',
				'organizer'     => '',
				'toolbar'       => 'no',
				'show_past'     => 'no',
				'one_per_event' => 'yes',
				'show_images'   => 'no',
				'show_excerpt'  => 'yes',
				'class'         => '',
			),
			$atts,
			'xodw_cc_events'
		);

		$atts['view'] = 'list';

		return Renderer::render( $this->normalize( $atts ) );
	}

	/**
	 * [xodw_cc_rsvp event="123"]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function rsvp( $atts ) {
		$atts = shortcode_atts(
			array(
				'event' => get_the_ID(),
				'occ'   => '',
			),
			$atts,
			'xodw_cc_rsvp'
		);

		$event_id = absint( $atts['event'] );

		if ( $event_id <= 0 ) {
			return '';
		}

		$occurrence = Ics::resolve_occurrence( $event_id, preg_replace( '/[^0-9]/', '', (string) $atts['occ'] ) );

		if ( ! $occurrence ) {
			return '';
		}

		Assets::enqueue_frontend();

		return '<div class="xodw-cc">' . Rsvp::form( $occurrence ) . '</div>';
	}

	/**
	 * [xodw_cc_add_to_calendar event="123"]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function add_to_calendar( $atts ) {
		if ( ! xodw_cc_module_enabled( 'ics' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'event' => get_the_ID(),
				'occ'   => '',
			),
			$atts,
			'xodw_cc_add_to_calendar'
		);

		$event_id = absint( $atts['event'] );

		if ( $event_id <= 0 ) {
			return '';
		}

		$occurrence = Ics::resolve_occurrence( $event_id, preg_replace( '/[^0-9]/', '', (string) $atts['occ'] ) );

		if ( ! $occurrence ) {
			return '';
		}

		Assets::enqueue_frontend();

		return '<div class="xodw-cc">' . Single::add_to_calendar( $occurrence ) . '</div>';
	}

	/**
	 * Turns yes/no attributes into booleans.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return array<string,mixed>
	 */
	private function normalize( array $atts ) {
		foreach ( array( 'toolbar', 'show_past', 'one_per_event', 'show_images', 'show_excerpt' ) as $flag ) {
			if ( isset( $atts[ $flag ] ) ) {
				$atts[ $flag ] = in_array( strtolower( (string) $atts[ $flag ] ), array( 'yes', 'true', '1', 'on' ), true );
			}
		}

		return $atts;
	}
}
