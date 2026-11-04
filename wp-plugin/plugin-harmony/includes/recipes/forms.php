<?php
/**
 * Recipes: form plugins.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'cf7',
		'name'         => 'Contact Form 7',
		'family'       => 'forms',
		'priority'     => 30,
		'plugins'      => array( 'contact-form-7/wp-contact-form-7.php' ),
		'detect'       => array( 'constant' => 'WPCF7_VERSION' ),
		'capabilities' => array( 'forms' => 45, 'spam' => 30 ),
		'advisory'     => true,
		'note'         => 'فرم‌ها و تنظیمات CF7 در پست‌تایپ اختصاصی ذخیره می‌شوند. پیشنهاد: برای فرم‌های حساس از کپچا استفاده کنید و افزونهٔ ضداسپم دوم را فعال نکنید.',
	),
	array(
		'id'           => 'wpforms',
		'name'         => 'WPForms',
		'family'       => 'forms',
		'priority'     => 35,
		'plugins'      => array( 'wpforms-lite/wpforms.php', 'wpforms/wpforms.php' ),
		'detect'       => array( 'constant' => 'WPFORMS_VERSION' ),
		'capabilities' => array( 'forms' => 50, 'spam' => 35 ),
		'advisory'     => true,
		'note'         => 'تنظیمات WPForms در wpforms_settings ذخیره می‌شود اما کلیدها بین نسخه‌ها جابه‌جا می‌شوند؛ فقط توصیهٔ دستی ارائه می‌شود.',
	),
	array(
		'id'           => 'gravityforms',
		'name'         => 'Gravity Forms',
		'family'       => 'forms',
		'priority'     => 40,
		'plugins'      => array( 'gravityforms/gravityforms.php' ),
		'detect'       => array( 'class' => 'GFForms' ),
		'capabilities' => array( 'forms' => 55, 'spam' => 40 ),
		'advisory'     => true,
		'note'         => 'Gravity Forms جدول اختصاصی دارد؛ پیشنهاد: کپچا و ضداسپم فقط از یک لایه فعال باشد.',
	),
	array(
		'id'           => 'fluentform',
		'name'         => 'Fluent Forms',
		'family'       => 'forms',
		'priority'     => 30,
		'plugins'      => array( 'fluentform/fluentform.php' ),
		'detect'       => array( 'constant' => 'FLUENTFORM' ),
		'capabilities' => array( 'forms' => 45, 'spam' => 30 ),
		'advisory'     => true,
		'note'         => 'برای فرم تماس یک افزونهٔ اصلی انتخاب کنید و فرم‌های موازی نسازید.',
	),
	array(
		'id'           => 'forminator',
		'name'         => 'Forminator',
		'family'       => 'forms',
		'priority'     => 28,
		'plugins'      => array( 'forminator/forminator.php' ),
		'detect'       => array( 'constant' => 'FORMINATOR_VERSION' ),
		'capabilities' => array( 'forms' => 40, 'spam' => 28 ),
		'advisory'     => true,
		'note'         => 'پیشنهاد: کپچای Forminator را فقط زمانی فعال کنید که افزونهٔ ضداسپم دیگری روی همان فرم نیست.',
	),
);
