<?php
/**
 * Server side rendering of every view.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Frontend;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use XODW\CalendarCore\Occurrence;
use XODW\CalendarCore\Query;
use XODW\CalendarCore\Timezone;

/**
 * One renderer for blocks, shortcodes and the REST view switcher: the markup a
 * visitor gets on first load is the same markup the REST endpoint returns.
 */
class Renderer {

	/**
	 * Default attributes of a calendar instance.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'view'          => (string) xodw_cc_setting( 'default_view', 'month' ),
			'date'          => '',
			'venue'         => '',
			'organizer'     => '',
			'limit'         => (int) xodw_cc_setting( 'events_per_page', 12 ),
			'offset'        => 0,
			'toolbar'       => true,
			'views'         => (array) xodw_cc_setting( 'views_enabled', array( 'month', 'week', 'day', 'list' ) ),
			'show_past'     => false,
			'one_per_event' => false,
			'show_images'   => true,
			'show_excerpt'  => true,
			'class'         => '',
		);
	}

	/**
	 * Normalises attributes coming from a block, a shortcode or a REST request.
	 *
	 * @param array<string,mixed> $atts Raw attributes.
	 * @return array<string,mixed>
	 */
	public static function parse_atts( array $atts ) {
		$atts  = wp_parse_args( $atts, self::defaults() );
		$views = array();

		foreach ( (array) $atts['views'] as $view ) {
			if ( in_array( $view, array( 'month', 'week', 'day', 'list' ), true ) ) {
				$views[] = $view;
			}
		}

		$atts['views'] = empty( $views ) ? array( 'month', 'list' ) : $views;

		// No-JavaScript navigation: ?xodw_cc_view= and ?xodw_cc_date= win over
		// the block attributes, so prev / next links work without scripts.
		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$requested_view = get_query_var( 'xodw_cc_view' );
			$requested_date = get_query_var( 'xodw_cc_date' );

			if ( is_string( $requested_view ) && in_array( $requested_view, $atts['views'], true ) ) {
				$atts['view'] = $requested_view;
			}

			if ( is_string( $requested_date ) && '' !== $requested_date ) {
				$atts['date'] = $requested_date;
			}
		}

		$atts['view'] = in_array( $atts['view'], $atts['views'], true ) ? $atts['view'] : $atts['views'][0];
		$atts['date'] = self::sanitize_anchor( $atts['date'] );

		$atts['limit']  = min( 100, max( 1, (int) $atts['limit'] ) );
		$atts['offset'] = max( 0, (int) $atts['offset'] );

		foreach ( array( 'venue', 'organizer' ) as $key ) {
			$value        = is_array( $atts[ $key ] ) ? implode( ',', $atts[ $key ] ) : (string) $atts[ $key ];
			$atts[ $key ] = implode( ',', array_filter( array_map( 'sanitize_title', explode( ',', $value ) ) ) );
		}

		foreach ( array( 'toolbar', 'show_past', 'one_per_event', 'show_images', 'show_excerpt' ) as $flag ) {
			$atts[ $flag ] = filter_var( $atts[ $flag ], FILTER_VALIDATE_BOOLEAN );
		}

		$atts['class'] = implode( ' ', array_map( 'sanitize_html_class', explode( ' ', (string) $atts['class'] ) ) );

