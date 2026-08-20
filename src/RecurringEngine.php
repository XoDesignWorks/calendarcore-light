<?php
/**
 * Recurrence expansion.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns a recurrence rule stored in postmeta into rows of the occurrence
 * table. Nothing is expanded further than the rolling horizon (12 months by
 * default) — a cron job pushes the horizon forward as time passes.
 */
class RecurringEngine {

	/**
	 * Hard iteration ceiling, protects against pathological rules.
	 */
	const MAX_ITERATIONS = 5000;

	/**
	 * Rebuilds the occurrences of one event.
	 *
	 * @param int  $event_id Event post ID.
	 * @param bool $force    Reserved: regenerate even when nothing changed.
	 * @return int Number of stored occurrences.
	 */
	public function generate( $event_id, $force = false ) {
		$event_id = (int) $event_id;
		$post     = get_post( $event_id );

		if ( ! $post || XODW_CC_POST_TYPE !== $post->post_type ) {
			Instances::delete_event( $event_id );

			return 0;
		}

		/**
		 * Filters which post statuses get occurrence rows.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int,string> $statuses Post statuses.
		 */
		$statuses = apply_filters( 'xodw_cc_instance_statuses', array( 'publish', 'future', 'private' ) );

		if ( ! in_array( $post->post_status, (array) $statuses, true ) ) {
			Instances::delete_event( $event_id );
			update_post_meta( $event_id, '_xodw_cc_instances_count', 0 );

			return 0;
		}

		// Recompute the derived UTC fields first: a direct meta write, an
		// importer or a cron run must not expand a stale rule.
		$data = $force ? EventMeta::normalize( $event_id ) : EventMeta::get( $event_id );

		if ( '' === $data['start_utc'] ) {
			$data = EventMeta::normalize( $event_id );
		}

		$occurrences = $this->build( $data );
		$stored      = Instances::replace( $event_id, $occurrences );
		$last        = ! empty( $occurrences ) ? end( $occurrences ) : array( 'start' => $data['start_utc'] );

		update_post_meta( $event_id, '_xodw_cc_instances_count', count( $occurrences ) );
		update_post_meta( $event_id, '_xodw_cc_horizon_end', isset( $last['start'] ) ? $last['start'] : '' );

		xodw_cc_flush_cache();

		/**
		 * Fires after the occurrences of an event were regenerated.
		 *
		 * @since 1.0.0
		 *
		 * @param int                                       $event_id    Event post ID.
		 * @param array<int,array{start:string,end:string}> $occurrences Stored occurrences (UTC).
		 * @param array<string,mixed>                       $data        Event meta.
		 */
		do_action( 'xodw_cc_recurring_generated', $event_id, $occurrences, $data );

		return $stored;
	}

