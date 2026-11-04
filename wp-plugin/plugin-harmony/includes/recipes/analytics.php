<?php
/**
 * Recipes: analytics and tracking plugins.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'site-kit',
		'name'         => 'Site Kit by Google',
		'family'       => 'analytics',
		'priority'     => 45,
		'plugins'      => array( 'google-site-kit/google-site-kit.php' ),
		'detect'       => array( 'constant' => 'GOOGLESITEKIT_VERSION' ),
		'capabilities' => array( 'analytics' => 60 ),
		'advisory'     => true,
		'note'         => 'احراز هویت Site Kit با حساب گوگل انجام می‌شود و تغییر خودکار ندارد. پیشنهاد: تنها منبع آمار همین باشد و کدهای gtag دستی را حذف کنید.',
	),
	array(
		'id'           => 'monsterinsights',
		'name'         => 'MonsterInsights',
		'family'       => 'analytics',
		'priority'     => 40,
		'plugins'      => array( 'google-analytics-for-wordpress/googleanalytics.php', 'google-analytics-premium/googleanalytics-premium.php' ),
		'detect'       => array( 'constant' => 'MONSTERINSIGHTS_VERSION' ),
		'capabilities' => array( 'analytics' => 50 ),
		'advisory'     => true,
		'note'         => 'اگر Site Kit هم فعال است، دو کد رهگیری بارگذاری می‌شود و آمار دوبار شمرده می‌شود؛ یکی را غیرفعال کنید.',
	),
	array(
		'id'           => 'ga-google-analytics',
		'name'         => 'GA Google Analytics',
		'family'       => 'analytics',
		'priority'     => 20,
		'plugins'      => array( 'ga-google-analytics/ga-google-analytics.php' ),
		'detect'       => array( 'constant' => 'GA_GOOGLE_ANALYTICS_VERSION' ),
		'capabilities' => array( 'analytics' => 30 ),
		'advisory'     => true,
		'note'         => 'کد رهگیری سبک است اما در کنار افزونهٔ آمار دیگر باعث شمارش دوباره می‌شود.',
	),
	array(
		'id'           => 'matomo',
		'name'         => 'Matomo Analytics',
		'family'       => 'analytics',
		'priority'     => 35,
		'plugins'      => array( 'matomo/matomo.php' ),
		'detect'       => array( 'class' => 'WpMatomo' ),
		'capabilities' => array( 'analytics' => 45 ),
		'advisory'     => true,
		'note'         => 'Matomo داده را روی هاست خودتان نگه می‌دارد؛ اگر آن را مرجع می‌کنید، کدهای گوگل را خاموش کنید.',
	),
);
