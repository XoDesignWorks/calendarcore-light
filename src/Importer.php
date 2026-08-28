<?php
/**
 * iCalendar import.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an .ics document and turns its VEVENT blocks into events.
 *
 * The parser is deliberately small and forgiving: real world calendars are full
 * of odd folding, missing DTEND and floating times, and an import that dies on
 * the first surprise is useless.
 */
class Importer {

	const UID_META    = '_xodw_cc_ics_uid';
	const SOURCE_META = '_xodw_cc_ics_source';

	/**
	 * Largest document we accept, in bytes.
	 */
	const MAX_BYTES = 5242880;

	/**
	 * Parses an iCalendar document into event arrays.
	 *
	 * @param string $content Raw .ics content.
	 * @return array<int,array<string,mixed>>
	 */
	public function parse( $content ) {
		$content = (string) $content;

		if ( '' === trim( $content ) ) {
			return array();
		}

		// Unfold: a CRLF followed by a space or tab continues the previous line.
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );
		$content = preg_replace( '/\n[ \t]/', '', $content );

		$events  = array();
		$current = null;

		foreach ( explode( "\n", $content ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( 0 === strcasecmp( $line, 'BEGIN:VEVENT' ) ) {
				$current = array();

				continue;
			}

			if ( 0 === strcasecmp( $line, 'END:VEVENT' ) ) {
				if ( is_array( $current ) ) {
					$event = $this->normalize_block( $current );

					if ( $event ) {
						$events[] = $event;
					}
				}

				$current = null;

				continue;
			}

			if ( ! is_array( $current ) ) {
				continue;
			}

			$colon = strpos( $line, ':' );

			if ( false === $colon ) {
				continue;
			}

			$head  = substr( $line, 0, $colon );
			$value = substr( $line, $colon + 1 );
			$parts = explode( ';', $head );
			$name  = strtoupper( array_shift( $parts ) );
			$args  = array();

			foreach ( $parts as $param ) {
				$pair = explode( '=', $param, 2 );

				if ( 2 === count( $pair ) ) {
					$args[ strtoupper( $pair[0] ) ] = trim( $pair[1], '"' );
				}
			}

			// Repeated properties (EXDATE, RRULE) are collected, not overwritten.
			$current[ $name ][] = array(
				'value'  => $value,
				'params' => $args,
			);
		}

		/**
		 * Filters the events parsed out of an iCalendar document.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int,array<string,mixed>> $events Parsed events.
		 * @param string                         $content Raw document.
		 */
		return apply_filters( 'xodw_cc_ics_parsed', $events, $content );
	}

	/**
	 * Turns one raw VEVENT block into the fields we store.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $block Raw properties.
	 * @return array<string,mixed>|null
	 */
	private function normalize_block( array $block ) {
		$status = $this->first( $block, 'STATUS' );

		if ( 'CANCELLED' === strtoupper( (string) $status ) ) {
			return null;
		}

		$start = $this->datetime( $block, 'DTSTART' );

		if ( ! $start ) {
			return null;
		}

		$end = $this->datetime( $block, 'DTEND' );

		if ( ! $end ) {
			$duration = $this->first( $block, 'DURATION' );
			$end      = $duration ? $this->apply_duration( $start, (string) $duration ) : null;
		}

		if ( ! $end ) {
			$end = array(
				'local'    => $start['all_day']
					? $start['local']
					: gmdate( 'Y-m-d H:i:s', strtotime( $start['local'] . ' +1 hour' ) ),
				'timezone' => $start['timezone'],
				'all_day'  => $start['all_day'],
			);
		}

		if ( $start['all_day'] ) {
			// DTEND of an all day event is exclusive: step back one day.
			$last          = gmdate( 'Y-m-d', strtotime( substr( $end['local'], 0, 10 ) . ' -1 day' ) );
			$end['local']  = ( $last >= substr( $start['local'], 0, 10 ) ? $last : substr( $start['local'], 0, 10 ) ) . ' 23:59:59';
		}

		$event = array(
			'uid'         => (string) $this->first( $block, 'UID' ),
			'title'       => $this->text( (string) $this->first( $block, 'SUMMARY' ) ),
			'description' => $this->text( (string) $this->first( $block, 'DESCRIPTION' ) ),
			'location'    => $this->text( (string) $this->first( $block, 'LOCATION' ) ),
			'url'         => (string) $this->first( $block, 'URL' ),
			'organizer'   => $this->organizer( $block ),
			'start'       => $start['local'],
			'end'         => $end['local'],
			'timezone'    => $start['timezone'],
			'all_day'     => $start['all_day'],
			'recurrence'  => array(),
			'exdates'     => $this->exdates( $block ),
		);

		if ( '' === $event['title'] ) {
			$event['title'] = __( 'Untitled event', 'calendarcore' );
		}

		$rrule = $this->first( $block, 'RRULE' );

		if ( $rrule ) {
			$event['recurrence'] = Rrule::to_meta( (string) $rrule );
		}

		return $event;
	}

