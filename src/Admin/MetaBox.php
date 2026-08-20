<?php
/**
 * Event editor fields.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Admin;

defined( 'ABSPATH' ) || exit;

use XODW\CalendarCore\EventMeta;
use XODW\CalendarCore\Rsvp;

/**
 * One meta box with native date and time inputs. No jQuery UI datepicker, no
 * third party picker: the browser already ships one.
 */
class MetaBox {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post_' . XODW_CC_POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Registers the meta boxes.
	 *
	 * @return void
	 */
	public function register() {
		add_meta_box(
			'xodw_cc_event_when',
			__( 'Date and time', 'calendarcore' ),
			array( $this, 'render_when' ),
			XODW_CC_POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'xodw_cc_event_details',
			__( 'Event details', 'calendarcore' ),
			array( $this, 'render_details' ),
			XODW_CC_POST_TYPE,
			'normal',
			'default'
		);

		if ( xodw_cc_module_enabled( 'rsvp' ) ) {
			add_meta_box(
				'xodw_cc_event_rsvp',
				__( 'RSVP', 'calendarcore' ),
				array( $this, 'render_rsvp' ),
				XODW_CC_POST_TYPE,
				'side',
				'default'
			);
		}
	}

	/**
	 * Date, time and recurrence fields.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_when( $post ) {
		$meta = EventMeta::get( $post->ID );

		wp_nonce_field( 'xodw_cc_save_event', 'xodw_cc_event_nonce' );

		$start_date = '' !== $meta['start'] ? substr( $meta['start'], 0, 10 ) : '';
		$start_time = '' !== $meta['start'] ? substr( $meta['start'], 11, 5 ) : '';
		$end_date   = '' !== $meta['end'] ? substr( $meta['end'], 0, 10 ) : '';
		$end_time   = '' !== $meta['end'] ? substr( $meta['end'], 11, 5 ) : '';
		$byday      = array_filter( explode( ',', (string) $meta['recur_byday'] ) );

		?>
		<div class="xodw-cc-admin" data-xodw-cc-admin="1">
			<div class="xodw-cc-admin__row">
				<label class="xodw-cc-admin__check">
					<input type="checkbox" name="xodw_cc[all_day]" value="1" data-xodw-cc-allday="1" <?php checked( (bool) $meta['all_day'] ); ?> />
					<?php esc_html_e( 'All day event', 'calendarcore' ); ?>
				</label>
			</div>

			<div class="xodw-cc-admin__grid">
				<p class="xodw-cc-admin__field">
					<label for="xodw-cc-start-date"><?php esc_html_e( 'Starts', 'calendarcore' ); ?></label>
					<input type="date" id="xodw-cc-start-date" name="xodw_cc[start_date]" value="<?php echo esc_attr( $start_date ); ?>" required />
				</p>
				<p class="xodw-cc-admin__field" data-xodw-cc-timefield="1">
					<label for="xodw-cc-start-time"><?php esc_html_e( 'Start time', 'calendarcore' ); ?></label>
					<input type="time" id="xodw-cc-start-time" name="xodw_cc[start_time]" value="<?php echo esc_attr( $start_time ); ?>" />
				</p>
				<p class="xodw-cc-admin__field">
					<label for="xodw-cc-end-date"><?php esc_html_e( 'Ends', 'calendarcore' ); ?></label>
					<input type="date" id="xodw-cc-end-date" name="xodw_cc[end_date]" value="<?php echo esc_attr( $end_date ); ?>" />
				</p>
				<p class="xodw-cc-admin__field" data-xodw-cc-timefield="1">
					<label for="xodw-cc-end-time"><?php esc_html_e( 'End time', 'calendarcore' ); ?></label>
					<input type="time" id="xodw-cc-end-time" name="xodw_cc[end_time]" value="<?php echo esc_attr( $end_time ); ?>" />
				</p>
			</div>

			<p class="xodw-cc-admin__field">
				<label for="xodw-cc-timezone"><?php esc_html_e( 'Timezone', 'calendarcore' ); ?></label>
				<select id="xodw-cc-timezone" name="xodw_cc[timezone]">
					<option value=""><?php echo esc_html( sprintf( /* translators: %s: site timezone. */ __( 'Site default (%s)', 'calendarcore' ), wp_timezone_string() ) ); ?></option>
					<?php foreach ( timezone_identifiers_list() as $identifier ) : ?>
						<option value="<?php echo esc_attr( $identifier ); ?>" <?php selected( $meta['timezone'], $identifier ); ?>>
							<?php echo esc_html( $identifier ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<?php if ( xodw_cc_module_enabled( 'recurring' ) ) : ?>
				<hr />
				<p class="xodw-cc-admin__field">
					<label for="xodw-cc-recur-freq"><?php esc_html_e( 'Repeats', 'calendarcore' ); ?></label>
					<select id="xodw-cc-recur-freq" name="xodw_cc[recur_freq]" data-xodw-cc-freq="1">
						<option value="none" <?php selected( $meta['recur_freq'], 'none' ); ?>><?php esc_html_e( 'Does not repeat', 'calendarcore' ); ?></option>
						<option value="daily" <?php selected( $meta['recur_freq'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'calendarcore' ); ?></option>
						<option value="weekly" <?php selected( $meta['recur_freq'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'calendarcore' ); ?></option>
						<option value="monthly" <?php selected( $meta['recur_freq'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'calendarcore' ); ?></option>
						<option value="yearly" <?php selected( $meta['recur_freq'], 'yearly' ); ?>><?php esc_html_e( 'Yearly', 'calendarcore' ); ?></option>
					</select>
				</p>

				<div class="xodw-cc-admin__recurrence" data-xodw-cc-recurrence="1" <?php echo 'none' === $meta['recur_freq'] ? 'hidden' : ''; ?>>
					<p class="xodw-cc-admin__field">
						<label for="xodw-cc-recur-interval"><?php esc_html_e( 'Every', 'calendarcore' ); ?></label>
						<input type="number" id="xodw-cc-recur-interval" name="xodw_cc[recur_interval]" min="1" max="52" value="<?php echo esc_attr( (string) max( 1, (int) $meta['recur_interval'] ) ); ?>" />
						<span class="xodw-cc-admin__hint" data-xodw-cc-unit="1"></span>
					</p>

					<fieldset class="xodw-cc-admin__field" data-xodw-cc-weekly="1" <?php echo 'weekly' === $meta['recur_freq'] ? '' : 'hidden'; ?>>
						<legend><?php esc_html_e( 'On these days', 'calendarcore' ); ?></legend>
						<?php
						$labels = array(
							'MO' => __( 'Mon', 'calendarcore' ),
							'TU' => __( 'Tue', 'calendarcore' ),
							'WE' => __( 'Wed', 'calendarcore' ),
							'TH' => __( 'Thu', 'calendarcore' ),
							'FR' => __( 'Fri', 'calendarcore' ),
							'SA' => __( 'Sat', 'calendarcore' ),
							'SU' => __( 'Sun', 'calendarcore' ),
						);

						foreach ( $labels as $code => $label ) :
							?>
							<label class="xodw-cc-admin__check">
								<input type="checkbox" name="xodw_cc[recur_byday][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $byday, true ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<p class="xodw-cc-admin__field" data-xodw-cc-monthly="1" <?php echo 'monthly' === $meta['recur_freq'] ? '' : 'hidden'; ?>>
						<label for="xodw-cc-recur-monthly"><?php esc_html_e( 'Monthly pattern', 'calendarcore' ); ?></label>
						<select id="xodw-cc-recur-monthly" name="xodw_cc[recur_monthly_mode]">
							<option value="monthday" <?php selected( $meta['recur_monthly_mode'], 'monthday' ); ?>><?php esc_html_e( 'Same day of the month', 'calendarcore' ); ?></option>
							<option value="weekday" <?php selected( $meta['recur_monthly_mode'], 'weekday' ); ?>><?php esc_html_e( 'Same weekday position (e.g. third Tuesday)', 'calendarcore' ); ?></option>
						</select>
					</p>

					<div class="xodw-cc-admin__grid">
						<p class="xodw-cc-admin__field">
							<label for="xodw-cc-recur-until"><?php esc_html_e( 'Repeat until', 'calendarcore' ); ?></label>
							<input type="date" id="xodw-cc-recur-until" name="xodw_cc[recur_until]" value="<?php echo esc_attr( (string) $meta['recur_until'] ); ?>" />
						</p>
						<p class="xodw-cc-admin__field">
							<label for="xodw-cc-recur-count"><?php esc_html_e( 'or number of times', 'calendarcore' ); ?></label>
							<input type="number" id="xodw-cc-recur-count" name="xodw_cc[recur_count]" min="0" max="2000" value="<?php echo esc_attr( (string) (int) $meta['recur_count'] ); ?>" />
						</p>
					</div>

					<p class="xodw-cc-admin__field">
						<label for="xodw-cc-recur-exdates"><?php esc_html_e( 'Skip these dates', 'calendarcore' ); ?></label>
						<input type="text" id="xodw-cc-recur-exdates" name="xodw_cc[recur_exdates]" value="<?php echo esc_attr( (string) $meta['recur_exdates'] ); ?>" placeholder="2026-12-24, 2026-12-31" />
						<span class="xodw-cc-admin__hint"><?php esc_html_e( 'Comma separated, YYYY-MM-DD.', 'calendarcore' ); ?></span>
					</p>

					<p class="xodw-cc-admin__hint">
						<?php
						$count = (int) get_post_meta( $post->ID, '_xodw_cc_instances_count', true );

						echo esc_html(
							sprintf(
								/* translators: %d: number of generated dates. */
								_n( '%d date generated for the next 12 months.', '%d dates generated for the next 12 months.', $count, 'calendarcore' ),
								$count
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Website, price and helper links.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_details( $post ) {
		$meta = EventMeta::get( $post->ID );
		?>
		<div class="xodw-cc-admin">
			<div class="xodw-cc-admin__grid">
				<p class="xodw-cc-admin__field">
					<label for="xodw-cc-url"><?php esc_html_e( 'Event website', 'calendarcore' ); ?></label>
					<input type="url" id="xodw-cc-url" name="xodw_cc[url]" value="<?php echo esc_attr( (string) $meta['url'] ); ?>" placeholder="https://" />
				</p>
				<p class="xodw-cc-admin__field">
					<label for="xodw-cc-cost"><?php esc_html_e( 'Price', 'calendarcore' ); ?></label>
					<input type="text" id="xodw-cc-cost" name="xodw_cc[cost]" value="<?php echo esc_attr( (string) $meta['cost'] ); ?>" placeholder="<?php esc_attr_e( 'Free', 'calendarcore' ); ?>" />
				</p>
			</div>
			<p class="xodw-cc-admin__hint">
				<?php esc_html_e( 'Venue and organizer are taxonomies: use the boxes in the sidebar so visitors can filter by them.', 'calendarcore' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * RSVP settings and the current attendee count.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_rsvp( $post ) {
		$meta  = EventMeta::get( $post->ID );
		$count = Rsvp::count( $post->ID );
		?>
		<div class="xodw-cc-admin">
			<p class="xodw-cc-admin__field">
				<label class="xodw-cc-admin__check">
					<input type="checkbox" name="xodw_cc[rsvp_enabled]" value="1" <?php checked( (bool) $meta['rsvp_enabled'] ); ?> />
					<?php esc_html_e( 'Let visitors confirm attendance', 'calendarcore' ); ?>
				</label>
			</p>
			<p class="xodw-cc-admin__field">
				<label for="xodw-cc-rsvp-capacity"><?php esc_html_e( 'Capacity (0 = unlimited)', 'calendarcore' ); ?></label>
				<input type="number" id="xodw-cc-rsvp-capacity" name="xodw_cc[rsvp_capacity]" min="0" value="<?php echo esc_attr( (string) (int) $meta['rsvp_capacity'] ); ?>" />
			</p>
			<p class="xodw-cc-admin__hint">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of confirmed guests. */
						_n( '%d guest confirmed.', '%d guests confirmed.', $count, 'calendarcore' ),
						$count
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Saves the posted fields.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		unset( $post );

		if ( ! isset( $_POST['xodw_cc_event_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['xodw_cc_event_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'xodw_cc_save_event' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$input = isset( $_POST['xodw_cc'] ) ? wp_unslash( $_POST['xodw_cc'] ) : array();
		$input = is_array( $input ) ? $input : array();

		$all_day = ! empty( $input['all_day'] );

		$start = $this->combine(
			isset( $input['start_date'] ) ? $input['start_date'] : '',
			isset( $input['start_time'] ) ? $input['start_time'] : '',
			$all_day
		);

		$end = $this->combine(
			isset( $input['end_date'] ) ? $input['end_date'] : ( isset( $input['start_date'] ) ? $input['start_date'] : '' ),
			isset( $input['end_time'] ) ? $input['end_time'] : '',
			$all_day
		);

		update_post_meta( $post_id, '_xodw_cc_all_day', $all_day ? 1 : 0 );
		update_post_meta( $post_id, '_xodw_cc_start', EventMeta::sanitize_datetime( $start ) );
		update_post_meta( $post_id, '_xodw_cc_end', EventMeta::sanitize_datetime( $end ) );
		update_post_meta( $post_id, '_xodw_cc_timezone', EventMeta::sanitize_timezone( isset( $input['timezone'] ) ? $input['timezone'] : '' ) );
		update_post_meta( $post_id, '_xodw_cc_url', sanitize_url( isset( $input['url'] ) ? $input['url'] : '' ) );
		update_post_meta( $post_id, '_xodw_cc_cost', sanitize_text_field( isset( $input['cost'] ) ? $input['cost'] : '' ) );

		if ( xodw_cc_module_enabled( 'recurring' ) ) {
			update_post_meta( $post_id, '_xodw_cc_recur_freq', EventMeta::sanitize_frequency( isset( $input['recur_freq'] ) ? $input['recur_freq'] : 'none' ) );
			update_post_meta( $post_id, '_xodw_cc_recur_interval', EventMeta::sanitize_interval( isset( $input['recur_interval'] ) ? $input['recur_interval'] : 1 ) );
			update_post_meta( $post_id, '_xodw_cc_recur_byday', EventMeta::sanitize_byday( isset( $input['recur_byday'] ) ? $input['recur_byday'] : '' ) );
			update_post_meta( $post_id, '_xodw_cc_recur_monthly_mode', EventMeta::sanitize_monthly_mode( isset( $input['recur_monthly_mode'] ) ? $input['recur_monthly_mode'] : 'monthday' ) );
			update_post_meta( $post_id, '_xodw_cc_recur_until', EventMeta::sanitize_date( isset( $input['recur_until'] ) ? $input['recur_until'] : '' ) );
			update_post_meta( $post_id, '_xodw_cc_recur_count', EventMeta::sanitize_count( isset( $input['recur_count'] ) ? $input['recur_count'] : 0 ) );
			update_post_meta( $post_id, '_xodw_cc_recur_exdates', EventMeta::sanitize_date_list( isset( $input['recur_exdates'] ) ? $input['recur_exdates'] : '' ) );
		}

		if ( xodw_cc_module_enabled( 'rsvp' ) ) {
			update_post_meta( $post_id, '_xodw_cc_rsvp_enabled', ! empty( $input['rsvp_enabled'] ) ? 1 : 0 );
			update_post_meta( $post_id, '_xodw_cc_rsvp_capacity', EventMeta::sanitize_capacity( isset( $input['rsvp_capacity'] ) ? $input['rsvp_capacity'] : 0 ) );
		}
	}

	/**
	 * Joins a date and a time input into a datetime string.
	 *
	 * @param mixed $date    Date value.
	 * @param mixed $time    Time value.
	 * @param bool  $all_day Whether the event covers whole days.
	 * @return string
	 */
	private function combine( $date, $time, $all_day ) {
		$date = is_scalar( $date ) ? trim( (string) $date ) : '';
		$time = is_scalar( $time ) ? trim( (string) $time ) : '';

		if ( '' === $date ) {
			return '';
		}

		if ( $all_day || '' === $time ) {
			return $date . ' 00:00:00';
		}

		return $date . ' ' . ( 5 === strlen( $time ) ? $time . ':00' : $time );
	}
}
