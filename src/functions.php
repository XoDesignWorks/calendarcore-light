<?php
/**
 * Procedural API. Every public helper is prefixed with xodw_cc_.
 *
 * @package XODW\CalendarCore
 */

defined( 'ABSPATH' ) || exit;

use XODW\CalendarCore\Instances;
use XODW\CalendarCore\Query;
use XODW\CalendarCore\RecurringEngine;
use XODW\CalendarCore\Rsvp;
use XODW\CalendarCore\Settings;
use XODW\CalendarCore\Timezone;

/**
 * All plugin settings, merged with defaults.
 *
 * @return array<string,mixed>
 */
function xodw_cc_settings() {
	return Settings::all();
}

/**
 * A single setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback when the key is unknown.
 * @return mixed
 */
function xodw_cc_setting( $key, $default = null ) {
	return Settings::get( $key, $default );
}

/**
 * Whether an optional module is switched on.
 *
 * @param string $module Module slug: recurring|rsvp|ics|timezone|blocks|shortcodes.
 * @return bool
 */
function xodw_cc_module_enabled( $module ) {
	return Settings::module_enabled( $module );
}

/**
 * Resolves a timezone string (or empty value) into a DateTimeZone.
 *
 * @param string $timezone Timezone identifier or offset. Empty means site timezone.
 * @return DateTimeZone
 */
function xodw_cc_calculate_timezone( $timezone = '' ) {
	return Timezone::resolve( $timezone );
}

/**
 * Converts a local wall-clock datetime to UTC.
 *
 * @param string $local    Datetime as 'Y-m-d H:i:s'.
 * @param string $timezone Timezone the local value belongs to.
 * @return string UTC datetime as 'Y-m-d H:i:s'.
 */
function xodw_cc_to_utc( $local, $timezone = '' ) {
	return Timezone::to_utc( $local, $timezone );
}

/**
 * Converts a UTC datetime into a local wall-clock datetime.
 *
 * @param string $utc      UTC datetime as 'Y-m-d H:i:s'.
 * @param string $timezone Target timezone.
 * @return string Local datetime as 'Y-m-d H:i:s'.
 */
function xodw_cc_from_utc( $utc, $timezone = '' ) {
	return Timezone::from_utc( $utc, $timezone );
}

/**
 * (Re)builds the occurrence rows of an event.
 *
 * @param int  $event_id Event post ID.
 * @param bool $force    Rebuild even when the horizon is already covered.
 * @return int Number of stored occurrences.
 */
function xodw_cc_generate_instances( $event_id, $force = false ) {
	return ( new RecurringEngine() )->generate( (int) $event_id, (bool) $force );
}

/**
 * Occurrences for a date range, ready for rendering.
 *
 * @param array<string,mixed> $args See XODW\CalendarCore\Query::defaults().
 * @return XODW\CalendarCore\Occurrence[]
 */
function xodw_cc_get_occurrences( array $args = array() ) {
	return ( new Query() )->get_occurrences( $args );
}

/**
 * Upcoming occurrences shortcut.
 *
 * @param int                 $limit Maximum number of occurrences.
 * @param array<string,mixed> $args  Extra query arguments.
 * @return XODW\CalendarCore\Occurrence[]
 */
function xodw_cc_get_upcoming( $limit = 5, array $args = array() ) {
	return xodw_cc_get_occurrences(
		array_merge(
			array(
				'from'  => gmdate( 'Y-m-d H:i:s' ),
				'limit' => (int) $limit,
			),
			$args
		)
	);
}

/**
 * Start of an event in UTC.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function xodw_cc_event_start( $event_id ) {
	return (string) get_post_meta( (int) $event_id, '_xodw_cc_start_utc', true );
}

/**
 * End of an event in UTC.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function xodw_cc_event_end( $event_id ) {
	return (string) get_post_meta( (int) $event_id, '_xodw_cc_end_utc', true );
}

/**
 * Download URL of the .ics file for one occurrence.
 *
 * @param int    $event_id Event post ID.
 * @param string $start    Occurrence start in UTC. Empty for the event start.
 * @return string
 */
function xodw_cc_ics_url( $event_id, $start = '' ) {
	return XODW\CalendarCore\Ics::event_url( (int) $event_id, $start );
}

/**
 * Number of confirmed RSVP guests for an occurrence.
 *
 * @param int    $event_id Event post ID.
 * @param string $start    Occurrence start in UTC.
 * @return int
 */
function xodw_cc_rsvp_count( $event_id, $start = '' ) {
	return Rsvp::count( (int) $event_id, $start );
}

/**
 * Cache generation counter. Part of every transient key, so bumping it
 * invalidates all cached queries at once.
 *
 * @return int
 */
function xodw_cc_cache_version() {
	return (int) get_option( 'xodw_cc_cache_version', 1 );
}

/**
 * Invalidates every cached event query.
 *
 * @return void
 */
function xodw_cc_flush_cache() {
	update_option( 'xodw_cc_cache_version', xodw_cc_cache_version() + 1, false );

	/**
	 * Fires after the event query cache has been invalidated.
	 *
	 * @since 1.0.0
	 */
	do_action( 'xodw_cc_cache_flushed' );
}

/**
 * Whether a valid Pro license unlocks the paid modules.
 *
 * @return bool
 */
function xodw_cc_is_pro() {
	/**
	 * Filters the Pro state. The Pro add-on returns true here once its
	 * license key validates.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $is_pro Whether Pro features are unlocked.
	 */
	return (bool) apply_filters( 'xodw_cc_license_is_pro', false );
}

/**
 * Rows currently stored in the occurrence table. Used by the benchmark page.
 *
 * @return int
 */
function xodw_cc_instances_count() {
	return Instances::count();
}
