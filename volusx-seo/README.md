# Volusx SEO Fix Pack — volusx.com

بستهٔ اصلاحات آمادهٔ نصب برای رفع مشکلات گزارش‌شده در **Google Search Console** (گزارش ۳۰ اکتبر ۲۰۲۶).

> ⚠️ این بسته برای سایت **وردپرس + ووکامرس** نوشته شده و فقط با filter/hook استاندارد کار می‌کند:
> هیچ داده‌ای حذف یا بازنویسی نمی‌شود و با حذف یک فایل، سایت دقیقاً به وضعیت قبل برمی‌گردد.
> پیشنهاد: ابتدا روی **استیجینگ** نصب و بررسی شود، سپس روی سایت اصلی.

---

## ۱. نصب (۶۰ ثانیه)

1. در هاست، به مسیر `wp-content/` بروید و اگر پوشهٔ `mu-plugins` وجود ندارد آن را بسازید (نام دقیقاً `mu-plugins`).
2. فایل `volusx-seo-fixes.php` را داخل `mu-plugins/` آپلود کنید.
3. تمام. mu-plugin خودکار فعال است (در فهرست افزونه‌ها دیده نمی‌شود — این طبیعی است).

بررسی موفقیت نصب:

```bash
wp plugin list --status=must-use
wp eval 'var_dump( has_filter( "robots_txt", array( "Volusx_SEO_Fixes", "filter_robots_txt" ) ) );'
```

خاموش‌کردن بدون حذف فایل — این خط را به `wp-config.php` اضافه کنید:

```php
define( 'VOLUSX_SEO_DISABLE', true );
```

### فعال‌کردن ریدایرکت خوشهٔ تکراری hyomax → hyamax (اختیاری، پیش‌فرض خاموش)

در گزارش‌ها دو املای برند (`hyomax` و `hyamax`) هر دو وجود دارند و با هم رقابت می‌کنند.
پس از اطمینان از اینکه کدام محصول «اصلی» است، این خط را به `wp-config.php` اضافه کنید:

```php
define( 'VOLUSX_SEO_DUP_SLUG_MAP', true );
```

تا وقتی این خط نباشد، هیچ ریدایرکتی از این خوشه اعمال نمی‌شود.

---

## ۲. هر بخش چه کاری می‌کند و کدام مشکل Search Console را می‌بندد

| بخش | مشکل GSC | تعداد | چه می‌کند |
|---|---|---|---|
| `filter_robots_txt` | Blocked by robots.txt | ۷ | مسیرهای ضروری (`/wp-content/uploads/`، `admin-ajax.php`، `.well-known`، `/en/`) را Allow و صفحات سبد/پرداخت/فیلتر را Disallow می‌کند |
| `filter_wp_robots` | Excluded by 'noindex' | ۱۴ | `noindex` ناخواسته را از صفحهٔ اصلی، نوشته‌ها، صفحات و **همهٔ محصولات و دسته‌های محصول** برمی‌دارد و `max-image-preview: large` اضافه می‌کند |
| `is_facet_url` + `filter_canonical` | Duplicate, Google chose different canonical | ۸ | URLهای `?orderby=`، `?per_page=`، `?filter_*` را `noindex, follow` می‌کند و canonical را به نسخهٔ تمیز برمی‌گرداند |
| `normalize_duplicate_urls` | Page with redirect / URLهای تکراری | ۱۷ | پارامترهای تبلیغاتی (`utm_*`، `fbclid`، `gclid`، …) را با **301** به URL تمیز منتقل می‌کند |
| `handle_404` | Not found (404) | ۳ | نقشهٔ ریدایرکت ۳۰۱ + ثبت خودکار هر ۴۰۴ در لاگ تا نقشه کامل شود |
| `filter_product_schema` | Product snippets: «Either offers, review, or aggregateRating should be specified» | **۴۶ (بحرانی)** | اگر `offers` نبود از خود محصول می‌سازد (Offer یا AggregateOffer برای محصولات متغیر)، ارز را به کد ISO استاندارد (`IRR`) تبدیل می‌کند، و `aggregateRating` بی‌اعتبار (reviewCount صفر) را حذف می‌کند |
| `ensure_product_core_fields` | Merchant listings: «Item name = N/A» برای همهٔ ۸ آیتم | ۸ | `name`، `image`، `brand` و `description` را در ساختار Product تکمیل می‌کند |
| `force_https` | آدرس `http://volusx.com/en/shop/` در گزارش Generative AI | ۱+ | هر درخواست HTTP را با ۳۰۱ به HTTPS منتقل می‌کند (با پشتیبانی از پروکسی/CDN) |
| `preload_lcp_image` + `lcp_image_attributes` | Core Web Vitals: LCP > 4s (mobile) | ۶۶ | تصویر شاخص محصول را preload و `fetchpriority="high"` + `loading="eager"` می‌کند |

---

## ۳. کارهایی که فقط با دسترسی به سایت انجام می‌شود (دستی)

### ۳-۱. سه آدرس ۴۰۴ را پیدا و ریدایرکت کنید

Search Console → **Page indexing** → «Not found (404)» → دکمهٔ **Export** → ستون URL را بردارید.

سپس در بخش `redirect_map()` فایل، خطوط نمونه را از حالت کامنت درآورید و مقصد درست را بنویسید:

```php
$map = array(
    '/product/hyamax-old-name' => 'https://volusx.com/product/hyamax-extra-deep-filler/',
);
```

