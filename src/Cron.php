<?php
/**
 * Background work: rolling horizon and housekeeping.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Pushes the generation horizon forward in batches, so a site with thousands
 * of recurring events never blocks a page load and never times out.
 */
class Cron {

	const HOOK_HORIZON = 'xodw_cc_extend_horizon';
	const HOOK_CLEANUP = 'xodw_cc_cleanup_instances';
	const BATCH_SIZE   = 200;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( self::HOOK_HORIZON, array( $this, 'extend_horizon' ) );
		add_action( self::HOOK_CLEANUP, array( $this, 'cleanup' ) );
	}

	/**
	 * Registers the schedules if they are missing.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_HORIZON ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::HOOK_HORIZON );
		}

		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + 600, 'daily', self::HOOK_CLEANUP );
		}
	}

	/**
	 * Removes the schedules.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_HORIZON );
		wp_clear_scheduled_hook( self::HOOK_CLEANUP );
	}

	/**
	 * Regenerates the events whose horizon is running out.
	 *
	 * Processes at most BATCH_SIZE events per run and remembers where it
	 * stopped, so the work spreads across runs.
	 *
	 * @return int Number of events processed.
	 */
	public function extend_horizon() {
		$months = min( 12, max( 1, (int) xodw_cc_setting( 'horizon_months', 12 ) ) );
		// Refill when less than a third of the horizon is left.
		$threshold = gmdate( 'Y-m-d H:i:s', time() + (int) ( $months * MONTH_IN_SECONDS * 0.66 ) );

		$query = new \WP_Query(
			array(
				'post_type'              => XODW_CC_POST_TYPE,
				'post_status'            => array( 'publish', 'future', 'private' ),
				'posts_per_page'         => self::BATCH_SIZE,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => '_xodw_cc_recur_freq',
						'value'   => 'none',
						'compare' => '!=',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_xodw_cc_recur_complete',
							'value'   => '1',
							'compare' => '!=',
						),
						array(
							'key'     => '_xodw_cc_recur_complete',
							'compare' => 'NOT EXISTS',
						),
					),
					array(
						'key'     => '_xodw_cc_horizon_end',
						'value'   => $threshold,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		$engine    = new RecurringEngine();
		$processed = 0;

		foreach ( $query->posts as $event_id ) {
			$engine->generate( (int) $event_id, true );
			++$processed;
		}

		/**
		 * Fires after a horizon batch was processed.
		 *
		 * @since 1.0.0
		 *
		 * @param int $processed Number of events regenerated.
		 */
		do_action( 'xodw_cc_horizon_extended', $processed );

		return $processed;
	}

	/**
	 * Housekeeping: orphaned rows and very old recurring occurrences.
	 *
	 * @return void
	 */
	public function cleanup() {
		Instances::prune_orphans();

		/**
		 * Filters how many months of past occurrences of recurring events are
		 * kept. Occurrences of one-off events are never pruned.
		 *
		 * @since 1.0.0
		 *
		 * @param int $months Months of history to keep.
		 */
		$months = (int) apply_filters( 'xodw_cc_keep_past_months', 12 );

		if ( $months > 0 ) {
			Instances::prune_recurring_before( gmdate( 'Y-m-d H:i:s', time() - ( $months * MONTH_IN_SECONDS ) ) );
		}

		xodw_cc_flush_cache();
	}
}
