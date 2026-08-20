<?php
/**
 * Elementor adapter: a thin wrapper, no duplicated rendering logic.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Registers one Elementor widget that delegates to the shared renderer. The
 * class is only instantiated when Elementor is actually loaded.
 */
class Elementor {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
	}

	/**
	 * Registers the widget with Elementor.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widget( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		// Autoloading happens here, when Elementor's base class exists.
		$widgets_manager->register( new ElementorWidget() );
	}

	/**
	 * Adds a panel category.
	 *
	 * @param object $manager Elementor elements manager.
	 * @return void
	 */
	public function register_category( $manager ) {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'add_category' ) ) {
			return;
		}

		$manager->add_category(
			'xodw-cc',
			array(
				'title' => __( 'CalendarCore', 'calendarcore' ),
				'icon'  => 'eicon-calendar',
			)
		);
	}
}
