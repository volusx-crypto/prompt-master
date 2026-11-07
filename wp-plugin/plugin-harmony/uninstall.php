<?php
/**
 * Uninstall routine.
 *
 * Only deletes data when the site owner explicitly asked for it.
 *
 * @package Plugin_Harmony
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$phm_settings = get_option( 'phm_settings', array() );

if ( ! is_array( $phm_settings ) || empty( $phm_settings['delete_data'] ) ) {
	return;
}

$phm_options = array(
	'phm_settings',
	'phm_change_log',
	'phm_backups',
	'phm_pending_suggestions',
	'phm_seen_plugins',
	'phm_new_plugins',
	'phm_last_scan',
);

foreach ( $phm_options as $phm_option ) {
	delete_option( $phm_option );
}

$phm_transients = array(
	'phm_site_profile',
	'phm_needs_review',
	'phm_review_summary',
	'phm_activation_scan',
);

foreach ( $phm_transients as $phm_transient ) {
	delete_transient( $phm_transient );
}

wp_clear_scheduled_hook( 'phm_daily_scan' );
wp_clear_scheduled_hook( 'phm_auto_review' );
