<?php
/**
 * Plugin Conflict Inspector & Autolinks Manager (DAEXT v1.12) Auto-Configurator.
 *
 * Audits all active plugins on the site, detects conflicts with Autolinks Manager
 * (Woodmart, WooCommerce, Elementor, WPML, Easy Table of Contents, PB SEO Friendly Images,
 * LiteSpeed Cache, Perfmatters, Gravity Forms), enforces runtime safety shields,
 * configures optimal SEO options (`daextam_*`), and seeds verified internal autolinks
 * with full Snapshot/Rollback support.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Plugin_Inspector {

	/**
	 * Temporary storage for shielded HTML fragments (TOC, Gravity Forms) during `the_content`.
	 *
	 * @var array
	 */
	private static $shielded_blocks = array();

	/**
	 * Register runtime shields and one-time automatic optimization hook.
	 */
	public static function init() {
		// Runtime guard 1: Prevent Daextam_Autolink_Engine from running on WooCommerce Cart/Checkout/Account,
		// Elementor preview/templates, Woodmart CMS blocks, or static pages.
		add_filter( 'pre_option_daextam_advanced_enable_autolinks', array( __CLASS__, 'filter_runtime_enable_autolinks' ), 10, 1 );

		// Runtime guard 2: Shield Easy Table of Contents (#ez-toc-container) & Gravity Forms (.gform_wrapper)
		// right before Daextam_Autolink_Engine (2147483646) and restore right after (2147483647).
		add_filter( 'the_content', array( __CLASS__, 'shield_sensitive_containers' ), 2147483640 );
		add_filter( 'the_content', array( __CLASS__, 'restore_sensitive_containers' ), 2147483647 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_auto_apply_on_boot' ), 25 );
			add_action( 'admin_notices', array( __CLASS__, 'render_auto_applied_notice' ) );
		}
	}

	/**
	 * Block autolinks dynamically on transactional, builder, and layout post types.
	 *
	 * @param mixed $preempt Default false.
	 * @return mixed
	 */
	public static function filter_runtime_enable_autolinks( $preempt ) {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $preempt;
		}

		// Protect Elementor live preview / editor iframe.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '0';
		}

		// Protect WooCommerce transactional screens.
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return '0';
		}

		// Protect Woodmart blocks, Elementor templates, sliders, and non-blog post types.
		if ( is_singular() ) {
			$pt = get_post_type();
			$blocked_pts = array(
				'cms_block',
				'woodmart_sidebar',
				'woodmart_slide',
				'woodmart_layout',
				'elementor_library',
				'product_variation',
				'shop_order',
				'page',
			);
			if ( $pt && in_array( $pt, $blocked_pts, true ) ) {
				return '0';
			}
		}

		return $preempt;
	}

	/**
	 * Temporarily replace Easy Table of Contents and Gravity Forms HTML blocks with inert tokens
	 * so autolink replacement never mutates TOC anchors or form markup.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function shield_sensitive_containers( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}
		if ( false === strpos( $content, 'ez-toc' ) && false === strpos( $content, 'gform_wrapper' ) ) {
			return $content;
		}

		self::$shielded_blocks = array();

		$patterns = array(
			'/<div[^>]*id=["\']ez-toc-container["\'][^>]*>.*?<\/nav>\s*<\/div>/si',
			'/<div[^>]*class=["\'][^"\']*ez-toc-v2_0[^"\']*["\'][^>]*>.*?<\/div>/si',
			'/<div[^>]*class=["\'][^"\']*gform_wrapper[^"\']*["\'][^>]*>.*?<\/form>\s*<\/div>/si',
		);

		foreach ( $patterns as $pattern ) {
			$content = preg_replace_callback(
				$pattern,
				function ( $matches ) {
					$idx                           = count( ASC_Plugin_Inspector::$shielded_blocks );
					$token                         = '<!--ASC_SHIELD_' . $idx . '-->';
					ASC_Plugin_Inspector::$shielded_blocks[ $token ] = $matches[0];
					return $token;
				},
				$content
			);
		}

		return $content;
	}

	/**
	 * Restore shielded HTML containers after Daextam_Autolink_Engine finishes.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function restore_sensitive_containers( $content ) {
		if ( empty( self::$shielded_blocks ) || ! is_string( $content ) ) {
			return $content;
		}
		$content               = strtr( $content, self::$shielded_blocks );
		self::$shielded_blocks = array();
		return $content;
	}

	/**
	 * Automatically apply optimal Autolinks Manager configuration & strategic links once upon v1.3.0 upgrade/activation.
	 */
	public static function maybe_auto_apply_on_boot() {
		if ( '1' === get_option( 'asc_autolinks_v130_auto_done', '0' ) ) {
			return;
		}

		// Verify Autolinks Manager is installed/configured on this site before running.
		$opts = self::get_autolinks_options();
		if ( empty( $opts ) && ! self::autolinks_table_exists() ) {
			return;
		}

		$result = self::auto_configure_autolinks_manager( 'ai:arena-auto-v130', true );
		if ( ! is_wp_error( $result ) ) {
			update_option( 'asc_autolinks_v130_auto_done', '1', false );
			update_option( 'asc_autolinks_last_auto_summary', $result, false );
			set_transient( 'asc_autolinks_auto_notice', $result, DAY_IN_SECONDS );
		}
	}

	/**
	 * Render admin confirmation banner when automatic configuration has just executed.
	 */
	public static function render_auto_applied_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['asc_dismiss_al_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( 'asc_autolinks_auto_notice' );
			return;
		}
		$summary = get_transient( 'asc_autolinks_auto_notice' );
		if ( ! is_array( $summary ) ) {
			return;
		}
		$dismiss_url = add_query_arg( 'asc_dismiss_al_notice', '1' );
		?>
		<div class="notice notice-success" style="border-right:4px solid #16a34a;padding:12px 16px;" dir="rtl">
			<p style="margin:0 0 6px;font-weight:700;font-size:14px;">
				✓ فرمانده هوشمند سایت (اجرای خودکار هوش مصنوعی): تنظیمات بهینه و ضدتداخل <code>Autolinks Manager</code> با موفقیت روی سایت اعمال شد!
			</p>
			<p style="margin:0;font-size:13px;color:#1e293b;">
				<?php echo esc_html( isset( $summary['message'] ) ? $summary['message'] : '' ); ?>
				— تمام تغییرات دارای نسخه پشتیبان در تب <a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-site-commander&tab=snapshots' ) ); ?>">پشتیبان‌ها و بازگشت (Rollback)</a> هستند.
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-right:12px;color:#64748b;">[بستن پیام]</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Return full list of installed and active plugins with metadata.
	 *
	 * @return array
	 */
	public static function get_installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$active_plugins = array_unique( array_merge( $active_plugins, $network_active ) );
		}

		$active_list   = array();
		$inactive_list = array();

		foreach ( $all_plugins as $file => $data ) {
			$item = array(
				'file'        => $file,
				'slug'        => dirname( $file ),
				'name'        => isset( $data['Name'] ) ? $data['Name'] : $file,
				'version'     => isset( $data['Version'] ) ? $data['Version'] : '',
				'author'      => isset( $data['AuthorName'] ) ? $data['AuthorName'] : ( isset( $data['Author'] ) ? wp_strip_all_tags( $data['Author'] ) : '' ),
				'description' => isset( $data['Description'] ) ? wp_strip_all_tags( $data['Description'] ) : '',
				'active'      => in_array( $file, $active_plugins, true ),
			);

			if ( $item['active'] ) {
				$active_list[] = $item;
			} else {
				$inactive_list[] = $item;
			}
		}

		return array(
			'active_count'   => count( $active_list ),
			'inactive_count' => count( $inactive_list ),
			'active'         => $active_list,
			'inactive'       => $inactive_list,
		);
	}

	/**
	 * Detect the Autolinks Manager DB table (`daextam_autolink` in v1.10+/v1.12 or legacy `daam_autolink`).
	 *
	 * @return string Empty string if not found.
	 */
	public static function get_autolinks_table_name() {
		global $wpdb;
		$candidates = array(
			$wpdb->prefix . 'daextam_autolink',
			$wpdb->prefix . 'daam_autolink',
			$wpdb->prefix . 'daam_autolinks',
		);
		foreach ( $candidates as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found === $table ) {
				return $table;
			}
		}
		return '';
	}

	/**
	 * Check whether Autolinks Manager table exists.
	 *
	 * @return bool
	 */
	public static function autolinks_table_exists() {
		return '' !== self::get_autolinks_table_name();
	}

	/**
	 * Read current Autolinks Manager (`daextam_*` and legacy `daam_*`) options from wp_options.
	 *
	 * @return array
	 */
	public static function get_autolinks_options() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE option_name LIKE 'daextam\\_%'
			    OR option_name LIKE 'daam\\_%'
			    OR option_name LIKE '%autolink%'
			 ORDER BY option_name ASC
			 LIMIT 250",
			ARRAY_A
		);

		$options = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$name = $row['option_name'];
				if ( 0 === strpos( $name, 'asc_autolinks_' ) ) {
					continue;
				}
				$options[ $name ] = maybe_unserialize( $row['option_value'] );
			}
		}
		return $options;
	}

	/**
	 * Read existing autolink rules from `wp_daextam_autolink`.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_autolinks_rules( $limit = 100 ) {
		global $wpdb;
		$table = self::get_autolinks_table_name();
		if ( '' === $table ) {
			return array();
		}
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$rows  = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY priority DESC, autolink_id ASC LIMIT {$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run full conflict analysis between active plugins and Autolinks Manager.
	 *
	 * @return array
	 */
	public static function audit_conflicts() {
		$plugins        = self::get_installed_plugins();
		$active         = $plugins['active'];
		$autolinks_opts = self::get_autolinks_options();
		$rules          = self::get_autolinks_rules( 100 );

		$warnings           = array();
		$autolinks_detected = null;
		$other_autolinkers  = array();
		$seo_plugins        = array();
		$cache_plugins      = array();
		$builder_plugins    = array();

		foreach ( $active as $p ) {
			$haystack = strtolower( $p['file'] . ' ' . $p['name'] . ' ' . $p['description'] );

			if ( false !== strpos( $haystack, 'daext-autolinks-manager' ) || false !== strpos( $haystack, 'autolinks manager' ) ) {
				$autolinks_detected = $p;
				continue;
			}

			if (
				false !== strpos( $haystack, 'link whisper' ) ||
				false !== strpos( $haystack, 'internal link juicer' ) ||
				false !== strpos( $haystack, 'wpil' ) ||
				false !== strpos( $haystack, 'seo smart links' ) ||
				false !== strpos( $haystack, 'taxopress' ) ||
				false !== strpos( $haystack, 'interlinks manager' )
			) {
				$other_autolinkers[] = $p['name'];
			}

			if ( false !== strpos( $haystack, 'rank-math' ) || false !== strpos( $haystack, 'wordpress-seo' ) || false !== strpos( $haystack, 'yoast' ) ) {
				$seo_plugins[] = $p['name'];
			}

			if (
				false !== strpos( $haystack, 'wp-rocket' ) ||
				false !== strpos( $haystack, 'litespeed' ) ||
				false !== strpos( $haystack, 'perfmatters' )
			) {
				$cache_plugins[] = $p['name'];
			}

			if (
				false !== strpos( $haystack, 'elementor' ) ||
				false !== strpos( $haystack, 'woodmart' ) ||
				false !== strpos( $haystack, 'easy-table-of-contents' ) ||
				false !== strpos( $haystack, 'wpml' )
			) {
				$builder_plugins[] = $p['name'];
			}
		}

		if ( ! empty( $other_autolinkers ) ) {
			$warnings[] = array(
				'severity' => 'critical',
				'type'     => 'duplicate_autolinker',
				'title'    => 'تداخل مستقیم با افزونه لینک‌ساز داخلی دیگر',
				'detail'   => sprintf(
					'افزونه‌های (%s) هم‌زمان با Autolinks Manager فعال هستند و باعث تودرتو شدن تگ <a> می‌شوند.',
					implode( '، ', $other_autolinkers )
				),
			);
		}

		// Verify live Daextam options whether any risky default is still active.
		$rand_prio = isset( $autolinks_opts['daextam_advanced_random_prioritization'] ) ? (string) $autolinks_opts['daextam_advanced_random_prioritization'] : '';
		$def_pt    = isset( $autolinks_opts['daextam_defaults_post_types'] ) ? $autolinks_opts['daextam_defaults_post_types'] : null;

		if ( '1' === $rand_prio ) {
			$warnings[] = array(
				'severity' => 'medium',
				'type'     => 'random_prioritization_cache',
				'title'    => 'گزینه Random Prioritization فعال است (تداخل با کش LiteSpeed)',
				'detail'   => 'فعال بودن اولویت تصادفی باعث تغییر لینک‌های داخلی در هر بار رفرش کش می‌شود و باید روی ۰ تنظیم گردد.',
			);
		}

		if ( ! is_array( $def_pt ) || in_array( 'page', $def_pt, true ) ) {
			$warnings[] = array(
				'severity' => 'medium',
				'type'     => 'unrestricted_post_types',
				'title'    => 'محدود نبودن Post Types پیش‌فرض به نوشته‌های وبلاگ (post)',
				'detail'   => 'برای جلوگیری از تزریق لینک در برگه‌های ووکامرس، الممنتور و بلاک‌های Woodmart، پست‌تایپ پیش‌فرض باید روی post تنظیم شود.',
			);
		}

		$warnings[] = array(
			'severity' => 'low',
			'type'     => 'runtime_shield_active',
			'title'    => 'سپر محافظ زنده فرمانده هوشمند فعال است',
			'detail'   => 'سبد خرید و تسویه‌حساب ووکامرس، بلاک‌های Woodmart، ویرایشگر المنتور، فهرست مطالب (Easy TOC) و فرم‌های گرویتی به صورت خودکار در برابر لینک‌سازی ناخواسته محافظت می‌شوند.',
		);

		return array(
			'autolinks_detected'   => $autolinks_detected,
			'active_plugins_count' => $plugins['active_count'],
			'active_plugins'       => $active,
			'inactive_plugins'     => $plugins['inactive'],
			'seo_plugins'          => $seo_plugins,
			'cache_plugins'        => $cache_plugins,
			'builder_plugins'      => $builder_plugins,
			'warnings'             => $warnings,
			'daam_options_found'   => count( $autolinks_opts ),
			'daam_options'         => $autolinks_opts,
			'autolinks_rules_count'=> count( $rules ),
			'autolinks_rules'      => $rules,
		);
	}

	/**
	 * Percent-encode any non-ASCII bytes in a URL path so it passes Daextam_Shared::$url_regex
	 * while remaining 100% valid for browsers, Googlebot, and WordPress.
	 *
	 * @param string $url Full URL.
	 * @return string
	 */
	private static function normalize_ascii_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		return preg_replace_callback(
			'/[^\x20-\x7E]/',
			function ( $m ) {
				return strtolower( rawurlencode( $m[0] ) );
			},
			$url
		);
	}

	/**
	 * Resolve a candidate product/category/post target on the live WordPress site so only real,
	 * published URLs on `volusx.com` are ever linked.
	 *
	 * @param array $spec Target specification.
	 * @return string Resolved ASCII-safe live permalink or empty string if not found.
	 */
	private static function resolve_live_target_url( array $spec ) {
		global $wpdb;

		// 1. Product category lookup by slug(s).
		if ( 'product_cat' === $spec['kind'] && taxonomy_exists( 'product_cat' ) ) {
			foreach ( (array) $spec['slugs'] as $slug ) {
				$term = get_term_by( 'slug', $slug, 'product_cat' );
				if ( ! $term ) {
					$encoded = strtolower( rawurlencode( $slug ) );
					$term    = get_term_by( 'slug', $encoded, 'product_cat' );
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$link = get_term_link( $term, 'product_cat' );
					if ( ! is_wp_error( $link ) && is_string( $link ) ) {
						return self::normalize_ascii_url( $link );
					}
				}
			}
		}

		// 2. Post / WooCommerce product lookup by exact path or slug fragment.
		if ( in_array( $spec['kind'], array( 'product', 'post' ), true ) ) {
			if ( ! empty( $spec['path'] ) ) {
				$candidate_url = home_url( $spec['path'] );
				$pid           = url_to_postid( $candidate_url );
				if ( $pid > 0 && 'publish' === get_post_status( $pid ) ) {
					$link = get_permalink( $pid );
					if ( is_string( $link ) && '' !== $link ) {
						return self::normalize_ascii_url( $link );
					}
				}
			}

			foreach ( (array) $spec['slugs'] as $slug_frag ) {
				$clean_frag = strtolower( rawurlencode( rawurldecode( $slug_frag ) ) );
				$like       = '%' . $wpdb->esc_like( $clean_frag ) . '%';
				$pid        = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						 WHERE post_type = %s
						   AND post_status = 'publish'
						   AND post_name LIKE %s
						 ORDER BY ID ASC
						 LIMIT 1",
						$spec['kind'],
						$like
					)
				);
				if ( $pid > 0 ) {
					$link = get_permalink( $pid );
					if ( is_string( $link ) && '' !== $link ) {
						// Verify English vs Persian path expectation if specified.
						if ( ! empty( $spec['lang'] ) && 'en' === $spec['lang'] && false === strpos( $link, '/en/' ) ) {
							// If WPML translated URL has /en/ prefix in path fallback, prefer WPML permalink if present.
							if ( ! empty( $spec['fallback_path'] ) ) {
								return self::normalize_ascii_url( home_url( $spec['fallback_path'] ) );
							}
						}
						return self::normalize_ascii_url( $link );
					}
				}
			}
		}

		// 3. Verified fallback path if site matches volusx.com domain.
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( false !== strpos( $host, 'volusx.com' ) && ! empty( $spec['fallback_path'] ) ) {
			return self::normalize_ascii_url( home_url( $spec['fallback_path'] ) );
		}

		return '';
	}

	/**
	 * Seed strategic internal SEO autolink rules into `wp_daextam_autolink` tailored for volusx.com's
	 * real Search Console queries, pillar categories, and money products (Persian + /en/ separated).
	 *
	 * Never overwrites or duplicates existing keywords. Records a Snapshot for 1-click Rollback.
	 *
	 * @param string $actor Actor label.
	 * @return array
	 */
	public static function seed_strategic_autolinks( $actor = 'ai-agent' ) {
		global $wpdb;

		$table = self::get_autolinks_table_name();
		if ( '' === $table ) {
			return array(
				'seeded_count' => 0,
				'inserted_ids' => array(),
				'note'         => 'جدول دیتابیس Autolinks Manager هنوز ایجاد نشده است.',
			);
		}

		$col_rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $col_rows ) || ! is_array( $col_rows ) ) {
			return array(
				'seeded_count' => 0,
				'inserted_ids' => array(),
			);
		}

		$available_cols = array();
		foreach ( $col_rows as $c ) {
			$available_cols[ $c['Field'] ] = true;
		}

		// Strategic keyword -> target map derived from volusx.com Search Console performance & coverage gaps.
		$blueprint = array(
			// Priority 20: High-intent Persian flagship products.
			array(
				'name'          => 'محصول: مزوژل ویتال میس فیل',
				'keyword'       => 'مزوژل ویتال',
				'title'         => 'خرید و قیمت مزوژل آبرسان ویتال میس فیل اصل کره',
				'priority'      => 20,
				'kind'          => 'product',
				'slugs'         => array( 'vital-misfill', 'مزوژل-آبرسان-ویتال' ),
				'fallback_path' => '/product/مزوژل-آبرسان-ویتال-میس-فیل-vital-misfill/',
			),
			array(
				'name'          => 'محصول: فیلر ولوس',
				'keyword'       => 'فیلر ولوس',
				'title'         => 'خرید فیلر ولوس اورجینال کره',
				'priority'      => 20,
				'kind'          => 'product',
				'slugs'         => array( 'فیلر-ولوس' ),
				'fallback_path' => '/product/فیلر-ولوس/',
			),
			array(
				'name'          => 'محصول: ژل ولوس',
				'keyword'       => 'ژل ولوس',
				'title'         => 'قیمت و مشخصات ژل ولوس اصل',
				'priority'      => 19,
				'kind'          => 'product',
				'slugs'         => array( 'فیلر-ولوس' ),
				'fallback_path' => '/product/فیلر-ولوس/',
			),
			array(
				'name'          => 'محصول: فیلر پلورول دیپ',
				'keyword'       => 'فیلر پلورول دیپ',
				'title'         => 'خرید فیلر پلورول دیپ Plurvel Deep',
				'priority'      => 20,
				'kind'          => 'product',
				'slugs'         => array( 'plurvel-deep', 'فیلر-پلورول-دیپ' ),
				'fallback_path' => '/product/فیلر-پلورول-دیپ-plurvel-deep/',
			),
			array(
				'name'          => 'محصول: فیلر پلورول لیپس',
				'keyword'       => 'فیلر پلورول لیپس',
				'title'         => 'خرید فیلر لب پلورول لیپس Plurvel Lips',
				'priority'      => 20,
				'kind'          => 'product',
				'slugs'         => array( 'plurvel-lips', 'فیلر-پلورول-لیپس' ),
				'fallback_path' => '/product/فیلر-پلورول-لیپس-plurvel-lips/',
			),
			array(
				'name'          => 'محصول: مزوژل رویتال',
				'keyword'       => 'مزوژل رویتال',
				'title'         => 'خرید مزوژل جوانساز رویتال Reyoungel Revital',
				'priority'      => 20,
				'kind'          => 'product',
				'slugs'         => array( 'reyoungel-revital' ),
				'fallback_path' => '/product/reyoungel-revital/',
			),
			array(
				'name'          => 'محصول: مزوژل ریوایت شاین',
				'keyword'       => 'مزوژل ریوایت شاین',
				'title'         => 'مزوژل ریوایت شاین PDRN',
				'priority'      => 20,
				'kind'          => 'product',
				'slugs'         => array( 'revite-shine-pdrn' ),
				'fallback_path' => '/product/revite-shine-pdrn/',
			),
			array(
				'name'          => 'محصول: فیلر ریوایت',
				'keyword'       => 'فیلر ریوایت',
				'title'         => 'خرید فیلر ریوایت سافت Revite Soft',
				'priority'      => 19,
				'kind'          => 'product',
				'slugs'         => array( 'revite-soft-1cc-filler' ),
				'fallback_path' => '/product/revite-soft-1cc-filler/',
			),
			array(
				'name'          => 'محصول: مزوژل هایومکس سوپر هایدرا',
				'keyword'       => 'مزوژل هایومکس',
				'title'         => 'مزوژل آبرسان هایومکس سوپر هایدرا',
				'priority'      => 19,
				'kind'          => 'product',
				'slugs'         => array( 'mesogel-hyamax-super-hydra' ),
				'fallback_path' => '/product/mesogel-hyamax-super-hydra/',
			),
			array(
				'name'          => 'محصول: فیلر ولومایزر هایومکس',
				'keyword'       => 'فیلر ولومایزر هایومکس',
				'title'         => 'فیلر ولومایزر هایومکس Hyamax Volumizer',
				'priority'      => 20,
				'kind'          => 'product',
				'slugs'         => array( 'hyamax-volumizer', 'فیلر-ولومایزر-هایومکس' ),
				'fallback_path' => '/product/فیلر-ولومایزر-هایومکس-hyamax-volumizer/',
			),
			array(
				'name'          => 'محصول: فیلر کانتور هایومکس',
				'keyword'       => 'فیلر کانتور هایومکس',
				'title'         => 'فیلر کانتور هایومکس Hyamax Contour',
				'priority'      => 20,
				'kind'          => 'product',
				'slugs'         => array( 'hyamax-contour', 'فیلر-کانتور-هایومکس' ),
				'fallback_path' => '/product/فیلر-کانتور-هایومکس-hyamax-contour/',
			),
			// Priority 10: Persian category pillar pages (boosting rankings from pos 30-48 into Top 10).
			array(
				'name'          => 'دسته مادر: خرید مزوژل',
				'keyword'       => 'خرید مزوژل',
				'title'         => 'لیست قیمت و خرید انواع مزوژل اورجینال',
				'priority'      => 12,
				'kind'          => 'product_cat',
				'slugs'         => array( 'مزوژل' ),
				'fallback_path' => '/product-category/مزوژل/',
			),
			array(
				'name'          => 'دسته مادر: انواع مزوژل',
				'keyword'       => 'انواع مزوژل',
				'title'         => 'مقایسه و قیمت انواع مزوژل جوانساز و آبرسان',
				'priority'      => 10,
				'kind'          => 'product_cat',
				'slugs'         => array( 'مزوژل' ),
				'fallback_path' => '/product-category/مزوژل/',
			),
			array(
				'name'          => 'دسته مادر: خرید فیلر',
				'keyword'       => 'خرید فیلر',
				'title'         => 'خرید انواع فیلر و ژل زیبایی اورجینال',
				'priority'      => 12,
				'kind'          => 'product_cat',
				'slugs'         => array( 'فیلر' ),
				'fallback_path' => '/product-category/فیلر/',
			),
			array(
				'name'          => 'دسته مادر: خرید بوتاکس',
				'keyword'       => 'خرید بوتاکس',
				'title'         => 'خرید و قیمت انواع بوتاکس اصل',
				'priority'      => 12,
				'kind'          => 'product_cat',
				'slugs'         => array( 'بوتاکس' ),
				'fallback_path' => '/product-category/بوتاکس/',
			),
			array(
				'name'          => 'دسته مادر: محصولات PDRN',
				'keyword'       => 'مزوژل PDRN',
				'title'         => 'محصولات جوانساز و ترمیم‌کننده PDRN',
				'priority'      => 14,
				'kind'          => 'product_cat',
				'slugs'         => array( 'pdrn' ),
				'fallback_path' => '/product-category/pdrn/',
			),
			// English (/en/) rules — strictly English phrases pointing strictly to /en/ URLs (WPML-safe).
			array(
				'name'          => 'EN Product: Dyston Botox 500U',
				'keyword'       => 'Dyston Botox',
				'title'         => 'Dyston 500 Units Botulinum Toxin Specifications & Price',
				'priority'      => 20,
				'kind'          => 'product',
				'lang'          => 'en',
				'slugs'         => array( 'botox-dyston-500-units' ),
				'fallback_path' => '/en/product/botox-dyston-500-units/',
			),
			array(
				'name'          => 'EN Product: Masport Botox 500U',
				'keyword'       => 'Masport Botox',
				'title'         => 'Masport 500 Units Botulinum Toxin',
				'priority'      => 20,
				'kind'          => 'product',
				'lang'          => 'en',
				'slugs'         => array( 'botox-masport-500-units' ),
				'fallback_path' => '/en/product/botox-masport-500-units/',
			),
			array(
				'name'          => 'EN Product: Nabota Botox 150U',
				'keyword'       => 'Nabota Botox',
				'title'         => 'Nabota 150 Units Botulinum Toxin',
				'priority'      => 20,
				'kind'          => 'product',
				'lang'          => 'en',
				'slugs'         => array( 'nabota-botox-150-units' ),
				'fallback_path' => '/en/product/nabota-botox-150-units/',
			),
			array(
				'name'          => 'EN Product: Volus 10cc Filler',
				'keyword'       => 'Volus Filler',
				'title'         => 'Volus 10cc Korean Hyaluronic Acid Body Filler',
				'priority'      => 18,
				'kind'          => 'product',
				'lang'          => 'en',
				'slugs'         => array( 'volus-filler-10cc-korea' ),
				'fallback_path' => '/en/product/volus-filler-10cc-korea/',
			),
		);

		$inserted_ids  = array();
		$inserted_rows = array();

		foreach ( $blueprint as $rule ) {
			// Skip if keyword already exists in Autolinks Manager.
			$exists_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT autolink_id FROM `{$table}` WHERE LOWER(keyword) = LOWER(%s) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rule['keyword']
				)
			);
			if ( $exists_id ) {
				continue;
			}

			$target_url = self::resolve_live_target_url( $rule );
			if ( '' === $target_url ) {
				continue;
			}

			$candidate_row = array(
				'name'                  => $rule['name'],
				'category_id'           => 0,
				'keyword'               => $rule['keyword'],
				'url'                   => $target_url,
				'title'                 => $rule['title'],
				'open_new_tab'          => 0,
				'use_nofollow'          => 0,
				'case_sensitive_search' => 0,
				'limit'                 => 1,
				'priority'              => (int) $rule['priority'],
				'left_boundary'         => 0, // 0 = \b Unicode word boundary in Daextam_Autolink_Engine.
				'right_boundary'        => 0, // 0 = \b Unicode word boundary in Daextam_Autolink_Engine.
				'keyword_before'        => '',
				'keyword_after'         => '',
				'post_types'            => serialize( array( 'post' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				'categories'            => '',
				'tags'                  => '',
				'term_group_id'         => 0,
			);

			$insert_data = array();
			foreach ( $candidate_row as $col => $val ) {
				if ( isset( $available_cols[ $col ] ) ) {
					$insert_data[ $col ] = $val;
				}
			}

			if ( empty( $insert_data['keyword'] ) || empty( $insert_data['url'] ) ) {
				continue;
			}

			$ok = $wpdb->insert( $table, $insert_data );
			if ( $ok && $wpdb->insert_id ) {
				$inserted_ids[]  = (int) $wpdb->insert_id;
				$inserted_rows[] = array(
					'autolink_id' => (int) $wpdb->insert_id,
					'keyword'     => $rule['keyword'],
					'url'         => rawurldecode( $target_url ),
					'priority'    => (int) $rule['priority'],
				);
			}
		}

		$snapshot_id = null;
		if ( ! empty( $inserted_ids ) ) {
			$snapshot_id = ASC_Snapshot::create(
				'seed_autolinks',
				'autolinks_seed',
				'volusx_seo_seed',
				sprintf( 'ایجاد خودکار %d قانون لینک داخلی هدفمند در Autolinks Manager', count( $inserted_ids ) ),
				array(
					'exists' => true,
					'table'  => $table,
				),
				$actor,
				'تزریق قوانین لینک‌سازی داخلی محصولات و دسته‌های کلیدی volusx.com بر اساس داده‌های سرچ کنسول'
			);
			ASC_Snapshot::record_after_state(
				$snapshot_id,
				array(
					'inserted_ids'  => $inserted_ids,
					'inserted_rows' => $inserted_rows,
				)
			);
		}

		return array(
			'seeded_count'  => count( $inserted_ids ),
			'inserted_ids'  => $inserted_ids,
			'inserted_rows' => $inserted_rows,
			'snapshot_id'   => $snapshot_id,
		);
	}

	/**
	 * Automatically apply optimal Persian/WPML/Woodmart/WooCommerce SEO & anti-conflict settings
	 * to Autolinks Manager (`daext-autolinks-manager` v1.12) and optionally seed strategic internal links.
	 *
	 * @param string $actor      Actor label.
	 * @param bool   $seed_links Also seed verified internal autolinks if not yet present.
	 * @return array|WP_Error
	 */
	public static function auto_configure_autolinks_manager( $actor = 'ai-agent', $seed_links = true ) {
		$protected_tags_required = array(
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'a', 'img', 'ul', 'ol', 'span',
			'pre', 'code', 'table', 'iframe', 'script',
			'style', 'blockquote', 'button', 'label',
			'input', 'textarea', 'select', 'form', 'svg',
		);

		// Exact Daextam_Shared v1.10-v1.12 option targets verified against source code:
		// - left_boundary / right_boundary = '0' (\b Unicode word boundary, NOT '1' literal space)
		// - defaults_post_types = array('post') (prevents autolinks in WooCommerce pages, Woodmart cms_block, Elementor)
		// - advanced_random_prioritization = '0' (deterministic output for LiteSpeed Cache)
		// - advanced_protect_attributes = '1' (protects PB SEO Friendly Images alt/title attributes)
		// - advanced_same_url_limit = '1' (max 1 link to same target URL per post)
		// - defaults_limit = '1' (max 1 replacement per keyword per post)
		// - advanced_general_limit_mode = '1', advanced_general_limit_amount = '4' (max 4 autolinks per post)
		$exact_daextam_targets = array(
			'daextam_defaults_open_new_tab'          => '0',
			'daextam_defaults_use_nofollow'          => '0',
			'daextam_defaults_post_types'            => array( 'post' ),
			'daextam_defaults_case_sensitive_search' => '0',
			'daextam_defaults_left_boundary'         => '0',
			'daextam_defaults_right_boundary'        => '0',
			'daextam_defaults_limit'                 => '1',
			'daextam_defaults_priority'              => '10',
			'daextam_analysis_post_types'            => array( 'post' ),
			'daextam_advanced_enable_autolinks'      => '1',
			'daextam_advanced_filter_priority'       => '2147483646',
			'daextam_advanced_enable_test_mode'      => '0',
			'daextam_advanced_random_prioritization' => '0',
			'daextam_advanced_ignore_self_autolinks' => '1',
			'daextam_advanced_general_limit_mode'    => '1',
			'daextam_advanced_general_limit_amount'  => '4',
			'daextam_advanced_same_url_limit'        => '1',
			'daextam_advanced_protect_attributes'    => '1',
		);

		$changes_applied = array();

		// Apply exact `daextam_*` options if Autolinks Manager v1.10+ / v1.12 is installed.
		$has_daextam = ( false !== get_option( 'daextam_options_version', false ) ) || self::autolinks_table_exists();

		if ( $has_daextam ) {
			foreach ( $exact_daextam_targets as $opt_name => $target_val ) {
				$cur_val = get_option( $opt_name, null );
				if ( $cur_val !== $target_val ) {
					$snap_id = ASC_Snapshot::create(
						'configure_autolinks',
						'option',
						$opt_name,
						'تنظیم Autolinks Manager: ' . $opt_name,
						array(
							'exists' => ( null !== $cur_val ),
							'value'  => $cur_val,
						),
						$actor,
						'بهینه‌سازی خودکار سئو و جلوگیری از تداخل در Autolinks Manager v1.12'
					);

					update_option( $opt_name, $target_val );

					ASC_Snapshot::record_after_state(
						$snap_id,
						array(
							'exists' => true,
							'value'  => $target_val,
						)
					);

					$changes_applied[] = array(
						'option'      => $opt_name,
						'old_value'   => $cur_val,
						'new_value'   => $target_val,
						'snapshot_id' => $snap_id,
					);
				}
			}

			// Merge protected HTML tags cleanly without losing any custom tags the user added.
			$cur_tags = get_option( 'daextam_advanced_protected_tags', array() );
			$cur_arr  = is_array( $cur_tags ) ? $cur_tags : array();
			$new_tags = array_values( array_unique( array_merge( $cur_arr, $protected_tags_required ) ) );
			if ( $new_tags !== $cur_tags ) {
				$snap_id = ASC_Snapshot::create(
					'configure_autolinks',
					'option',
					'daextam_advanced_protected_tags',
					'محافظت از تگ‌های حساس در Autolinks Manager',
					array(
						'exists' => ( false !== get_option( 'daextam_advanced_protected_tags', false ) ),
						'value'  => $cur_tags,
					),
					$actor,
					'محافظت از هدینگ‌ها، فهرست مطالب، تصاویر، فرم‌ها و دکمه‌ها در برابر لینک‌سازی خودکار'
				);
				update_option( 'daextam_advanced_protected_tags', $new_tags );
				ASC_Snapshot::record_after_state(
					$snap_id,
					array(
						'exists' => true,
						'value'  => $new_tags,
					)
				);
				$changes_applied[] = array(
					'option'      => 'daextam_advanced_protected_tags',
					'old_value'   => $cur_tags,
					'new_value'   => $new_tags,
					'snapshot_id' => $snap_id,
				);
			}
		}

		// Also tighten any existing autolink rows that left `post_types` blank (which otherwise target ALL post types).
		global $wpdb;
		$table = self::get_autolinks_table_name();
		$restricted_existing_rows = 0;
		if ( '' !== $table ) {
			$blank_rows = $wpdb->get_results(
				"SELECT autolink_id, post_types FROM `{$table}` WHERE post_types = '' OR post_types IS NULL LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			if ( ! empty( $blank_rows ) ) {
				$post_only_serialized = serialize( array( 'post' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				foreach ( $blank_rows as $br ) {
					$wpdb->update(
						$table,
						array( 'post_types' => $post_only_serialized ),
						array( 'autolink_id' => (int) $br['autolink_id'] ),
						array( '%s' ),
						array( '%d' )
					);
					++$restricted_existing_rows;
				}
			}
		}

		$seed_result = array(
			'seeded_count'  => 0,
			'inserted_rows' => array(),
		);
		if ( $seed_links ) {
			$seed_result = self::seed_strategic_autolinks( $actor );
		}

		ASC_Engine::purge_site_caches();

		$summary_msg = sprintf(
			'%d گزینه هسته Autolinks Manager بهینه‌سازی شد، %d قانون لینک داخلی هدفمند سئو ایجاد گردید و کش لایت‌اسپید پاک‌سازی شد.',
			count( $changes_applied ),
			(int) $seed_result['seeded_count']
		);

		ASC_DB::log_audit(
			$actor,
			'configure_autolinks_manager',
			'Autolinks Manager v1.12',
			'success',
			$summary_msg
		);

		return array(
			'configured'               => true,
			'updated_count'            => count( $changes_applied ),
			'changes'                  => $changes_applied,
			'restricted_existing_rows' => $restricted_existing_rows,
			'seeded_links_count'       => (int) $seed_result['seeded_count'],
			'seeded_links'             => isset( $seed_result['inserted_rows'] ) ? $seed_result['inserted_rows'] : array(),
			'message'                  => $summary_msg,
		);
	}
}