اگر نمی‌دانید هر ۴۰۴ قبلاً چه بوده، از **Wayback Machine** (`web.archive.org/web/*/volusx.com/مسیر`) کمک بگیرید.
اگر صفحه واقعاً حذف شده و جانشین ندارد، به‌جای ریدایرکت، بهتر است **۴۱۰ (Gone)** برگردانید.

**لاگ خودکار:** هر ۴۰۴ جدید در `wp-content/uploads/volusx-404.log` ثبت می‌شود (سقف ۱ مگابایت).
برای خاموش‌کردن: `define( 'VOLUSX_SEO_LOG_404', false );`

### ۳-۲. صفحهٔ خطای سرور (5xx) — ۱ مورد

```bash
# آخرین خطاهای PHP
tail -n 200 wp-content/debug.log
wp eval 'global $wpdb; var_dump( $wpdb->get_results( "SELECT option_name FROM {$wpdb->options} WHERE autoload = \"yes\" AND LENGTH(option_value) > 1000000" ) );'
```

اگر محصول خاصی خطا می‌دهد، معمولاً از یک افزونهٔ سازگاری‌نشده یا متای خراب است. `WP_DEBUG_LOG` را موقتاً روشن کنید و همان URL را باز کنید.

### ۳-۳. «Discovered — currently not indexed» (۷۹ صفحه) و «Crawled — currently not indexed» (۳۱ صفحه)

این‌ها **باگ نیستند**، سیگنال کیفیت/بودجهٔ خزش‌اند. اقدام‌های مؤثر:

1. **لینک داخلی**: هر محصول باید حداقل از ۲ صفحهٔ دیگر (دستهٔ مربوطه + ۲ محصول مشابه) لینک بگیرد.
2. **محتوای یکتا**: محصولات با توضیح کوتاه/کپی‌شده ایندکس نمی‌شوند. حداقل ۳۰۰ کلمهٔ اختصاصی + جدول مشخصات + FAQ.
3. **سرعت پاسخ سرور**: TTFB بالای ۶۰۰ms بودجهٔ خزش را می‌سوزاند (کش صفحه + Object Cache).
4. **حذف صفحات کم‌ارزش** از sitemap: تگ‌های خالی، آرشیو صفحه‌بندی‌شدهٔ بی‌محتوا، `/author/`، پیوست‌ها.

### ۳-۴. Content `/en/` (نسخهٔ انگلیسی)

صفحات انگلیسی impressions زیادی دارند اما CTR پایین — نمونهٔ شاخص:

| صفحه | Impressions | Clicks | CTR | Position |
|---|---|---|---|---|
| `/en/product/botox-dyston-500-units/` | ۳۳۸ | ۱ | **۰.۳٪** | ۳.۶۶ |
| `/en/product/botox-masport-500-units/` | ۳۰۹ | ۱۱ | ۳.۵۶٪ | ۵.۵۴ |
| `/product-category/مزوژل/` | ۱۶۳ | ۱ | ۰.۶۱٪ | ۴۸.۷۹ |

رتبهٔ ۳.۶ با CTR ۰.۳٪ یعنی **عنوان و توضیح متا مشکل دارند**، نه رتبه. بازنویسی `title` و `meta description` این سه صفحه، سریع‌ترین برد این پروژه است (بدون هیچ تغییر فنی).

### ۳-۵. سرعت (۶۶ آدرس با LCP > 4s)

بعد از نصب این بسته، به ترتیب:

1. تبدیل تصاویر به **WebP** (افزونهٔ Converter for Media) + حداکثر عرض ۱۲۰۰px.
2. **کش صفحه** (LiteSpeed Cache / WP Rocket) + کش آبجکت (Redis).
3. بارگذاری تأخیری اسکریپت‌های غیرضروری (اسلایدر، ویجت‌های شبکهٔ اجتماعی).
4. فونت: `font-display: swap` + preload فونت اصلی.
5. سنجش مجدد با PageSpeed Insights روی ۳ محصول پربازدید.

---

## ۴. بررسی پس از نصب (چک‌لیست)

```bash
# robots.txt باید بلوک Volusx را داشته باشد
curl -s https://volusx.com/robots.txt | tail -20

# متای robots صفحهٔ محصول نباید noindex باشد
curl -s https://volusx.com/product/reyoungel-revital/ | grep -o '<meta name="robots"[^>]*>'

# ساختار محصول باید offers داشته باشد
curl -s https://volusx.com/product/reyoungel-revital/ | grep -o '"offers":{[^}]*}'

# هدرهای LCP
curl -sI https://volusx.com/product/reyoungel-revital/ | grep -i 'link.*preload'
```

سپس در Search Console:

- **URL Inspection** روی ۳ محصول → «Test live URL» → تأیید `Indexable`.
- **Product snippets** → روی خطای ۴۶ موردی → **Validate fix**.
- **Page indexing → 404** → پس از تکمیل نقشه → **Validate fix**.
- اعتبارسنجی معمولاً ۱ تا ۳ هفته طول می‌کشد تا وضعیت `Passed` شود.

---

## ۵. فایل‌ها

| فایل | توضیح |
|---|---|
| `volusx-seo-fixes.php` | خودِ افزونه (mu-plugin) |
| `robots.txt` | نسخهٔ پیشنهادی کامل robots.txt (اگر افزونهٔ SEO سایت شما آن را تولید نمی‌کند) |
| `../SEO-REPORT-volusx-2026-10-30.md` | گزارش کامل تحلیل و اولویت‌بندی |
