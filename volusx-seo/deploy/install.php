<?php
/**
 * نصب‌کنندهٔ بستهٔ اصلاحات Volusx — برای اجرا داخل وردپرس.
 *
 * این فایل خودش را نصب نمی‌کند؛ فقط `volusx-seo-fixes.php` را از مخزن گیت‌هاب
 * برمی‌دارد و در `wp-content/mu-plugins/` می‌گذارد.
 *
 * روش استفاده (سه گزینه):
 *   ۱) WP-CLI:   wp eval-file install.php
 *   ۲) Novamira: این فایل را در ابزار «execute PHP» اجرا کنید.
 *   ۳) افزونهٔ Code Snippets: به‌صورت موقت به‌عنوان snippet نوع PHP اجرا کنید و بعد پاک کنید.
 *
 * ⚠️ بعد از نصب، این فایل را حذف کنید.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "این فایل باید داخل وردپرس اجرا شود.\n" );
	return;
}

const VOLUSX_SRC = 'https://raw.githubusercontent.com/volusx-crypto/prompt-master/arena/2466cef9-prompt-master/volusx-seo/volusx-seo-fixes.php';

function volusx_out( $msg ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $msg );
	} else {
		echo $msg . "\n";
	}
}

volusx_out( '--- نصب بستهٔ اصلاحات Volusx SEO ---' );

/* ۱) دانلود فایل */
$response = wp_remote_get( VOLUSX_SRC, array( 'timeout' => 20 ) );

if ( is_wp_error( $response ) ) {
	volusx_out( '❌ دانلود ناموفق: ' . $response->get_error_message() );
	volusx_out( '   اگر سرور به گیت‌هاب دسترسی ندارد، فایل را دستی آپلود کنید (روش SFTP).' );
	return;
}

$code = wp_remote_retrieve_response_code( $response );
$body = (string) wp_remote_retrieve_body( $response );

if ( 200 !== $code ) {
	volusx_out( "❌ دانلود ناموفق: HTTP {$code}" );
	return;
}

/* ۲) اعتبارسنجی محتوا — قبل از نوشتن روی دیسک */
$must_have = array( 'Plugin Name', 'Volusx_SEO_Fixes', 'filter_product_schema', 'force_https' );
foreach ( $must_have as $needle ) {
	if ( false === strpos( $body, $needle ) ) {
		volusx_out( "❌ فایل دانلودشده معتبر نیست (نشانهٔ «{$needle}» پیدا نشد). نوشتن انجام نشد." );
		return;
	}
}
if ( 0 !== strpos( ltrim( $body ), '<?php' ) ) {
	volusx_out( '❌ فایل با <?php شروع نمی‌شود. نوشتن انجام نشد.' );
	return;
}

volusx_out( '✅ دانلود و اعتبارسنجی شد (' . size_format( strlen( $body ) ) . ')' );

/* ۳) آماده‌سازی مسیر */
$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $mu_dir ) ) {
	$made = wp_mkdir_p( $mu_dir );
	volusx_out( $made ? "📁 پوشه ساخته شد: {$mu_dir}" : "❌ ساخت پوشه ممکن نشد: {$mu_dir}" );
	if ( ! $made ) {
		return;
	}
}

if ( ! is_writable( $mu_dir ) ) {
	volusx_out( "❌ پوشه قابل نوشتن نیست: {$mu_dir}" );
	volusx_out( '   دسترسی پوشه را ۷۵۵ کنید یا از روش SFTP استفاده کنید.' );
	return;
}

/* ۴) نوشتن */
$target = trailingslashit( $mu_dir ) . 'volusx-seo-fixes.php';

$backup = '';
if ( file_exists( $target ) ) {
	$backup = $target . '.bak-' . gmdate( 'Ymd-His' );
	@copy( $target, $backup );
	volusx_out( 'ℹ️ نسخهٔ قبلی بکاپ گرفته شد: ' . basename( $backup ) );
}

$written = @file_put_contents( $target, $body, LOCK_EX );

if ( false === $written ) {
	volusx_out( "❌ نوشتن فایل ممکن نشد: {$target}" );
	volusx_out( '   احتمالاً Novamira نوشتن در این مسیر را sandbox کرده یا مجوز فایل‌سیستم بسته است.' );
	volusx_out( '   روش مطمئن: آپلود دستی از طریق File Manager/SFTP.' );
	return;
}

volusx_out( '✅ نوشته شد: ' . $target . ' (' . size_format( $written ) . ')' );

/* ۵) بررسی نهایی */
volusx_out( '' );
volusx_out( '--- بررسی وضعیت ---' );
volusx_out( 'فایل موجود است: ' . ( file_exists( $target ) ? 'بله' : 'خیر' ) );
volusx_out( 'کلاس در همین درخواست لود شده؟ ' . ( class_exists( 'Volusx_SEO_Fixes' ) ? 'بله' : 'نه — طبیعی است' ) );
volusx_out( '' );
volusx_out( 'ℹ️ mu-plugin در درخواست بعدی خودکار فعال می‌شود.' );
volusx_out( '   برای تأیید، این آدرس‌ها را باز کنید:' );
volusx_out( '   - https://volusx.com/robots.txt  (باید بلوک «# BEGIN Volusx SEO» داشته باشد)' );
volusx_out( '   - یک صفحهٔ محصول (متای robots نباید noindex باشد)' );
volusx_out( '' );
volusx_out( 'برای تست کامل، اسکریپت verify.sh را از سیستم خودتان اجرا کنید.' );
volusx_out( '⚠️ این فایل نصب‌کننده را حذف کنید.' );
