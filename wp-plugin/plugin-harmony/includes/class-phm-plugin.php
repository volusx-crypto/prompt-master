<?php
/**
 * Core plugin bootstrap and WordPress hooks.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Plugin
 */
class PHM_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var PHM_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and boot) the plugin.
	 *
	 * @return PHM_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		load_plugin_textdomain( 'plugin-harmony', false, dirname( plugin_basename( PHM_FILE ) ) . '/languages' );

		PHM_Admin::hooks();
		PHM_Cron::hooks();

		add_action( 'activated_plugin', array( $this, 'on_activated' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_review' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PHM_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Activation routine.
	 *
	 * @return void
	 */
	public static function activate() {
		$existing = get_option( PHM_OPTION, null );
		if ( null === $existing ) {
			update_option( PHM_OPTION, PHM_Settings::defaults() );
		}

		PHM_Cron::schedule();
		PHM_Profile::flush();
		PHM_Detector::flush();

		set_transient( 'phm_needs_review', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate() {
		PHM_Cron::unschedule();
		delete_transient( 'phm_needs_review' );
		delete_transient( 'phm_review_summary' );
	}

	/**
	 * A plugin was just activated: queue a review of the whole site.
	 *
	 * The plan is deliberately built on the next admin request, because
	 * during `activated_plugin` the new plugin has not created its options yet.
	 *
	 * @param string $plugin_file  Plugin basename.
	 * @param bool   $network_wide Network activation flag.
	 * @return void
	 */
	public function on_activated( $plugin_file, $network_wide ) {
		unset( $network_wide );

		if ( false !== strpos( (string) $plugin_file, 'plugin-harmony' ) ) {
			return;
		}

		$new = get_option( 'phm_new_plugins', array() );
		if ( ! is_array( $new ) ) {
			$new = array();
		}
		$new[ $plugin_file ] = time();
		update_option( 'phm_new_plugins', $new, false );

		PHM_Profile::flush();
		PHM_Detector::flush();

		set_transient( 'phm_needs_review', 1, HOUR_IN_SECONDS );
	}

	/**
	 * A plugin was installed or updated.
	 *
	 * @param object $upgrader Upgrader instance.
	 * @param array  $options  Upgrade context.
	 * @return void
	 */
	public function on_upgrader( $upgrader, $options ) {
		unset( $upgrader );

		if ( ! is_array( $options ) || empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}

		PHM_Profile::flush();
		PHM_Detector::flush();

		$action = isset( $options['action'] ) ? $options['action'] : '';

		if ( 'update' === $action && PHM_Settings::get( 'auto_on_update' ) ) {
			PHM_Cron::schedule_single( 'phm_auto_review', 60 );
		}

		set_transient( 'phm_needs_review', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Build a plan after an activation and, when allowed, apply it.
	 *
	 * Runs on `admin_init` so it only costs time for logged in admins.
	 *
	 * @return void
	 */
	public function maybe_review() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_transient( 'phm_needs_review' ) ) {
			return;
		}

		delete_transient( 'phm_needs_review' );

		$new_plugins = get_option( 'phm_new_plugins', array() );
		$new_plugins = is_array( $new_plugins ) ? $new_plugins : array();

		$names = array();
		foreach ( array_keys( $new_plugins ) as $file ) {
			$names[] = PHM_Detector::plugin_name( $file );
		}

		$plan    = PHM_Planner::build();
		$pending = self::pending_changes( $plan );

		$result = null;
		if ( ! empty( $pending ) && PHM_Settings::can_write() && PHM_Settings::get( 'auto_on_activation' ) ) {
			$result = PHM_Applier::apply(
				$pending,
				array(
					'trigger' => 'activation',
					'auto'    => true,
				)
			);
		}

		set_transient(
			'phm_review_summary',
			array(
				'at'         => time(),
				'new'        => $names,
				'pending'    => count( $pending ),
				'conflicts'  => count( $plan['conflicts'] ),
				'advisories' => count( $plan['advisories'] ),
				'applied'    => is_array( $result ) ? count( $result['items'] ) : 0,
				'error'      => is_wp_error( $result ) ? $result->get_error_message() : '',
				'auto'       => PHM_Settings::can_write() && (bool) PHM_Settings::get( 'auto_on_activation' ),
			),
			10 * MINUTE_IN_SECONDS
		);

		update_option( 'phm_new_plugins', array(), false );
	}

	/**
	 * Keep only the changes that would actually write something.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	public static function pending_changes( $plan ) {
		$out = array();
		if ( empty( $plan['changes'] ) ) {
			return $out;
		}
		foreach ( $plan['changes'] as $change ) {
			if ( isset( $change['status'] ) && 'pending' === $change['status'] ) {
				$out[] = $change;
			}
		}
		return $out;
	}

	/**
	 * Show the review summary notice.
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$summary = get_transient( 'phm_review_summary' );

		if ( ! is_array( $summary ) ) {
			return;
		}

		$screen = get_current_screen();
		$on_page = ( $screen && false !== strpos( (string) $screen->id, 'plugin-harmony' ) );

		if ( $on_page ) {
			return;
		}

		$names = ! empty( $summary['new'] ) ? implode( '، ', array_map( 'esc_html', $summary['new'] ) ) : 'افزونهٔ جدید';
		$url   = admin_url( 'admin.php?page=plugin-harmony' );

		echo '<div class="notice notice-info is-dismissible phm-notice">';
		echo '<p><strong>هماهنگ‌ساز افزونه‌ها:</strong> ';

		if ( ! empty( $summary['applied'] ) ) {
			echo esc_html( sprintf( 'پس از فعال‌شدن %s، تعداد %d تنظیم به‌صورت خودکار هماهنگ شد.', $names, (int) $summary['applied'] ) );
		} elseif ( ! empty( $summary['pending'] ) ) {
			echo esc_html( sprintf( '%s فعال شد. %d پیشنهاد تنظیمات آماده است و %d تضاد بین افزونه‌ها پیدا شد.', $names, (int) $summary['pending'], (int) $summary['conflicts'] ) );
		} else {
			echo esc_html( sprintf( '%s بررسی شد؛ تنظیمات سایت با سایر افزونه‌ها هماهنگ است.', $names ) );
		}

		if ( ! empty( $summary['error'] ) ) {
			echo ' <em>' . esc_html( $summary['error'] ) . '</em>';
		}

		echo ' <a href="' . esc_url( $url ) . '">مشاهده و اعمال</a></p>';
		echo '</div>';
	}

	/**
	 * Plugin row action link.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=plugin-harmony' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">هماهنگ‌سازی</a>' );
		return $links;
	}
}
