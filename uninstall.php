<?php
/**
 * Al desinstalar el plugin se eliminan sus tablas y opciones.
 *
 * @package WP_Events_Calendar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'events', 'locations', 'shows', 'shortcodes' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpec_{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'wpec_db_version' );
