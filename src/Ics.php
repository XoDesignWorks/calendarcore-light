<?php
/**
 * iCalendar export.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Produces RFC 5545 output for a single occurrence or for a filtered feed.
 * Everything is written in UTC, which every major client understands without
 * a VTIMEZONE block.
 */
class Ics {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_output' ), 1 );
	}

	/**
	 * Download URL of a single occurrence.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $start    Occurrence start in UTC.
	 * @return string
	 */
	public static function event_url( $event_id, $start = '' ) {
		$args = array( 'xodw_cc_ics' => (int) $event_id );

		if ( '' !== $start ) {
			$args['occ'] = str_replace( array( '-', ' ', ':' ), '', $start );
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Subscription URL of the whole calendar.
	 *
	 * @param array<string,string> $filters Optional venue / organizer slugs.
	 * @return string
	 */
	public static function feed_url( array $filters = array() ) {
		$args = array( 'xodw_cc_ics' => 'feed' );

		foreach ( array( 'venue', 'organizer' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$args[ $key ] = sanitize_title( $filters[ $key ] );
			}
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Google Calendar "add event" URL.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @return string
	 */
	public static function google_url( Occurrence $occurrence ) {
		$all_day = $occurrence->all_day();
		$format  = $all_day ? 'Ymd' : 'Ymd\THis\Z';
		$start   = gmdate( $format, strtotime( $occurrence->start . ' UTC' ) );
		$end     = gmdate( $format, strtotime( $occurrence->end . ' UTC' ) + ( $all_day ? DAY_IN_SECONDS : 0 ) );

		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( $occurrence->title() ),
				'dates'    => $start . '/' . $end,
				// esc_url() strips encoded line breaks, so the parts are joined
				// with a dash instead of a newline.
				'details'  => rawurlencode( trim( $occurrence->excerpt( 40 ) . ' — ' . $occurrence->permalink() ) ),
				'location' => rawurlencode( $occurrence->venue_label() ),
				'ctz'      => rawurlencode( $occurrence->timezone() ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * Outlook Web "add event" URL.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @return string
	 */
	public static function outlook_url( Occurrence $occurrence ) {
		return add_query_arg(
			array(
				'path'     => '/calendar/action/compose',
				'rru'      => 'addevent',
				'subject'  => rawurlencode( $occurrence->title() ),
				'startdt'  => $occurrence->start_iso(),
				'enddt'    => $occurrence->end_iso(),
				'body'     => rawurlencode( $occurrence->permalink() ),
				'location' => rawurlencode( $occurrence->venue_label() ),
			),
			'https://outlook.live.com/calendar/0/deeplink/compose'
		);
	}

	/**
	 * Serves the .ics response when the request asks for one.
	 *
	 * @return void
	 */
	public function maybe_output() {
		$request = get_query_var( 'xodw_cc_ics' );

		if ( '' === $request || null === $request ) {
			return;
		}

		if ( 'feed' === $request ) {
			$this->output_feed();

			return;
		}

		$event_id = absint( $request );

		if ( $event_id <= 0 ) {
			return;
		}

		$post = get_post( $event_id );

		if ( ! $post || XODW_CC_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$occ = isset( $_GET['occ'] ) ? sanitize_text_field( wp_unslash( $_GET['occ'] ) ) : '';

		$occurrence = self::resolve_occurrence( $event_id, $occ );

		if ( ! $occurrence ) {
			return;
		}

		$this->send( $this->calendar( array( $occurrence ) ), sanitize_file_name( $post->post_name . '.ics' ) );
	}

	/**
	 * Serves a feed of upcoming occurrences.
	 *
	 * @return void
	 */
	private function output_feed() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$venue     = isset( $_GET['venue'] ) ? sanitize_title( wp_unslash( $_GET['venue'] ) ) : '';
		$organizer = isset( $_GET['organizer'] ) ? sanitize_title( wp_unslash( $_GET['organizer'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$occurrences = ( new Query() )->get_occurrences(
			array(
				'from'      => gmdate( 'Y-m-d H:i:s' ),
				'limit'     => 200,
				'venue'     => $venue,
				'organizer' => $organizer,
			)
		);

		$this->send( $this->calendar( $occurrences ), sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-events.ics' ) );
	}

	/**
	 * Finds an occurrence by its key, falling back to the event start.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $key      Occurrence key, 'YmdHis'.
	 * @return Occurrence|null
	 */
	public static function resolve_occurrence( $event_id, $key = '' ) {
		$meta = EventMeta::get( $event_id );

		if ( '' === $meta['start_utc'] ) {
			return null;
		}

		if ( '' !== $key && preg_match( '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $key, $m ) ) {
			$start = sprintf( '%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] );

			$occurrences = ( new Query() )->get_occurrences(
				array(
					'event_ids' => array( (int) $event_id ),
					'from'      => $start,
					'to'        => $start,
					'limit'     => 1,
				)
			);

			if ( ! empty( $occurrences ) ) {
				return $occurrences[0];
			}
		}

		return new Occurrence( (int) $event_id, $meta['start_utc'], $meta['end_utc'] );
	}

	/**
	 * Builds a whole VCALENDAR document.
	 *
	 * @param Occurrence[] $occurrences Occurrences to export.
	 * @return string
	 */
	public function calendar( array $occurrences ) {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//XoDesignWorks//CalendarCore ' . XODW_CC_VERSION . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'X-WR-TIMEZONE:' . self::escape( wp_timezone_string() ),
		);

		foreach ( $occurrences as $occurrence ) {
			$lines = array_merge( $lines, $this->event_lines( $occurrence ) );
		}

		$lines[] = 'END:VCALENDAR';

		$output = '';

		foreach ( $lines as $line ) {
			$output .= self::fold( $line ) . "\r\n";
		}

		/**
		 * Filters the generated iCalendar document.
		 *
		 * @since 1.0.0
		 *
		 * @param string       $output      Complete .ics content.
		 * @param Occurrence[] $occurrences Exported occurrences.
		 */
		return apply_filters( 'xodw_cc_ics_export', $output, $occurrences );
	}

	/**
	 * VEVENT lines of one occurrence.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @return array<int,string>
	 */
	private function event_lines( Occurrence $occurrence ) {
		$post = get_post( $occurrence->event_id );

		if ( ! $post ) {
			return array();
		}

		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$all_day = $occurrence->all_day();

		$description = wp_strip_all_tags( strip_shortcodes( '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content ) );
		$description = trim( preg_replace( '/\s+/', ' ', $description ) );

		$lines = array( 'BEGIN:VEVENT' );

		$lines[] = 'UID:xodw-cc-' . $occurrence->event_id . '-' . $occurrence->key() . '@' . $host;
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
		$lines[] = 'LAST-MODIFIED:' . gmdate( 'Ymd\THis\Z', (int) get_post_modified_time( 'U', true, $post ) );

		if ( $all_day ) {
			$lines[] = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $occurrence->start . ' UTC' ) );
			// DTEND is exclusive for all day events.
			$lines[] = 'DTEND;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $occurrence->end . ' UTC' ) + DAY_IN_SECONDS );
		} else {
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $occurrence->start . ' UTC' ) );
			$lines[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $occurrence->end . ' UTC' ) );
		}

		$lines[] = 'SUMMARY:' . self::escape( $occurrence->title() );

		if ( '' !== $description ) {
			$lines[] = 'DESCRIPTION:' . self::escape( wp_trim_words( $description, 120, '…' ) );
		}

		$venue = $occurrence->venue_label();

		if ( '' !== $venue ) {
			$address = '';
			$venues  = $occurrence->venues();

			if ( ! empty( $venues ) ) {
				$address = (string) get_term_meta( (int) $venues[0]->term_id, '_xodw_cc_venue_address', true );
			}

			$lines[] = 'LOCATION:' . self::escape( '' !== $address ? $venue . ', ' . $address : $venue );
		}

		$organizers = $occurrence->organizers();

		if ( ! empty( $organizers ) ) {
			$email = (string) get_term_meta( (int) $organizers[0]->term_id, '_xodw_cc_organizer_email', true );
			$name  = self::escape( $organizers[0]->name );

			$lines[] = is_email( $email )
				? 'ORGANIZER;CN=' . $name . ':mailto:' . $email
				: 'ORGANIZER;CN=' . $name . ':MAILTO:noreply@' . $host;
		}

		$lines[] = 'URL:' . esc_url_raw( $occurrence->permalink() );
		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'SEQUENCE:0';
		$lines[] = 'END:VEVENT';

		/**
		 * Filters the VEVENT lines of one occurrence.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int,string> $lines      VEVENT lines.
		 * @param Occurrence        $occurrence Occurrence.
		 */
		return apply_filters( 'xodw_cc_ics_event_lines', $lines, $occurrence );
	}

	/**
	 * Sends the response and stops.
	 *
	 * @param string $content  .ics content.
	 * @param string $filename Download file name.
	 * @return void
	 */
	private function send( $content, $filename ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . strlen( $content ) );
			header( 'X-Robots-Tag: noindex' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $content;
		exit;
	}

	/**
	 * Escapes a text value per RFC 5545.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function escape( $value ) {
		$value = wp_specialchars_decode( (string) $value, ENT_QUOTES );
		$value = str_replace( array( "\\", "\r\n", "\n", "\r", ';', ',' ), array( '\\\\', '\n', '\n', '\n', '\;', '\,' ), $value );

		return $value;
	}

	/**
	 * Folds a content line at 75 octets, as required by RFC 5545.
	 *
	 * @param string $line Line to fold.
	 * @return string
	 */
	public static function fold( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$folded    = '';
		$remaining = $line;
		$limit     = 74;

		while ( strlen( $remaining ) > $limit ) {
			$chunk = mb_strcut( $remaining, 0, $limit, 'UTF-8' );

			if ( '' === $chunk ) {
				break;
			}

			$folded   .= $chunk . "\r\n ";
			$remaining = substr( $remaining, strlen( $chunk ) );
			$limit     = 73;
		}

		return $folded . $remaining;
	}
}
