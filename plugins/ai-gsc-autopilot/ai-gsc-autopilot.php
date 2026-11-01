<?php
/**
 * Plugin Name: اتوپایلوت هوشمند سرچ کنسول (AI Search Console Autopilot)
 * Plugin URI: https://github.com/volusx-crypto/prompt-master
 * Description: اتصال مستقیم به Google Search Console، دریافت خودکار خطاهای ایندکس، افت ورودی، مشکلات CTR، کنیبالیزیشن و اسکیما، تحلیل ریشه‌ای با هوش مصنوعی و اجرای مستقیم اصلاحات روی سایت از طریق پل «فرمانده هوشمند سایت».
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: AI Site Suite
 * Text Domain: ai-gsc-autopilot
 * Domain Path: /languages
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGSC_VERSION', '1.2.0' );
define( 'AGSC_PLUGIN_FILE', __FILE__ );
define( 'AGSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AGSC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once AGSC_PLUGIN_DIR . 'includes/class-agsc-db.php';
require_once AGSC_PLUGIN_DIR . 'includes/class-agsc-google-client.php';
require_once AGSC_PLUGIN_DIR . 'includes/class-agsc-issue-detector.php';
require_once AGSC_PLUGIN_DIR . 'includes/class-agsc-ai-analyzer.php';
require_once AGSC_PLUGIN_DIR . 'includes/class-agsc-remediator.php';
require_once AGSC_PLUGIN_DIR . 'includes/class-agsc-rest-api.php';

if ( is_admin() ) {
	require_once AGSC_PLUGIN_DIR . 'admin/class-agsc-admin.php';
}

/**
 * Main AI Search Console Autopilot singleton bootstrap.
 */
final class AI_GSC_Autopilot {

	/**
	 * Singleton instance.
	 *
	 * @var AI_GSC_Autopilot|null
	 */
	private static $instance = null;

	/**
	 * Retrieve singleton instance.
	 *
	 * @return AI_GSC_Autopilot
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		register_activation_hook( AGSC_PLUGIN_FILE, array( 'AGSC_DB', 'activate' ) );
		register_deactivation_hook( AGSC_PLUGIN_FILE, array( 'AGSC_DB', 'deactivate' ) );

		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
		add_action( 'plugins_loaded', array( $this, 'init_components' ), 15 );
		add_action( 'agsc_scheduled_sync_event', array( $this, 'run_scheduled_autopilot' ) );
	}

	/**
	 * Add 6-hour interval for Search Console background synchronization.
	 *
	 * @param array $schedules Existing WP-Cron schedules.
	 * @return array
	 */
	public function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['every_six_hours'] ) ) {
			$schedules['every_six_hours'] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => 'هر ۶ ساعت (اتوپایلوت سرچ کنسول)',
			);
		}
		return $schedules;
	}

	/**
	 * Initialize runtime services.
	 */
	public function init_components() {
		AGSC_DB::maybe_upgrade();
		AGSC_REST_API::init();

		if ( is_admin() && class_exists( 'AGSC_Admin' ) ) {
			AGSC_Admin::init();
		}
	}

	/**
	 * Scheduled background sync (and auto-remediation if auto_pilot_enabled is active).
	 */
	public function run_scheduled_autopilot() {
		$settings = AGSC_DB::get_settings();
		if ( ! AGSC_Google_Client::is_configured() ) {
			return;
		}
		AGSC_Issue_Detector::sync_and_detect();

		if ( ! empty( $settings['autopilot_auto_fix'] ) ) {
			AGSC_Remediator::run_autopilot_batch( 5 );
		}
	}
}

/**
 * Global helper accessor.
 *
 * @return AI_GSC_Autopilot
 */
function ai_gsc_autopilot() {
	return AI_GSC_Autopilot::instance();
}

ai_gsc_autopilot();
