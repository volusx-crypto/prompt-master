<?php
/**
 * Recipes: security plugins.
 *
 * Security settings are deliberately report-only: a wrong firewall or 2FA
 * value can lock the site owner out, so Plugin Harmony never writes here.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'wordfence',
		'name'         => 'Wordfence Security',
		'family'       => 'security',
		'priority'     => 45,
		'plugins'      => array( 'wordfence/wordfence.php' ),
		'detect'       => array( 'constant' => 'WORDFENCE_VERSION', 'class' => 'wordfence' ),
		'capabilities' => array( 'firewall' => 55, 'malware' => 55, 'login_limit' => 40, 'two_factor' => 30 ),
		'advisory'     => true,
		'note'         => 'تنظیمات Wordfence در جدول اختصاصی و ساختار سریالایز پیچیده است. این افزونه فقط وضعیت را گزارش می‌کند تا خطای امنیتی ایجاد نشود.',
	),
	array(
		'id'           => 'solid-security',
		'name'         => 'Solid Security (Better WP Security)',
		'family'       => 'security',
		'priority'     => 40,
		'plugins'      => array( 'better-wp-security/better-wp-security.php' ),
		'detect'       => array( 'constant' => 'ITSEC_VERSION' ),
		'capabilities' => array( 'firewall' => 45, 'malware' => 35, 'two_factor' => 50, 'login_limit' => 45 ),
		'advisory'     => true,
		'note'         => 'تغییر خودکار تنظیمات امنیتی می‌تواند دسترسی مدیر را قطع کند؛ بنابراین فقط پیشنهاد دستی ارائه می‌شود.',
	),
	array(
		'id'           => 'aiowps',
		'name'         => 'All In One WP Security',
		'family'       => 'security',
		'priority'     => 30,
		'plugins'      => array( 'all-in-one-wp-security-and-firewall/wp-security.php' ),
		'detect'       => array( 'constant' => 'AIOS_VERSION' ),
		'capabilities' => array( 'firewall' => 35, 'login_limit' => 35 ),
		'advisory'     => true,
		'note'         => 'پیشنهاد: در کنار فایروال دیگری فعال نباشد؛ قواعد .htaccess دو افزونهٔ امنیتی می‌تواند سایت را قفل کند.',
	),
	array(
		'id'           => 'sucuri',
		'name'         => 'Sucuri Security',
		'family'       => 'security',
		'priority'     => 25,
		'plugins'      => array( 'sucuri-scanner/sucuri.php' ),
		'detect'       => array( 'constant' => 'SUCURISCAN_VERSION' ),
		'capabilities' => array( 'firewall' => 25, 'malware' => 50 ),
		'advisory'     => true,
		'note'         => 'Sucuri بیشتر پایش بیرونی است؛ اگر فایروال سایت جای دیگری فعال است، این افزونه را فقط برای پایش نگه دارید.',
	),
	array(
		'id'           => 'limit-login',
		'name'         => 'Limit Login Attempts Reloaded',
		'family'       => 'security',
		'priority'     => 55,
		'plugins'      => array( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' ),
		'detect'       => array( 'constant' => 'LLLL_VERSION' ),
		'capabilities' => array( 'login_limit' => 60 ),
		'advisory'     => true,
		'note'         => 'این افزونه مرجع محدودسازی تلاش ورود است؛ در افزونه‌های امنیتی دیگر این قابلیت را خاموش کنید.',
	),
	array(
		'id'           => 'wp2fa',
		'name'         => 'WP 2FA',
		'family'       => 'security',
		'priority'     => 55,
		'plugins'      => array( 'wp-2fa/wp-2fa.php' ),
		'detect'       => array( 'constant' => 'WP_2FA_VERSION' ),
		'capabilities' => array( 'two_factor' => 60 ),
		'advisory'     => true,
		'note'         => 'ورود دومرحله‌ای را فقط از یک افزونه اعمال کنید تا کاربران قفل نشوند.',
	),
);
