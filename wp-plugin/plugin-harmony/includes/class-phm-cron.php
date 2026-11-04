<?php
/**
 * Scheduled maintenance.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Cron
 */
class PHM_Cron {

	/**
	 * Hook names.
	 */
	const DAILY_HOOK = 'phm_daily_scan';
	const AUTO_HOOK  = 'phm_auto_review';

	/**
	 * Register cron hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( self::DAILY_HOOK, array( __CLASS__, 'run_scan' ) );
		add_action( self::AUTO_HOOK, array( __CLASS__, 'run_auto_review' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/**
	 * Make sure the daily event exists.
	 *
	 * @return void
	 */
	public static function ensure_schedule() {
		if ( ! PHM_Settings::get( 'daily_scan' ) ) {
			if ( wp_next_scheduled( self::DAILY_HOOK ) || wp_next_scheduled( self::AUTO_HOOK ) ) {
				self::unschedule();
			}
			return;
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Schedule the daily scan now (activation).
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Remove scheduled events.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_clear_scheduled_hook( self::AUTO_HOOK );
	}

	/**
	 * Schedule a one-off review.
	 *
	 * @param string $hook     Hook name.
	 * @param int    $delay    Delay in seconds.
	 * @return void
	 */
	public static function schedule_single( $hook, $delay = 60 ) {
		if ( wp_next_scheduled( $hook ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 10, (int) $delay ), $hook );
	}

	/**
	 * Daily scan: detect newly activated plugins, drift, and repair settings.
	 *
	 * @return void
	 */
	public static function run_scan() {
		PHM_Profile::flush();
		PHM_Detector::flush();
		PHM_Registry::flush();

		$active = PHM_Detector::active_plugin_files();
		$seen   = get_option( 'phm_seen_plugins', array() );
		$seen   = is_array( $seen ) ? $seen : array();

		$new = array_values( array_diff( $active, $seen ) );
		update_option( 'phm_seen_plugins', $active, false );

		$plan    = PHM_Planner::build();
		$pending = PHM_Plugin::pending_changes( $plan );

		$applied = 0;
		$error   = '';

		if ( $pending && PHM_Settings::can_write() ) {
			$result = PHM_Applier::apply(
				$pending,
				array(
					'trigger' => 'cron',
					'auto'    => true,
				)
			);
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$applied = count( $result['items'] );
			}
		}

		update_option(
			'phm_last_scan',
			array(
				'time'      => time(),
				'new'       => $new,
				'pending'   => count( $pending ),
				'applied'   => $applied,
				'conflicts' => count( $plan['conflicts'] ),
				'error'     => $error,
			),
			false
		);
	}

	/**
	 * Deferred review after plugin updates.
	 *
	 * @return void
	 */
	public static function run_auto_review() {
		PHM_Profile::flush();
		PHM_Detector::flush();

		$plan    = PHM_Planner::build();
		$pending = PHM_Plugin::pending_changes( $plan );

		if ( $pending && PHM_Settings::can_write() && PHM_Settings::get( 'auto_on_update' ) ) {
			PHM_Applier::apply(
				$pending,
				array(
					'trigger' => 'update',
					'auto'    => true,
				)
			);
		}
	}
}
