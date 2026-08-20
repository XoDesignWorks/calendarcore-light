<?php
/**
 * Elementor widget definition.
 *
 * This file is only ever loaded from Elementor::register_widget(), which runs
 * inside an Elementor hook, so the parent class is guaranteed to exist.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Integrations;

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use XODW\CalendarCore\Assets;
use XODW\CalendarCore\Frontend\Renderer;

/**
 * Calendar widget for Elementor.
 */
class ElementorWidget extends Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'xodw_cc_calendar';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Event calendar', 'calendarcore' );
	}

	/**
	 * Panel icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-calendar';
	}

	/**
	 * Panel categories.
	 *
	 * @return array<int,string>
	 */
	public function get_categories() {
		return array( 'xodw-cc' );
	}

	/**
	 * Search keywords.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords() {
		return array( 'event', 'events', 'calendar', 'agenda' );
	}

	/**
	 * Front end script dependency.
	 *
	 * @return array<int,string>
	 */
	public function get_script_depends() {
		return array( Assets::SCRIPT );
	}

	/**
	 * Front end style dependency.
	 *
	 * @return array<int,string>
	 */
	public function get_style_depends() {
		return array( Assets::STYLE );
	}

	/**
	 * Widget controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'xodw_cc_section',
			array(
				'label' => __( 'Events', 'calendarcore' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'view',
			array(
				'label'   => __( 'Default view', 'calendarcore' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'month',
				'options' => array(
					'month' => __( 'Month', 'calendarcore' ),
					'week'  => __( 'Week', 'calendarcore' ),
					'day'   => __( 'Day', 'calendarcore' ),
					'list'  => __( 'List', 'calendarcore' ),
				),
			)
		);

		$this->add_control(
			'venue',
			array(
				'label'       => __( 'Venue slugs', 'calendarcore' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Comma separated. Leave empty for all venues.', 'calendarcore' ),
			)
		);

		$this->add_control(
			'organizer',
			array(
				'label'   => __( 'Organizer slugs', 'calendarcore' ),
				'type'    => Controls_Manager::TEXT,
				'default' => '',
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Events per page', 'calendarcore' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 50,
				'default' => 12,
			)
		);

		$this->add_control(
			'toolbar',
			array(
				'label'        => __( 'Navigation toolbar', 'calendarcore' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'one_per_event',
			array(
				'label'        => __( 'Only the next date of recurring events', 'calendarcore' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the widget through the shared renderer.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		Assets::enqueue_frontend();

		$html = Renderer::render(
			array(
				'view'          => isset( $settings['view'] ) ? $settings['view'] : 'month',
				'venue'         => isset( $settings['venue'] ) ? $settings['venue'] : '',
				'organizer'     => isset( $settings['organizer'] ) ? $settings['organizer'] : '',
				'limit'         => isset( $settings['limit'] ) ? (int) $settings['limit'] : 12,
				'toolbar'       => ! empty( $settings['toolbar'] ),
				'one_per_event' => ! empty( $settings['one_per_event'] ),
			)
		);

		// The renderer escapes every value it prints; this is finished markup.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}
}
