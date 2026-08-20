<?php
/**
 * Activation, database schema and upgrade routines.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the single narrow table the plugin needs and keeps it in shape.
 */
class Install {

	/**
	 * Registers the upgrade check.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_table();

		add_option( Settings::OPTION_SETTINGS, Settings::defaults(), '', true );
		add_option( Settings::OPTION_MODULES, Settings::module_defaults(), '', true );
		add_option( 'xodw_cc_cache_version', 1, '', true );
		update_option( 'xodw_cc_db_version', XODW_CC_DB_VERSION, true );

		Cron::schedule();

		// The CPT is not registered yet during activation, so ask WP to flush later.
		update_option( 'xodw_cc_flush_rewrite', 1, false );
	}

	/**
	 * Runs on plugin deactivation. Data is kept; only schedules are removed.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cron::unschedule();
		flush_rewrite_rules();
	}

	/**
	 * Applies pending schema or option upgrades.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'xodw_cc_db_version' ) !== XODW_CC_DB_VERSION ) {
			self::create_table();
			update_option( 'xodw_cc_db_version', XODW_CC_DB_VERSION, true );
		}

		if ( get_option( 'xodw_cc_flush_rewrite' ) ) {
			delete_option( 'xodw_cc_flush_rewrite' );
			flush_rewrite_rules( false );
		}

		Cron::schedule();
	}

	/**
	 * Creates or updates wp_xodw_cc_event_instances.
	 *
	 * Deliberately narrow: an occurrence is nothing but a pointer to a post
	 * plus a start and an end. Everything else lives in postmeta.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Instances::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			start_datetime datetime NOT NULL,
			end_datetime datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_start (event_id,start_datetime),
			KEY start_datetime (start_datetime),
			KEY end_datetime (end_datetime)
		) {$collate};";

		dbDelta( $sql );
	}
}
