<?php
/**
 * Recipes: anti-spam and comment tooling.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'id'           => 'akismet',
		'name'         => 'Akismet Anti-spam',
		'family'       => 'comments',
		'priority'     => 50,
		'plugins'      => array( 'akismet/akismet.php' ),
		'detect'       => array( 'constant' => 'AKISMET_VERSION', 'class' => 'Akismet' ),
		'capabilities' => array( 'spam' => 60, 'comments' => 40 ),
		'advisory'     => true,
		'note'         => 'کلید API آکیسمت باید دستی وارد شود. تا زمان واردشدن کلید، این افزونه عملاً محافظت نمی‌کند و افزونهٔ ضداسپم دیگری لازم است.',
	),
	array(
		'id'           => 'antispam-bee',
		'name'         => 'Antispam Bee',
		'family'       => 'comments',
		'priority'     => 45,
		'plugins'      => array( 'antispam-bee/antispam.php' ),
		'detect'       => array( 'constant' => 'ANTISPAM_BEE_VERSION' ),
		'capabilities' => array( 'spam' => 50 ),
		'advisory'     => true,
		'note'         => 'اگر Akismet هم فعال و پیکربندی‌شده است، یکی را مرجع کنید؛ دو لایهٔ ضداسپم، دیدگاه‌های سالم را هم اسپم می‌کند.',
	),
	array(
		'id'           => 'wp-discuz',
		'name'         => 'wpDiscuz',
		'family'       => 'comments',
		'priority'     => 30,
		'plugins'      => array( 'wpdiscuz/wpdiscuz.php' ),
		'detect'       => array( 'constant' => 'WPDISCUZ_VERSION' ),
		'capabilities' => array( 'comments' => 50 ),
		'advisory'     => true,
		'note'         => 'سیستم دیدگاه سفارشی، تنظیمات دیدگاه وردپرس را بازنویسی می‌کند؛ تنظیمات تکمیلی را در همان افزونه بررسی کنید.',
	),
);
