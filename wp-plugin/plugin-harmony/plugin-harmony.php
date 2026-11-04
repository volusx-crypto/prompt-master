<?php
/**
 * Plugin Name:       هماهنگ‌ساز افزونه‌ها — Plugin Harmony
 * Plugin URI:        https://github.com/volusx-crypto/prompt-master
 * Description:       سایت را تحلیل می‌کند، افزونه‌های نصب‌شده را شناسایی می‌کند و تنظیمات آن‌ها را با یکدیگر و با نیاز واقعی سایت هماهنگ می‌سازد. با فعال‌شدن هر افزونهٔ تازه، تنظیمات آن بررسی و با سایر افزونه‌ها هم‌راستا می‌شود.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Plugin Harmony
 * Author URI:        https://github.com/volusx-crypto
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plugin-harmony
 * Domain Path:       /languages
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

define( 'PHM_VERSION', '1.0.0' );
define( 'PHM_FILE', __FILE__ );
define( 'PHM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHM_URL', plugin_dir_url( __FILE__ ) );
define( 'PHM_OPTION', 'phm_settings' );
define( 'PHM_LOG_OPTION', 'phm_change_log' );
define( 'PHM_BACKUP_OPTION', 'phm_backups' );
define( 'PHM_PENDING_OPTION', 'phm_pending_suggestions' );

$phm_includes = array(
	'includes/class-phm-settings.php',
	'includes/class-phm-registry.php',
	'includes/class-phm-profile.php',
	'includes/class-phm-detector.php',
	'includes/class-phm-values.php',
	'includes/class-phm-advisor.php',
	'includes/class-phm-planner.php',
	'includes/class-phm-applier.php',
	'includes/class-phm-admin.php',
	'includes/class-phm-cron.php',
	'includes/class-phm-cli.php',
	'includes/class-phm-plugin.php',
);

foreach ( $phm_includes as $phm_include ) {
	require_once PHM_DIR . $phm_include;
}
unset( $phm_includes, $phm_include );

/**
 * Register activation / deactivation hooks.
 */
register_activation_hook( __FILE__, array( 'PHM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PHM_Plugin', 'deactivate' ) );

/**
 * Boot the plugin.
 */
add_action( 'plugins_loaded', array( 'PHM_Plugin', 'instance' ), 5 );
