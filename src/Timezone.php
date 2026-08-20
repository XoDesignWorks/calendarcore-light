<?php
/**
 * Timezone helpers. All storage is UTC, all display is local.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Converts between wall-clock time in an event timezone and UTC storage time.
 */
class Timezone {

	/**
	 * Resolved timezone objects, keyed by identifier.
	 *
	 * @var array<string,DateTimeZone>
	 */
	private static $cache = array();

	/**
	 * Resolves a timezone string into an object, falling back to the site
	 * timezone and finally to UTC.
	 *
	 * @param string $timezone Timezone identifier or offset.
	 * @return DateTimeZone
	 */
	public static function resolve( $timezone = '' ) {
		$timezone = is_string( $timezone ) ? trim( $timezone ) : '';

		if ( '' === $timezone ) {
			return wp_timezone();
		}

		if ( isset( self::$cache[ $timezone ] ) ) {
			return self::$cache[ $timezone ];
		}

		try {
			self::$cache[ $timezone ] = new DateTimeZone( $timezone );
		} catch ( Exception $e ) {
			self::$cache[ $timezone ] = wp_timezone();
		}

		return self::$cache[ $timezone ];
	}

	/**
	 * Builds a DateTimeImmutable from a wall-clock string.
	 *
	 * @param string $datetime Datetime string.
	 * @param string $timezone Timezone the string belongs to.
	 * @return DateTimeImmutable|null
	 */
	public static function make( $datetime, $timezone = '' ) {
		$datetime = trim( (string) $datetime );

		if ( '' === $datetime ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $datetime, self::resolve( $timezone ) );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Converts a local wall-clock datetime into UTC storage format.
	 *
	 * @param string $local    Local datetime.
	 * @param string $timezone Timezone of the local value.
	 * @return string UTC 'Y-m-d H:i:s', empty string on failure.
	 */
	public static function to_utc( $local, $timezone = '' ) {
		$date = self::make( $local, $timezone );

		if ( ! $date ) {
			return '';
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Converts a UTC datetime into a local wall-clock datetime.
	 *
	 * @param string $utc      UTC datetime.
	 * @param string $timezone Target timezone.
	 * @return string Local 'Y-m-d H:i:s', empty string on failure.
	 */
	public static function from_utc( $utc, $timezone = '' ) {
		$date = self::make( $utc, 'UTC' );

		if ( ! $date ) {
			return '';
		}

		return $date->setTimezone( self::resolve( $timezone ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * ISO-8601 representation in UTC, the format handed to Intl.DateTimeFormat.
	 *
	 * @param string $utc UTC datetime.
	 * @return string
	 */
	public static function to_iso( $utc ) {
		$date = self::make( $utc, 'UTC' );

		return $date ? $date->format( 'Y-m-d\TH:i:s\Z' ) : '';
	}

	/**
	 * Formats a UTC datetime for display, using WordPress date settings.
	 *
	 * @param string $utc      UTC datetime.
	 * @param string $format   PHP date format. Empty means the plugin default.
	 * @param string $timezone Display timezone.
	 * @return string
	 */
	public static function format( $utc, $format = '', $timezone = '' ) {
		$date = self::make( $utc, 'UTC' );

		if ( ! $date ) {
			return '';
		}

		if ( '' === $format ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}

		// wp_date() loads the locale to translate month and weekday names. A
		// format like 'H:i' needs none of that, and a month grid asks for
		// hundreds of them, so plain formatting is used where it is identical.
		if ( ! preg_match( '/[DlFMSaA]/', preg_replace( '/\\\\./', '', $format ) ) ) {
			return $date->setTimezone( self::resolve( $timezone ) )->format( $format );
		}

		return wp_date( $format, $date->getTimestamp(), self::resolve( $timezone ) );
	}

	/**
	 * Human readable timezone label, e.g. "Europe/Berlin (CEST)".
	 *
	 * @param string $timezone Timezone identifier.
	 * @param string $utc      Reference moment, needed for the DST abbreviation.
	 * @return string
	 */
	public static function label( $timezone = '', $utc = '' ) {
		$zone = self::resolve( $timezone );
		$date = self::make( '' !== $utc ? $utc : 'now', 'UTC' );

		if ( ! $date ) {
			return $zone->getName();
		}

		$abbr = $date->setTimezone( $zone )->format( 'T' );

		return sprintf( '%s (%s)', $zone->getName(), $abbr );
	}
}
