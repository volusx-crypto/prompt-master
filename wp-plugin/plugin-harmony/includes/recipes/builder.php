<?php
/**
 * Recipes: page builders.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'elementor',
		'name'         => 'Elementor',
		'family'       => 'builder',
		'priority'     => 45,
		'plugins'      => array( 'elementor/elementor.php' ),
		'detect'       => array( 'constant' => 'ELEMENTOR_VERSION', 'class' => 'Elementor\\Plugin' ),
		'capabilities' => array( 'builder' => 60 ),
		'advisory'     => true,
		'note'         => 'با Elementor، ویژگی‌های بهینه‌سازی تصویر و کش آن را با افزونه‌های اختصاصی هماهنگ کنید: یک لایه بارگذاری تنبل، یک لایه فشرده‌سازی CSS کافی است.',
	),
	array(
		'id'           => 'wpbakery',
		'name'         => 'WPBakery Page Builder',
		'family'       => 'builder',
		'priority'     => 30,
		'plugins'      => array( 'js_composer/js_composer.php' ),
		'detect'       => array( 'constant' => 'WPB_VC_VERSION' ),
		'capabilities' => array( 'builder' => 50 ),
		'advisory'     => true,
		'note'         => 'دو صفحه‌ساز هم‌زمان، CSS و JS اضافه بارگذاری می‌کنند و کد کوتاه‌ها ممکن است درست رندر نشوند.',
	),
	array(
		'id'           => 'divi-builder',
		'name'         => 'Divi Builder',
		'family'       => 'builder',
		'priority'     => 30,
		'plugins'      => array( 'divi-builder/divi-builder.php' ),
		'detect'       => array( 'constant' => 'ET_BUILDER_VERSION' ),
		'capabilities' => array( 'builder' => 45 ),
		'advisory'     => true,
		'note'         => 'اگر قالب Divi دارید، افزونهٔ جداگانهٔ صفحه‌ساز لازم نیست.',
	),
	array(
		'id'           => 'bricks',
		'name'         => 'Bricks Builder',
		'family'       => 'builder',
		'priority'     => 30,
		'plugins'      => array( 'bricks/bricks.php' ),
		'detect'       => array( 'constant' => 'BRICKS_VERSION' ),
		'capabilities' => array( 'builder' => 55 ),
		'advisory'     => true,
		'note'         => 'Bricks معمولاً به‌عنوان قالب کار می‌کند؛ در کنار صفحه‌ساز دیگر فعال نباشد.',
	),
);
