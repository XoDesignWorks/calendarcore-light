<?php
/**
 * Gateway for the occurrence table.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * The only custom table in the plugin: event_id, start_datetime, end_datetime.
 * All datetimes are UTC.
 */
class Instances {

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'xodw_cc_event_instances';
	}

	/**
	 * Whether the table exists. Guards against a failed activation.
	 *
	 * @return bool
	 */
	public static function exists() {
		global $wpdb;

		$cached = wp_cache_get( 'xodw_cc_table_exists', 'xodw_cc' );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$exists = ( $found === $table );

		wp_cache_set( 'xodw_cc_table_exists', $exists ? 1 : 0, 'xodw_cc', HOUR_IN_SECONDS );

		return $exists;
	}

	/**
	 * Replaces all occurrences of an event in a single transaction-ish pass.
	 *
	 * @param int                                   $event_id     Event post ID.
	 * @param array<int,array{start:string,end:string}> $occurrences UTC start/end pairs.
	 * @return int Number of inserted rows.
	 */
	public static function replace( $event_id, array $occurrences ) {
		global $wpdb;

		$event_id = (int) $event_id;

		if ( $event_id <= 0 || ! self::exists() ) {
			return 0;
		}

		self::delete_event( $event_id );

		if ( empty( $occurrences ) ) {
			return 0;
		}

		$table  = self::table();
		$values = array();
		$args   = array();

		foreach ( $occurrences as $occurrence ) {
			if ( empty( $occurrence['start'] ) || empty( $occurrence['end'] ) ) {
				continue;
			}

			$values[] = '(%d, %s, %s)';
			$args[]   = $event_id;
			$args[]   = $occurrence['start'];
			$args[]   = $occurrence['end'];
		}

		if ( empty( $values ) ) {
			return 0;
		}

		$sql = "INSERT IGNORE INTO {$table} (event_id, start_datetime, end_datetime) VALUES " . implode( ', ', $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$inserted = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		return (int) $inserted;
	}

	/**
	 * Deletes every occurrence of an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return int Rows removed.
	 */
	public static function delete_event( $event_id ) {
		global $wpdb;

		if ( ! self::exists() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->delete( self::table(), array( 'event_id' => (int) $event_id ), array( '%d' ) );
	}

	/**
	 * Latest stored occurrence start of an event.
	 *
	 * @param int $event_id Event post ID.
	 * @return string UTC datetime, empty when the event has no occurrence.
	 */
	public static function last_start( $event_id ) {
		global $wpdb;

		if ( ! self::exists() ) {
			return '';
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(start_datetime) FROM {$table} WHERE event_id = %d", (int) $event_id ) );

		return $value ? (string) $value : '';
	}

	/**
	 * Total number of rows. Used by the settings screen and the benchmark page.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		if ( ! self::exists() ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Removes occurrences that ended before a given moment.
	 *
	 * @param string $before UTC datetime.
	 * @return int Rows removed.
	 */
	public static function prune_before( $before ) {
		global $wpdb;

		if ( ! self::exists() ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE end_datetime < %s", $before ) );
	}

	/**
	 * Removes orphaned rows whose event no longer exists or is not published.
	 *
	 * @return int Rows removed.
	 */
	public static function prune_orphans() {
		global $wpdb;

		if ( ! self::exists() ) {
			return 0;
		}

		$table = self::table();

		// The table name is built from $wpdb->prefix, every value is prepared.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE i FROM {$table} i
				 LEFT JOIN {$wpdb->posts} p ON p.ID = i.event_id
				 WHERE p.ID IS NULL OR p.post_type != %s",
				XODW_CC_POST_TYPE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $removed;
	}

	/**
	 * Removes past occurrences of recurring events only. One-off events keep
	 * their row forever, so the archive stays complete.
	 *
	 * @param string $before UTC datetime.
	 * @return int Rows removed.
	 */
	public static function prune_recurring_before( $before ) {
		global $wpdb;

		if ( ! self::exists() ) {
			return 0;
		}

		$table = self::table();

		// The table name is built from $wpdb->prefix, every value is prepared.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE i FROM {$table} i
				 INNER JOIN {$wpdb->postmeta} pm
				 ON pm.post_id = i.event_id AND pm.meta_key = '_xodw_cc_recur_freq'
				 WHERE pm.meta_value != 'none' AND i.end_datetime < %s",
				$before
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $removed;
	}
}
