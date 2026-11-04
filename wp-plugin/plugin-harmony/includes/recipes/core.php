<?php
/**
 * Recipe: WordPress core settings.
 *
 * These options always exist, so they are the safest place to start: the
 * results are deterministic and reversible.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'core',
		'name'         => 'تنظیمات پایهٔ وردپرس',
		'family'       => 'core',
		'priority'     => 100,
		'plugins'      => array(),
		'capabilities' => array(),
		'note'         => 'این قواعد روی گزینه‌های خود وردپرس کار می‌کنند و پیش‌نیاز درست کار کردن افزونه‌های سئو و کش هستند.',
		'rules'        => array(
			array(
				'id'     => 'core_require_name_email',
				'label'  => 'الزام نام و ایمیل در فرم دیدگاه',
				'option' => 'require_name_email',
				'value'  => '1',
				'risk'   => 'low',
			),
			array(
				'id'     => 'core_comment_moderation',
				'label'  => 'نگه‌داشتن دیدگاه‌ها برای تأیید دستی',
				'option' => 'comment_moderation',
				'value'  => '1',
				'risk'   => 'medium',
			),
			array(
				'id'     => 'core_thread_comments',
				'label'  => 'فعال‌کردن پاسخ‌های تودرتو در دیدگاه‌ها',
				'option' => 'thread_comments',
				'value'  => '1',
				'risk'   => 'low',
			),
			array(
				'id'     => 'core_thread_depth',
				'label'  => 'عمق پاسخ‌های تودرتو روی ۳ سطح',
				'option' => 'thread_comments_depth',
				'value'  => '3',
				'risk'   => 'low',
			),
			array(
				'id'     => 'core_close_old_comments',
				'label'  => 'بستن دیدگاه نوشته‌های قدیمی',
				'option' => 'close_comments_for_old_posts',
				'value'  => '1',
				'risk'   => 'low',
			),
			array(
				'id'     => 'core_close_after_days',
				'label'  => 'بستن دیدگاه پس از ۶۰ روز',
				'option' => 'close_comments_days_old',
				'value'  => '60',
				'risk'   => 'low',
			),
			array(
				'id'     => 'core_ping_status',
				'label'  => 'خاموش‌کردن پینگ‌بک ورودی (ضداسپم)',
				'option' => 'default_ping_status',
				'value'  => 'closed',
				'risk'   => 'low',
			),
			array(
				'id'     => 'core_pingback_flag',
				'label'  => 'خاموش‌کردن اعلان پینگ‌بک خروجی',
				'option' => 'default_pingback_flag',
				'value'  => '0',
				'risk'   => 'low',
			),
			array(
				'id'     => 'core_uploads_folders',
				'label'  => 'تفکیک آپلودها بر اساس سال و ماه',
				'option' => 'uploads_use_yearmonth_folders',
				'value'  => '1',
				'risk'   => 'low',
			),
			array(
				'id'         => 'core_close_comments_no_blog',
				'label'      => 'بستن دیدگاه پیش‌فرض در سایتی که بخش وبلاگ ندارد',
				'option'     => 'default_comment_status',
				'value'      => 'closed',
				'risk'       => 'medium',
				'conditions' => array(
					'not_profile' => array( 'has_blog' ),
				),
			),
		),
	),
);
