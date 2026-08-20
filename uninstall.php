<?php
/**
 * Removes every trace of CalendarCore on uninstall.
 *
 * Order matters: content first, then the table, then the options. Deleting
 * posts can re-create bookkeeping options through the plugin's own hooks, so
 * options go last.
 *
 * @package XODW\CalendarCore
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Events, their meta and their RSVP records.
$xodw_cc_ids = get_posts(
	array(
		'post_type'   => 'xodw_cc_event',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);

foreach ( $xodw_cc_ids as $xodw_cc_id ) {
	$xodw_cc_rsvps = get_comments(
		array(
			'post_id' => $xodw_cc_id,
			'type'    => 'xodw_cc_rsvp',
			'status'  => 'all',
			'fields'  => 'ids',
		)
	);

	foreach ( $xodw_cc_rsvps as $xodw_cc_rsvp ) {
		wp_delete_comment( (int) $xodw_cc_rsvp, true );
	}

	wp_delete_post( (int) $xodw_cc_id, true );
}

// Venue and organizer terms.
foreach ( array( 'xodw_cc_venue', 'xodw_cc_organizer' ) as $xodw_cc_tax ) {
	$xodw_cc_terms = get_terms(
		array(
			'taxonomy'   => $xodw_cc_tax,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	if ( is_array( $xodw_cc_terms ) ) {
		foreach ( $xodw_cc_terms as $xodw_cc_term ) {
			wp_delete_term( (int) $xodw_cc_term, $xodw_cc_tax );
		}
	}
}

// Scheduled work.
wp_clear_scheduled_hook( 'xodw_cc_extend_horizon' );
wp_clear_scheduled_hook( 'xodw_cc_cleanup_instances' );

// The occurrence table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'xodw_cc_event_instances' );

// Cached queries and rate limit counters.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
$xodw_cc_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_xodw_cc_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_xodw_cc_' ) . '%'
	)
);

foreach ( (array) $xodw_cc_transients as $xodw_cc_transient ) {
	delete_option( $xodw_cc_transient );
}

// User preferences.
delete_metadata( 'user', 0, 'xodw_cc_upsell_dismissed', '', true );

// Options last: the deletions above can touch the cache counter.
$xodw_cc_options = array(
	'xodw_cc_settings',
	'xodw_cc_modules_enabled',
	'xodw_cc_db_version',
	'xodw_cc_cache_version',
	'xodw_cc_flush_rewrite',
	'xodw_cc_horizon_cursor',
	'xodw_cc_license',
);

foreach ( $xodw_cc_options as $xodw_cc_option ) {
	delete_option( $xodw_cc_option );
	delete_site_option( $xodw_cc_option );
}

// Paid data: orders, promo codes, license, Google tokens and the organizer role.
$xodw_cc_orders = get_posts(
	array(
		'post_type'   => 'xodw_cc_order',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);

foreach ( $xodw_cc_orders as $xodw_cc_order ) {
	wp_delete_post( (int) $xodw_cc_order, true );
}

foreach ( array( 'xodw_cc_pro_settings', 'xodw_cc_pro_promos', 'xodw_cc_license', 'xodw_cc_pro_google' ) as $xodw_cc_pro_option ) {
	delete_option( $xodw_cc_pro_option );
	delete_site_option( $xodw_cc_pro_option );
}

delete_metadata( 'user', 0, '_xodw_cc_organizer_term', '', true );

if ( get_role( 'xodw_cc_organizer' ) ) {
	remove_role( 'xodw_cc_organizer' );
}

foreach ( array( 'xodw_cc_license_check', 'xodw_cc_pro_reminders', 'xodw_cc_pro_google_sync', 'xodw_cc_pro_expire_orders' ) as $xodw_cc_pro_hook ) {
	wp_clear_scheduled_hook( $xodw_cc_pro_hook );
}
