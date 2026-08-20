<?php
/**
 * Event fields. Stored in postmeta, never in a custom table.
 *
 * @package XODW\CalendarCore;
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Declares, registers, sanitises and reads the event meta.
 */
class EventMeta {

	/**
	 * Recurrence frequencies the engine understands.
	 *
	 * @var array<int,string>
	 */
	const FREQUENCIES = array( 'none', 'daily', 'weekly', 'monthly', 'yearly' );

	/**
	 * Weekday codes in ISO order.
	 *
	 * @var array<int,string>
	 */
	const WEEKDAYS = array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' );

	/**
	 * Memoized schema.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $schema = null;

	/**
	 * Memoized event data, keyed by post ID. Rendering a month view touches the
	 * same events many times.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $cache = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( __CLASS__, 'register' ), 12 );
		add_action( 'xodw_cc_meta_normalized', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Drops the memoized data of an event, or of every event.
	 *
	 * @param int $event_id Event post ID, 0 for all.
	 * @return void
	 */
	public static function clear_cache( $event_id = 0 ) {
		if ( $event_id ) {
			unset( self::$cache[ (int) $event_id ] );

			return;
		}

		self::$cache = array();
	}

	/**
	 * Meta schema: key => type, default, sanitize callback.
	 *
	 * @return array<string,array{type:string,default:mixed,sanitize:callable,rest:bool}>
	 */
	public static function schema() {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		self::$schema = array(
			'_xodw_cc_start'              => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_datetime' ),
				'rest'     => true,
			),
			'_xodw_cc_end'                => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_datetime' ),
				'rest'     => true,
			),
			'_xodw_cc_start_utc'          => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_datetime' ),
				'rest'     => true,
			),
			'_xodw_cc_end_utc'            => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_datetime' ),
				'rest'     => true,
			),
			'_xodw_cc_all_day'            => array(
				'type'     => 'boolean',
				'default'  => false,
				'sanitize' => array( __CLASS__, 'sanitize_bool' ),
				'rest'     => true,
			),
			'_xodw_cc_timezone'           => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_timezone' ),
				'rest'     => true,
			),
			'_xodw_cc_url'                => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => 'sanitize_url',
				'rest'     => true,
			),
			'_xodw_cc_cost'               => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => 'sanitize_text_field',
				'rest'     => true,
			),
			'_xodw_cc_recur_freq'         => array(
				'type'     => 'string',
				'default'  => 'none',
				'sanitize' => array( __CLASS__, 'sanitize_frequency' ),
				'rest'     => true,
			),
			'_xodw_cc_recur_interval'     => array(
				'type'     => 'integer',
				'default'  => 1,
				'sanitize' => array( __CLASS__, 'sanitize_interval' ),
				'rest'     => true,
			),
			'_xodw_cc_recur_byday'        => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_byday' ),
				'rest'     => true,
			),
			'_xodw_cc_recur_monthly_mode' => array(
				'type'     => 'string',
				'default'  => 'monthday',
				'sanitize' => array( __CLASS__, 'sanitize_monthly_mode' ),
				'rest'     => true,
			),
			'_xodw_cc_recur_until'        => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_date' ),
				'rest'     => true,
			),
			'_xodw_cc_recur_count'        => array(
				'type'     => 'integer',
				'default'  => 0,
				'sanitize' => array( __CLASS__, 'sanitize_count' ),
				'rest'     => true,
			),
			'_xodw_cc_recur_exdates'      => array(
				'type'     => 'string',
				'default'  => '',
				'sanitize' => array( __CLASS__, 'sanitize_date_list' ),
				'rest'     => true,
			),
			'_xodw_cc_rsvp_enabled'       => array(
				'type'     => 'boolean',
				'default'  => false,
				'sanitize' => array( __CLASS__, 'sanitize_bool' ),
				'rest'     => true,
			),
			'_xodw_cc_rsvp_capacity'      => array(
				'type'     => 'integer',
				'default'  => 0,
				'sanitize' => array( __CLASS__, 'sanitize_capacity' ),
				'rest'     => true,
			),
		);

		return self::$schema;
	}

	/**
	 * Registers every meta key with the REST API and sanitisation.
	 *
	 * @return void
	 */
	public static function register() {
		foreach ( self::schema() as $key => $field ) {
			register_post_meta(
				XODW_CC_POST_TYPE,
				$key,
				array(
					'type'              => $field['type'],
					'single'            => true,
					'default'           => $field['default'],
					'show_in_rest'      => (bool) $field['rest'],
					'sanitize_callback' => $field['sanitize'],
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
		}
	}

	/**
	 * Reads all event fields, applying defaults.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<string,mixed> Field values keyed without the meta prefix.
	 */
	public static function get( $event_id ) {
		$event_id = (int) $event_id;

		if ( isset( self::$cache[ $event_id ] ) ) {
			return self::$cache[ $event_id ];
		}

		$data = array();
		// One cached read instead of one per field.
		$all = get_post_meta( $event_id );
		$all = is_array( $all ) ? $all : array();

		foreach ( self::schema() as $key => $field ) {
			$value = isset( $all[ $key ][0] ) ? $all[ $key ][0] : '';
			$short = substr( $key, strlen( '_xodw_cc_' ) );

			if ( '' === $value || null === $value ) {
				$value = $field['default'];
			}

			switch ( $field['type'] ) {
				case 'boolean':
					$value = (bool) $value;
					break;
				case 'integer':
					$value = (int) $value;
					break;
				default:
					$value = (string) $value;
			}

			$data[ $short ] = $value;
		}

		$data['id']       = $event_id;
		$data['timezone'] = '' !== $data['timezone'] ? $data['timezone'] : wp_timezone_string();

		self::$cache[ $event_id ] = $data;

		return $data;
	}

	/**
	 * Recomputes the derived UTC fields and repairs inconsistent input.
	 *
	 * Runs on every save, so imports and REST writes are normalised too.
	 *
	 * @param int $event_id Event post ID.
	 * @return array<string,mixed> Normalised data.
	 */
	public static function normalize( $event_id ) {
		$event_id = (int) $event_id;
		self::clear_cache( $event_id );
		$data = self::get( $event_id );
		$timezone = $data['timezone'];

		$start = $data['start'];

		if ( '' === $start ) {
			// An event without a date defaults to its own publish date, so the
			// editor never produces an unqueryable event.
			$post  = get_post( $event_id );
			$start = $post ? get_post_time( 'Y-m-d H:i:s', false, $post ) : current_time( 'Y-m-d H:i:s' );
		}

		if ( $data['all_day'] ) {
			$start = substr( $start, 0, 10 ) . ' 00:00:00';
		}

		$start_object = Timezone::make( $start, $timezone );

		if ( ! $start_object ) {
			return $data;
		}

		$end        = $data['end'];
		$end_object = '' !== $end ? Timezone::make( $end, $timezone ) : null;

		if ( $data['all_day'] ) {
			$end_object = $end_object ? $end_object->setTime( 23, 59, 59 ) : $start_object->setTime( 23, 59, 59 );
		}

		if ( ! $end_object || $end_object < $start_object ) {
			// Default duration: one hour, or the rest of the day for all-day events.
			$end_object = $data['all_day'] ? $start_object->setTime( 23, 59, 59 ) : $start_object->modify( '+1 hour' );
		}

		$data['start']     = $start_object->format( 'Y-m-d H:i:s' );
		$data['end']       = $end_object->format( 'Y-m-d H:i:s' );
		$data['start_utc'] = Timezone::to_utc( $data['start'], $timezone );
		$data['end_utc']   = Timezone::to_utc( $data['end'], $timezone );

		if ( 'weekly' === $data['recur_freq'] && '' === $data['recur_byday'] ) {
			$data['recur_byday'] = self::WEEKDAYS[ (int) $start_object->format( 'N' ) - 1 ];
		}

		update_post_meta( $event_id, '_xodw_cc_start', $data['start'] );
		update_post_meta( $event_id, '_xodw_cc_end', $data['end'] );
		update_post_meta( $event_id, '_xodw_cc_start_utc', $data['start_utc'] );
		update_post_meta( $event_id, '_xodw_cc_end_utc', $data['end_utc'] );
		update_post_meta( $event_id, '_xodw_cc_timezone', $timezone );
		self::clear_cache( $event_id );

		if ( '' !== $data['recur_byday'] ) {
			update_post_meta( $event_id, '_xodw_cc_recur_byday', $data['recur_byday'] );
		}

		/**
		 * Fires after the derived date fields of an event were rebuilt.
		 *
		 * @since 1.0.0
		 *
		 * @param int                 $event_id Event post ID.
		 * @param array<string,mixed> $data     Normalised event data.
		 */
		do_action( 'xodw_cc_meta_normalized', $event_id, $data );

		return $data;
	}

	/**
	 * Sanitises a datetime string down to 'Y-m-d H:i:s'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_datetime( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		// Accepts '2026-08-19', '2026-08-19T18:30', '2026-08-19 18:30:00'.
		$value = str_replace( 'T', ' ', $value );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:\s(\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m ) ) {
			return '';
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}

		$hour   = isset( $m[4] ) ? min( 23, (int) $m[4] ) : 0;
		$minute = isset( $m[5] ) ? min( 59, (int) $m[5] ) : 0;
		$second = isset( $m[6] ) ? min( 59, (int) $m[6] ) : 0;

		return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) $m[3], $hour, $minute, $second );
	}

	/**
	 * Sanitises a date-only string.
	 *
	 * @param mixed $value Raw value.
	 * @return string 'Y-m-d' or empty string.
	 */
	public static function sanitize_date( $value ) {
		$datetime = self::sanitize_datetime( $value );

		return '' === $datetime ? '' : substr( $datetime, 0, 10 );
	}

	/**
	 * Sanitises a comma separated list of dates.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_date_list( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$dates = array();

		foreach ( preg_split( '/[,\s]+/', $value ) as $candidate ) {
			$date = self::sanitize_date( $candidate );

			if ( '' !== $date ) {
				$dates[ $date ] = $date;
			}
		}

		return implode( ',', array_values( $dates ) );
	}

	/**
	 * Sanitises a boolean flag.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_bool( $value ) {
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Sanitises a timezone identifier.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_timezone( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		return in_array( $value, timezone_identifiers_list(), true ) ? $value : '';
	}

	/**
	 * Sanitises the recurrence frequency.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_frequency( $value ) {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : 'none';

		return in_array( $value, self::FREQUENCIES, true ) ? $value : 'none';
	}

	/**
	 * Sanitises the recurrence interval.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_interval( $value ) {
		return min( 52, max( 1, (int) $value ) );
	}

	/**
	 * Sanitises the weekday list of a weekly rule.
	 *
	 * @param mixed $value Raw value: array or comma separated string.
	 * @return string
	 */
	public static function sanitize_byday( $value ) {
		$items = is_array( $value ) ? $value : preg_split( '/[,\s]+/', (string) $value );
		$days  = array();

		foreach ( (array) $items as $day ) {
			$day = strtoupper( trim( (string) $day ) );

			if ( in_array( $day, self::WEEKDAYS, true ) ) {
				$days[ $day ] = $day;
			}
		}

		// Keep ISO order regardless of input order.
		$ordered = array();
		foreach ( self::WEEKDAYS as $day ) {
			if ( isset( $days[ $day ] ) ) {
				$ordered[] = $day;
			}
		}

		return implode( ',', $ordered );
	}

	/**
	 * Sanitises the monthly repetition mode.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_monthly_mode( $value ) {
		$value = is_scalar( $value ) ? (string) $value : 'monthday';

		return in_array( $value, array( 'monthday', 'weekday' ), true ) ? $value : 'monthday';
	}

	/**
	 * Sanitises the occurrence count limit.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_count( $value ) {
		return min( 2000, max( 0, (int) $value ) );
	}

	/**
	 * Sanitises an RSVP capacity.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_capacity( $value ) {
		return max( 0, (int) $value );
	}
}
