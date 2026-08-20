<?php
/**
 * Settings screen and maintenance tools.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Admin;

defined( 'ABSPATH' ) || exit;

use XODW\CalendarCore\Instances;
use XODW\CalendarCore\RecurringEngine;
use XODW\CalendarCore\Settings;

/**
 * A single settings page, grouped by module, plus two maintenance actions.
 */
class SettingsPage {

	const PAGE = 'xodw-cc-settings';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_xodw_cc_rebuild', array( $this, 'rebuild' ) );
		add_action( 'admin_post_xodw_cc_flush', array( $this, 'flush' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( XODW_CC_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the submenu entry.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=' . XODW_CC_POST_TYPE,
			__( 'CalendarCore Settings', 'calendarcore' ),
			__( 'Settings', 'calendarcore' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Adds a settings shortcut to the plugin list.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function action_links( $links ) {
		$url = admin_url( 'edit.php?post_type=' . XODW_CC_POST_TYPE . '&page=' . self::PAGE );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'calendarcore' ) . '</a>' );

		return $links;
	}

	/**
	 * Registers both options with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'xodw_cc_group',
			Settings::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize_settings' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'xodw_cc_group',
			Settings::OPTION_MODULES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize_modules' ),
				'default'           => Settings::module_defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::all();
		$modules  = Settings::modules();
		$views    = array(
			'month' => __( 'Month', 'calendarcore' ),
			'week'  => __( 'Week', 'calendarcore' ),
			'day'   => __( 'Day', 'calendarcore' ),
			'list'  => __( 'List', 'calendarcore' ),
		);
		$options  = Settings::OPTION_SETTINGS;
		$mopt     = Settings::OPTION_MODULES;
		?>
		<div class="wrap xodw-cc-admin">
			<h1><?php esc_html_e( 'CalendarCore', 'calendarcore' ); ?></h1>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'xodw_cc_group' ); ?>

				<h2><?php esc_html_e( 'Views', 'calendarcore' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Available views', 'calendarcore' ); ?></th>
						<td>
							<?php foreach ( $views as $slug => $label ) : ?>
								<label style="margin-right:1em">
									<input type="checkbox" name="<?php echo esc_attr( $options ); ?>[views_enabled][]"
										value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( in_array( $slug, (array) $settings['views_enabled'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xodw-cc-default-view"><?php esc_html_e( 'Default view', 'calendarcore' ); ?></label></th>
						<td>
							<select id="xodw-cc-default-view" name="<?php echo esc_attr( $options ); ?>[default_view]">
								<?php foreach ( $views as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['default_view'], $slug ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xodw-cc-per-page"><?php esc_html_e( 'Events per page (list view)', 'calendarcore' ); ?></label></th>
						<td><input type="number" min="1" max="100" id="xodw-cc-per-page" name="<?php echo esc_attr( $options ); ?>[events_per_page]" value="<?php echo esc_attr( (string) $settings['events_per_page'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Past events', 'calendarcore' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $options ); ?>[hide_past]" value="1" <?php checked( (bool) $settings['hide_past'] ); ?> />
								<?php esc_html_e( 'Hide past events from the event archive', 'calendarcore' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Display', 'calendarcore' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Timezones', 'calendarcore' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $options ); ?>[timezone_aware]" value="1" <?php checked( (bool) $settings['timezone_aware'] ); ?> />
								<?php esc_html_e( 'Show times in the visitor timezone', 'calendarcore' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $options ); ?>[show_timezone_note]" value="1" <?php checked( (bool) $settings['show_timezone_note'] ); ?> />
								<?php esc_html_e( 'Print a note stating which timezone is used', 'calendarcore' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xodw-cc-accent"><?php esc_html_e( 'Accent colour', 'calendarcore' ); ?></label></th>
						<td>
							<input type="color" id="xodw-cc-accent" name="<?php echo esc_attr( $options ); ?>[accent_color]" value="<?php echo esc_attr( '' !== $settings['accent_color'] ? $settings['accent_color'] : '#2563eb' ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave at the default to inherit the theme palette from theme.json.', 'calendarcore' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xodw-cc-dark"><?php esc_html_e( 'Colour scheme', 'calendarcore' ); ?></label></th>
						<td>
							<select id="xodw-cc-dark" name="<?php echo esc_attr( $options ); ?>[dark_mode]">
								<option value="auto" <?php selected( $settings['dark_mode'], 'auto' ); ?>><?php esc_html_e( 'Follow the visitor system setting', 'calendarcore' ); ?></option>
								<option value="light" <?php selected( $settings['dark_mode'], 'light' ); ?>><?php esc_html_e( 'Always light', 'calendarcore' ); ?></option>
								<option value="dark" <?php selected( $settings['dark_mode'], 'dark' ); ?>><?php esc_html_e( 'Always dark', 'calendarcore' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stylesheet', 'calendarcore' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $options ); ?>[load_css]" value="1" <?php checked( (bool) $settings['load_css'] ); ?> />
								<?php esc_html_e( 'Load the CalendarCore stylesheet (turn off to style everything in the theme)', 'calendarcore' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Recurring events', 'calendarcore' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="xodw-cc-horizon"><?php esc_html_e( 'Generate dates for', 'calendarcore' ); ?></label></th>
						<td>
							<input type="number" min="1" max="12" id="xodw-cc-horizon" name="<?php echo esc_attr( $options ); ?>[horizon_months]" value="<?php echo esc_attr( (string) $settings['horizon_months'] ); ?>" class="small-text" />
							<?php esc_html_e( 'months ahead', 'calendarcore' ); ?>
							<p class="description"><?php esc_html_e( 'A cron job keeps this window filled. Twelve months is the maximum on purpose: pre-generating years of rows is what makes other calendars slow.', 'calendarcore' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xodw-cc-max"><?php esc_html_e( 'Maximum dates per event', 'calendarcore' ); ?></label></th>
						<td><input type="number" min="10" max="2000" id="xodw-cc-max" name="<?php echo esc_attr( $options ); ?>[max_instances]" value="<?php echo esc_attr( (string) $settings['max_instances'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="xodw-cc-cache"><?php esc_html_e( 'Cache queries for', 'calendarcore' ); ?></label></th>
						<td>
							<input type="number" min="0" max="15" id="xodw-cc-cache" name="<?php echo esc_attr( $options ); ?>[cache_minutes]" value="<?php echo esc_attr( (string) $settings['cache_minutes'] ); ?>" class="small-text" />
							<?php esc_html_e( 'minutes (0 disables caching)', 'calendarcore' ); ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'RSVP', 'calendarcore' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="xodw-cc-capacity"><?php esc_html_e( 'Default capacity', 'calendarcore' ); ?></label></th>
						<td><input type="number" min="0" id="xodw-cc-capacity" name="<?php echo esc_attr( $options ); ?>[rsvp_capacity]" value="<?php echo esc_attr( (string) $settings['rsvp_capacity'] ); ?>" class="small-text" /> <?php esc_html_e( '0 = unlimited', 'calendarcore' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="xodw-cc-guests"><?php esc_html_e( 'Maximum guests per registration', 'calendarcore' ); ?></label></th>
						<td><input type="number" min="1" max="50" id="xodw-cc-guests" name="<?php echo esc_attr( $options ); ?>[rsvp_guests_max]" value="<?php echo esc_attr( (string) $settings['rsvp_guests_max'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Moderation', 'calendarcore' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $options ); ?>[rsvp_moderation]" value="1" <?php checked( (bool) $settings['rsvp_moderation'] ); ?> />
								<?php esc_html_e( 'Hold registrations for approval', 'calendarcore' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Modules', 'calendarcore' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Switch off what you do not use: disabled modules are not loaded at all.', 'calendarcore' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$module_labels = array(
						'recurring'  => __( 'Recurring events', 'calendarcore' ),
						'rsvp'       => __( 'RSVP', 'calendarcore' ),
						'ics'        => __( '.ics export and add-to-calendar links', 'calendarcore' ),
						'timezone'   => __( 'Timezone aware display', 'calendarcore' ),
						'blocks'     => __( 'Gutenberg blocks', 'calendarcore' ),
						'shortcodes' => __( 'Shortcodes', 'calendarcore' ),
					);

					if ( did_action( 'elementor/loaded' ) ) {
						$module_labels['elementor'] = __( 'Elementor widget', 'calendarcore' );
					}

					/**
					 * Filters the module toggle labels. The paid build adds its
					 * own modules here.
					 *
					 * @since 1.0.0
					 *
					 * @param array<string,string> $module_labels Slug to label.
					 */
					$module_labels = apply_filters( 'xodw_cc_pro_module_labels', $module_labels );

					foreach ( $module_labels as $slug => $label ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $mopt ); ?>[<?php echo esc_attr( $slug ); ?>]" value="0" />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $mopt ); ?>[<?php echo esc_attr( $slug ); ?>]" value="1"
										<?php checked( ! empty( $modules[ $slug ] ) ); ?> />
									<?php esc_html_e( 'Enabled', 'calendarcore' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Maintenance', 'calendarcore' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: number of occurrence rows, 2: table name. */
					esc_html__( '%1$s rows in %2$s.', 'calendarcore' ),
					'<strong>' . esc_html( number_format_i18n( Instances::count() ) ) . '</strong>',
					'<code>' . esc_html( Instances::table() ) . '</code>'
				);
				?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xodw_cc_rebuild' ), 'xodw_cc_rebuild' ) ); ?>">
					<?php esc_html_e( 'Rebuild all event dates', 'calendarcore' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=xodw_cc_flush' ), 'xodw_cc_flush' ) ); ?>">
					<?php esc_html_e( 'Clear the query cache', 'calendarcore' ); ?>
				</a>
			</p>

			<?php if ( Upsell::visible() ) : ?>
				<div class="xodw-cc-admin__upsell card">
					<h2><?php esc_html_e( 'What is coming next', 'calendarcore' ); ?></h2>
					<p><?php esc_html_e( 'Reminders, tickets, Google Calendar sync, frontend submission and per-organizer access are in development. Everything you see here stays free.', 'calendarcore' ); ?></p>
					<p>
						<a class="button button-primary" href="https://xodesignworks.com/" target="_blank" rel="noopener">
							<?php esc_html_e( 'Follow development', 'calendarcore' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Regenerates the occurrences of every event, in batches.
	 *
	 * @return void
	 */
	public function rebuild() {
		check_admin_referer( 'xodw_cc_rebuild' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'calendarcore' ), 403 );
		}

		$query = new \WP_Query(
			array(
				'post_type'              => XODW_CC_POST_TYPE,
				'post_status'            => array( 'publish', 'future', 'private', 'draft', 'pending' ),
				'posts_per_page'         => 200,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
			)
		);

		$engine = new RecurringEngine();

		foreach ( $query->posts as $event_id ) {
			$engine->generate( (int) $event_id, true );
		}

		Instances::prune_orphans();
		xodw_cc_flush_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'     => XODW_CC_POST_TYPE,
					'page'          => self::PAGE,
					'xodw_cc_done'  => count( $query->posts ),
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Clears the query cache.
	 *
	 * @return void
	 */
	public function flush() {
		check_admin_referer( 'xodw_cc_flush' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'calendarcore' ), 403 );
		}

		xodw_cc_flush_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => XODW_CC_POST_TYPE,
					'page'      => self::PAGE,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
