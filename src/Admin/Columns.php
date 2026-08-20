<?php
/**
 * Event list table: start date, recurrence and RSVP at a glance.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Admin;

defined( 'ABSPATH' ) || exit;

use XODW\CalendarCore\EventMeta;
use XODW\CalendarCore\Frontend\Single;
use XODW\CalendarCore\Rsvp;
use XODW\CalendarCore\Timezone;

/**
 * Columns, sorting and a time filter for the event list table.
 */
class Columns {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'manage_' . XODW_CC_POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . XODW_CC_POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . XODW_CC_POST_TYPE . '_sortable_columns', array( $this, 'sortable' ) );
		add_action( 'pre_get_posts', array( $this, 'sort' ) );
		add_action( 'restrict_manage_posts', array( $this, 'filter_ui' ) );
	}

	/**
	 * Adds the plugin columns after the title.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['xodw_cc_start'] = __( 'Starts', 'calendarcore' );
				$new['xodw_cc_recur'] = __( 'Repeats', 'calendarcore' );

				if ( xodw_cc_module_enabled( 'rsvp' ) ) {
					$new['xodw_cc_rsvp'] = __( 'RSVP', 'calendarcore' );
				}
			}
		}

		return $new;
	}

	/**
	 * Renders a plugin column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function column( $column, $post_id ) {
		$meta = EventMeta::get( $post_id );

		switch ( $column ) {
			case 'xodw_cc_start':
				if ( '' === $meta['start_utc'] ) {
					echo '—';
					break;
				}

				$format = get_option( 'date_format' ) . ( $meta['all_day'] ? '' : ' ' . get_option( 'time_format' ) );
				$start  = $meta['start_utc'];
				$next   = false;

				if ( 'none' !== $meta['recur_freq'] ) {
					// A series is judged by its next date, not by the first one.
					$upcoming = xodw_cc_get_occurrences(
						array(
							'event_ids' => array( (int) $post_id ),
							'from'      => gmdate( 'Y-m-d H:i:s' ),
							'limit'     => 1,
							'cache'     => false,
						)
					);

					if ( ! empty( $upcoming ) ) {
						$start = $upcoming[0]->start;
						$next  = true;
					}
				}

				echo esc_html( Timezone::format( $start, $format, wp_timezone_string() ) );

				if ( $next ) {
					echo ' <span class="xodw-cc-admin__badge">' . esc_html__( 'next date', 'calendarcore' ) . '</span>';
				} elseif ( $meta['end_utc'] < gmdate( 'Y-m-d H:i:s' ) ) {
					echo ' <span class="xodw-cc-admin__badge">' . esc_html__( 'past', 'calendarcore' ) . '</span>';
				}
				break;

			case 'xodw_cc_recur':
				echo esc_html( 'none' === $meta['recur_freq'] ? '—' : Single::recurrence_label( $meta ) );
				break;

			case 'xodw_cc_rsvp':
				if ( empty( $meta['rsvp_enabled'] ) ) {
					echo '—';
					break;
				}

				$count    = Rsvp::count( $post_id );
				$capacity = Rsvp::capacity( $post_id );

				echo esc_html( $capacity > 0 ? $count . ' / ' . $capacity : (string) $count );
				break;
		}
	}

	/**
	 * Makes the start column sortable.
	 *
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string>
	 */
	public function sortable( $columns ) {
		$columns['xodw_cc_start'] = 'xodw_cc_start';

		return $columns;
	}

	/**
	 * Applies the start date sorting and the upcoming / past filter.
	 *
	 * @param \WP_Query $query Current query.
	 * @return void
	 */
	public function sort( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || XODW_CC_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'xodw_cc_start' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_xodw_cc_start_utc' );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( '' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_xodw_cc_start_utc' );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', 'DESC' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$when = isset( $_GET['xodw_cc_when'] ) ? sanitize_key( wp_unslash( $_GET['xodw_cc_when'] ) ) : '';

		if ( 'upcoming' === $when || 'past' === $when ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => '_xodw_cc_end_utc',
						'value'   => gmdate( 'Y-m-d H:i:s' ),
						'compare' => 'upcoming' === $when ? '>=' : '<',
						'type'    => 'DATETIME',
					),
				)
			);
		}
	}

	/**
	 * Renders the upcoming / past dropdown.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public function filter_ui( $post_type ) {
		if ( XODW_CC_POST_TYPE !== $post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['xodw_cc_when'] ) ? sanitize_key( wp_unslash( $_GET['xodw_cc_when'] ) ) : '';
		?>
		<label class="screen-reader-text" for="xodw_cc_when"><?php esc_html_e( 'Filter by date', 'calendarcore' ); ?></label>
		<select name="xodw_cc_when" id="xodw_cc_when">
			<option value=""><?php esc_html_e( 'All dates', 'calendarcore' ); ?></option>
			<option value="upcoming" <?php selected( $current, 'upcoming' ); ?>><?php esc_html_e( 'Upcoming', 'calendarcore' ); ?></option>
			<option value="past" <?php selected( $current, 'past' ); ?>><?php esc_html_e( 'Past', 'calendarcore' ); ?></option>
		</select>
		<?php
	}
}