	/**
	 * Expands the rule of an event into UTC start/end pairs.
	 *
	 * @param array<string,mixed> $data Event meta from EventMeta::get().
	 * @return array<int,array{start:string,end:string}>
	 */
	public function build( array $data ) {
		$timezone = isset( $data['timezone'] ) ? (string) $data['timezone'] : '';
		$start    = Timezone::make( $data['start'], $timezone );

		if ( ! $start ) {
			return array();
		}

		$duration = $this->duration( $data );
		$dates    = array();
		$freq     = isset( $data['recur_freq'] ) ? $data['recur_freq'] : 'none';

		if ( 'none' === $freq || ! xodw_cc_module_enabled( 'recurring' ) ) {
			$dates = array( $start );
		} else {
			$dates = $this->expand( $start, $data );
		}

		/**
		 * Filters the expanded local start dates before they are stored.
		 * Custom rules (for example "every second Friday except August") plug
		 * in here without touching the engine.
		 *
		 * @since 1.0.0
		 *
		 * @param DateTimeImmutable[]  $dates Local occurrence starts.
		 * @param array<string,mixed>  $data  Event meta.
		 */
		$dates = apply_filters( 'xodw_cc_recurring_dates', $dates, $data );

		$utc         = new DateTimeZone( 'UTC' );
		$occurrences = array();
		$seen        = array();

		foreach ( $dates as $date ) {
			if ( ! $date instanceof DateTimeImmutable ) {
				continue;
			}

			$start_utc = $date->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

			if ( isset( $seen[ $start_utc ] ) ) {
				continue;
			}

			$seen[ $start_utc ] = true;

			$occurrences[] = array(
				'start' => $start_utc,
				'end'   => gmdate( 'Y-m-d H:i:s', $date->getTimestamp() + $duration ),
			);
		}

		usort(
			$occurrences,
			static function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		return $occurrences;
	}

	/**
	 * Duration of one occurrence in seconds.
	 *
	 * @param array<string,mixed> $data Event meta.
	 * @return int
	 */
	private function duration( array $data ) {
		$start = Timezone::make( $data['start_utc'], 'UTC' );
		$end   = Timezone::make( $data['end_utc'], 'UTC' );

		if ( ! $start || ! $end ) {
			return HOUR_IN_SECONDS;
		}

		$duration = $end->getTimestamp() - $start->getTimestamp();

		return $duration > 0 ? $duration : HOUR_IN_SECONDS;
	}

	/**
	 * Walks the rule and collects local occurrence starts.
	 *
	 * @param DateTimeImmutable   $start Base occurrence.
	 * @param array<string,mixed> $data  Event meta.
	 * @return DateTimeImmutable[]
	 */
	private function expand( DateTimeImmutable $start, array $data ) {
		$freq     = (string) $data['recur_freq'];
		$interval = max( 1, (int) $data['recur_interval'] );
		$count    = max( 0, (int) $data['recur_count'] );
		$max      = min( (int) xodw_cc_setting( 'max_instances', 730 ), 2000 );

		$horizon = $this->horizon( $start );
		$until   = $this->until( $data, $start );
		$exdates = $this->exdates( $data );

		$dates      = array();
		$cycle      = 0;
		$iterations = 0;
		// Candidates the rule produced, including the ones removed by an
		// exception date: COUNT applies before EXDATE, as in RFC 5545.
		$produced = 0;

		while ( count( $dates ) < $max && $iterations < self::MAX_ITERATIONS ) {
			++$iterations;

			$candidates = $this->cycle_dates( $freq, $start, $cycle, $interval, $data );
			++$cycle;

			if ( empty( $candidates ) ) {
				// Skipped month (e.g. the 31st in February): keep walking.
				if ( $cycle > 1 && $this->cycle_start( $freq, $start, $cycle, $interval ) > $horizon ) {
					break;
				}

				continue;
			}

			$past_horizon = true;

			foreach ( $candidates as $candidate ) {
				if ( $candidate < $start ) {
					$past_horizon = false;
					continue;
				}

				if ( $candidate > $horizon ) {
					continue;
				}

				$past_horizon = false;

				if ( $until && $candidate > $until ) {
					break 2;
				}

				++$produced;

				if ( ! isset( $exdates[ $candidate->format( 'Y-m-d' ) ] ) ) {
					$dates[] = $candidate;
				}

				if ( $count > 0 && $produced >= $count ) {
					break 2;
				}

				if ( count( $dates ) >= $max ) {
					break 2;
				}
			}

			if ( $past_horizon ) {
				break;
			}
		}

		if ( empty( $dates ) ) {
			// An event that starts beyond the horizon still needs one row.
			$dates[] = $start;
		}

		$complete = ( $count > 0 && $produced >= $count ) || ( $until && end( $dates ) >= $until );
		update_post_meta( (int) $data['id'], '_xodw_cc_recur_complete', $complete ? 1 : 0 );

		return $dates;
	}

	/**
	 * Latest local moment occurrences are generated for.
	 *
	 * @param DateTimeImmutable $start Base occurrence, carries the timezone.
	 * @return DateTimeImmutable
	 */
	private function horizon( DateTimeImmutable $start ) {
		$months = min( 12, max( 1, (int) xodw_cc_setting( 'horizon_months', 12 ) ) );
		$now    = new DateTimeImmutable( 'now', $start->getTimezone() );
		$base   = $now > $start ? $now : $start;

		/**
		 * Filters the generation horizon. Hard capped at 12 months by the
		 * settings sanitiser: pre-generating years of rows is what makes other
		 * calendar plugins slow.
		 *
		 * @since 1.0.0
		 *
		 * @param DateTimeImmutable $horizon Horizon end.
		 * @param DateTimeImmutable $start   Base occurrence.
		 */
		return apply_filters( 'xodw_cc_recurring_horizon', $base->modify( '+' . $months . ' months' ), $start );
	}

	/**
	 * End of the rule, if the editor set one.
	 *
	 * @param array<string,mixed> $data  Event meta.
	 * @param DateTimeImmutable   $start Base occurrence.
	 * @return DateTimeImmutable|null
	 */
	private function until( array $data, DateTimeImmutable $start ) {
		if ( empty( $data['recur_until'] ) ) {
			return null;
		}

		$until = Timezone::make( $data['recur_until'] . ' 23:59:59', $data['timezone'] );

		return ( $until && $until >= $start ) ? $until : null;
	}

	/**
	 * Excluded dates, keyed by 'Y-m-d'.
	 *
	 * @param array<string,mixed> $data Event meta.
	 * @return array<string,bool>
	 */
	private function exdates( array $data ) {
		$dates = array();

		if ( empty( $data['recur_exdates'] ) ) {
			return $dates;
		}

		foreach ( explode( ',', (string) $data['recur_exdates'] ) as $date ) {
			$date = trim( $date );

			if ( '' !== $date ) {
				$dates[ $date ] = true;
			}
		}

		return $dates;
	}

	/**
	 * First local moment of a cycle, used for the horizon check.
	 *
	 * @param string            $freq     Frequency.
	 * @param DateTimeImmutable $start    Base occurrence.
	 * @param int               $cycle    Cycle index.
	 * @param int               $interval Interval.
	 * @return DateTimeImmutable
	 */
	private function cycle_start( $freq, DateTimeImmutable $start, $cycle, $interval ) {
		$step = $cycle * $interval;

		switch ( $freq ) {
			case 'daily':
				return $start->modify( '+' . $step . ' days' );
			case 'weekly':
				return $start->modify( '+' . $step . ' weeks' );
			case 'yearly':
				return $start->modify( '+' . $step . ' years' );
			case 'monthly':
			default:
				return $this->add_months( $start, $step );
		}
	}

	/**
	 * All occurrence candidates of one cycle.
	 *
	 * @param string              $freq     Frequency.
	 * @param DateTimeImmutable   $start    Base occurrence.
	 * @param int                 $cycle    Cycle index.
	 * @param int                 $interval Interval.
	 * @param array<string,mixed> $data     Event meta.
	 * @return DateTimeImmutable[]
	 */
	private function cycle_dates( $freq, DateTimeImmutable $start, $cycle, $interval, array $data ) {
		switch ( $freq ) {
			case 'daily':
				return array( $start->modify( '+' . ( $cycle * $interval ) . ' days' ) );

			case 'weekly':
				return $this->weekly_dates( $start, $cycle, $interval, $data );

			case 'monthly':
				return $this->monthly_dates( $start, $cycle, $interval, $data );

			case 'yearly':
				return $this->yearly_dates( $start, $cycle, $interval );
		}

		return array();
	}

	/**
	 * Weekly candidates, honouring the selected weekdays.
	 *
	 * @param DateTimeImmutable   $start    Base occurrence.
	 * @param int                 $cycle    Cycle index.
	 * @param int                 $interval Interval in weeks.
	 * @param array<string,mixed> $data     Event meta.
	 * @return DateTimeImmutable[]
	 */
	private function weekly_dates( DateTimeImmutable $start, $cycle, $interval, array $data ) {
		$byday = array();

		foreach ( explode( ',', (string) $data['recur_byday'] ) as $day ) {
			$day   = strtoupper( trim( $day ) );
			$index = array_search( $day, EventMeta::WEEKDAYS, true );

			if ( false !== $index ) {
				$byday[] = (int) $index;
			}
		}

		if ( empty( $byday ) ) {
			$byday = array( (int) $start->format( 'N' ) - 1 );
		}

		// Monday of the week the base occurrence belongs to.
		$week_start = $start->modify( '-' . ( (int) $start->format( 'N' ) - 1 ) . ' days' );
		$week_start = $week_start->modify( '+' . ( $cycle * $interval ) . ' weeks' );

		$dates = array();

		foreach ( $byday as $offset ) {
			$date = $week_start->modify( '+' . $offset . ' days' );
			// modify() keeps the time of day, but crossing a DST switch can
			// shift it, so the wall time is restored explicitly.
			$dates[] = $date->setTime( (int) $start->format( 'H' ), (int) $start->format( 'i' ), (int) $start->format( 's' ) );
		}

		return $dates;
	}

	/**
	 * Monthly candidates: same day number, or the same weekday position.
	 *
	 * @param DateTimeImmutable   $start    Base occurrence.
	 * @param int                 $cycle    Cycle index.
	 * @param int                 $interval Interval in months.
	 * @param array<string,mixed> $data     Event meta.
	 * @return DateTimeImmutable[]
	 */
	private function monthly_dates( DateTimeImmutable $start, $cycle, $interval, array $data ) {
		$step  = $cycle * $interval;
		$month = $start->modify( 'first day of this month' )->modify( '+' . $step . ' months' );

		if ( 'weekday' === $data['recur_monthly_mode'] ) {
			$weekday  = (int) $start->format( 'N' );
			$day      = (int) $start->format( 'j' );
			$position = (int) ceil( $day / 7 );
			$last     = ( $day + 7 ) > (int) $start->format( 't' );

			$date = $last
				? $this->last_weekday_of_month( $month, $weekday )
				: $this->nth_weekday_of_month( $month, $weekday, $position );

			if ( ! $date ) {
				return array();
			}

			return array( $date->setTime( (int) $start->format( 'H' ), (int) $start->format( 'i' ), (int) $start->format( 's' ) ) );
		}

		$day = (int) $start->format( 'j' );

		if ( $day > (int) $month->format( 't' ) ) {
			// The 31st simply does not happen in February: skip, do not clamp.
			return array();
		}

		return array( $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $day )
			->setTime( (int) $start->format( 'H' ), (int) $start->format( 'i' ), (int) $start->format( 's' ) ) );
	}

