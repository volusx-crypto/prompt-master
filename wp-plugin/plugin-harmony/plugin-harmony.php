<?php
/**
 * Plugin Name:       هماهنگ‌ساز افزونه‌ها — Plugin Harmony
 * Plugin URI:        https://github.com/volusx-crypto/prompt-master
 * Description:       سایت را تحلیل می‌کند، افزونه‌های نصب‌شده را شناسایی می‌کند و تنظیمات آن‌ها را با یکدیگر و با نیاز واقعی سایت هماهنگ می‌سازد. با فعال‌شدن هر افزونهٔ تازه، تنظیمات آن بررسی و با سایر افزونه‌ها هم‌راستا می‌شود.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Tested up to:      6.8
 * Requires PHP:      7.4
 * Author:            Plugin Harmony
 * Author URI:        https://github.com/volusx-crypto
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plugin-harmony
 * Domain Path:       /languages
 * Network:           true
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

define( 'PHM_VERSION', '1.1.0' );
define( 'PHM_FILE', __FILE__ );
define( 'PHM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHM_URL', plugin_dir_url( __FILE__ ) );
define( 'PHM_OPTION', 'phm_settings' );
define( 'PHM_LOG_OPTION', 'phm_change_log' );
define( 'PHM_BACKUP_OPTION', 'phm_backups' );
define( 'PHM_PENDING_OPTION', 'phm_pending_suggestions' );

/**
 * Refuse to load on unsupported PHP instead of throwing a fatal error.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	if ( ! function_exists( 'phm_php_version_notice' ) ) {
		/**
		 * Admin notice for old PHP versions.
		 *
		 * @return void
		 */
		function phm_php_version_notice() {
			echo '<div class="notice notice-error"><p>';
			echo esc_html( 'هماهنگ‌ساز افزونه‌ها به PHP 7.4 یا بالاتر نیاز دارد. نسخهٔ فعلی: ' . PHP_VERSION );
			echo '</p></div>';
		}
	}
	add_action( 'admin_notices', 'phm_php_version_notice' );
	return;
}

/**
 * Class autoloader.
 *
 * Only the classes a request actually needs are loaded, so a normal front-end
 * page never parses the planner, the admin UI or the recipe library.
 *
 * @param string $class Class name.
 * @return void
 */
function phm_autoload( $class ) {
	if ( 0 !== strpos( $class, 'PHM_' ) ) {
		return;
	}

	$slug = strtolower( str_replace( '_', '-', substr( $class, 4 ) ) );
	$file = PHM_DIR . 'includes/class-phm-' . $slug . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'phm_autoload' );

// WP-CLI commands are registered only in a CLI context.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	PHM_CLI::register();
}

register_activation_hook( __FILE__, array( 'PHM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PHM_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'PHM_Plugin', 'instance' ), 5 );
