<?php
/**
 * Gutenberg blocks. Both render server side, so the markup never depends on
 * the version of the editor bundle that saved the post.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

use XODW\CalendarCore\Frontend\Renderer;

/**
 * Registers xodw-cc/calendar and xodw-cc/event-list.
 */
class Blocks {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register' ), 20 );
		add_filter( 'block_categories_all', array( $this, 'category' ), 10, 1 );
	}

	/**
	 * Registers both blocks from their block.json.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		foreach ( array( 'calendar', 'event-list' ) as $block ) {
			$path = XODW_CC_DIR . 'blocks/' . $block;

			if ( ! is_readable( $path . '/block.json' ) ) {
				continue;
			}

			register_block_type(
				$path,
				array(
					'render_callback' => array( $this, 'render' ),
				)
			);
		}
	}

	/**
	 * Adds a block category so both blocks are easy to find.
	 *
	 * @param array<int,array<string,mixed>> $categories Registered categories.
	 * @return array<int,array<string,mixed>>
	 */
	public function category( $categories ) {
		foreach ( $categories as $category ) {
			if ( isset( $category['slug'] ) && 'xodw-cc' === $category['slug'] ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => 'xodw-cc',
			'title' => __( 'CalendarCore', 'calendarcore' ),
			'icon'  => 'calendar-alt',
		);

		return $categories;
	}

	/**
	 * Shared render callback.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner content, unused.
	 * @param \WP_Block|null      $block      Block instance.
	 * @return string
	 */
	public function render( $attributes, $content = '', $block = null ) {
		unset( $content );

		$attributes = is_array( $attributes ) ? $attributes : array();
		$name       = ( $block && isset( $block->name ) ) ? $block->name : 'xodw-cc/calendar';

		Assets::enqueue_frontend();

		$atts = array(
			'view'          => isset( $attributes['view'] ) ? $attributes['view'] : xodw_cc_setting( 'default_view', 'month' ),
			'date'          => isset( $attributes['date'] ) ? $attributes['date'] : '',
			'venue'         => isset( $attributes['venue'] ) ? $attributes['venue'] : '',
			'organizer'     => isset( $attributes['organizer'] ) ? $attributes['organizer'] : '',
			'limit'         => isset( $attributes['limit'] ) ? (int) $attributes['limit'] : (int) xodw_cc_setting( 'events_per_page', 12 ),
			'toolbar'       => isset( $attributes['showToolbar'] ) ? (bool) $attributes['showToolbar'] : true,
			'show_past'     => ! empty( $attributes['showPast'] ),
			'one_per_event' => ! empty( $attributes['onePerEvent'] ),
			'show_images'   => isset( $attributes['showImages'] ) ? (bool) $attributes['showImages'] : true,
			'show_excerpt'  => isset( $attributes['showExcerpt'] ) ? (bool) $attributes['showExcerpt'] : true,
			'class'         => isset( $attributes['className'] ) ? $attributes['className'] : '',
		);

		if ( 'xodw-cc/event-list' === $name ) {
			$atts['view'] = 'list';
		}

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';
		$html    = Renderer::render( $atts );

		if ( '' === $wrapper ) {
			return $html;
		}

		return '<div ' . $wrapper . '>' . $html . '</div>';
	}
}