	/**
	 * Yearly candidates. February 29th is kept, not moved.
	 *
	 * @param DateTimeImmutable $start    Base occurrence.
	 * @param int               $cycle    Cycle index.
	 * @param int               $interval Interval in years.
	 * @return DateTimeImmutable[]
	 */
	private function yearly_dates( DateTimeImmutable $start, $cycle, $interval ) {
		$year  = (int) $start->format( 'Y' ) + ( $cycle * $interval );
		$month = (int) $start->format( 'n' );
		$day   = (int) $start->format( 'j' );

		if ( ! checkdate( $month, $day, $year ) ) {
			return array();
		}

		return array( $start->setDate( $year, $month, $day ) );
	}

	/**
	 * Adds months without rolling over into the next month.
	 *
	 * @param DateTimeImmutable $date   Base date.
	 * @param int               $months Months to add.
	 * @return DateTimeImmutable
	 */
	private function add_months( DateTimeImmutable $date, $months ) {
		return $date->modify( 'first day of this month' )->modify( '+' . (int) $months . ' months' );
	}

	/**
	 * Nth weekday of a month, e.g. the third Tuesday.
	 *
	 * @param DateTimeImmutable $month    Any day in the target month.
	 * @param int               $weekday  ISO weekday, 1 = Monday.
	 * @param int               $position 1-5.
	 * @return DateTimeImmutable|null
	 */
	private function nth_weekday_of_month( DateTimeImmutable $month, $weekday, $position ) {
		$first  = $month->modify( 'first day of this month' );
		$offset = ( $weekday - (int) $first->format( 'N' ) + 7 ) % 7;
		$day    = 1 + $offset + ( ( max( 1, (int) $position ) - 1 ) * 7 );

		if ( $day > (int) $first->format( 't' ) ) {
			return null;
		}

		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day );
	}

	/**
	 * Last given weekday of a month.
	 *
	 * @param DateTimeImmutable $month   Any day in the target month.
	 * @param int               $weekday ISO weekday, 1 = Monday.
	 * @return DateTimeImmutable|null
	 */
	private function last_weekday_of_month( DateTimeImmutable $month, $weekday ) {
		$last   = $month->modify( 'last day of this month' );
		$offset = ( (int) $last->format( 'N' ) - $weekday + 7 ) % 7;

		return $last->modify( '-' . $offset . ' days' );
	}
}
