<?php
/**
 * RSVP without payment.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Attendee confirmations are stored as comments of type xodw_cc_rsvp: no extra
 * table, and moderation, export and deletion come for free.
 */
class Rsvp {

	const COMMENT_TYPE = 'xodw_cc_rsvp';
	const RATE_LIMIT   = 10;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_post_nopriv_xodw_cc_rsvp', array( $this, 'handle_post' ) );
		add_action( 'admin_post_xodw_cc_rsvp', array( $this, 'handle_post' ) );
		add_action( 'pre_get_comments', array( $this, 'hide_from_comment_queries' ) );
		add_filter( 'pre_wp_update_comment_count_now', array( $this, 'exclude_from_comment_count' ), 10, 3 );
		add_filter( 'comments_open', array( $this, 'keep_comments_untouched' ), 10, 2 );
	}

	/**
	 * Keeps RSVP records out of comment listings that did not ask for them.
	 *
	 * @param \WP_Comment_Query $query Comment query.
	 * @return void
	 */
	public function hide_from_comment_queries( $query ) {
		$vars = $query->query_vars;

		if ( ! empty( $vars['type'] ) || ! empty( $vars['type__in'] ) ) {
			return;
		}

		$excluded = isset( $vars['type__not_in'] ) ? (array) $vars['type__not_in'] : array();

		if ( ! in_array( self::COMMENT_TYPE, $excluded, true ) ) {
			$excluded[]                     = self::COMMENT_TYPE;
			$query->query_vars['type__not_in'] = $excluded;
		}
	}

	/**
	 * Keeps RSVP records out of the post comment count, so an event with ten
	 * attendees does not claim to have ten comments.
	 *
	 * @param int|null $new     Count core is about to store.
	 * @param int      $old     Previous count.
	 * @param int      $post_id Post ID.
	 * @return int
	 */
	public function exclude_from_comment_count( $new, $old, $post_id ) {
		global $wpdb;

		unset( $new, $old );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments}
				 WHERE comment_post_ID = %d AND comment_approved = '1' AND comment_type != %s",
				(int) $post_id,
				self::COMMENT_TYPE
			)
		);
	}

	/**
	 * RSVP records never affect whether discussion is open on a post.
	 *
	 * @param bool $open    Whether comments are open.
	 * @param int  $post_id Post ID.
	 * @return bool
	 */
	public function keep_comments_untouched( $open, $post_id ) {
		unset( $post_id );

		return $open;
	}

	/**
	 * Capacity of an event, 0 meaning unlimited.
	 *
	 * @param int $event_id Event post ID.
	 * @return int
	 */
	public static function capacity( $event_id ) {
		$meta     = EventMeta::get( (int) $event_id );
		$capacity = (int) $meta['rsvp_capacity'];

		if ( $capacity <= 0 ) {
			$capacity = (int) xodw_cc_setting( 'rsvp_capacity', 0 );
		}

		return max( 0, $capacity );
	}

	/**
	 * Confirmed guests of an occurrence.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $start    Occurrence start in UTC, empty for any.
	 * @return int
	 */
	public static function count( $event_id, $start = '' ) {
		global $wpdb;

		$event_id = (int) $event_id;

		if ( $event_id <= 0 ) {
			return 0;
		}

		$key = self::occurrence_key( $start );

		$sql = "SELECT COALESCE( SUM( CAST( COALESCE( cm.meta_value, '1' ) AS UNSIGNED ) ), 0 )
			FROM {$wpdb->comments} c
			LEFT JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID AND cm.meta_key = '_xodw_cc_guests'
			WHERE c.comment_post_ID = %d AND c.comment_type = %s AND c.comment_approved = '1'";

		$params = array( $event_id, self::COMMENT_TYPE );

		if ( '' !== $key ) {
			$sql     .= " AND EXISTS (
				SELECT 1 FROM {$wpdb->commentmeta} cm2
				WHERE cm2.comment_id = c.comment_ID AND cm2.meta_key = '_xodw_cc_occ' AND cm2.meta_value = %s
			)";
			$params[] = $key;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Remaining seats, or null when the event has no capacity limit.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $start    Occurrence start in UTC.
	 * @return int|null
	 */
	public static function remaining( $event_id, $start = '' ) {
		$capacity = self::capacity( $event_id );

		if ( $capacity <= 0 ) {
			return null;
		}

		return max( 0, $capacity - self::count( $event_id, $start ) );
	}

	/**
	 * Normalises an occurrence start into the key stored with a record.
	 *
	 * @param string $start Occurrence start in UTC or an already built key.
	 * @return string
	 */
	public static function occurrence_key( $start ) {
		$start = (string) $start;

		if ( preg_match( '/^\d{14}$/', $start ) ) {
			return $start;
		}

		$clean = str_replace( array( '-', ' ', ':' ), '', $start );

		return preg_match( '/^\d{14}$/', $clean ) ? $clean : '';
	}

	/**
	 * Stores an RSVP.
	 *
	 * @param array<string,mixed> $data Raw submission: event_id, occ, name, email, guests, honeypot.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function submit( array $data ) {
		$event_id = isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0;
		$post     = get_post( $event_id );

		if ( ! $post || XODW_CC_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'xodw_cc_rsvp_event', __( 'This event is not available.', 'calendarcore' ), array( 'status' => 404 ) );
		}

		$meta = EventMeta::get( $event_id );

		if ( empty( $meta['rsvp_enabled'] ) || ! xodw_cc_module_enabled( 'rsvp' ) ) {
			return new WP_Error( 'xodw_cc_rsvp_closed', __( 'RSVP is closed for this event.', 'calendarcore' ), array( 'status' => 403 ) );
		}

		// Honeypot: a filled hidden field means a bot.
		if ( ! empty( $data['xodw_cc_hp'] ) ) {
			return new WP_Error( 'xodw_cc_rsvp_spam', __( 'Your confirmation could not be saved.', 'calendarcore' ), array( 'status' => 400 ) );
		}

		$name   = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		$email  = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
		$guests = isset( $data['guests'] ) ? max( 1, (int) $data['guests'] ) : 1;
		$guests = min( $guests, max( 1, (int) xodw_cc_setting( 'rsvp_guests_max', 5 ) ) );
		$key    = self::occurrence_key( isset( $data['occ'] ) ? $data['occ'] : $meta['start_utc'] );
		$key    = '' !== $key ? $key : self::occurrence_key( $meta['start_utc'] );

		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'xodw_cc_rsvp_fields', __( 'Please enter your name and a valid email address.', 'calendarcore' ), array( 'status' => 400 ) );
		}

		$occurrence = Ics::resolve_occurrence( $event_id, $key );

		if ( $occurrence && $occurrence->is_past() ) {
			return new WP_Error( 'xodw_cc_rsvp_past', __( 'This event has already taken place.', 'calendarcore' ), array( 'status' => 403 ) );
		}

		if ( ! self::check_rate_limit() ) {
			return new WP_Error( 'xodw_cc_rsvp_rate', __( 'Too many attempts. Please try again later.', 'calendarcore' ), array( 'status' => 429 ) );
		}

		if ( self::already_registered( $event_id, $email, $key ) ) {
			return new WP_Error( 'xodw_cc_rsvp_duplicate', __( 'You are already on the list for this event.', 'calendarcore' ), array( 'status' => 409 ) );
		}

		$remaining = self::remaining( $event_id, $key );

		if ( null !== $remaining && $remaining < $guests ) {
			return new WP_Error(
				'xodw_cc_rsvp_full',
				0 === $remaining
					? __( 'This event is fully booked.', 'calendarcore' )
					: __( 'Not enough seats left for that many guests.', 'calendarcore' ),
				array( 'status' => 409 )
			);
		}

		$approved = xodw_cc_setting( 'rsvp_moderation' ) ? 0 : 1;

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $event_id,
				'comment_author'       => $name,
				'comment_author_email' => $email,
				'comment_content'      => '',
				'comment_type'         => self::COMMENT_TYPE,
				'comment_approved'     => $approved,
				'comment_author_IP'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				'user_id'              => get_current_user_id(),
				'comment_meta'         => array(
					'_xodw_cc_occ'    => $key,
					'_xodw_cc_guests' => $guests,
				),
			)
		);

		if ( ! $comment_id ) {
			return new WP_Error( 'xodw_cc_rsvp_failed', __( 'Your confirmation could not be saved.', 'calendarcore' ), array( 'status' => 500 ) );
		}

		self::bump_rate_limit();

		/**
		 * Fires after an RSVP was stored. The Pro add-on hooks the confirmation
		 * email and the reminders here.
		 *
		 * @since 1.0.0
		 *
		 * @param int                 $comment_id RSVP record ID.
		 * @param int                 $event_id   Event post ID.
		 * @param array<string,mixed> $payload    Stored values.
		 */
		do_action(
			'xodw_cc_rsvp_created',
			(int) $comment_id,
			$event_id,
			array(
				'name'   => $name,
				'email'  => $email,
				'guests' => $guests,
				'occ'    => $key,
			)
		);

		return array(
			'status'  => $approved ? 'confirmed' : 'pending',
			'count'   => self::count( $event_id, $key ),
			'seats'   => self::remaining( $event_id, $key ),
			'message' => $approved
				? __( 'You are on the list. See you there!', 'calendarcore' )
				: __( 'Thanks! Your registration is awaiting approval.', 'calendarcore' ),
		);
	}

	/**
	 * Whether this email already registered for the occurrence.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $email    Email address.
	 * @param string $key      Occurrence key.
	 * @return bool
	 */
	public static function already_registered( $event_id, $email, $key ) {
		$existing = get_comments(
			array(
				'post_id'      => (int) $event_id,
				'author_email' => $email,
				'type'         => self::COMMENT_TYPE,
				'status'       => 'all',
				'number'       => 20,
				'fields'       => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'   => array(
					array(
						'key'   => '_xodw_cc_occ',
						'value' => $key,
					),
				),
			)
		);

		return ! empty( $existing );
	}

	/**
	 * Rate limit check, keyed by a hashed IP.
	 *
	 * @return bool True when the request may proceed.
	 */
	private static function check_rate_limit() {
		$hits = (int) get_transient( self::rate_key() );

		return $hits < self::RATE_LIMIT;
	}

	/**
	 * Increments the rate limit counter.
	 *
	 * @return void
	 */
	private static function bump_rate_limit() {
		$key  = self::rate_key();
		$hits = (int) get_transient( $key );

		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Transient key of the rate limiter. The IP is hashed, never stored raw.
	 *
	 * @return string
	 */
	private static function rate_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return 'xodw_cc_rl_' . md5( $ip . wp_salt() );
	}

	/**
	 * Handles the no-JavaScript form submission.
	 *
	 * @return void
	 */
	public function handle_post() {
		$nonce = isset( $_POST['xodw_cc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['xodw_cc_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'xodw_cc_rsvp' ) ) {
			wp_die( esc_html__( 'The form expired. Please reload the page and try again.', 'calendarcore' ), 403 );
		}

		$result = self::submit(
			array(
				'event_id'   => isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0,
				'occ'        => isset( $_POST['occ'] ) ? sanitize_text_field( wp_unslash( $_POST['occ'] ) ) : '',
				'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'email'      => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'guests'     => isset( $_POST['guests'] ) ? absint( $_POST['guests'] ) : 1,
				'xodw_cc_hp' => isset( $_POST['xodw_cc_hp'] ) ? sanitize_text_field( wp_unslash( $_POST['xodw_cc_hp'] ) ) : '',
			)
		);

		$redirect = isset( $_POST['redirect'] )
			? wp_validate_redirect( sanitize_url( wp_unslash( $_POST['redirect'] ) ), home_url( '/' ) )
			: home_url( '/' );

		$redirect = add_query_arg(
			array(
				'xodw_cc_rsvp' => is_wp_error( $result ) ? 'error' : $result['status'],
				'xodw_cc_msg'  => rawurlencode( is_wp_error( $result ) ? $result->get_error_message() : $result['message'] ),
			),
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * RSVP form markup for one occurrence.
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @return string
	 */
	public static function form( Occurrence $occurrence ) {
		if ( ! $occurrence->rsvp_enabled() ) {
			return '';
		}

		$event_id  = $occurrence->event_id;
		$key       = $occurrence->key();
		$remaining = self::remaining( $event_id, $key );
		$max       = max( 1, (int) xodw_cc_setting( 'rsvp_guests_max', 5 ) );
		$notice    = '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['xodw_cc_rsvp'], $_GET['xodw_cc_msg'] ) ) {
			$status = sanitize_key( wp_unslash( $_GET['xodw_cc_rsvp'] ) );
			$raw    = sanitize_text_field( wp_unslash( $_GET['xodw_cc_msg'] ) );
			$text   = sanitize_text_field( rawurldecode( $raw ) );
			$class  = 'error' === $status ? 'is-error' : 'is-success';
			$notice = '<p class="xodw-cc-rsvp__notice ' . esc_attr( $class ) . '" role="status">' . esc_html( $text ) . '</p>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$html  = '<div class="xodw-cc-rsvp" data-xodw-cc-rsvp="1" data-event="' . esc_attr( (string) $event_id ) . '" data-occ="' . esc_attr( $key ) . '">';
		$html .= '<h3 class="xodw-cc-rsvp__title">' . esc_html__( 'Save your spot', 'calendarcore' ) . '</h3>';

		// Rendered by the browser, so page caches never serve a stale counter.
		$html .= '<p class="xodw-cc-rsvp__count" data-xodw-cc-rsvp-count="1">';
		$html .= '<span class="xodw-cc-rsvp__count-value"></span></p>';

		$html .= $notice;

		$html .= '<form class="xodw-cc-rsvp__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="xodw_cc_rsvp" />';
		$html .= '<input type="hidden" name="event_id" value="' . esc_attr( (string) $event_id ) . '" />';
		$html .= '<input type="hidden" name="occ" value="' . esc_attr( $key ) . '" />';
		$html .= '<input type="hidden" name="redirect" value="' . esc_url( $occurrence->permalink() ) . '" />';
		$html .= wp_nonce_field( 'xodw_cc_rsvp', 'xodw_cc_nonce', true, false );

		$html .= '<p class="xodw-cc-rsvp__field"><label for="xodw-cc-rsvp-name-' . esc_attr( $key ) . '">' . esc_html__( 'Name', 'calendarcore' ) . '</label>';
		$html .= '<input type="text" id="xodw-cc-rsvp-name-' . esc_attr( $key ) . '" name="name" required autocomplete="name" /></p>';

		$html .= '<p class="xodw-cc-rsvp__field"><label for="xodw-cc-rsvp-email-' . esc_attr( $key ) . '">' . esc_html__( 'Email', 'calendarcore' ) . '</label>';
		$html .= '<input type="email" id="xodw-cc-rsvp-email-' . esc_attr( $key ) . '" name="email" required autocomplete="email" /></p>';

		if ( $max > 1 ) {
			$html .= '<p class="xodw-cc-rsvp__field"><label for="xodw-cc-rsvp-guests-' . esc_attr( $key ) . '">' . esc_html__( 'Number of people', 'calendarcore' ) . '</label>';
			$html .= '<input type="number" id="xodw-cc-rsvp-guests-' . esc_attr( $key ) . '" name="guests" value="1" min="1" max="' . esc_attr( (string) $max ) . '" inputmode="numeric" /></p>';
		}

		// Honeypot, hidden from humans and from screen readers.
		$html .= '<p class="xodw-cc-rsvp__hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'calendarcore' );
		$html .= '<input type="text" name="xodw_cc_hp" value="" tabindex="-1" autocomplete="off" /></label></p>';

		$disabled = ( null !== $remaining && 0 === $remaining );

		$html .= '<p class="xodw-cc-rsvp__submit"><button type="submit" class="xodw-cc__btn xodw-cc__btn--primary"' . ( $disabled ? ' disabled' : '' ) . '>';
		$html .= esc_html( $disabled ? __( 'Fully booked', 'calendarcore' ) : __( 'Confirm attendance', 'calendarcore' ) ) . '</button></p>';
		$html .= '<p class="xodw-cc-rsvp__message" data-xodw-cc-rsvp-message="1" role="status" aria-live="polite"></p>';
		$html .= '</form></div>';

		/**
		 * Filters the RSVP form markup.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $html       Form markup.
		 * @param Occurrence $occurrence Occurrence.
		 */
		return apply_filters( 'xodw_cc_rsvp_form', $html, $occurrence );
	}
}
