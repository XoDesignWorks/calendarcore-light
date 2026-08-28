<?php
/**
 * Asset registration. Nothing is loaded on pages without a calendar.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Registers one stylesheet and one script, both dependency free: no jQuery, no
 * date library, no polyfills.
 */
class Assets {

	const STYLE        = 'xodw-cc';
	const SCRIPT       = 'xodw-cc';
	const BLOCK_SCRIPT = 'xodw-cc-blocks';
	const ADMIN_STYLE  = 'xodw-cc-admin';
	const ADMIN_SCRIPT = 'xodw-cc-admin';

	/**
	 * Whether the front end assets were already requested.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	/**
	 * Registers every handle. Registration is cheap; enqueueing is on demand.
	 *
	 * @return void
	 */
	public function register() {
		wp_register_style( self::STYLE, XODW_CC_URL . 'assets/css/calendarcore.css', array(), XODW_CC_VERSION );

		// Handing WordPress the file path lets it inline the stylesheet instead
		// of costing a render blocking request: the whole sheet is smaller than
		// the round trip that would fetch it. Core keeps its own size limit, so
		// a site that raises the sheet stays on a normal <link>.
		wp_style_add_data( self::STYLE, 'path', XODW_CC_DIR . 'assets/css/calendarcore.css' );

		wp_register_script(
			self::SCRIPT,
			XODW_CC_URL . 'assets/js/calendarcore.js',
			array(),
			XODW_CC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			self::BLOCK_SCRIPT,
			XODW_CC_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			XODW_CC_VERSION,
			array( 'in_footer' => true )
		);

		wp_register_style( self::ADMIN_STYLE, XODW_CC_URL . 'assets/css/admin.css', array(), XODW_CC_VERSION );
		wp_register_script(
			self::ADMIN_SCRIPT,
			XODW_CC_URL . 'assets/js/admin.js',
			array(),
			XODW_CC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// The paid build ships its own catalogues; the free build falls back to
		// the ones wordpress.org installs, which this path does not disturb.
		$languages = XODW_CC_DIR . 'languages';

		wp_set_script_translations( self::BLOCK_SCRIPT, 'calendarcore', $languages );
		wp_set_script_translations( self::SCRIPT, 'calendarcore', $languages );
	}

	/**
	 * Enqueues the front end assets once, from wherever a calendar is rendered.
	 *
	 * @return void
	 */
	public static function enqueue_frontend() {
		if ( self::$enqueued ) {
			return;
		}

		self::$enqueued = true;

		if ( xodw_cc_setting( 'load_css' ) ) {
			wp_enqueue_style( self::STYLE );

			$accent = (string) xodw_cc_setting( 'accent_color' );

			if ( '' !== $accent ) {
				wp_add_inline_style( self::STYLE, '.xodw-cc{--xodw-cc-accent:' . $accent . ';}' );
			}
		}

		wp_enqueue_script( self::SCRIPT );
		wp_add_inline_script( self::SCRIPT, 'window.xodwCcConfig=' . wp_json_encode( self::script_config() ) . ';', 'before' );
	}

	/**
	 * Configuration handed to the front end script.
	 *
	 * @return array<string,mixed>
	 */
	public static function script_config() {
		$config = array(
			'rest'     => esc_url_raw( rest_url( Rest::NAMESPACE_V1 ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'tzAware'  => (bool) xodw_cc_setting( 'timezone_aware' ),
			'timezone' => wp_timezone_string(),
			// Times are reformatted in the visitor timezone but in the site
			// language, so the page never mixes two languages.
			'locale'   => str_replace( '_', '-', get_locale() ),
			'hour12'   => (bool) preg_match( '/g|h/', (string) get_option( 'time_format' ) ),
			'weekStart' => (int) get_option( 'start_of_week', 1 ),
			'i18n'     => array(
				'loading'  => __( 'Loading…', 'calendarcore' ),
				'error'    => __( 'Could not load events. Please try again.', 'calendarcore' ),
				/* translators: %s: timezone of the visitor, e.g. Europe/Berlin. */
				'yourTime' => __( 'Times shown in your local time (%s)', 'calendarcore' ),
				'sending'  => __( 'Sending…', 'calendarcore' ),
				/* translators: %d: number of remaining seats. */
				'seats'    => __( '%d seats left', 'calendarcore' ),
				/* translators: %d: number of confirmed attendees. */
				'going'    => __( '%d going', 'calendarcore' ),
				'full'     => __( 'Fully booked', 'calendarcore' ),
			),
		);

		/**
		 * Filters the front end script configuration.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $config Configuration.
		 */
		return apply_filters( 'xodw_cc_script_config', $config );
	}

	/**
	 * Admin assets, only on the screens that need them.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_event = $screen && XODW_CC_POST_TYPE === $screen->post_type;
		$is_settings = is_string( $hook ) && false !== strpos( $hook, 'xodw-cc-settings' );

		if ( ! $is_event && ! $is_settings ) {
			return;
		}

		wp_enqueue_style( self::ADMIN_STYLE );

		if ( $is_event ) {
			wp_enqueue_script( self::ADMIN_SCRIPT );
		}
	}
}
