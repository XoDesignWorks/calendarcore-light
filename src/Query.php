<?php
/**
 * The single query wrapper every view goes through.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Reads occurrences from the narrow occurrence table and resolves taxonomy or
 * search filters through WP_Query, so capabilities, filters and the object
 * cache keep working. Results are cached in a transient whose key contains a
 * global generation counter: one option bump invalidates everything.
 */
class Query {

	/**
	 * Default arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'from'          => '',
			'to'            => '',
			'limit'         => 20,
			'offset'        => 0,
			'order'         => 'ASC',
			'event_ids'     => array(),
			'exclude_ids'   => array(),
			'venue'         => '',
			'organizer'     => '',
			'search'        => '',
			'author'        => 0,
			'one_per_event' => false,
			'post_status'   => array( 'publish' ),
			'cache'         => true,
		);
	}

	/**
	 * Normalises and validates arguments.
	 *
	 * @param array<string,mixed> $args Raw arguments.
	 * @return array<string,mixed>
	 */
	public static function parse_args( array $args ) {
		$args = wp_parse_args( $args, self::defaults() );

		$args['from']   = EventMeta::sanitize_datetime( $args['from'] );
		$args['to']     = EventMeta::sanitize_datetime( $args['to'] );
		$args['limit']  = min( 500, max( 1, (int) $args['limit'] ) );
		$args['offset'] = max( 0, (int) $args['offset'] );
		$args['order']  = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$args['search'] = sanitize_text_field( (string) $args['search'] );
		$args['author'] = max( 0, (int) $args['author'] );

		$args['event_ids'] = array_values( array_filter( array_map( 'absint', (array) $args['event_ids'] ) ) );
		$args['exclude_ids'] = array_values( array_filter( array_map( 'absint', (array) $args['exclude_ids'] ) ) );

		foreach ( array( 'venue', 'organizer' ) as $key ) {
			$value = $args[ $key ];

			if ( is_array( $value ) ) {
				$args[ $key ] = array_values( array_filter( array_map( 'sanitize_title', $value ) ) );
			} else {
				$value        = trim( (string) $value );
				$args[ $key ] = '' === $value ? array() : array_values( array_filter( array_map( 'sanitize_title', explode( ',', $value ) ) ) );
			}
		}

		$args['one_per_event'] = (bool) $args['one_per_event'];
		$args['post_status']   = array_values( array_intersect( (array) $args['post_status'], array( 'publish', 'private', 'future' ) ) );
		$args['post_status']   = empty( $args['post_status'] ) ? array( 'publish' ) : $args['post_status'];

		return $args;
	}

