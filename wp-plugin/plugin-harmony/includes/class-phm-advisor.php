<?php
/**
 * Advisor: heuristic recommendations for plugins without a recipe.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Advisor
 */
class PHM_Advisor {

	/**
	 * Keyword map used to guess the family of an unknown plugin.
	 *
	 * @return array family => keywords
	 */
	public static function keywords() {
		return array(
			'seo'          => array( 'seo', 'rank', 'yoast', 'sitemap', 'schema', 'meta tag', 'serp', 'all in one seo', 'seopress' ),
			'cache'        => array( 'cache', 'rocket', 'minify', 'speed', 'optimize', 'autoptimize', 'litespeed', 'boost', 'turbo' ),
			'performance'  => array( 'image', 'compress', 'webp', 'smush', 'imagify', 'shortpixel', 'ewww', 'media' ),
			'security'     => array( 'security', 'firewall', 'malware', 'login', '2fa', 'two factor', 'captcha', 'waf', 'wordfence', 'sucuri', 'bulletproof' ),
			'forms'        => array( 'form', 'contact', 'survey', 'quiz', 'poll', 'newsletter' ),
			'ecommerce'    => array( 'woocommerce', 'shop', 'cart', 'checkout', 'store', 'payment', 'digits' ),
			'backup'       => array( 'backup', 'restore', 'migrate', 'updraft', 'duplicator', 'snapshot' ),
			'analytics'    => array( 'analytics', 'gtag', 'tracking', 'pixel', 'statistic', 'insights' ),
			'redirects'    => array( 'redirect', '404', 'link', 'slug', 'rewrite' ),
			'mail'         => array( 'smtp', 'mail', 'email', 'postmark', 'sendgrid' ),
			'comments'     => array( 'comment', 'spam', 'akismet', 'antispam', 'recaptcha' ),
			'multilingual' => array( 'translate', 'multilingual', 'language', 'polylang', 'wpml', 'weglot', 'localiz' ),
			'maintenance'  => array( 'maintenance', 'coming soon', 'under construction', 'construction' ),
			'builder'      => array( 'elementor', 'page builder', 'wpbakery', 'divi', 'beaver', 'bricks', 'oxygen' ),
			'membership'   => array( 'member', 'subscription', 'restrict', 'learndash', 'lifter', 'course' ),
		);
	}

	/**
	 * Persian advice per family.
	 *
	 * @param string $family Family slug.
	 * @return string
	 */
	public static function family_advice( $family ) {
		$advice = array(
			'seo'          => 'در هر سایت فقط یک افزونهٔ سئو فعال بگذارید. نقشهٔ سایت را فقط یکی از آن‌ها بسازد، متا تگ‌ها را یکی تولید کند و بقیه خاموش باشند تا محتوای تکراری و تضاد در تگ‌ها ایجاد نشود.',
			'cache'        => 'فقط از یک لایهٔ کش صفحه استفاده کنید. اگر هاست کش سمت سرور دارد (LiteSpeed/Nginx) تنظیمات آن را مرجع بگیرید و افزونهٔ کش وردپرسی را در حالت سازگار قرار دهید. صفحات سبد خرید، تسویه و حساب کاربری باید همیشه از کش مستثنی باشند.',
			'performance'  => 'فقط یک افزونهٔ بهینه‌سازی تصویر فعال باشد؛ دو افزونهٔ هم‌زمان، فایل‌های تکراری و فضای بی‌مصرف می‌سازند. اگر هاست از WebP پشتیبانی می‌کند تبدیل خودکار را روشن کنید.',
			'security'     => 'فایروال، محدودسازی تلاش ورود و ورود دومرحله‌ای را فقط از یک افزونهٔ امنیتی اعمال کنید. دو فایروال هم‌زمان می‌توانند مدیر را قفل کنند و درخواست‌های سالم را بلاک کنند.',
			'forms'        => 'برای فرم تماس یک افزونهٔ اصلی انتخاب کنید. فرم‌های ساخته‌شده در صفحه‌ساز یا افزونهٔ دیگر را غیرفعال نکنید، اما ضداسپم (کپچا/Akismet) را فقط در یک لایه فعال کنید تا ارسال‌ها دوبار بررسی نشوند.',
			'ecommerce'    => 'با فعال‌بودن فروشگاه، صفحات سبد خرید/تسویه/حساب کاربری باید از کش و از ایندکس موتور جست‌وجو خارج شوند، پشتیبان‌گیری روزانه از دیتابیس انجام شود و تست پرداخت پیش از انتشار گرفته شود.',
			'backup'       => 'یک سیستم پشتیبان‌گیری اصلی کافی است. زمان‌بندی روزانه برای دیتابیس و هفتگی برای فایل‌ها، نگهداری حداقل ۴ نسخه، و ذخیرهٔ نسخه‌ها بیرون از هاست (S3/Drive) توصیه می‌شود.',
			'analytics'    => 'از دو کد رهگیری هم‌زمان استفاده نکنید؛ بازدیدها دوبار شمرده می‌شوند. یک ابزار مرجع انتخاب کنید، IP کاربران را ناشناس کنید و بازدید مدیر را از آمار حذف کنید.',
			'redirects'    => 'ثبت ۴۰۴ و تغییر مسیر را فقط به یک افزونه بسپارید، وگرنه حلقهٔ ریدایرکت یا ریدایرکت دوبل ایجاد می‌شود. نقشهٔ ریدایرکت‌ها را پیش از تغییر ساختار لینک‌ها آماده کنید.',
			'mail'         => 'یک افزونهٔ SMTP کافی است. پورت و رمز را از هاست‌تان بگیرید و پس از هر تغییر یک ایمیل آزمایشی بفرستید؛ تنظیمات ایمیل به هاست وابسته است و نباید خودکار حدس زده شود.',
			'comments'     => 'یک لایهٔ ضداسپم فعال کنید (Akismet یا کپچا). دیدگاه‌ها را پیش از انتشار به‌صورت دستی تأیید کنید و ثبت‌نام برای دیدگاه را فقط در صورت نیاز باز بگذارید.',
			'multilingual' => 'یک افزونهٔ چندزبانه کافی است. پیش از فعال‌سازی دومی، زبان پیش‌فرض و ساختار URL را تثبیت کنید؛ تغییر بعدی آن‌ها لینک‌های ایندکس‌شده را می‌شکند.',
			'maintenance'  => 'فقط یک افزونهٔ حالت تعمیر/به‌زودی فعال باشد. اگر دو افزونه هم‌زمان پردهٔ تعمیر نشان دهند، ممکن است صفحهٔ خطا یا حلقهٔ ریدایرکت ببینید.',
			'builder'      => 'یک صفحه‌ساز اصلی انتخاب کنید و بقیه را غیرفعال یا فقط برای محتوای قدیمی نگه دارید. بارگذاری دو صفحه‌ساز هم‌زمان، CSS/JS اضافه و کندی محسوس ایجاد می‌کند.',
			'membership'   => 'با فعال‌بودن عضویت، صفحات محتوای قفل‌شده را از کش و ایندکس خارج کنید و مطمئن شوید افزونهٔ کش تنظیمات کاربران وارد‌شده را نادیده نمی‌گیرد.',
			'core'         => 'تنظیمات پایهٔ وردپرس را با نیاز واقعی سایت هم‌راستا کنید: پیوندهای یکتا، مدیریت دیدگاه، پینگ‌بک و پوشه‌بندی آپلودها.',
		);

		return isset( $advice[ $family ] ) ? $advice[ $family ] : 'این افزونه در فهرست دانش افزونهٔ هماهنگ‌ساز نیست. تنظیمات آن را دستی بررسی کنید و در صورت هم‌پوشانی با افزونهٔ دیگری، یکی را مرجع قرار دهید.';
	}

