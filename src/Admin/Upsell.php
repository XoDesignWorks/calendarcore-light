<?php
/**
 * Pro hints. One card, dismissible, never a nag screen.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a single quiet card next to the features Pro extends.
 */
class Upsell {

	const DISMISS_META = 'xodw_cc_upsell_dismissed';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'register' ), 20 );
		add_action( 'admin_post_xodw_cc_dismiss_upsell', array( $this, 'dismiss' ) );
	}

	/**
	 * Whether the card may be shown.
	 *
	 * @return bool
	 */
	public static function visible() {
		if ( xodw_cc_is_pro() ) {
			return false;
		}

		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return false;
		}

		/**
		 * Filters whether the Pro card is rendered at all.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $visible Whether to show the card.
		 */
		return (bool) apply_filters( 'xodw_cc_show_upsell', true );
	}

	/**
	 * Registers the sidebar card on the event editor.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! self::visible() ) {
			return;
		}

		add_meta_box(
			'xodw_cc_pro',
			__( 'CalendarCore roadmap', 'calendarcore' ),
			array( $this, 'render' ),
			XODW_CC_POST_TYPE,
			'side',
			'low'
		);
	}

	/**
	 * Card markup.
	 *
	 * @return void
	 */
	public function render() {
		?>
		<div class="xodw-cc-admin xodw-cc-admin__upsell">
			<p><?php esc_html_e( 'Recurring events, .ics export and time zones are free, forever. These features are in development as a paid add-on:', 'calendarcore' ); ?></p>
			<ul>
				<?php foreach ( self::catalog() as $feature ) : ?>
					<li><?php echo esc_html( $feature['title'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a class="button button-secondary" href="https://xodesignworks.com/" target="_blank" rel="noopener">
					<?php esc_html_e( 'Follow development', 'calendarcore' ); ?>
				</a>
			</p>
			<p>
				<a class="xodw-cc-admin__dismiss" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xodw_cc_dismiss_upsell' ), 'xodw_cc_dismiss_upsell' ) ); ?>">
					<?php esc_html_e( 'Hide this', 'calendarcore' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * The paid feature catalog. Shipped as a generated file so the free build
	 * can describe the paid edition without containing any of its code.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function catalog() {
		$path = XODW_CC_DIR . 'pro-catalog.php';

		if ( is_readable( $path ) ) {
			$catalog = include $path;

			if ( is_array( $catalog ) && ! empty( $catalog ) ) {
				return $catalog;
			}
		}

		return array(
			array(
				'slug'  => 'tickets',
				'title' => __( 'Ticket sales with Stripe or WooCommerce', 'calendarcore' ),
				'blurb' => '',
			),
			array(
				'slug'  => 'reminders',
				'title' => __( 'Email and SMS reminders', 'calendarcore' ),
				'blurb' => '',
			),
			array(
				'slug'  => 'google',
				'title' => __( 'Two way Google Calendar sync', 'calendarcore' ),
				'blurb' => '',
			),
		);
	}

	/**
	 * Stores the dismissal per user.
	 *
	 * @return void
	 */
	public function dismiss() {
		check_admin_referer( 'xodw_cc_dismiss_upsell' );

		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . XODW_CC_POST_TYPE ) );
		exit;
	}
}
