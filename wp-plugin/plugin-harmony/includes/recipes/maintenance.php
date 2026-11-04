<?php
/**
 * Recipes: maintenance / coming soon plugins.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'wp-maintenance-mode',
		'name'         => 'WP Maintenance Mode',
		'family'       => 'maintenance',
		'priority'     => 40,
		'plugins'      => array( 'wp-maintenance-mode/wp-maintenance-mode.php' ),
		'detect'       => array( 'constant' => 'WPM_VERSION' ),
		'capabilities' => array( 'maintenance' => 60 ),
		'advisory'     => true,
		'note'         => 'حالت تعمیر را فقط از یک افزونه فعال کنید. اگر افزونهٔ کش صفحه فعال است، صفحهٔ تعمیر را از کش مستثنی کنید.',
	),
	array(
		'id'           => 'coming-soon',
		'name'         => 'Coming Soon Page (SeedProd)',
		'family'       => 'maintenance',
		'priority'     => 35,
		'plugins'      => array( 'coming-soon/coming-soon.php' ),
		'detect'       => array( 'constant' => 'SEEDPROD_VERSION' ),
		'capabilities' => array( 'maintenance' => 50 ),
		'advisory'     => true,
		'note'         => 'پردهٔ «به‌زودی» هم‌زمان با افزونهٔ تعمیر دیگر می‌تواند باعث حلقهٔ ریدایرکت شود؛ یکی را غیرفعال کنید.',
	),
	array(
		'id'           => 'under-construction',
		'name'         => 'Under Construction',
		'family'       => 'maintenance',
		'priority'     => 25,
		'plugins'      => array( 'under-construction-maintenance-mode/under-construction.php' ),
		'detect'       => array( 'constant' => 'UCM_VERSION' ),
		'capabilities' => array( 'maintenance' => 40 ),
		'advisory'     => true,
		'note'         => 'پس از انتشار سایت، حالت تعمیر را خاموش و کش را پاک کنید تا نسخهٔ واقعی برای بازدیدکنندگان نمایش داده شود.',
	),
);