	/**
	 * Occurrences matching the arguments.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return Occurrence[]
	 */
	public function get_occurrences( array $args = array() ) {
		$args = self::parse_args( $args );
		$rows = $this->get_rows( $args );

		if ( empty( $rows ) ) {
			return array();
		}

		$event_ids = array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'event_id' ) ) ) );

		// One round trip for posts, one for meta, one for terms.
		_prime_post_caches( $event_ids, true, true );

		$occurrences = array();

		foreach ( $rows as $row ) {
			$occurrences[] = Occurrence::from_row( $row );
		}

		/**
		 * Filters the occurrence list before it reaches a view.
		 *
		 * @since 1.0.0
		 *
		 * @param Occurrence[]        $occurrences Occurrences.
		 * @param array<string,mixed> $args        Parsed query arguments.
		 */
		return apply_filters( 'xodw_cc_query_occurrences', $occurrences, $args );
	}

	/**
	 * Number of occurrences matching the arguments, ignoring limit and offset.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return int
	 */
	public function count_occurrences( array $args = array() ) {
		global $wpdb;

		$args = self::parse_args( $args );

		if ( ! Instances::exists() ) {
			return 0;
		}

		$ids = $this->resolve_event_ids( $args );

		if ( is_array( $ids ) && empty( $ids ) ) {
			return 0;
		}

		list( $where, $params ) = $this->build_where( $args, $ids );

		$table = Instances::table();
		$sql   = "SELECT COUNT(*) FROM {$table} i INNER JOIN {$wpdb->posts} p ON p.ID = i.event_id WHERE {$where}";

		$cache_key = 'xodw_cc_c_' . md5( (string) wp_json_encode( array( xodw_cc_cache_version(), $sql, $params ) ) );
		$ttl       = $this->cache_ttl();
		$use_cache = $args['cache'] && $ttl > 0;
		$cached    = $use_cache ? get_transient( $cache_key ) : false;

		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );

		if ( $use_cache ) {
			set_transient( $cache_key, $count, $ttl );
		}

		return $count;
	}

	/**
	 * Raw occurrence rows, cached.
	 *
	 * @param array<string,mixed> $args Parsed arguments.
	 * @return array<int,object>
	 */
	private function get_rows( array $args ) {
		global $wpdb;

		if ( ! Instances::exists() ) {
			return array();
		}

		$ids = $this->resolve_event_ids( $args );

		if ( is_array( $ids ) && empty( $ids ) ) {
			return array();
		}

		list( $where, $params ) = $this->build_where( $args, $ids );

		$table = Instances::table();
		$order = $args['order'];

		if ( $args['one_per_event'] ) {
			// Next (or previous, when ordering DESC) occurrence per event.
			$aggregate = 'DESC' === $order ? 'MAX' : 'MIN';
			$sql       = "SELECT i.id, i.event_id, i.start_datetime, i.end_datetime
				FROM {$table} i
				INNER JOIN {$wpdb->posts} p ON p.ID = i.event_id
				INNER JOIN (
					SELECT i2.event_id AS eid, {$aggregate}(i2.start_datetime) AS pick
					FROM {$table} i2
					INNER JOIN {$wpdb->posts} p2 ON p2.ID = i2.event_id
					WHERE " . str_replace( array( 'i.', 'p.' ), array( 'i2.', 'p2.' ), $where ) . "
					GROUP BY i2.event_id
				) picked ON picked.eid = i.event_id AND picked.pick = i.start_datetime
				WHERE {$where}
				ORDER BY i.start_datetime {$order}, i.event_id ASC
				LIMIT %d OFFSET %d";
			$params    = array_merge( $params, $params, array( $args['limit'], $args['offset'] ) );
		} else {
			$sql    = "SELECT i.id, i.event_id, i.start_datetime, i.end_datetime
				FROM {$table} i
				INNER JOIN {$wpdb->posts} p ON p.ID = i.event_id
				WHERE {$where}
				ORDER BY i.start_datetime {$order}, i.event_id ASC
				LIMIT %d OFFSET %d";
			$params = array_merge( $params, array( $args['limit'], $args['offset'] ) );
		}

		$cache_key = 'xodw_cc_q_' . md5( (string) wp_json_encode( array( xodw_cc_cache_version(), $sql, $params ) ) );
		$ttl       = $this->cache_ttl();
		$use_cache = $args['cache'] && $ttl > 0;
		$cached    = $use_cache ? get_transient( $cache_key ) : false;

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		$rows = is_array( $rows ) ? $rows : array();

		if ( $use_cache ) {
			set_transient( $cache_key, $rows, $ttl );
		}

		return $rows;
	}

	/**
	 * Builds the shared WHERE clause.
	 *
	 * @param array<string,mixed>    $args Parsed arguments.
	 * @param array<int,int>|null    $ids  Resolved event IDs, null when unfiltered.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build_where( array $args, $ids ) {
		$where  = array( 'p.post_type = %s' );
		$params = array( XODW_CC_POST_TYPE );

		$statuses = $args['post_status'];
		$where[]  = 'p.post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$params   = array_merge( $params, $statuses );

		if ( '' !== $args['from'] ) {
			// An occurrence in progress still belongs to the range.
			$where[]  = 'i.end_datetime >= %s';
			$params[] = $args['from'];
		}

		if ( '' !== $args['to'] ) {
			$where[]  = 'i.start_datetime <= %s';
			$params[] = $args['to'];
		}

		if ( is_array( $ids ) && ! empty( $ids ) ) {
			$where[] = 'i.event_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		if ( ! empty( $args['exclude_ids'] ) ) {
			$where[] = 'i.event_id NOT IN (' . implode( ',', array_fill( 0, count( $args['exclude_ids'] ), '%d' ) ) . ')';
			$params  = array_merge( $params, $args['exclude_ids'] );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Resolves taxonomy, search, author and explicit ID filters into a list of
	 * event IDs. Returns null when no filter narrows the set.
	 *
	 * @param array<string,mixed> $args Parsed arguments.
	 * @return array<int,int>|null
	 */
	private function resolve_event_ids( array $args ) {
		$needs_query = ! empty( $args['venue'] ) || ! empty( $args['organizer'] ) || '' !== $args['search'] || $args['author'] > 0;

		if ( ! $needs_query ) {
			return ! empty( $args['event_ids'] ) ? $args['event_ids'] : null;
		}

		$query_args = array(
			'post_type'              => XODW_CC_POST_TYPE,
			'post_status'            => $args['post_status'],
			'posts_per_page'         => 2000,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
		);

		if ( ! empty( $args['event_ids'] ) ) {
			$query_args['post__in'] = $args['event_ids'];
		}

		if ( '' !== $args['search'] ) {
			$query_args['s'] = $args['search'];
		}

		if ( $args['author'] > 0 ) {
			$query_args['author'] = $args['author'];
		}

		$tax_query = array();

		if ( ! empty( $args['venue'] ) ) {
			$tax_query[] = array(
				'taxonomy' => XODW_CC_TAX_VENUE,
				'field'    => 'slug',
				'terms'    => $args['venue'],
			);
		}

		if ( ! empty( $args['organizer'] ) ) {
			$tax_query[] = array(
				'taxonomy' => XODW_CC_TAX_ORGANIZER,
				'field'    => 'slug',
				'terms'    => $args['organizer'],
			);
		}

		if ( ! empty( $tax_query ) ) {
			$tax_query['relation']   = 'AND';
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new \WP_Query( $query_args );

		return array_values( array_map( 'intval', $query->posts ) );
	}

	/**
	 * Transient lifetime in seconds, 0 disables caching.
	 *
	 * @return int
	 */
	private function cache_ttl() {
		$minutes = min( 15, max( 0, (int) xodw_cc_setting( 'cache_minutes', 10 ) ) );

		/**
		 * Filters the query cache lifetime.
		 *
		 * @since 1.0.0
		 *
		 * @param int $seconds Cache lifetime in seconds.
		 */
		return (int) apply_filters( 'xodw_cc_cache_ttl', $minutes * MINUTE_IN_SECONDS );
	}
}