	/**
	 * Guess a family from a plugin name / file path.
	 *
	 * @param string $haystack Text to inspect.
	 * @return string Family slug or empty string.
	 */
	public static function guess_family( $haystack ) {
		$haystack = strtolower( $haystack );

		$best      = '';
		$best_hits = 0;

		foreach ( self::keywords() as $family => $keywords ) {
			$hits = 0;
			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $haystack, $keyword ) ) {
					$hits++;
				}
			}
			if ( $hits > $best_hits ) {
				$best      = $family;
				$best_hits = $hits;
			}
		}

		return $best;
	}

	/**
	 * Active plugins that have no recipe.
	 *
	 * @return array plugin basenames
	 */
	public static function unknown_plugins() {
		$active = PHM_Detector::active_plugin_files();
		$known  = array();

		foreach ( PHM_Registry::all() as $recipe ) {
			foreach ( $recipe['plugins'] as $file ) {
				$known[] = $file;
			}
		}

		$unknown = array();
		foreach ( $active as $file ) {
			if ( in_array( $file, $known, true ) ) {
				continue;
			}
			// Our own plugin is never interesting.
			if ( false !== strpos( $file, 'plugin-harmony' ) ) {
				continue;
			}
			$unknown[] = $file;
		}

		return $unknown;
	}

	/**
	 * Build advice for every unknown active plugin.
	 *
	 * @param array $state Detection state.
	 * @return array
	 */
	public static function advise( $state ) {
		$out = array();

		foreach ( self::unknown_plugins() as $file ) {
			$name   = PHM_Detector::plugin_name( $file );
			$family = self::guess_family( $name . ' ' . $file );

			$related = array();
			if ( '' !== $family && isset( $state ) ) {
				foreach ( $state as $info ) {
					if ( ! empty( $info['active'] ) && $info['family'] === $family ) {
						$related[] = $info['name'];
					}
				}
			}

			$out[] = array(
				'id'      => $file,
				'name'    => $name,
				'file'    => $file,
				'family'  => $family,
				'reason'  => '' !== $family
					? 'این افزونه در فهرست دانش نیست، اما بر اساس نامش در خانوادهٔ «' . PHM_Detector::family_label( $family ) . '» دسته‌بندی شد.'
					: 'این افزونه در فهرست دانش هماهنگ‌ساز نیست و خانوادهٔ آن تشخیص داده نشد.',
				'advice'  => self::family_advice( $family ),
				'related' => $related,
				'known'   => false,
			);
		}

		return $out;
	}
}