		return $atts;
	}

	/**
	 * Sanitises the anchor date and falls back to today in site time.
	 *
	 * @param mixed $date Raw date.
	 * @return string 'Y-m-d'
	 */
	public static function sanitize_anchor( $date ) {
		$date = is_scalar( $date ) ? trim( (string) $date ) : '';

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $date;
		}

		if ( preg_match( '/^(\d{4})-(\d{2})$/', $date, $m ) && (int) $m[2] >= 1 && (int) $m[2] <= 12 ) {
			return $date . '-01';
		}

		return current_datetime()->format( 'Y-m-d' );
	}

	/**
	 * Full calendar markup: wrapper, toolbar and the current view.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return string
	 */
	public static function render( array $atts ) {
		$atts  = self::parse_atts( $atts );
		$range = self::range( $atts );
		$id    = wp_unique_id( 'xodw-cc-' );

		$classes = array( 'xodw-cc', 'xodw-cc--' . $atts['view'] );

		if ( '' !== $atts['class'] ) {
			$classes[] = $atts['class'];
		}

		if ( 'dark' === xodw_cc_setting( 'dark_mode' ) ) {
			$classes[] = 'xodw-cc--dark';
		} elseif ( 'light' === xodw_cc_setting( 'dark_mode' ) ) {
			$classes[] = 'xodw-cc--light';
		}

		$state = array(
			'view'          => $atts['view'],
			'date'          => $range['anchor'],
			'venue'         => $atts['venue'],
			'organizer'     => $atts['organizer'],
			'limit'         => $atts['limit'],
			'views'         => $atts['views'],
			'showPast'      => $atts['show_past'],
			'onePerEvent'   => $atts['one_per_event'],
			'showImages'    => $atts['show_images'],
			'showExcerpt'   => $atts['show_excerpt'],
			'toolbar'       => $atts['toolbar'],
			'timezoneAware' => (bool) xodw_cc_setting( 'timezone_aware' ),
		);

		$html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" id="' . esc_attr( $id ) . '"';
		$html .= ' data-xodw-cc-calendar="1" data-xodw-cc-state="' . esc_attr( (string) wp_json_encode( $state ) ) . '">';

		if ( $atts['toolbar'] ) {
			$html .= self::toolbar( $atts, $range );
		}

		$html .= '<div class="xodw-cc__body" data-xodw-cc-body="1">' . self::body( $atts ) . '</div>';
		$html .= self::timezone_note();
		$html .= '</div>';

		/**
		 * Filters the complete calendar markup.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $html Markup.
		 * @param array<string,mixed> $atts Parsed attributes.
		 */
		return apply_filters( 'xodw_cc_render_calendar', $html, $atts );
	}

	/**
	 * Markup of the current view only. This is what the REST endpoint swaps in.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return string
	 */
	public static function body( array $atts ) {
		$atts  = self::parse_atts( $atts );
		$range = self::range( $atts );

		$query_args = array(
			'from'          => $range['from_utc'],
			'to'            => $range['to_utc'],
			'venue'         => $atts['venue'],
			'organizer'     => $atts['organizer'],
			'limit'         => 'list' === $atts['view'] ? $atts['limit'] : 500,
			'offset'        => 'list' === $atts['view'] ? $atts['offset'] : 0,
			'one_per_event' => $atts['one_per_event'],
		);

		if ( 'list' === $atts['view'] && ! $atts['show_past'] ) {
			$now                = gmdate( 'Y-m-d H:i:s' );
			$query_args['from'] = max( $range['from_utc'], $now );
		}

		/**
		 * Filters the query arguments of a view before it runs.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $query_args Query arguments.
		 * @param array<string,mixed> $atts       Parsed attributes.
		 */
		$query_args  = apply_filters( 'xodw_cc_view_query_args', $query_args, $atts );
		$occurrences = ( new Query() )->get_occurrences( $query_args );

		switch ( $atts['view'] ) {
			case 'week':
				$html = self::view_week( $occurrences, $atts, $range );
				break;
			case 'day':
				$html = self::view_day( $occurrences, $atts, $range );
				break;
			case 'list':
				$html = self::view_list( $occurrences, $atts, $range, $query_args );
				break;
			case 'month':
			default:
				$html = self::view_month( $occurrences, $atts, $range );
		}

		/**
		 * Filters the markup of a rendered view.
		 *
		 * @since 1.0.0
		 *
		 * @param string              $html        View markup.
		 * @param Occurrence[]        $occurrences Occurrences in the range.
		 * @param array<string,mixed> $atts        Parsed attributes.
		 */
		return apply_filters( 'xodw_cc_render_view', $html, $occurrences, $atts );
	}

	/**
	 * Human readable label and navigation targets of the current range.
	 *
	 * @param array<string,mixed> $atts Parsed attributes.
	 * @return array<string,mixed>
	 */
	public static function range( array $atts ) {
		$anchor = self::sanitize_anchor( isset( $atts['date'] ) ? $atts['date'] : '' );
		$tz     = wp_timezone();
		$base   = new DateTimeImmutable( $anchor . ' 00:00:00', $tz );
		$view   = isset( $atts['view'] ) ? $atts['view'] : 'month';
		$week   = (int) get_option( 'start_of_week', 1 );

		switch ( $view ) {
			case 'day':
				$start      = $base;
				$end        = $base->setTime( 23, 59, 59 );
				$grid_start = $start;
				$grid_end   = $end;
				$label      = wp_date( 'l, j F Y', $start->getTimestamp(), $tz );
				$prev       = $base->modify( '-1 day' );
				$next       = $base->modify( '+1 day' );
				break;

			case 'week':
				$start      = self::week_start( $base, $week );
				$end        = $start->modify( '+6 days' )->setTime( 23, 59, 59 );
				$grid_start = $start;
				$grid_end   = $end;
				$label      = sprintf(
					/* translators: 1: first day of the week, 2: last day of the week. */
					__( '%1$s – %2$s', 'calendarcore' ),
					wp_date( 'j M', $start->getTimestamp(), $tz ),
					wp_date( 'j M Y', $end->getTimestamp(), $tz )
				);
				$prev       = $start->modify( '-7 days' );
				$next       = $start->modify( '+7 days' );
				break;

			case 'list':
				$start      = $base;
				$end        = $base->modify( '+1 year' )->setTime( 23, 59, 59 );
				$grid_start = $start;
				$grid_end   = $end;
				$label      = __( 'Upcoming events', 'calendarcore' );
				$prev       = $base->modify( '-1 month' );
				$next       = $base->modify( '+1 month' );
				break;

			case 'month':
			default:
				$start      = $base->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$end        = $base->modify( 'last day of this month' )->setTime( 23, 59, 59 );
				$grid_start = self::week_start( $start, $week );

				// Enough rows to cover the month, five or six depending on the offset.
				$days     = (int) $grid_start->diff( $end )->days + 1;
				$rows     = (int) ceil( $days / 7 );
				$grid_end = $grid_start->modify( '+' . ( max( 5, $rows ) * 7 - 1 ) . ' days' )->setTime( 23, 59, 59 );

				$label = wp_date( 'F Y', $start->getTimestamp(), $tz );
				$prev  = $start->modify( '-1 month' );
				$next  = $start->modify( '+1 month' );
		}

		return array(
			'view'       => $view,
			'anchor'     => $anchor,
			'label'      => $label,
			'start'      => $start,
			'end'        => $end,
			'grid_start' => $grid_start,
			'grid_end'   => $grid_end,
			'from_utc'   => Timezone::to_utc( $grid_start->format( 'Y-m-d H:i:s' ), $tz->getName() ),
			'to_utc'     => Timezone::to_utc( $grid_end->format( 'Y-m-d H:i:s' ), $tz->getName() ),
			'prev'       => $prev->format( 'Y-m-d' ),
			'next'       => $next->format( 'Y-m-d' ),
			'today'      => current_datetime()->format( 'Y-m-d' ),
		);
	}

	/**
	 * First day of the week containing a date.
	 *
	 * @param DateTimeImmutable $date          Date.
	 * @param int               $start_of_week 0 = Sunday … 6 = Saturday.
	 * @return DateTimeImmutable
	 */
	private static function week_start( DateTimeImmutable $date, $start_of_week ) {
		$start_of_week = ( (int) $start_of_week % 7 + 7 ) % 7;
		$weekday       = (int) $date->format( 'w' );
		$offset        = ( $weekday - $start_of_week + 7 ) % 7;

		return $date->modify( '-' . $offset . ' days' )->setTime( 0, 0, 0 );
	}

	/**
	 * Toolbar with navigation, label and view switcher.
	 *
	 * @param array<string,mixed> $atts  Parsed attributes.
	 * @param array<string,mixed> $range Range data.
	 * @return string
	 */
	private static function toolbar( array $atts, array $range ) {
		$labels = array(
			'month' => __( 'Month', 'calendarcore' ),
			'week'  => __( 'Week', 'calendarcore' ),
			'day'   => __( 'Day', 'calendarcore' ),
			'list'  => __( 'List', 'calendarcore' ),
		);

		$html  = '<div class="xodw-cc__toolbar">';
		$html .= '<div class="xodw-cc__nav">';
		$html .= '<a class="xodw-cc__btn xodw-cc__btn--icon" href="' . esc_url( self::nav_url( $atts['view'], $range['prev'] ) ) . '"';
		$html .= ' data-xodw-cc-go="' . esc_attr( $range['prev'] ) . '" rel="prev" aria-label="' . esc_attr__( 'Previous period', 'calendarcore' ) . '">';
		$html .= '<span aria-hidden="true">&#8249;</span></a>';
		$html .= '<a class="xodw-cc__btn" href="' . esc_url( self::nav_url( $atts['view'], $range['today'] ) ) . '"';
		$html .= ' data-xodw-cc-go="' . esc_attr( $range['today'] ) . '">' . esc_html__( 'Today', 'calendarcore' ) . '</a>';
		$html .= '<a class="xodw-cc__btn xodw-cc__btn--icon" href="' . esc_url( self::nav_url( $atts['view'], $range['next'] ) ) . '"';
		$html .= ' data-xodw-cc-go="' . esc_attr( $range['next'] ) . '" rel="next" aria-label="' . esc_attr__( 'Next period', 'calendarcore' ) . '">';
		$html .= '<span aria-hidden="true">&#8250;</span></a>';
		$html .= '</div>';

		$html .= '<h2 class="xodw-cc__label" data-xodw-cc-label="1" aria-live="polite">' . esc_html( $range['label'] ) . '</h2>';

		if ( count( $atts['views'] ) > 1 ) {
			$html .= '<div class="xodw-cc__views" role="tablist" aria-label="' . esc_attr__( 'Calendar views', 'calendarcore' ) . '">';

			foreach ( $atts['views'] as $view ) {
				$current = $view === $atts['view'];
				$html   .= '<a class="xodw-cc__btn' . ( $current ? ' is-active' : '' ) . '" role="tab"';
				$html   .= ' aria-selected="' . ( $current ? 'true' : 'false' ) . '"';
				$html   .= ' href="' . esc_url( self::nav_url( $view, $range['anchor'] ) ) . '"';
				$html   .= ' data-xodw-cc-view="' . esc_attr( $view ) . '">' . esc_html( $labels[ $view ] ) . '</a>';
			}

			$html .= '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * URL for a no-JavaScript navigation step.
	 *
	 * @param string $view View slug.
	 * @param string $date Anchor date.
	 * @return string
	 */
	private static function nav_url( $view, $date ) {
		return add_query_arg(
			array(
				'xodw_cc_view' => $view,
				'xodw_cc_date' => $date,
			),
			self::current_url()
		);
	}

	/**
	 * Current front end URL, without the plugin navigation arguments.
	 *
	 * @return string
	 */
	private static function current_url() {
		global $wp;

		$path = isset( $wp->request ) ? (string) $wp->request : '';
		$url  = home_url( '' === $path ? '/' : user_trailingslashit( $path ) );

		$query = array();

		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			wp_parse_str( sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ), $query );
			unset( $query['xodw_cc_view'], $query['xodw_cc_date'] );
		}

		return empty( $query ) ? $url : add_query_arg( $query, $url );
	}

	/**
	 * Note about the timezone the times are shown in.
	 *
	 * @return string
	 */
	private static function timezone_note() {
		if ( ! xodw_cc_setting( 'show_timezone_note' ) ) {
			return '';
		}

		return '<p class="xodw-cc__tznote" data-xodw-cc-tznote="1">' . esc_html(
			sprintf(
				/* translators: %s: timezone name, e.g. Europe/Berlin (CEST). */
				__( 'Times shown in %s', 'calendarcore' ),
				Timezone::label( wp_timezone_string() )
			)
		) . '</p>';
	}

	/**
	 * Groups occurrences by their local date, repeating multi day events.
	 *
	 * @param Occurrence[] $occurrences Occurrences.
	 * @return array<string,Occurrence[]>
	 */
	private static function group_by_date( array $occurrences ) {
		$grouped = array();
		$tz      = wp_timezone_string();

		foreach ( $occurrences as $occurrence ) {
			$day  = substr( Timezone::from_utc( $occurrence->start, $tz ), 0, 10 );
			$last = substr( Timezone::from_utc( $occurrence->end, $tz ), 0, 10 );

			$cursor = new DateTimeImmutable( $day . ' 12:00:00', wp_timezone() );
			$guard  = 0;

			while ( $cursor->format( 'Y-m-d' ) <= $last && $guard < 366 ) {
				$grouped[ $cursor->format( 'Y-m-d' ) ][] = $occurrence;
				$cursor                                  = $cursor->modify( '+1 day' );
				++$guard;
			}
		}

		return $grouped;
	}

	/**
	 * Month grid.
	 *
	 * @param Occurrence[]        $occurrences Occurrences.
	 * @param array<string,mixed> $atts        Parsed attributes.
	 * @param array<string,mixed> $range       Range data.
	 * @return string
	 */
	private static function view_month( array $occurrences, array $atts, array $range ) {
		unset( $atts );

		$tz       = wp_timezone();
		$grouped  = self::group_by_date( $occurrences );
		$today    = current_datetime()->format( 'Y-m-d' );
		$cursor   = $range['grid_start'];
		$end      = $range['grid_end'];
		$month    = (int) $range['start']->format( 'n' );
		$weekdays = self::weekday_labels();

		$html  = '<div class="xodw-cc-month" role="grid" aria-label="' . esc_attr( $range['label'] ) . '">';
		$html .= '<div class="xodw-cc-month__head" role="row">';

		foreach ( $weekdays as $weekday ) {
			$html .= '<div class="xodw-cc-month__weekday" role="columnheader">';
			$html .= '<span aria-hidden="true">' . esc_html( $weekday['short'] ) . '</span>';
			$html .= '<span class="screen-reader-text">' . esc_html( $weekday['full'] ) . '</span></div>';
		}

		$html .= '</div><div class="xodw-cc-month__grid">';

		$guard = 0;

		while ( $cursor <= $end && $guard < 60 ) {
			++$guard;
			$date    = $cursor->format( 'Y-m-d' );
			$classes = array( 'xodw-cc-day' );

			if ( (int) $cursor->format( 'n' ) !== $month ) {
				$classes[] = 'is-outside';
			}

			if ( $date === $today ) {
				$classes[] = 'is-today';
			}

			$items = isset( $grouped[ $date ] ) ? $grouped[ $date ] : array();

			if ( ! empty( $items ) ) {
				$classes[] = 'has-events';
			}

			$html .= '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" role="gridcell" data-date="' . esc_attr( $date ) . '">';
			$html .= '<a class="xodw-cc-day__num" href="' . esc_url( self::nav_url( 'day', $date ) ) . '" data-xodw-cc-day="' . esc_attr( $date ) . '">';
			$html .= '<span aria-hidden="true">' . esc_html( $cursor->format( 'j' ) ) . '</span>';
			$html .= '<span class="screen-reader-text">' . esc_html( wp_date( 'j F Y', $cursor->getTimestamp(), $tz ) ) . '</span></a>';

			if ( ! empty( $items ) ) {
				$html .= '<ul class="xodw-cc-day__events">';

				foreach ( $items as $occurrence ) {
					$html .= '<li>' . self::chip( $occurrence ) . '</li>';
				}

				$html .= '</ul>';
			}

			$html  .= '</div>';
			$cursor = $cursor->modify( '+1 day' );
		}

		$html .= '</div></div>';

		if ( empty( $occurrences ) ) {
			$html .= self::empty_notice();
		}

		return $html;
	}

	/**
	 * Week view: one column per day on desktop, stacked on mobile.
	 *
	 * @param Occurrence[]        $occurrences Occurrences.
	 * @param array<string,mixed> $atts        Parsed attributes.
	 * @param array<string,mixed> $range       Range data.
	 * @return string
	 */
	private static function view_week( array $occurrences, array $atts, array $range ) {
		unset( $atts );

		$tz      = wp_timezone();
		$grouped = self::group_by_date( $occurrences );
		$today   = current_datetime()->format( 'Y-m-d' );
		$cursor  = $range['grid_start'];

		$html = '<div class="xodw-cc-week">';

		for ( $i = 0; $i < 7; $i++ ) {
			$date    = $cursor->format( 'Y-m-d' );
			$items   = isset( $grouped[ $date ] ) ? $grouped[ $date ] : array();
			$classes = array( 'xodw-cc-week__day' );

			if ( $date === $today ) {
				$classes[] = 'is-today';
			}

			$html .= '<section class="' . esc_attr( implode( ' ', $classes ) ) . '" data-date="' . esc_attr( $date ) . '">';
			$html .= '<h3 class="xodw-cc-week__title"><a href="' . esc_url( self::nav_url( 'day', $date ) ) . '" data-xodw-cc-day="' . esc_attr( $date ) . '">';
			$html .= '<span class="xodw-cc-week__dow">' . esc_html( wp_date( 'D', $cursor->getTimestamp(), $tz ) ) . '</span> ';
			$html .= '<span class="xodw-cc-week__date">' . esc_html( wp_date( 'j M', $cursor->getTimestamp(), $tz ) ) . '</span>';
			$html .= '</a></h3>';

			if ( empty( $items ) ) {
				$html .= '<p class="xodw-cc-week__empty">' . esc_html__( 'No events', 'calendarcore' ) . '</p>';
			} else {
				$html .= '<ul class="xodw-cc-week__events">';

				foreach ( $items as $occurrence ) {
					$html .= '<li>' . self::chip( $occurrence, true ) . '</li>';
				}

				$html .= '</ul>';
			}

			$html  .= '</section>';
			$cursor = $cursor->modify( '+1 day' );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Day view.
	 *
	 * @param Occurrence[]        $occurrences Occurrences.
	 * @param array<string,mixed> $atts        Parsed attributes.
	 * @param array<string,mixed> $range       Range data.
	 * @return string
	 */
	private static function view_day( array $occurrences, array $atts, array $range ) {
		unset( $range );

		if ( empty( $occurrences ) ) {
			return self::empty_notice();
		}

		$html = '<ul class="xodw-cc-list xodw-cc-list--day">';

		foreach ( $occurrences as $occurrence ) {
			$html .= self::card( $occurrence, $atts );
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * List view with a load more control.
	 *
	 * @param Occurrence[]        $occurrences Occurrences.
	 * @param array<string,mixed> $atts        Parsed attributes.
	 * @param array<string,mixed> $range       Range data.
	 * @param array<string,mixed> $query_args  Arguments the view ran with.
	 * @return string
	 */
	private static function view_list( array $occurrences, array $atts, array $range, array $query_args ) {
		unset( $range );

		if ( empty( $occurrences ) ) {
			return self::empty_notice();
		}

		$html    = '<ul class="xodw-cc-list">';
		$current = '';

		foreach ( $occurrences as $occurrence ) {
			$date = substr( Timezone::from_utc( $occurrence->start, wp_timezone_string() ), 0, 10 );

			if ( $date !== $current ) {
				$current = $date;
				$html   .= '<li class="xodw-cc-list__sep"><span>' . esc_html( Timezone::format( $occurrence->start, 'l, j F Y', wp_timezone_string() ) ) . '</span></li>';
			}

			$html .= self::card( $occurrence, $atts );
		}

		$html .= '</ul>';

		$total = ( new Query() )->count_occurrences( $query_args );

		if ( $total > ( $atts['offset'] + count( $occurrences ) ) ) {
			$html .= '<p class="xodw-cc__more"><button type="button" class="xodw-cc__btn xodw-cc__btn--more" data-xodw-cc-more="' . esc_attr( (string) ( $atts['offset'] + $atts['limit'] ) ) . '">';
			$html .= esc_html__( 'Load more events', 'calendarcore' ) . '</button></p>';
		}

		return $html;
	}

	/**
	 * Compact event chip used inside grid cells.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @param bool       $with_venue Whether to append the venue name.
	 * @return string
	 */
	private static function chip( Occurrence $occurrence, $with_venue = false ) {
		$classes = array( 'xodw-cc-chip' );

		if ( $occurrence->is_past() ) {
			$classes[] = 'is-past';
		}

		if ( $occurrence->is_now() ) {
			$classes[] = 'is-now';
		}

		$html  = '<a class="' . esc_attr( implode( ' ', $classes ) ) . '" href="' . esc_url( $occurrence->permalink() ) . '">';
		$html .= self::time_tag( $occurrence, 'time' );
		$html .= '<span class="xodw-cc-chip__title">' . esc_html( $occurrence->title() ) . '</span>';

		if ( $with_venue ) {
			$venue = $occurrence->venue_label();

			if ( '' !== $venue ) {
				$html .= '<span class="xodw-cc-chip__venue">' . esc_html( $venue ) . '</span>';
			}
		}

		$html .= '</a>';

		return $html;
	}

	/**
	 * Full event card used by the list and day views.
	 *
	 * @param Occurrence          $occurrence Occurrence.
	 * @param array<string,mixed> $atts       Parsed attributes.
	 * @return string
	 */
	private static function card( Occurrence $occurrence, array $atts ) {
		$classes = array( 'xodw-cc-card' );

		if ( $occurrence->is_past() ) {
			$classes[] = 'is-past';
		}

		$html = '<li class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		$thumb = ! empty( $atts['show_images'] ) ? $occurrence->thumbnail( 'medium' ) : '';

		if ( '' !== $thumb ) {
			$html .= '<a class="xodw-cc-card__media" href="' . esc_url( $occurrence->permalink() ) . '" tabindex="-1" aria-hidden="true">';
			$html .= '<img src="' . esc_url( $thumb ) . '" alt="" loading="lazy" decoding="async" />';
			$html .= '</a>';
		}

		$html .= '<div class="xodw-cc-card__body">';
		$html .= '<h3 class="xodw-cc-card__title"><a href="' . esc_url( $occurrence->permalink() ) . '">' . esc_html( $occurrence->title() ) . '</a></h3>';
		$html .= '<p class="xodw-cc-card__meta">' . self::time_tag( $occurrence, 'full' );

		$venue = $occurrence->venue_label();

		if ( '' !== $venue ) {
			$html .= '<span class="xodw-cc-card__venue">' . esc_html( $venue ) . '</span>';
		}

		$organizer = $occurrence->organizer_label();

		if ( '' !== $organizer ) {
			$html .= '<span class="xodw-cc-card__organizer">' . esc_html( $organizer ) . '</span>';
		}

		$html .= '</p>';

		if ( ! empty( $atts['show_excerpt'] ) ) {
			$excerpt = $occurrence->excerpt();

			if ( '' !== $excerpt ) {
				$html .= '<p class="xodw-cc-card__excerpt">' . esc_html( $excerpt ) . '</p>';
			}
		}

		$html .= '<p class="xodw-cc-card__actions">';
		$html .= '<a class="xodw-cc__btn xodw-cc__btn--small" href="' . esc_url( $occurrence->permalink() ) . '">' . esc_html__( 'Details', 'calendarcore' ) . '</a>';

		if ( xodw_cc_module_enabled( 'ics' ) && ! $occurrence->is_past() ) {
			$html .= '<a class="xodw-cc__btn xodw-cc__btn--small xodw-cc__btn--ghost" href="' . esc_url( xodw_cc_ics_url( $occurrence->event_id, $occurrence->start ) ) . '" download>';
			$html .= esc_html__( 'Add to calendar', 'calendarcore' ) . '</a>';
		}

		$html .= '</p></div></li>';

		return $html;
	}

	/**
	 * Time element carrying UTC data for the timezone-aware script.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @param string     $mode       'time' or 'full'.
	 * @return string
	 */
	public static function time_tag( Occurrence $occurrence, $mode = 'time' ) {
		// Every view renders in the site timezone, whatever timezone the event
		// itself was entered in: a grid with mixed reference frames is unreadable.
		// The browser then converts to the visitor timezone.
		$site_tz = wp_timezone_string();

		if ( $occurrence->all_day() ) {
			$text = 'full' === $mode
				? Timezone::format( $occurrence->start, (string) get_option( 'date_format' ), $site_tz ) . ' · ' . __( 'All day', 'calendarcore' )
				: __( 'All day', 'calendarcore' );

			return '<time class="xodw-cc-time" datetime="' . esc_attr( substr( $occurrence->start_iso(), 0, 10 ) ) . '">' . esc_html( $text ) . '</time>';
		}

		$time_format = (string) get_option( 'time_format' );
		$date_format = (string) get_option( 'date_format' );

		if ( 'full' === $mode ) {
			$text = Timezone::format( $occurrence->start, $date_format . ' ' . $time_format, $site_tz );

			$same_day = substr( Timezone::from_utc( $occurrence->start, $site_tz ), 0, 10 )
				=== substr( Timezone::from_utc( $occurrence->end, $site_tz ), 0, 10 );

			$text .= ' – ' . Timezone::format( $occurrence->end, $same_day ? $time_format : $date_format . ' ' . $time_format, $site_tz );
		} else {
			$text = Timezone::format( $occurrence->start, $time_format, $site_tz );
		}

		$html  = '<time class="xodw-cc-time" datetime="' . esc_attr( $occurrence->start_iso() ) . '"';
		$html .= ' data-xodw-cc-time="' . esc_attr( $mode ) . '"';
		$html .= ' data-end="' . esc_attr( $occurrence->end_iso() ) . '"';
		$html .= ' data-tz="' . esc_attr( $occurrence->timezone() ) . '">';
		$html .= esc_html( $text ) . '</time>';

		return $html;
	}

	/**
	 * Localised weekday labels honouring start_of_week.
	 *
	 * @return array<int,array{short:string,full:string}>
	 */
	private static function weekday_labels() {
		$start  = (int) get_option( 'start_of_week', 1 );
		$tz     = wp_timezone();
		$labels = array();
		// 2024-01-07 was a Sunday: a stable base for weekday names.
		$base = new DateTimeImmutable( '2024-01-07 12:00:00', $tz );

		for ( $i = 0; $i < 7; $i++ ) {
			$day      = $base->modify( '+' . ( ( $start + $i ) % 7 ) . ' days' );
			$labels[] = array(
				'short' => wp_date( 'D', $day->getTimestamp(), $tz ),
				'full'  => wp_date( 'l', $day->getTimestamp(), $tz ),
			);
		}

		return $labels;
	}

	/**
	 * Empty state.
	 *
	 * @return string
	 */
	private static function empty_notice() {
		return '<p class="xodw-cc__empty">' . esc_html__( 'No events scheduled for this period.', 'calendarcore' ) . '</p>';
	}
}