	/**
	 * First value of a property.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $block Raw properties.
	 * @param string                                       $name  Property name.
	 * @return string|null
	 */
	private function first( array $block, $name ) {
		return isset( $block[ $name ][0]['value'] ) ? $block[ $name ][0]['value'] : null;
	}

	/**
	 * Reads a date property into local wall time plus its timezone.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $block Raw properties.
	 * @param string                                       $name  Property name.
	 * @return array{local:string,timezone:string,all_day:bool}|null
	 */
	private function datetime( array $block, $name ) {
		if ( ! isset( $block[ $name ][0] ) ) {
			return null;
		}

		$value  = trim( (string) $block[ $name ][0]['value'] );
		$params = $block[ $name ][0]['params'];
		$tzid   = isset( $params['TZID'] ) ? $params['TZID'] : '';
		$is_utc = 'Z' === substr( $value, -1 );

		if ( isset( $params['VALUE'] ) && 'DATE' === strtoupper( $params['VALUE'] ) ) {
			if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
				return null;
			}

			return array(
				'local'    => sprintf( '%s-%s-%s 00:00:00', $m[1], $m[2], $m[3] ),
				'timezone' => '' !== $tzid ? $tzid : wp_timezone_string(),
				'all_day'  => true,
			);
		}

		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/', $value, $m ) ) {
			return null;
		}

		$wall = sprintf( '%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] );

		if ( $is_utc ) {
			// Store the site's wall time for a UTC stamp, so the editor sees
			// the same moment the calendar client shows.
			$timezone = wp_timezone_string();

			return array(
				'local'    => Timezone::from_utc( $wall, $timezone ),
				'timezone' => $timezone,
				'all_day'  => false,
			);
		}

		$timezone = '' !== $tzid && in_array( $tzid, timezone_identifiers_list(), true ) ? $tzid : wp_timezone_string();

		return array(
			'local'    => $wall,
			'timezone' => $timezone,
			'all_day'  => false,
		);
	}

	/**
	 * Applies an ISO 8601 DURATION to a start moment.
	 *
	 * @param array{local:string,timezone:string,all_day:bool} $start    Start.
	 * @param string                                           $duration DURATION value.
	 * @return array{local:string,timezone:string,all_day:bool}|null
	 */
	private function apply_duration( array $start, $duration ) {
		if ( ! preg_match( '/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim( $duration ), $m ) ) {
			return null;
		}

		$seconds = 0;
		$seconds += isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] * WEEK_IN_SECONDS : 0;
		$seconds += isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] * DAY_IN_SECONDS : 0;
		$seconds += isset( $m[4] ) && '' !== $m[4] ? (int) $m[4] * HOUR_IN_SECONDS : 0;
		$seconds += isset( $m[5] ) && '' !== $m[5] ? (int) $m[5] * MINUTE_IN_SECONDS : 0;
		$seconds += isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0;

		if ( isset( $m[1] ) && '-' === $m[1] ) {
			$seconds = -$seconds;
		}

		$base = Timezone::make( $start['local'], $start['timezone'] );

		if ( ! $base ) {
			return null;
		}

		return array(
			'local'    => $base->modify( ( $seconds >= 0 ? '+' : '' ) . $seconds . ' seconds' )->format( 'Y-m-d H:i:s' ),
			'timezone' => $start['timezone'],
			'all_day'  => $start['all_day'],
		);
	}

	/**
	 * Collects EXDATE values as local dates.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $block Raw properties.
	 * @return array<int,string>
	 */
	private function exdates( array $block ) {
		if ( empty( $block['EXDATE'] ) ) {
			return array();
		}

		$dates = array();

		foreach ( $block['EXDATE'] as $property ) {
			foreach ( explode( ',', (string) $property['value'] ) as $value ) {
				if ( preg_match( '/^(\d{4})(\d{2})(\d{2})/', trim( $value ), $m ) ) {
					$dates[] = sprintf( '%s-%s-%s', $m[1], $m[2], $m[3] );
				}
			}
		}

		return array_values( array_unique( $dates ) );
	}

	/**
	 * Organizer display name, when the file carries one.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $block Raw properties.
	 * @return string
	 */
	private function organizer( array $block ) {
		if ( ! isset( $block['ORGANIZER'][0] ) ) {
			return '';
		}

		$params = $block['ORGANIZER'][0]['params'];

		if ( isset( $params['CN'] ) && '' !== $params['CN'] ) {
			return $this->text( $params['CN'] );
		}

		$value = (string) $block['ORGANIZER'][0]['value'];

		return $this->text( preg_replace( '/^mailto:/i', '', $value ) );
	}

	/**
	 * Unescapes an iCalendar TEXT value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function text( $value ) {
		$value = str_replace( array( '\\n', '\\N' ), "\n", (string) $value );
		$value = str_replace( array( '\\,', '\;', '\\\\' ), array( ',', ';', '\\' ), $value );

		return trim( $value );
	}

	/**
	 * Imports parsed events.
	 *
	 * @param array<int,array<string,mixed>> $events  Parsed events.
	 * @param array<string,mixed>            $options status, venue, dry_run.
	 * @return array{created:int,updated:int,skipped:int,messages:array<int,string>}
	 */
	public function import( array $events, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'status'  => 'draft',
				'venue'   => true,
				'dry_run' => false,
			)
		);

		$status = in_array( $options['status'], array( 'publish', 'draft', 'pending' ), true ) ? $options['status'] : 'draft';

		$report = array(
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'messages' => array(),
		);

		foreach ( $events as $event ) {
			$existing = '' !== $event['uid'] ? $this->find_by_uid( $event['uid'] ) : null;

			if ( $options['dry_run'] ) {
				if ( $existing ) {
					++$report['updated'];
				} else {
					++$report['created'];
				}

				continue;
			}

			$postarr = array(
				'post_type'    => XODW_CC_POST_TYPE,
				'post_status'  => $status,
				'post_title'   => $event['title'],
				'post_content' => $event['description'],
			);

			if ( $existing ) {
				$postarr['ID'] = $existing;
				$event_id      = wp_update_post( $postarr, true );
			} else {
				$event_id = wp_insert_post( $postarr, true );
			}

			if ( is_wp_error( $event_id ) ) {
				++$report['skipped'];
				$report['messages'][] = $event_id->get_error_message();

				continue;
			}

			$event_id = (int) $event_id;

			if ( '' !== $event['uid'] ) {
				update_post_meta( $event_id, self::UID_META, sanitize_text_field( $event['uid'] ) );
			}

			update_post_meta( $event_id, self::SOURCE_META, 1 );
			update_post_meta( $event_id, '_xodw_cc_all_day', $event['all_day'] ? 1 : 0 );
			update_post_meta( $event_id, '_xodw_cc_timezone', EventMeta::sanitize_timezone( $event['timezone'] ) );
			update_post_meta( $event_id, '_xodw_cc_start', EventMeta::sanitize_datetime( $event['start'] ) );
			update_post_meta( $event_id, '_xodw_cc_end', EventMeta::sanitize_datetime( $event['end'] ) );
			update_post_meta( $event_id, '_xodw_cc_url', sanitize_url( $event['url'] ) );

			$this->apply_recurrence( $event_id, $event );

			if ( $options['venue'] && '' !== $event['location'] ) {
				$this->assign_term( $event_id, $event['location'], XODW_CC_TAX_VENUE );
			}

			if ( '' !== $event['organizer'] ) {
				$this->assign_term( $event_id, $event['organizer'], XODW_CC_TAX_ORGANIZER );
			}

			EventMeta::normalize( $event_id );
			xodw_cc_generate_instances( $event_id, true );

			if ( $existing ) {
				++$report['updated'];
			} else {
				++$report['created'];
			}

			/**
			 * Fires after one event was imported from an .ics document.
			 *
			 * @since 1.0.0
			 *
			 * @param int                 $event_id Event post ID.
			 * @param array<string,mixed> $event    Parsed event.
			 */
			do_action( 'xodw_cc_ics_imported', $event_id, $event );
		}

		if ( ! $options['dry_run'] ) {
			xodw_cc_flush_cache();
		}

		return $report;
	}

	/**
	 * Writes the recurrence fields of an imported event.
	 *
	 * @param int                 $event_id Event post ID.
	 * @param array<string,mixed> $event    Parsed event.
	 * @return void
	 */
	private function apply_recurrence( $event_id, array $event ) {
		if ( empty( $event['recurrence'] ) ) {
			update_post_meta( $event_id, '_xodw_cc_recur_freq', 'none' );

			return;
		}

		foreach ( $event['recurrence'] as $key => $value ) {
			update_post_meta( $event_id, $key, $value );
		}

		if ( ! empty( $event['exdates'] ) ) {
			update_post_meta( $event_id, '_xodw_cc_recur_exdates', EventMeta::sanitize_date_list( implode( ',', $event['exdates'] ) ) );
		}
	}

	/**
	 * Finds a previously imported event by its UID.
	 *
	 * @param string $uid iCalendar UID.
	 * @return int|null
	 */
	public function find_by_uid( $uid ) {
		$found = get_posts(
			array(
				'post_type'      => XODW_CC_POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'   => self::UID_META,
						'value' => $uid,
					),
				),
			)
		);

		return ! empty( $found ) ? (int) $found[0] : null;
	}

	/**
	 * Assigns a term by name, creating it when it is new.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	private function assign_term( $event_id, $name, $taxonomy ) {
		// Locations often carry the full address; the first segment is the name.
		$name = trim( explode( ',', $name )[0] );

		if ( '' === $name ) {
			return;
		}

		$existing = term_exists( $name, $taxonomy );

		if ( $existing && isset( $existing['term_id'] ) ) {
			wp_set_object_terms( $event_id, array( (int) $existing['term_id'] ), $taxonomy );

			return;
		}

		$created = wp_insert_term( $name, $taxonomy );

		if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
			wp_set_object_terms( $event_id, array( (int) $created['term_id'] ), $taxonomy );
		}
	}

	/**
	 * Reads a document from a URL.
	 *
	 * @param string $url Feed URL.
	 * @return string|\WP_Error
	 */
	public function fetch( $url ) {
		$url = sanitize_url( $url );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'xodw_cc_ics_url', __( 'That does not look like a valid URL.', 'calendarcore' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 25,
				'headers' => array( 'Accept' => 'text/calendar, text/plain' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new \WP_Error( 'xodw_cc_ics_http', __( 'The calendar could not be downloaded.', 'calendarcore' ) );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new \WP_Error( 'xodw_cc_ics_size', __( 'That calendar is too large to import.', 'calendarcore' ) );
		}

		if ( false === strpos( $body, 'BEGIN:VCALENDAR' ) ) {
			return new \WP_Error( 'xodw_cc_ics_format', __( 'That file is not an iCalendar document.', 'calendarcore' ) );
		}

		return $body;
	}
}
