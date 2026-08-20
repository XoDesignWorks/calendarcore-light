<?php
/**
 * Single event output.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Frontend;

defined( 'ABSPATH' ) || exit;

use XODW\CalendarCore\Assets;
use XODW\CalendarCore\EventMeta;
use XODW\CalendarCore\Ics;
use XODW\CalendarCore\Occurrence;
use XODW\CalendarCore\Rsvp;
use XODW\CalendarCore\Timezone;

/**
 * Appends the event details, the add-to-calendar buttons and the RSVP form to
 * the event content. Themes can turn this off and use the shortcodes instead.
 */
class Single {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'the_content', array( $this, 'append_details' ), 20 );
		add_filter( 'get_the_archive_title', array( $this, 'archive_title' ) );
	}

	/**
	 * Appends the details panel to the event content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_details( $content ) {
		if ( ! is_singular( XODW_CC_POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		/**
		 * Filters whether the details panel is appended automatically.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether to append the panel.
		 */
		if ( ! apply_filters( 'xodw_cc_append_single_details', true ) ) {
			return $content;
		}

		$occurrence = self::current_occurrence( get_the_ID() );

		if ( ! $occurrence ) {
			return $content;
		}

		return $content . self::details( $occurrence );
	}

	/**
	 * Resolves which occurrence the visitor is looking at.
	 *
	 * @param int $event_id Event post ID.
	 * @return Occurrence|null
	 */
	public static function current_occurrence( $event_id ) {
		$event_id = (int) $event_id;
		$key      = get_query_var( 'xodw_cc_occ' );
		$key      = is_string( $key ) ? preg_replace( '/[^0-9]/', '', $key ) : '';

		if ( '' === $key ) {
			// Default to the next upcoming occurrence, or the last one for a
			// series that already finished.
			$upcoming = xodw_cc_get_occurrences(
				array(
					'event_ids' => array( $event_id ),
					'from'      => gmdate( 'Y-m-d H:i:s' ),
					'limit'     => 1,
				)
			);

			if ( ! empty( $upcoming ) ) {
				return $upcoming[0];
			}

			$past = xodw_cc_get_occurrences(
				array(
					'event_ids' => array( $event_id ),
					'order'     => 'DESC',
					'limit'     => 1,
				)
			);

			if ( ! empty( $past ) ) {
				return $past[0];
			}

			$meta = EventMeta::get( $event_id );

			return '' !== $meta['start_utc'] ? new Occurrence( $event_id, $meta['start_utc'], $meta['end_utc'] ) : null;
		}

		return Ics::resolve_occurrence( $event_id, $key );
	}

	/**
	 * Details panel markup.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @return string
	 */
	public static function details( Occurrence $occurrence ) {
		Assets::enqueue_frontend();

		$meta = $occurrence->meta();

		$html  = '<div class="xodw-cc xodw-cc-single" data-xodw-cc-single="1">';
		$html .= '<dl class="xodw-cc-single__meta">';

		$html .= '<div class="xodw-cc-single__row"><dt>' . esc_html__( 'When', 'calendarcore' ) . '</dt>';
		$html .= '<dd>' . Renderer::time_tag( $occurrence, 'full' );

		if ( ! $occurrence->all_day() ) {
			$html .= ' <span class="xodw-cc-single__tz">' . esc_html( Timezone::label( wp_timezone_string(), $occurrence->start ) ) . '</span>';

			// An event entered in another timezone also shows its own local time.
			if ( $occurrence->timezone() !== wp_timezone_string() ) {
				$html .= '<br /><span class="xodw-cc-single__tz">' . esc_html(
					sprintf(
						/* translators: 1: local start time of the event, 2: timezone of the event. */
						__( 'Local time at the event: %1$s (%2$s)', 'calendarcore' ),
						$occurrence->start_format( (string) get_option( 'time_format' ) ),
						Timezone::label( $occurrence->timezone(), $occurrence->start )
					)
				) . '</span>';
			}
		}

		$html .= '</dd></div>';

		$venues = $occurrence->venues();

		if ( ! empty( $venues ) ) {
			$html .= '<div class="xodw-cc-single__row"><dt>' . esc_html__( 'Where', 'calendarcore' ) . '</dt><dd>';
			$parts = array();

			foreach ( $venues as $venue ) {
				$address = (string) get_term_meta( (int) $venue->term_id, '_xodw_cc_venue_address', true );
				$link    = get_term_link( $venue );
				$label   = is_wp_error( $link )
					? esc_html( $venue->name )
					: '<a href="' . esc_url( $link ) . '">' . esc_html( $venue->name ) . '</a>';

				$parts[] = '' !== $address ? $label . ', ' . esc_html( $address ) : $label;
			}

			$html .= implode( '<br />', $parts ) . '</dd></div>';
		}

		$organizers = $occurrence->organizers();

		if ( ! empty( $organizers ) ) {
			$html .= '<div class="xodw-cc-single__row"><dt>' . esc_html__( 'Organizer', 'calendarcore' ) . '</dt><dd>';
			$parts = array();

			foreach ( $organizers as $organizer ) {
				$link    = get_term_link( $organizer );
				$parts[] = is_wp_error( $link )
					? esc_html( $organizer->name )
					: '<a href="' . esc_url( $link ) . '">' . esc_html( $organizer->name ) . '</a>';
			}

			$html .= implode( ', ', $parts ) . '</dd></div>';
		}

		if ( '' !== $meta['cost'] ) {
			$html .= '<div class="xodw-cc-single__row"><dt>' . esc_html__( 'Price', 'calendarcore' ) . '</dt>';
			$html .= '<dd>' . esc_html( $meta['cost'] ) . '</dd></div>';
		}

		if ( '' !== $meta['url'] ) {
			$html .= '<div class="xodw-cc-single__row"><dt>' . esc_html__( 'Website', 'calendarcore' ) . '</dt>';
			$html .= '<dd><a href="' . esc_url( $meta['url'] ) . '" rel="nofollow noopener">' . esc_html( $meta['url'] ) . '</a></dd></div>';
		}

		if ( 'none' !== $meta['recur_freq'] ) {
			$html .= '<div class="xodw-cc-single__row"><dt>' . esc_html__( 'Repeats', 'calendarcore' ) . '</dt>';
			$html .= '<dd>' . esc_html( self::recurrence_label( $meta ) ) . '</dd></div>';
		}

		$html .= '</dl>';

		if ( xodw_cc_module_enabled( 'ics' ) ) {
			$html .= self::add_to_calendar( $occurrence );
		}

		if ( 'none' !== $meta['recur_freq'] ) {
			$html .= self::next_dates( $occurrence );
		}

		if ( $occurrence->rsvp_enabled() ) {
			$html .= Rsvp::form( $occurrence );
		}

		$html .= '</div>';

		/**
		 * Filters the single event details panel.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $html       Panel markup.
		 * @param Occurrence $occurrence Occurrence being displayed.
		 */
		return apply_filters( 'xodw_cc_single_details', $html, $occurrence );
	}

	/**
	 * Add to calendar controls.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @return string
	 */
	public static function add_to_calendar( Occurrence $occurrence ) {
		$html  = '<div class="xodw-cc-addto">';
		$html .= '<span class="xodw-cc-addto__label">' . esc_html__( 'Add to calendar:', 'calendarcore' ) . '</span> ';
		$html .= '<a class="xodw-cc__btn xodw-cc__btn--small" href="' . esc_url( xodw_cc_ics_url( $occurrence->event_id, $occurrence->start ) ) . '" download>';
		$html .= esc_html__( 'Apple / Outlook (.ics)', 'calendarcore' ) . '</a> ';
		$html .= '<a class="xodw-cc__btn xodw-cc__btn--small xodw-cc__btn--ghost" href="' . esc_url( Ics::google_url( $occurrence ) ) . '" target="_blank" rel="noopener">';
		$html .= esc_html__( 'Google', 'calendarcore' ) . '</a> ';
		$html .= '<a class="xodw-cc__btn xodw-cc__btn--small xodw-cc__btn--ghost" href="' . esc_url( Ics::outlook_url( $occurrence ) ) . '" target="_blank" rel="noopener">';
		$html .= esc_html__( 'Outlook.com', 'calendarcore' ) . '</a>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Upcoming dates of a recurring event.
	 *
	 * @param Occurrence $occurrence Current occurrence.
	 * @return string
	 */
	private static function next_dates( Occurrence $occurrence ) {
		$upcoming = xodw_cc_get_occurrences(
			array(
				'event_ids' => array( $occurrence->event_id ),
				'from'      => gmdate( 'Y-m-d H:i:s' ),
				'limit'     => 6,
			)
		);

		if ( count( $upcoming ) < 2 ) {
			return '';
		}

		$html = '<div class="xodw-cc-dates"><h3 class="xodw-cc-dates__title">' . esc_html__( 'Upcoming dates', 'calendarcore' ) . '</h3><ul>';

		foreach ( $upcoming as $item ) {
			$current = $item->key() === $occurrence->key();
			$html   .= '<li' . ( $current ? ' class="is-current"' : '' ) . '>';
			$html   .= $current
				? Renderer::time_tag( $item, 'full' )
				: '<a href="' . esc_url( $item->permalink() ) . '">' . Renderer::time_tag( $item, 'full' ) . '</a>';
			$html   .= '</li>';
		}

		$html .= '</ul></div>';

		return $html;
	}

	/**
	 * Human readable recurrence description.
	 *
	 * @param array<string,mixed> $meta Event meta.
	 * @return string
	 */
	public static function recurrence_label( array $meta ) {
		$interval = max( 1, (int) $meta['recur_interval'] );

		switch ( $meta['recur_freq'] ) {
			case 'daily':
				$label = 1 === $interval
					? __( 'Every day', 'calendarcore' )
					/* translators: %d: number of days. */
					: sprintf( __( 'Every %d days', 'calendarcore' ), $interval );
				break;

			case 'weekly':
				$days = array();

				foreach ( explode( ',', (string) $meta['recur_byday'] ) as $code ) {
					$index = array_search( strtoupper( trim( $code ) ), EventMeta::WEEKDAYS, true );

					if ( false !== $index ) {
						$days[] = wp_date( 'l', strtotime( 'monday +' . (int) $index . ' days' ) );
					}
				}

				$label = 1 === $interval
					? __( 'Every week', 'calendarcore' )
					/* translators: %d: number of weeks. */
					: sprintf( __( 'Every %d weeks', 'calendarcore' ), $interval );

				if ( ! empty( $days ) ) {
					$label .= ' · ' . implode( ', ', $days );
				}
				break;

			case 'monthly':
				$label = 1 === $interval
					? __( 'Every month', 'calendarcore' )
					/* translators: %d: number of months. */
					: sprintf( __( 'Every %d months', 'calendarcore' ), $interval );
				break;

			case 'yearly':
				$label = 1 === $interval
					? __( 'Every year', 'calendarcore' )
					/* translators: %d: number of years. */
					: sprintf( __( 'Every %d years', 'calendarcore' ), $interval );
				break;

			default:
				return __( 'Does not repeat', 'calendarcore' );
		}

		if ( '' !== $meta['recur_until'] ) {
			$label .= ' · ' . sprintf(
				/* translators: %s: end date of a recurring series. */
				__( 'until %s', 'calendarcore' ),
				wp_date( (string) get_option( 'date_format' ), strtotime( $meta['recur_until'] ) )
			);
		} elseif ( (int) $meta['recur_count'] > 0 ) {
			$label .= ' · ' . sprintf(
				/* translators: %d: number of occurrences. */
				_n( '%d time', '%d times', (int) $meta['recur_count'], 'calendarcore' ),
				(int) $meta['recur_count']
			);
		}

		return $label;
	}

	/**
	 * Nicer archive titles for the event archive and its taxonomies.
	 *
	 * @param string $title Archive title.
	 * @return string
	 */
	public function archive_title( $title ) {
		if ( is_post_type_archive( XODW_CC_POST_TYPE ) ) {
			return __( 'Events', 'calendarcore' );
		}

		return $title;
	}
}
