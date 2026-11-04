<?php
/**
 * Recipes: multilingual plugins.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'polylang',
		'name'         => 'Polylang',
		'family'       => 'multilingual',
		'priority'     => 40,
		'plugins'      => array( 'polylang/polylang.php', 'polylang-pro/polylang.php' ),
		'detect'       => array( 'constant' => 'POLYLANG_VERSION', 'class' => 'Polylang' ),
		'capabilities' => array( 'multilingual' => 60 ),
		'advisory'     => true,
		'note'         => 'زبان پیش‌فرض و ساختار URL را فقط یک بار تعیین کنید؛ تغییر بعدی آن‌ها لینک‌های ایندکس‌شده را می‌شکند. افزونهٔ کش باید زبان را در کلید کش لحاظ کند.',
	),
	array(
		'id'           => 'wpml',
		'name'         => 'WPML',
		'family'       => 'multilingual',
		'priority'     => 45,
		'plugins'      => array( 'sitepress-multilingual-cms/sitepress.php' ),
		'detect'       => array( 'constant' => 'ICL_SITEPRESS_VERSION' ),
		'capabilities' => array( 'multilingual' => 65 ),
		'advisory'     => true,
		'note'         => 'WPML جدول‌های اختصاصی دارد؛ با Polylang هم‌زمان فعال نشود.',
	),
	array(
		'id'           => 'translatepress',
		'name'         => 'TranslatePress',
		'family'       => 'multilingual',
		'priority'     => 30,
		'plugins'      => array( 'translatepress-multilingual/index.php' ),
		'detect'       => array( 'constant' => 'TRP_PLUGIN_VERSION' ),
		'capabilities' => array( 'multilingual' => 40 ),
		'advisory'     => true,
		'note'         => 'TranslatePress ترجمه را در همان URL ذخیره می‌کند؛ تنظیم کش و سئو باید با همین مدل هماهنگ شود.',
	),
	array(
		'id'           => 'weglot',
		'name'         => 'Weglot',
		'family'       => 'multilingual',
		'priority'     => 30,
		'plugins'      => array( 'weglot/weglot.php' ),
		'detect'       => array( 'constant' => 'WEGLOT_VERSION' ),
		'capabilities' => array( 'multilingual' => 45 ),
		'advisory'     => true,
		'note'         => 'Weglot ترجمه را سمت سرویس ابری انجام می‌دهد؛ دو افزونهٔ چندزبانه هم‌زمان باعث دوبار ترجمه و هزینهٔ اضافه می‌شود.',
	),
);
