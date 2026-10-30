<?php
/**
 * Plugin Name:       Volusx SEO Fixes
 * Description:       رفع مشکلات گزارش‌شده در Google Search Console برای volusx.com (Page indexing، Product snippets، Core Web Vitals). به‌صورت mu-plugin کار می‌کند.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Arena Agent
 * License:           GPL-2.0-or-later
 * Text Domain:       volusx-seo
 *
 * نصب سریع:
 *   1) فایل را در wp-content/mu-plugins/volusx-seo-fixes.php قرار دهید (اگر پوشه mu-plugins نیست، بسازید).
 *   2) کار تمام است؛ mu-plugin خودکار فعال می‌شود و در لیست افزونه‌ها دیده نمی‌شود.
 *
 * خاموش‌کردن اضطراری (بدون حذف فایل): این خط را در wp-config.php اضافه کنید:
 *   define( 'VOLUSX_SEO_DISABLE', true );
 *
 * نکته: هیچ‌کدام از این تغییرات داده‌ای را حذف نمی‌کند و همه با filter/hook استاندارد وردپرس اعمال می‌شوند،
 * یعنی با غیرفعال‌کردن فایل، سایت دقیقاً به حالت قبل برمی‌گردد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'VOLUSX_SEO_DISABLE' ) && VOLUSX_SEO_DISABLE ) {
	return;
}

if ( ! class_exists( 'Volusx_SEO_Fixes' ) ) :

final class Volusx_SEO_Fixes {

	const VERSION = '1.0.0';

	/** پارامترهای ردیابی که با 301 حذف می‌شوند (کاملاً بی‌خطر برای فروشگاه). */
	private static $tracking_params = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_id',
		'fbclid',
		'gclid',
		'msclkid',
		'yclid',
		'ttclid',
	);

	/** پارامترهای فیلتر/مرتب‌سازی فروشگاه که باعث صفحات تکراری می‌شوند. */
	private static $facet_params = array(
		'orderby',
		'per_page',
		'min_price',
		'max_price',
		'rating_filter',
		'product_view',
		'product-page',
	);

	/** آیا تصویر LCP این درخواست علامت‌گذاری شده است؟ */
	private static $lcp_marked = false;

	public static function init() {
		// ۱) اصلاح robots.txt (رفع «Blocked by robots.txt»).
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 20, 2 );

		// ۲) اصلاح index/noindex (رفع «Excluded by noindex» و بستن صفحات کم‌ارزش).
		add_filter( 'wp_robots', array( __CLASS__, 'filter_wp_robots' ), 99 );

		// ۳) رفع «Duplicate, Google chose different canonical» برای URLهای فیلترشده.
		add_action( 'template_redirect', array( __CLASS__, 'normalize_duplicate_urls' ), 1 );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'filter_canonical' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'filter_canonical' ), 20 );

		// ۴) رفع «Not found (404)» با نقشه ریدایرکت + لاگ ۴۰۴های جدید.
		add_action( 'template_redirect', array( __CLASS__, 'handle_404' ), 2 );

		// ۷) اجبار HTTPS (آدرس‌های http:// در گزارش‌ها دیده شد).
		add_action( 'template_redirect', array( __CLASS__, 'force_https' ), 0 );

		// ۵) رفع «Product snippets: offers / review / aggregateRating» + فیلدهای Merchant listings.
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'filter_product_schema' ), 20, 2 );

		// ۶) بهبود LCP موبایل (۶۶ آدرس با LCP > 4s).
		add_action( 'wp_head', array( __CLASS__, 'preload_lcp_image' ), 1 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'lcp_image_attributes' ), 20, 3 );
	}

	/* ---------------------------------------------------------------------
	 * ۱) robots.txt
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $output محتوای robots.txt تولیدشده توسط وردپرس.
	 * @param bool   $public آیا سایت برای موتور جستجو باز است.
	 * @return string
	 */
	public static function filter_robots_txt( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}
		if ( false !== strpos( (string) $output, '# BEGIN Volusx SEO' ) ) {
			return $output;
		}

		$extra  = "\n# BEGIN Volusx SEO\n";
		// منابعی که باید حتماً قابل خزش باشند (CSS/JS/تصاویر) — نبودشان باعث رندر ناقص می‌شود.
		$extra .= "Allow: /wp-content/uploads/\n";
		$extra .= "Allow: /wp-admin/admin-ajax.php\n";
		$extra .= "Allow: /.well-known/\n";
		// نسخه انگلیسی باید قابل ایندکس باشد (قبلاً بخشی از صفحات بلاک شده بود).
		$extra .= "Allow: /en/\n";
		// صفحات سبد/پرداخت/حساب: نباید در نتایج باشند.
		$extra .= "Disallow: /cart/\n";
		$extra .= "Disallow: /checkout/\n";
		$extra .= "Disallow: /my-account/\n";
		$extra .= "Disallow: /*?add-to-cart=\n";
		$extra .= "Disallow: /*?orderby=\n";
		$extra .= "Disallow: /*?filter_\n";
		$extra .= "Disallow: /*?rating_filter=\n";
		$extra .= "Disallow: /*?s=\n";
		$extra .= "Disallow: /*?replytocom=\n";
		$extra .= "# END Volusx SEO\n";

		return $output . $extra;
	}

	/* ---------------------------------------------------------------------
	 * ۲) index / noindex
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $robots دستورهای robots meta.
	 * @return array
	 */
	public static function filter_wp_robots( $robots ) {
		if ( ! is_array( $robots ) ) {
			return $robots;
		}

		if ( self::is_utility_page() || self::is_facet_url() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'], $robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview'] );
			return $robots;
		}

		if ( self::should_be_indexable() ) {
			unset( $robots['noindex'], $robots['nofollow'] );
			$robots['index']  = true;
			$robots['follow'] = true;

			// نمایش تصویر بزرگ در نتایج → کلیک بیشتر.
			if ( ! isset( $robots['max-image-preview'] ) ) {
				$robots['max-image-preview'] = 'large';
			}
			if ( ! isset( $robots['max-snippet'] ) ) {
				$robots['max-snippet'] = -1;
			}
			if ( ! isset( $robots['max-video-preview'] ) ) {
				$robots['max-video-preview'] = -1;
			}
		}

		return $robots;
	}

	/** صفحاتی که هرگز نباید ایندکس شوند. */
	private static function is_utility_page() {
		if ( is_search() || is_404() || is_attachment() || is_feed() ) {
			return true;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return true;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return true;
		}

		return false;
	}

	/** صفحاتی که باید صراحتاً index شوند (رفع noindex ناخواسته). */
	private static function should_be_indexable() {
		$indexable = false;

		if ( is_front_page() || is_home() ) {
			$indexable = true;
		}
		if ( is_singular( array( 'post', 'page', 'product' ) ) ) {
			$indexable = true;
		}
		if ( is_tax( array( 'product_cat', 'product_tag', 'category', 'post_tag' ) ) ) {
			$indexable = true;
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$indexable = true;
		}
		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			$indexable = true;
		}

		/**
		 * اجازه تغییر دستی فهرست صفحات ایندکس‌شدنی.
		 *
		 * @param bool $indexable
		 */
		return (bool) apply_filters( 'volusx_seo_should_be_indexable', $indexable );
	}

	/** آیا URL فعلی یک نسخه فیلترشده از یک صفحه است؟ */
	private static function is_facet_url() {
		if ( empty( $_GET ) || ! is_array( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$keys = array_keys( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		foreach ( $keys as $key ) {
			$key = (string) $key;

			if ( in_array( $key, self::$facet_params, true ) ) {
				return true;
			}
			// filter_* (فیلترهای ویجت ووکامرس) و query_type_*.
			if ( 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * ۳) canonical / حذف تکراری‌ها
	 * ------------------------------------------------------------------ */

	public static function normalize_duplicate_urls() {
		if ( is_admin() || is_404() ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $request_uri ) {
			return;
		}

		$parts = wp_parse_url( $request_uri );
		if ( empty( $parts['query'] ) ) {
			return;
		}

		parse_str( $parts['query'], $args );
		if ( ! is_array( $args ) || empty( $args ) ) {
			return;
		}

		$found = false;
		foreach ( self::$tracking_params as $param ) {
			if ( isset( $args[ $param ] ) ) {
				unset( $args[ $param ] );
				$found = true;
			}
		}

		// فقط پارامترهای تبلیغاتی حذف می‌شوند؛ پارامترهای فروشگاه دست‌نخورده می‌مانند.
		if ( ! $found ) {
			return;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$url  = home_url( $path );
		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		wp_safe_redirect( $url, 301 );
		exit;
	}

	/**
	 * برای URLهای فیلترشده، canonical را به نسخه تمیز برمی‌گرداند.
	 *
	 * @param string $canonical
	 * @return string
	 */
	public static function filter_canonical( $canonical ) {
		if ( ! self::is_facet_url() || ! is_string( $canonical ) || '' === $canonical ) {
			return $canonical;
		}

		$clean = remove_query_arg( array_merge( self::$facet_params, self::$tracking_params ), $canonical );

		return $clean ? $clean : $canonical;
	}

	/* ---------------------------------------------------------------------
	 * ۷) اجبار HTTPS
	 * ------------------------------------------------------------------ */

	/**
	 * هر درخواست HTTP را با 301 به HTTPS منتقل می‌کند.
	 * در گزارش Generative AI Features آدرس http://volusx.com/en/shop/ دیده شد
	 * که یعنی نسخهٔ ناامن هنوز در دسترس و ایندکس‌پذیر است.
	 *
	 * اگر سایت پشت Cloudflare/پروکسی است، هدر X-Forwarded-Proto هم بررسی می‌شود
	 * تا حلقهٔ ریدایرکت ایجاد نشود.
	 */
	public static function force_https() {
		if ( is_admin() ) {
			return;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$enabled = (bool) apply_filters( 'volusx_seo_force_https', true );
		if ( ! $enabled ) {
			return;
		}

		if ( self::is_request_https() ) {
			return;
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! $host ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		if ( ! $host ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		wp_safe_redirect( 'https://' . $host . $uri, 301 );
		exit;
	}

	/** تشخیص HTTPS با در نظر گرفتن پروکسی/CDN. */
	private static function is_request_https() {
		if ( function_exists( 'is_ssl' ) && is_ssl() ) {
			return true;
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$proto = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) );
			if ( 'https' === $proto ) {
				return true;
			}
		}

		if ( ! empty( $_SERVER['HTTP_FRONT_END_HTTPS'] ) && 'on' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_FRONT_END_HTTPS'] ) ) ) ) {
			return true;
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * ۴) 404 → 301
	 * ------------------------------------------------------------------ */

	public static function handle_404() {
		if ( ! is_404() ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_string( $path ) || '' === $path ) {
			self::log_404( '' );
			return;
		}

		$path = untrailingslashit( $path );

		$map = self::redirect_map();
		if ( isset( $map[ $path ] ) ) {
			wp_safe_redirect( $map[ $path ], 301 );
			exit;
		}

		// اگر محصولی حذف شده باشد، به فروشگاه برگرد (فقط اگر صراحتاً فعال شود).
		if ( defined( 'VOLUSX_SEO_404_FALLBACK' ) && VOLUSX_SEO_404_FALLBACK ) {
			if ( 0 === strpos( $path, '/product/' ) && function_exists( 'wc_get_page_permalink' ) ) {
				wp_safe_redirect( wc_get_page_permalink( 'shop' ), 301 );
				exit;
			}
		}

		self::log_404( $path );
	}

	/**
	 * نقشه ریدایرکت ۴۰۴ها: کلید = مسیر قدیمی، مقدار = آدرس مقصد (کامل با https).
	 *
	 * @return array<string,string>
	 */
	private static function redirect_map() {
		$map = array(
			// نمونه‌ها — مسیرهای واقعی را از Search Console → Page indexing → Not found (404) بردارید:
			// '/old-product-slug'          => 'https://volusx.com/product/new-product-slug/',
			// '/category/old'              => 'https://volusx.com/product-category/filler/',
		);

		// ریدایرکت‌های پیشنهادی برای خوشهٔ تکراری «hyomax / hyamax».
		// این دو املای برند در گزارش HTTPS و Merchant listings هر دو وجود دارند و
		// باعث رقابت دو صفحه با یکدیگر (cannibalization) می‌شوند.
		// ⚠️ فقط پس از تأیید مدیر سایت فعال شود:
		//    define( 'VOLUSX_SEO_DUP_SLUG_MAP', true );
		if ( defined( 'VOLUSX_SEO_DUP_SLUG_MAP' ) && VOLUSX_SEO_DUP_SLUG_MAP ) {
			$map['/product/hyomax-caha']       = 'https://volusx.com/product/فیلر-hyamax-caha-lido/';
			$map['/product/hyomax-extra-deep'] = 'https://volusx.com/product/hyamax-extra-deep-filler/';
		}

		/**
		 * @param array<string,string> $map
		 */
		$map = (array) apply_filters( 'volusx_seo_redirect_map', $map );

		$normalized = array();
		foreach ( $map as $from => $to ) {
			$normalized[ untrailingslashit( (string) $from ) ] = $to;
		}

		return $normalized;
	}

	/**
	 * ثبت ۴۰۴ها برای تکمیل نقشه ریدایرکت. پیش‌فرض: روشن.
	 * برای خاموش‌کردن: define( 'VOLUSX_SEO_LOG_404', false );
	 *
	 * @param string $path
	 */
	private static function log_404( $path ) {
		if ( defined( 'VOLUSX_SEO_LOG_404' ) && ! VOLUSX_SEO_LOG_404 ) {
			return;
		}

		$dir = WP_CONTENT_DIR . '/uploads';
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return;
		}

		$file = $dir . '/volusx-404.log';
		if ( file_exists( $file ) && filesize( $file ) > 1048576 ) { // 1MB
			return;
		}

		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$line    = gmdate( 'c' ) . "\t" . $path . "\t" . $referer . "\n";

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/* ---------------------------------------------------------------------
	 * ۵) Product structured data
	 * ------------------------------------------------------------------ */

	/**
	 * @param array      $markup
	 * @param WC_Product $product
	 * @return array
	 */
	public static function filter_product_schema( $markup, $product ) {
		if ( ! is_array( $markup ) ) {
			return $markup;
		}

		// ۵-۱) اگر offers وجود دارد، ارز آن را استاندارد کن.
		if ( ! empty( $markup['offers'] ) ) {
			$markup['offers'] = self::normalize_offers_currency( $markup['offers'] );
		}

		// ۵-۲) اگر offers غایب است، از خود محصول بساز (رفع خطای بحرانی ۴۶ آیتم).
		if ( empty( $markup['offers'] ) && is_object( $product ) && method_exists( $product, 'get_price' ) ) {
			$offers = self::build_offers( $product );
			if ( ! empty( $offers ) ) {
				$markup['offers'] = $offers;
			}
		}

		// ۵-۳) aggregateRating بی‌اعتبار را حذف کن (رفع «reviewCount must be positive»).
		if ( isset( $markup['aggregateRating'] ) && is_array( $markup['aggregateRating'] ) ) {
			$rating_value = isset( $markup['aggregateRating']['ratingValue'] ) ? (float) $markup['aggregateRating']['ratingValue'] : 0.0;
			$review_count = 0;
			if ( isset( $markup['aggregateRating']['reviewCount'] ) ) {
				$review_count = (int) $markup['aggregateRating']['reviewCount'];
			} elseif ( isset( $markup['aggregateRating']['ratingCount'] ) ) {
				$review_count = (int) $markup['aggregateRating']['ratingCount'];
			}

			if ( $review_count < 1 || $rating_value <= 0 ) {
				unset( $markup['aggregateRating'] );
			}
		}

		// ۵-۴) آرایه‌های خالی review حذف شوند.
		if ( isset( $markup['review'] ) && empty( $markup['review'] ) ) {
			unset( $markup['review'] );
		}

		// ۵-۵) فیلدهای لازم برای نمایش در Merchant listings
		// (در گزارش Google، «Item name» برای همهٔ آیتم‌ها N/A بود).
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$markup = self::ensure_product_core_fields( $markup, $product );
		}

		return $markup;
	}

	/**
	 * تکمیل name / image / brand در ساختار Product.
	 *
	 * @param array      $markup
	 * @param WC_Product $product
	 * @return array
	 */
	private static function ensure_product_core_fields( $markup, $product ) {
		// name
		if ( empty( $markup['name'] ) && method_exists( $product, 'get_name' ) ) {
			$name = wp_strip_all_tags( (string) $product->get_name() );
			if ( '' !== $name ) {
				$markup['name'] = $name;
			}
		}

		// image
		if ( empty( $markup['image'] ) ) {
			$image_id = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
			if ( $image_id ) {
				$src = wp_get_attachment_image_src( $image_id, 'woocommerce_single' );
				if ( ! empty( $src[0] ) ) {
					$markup['image'] = $src[0];
				}
			}
		}

		// brand — فقط اگر واقعاً تعریف شده باشد (برند ساختگی ساخته نمی‌شود).
		if ( empty( $markup['brand'] ) ) {
			$brand = '';
			if ( method_exists( $product, 'get_attribute' ) ) {
				$brand = (string) $product->get_attribute( 'brand' );
				if ( '' === $brand ) {
					$brand = (string) $product->get_attribute( 'pa_brand' );
				}
			}
			$brand = trim( wp_strip_all_tags( $brand ) );
			if ( '' !== $brand ) {
				$markup['brand'] = array(
					'@type' => 'Brand',
					'name'  => $brand,
				);
			}
		}

		// description
		if ( empty( $markup['description'] ) && method_exists( $product, 'get_short_description' ) ) {
			$desc = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );
			if ( '' !== $desc ) {
				$markup['description'] = $desc;
			}
		}

		return $markup;
	}

	/**
	 * @param WC_Product $product
	 * @return array
	 */
	private static function build_offers( $product ) {
		$currency = self::normalize_currency( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'IRR' );
		$url      = get_permalink( $product->get_id() );
		$in_stock = method_exists( $product, 'is_in_stock' ) ? $product->is_in_stock() : true;
		$availability = $in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';

		// محصول متغیر → AggregateOffer
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_prices' ) ) {
			$prices = $product->get_variation_prices( true );
			$low    = isset( $prices['price'] ) && is_array( $prices['price'] ) ? min( array_map( 'floatval', $prices['price'] ) ) : 0;
			$high   = isset( $prices['price'] ) && is_array( $prices['price'] ) ? max( array_map( 'floatval', $prices['price'] ) ) : 0;
			$count  = ( method_exists( $product, 'get_children' ) ) ? count( (array) $product->get_children() ) : 1;

			if ( $low > 0 ) {
				return array(
					'@type'      => 'AggregateOffer',
					'url'        => $url,
					'lowPrice'   => self::format_price( $low ),
					'highPrice'  => self::format_price( $high > 0 ? $high : $low ),
					'offerCount' => (string) max( 1, $count ),
					'priceCurrency' => $currency,
					'availability'  => $availability,
				);
			}
		}

		$price = method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0;
		if ( $price <= 0 ) {
			return array();
		}

		return array(
			'@type'         => 'Offer',
			'url'           => $url,
			'price'         => self::format_price( $price ),
			'priceCurrency' => $currency,
			'availability'  => $availability,
			'priceValidUntil' => gmdate( 'Y-12-31' ),
			'seller'        => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
		);
	}

	/**
	 * @param mixed $offers
	 * @return mixed
	 */
	private static function normalize_offers_currency( $offers ) {
		if ( ! is_array( $offers ) ) {
			return $offers;
		}

		if ( isset( $offers['priceCurrency'] ) ) {
			$offers['priceCurrency'] = self::normalize_currency( $offers['priceCurrency'] );
		}

		// فروش محصولات تکی/چندگانه: آرایه‌ای از offers.
		foreach ( $offers as $key => $value ) {
			if ( is_array( $value ) && isset( $value['priceCurrency'] ) ) {
				$offers[ $key ]['priceCurrency'] = self::normalize_currency( $value['priceCurrency'] );
			}
		}

		return $offers;
	}

	/**
	 * کدهای غیراستاندارد ریال/تومان → IRR (ISO 4217 معتبر).
	 *
	 * @param string $code
	 * @return string
	 */
	private static function normalize_currency( $code ) {
		$code = strtoupper( trim( (string) $code ) );

		$map = array(
			'IRT'   => 'IRR',
			'TOMAN' => 'IRR',
			'RIAL'  => 'IRR',
			'ریال'  => 'IRR',
			'تومان' => 'IRR',
		);

		return isset( $map[ $code ] ) ? $map[ $code ] : $code;
	}

	/**
	 * قیمت بدون جداکننده هزارگان و با دو رقم اعشار.
	 *
	 * @param float $price
	 * @return string
	 */
	private static function format_price( $price ) {
		return number_format( (float) $price, 2, '.', '' );
	}

	/* ---------------------------------------------------------------------
	 * ۶) LCP موبایل
	 * ------------------------------------------------------------------ */

	public static function preload_lcp_image() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();
		if ( ! $product_id ) {
			return;
		}

		$image_id = get_post_thumbnail_id( $product_id );
		if ( ! $image_id ) {
			return;
		}

		$size    = 'woocommerce_single';
		$src     = wp_get_attachment_image_src( $image_id, $size );
		$srcset  = wp_get_attachment_image_srcset( $image_id, $size );
		if ( empty( $src[0] ) ) {
			return;
		}

		printf(
			'<link rel="preload" as="image" href="%1$s"%2$s sizes="(max-width: 600px) 100vw, 50vw" fetchpriority="high" />' . "\n",
			esc_url( $src[0] ),
			$srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' : ''
		);
	}

	/**
	 * تصویر اصلی محصول: eager + fetchpriority تا LCP سریع‌تر شود.
	 *
	 * @param array            $attr
	 * @param WP_Post|int|null $attachment
	 * @param string|int[]     $size
	 * @return array
	 */
	public static function lcp_image_attributes( $attr, $attachment, $size ) {
		if ( self::$lcp_marked ) {
			return $attr;
		}
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $attr;
		}

		$attachment_id = is_object( $attachment ) && isset( $attachment->ID ) ? (int) $attachment->ID : (int) $attachment;
		if ( ! $attachment_id ) {
			return $attr;
		}

		$thumb_id = get_post_thumbnail_id( get_queried_object_id() );
		if ( ! $thumb_id || (int) $thumb_id !== $attachment_id ) {
			return $attr;
		}

		self::$lcp_marked        = true;
		$attr['loading']         = 'eager';
		$attr['fetchpriority']   = 'high';

		return $attr;
	}
}

Volusx_SEO_Fixes::init();

endif;
