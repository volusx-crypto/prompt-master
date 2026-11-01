<?php
/**
 * Active Plugin Conflict Scanner & Autolinks Manager Auto-Configurator.
 *
 * Inspects all active plugins on the site, detects conflicts (especially around
 * internal linking, SEO, page builders, WooCommerce, and caching), and safely
 * applies optimal Persian SEO configuration to Autolinks Manager with Snapshot rollback.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Plugin_Inspector {

	/**
	 * Scan all installed/active plugins and analyze compatibility & potential conflicts with Autolinks Manager.
	 *
	 * @return array
	 */
	public static function audit_conflicts() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_paths   = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$active_paths   = array_unique( array_merge( $active_paths, $network_active ) );
		}

		$active_list        = array();
		$autolinks_detected = null;
		$competing_linkers  = array();
		$seo_plugins        = array();
		$cache_plugins      = array();
		$builders           = array();
		$has_woocommerce    = false;

		foreach ( $active_paths as $rel_path ) {
			$meta = isset( $all_plugins[ $rel_path ] ) ? $all_plugins[ $rel_path ] : array( 'Name' => $rel_path, 'Version' => '' );
			$name = (string) ( $meta['Name'] ?? $rel_path );
			$slug = strtolower( dirname( $rel_path ) . ' ' . $name );

			$active_list[] = array(
				'file'    => $rel_path,
				'name'    => $name,
				'version' => (string) ( $meta['Version'] ?? '' ),
			);

			if ( false !== strpos( $slug, 'autolinks-manager' ) || false !== strpos( $slug, 'autolinks manager' ) || false !== strpos( $slug, 'auto-links-manager' ) ) {
				$autolinks_detected = array(
					'file'    => $rel_path,
					'name'    => $name,
					'version' => (string) ( $meta['Version'] ?? '' ),
				);
				continue;
			}

			// Competing automatic internal linkers (High conflict risk!).
			if ( preg_match( '/(link-whisper|internal-link-juicer|wp-auto-links|seo-smart-links|wpa-seo-auto-linker|taxopress)/i', $slug ) ) {
				$competing_linkers[] = $name;
			}

			// SEO suites.
			if ( preg_match( '/(rank-math|wordpress-seo|yoast|all-in-one-seo|seopress)/i', $slug ) ) {
				$seo_plugins[] = $name;
			}

			// Caching / minification plugins.
			if ( preg_match( '/(litespeed-cache|wp-rocket|w3-total-cache|wp-super-cache|wp-fastest-cache|autoptimize)/i', $slug ) ) {
				$cache_plugins[] = $name;
			}

			// Page builders.
			if ( preg_match( '/(elementor|js_composer|wpbakery|bricks|divi|beaver-builder)/i', $slug ) ) {
				$builders[] = $name;
			}

			if ( false !== strpos( $slug, 'woocommerce' ) ) {
				$has_woocommerce = true;
			}
		}

		$warnings = array();

		// 1. Check competing internal linkers.
		if ( ! empty( $competing_linkers ) ) {
			$warnings[] = array(
				'severity' => 'critical',
				'title'    => 'تداخل مستقیم با افزونه‌های دیگر لینک‌ساز خودکار',
				'detail'   => sprintf(
					'افزونه(های) «%s» همزمان با Autolinks Manager فعال هستند. فعال بودن دو لینک‌ساز خودکار باعث تودرتو شدن تگ‌های <a>، شکستن ساختار HTML و اسپم شدن لینک‌ها در گوگل می‌شود. یکی از آن‌ها را غیرفعال کنید.',
					implode( '، ', $competing_linkers )
				),
			);
		}

		// 2. Check Page Builders.
		if ( ! empty( $builders ) ) {
			$warnings[] = array(
				'severity' => 'medium',
				'title'    => 'نکته سازگاری با صفحه‌ساز (' . implode( '، ', $builders ) . ')',
				'detail'   => 'برای جلوگیری از به هم ریختن استایل ویجت‌ها و لندینگ‌های صفحه‌ساز، Autolinks Manager باید فقط روی نوشته‌های وبلاگ (post) فعال شود و روی برگه‌ها (page) غیرفعال بماند.',
			);
		}

		// 3. Check WooCommerce cart/checkout protection.
		if ( $has_woocommerce ) {
			$warnings[] = array(
				'severity' => 'medium',
				'title'    => 'محافظت از صفحات سبد خرید و تسویه‌حساب ووکامرس',
				'detail'   => 'ووکامرس فعال است؛ لینک‌سازی خودکار نباید روی برگه‌های سیستمی (سبد خرید، تسویه‌حساب و حساب کاربری) اجرا شود و در محصولات نیز سقف لینک باید حداکثر ۲ تا ۳ عدد باشد.',
			);
		}

		// 4. Check SEO suites note.
		if ( ! empty( $seo_plugins ) ) {
			$warnings[] = array(
				'severity' => 'low',
				'title'    => 'سازگاری کامل با افزونه سئو (' . implode( '، ', $seo_plugins ) . ')',
				'detail'   => 'هیچ تداخل فنی با ' . implode( '، ', $seo_plugins ) . ' وجود ندارد؛ فقط توجه کنید شمارنده لینک داخلی افزونه سئو لینک‌های داینامیک لحظه رندر را در جدول پیشخوان نمی‌شمارد اما ربات گوگل آن‌ها را کامل می‌بیند.',
			);
		}

		// 5. Check Caching plugins.
		if ( ! empty( $cache_plugins ) ) {
			$warnings[] = array(
				'severity' => 'low',
				'title'    => 'هماهنگی با افزونه کش (' . implode( '، ', $cache_plugins ) . ')',
				'detail'   => 'پس از تغییر تنظیمات یا افزودن کلمات کلیدی در Autolinks Manager، کش سایت باید پاک‌سازی (Purge) شود (فرمانده هوشمند این کار را خودکار انجام می‌دهد).',
			);
		}

		// 6. Check PCRE UTF-8 support for Persian word boundaries.
		$utf8_pcre_ok = (bool) @preg_match( '/\p{L}/u', 'آزمایش' );
		if ( ! $utf8_pcre_ok ) {
			$warnings[] = array(
				'severity' => 'critical',
				'title'    => 'عدم پشتیبانی UTF-8 در موتور Regex سرور PHP',
				'detail'   => 'کتابخانه PCRE روی هاست شما از یونیکد کامل پشتیبانی نمی‌کند که می‌تواند باعث بریده شدن کلمات فارسی در Autolinks Manager شود.',
			);
		}

		return array(
			'active_plugins_count' => count( $active_list ),
			'active_plugins'       => $active_list,
			'autolinks_detected'   => $autolinks_detected,
			'daam_options_found'   => count( self::get_autolinks_options() ),
			'has_critical_conflict'=> ! empty( $competing_linkers ) || ! $utf8_pcre_ok,
			'warnings'             => $warnings,
		);
	}

	/**
	 * Fetch all database options belonging to Autolinks Manager (DAEXT `daam_*` or `autolinks_*`).
	 *
	 * @return array Associative map of option_name => current_value.
	 */
	public static function get_autolinks_options() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'daam\_%' OR option_name LIKE 'daextam\_%' ORDER BY option_name ASC",
			ARRAY_A
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$k         = $r['option_name'];
				$map[ $k ] = get_option( $k );
			}
		}
		return $map;
	}

	/**
	 * Automatically apply optimal Persian SEO & conflict-free configuration to Autolinks Manager
	 * while preserving exact data types (array vs string vs int) and creating a Rollback Snapshot.
	 *
	 * @param string $actor Actor name.
	 * @return array|WP_Error
	 */
	public static function auto_configure_autolinks_manager( $actor = 'wp-admin' ) {
		$current_options = self::get_autolinks_options();

		if ( empty( $current_options ) ) {
			return new WP_Error(
				'asc_autolinks_options_not_found',
				'گزینه‌های دیتابیس Autolinks Manager (پیشوند daam_ یا daextam_) یافت نشد. مطمئن شوید افزونه Autolinks Manager فعال است و یک‌بار وارد منوی Options آن شده‌اید.',
				array( 'status' => 404 )
			);
		}

		$protected_tags_list = array(
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'a',
			'img',
			'script',
			'style',
			'code',
			'pre',
			'blockquote',
			'button',
			'label',
			'input',
			'textarea',
			'select',
		);

		$changes_applied = array();

		foreach ( $current_options as $opt_name => $cur_val ) {
			$new_val     = $cur_val;
			$should_save = false;

			// 1. Internal links should open in the SAME tab (0 / '0') and be DOFOLLOW (0 / '0').
			if ( preg_match( '/_(open_new_tab|use_nofollow|case_sensitive_search|random_prioritization)$/', $opt_name ) ) {
				$new_val     = is_int( $cur_val ) ? 0 : '0';
				$should_save = true;
			}

			// 2. Prevent dangerous 'None' (5) boundary which splits Persian words in half; reset to Generic (1).
			if ( preg_match( '/_(left_boundary|right_boundary|string_before|string_after)$/', $opt_name ) ) {
				if ( '5' === (string) $cur_val ) {
					$new_val     = is_int( $cur_val ) ? 1 : '1';
					$should_save = true;
				}
			}

			// 3. Max repetitions of a single keyword per post = 1.
			if ( preg_match( '/_(default_limit|limit)$/', $opt_name ) && false === strpos( $opt_name, 'general_limit' ) && false === strpos( $opt_name, 'same_url' ) ) {
				$new_val     = is_int( $cur_val ) ? 1 : '1';
				$should_save = true;
			}

			// 4. Max links pointing to the exact SAME URL inside a single post = 1.
			if ( false !== strpos( $opt_name, 'same_url_limit' ) ) {
				$new_val     = is_int( $cur_val ) ? 1 : '1';
				$should_save = true;
			}

			// 5. Avoid self-linking (ignore_self_autolinks = 1).
			if ( preg_match( '/(ignore_self|self_autolinks|avoid_self)/', $opt_name ) ) {
				$new_val     = is_int( $cur_val ) ? 1 : '1';
				$should_save = true;
			}

			// 6. General max autolinks per post: cap at 4 (prevents Google over-optimization/spam).
			if ( false !== strpos( $opt_name, 'general_limit_mode' ) ) {
				$new_val     = is_int( $cur_val ) ? 1 : '1'; // Manual cap mode or controlled auto.
				$should_save = true;
			}
			if ( false !== strpos( $opt_name, 'general_limit_amount' ) ) {
				$new_val     = is_int( $cur_val ) ? 4 : '4';
				$should_save = true;
			}

			// 7. Restrict active post types to 'post' only (protects Elementor landing pages & WooCommerce cart/checkout pages).
			if ( preg_match( '/(enable_autolinks|post_types)$/', $opt_name ) && false === strpos( $opt_name, 'default_enable' ) ) {
				if ( is_array( $cur_val ) ) {
					$new_val     = array( 'post' );
					$should_save = true;
				}
			}

			// 8. Protect all headings (H1-H6), anchors, scripts, buttons, and code blocks from nested/broken autolinks.
			if ( false !== strpos( $opt_name, 'protected_tags' ) ) {
				if ( is_array( $cur_val ) ) {
					$new_val = array_values( array_unique( array_merge( $cur_val, $protected_tags_list ) ) );
				} else {
					$new_val = implode( ', ', $protected_tags_list );
				}
				$should_save = true;
			}

			if ( $should_save && $new_val !== $cur_val ) {
				$snap_id = ASC_Snapshot::create(
					'configure_autolinks',
					'option',
					$opt_name,
					'تنظیم Autolinks Manager: ' . $opt_name,
					array(
						'exists' => true,
						'value'  => $cur_val,
					),
					$actor,
					'پیکربندی بهینه سئوی فارسی و جلوگیری از تداخل در Autolinks Manager'
				);

				update_option( $opt_name, $new_val );

				ASC_Snapshot::record_after_state(
					$snap_id,
					array(
						'exists' => true,
						'value'  => $new_val,
					)
				);

				$changes_applied[] = array(
					'option'      => $opt_name,
					'old_value'   => $cur_val,
					'new_value'   => $new_val,
					'snapshot_id' => $snap_id,
				);
			}
		}

		ASC_Engine::purge_site_caches();

		ASC_DB::log_audit(
			$actor,
			'configure_autolinks_manager',
			'Autolinks Manager',
			'success',
			sprintf( '%d تنظیم کلیدی Autolinks Manager با موفقیت بهینه‌سازی و کش سایت پاک شد.', count( $changes_applied ) )
		);

		return array(
			'configured'    => true,
			'updated_count' => count( $changes_applied ),
			'changes'       => $changes_applied,
			'message'       => sprintf( 'پیکربندی بهینه Autolinks Manager انجام شد (%d گزینه به‌روزرسانی شد و کش پاک گردید).', count( $changes_applied ) ),
		);
	}
}
