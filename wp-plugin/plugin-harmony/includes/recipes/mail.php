<?php
/**
 * Recipes: SMTP / email delivery plugins.
 *
 * Mail credentials are host specific, so nothing is written automatically.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'wp-mail-smtp',
		'name'         => 'WP Mail SMTP',
		'family'       => 'mail',
		'priority'     => 45,
		'plugins'      => array( 'wp-mail-smtp/wp_mail_smtp.php', 'wp-mail-smtp-pro/wp_mail_smtp.php' ),
		'detect'       => array( 'constant' => 'WPMS_PLUGIN_VER' ),
		'capabilities' => array( 'smtp' => 60 ),
		'advisory'     => true,
		'note'         => 'پورت، رمز و رمزنگاری SMTP به هاست وابسته است و هرگز خودکار نوشته نمی‌شود. پس از هر تغییر، ایمیل آزمایشی بفرستید.',
	),
	array(
		'id'           => 'post-smtp',
		'name'         => 'Post SMTP',
		'family'       => 'mail',
		'priority'     => 40,
		'plugins'      => array( 'post-smtp/postman-smtp.php' ),
		'detect'       => array( 'constant' => 'POST_SMTP_VER' ),
		'capabilities' => array( 'smtp' => 50 ),
		'advisory'     => true,
		'note'         => 'فقط یک افزونهٔ SMTP فعال باشد؛ دو افزونه روی هوک wp_mail هم‌زمان کار می‌کنند و ارسال را ناپایدار می‌کنند.',
	),
	array(
		'id'           => 'fluent-smtp',
		'name'         => 'FluentSMTP',
		'family'       => 'mail',
		'priority'     => 40,
		'plugins'      => array( 'fluent-smtp/fluent-smtp.php' ),
		'detect'       => array( 'constant' => 'FLUENTMAIL_PLUGIN_VERSION' ),
		'capabilities' => array( 'smtp' => 50 ),
		'advisory'     => true,
		'note'         => 'کلیدهای API سرویس ایمیل حساس‌اند؛ این افزونه فقط پیشنهاد می‌دهد که یک لایهٔ ارسال کافی است.',
	),
);
