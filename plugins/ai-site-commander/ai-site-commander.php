<?php
/**
 * Plugin Name: فرمانده هوشمند سایت (AI Site Commander)
 * Plugin URI: https://github.com/volusx-crypto/prompt-master
 * Description: پل ارتباطی امن و پنل مدیریت جامع برای اتصال مستقیم هوش مصنوعی به وردپرس و ووکامرس؛ ویرایش مستقیم محتوا، سئو (Rank Math / Yoast)، ریدایرکت‌ها، کدهای سفارشی، فایل‌های قالب و تنظیمات همراه با سیستم پشتیبان‌گیری خودکار و بازگشت یک‌کلیکی (Rollback).
 * Version: 1.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: AI Site Suite
 * Text Domain: ai-site-commander
 * Domain Path: /languages
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ASC_VERSION', '1.3.0' );
define( 'ASC_PLUGIN_FILE', __FILE__ );
define( 'ASC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once ASC_PLUGIN_DIR . 'includes/class-asc-db.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-security.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-snapshot.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-content-manager.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-seo-manager.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-redirect-404.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-code-files.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-plugin-inspector.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-cloud-bridge.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-engine.php';
require_once ASC_PLUGIN_DIR . 'includes/class-asc-rest-api.php';

if ( is_admin() ) {
	require_once ASC_PLUGIN_DIR . 'admin/class-asc-admin.php';
}

/**
 * Main AI Site Commander singleton bootstrap.
 */
final class AI_Site_Commander {

	/**
	 * Instance.
	 *
	 * @var AI_Site_Commander|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return AI_Site_Commander
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
		register_activation_hook( ASC_PLUGIN_FILE, array( 'ASC_DB', 'activate' ) );
		register_deactivation_hook( ASC_PLUGIN_FILE, array( 'ASC_DB', 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init_components' ) );
	}

	/**
	 * Initialize runtime hooks and services.
	 */
	public function init_components() {
		ASC_DB::maybe_upgrade();
		ASC_SEO_Manager::init();
		ASC_Redirect_404::init();
		ASC_Code_Files::init();
		ASC_Plugin_Inspector::init();
		ASC_Cloud_Bridge::init();
		ASC_REST_API::init();

		if ( is_admin() && class_exists( 'ASC_Admin' ) ) {
			ASC_Admin::init();
		}

		/**
		 * Fires once AI Site Commander is fully loaded and ready to accept internal/external actions.
		 */
		do_action( 'ai_site_commander_loaded', $this );
	}

	/**
	 * Public PHP bridge for sibling plugins (such as AI GSC Autopilot) to execute actions directly.
	 *
	 * @param string $action_type Action identifier.
	 * @param array  $params      Action parameters.
	 * @param array  $context     Actor/reason/bypass_queue metadata.
	 * @return array|WP_Error
	 */
	public static function execute( $action_type, array $params = array(), array $context = array() ) {
		return ASC_Engine::dispatch( $action_type, $params, $context );
	}
}

/**
 * Global helper accessor for AI Site Commander.
 *
 * @return AI_Site_Commander
 */
function ai_site_commander() {
	return AI_Site_Commander::instance();
}

ai_site_commander();
