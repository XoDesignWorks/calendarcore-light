<?php
/**
 * Settings storage: two options, no scattered rows.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises xodw_cc_settings and xodw_cc_modules_enabled.
 */
class Settings {

	const OPTION_SETTINGS = 'xodw_cc_settings';
	const OPTION_MODULES  = 'xodw_cc_modules_enabled';

	/**
	 * Runtime cache of the merged settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $settings = null;

	/**
	 * Runtime cache of the module map.
	 *
	 * @var array<string,bool>|null
	 */
	private static $modules = null;

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'default_view'       => 'month',
			'views_enabled'      => array( 'month', 'week', 'day', 'list' ),
			'timezone_aware'     => 1,
			'show_timezone_note' => 1,
			'date_format'        => '',
			'time_format'        => '',
			'events_per_page'    => 12,
			'horizon_months'     => 12,
			'max_instances'      => 730,
			'cache_minutes'      => 10,
			'accent_color'       => '',
			'dark_mode'          => 'auto',
			'archive_slug'       => 'events',
			'single_slug'        => 'event',
			'rsvp_capacity'      => 0,
			'rsvp_guests_max'    => 5,
			'rsvp_moderation'    => 0,
			'hide_past'          => 1,
			'load_css'           => 1,
		);
	}

	/**
	 * Default module state. Everything free is on out of the box.
	 *
	 * @return array<string,bool>
	 */
	public static function module_defaults() {
		return array(
			'recurring'  => true,
			'rsvp'       => true,
			'ics'        => true,
			'timezone'   => true,
			'blocks'     => true,
			'shortcodes' => true,
			'elementor'  => false,
		);
	}

	/**
	 * All settings, stored values merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null === self::$settings ) {
			$stored = get_option( self::OPTION_SETTINGS, array() );
			$stored = is_array( $stored ) ? $stored : array();

			/**
			 * Filters the effective settings array.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string,mixed> $settings Merged settings.
			 */
			self::$settings = apply_filters( 'xodw_cc_settings', array_merge( self::defaults(), $stored ) );
		}

		return self::$settings;
	}

	/**
	 * Single setting accessor.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key does not exist.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::all();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return $default;
	}

	/**
	 * Module state map.
	 *
	 * @return array<string,bool>
	 */
	public static function modules() {
		if ( null === self::$modules ) {
			$stored = get_option( self::OPTION_MODULES, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$map    = array();

			foreach ( self::module_defaults() as $module => $default ) {
				$map[ $module ] = array_key_exists( $module, $stored ) ? (bool) $stored[ $module ] : (bool) $default;
			}

			/**
			 * Filters which modules are active.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string,bool> $map Module state map.
			 */
			self::$modules = apply_filters( 'xodw_cc_modules_enabled', $map );
		}

		return self::$modules;
	}

	/**
	 * Whether a module is enabled.
	 *
	 * @param string $module Module slug.
	 * @return bool
	 */
	public static function module_enabled( $module ) {
		$modules = self::modules();

		return ! empty( $modules[ $module ] );
	}

	/**
	 * Drops the runtime caches. Called after the options are saved.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$settings = null;
		self::$modules  = null;
	}

	/**
	 * Sanitises the settings option.
	 *
	 * @param mixed $input Raw input from the settings form.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$views  = array( 'month', 'week', 'day', 'list' );
		$output = self::defaults();

		$output['default_view'] = isset( $input['default_view'] ) && in_array( $input['default_view'], $views, true )
			? $input['default_view']
			: 'month';

		$enabled = array();
		if ( isset( $input['views_enabled'] ) && is_array( $input['views_enabled'] ) ) {
			foreach ( $input['views_enabled'] as $view ) {
				if ( in_array( $view, $views, true ) ) {
					$enabled[] = $view;
				}
			}
		}
		$output['views_enabled'] = empty( $enabled ) ? array( 'month', 'list' ) : $enabled;

		if ( ! in_array( $output['default_view'], $output['views_enabled'], true ) ) {
			$output['default_view'] = $output['views_enabled'][0];
		}

		foreach ( array( 'timezone_aware', 'show_timezone_note', 'rsvp_moderation', 'hide_past', 'load_css' ) as $flag ) {
			$output[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$output['date_format']     = isset( $input['date_format'] ) ? sanitize_text_field( $input['date_format'] ) : '';
		$output['time_format']     = isset( $input['time_format'] ) ? sanitize_text_field( $input['time_format'] ) : '';
		$output['events_per_page'] = isset( $input['events_per_page'] ) ? min( 100, max( 1, (int) $input['events_per_page'] ) ) : 12;
		$output['horizon_months']  = isset( $input['horizon_months'] ) ? min( 12, max( 1, (int) $input['horizon_months'] ) ) : 12;
		$output['max_instances']   = isset( $input['max_instances'] ) ? min( 2000, max( 10, (int) $input['max_instances'] ) ) : 730;
		$output['cache_minutes']   = isset( $input['cache_minutes'] ) ? min( 15, max( 0, (int) $input['cache_minutes'] ) ) : 10;
		$output['rsvp_capacity']   = isset( $input['rsvp_capacity'] ) ? max( 0, (int) $input['rsvp_capacity'] ) : 0;
		$output['rsvp_guests_max'] = isset( $input['rsvp_guests_max'] ) ? min( 50, max( 1, (int) $input['rsvp_guests_max'] ) ) : 5;

		$color                   = isset( $input['accent_color'] ) ? sanitize_hex_color( $input['accent_color'] ) : '';
		$output['accent_color']  = $color ? $color : '';
		$output['dark_mode']     = isset( $input['dark_mode'] ) && in_array( $input['dark_mode'], array( 'auto', 'light', 'dark' ), true )
			? $input['dark_mode']
			: 'auto';
		$output['archive_slug']  = isset( $input['archive_slug'] ) && '' !== trim( (string) $input['archive_slug'] )
			? sanitize_title( $input['archive_slug'] )
			: 'events';
		$output['single_slug']   = isset( $input['single_slug'] ) && '' !== trim( (string) $input['single_slug'] )
			? sanitize_title( $input['single_slug'] )
			: 'event';

		self::reset();
		xodw_cc_flush_cache();

		return $output;
	}

	/**
	 * Sanitises the module option.
	 *
	 * @param mixed $input Raw input from the settings form.
	 * @return array<string,bool>
	 */
	public static function sanitize_modules( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$stored = get_option( self::OPTION_MODULES, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$output = array();

		foreach ( self::module_defaults() as $module => $default ) {
			if ( array_key_exists( $module, $input ) ) {
				$output[ $module ] = ! empty( $input[ $module ] );

				continue;
			}

			// A module without a row on the form (Elementor when Elementor is
			// not installed) keeps whatever was stored before.
			$output[ $module ] = array_key_exists( $module, $stored ) ? (bool) $stored[ $module ] : (bool) $default;
		}

		self::reset();
		xodw_cc_flush_cache();

		return $output;
	}
}
