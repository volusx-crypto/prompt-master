<?php
/**
 * Recipes: redirects and 404 logging.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'redirection',
		'name'         => 'Redirection',
		'family'       => 'redirects',
		'priority'     => 40,
		'plugins'      => array( 'redirection/redirection.php' ),
		'detect'       => array( 'constant' => 'REDIRECTION_VERSION', 'class' => 'Redirection\\Plugin' ),
		'capabilities' => array( 'redirect' => 60, 'log_404' => 60 ),
		'advisory'     => true,
		'note'         => 'ثبت ۴۰۴ را فقط از یک افزونه فعال کنید؛ دو افزونهٔ هم‌زمان، جدول‌های لاگ را پر و مسیرها را دوباره‌نویسی می‌کنند.',
	),
	array(
		'id'           => 'safe-redirect-manager',
		'name'         => 'Safe Redirect Manager',
		'family'       => 'redirects',
		'priority'     => 25,
		'plugins'      => array( 'safe-redirect-manager/safe-redirect-manager.php' ),
		'detect'       => array( 'class' => 'SRM_Redirect' ),
		'capabilities' => array( 'redirect' => 35 ),
		'advisory'     => true,
		'note'         => 'اگر Redirection فعال است، این افزونه را فقط برای ریدایرکت‌های دستی محدود نگه دارید.',
	),
	array(
		'id'           => 'yoast-premium-redirects',
		'name'         => 'Yoast SEO Premium (Redirects)',
		'family'       => 'redirects',
		'priority'     => 30,
		'plugins'      => array( 'wordpress-seo-premium/wp-seo-premium.php' ),
		'detect'       => array( 'class' => 'WPSEO_Premium' ),
		'capabilities' => array( 'redirect' => 40, 'log_404' => 40 ),
		'advisory'     => true,
		'note'         => 'ماژول ریدایرکت یوآست پریمیوم با افزونهٔ ریدایرکت مستقل تضاد دارد؛ یکی را انتخاب کنید.',
	),
);
