<?php
/**
 * Recipes: SEO plugins.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'yoast',
		'name'         => 'Yoast SEO',
		'family'       => 'seo',
		'priority'     => 40,
		'plugins'      => array( 'wordpress-seo/wp-seo.php', 'wordpress-seo/wp-seo-premium.php' ),
		'detect'       => array( 'class' => 'WPSEO_Options', 'constant' => 'WPSEO_VERSION', 'option' => 'wpseo' ),
		'capabilities' => array( 'sitemap' => 50, 'meta' => 60, 'schema' => 55 ),
		'note'         => 'گزینه‌های wpseo و wpseo_titles با کلیدهای شناخته‌شده خوانده و بازنویسی می‌شوند؛ اگر نسخهٔ نصب‌شده کلیدی را نداشته باشد، قاعده خودکار رد می‌شود.',
		'rules'        => array(
			array(
				'id'         => 'yoast_sitemap_on',
				'label'      => 'روشن‌کردن نقشهٔ سایت یوآست (این افزونه مرجع نقشهٔ سایت است)',
				'option'     => 'wpseo',
				'path'       => array( 'enable_xml_sitemap' ),
				'value'      => true,
				'risk'       => 'low',
				'conditions' => array( 'capability_primary' => 'sitemap' ),
			),
			array(
				'id'         => 'yoast_sitemap_off',
				'label'      => 'خاموش‌کردن نقشهٔ سایت یوآست چون افزونهٔ دیگری مرجع است',
				'option'     => 'wpseo',
				'path'       => array( 'enable_xml_sitemap' ),
				'value'      => false,
				'risk'       => 'low',
				'conditions' => array( 'capability_secondary' => 'sitemap' ),
			),
			array(
				'id'     => 'yoast_admin_bar',
				'label'  => 'نمایش منوی یوآست در نوار مدیریت',
				'option' => 'wpseo',
				'path'   => array( 'enable_admin_bar_menu' ),
				'value'  => true,
				'risk'   => 'low',
			),
			array(
				'id'     => 'yoast_disable_attachment',
				'label'  => 'غیرفعال‌کردن صفحهٔ مستقل پیوست‌ها (جلوگیری از محتوای تکراری)',
				'option' => 'wpseo_titles',
				'path'   => array( 'disable-attachment' ),
				'value'  => true,
				'risk'   => 'low',
			),
			array(
				'id'     => 'yoast_noindex_attachment',
				'label'  => 'noindex کردن صفحات پیوست',
				'option' => 'wpseo_titles',
				'path'   => array( 'noindex-attachment' ),
				'value'  => true,
				'risk'   => 'low',
			),
			array(
				'id'     => 'yoast_breadcrumbs',
				'label'  => 'فعال‌کردن مسیر راهنما (breadcrumb) برای استفاده در قالب',
				'option' => 'wpseo_titles',
				'path'   => array( 'breadcrumbs-enable' ),
				'value'  => true,
				'risk'   => 'medium',
			),
		),
	),
	array(
		'id'           => 'rankmath',
		'name'         => 'Rank Math',
		'family'       => 'seo',
		'priority'     => 35,
		'plugins'      => array( 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' ),
		'detect'       => array( 'class' => 'RankMath', 'constant' => 'RANK_MATH_VERSION' ),
		'capabilities' => array( 'sitemap' => 45, 'meta' => 55, 'schema' => 50 ),
		'advisory'     => true,
		'note'         => 'تنظیمات Rank Math در ساختار سریالایز پیچیده و نسخه‌وابسته ذخیره می‌شود؛ این افزونه تغییر خودکار نمی‌نویسد و فقط پیشنهاد می‌دهد.',
	),
	array(
		'id'           => 'aioseo',
		'name'         => 'All in One SEO',
		'family'       => 'seo',
		'priority'     => 30,
		'plugins'      => array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ),
		'detect'       => array( 'class' => 'AIOSEO\\Plugin\\AIOSEO', 'constant' => 'AIOSEO_VERSION' ),
		'capabilities' => array( 'sitemap' => 40, 'meta' => 50, 'schema' => 45 ),
		'advisory'     => true,
		'note'         => 'گزینه‌های AIOSEO به‌صورت JSON ذخیره می‌شوند؛ برای جلوگیری از خرابی داده، فقط پیشنهاد دستی ارائه می‌شود.',
	),
	array(
		'id'           => 'seopress',
		'name'         => 'SEOPress',
		'family'       => 'seo',
		'priority'     => 25,
		'plugins'      => array( 'wp-seopress/seopress.php', 'wp-seopress-pro/seopress-pro.php' ),
		'detect'       => array( 'constant' => 'SEOPRESS_VERSION' ),
		'capabilities' => array( 'sitemap' => 40, 'meta' => 45, 'schema' => 40 ),
		'advisory'     => true,
		'note'         => 'ساختار تنظیمات SEOPress در نسخه‌های مختلف تغییر می‌کند؛ فقط توصیهٔ دستی ارائه می‌شود.',
	),
);
