<?php
/**
 * Recipes: media / image optimisation plugins.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'smush',
		'name'         => 'Smush',
		'family'       => 'performance',
		'priority'     => 40,
		'plugins'      => array( 'wp-smushit/wp-smush.php', 'wp-smush-pro/wp-smush.php' ),
		'detect'       => array( 'class' => 'Smush\\Core\\Core', 'constant' => 'WP_SMUSH_VERSION' ),
		'capabilities' => array( 'image_opt' => 60, 'webp' => 40, 'lazyload' => 25 ),
		'note'         => 'فقط کلیدهای موجود در گزینهٔ wp-smush-settings تغییر می‌کنند؛ اگر Smush کلیدی را نساخته باشد، همان قاعده رد می‌شود.',
		'rules'        => array(
			array(
				'id'         => 'smush_auto',
				'label'      => 'بهینه‌سازی خودکار تصاویر هنگام آپلود (Smush مرجع بهینه‌سازی است)',
				'option'     => 'wp-smush-settings',
				'path'       => array( 'auto' ),
				'value'      => true,
				'risk'       => 'low',
				'conditions' => array( 'capability_primary' => 'image_opt' ),
			),
			array(
				'id'         => 'smush_lossy',
				'label'      => 'سطح فشرده‌سازی: Lossy (بیشترین صرفه‌جویی حجم)',
				'option'     => 'wp-smush-settings',
				'path'       => array( 'lossy' ),
				'value'      => 1,
				'risk'       => 'medium',
				'conditions' => array( 'capability_primary' => 'image_opt' ),
			),
			array(
				'id'         => 'smush_webp',
				'label'      => 'تبدیل خودکار به WebP در صورت پشتیبانی هاست',
				'option'     => 'wp-smush-settings',
				'path'       => array( 'webp' ),
				'value'      => true,
				'risk'       => 'medium',
				'conditions' => array(
					'capability_primary' => 'webp',
					'not_profile'        => array( 'nginx' ),
				),
			),
		),
	),
	array(
		'id'           => 'ewww',
		'name'         => 'EWWW Image Optimizer',
		'family'       => 'performance',
		'priority'     => 35,
		'plugins'      => array( 'ewww-image-optimizer/ewww-image-optimizer.php' ),
		'detect'       => array( 'constant' => 'EWWW_IMAGE_OPTIMIZER_VERSION' ),
		'capabilities' => array( 'image_opt' => 50, 'webp' => 50 ),
		'advisory'     => true,
		'note'         => 'تنظیمات EWWW در گزینه‌های متعدد و وابسته به سرور ذخیره می‌شود؛ فقط توصیهٔ دستی ارائه می‌شود.',
	),
	array(
		'id'           => 'shortpixel',
		'name'         => 'ShortPixel Image Optimizer',
		'family'       => 'performance',
		'priority'     => 30,
		'plugins'      => array( 'wp-shortpixel-req/wp-shortpixel.php', 'shortpixel-image-optimiser/wp-shortpixel.php' ),
		'detect'       => array( 'constant' => 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ),
		'capabilities' => array( 'image_opt' => 45, 'webp' => 45 ),
		'advisory'     => true,
		'note'         => 'کلید API و تنظیمات ShortPixel حساس‌اند؛ تغییر خودکار انجام نمی‌شود.',
	),
	array(
		'id'           => 'imagify',
		'name'         => 'Imagify',
		'family'       => 'performance',
		'priority'     => 25,
		'plugins'      => array( 'imagify/imagify.php' ),
		'detect'       => array( 'constant' => 'IMAGIFY_VERSION' ),
		'capabilities' => array( 'image_opt' => 40, 'webp' => 40 ),
		'advisory'     => true,
		'note'         => 'تنظیمات Imagify به کلید API گره خورده است؛ فقط پیشنهاد دستی ارائه می‌شود.',
	),
	array(
		'id'           => 'perfmatters',
		'name'         => 'Perfmatters',
		'family'       => 'cache',
		'priority'     => 20,
		'plugins'      => array( 'perfmatters/perfmatters.php' ),
		'detect'       => array( 'constant' => 'PERFMATTERS_VERSION' ),
		'capabilities' => array( 'lazyload' => 35 ),
		'advisory'     => true,
		'note'         => 'Perfmatters گزینه‌های زیادی دارد که باید دستی و مطابق قالب تنظیم شود.',
	),
);
