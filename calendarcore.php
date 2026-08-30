<?php
/**
 * Plugin Name:       CalendarCore — Event Calendar, Recurring Events & RSVP
 * Description:       Recurring events, RSVP, .ics import and export, timezone-aware times. Free, fast, no jQuery, no bloat.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            XoDesignWorks
 * Author URI:        https://xodesignworks.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       calendarcore
 *
 * @package XODW\CalendarCore
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'XODW_CC_VERSION' ) ) {
	return;
}

define( 'XODW_CC_VERSION', '1.0.0' );
define( 'XODW_CC_DB_VERSION', '1' );
define( 'XODW_CC_FILE', __FILE__ );
define( 'XODW_CC_DIR', plugin_dir_path( __FILE__ ) );
define( 'XODW_CC_URL', plugin_dir_url( __FILE__ ) );
define( 'XODW_CC_POST_TYPE', 'xodw_cc_event' );
define( 'XODW_CC_TAX_VENUE', 'xodw_cc_venue' );
define( 'XODW_CC_TAX_ORGANIZER', 'xodw_cc_organizer' );

/**
 * PSR-4 style autoloader scoped to the plugin namespace.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
function xodw_cc_autoload( $class ) {
	$prefix = 'XODW\\CalendarCore\\';

	if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$path     = XODW_CC_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
}
spl_autoload_register( 'xodw_cc_autoload' );

require_once XODW_CC_DIR . 'src/functions.php';


register_activation_hook( __FILE__, array( 'XODW\CalendarCore\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'XODW\CalendarCore\Install', 'deactivate' ) );

XODW\CalendarCore\Plugin::instance()->boot();
