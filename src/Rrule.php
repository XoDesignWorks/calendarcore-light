<?php
/**
 * Translation between our recurrence meta and an iCalendar RRULE.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Google stores repetition as an RRULE string, we store it as a handful of meta
 * fields. This class converts both ways so a series stays a series on both
 * sides instead of exploding into hundreds of single events.
 */
class Rrule {

	/**
	 * Builds an RRULE line from event meta.
	 *
	 * @param array<string,mixed> $meta Event meta from EventMeta::get().
	 * @return string Empty string for a one-off event.
	 */
	public static function from_meta( array $meta ) {
		$freq = isset( $meta['recur_freq'] ) ? (string) $meta['recur_freq'] : 'none';

		if ( 'none' === $freq ) {
			return '';
		}

		$parts = array( 'FREQ=' . strtoupper( $freq ) );
		$every = max( 1, (int) $meta['recur_interval'] );

		if ( $every > 1 ) {
			$parts[] = 'INTERVAL=' . $every;
		}

		if ( 'weekly' === $freq && '' !== $meta['recur_byday'] ) {
			$parts[] = 'BYDAY=' . strtoupper( $meta['recur_byday'] );
		}

		if ( 'monthly' === $freq && 'weekday' === $meta['recur_monthly_mode'] ) {
			$start = Timezone::make( $meta['start'], $meta['timezone'] );

			if ( $start ) {
				$day      = (int) $start->format( 'j' );
				$weekday  = EventMeta::WEEKDAYS[ (int) $start->format( 'N' ) - 1 ];
				$position = ( $day + 7 ) > (int) $start->format( 't' ) ? -1 : (int) ceil( $day / 7 );
				$parts[]  = 'BYDAY=' . $position . $weekday;
			}
		}

		if ( (int) $meta['recur_count'] > 0 ) {
			$parts[] = 'COUNT=' . (int) $meta['recur_count'];
		} elseif ( '' !== $meta['recur_until'] ) {
			$until = Timezone::to_utc( $meta['recur_until'] . ' 23:59:59', $meta['timezone'] );

			if ( '' !== $until ) {
				$parts[] = 'UNTIL=' . gmdate( 'Ymd\THis\Z', strtotime( $until . ' UTC' ) );
			}
		}

		return 'RRULE:' . implode( ';', $parts );
	}

	/**
	 * Converts an RRULE line into our meta fields.
	 *
	 * @param string $rrule RRULE line, with or without the prefix.
	 * @return array<string,mixed> Meta fragment, empty when unsupported.
	 */
	public static function to_meta( $rrule ) {
		$rrule = strtoupper( trim( (string) $rrule ) );
		$rrule = preg_replace( '/^RRULE:/', '', $rrule );

		if ( '' === $rrule ) {
			return array();
		}

		$pairs = array();

		foreach ( explode( ';', $rrule ) as $chunk ) {
			$pair = explode( '=', $chunk, 2 );

			if ( 2 === count( $pair ) ) {
				$pairs[ $pair[0] ] = $pair[1];
			}
		}

		$freq = isset( $pairs['FREQ'] ) ? strtolower( $pairs['FREQ'] ) : '';

		if ( ! in_array( $freq, array( 'daily', 'weekly', 'monthly', 'yearly' ), true ) ) {
			return array();
		}

		$meta = array(
			'_xodw_cc_recur_freq'     => $freq,
			'_xodw_cc_recur_interval' => isset( $pairs['INTERVAL'] ) ? max( 1, (int) $pairs['INTERVAL'] ) : 1,
			'_xodw_cc_recur_count'    => isset( $pairs['COUNT'] ) ? max( 0, (int) $pairs['COUNT'] ) : 0,
			'_xodw_cc_recur_until'    => '',
			'_xodw_cc_recur_byday'    => '',
		);

		if ( isset( $pairs['UNTIL'] ) && preg_match( '/^(\d{4})(\d{2})(\d{2})/', $pairs['UNTIL'], $m ) ) {
			$meta['_xodw_cc_recur_until'] = sprintf( '%s-%s-%s', $m[1], $m[2], $m[3] );
		}

		if ( isset( $pairs['BYDAY'] ) ) {
			$days     = array();
			$position = 0;

			foreach ( explode( ',', $pairs['BYDAY'] ) as $token ) {
				if ( preg_match( '/^(-?\d)?(MO|TU|WE|TH|FR|SA|SU)$/', trim( $token ), $m ) ) {
					if ( '' !== $m[1] ) {
						$position = (int) $m[1];
					}

					$days[] = $m[2];
				}
			}

			if ( ! empty( $days ) ) {
				$meta['_xodw_cc_recur_byday'] = implode( ',', $days );
			}

			if ( 'monthly' === $freq && 0 !== $position ) {
				$meta['_xodw_cc_recur_monthly_mode'] = 'weekday';
			}
		}

		return $meta;
	}
}
