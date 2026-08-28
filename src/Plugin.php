<?php
/**
 * Plugin bootstrap.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the components together. Optional modules are only instantiated when
 * enabled, so a site that does not use RSVP pays nothing for it.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Booted components, keyed by short name.
	 *
	 * @var array<string,object>
	 */
	private $components = array();

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Private constructor: use instance().
	 */
	private function __construct() {}

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers every component.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$classes = array(
			'post_types' => PostTypes::class,
			'meta'       => EventMeta::class,
			'sync'       => Sync::class,
			'assets'     => Assets::class,
			'rest'       => Rest::class,
			'single'     => Frontend\Single::class,
			'install'    => Install::class,
		);

		if ( xodw_cc_module_enabled( 'recurring' ) ) {
			$classes['cron'] = Cron::class;
		}

		if ( xodw_cc_module_enabled( 'ics' ) ) {
			$classes['ics'] = Ics::class;
		}

		if ( xodw_cc_module_enabled( 'rsvp' ) ) {
			$classes['rsvp'] = Rsvp::class;
		}

		if ( xodw_cc_module_enabled( 'blocks' ) ) {
			$classes['blocks'] = Blocks::class;
		}

		if ( xodw_cc_module_enabled( 'shortcodes' ) ) {
			$classes['shortcodes'] = Frontend\Shortcodes::class;
		}

		if ( xodw_cc_module_enabled( 'elementor' ) ) {
			// boot() runs before Elementor is loaded, so the adapter waits.
			add_action(
				'elementor/loaded',
				static function () {
					$adapter = new Integrations\Elementor();
					$adapter->hooks();
				}
			);
		}

		// Present only in the paid build; the free build simply has no such file.
		if ( class_exists( Modules\Loader::class ) ) {
			$classes['pro'] = Modules\Loader::class;
		}

		if ( is_admin() ) {
			$classes['admin_meta']     = Admin\MetaBox::class;
			$classes['admin_columns']  = Admin\Columns::class;
			$classes['admin_settings'] = Admin\SettingsPage::class;
			$classes['admin_import']   = Admin\ImportPage::class;
			$classes['admin_upsell']   = Admin\Upsell::class;
		}

		/**
		 * Filters the component map before instantiation. Lets the Pro add-on
		 * inject its own modules without touching the free plugin.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,string> $classes Map of component name to class name.
		 */
		$classes = apply_filters( 'xodw_cc_components', $classes );

		foreach ( $classes as $name => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$component = new $class();

			if ( method_exists( $component, 'hooks' ) ) {
				$component->hooks();
			}

			$this->components[ $name ] = $component;
		}

		/**
		 * Fires once all CalendarCore components are registered.
		 *
		 * @since 1.0.0
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'xodw_cc_loaded', $this );
	}

	/**
	 * Returns a booted component.
	 *
	 * @param string $name Component name.
	 * @return object|null
	 */
	public function get( $name ) {
		return isset( $this->components[ $name ] ) ? $this->components[ $name ] : null;
	}
}
