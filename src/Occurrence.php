<?php
/**
 * A single occurrence of an event.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight value object handed to the renderer, the blocks and the REST
 * endpoints. Post data is fetched lazily through the WordPress object cache.
 */
class Occurrence {

	/**
	 * Event post ID.
	 *
	 * @var int
	 */
	public $event_id;

	/**
	 * Occurrence row ID, 0 for virtual occurrences.
	 *
	 * @var int
	 */
	public $instance_id;

	/**
	 * Start in UTC, 'Y-m-d H:i:s'.
	 *
	 * @var string
	 */
	public $start;

	/**
	 * End in UTC, 'Y-m-d H:i:s'.
	 *
	 * @var string
	 */
	public $end;

	/**
	 * Cached event meta.
	 *
	 * @var array<string,mixed>|null
	 */
	private $meta = null;

	/**
	 * Constructor.
	 *
	 * @param int    $event_id    Event post ID.
	 * @param string $start       UTC start.
	 * @param string $end         UTC end.
	 * @param int    $instance_id Occurrence row ID.
	 */
	public function __construct( $event_id, $start, $end, $instance_id = 0 ) {
		$this->event_id    = (int) $event_id;
		$this->start       = (string) $start;
		$this->end         = (string) $end;
		$this->instance_id = (int) $instance_id;
	}

	/**
	 * Builds an occurrence from a database row.
	 *
	 * @param object $row Row with event_id, start_datetime, end_datetime, id.
	 * @return Occurrence
	 */
	public static function from_row( $row ) {
		return new self(
			isset( $row->event_id ) ? $row->event_id : 0,
			isset( $row->start_datetime ) ? $row->start_datetime : '',
			isset( $row->end_datetime ) ? $row->end_datetime : '',
			isset( $row->id ) ? $row->id : 0
		);
	}

	/**
	 * Event meta, loaded once.
	 *
	 * @return array<string,mixed>
	 */
	public function meta() {
		if ( null === $this->meta ) {
			$this->meta = EventMeta::get( $this->event_id );
		}

		return $this->meta;
	}

	/**
	 * Event timezone.
	 *
	 * @return string
	 */
	public function timezone() {
		$meta = $this->meta();

		return (string) $meta['timezone'];
	}

	/**
	 * Whether the occurrence covers whole days.
	 *
	 * @return bool
	 */
	public function all_day() {
		$meta = $this->meta();

		return (bool) $meta['all_day'];
	}

	/**
	 * Event title.
	 *
	 * @return string
	 */
	public function title() {
		return get_the_title( $this->event_id );
	}

	/**
	 * Permalink pointing at this specific occurrence.
	 *
	 * @return string
	 */
	public function permalink() {
		$link = get_permalink( $this->event_id );

		if ( ! $link ) {
			return '';
		}

		$meta = $this->meta();

		if ( 'none' === $meta['recur_freq'] ) {
			return $link;
		}

		return add_query_arg( 'xodw_cc_occ', $this->key(), $link );
	}

	/**
	 * Stable identifier of the occurrence, used in URLs and RSVP records.
	 *
	 * @return string
	 */
	public function key() {
		return str_replace( array( '-', ' ', ':' ), '', $this->start );
	}

	/**
	 * Short description.
	 *
	 * @param int $words Word limit.
	 * @return string
	 */
	public function excerpt( $words = 22 ) {
		$post = get_post( $this->event_id );

		if ( ! $post ) {
			return '';
		}

		$text = '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content;
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );

		return wp_trim_words( $text, (int) $words, '…' );
	}

	/**
	 * Start as an ISO-8601 UTC string, ready for Intl.DateTimeFormat.
	 *
	 * @return string
	 */
	public function start_iso() {
		return Timezone::to_iso( $this->start );
	}

	/**
	 * End as an ISO-8601 UTC string.
	 *
	 * @return string
	 */
	public function end_iso() {
		return Timezone::to_iso( $this->end );
	}

	/**
	 * Formatted start in the event timezone.
	 *
	 * @param string $format PHP date format.
	 * @return string
	 */
	public function start_format( $format = '' ) {
		return Timezone::format( $this->start, $format, $this->timezone() );
	}

	/**
	 * Formatted end in the event timezone.
	 *
	 * @param string $format PHP date format.
	 * @return string
	 */
	public function end_format( $format = '' ) {
		return Timezone::format( $this->end, $format, $this->timezone() );
	}

	/**
	 * Local date of the occurrence in the event timezone, 'Y-m-d'.
	 *
	 * @return string
	 */
	public function local_date() {
		return substr( Timezone::from_utc( $this->start, $this->timezone() ), 0, 10 );
	}

	/**
	 * Whether the occurrence already ended.
	 *
	 * @return bool
	 */
	public function is_past() {
		return $this->end < gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Whether the occurrence is running right now.
	 *
	 * @return bool
	 */
	public function is_now() {
		$now = gmdate( 'Y-m-d H:i:s' );

		return $this->start <= $now && $this->end >= $now;
	}

	/**
	 * Venue terms.
	 *
	 * @return \WP_Term[]
	 */
	public function venues() {
		$terms = get_the_terms( $this->event_id, XODW_CC_TAX_VENUE );

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Organizer terms.
	 *
	 * @return \WP_Term[]
	 */
	public function organizers() {
		$terms = get_the_terms( $this->event_id, XODW_CC_TAX_ORGANIZER );

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Comma separated venue names.
	 *
	 * @return string
	 */
	public function venue_label() {
		return implode( ', ', wp_list_pluck( $this->venues(), 'name' ) );
	}

	/**
	 * Comma separated organizer names.
	 *
	 * @return string
	 */
	public function organizer_label() {
		return implode( ', ', wp_list_pluck( $this->organizers(), 'name' ) );
	}

	/**
	 * Thumbnail URL.
	 *
	 * @param string $size Image size.
	 * @return string
	 */
	public function thumbnail( $size = 'medium' ) {
		$url = get_the_post_thumbnail_url( $this->event_id, $size );

		return $url ? $url : '';
	}

	/**
	 * Whether RSVP is open for this occurrence.
	 *
	 * @return bool
	 */
	public function rsvp_enabled() {
		$meta = $this->meta();

		return xodw_cc_module_enabled( 'rsvp' ) && ! empty( $meta['rsvp_enabled'] ) && ! $this->is_past();
	}

	/**
	 * Array representation, used by the REST endpoints and the blocks.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		$meta = $this->meta();

		$data = array(
			'event_id'  => $this->event_id,
			'key'       => $this->key(),
			'title'     => $this->title(),
			'permalink' => $this->permalink(),
			'start'     => $this->start_iso(),
			'end'       => $this->end_iso(),
			'all_day'   => $this->all_day(),
			'timezone'  => $this->timezone(),
			'venue'     => $this->venue_label(),
			'organizer' => $this->organizer_label(),
			'excerpt'   => $this->excerpt(),
			'thumbnail' => $this->thumbnail(),
			'recurring' => 'none' !== $meta['recur_freq'],
			'rsvp'      => $this->rsvp_enabled(),
			'ics'       => xodw_cc_module_enabled( 'ics' ) ? xodw_cc_ics_url( $this->event_id, $this->start ) : '',
		);

		/**
		 * Filters the array representation of an occurrence.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $data       Occurrence data.
		 * @param Occurrence          $occurrence Occurrence object.
		 */
		return apply_filters( 'xodw_cc_occurrence_data', $data, $this );
	}
}
