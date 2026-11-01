<?php
/**
 * Uninstall cleanup for AI Site Commander (only drops data if user explicitly enabled full wipe).
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'asc_settings', array() );
if ( ! empty( $settings['delete_data_on_uninstall'] ) ) {
	global $wpdb;
	$slugs = array( 'api_keys', 'snapshots', 'queue', 'redirects', '404_logs', 'snippets', 'audit_logs' );
	foreach ( $slugs as $slug ) {
		$table = $wpdb->prefix . 'asc_' . $slug;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	delete_option( 'asc_settings' );
	delete_option( 'asc_db_version' );
}
