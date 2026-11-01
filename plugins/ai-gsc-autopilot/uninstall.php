<?php
/**
 * Uninstall cleanup for AI Search Console Autopilot.
 *
 * @package AI_GSC_Autopilot
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'agsc_settings', array() );
if ( ! empty( $settings['delete_data_on_uninstall'] ) ) {
	global $wpdb;
	foreach ( array( 'issues', 'analytics', 'sync_logs' ) as $slug ) {
		$table = $wpdb->prefix . 'agsc_' . $slug;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	delete_option( 'agsc_settings' );
	delete_option( 'agsc_db_version' );
	delete_option( 'agsc_sec_service_account_json' );
	delete_option( 'agsc_sec_oauth_client_secret' );
	delete_option( 'agsc_sec_oauth_refresh_token' );
	delete_option( 'agsc_sec_ai_api_key' );
	delete_transient( 'agsc_google_access_token' );
}
