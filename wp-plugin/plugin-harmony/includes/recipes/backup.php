<?php
/**
 * Recipes: backup plugins.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'updraftplus',
		'name'         => 'UpdraftPlus',
		'family'       => 'backup',
		'priority'     => 45,
		'plugins'      => array( 'updraftplus/updraftplus.php' ),
		'detect'       => array( 'constant' => 'UPDRAFTPLUS_DIR', 'class' => 'UpdraftPlus' ),
		'capabilities' => array( 'backup' => 60 ),
		'note'         => 'زمان‌بندی پشتیبان‌گیری بر اساس پروفایل سایت تنظیم می‌شود: سایت فروشگاهی، دیتابیس روزانه؛ سایت محتوایی، هفتگی.',
		'rules'        => array(
			array(
				'id'       => 'ud_files_interval',
				'label'    => 'پشتیبان‌گیری هفتگی از فایل‌ها',
				'option'   => 'updraft_interval',
				'value'    => 'weekly',
				'risk'     => 'low',
			),
			array(
				'id'       => 'ud_db_interval',
				'label'    => 'پشتیبان‌گیری خودکار از دیتابیس (فروشگاه: روزانه، بقیه: هفتگی)',
				'option'   => 'updraft_interval_database',
				'value_cb' => 'PHM_Values::db_backup_interval',
				'risk'     => 'low',
			),
			array(
				'id'       => 'ud_retain',
				'label'    => 'نگهداری چند نسخهٔ پشتیبان بر اساس حجم محتوا',
				'option'   => 'updraft_retain',
				'value_cb' => 'PHM_Values::backup_retain',
				'risk'     => 'low',
			),
			array(
				'id'     => 'ud_include_uploads',
				'label'  => 'گنجاندن پوشهٔ آپلودها در نسخهٔ پشتیبان',
				'option' => 'updraft_include_uploads',
				'value'  => true,
				'risk'   => 'low',
			),
			array(
				'id'     => 'ud_include_plugins',
				'label'  => 'گنجاندن افزونه‌ها در نسخهٔ پشتیبان',
				'option' => 'updraft_include_plugins',
				'value'  => true,
				'risk'   => 'low',
			),
			array(
				'id'     => 'ud_include_themes',
				'label'  => 'گنجاندن قالب‌ها در نسخهٔ پشتیبان',
				'option' => 'updraft_include_themes',
				'value'  => true,
				'risk'   => 'low',
			),
		),
	),
	array(
		'id'           => 'backwpup',
		'name'         => 'BackWPup',
		'family'       => 'backup',
		'priority'     => 30,
		'plugins'      => array( 'backwpup/backwpup.php' ),
		'detect'       => array( 'constant' => 'BACKWPUP_VERSION' ),
		'capabilities' => array( 'backup' => 45 ),
		'advisory'     => true,
		'note'         => 'کارهای پشتیبان‌گیری BackWPup در جدول اختصاصی ذخیره می‌شود؛ زمان‌بندی را دستی تنظیم کنید.',
	),
	array(
		'id'           => 'duplicator',
		'name'         => 'Duplicator',
		'family'       => 'backup',
		'priority'     => 20,
		'plugins'      => array( 'duplicator/duplicator.php' ),
		'detect'       => array( 'constant' => 'DUPLICATOR_VERSION' ),
		'capabilities' => array( 'backup' => 30 ),
		'advisory'     => true,
		'note'         => 'Duplicator برای انتقال سایت است، نه پشتیبان‌گیری زمان‌بندی‌شده؛ آن را جایگزین سیستم پشتیبان اصلی نکنید.',
	),
	array(
		'id'           => 'jetpack-backup',
		'name'         => 'Jetpack Backup',
		'family'       => 'backup',
		'priority'     => 25,
		'plugins'      => array( 'jetpack/jetpack.php' ),
		'detect'       => array( 'class' => 'Jetpack' ),
		'capabilities' => array( 'backup' => 35 ),
		'advisory'     => true,
		'note'         => 'اگر Jetpack Backup فعال است، فقط یکی از افزونه‌های پشتیبان‌گیری را مرجع نگه دارید تا منابع هاست دوبار مصرف نشود.',
	),
);
