<?php
/**
 * Comprehensive Google Search Console Indexing & Technical SEO Remediator for Volusx.
 *
 * Automatically diagnoses and fixes all 9 categories of non-indexed pages reported
 * in Google Search Console (175 non-indexed pages across Discovered-not-indexed,
 * Crawled-not-indexed, Excluded by noindex, Blocked by robots.txt, Duplicate canonicals,
 * Redirect chains, 404 Not Found, 5xx Server Errors, plus 46 Product Schema errors
 * and 66 Mobile LCP > 4s pages), with full Snapshot & 1-click Rollback protection.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Indexing_Fixer {

	const REMEDIATION_VERSION = '1.4.0-volusx-gsc';

	/**
	 * Track whether the first hero image on the current request was already boosted for LCP.
	 *
	 * @var bool
	 */
	private static $lcp_boosted = false;

	/**
	 * Cached active redirect source paths for fast XML sitemap filtering.
	 *
	 * @var array|null
	 */
	private static $redirect_sources_cache = null;

	/**
	 * Register all runtime indexing shields and boot-time automated DB remediation.
	 */
	public static function init() {
		// 0. 5xx Frontend Memory Floor & Fatal Shutdown Forensic Logger.
		add_action( 'template_redirect', array( __CLASS__, 'guard_frontend_5xx' ), -10 );

		// 1. Dynamic IndexNow key verification endpoint (`/{key}.txt`).
		add_action( 'init', array( __CLASS__, 'maybe_serve_indexnow_key_file' ), 1 );

		// 2. Boot-time automated database remediation (runs once per version with Snapshots).
		add_action( 'init', array( __CLASS__, 'maybe_boot_remediate' ), 26 );

		// 3. Single-hop HTTPS enforcement + pure tracking query-param canonicalization.
		add_action( 'template_redirect', array( __CLASS__, 'force_https_and_clean_tracking_urls' ), 0 );

		// 4. Robots meta shields (`Excluded by noindex` fix + facet/account `noindex` shield).
		add_filter( 'wp_robots', array( __CLASS__, 'filter_wp_robots' ), 999 );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'filter_rank_math_robots' ), 999 );

		// 5. Canonical shields (`Duplicate canonical` + facet query stripping).
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'filter_canonical_url' ), 999 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'filter_wp_canonical_url' ), 999, 2 );

		// 6. robots.txt shield (`Blocked by robots.txt` fix for /en/, uploads, admin-ajax + trap disallows).
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 99999, 2 );

		// 7. XML Sitemap hygiene (strip Woodmart/Elementor CPTs, account/cart, and redirected URLs).
		add_filter( 'rank_math/sitemap/exclude_post_type', array( __CLASS__, 'filter_rank_math_excluded_post_types' ), 99, 2 );
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'filter_rank_math_sitemap_entry' ), 99, 3 );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'filter_wp_sitemaps_post_types' ), 99 );

		// 8. Product Snippets (46 errors) & Merchant Listings (`Item name = N/A`) JSON-LD repair.
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'repair_rank_math_json_ld' ), 99, 2 );
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'repair_wc_product_schema' ), 99, 2 );

		// 9. Mobile LCP < 2.5s accelerator (preload hero image + eager/fetchpriority="high").
		add_action( 'wp_head', array( __CLASS__, 'preload_lcp_hero_image' ), 1 );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'boost_lcp_image_attributes' ), 99, 3 );
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( __CLASS__, 'boost_wc_hero_image_html' ), 99, 2 );

		// 10. Contextual Internal Crawl Mesh (eliminates orphan pages across FA & /en/).
		add_filter( 'the_content', array( __CLASS__, 'append_contextual_crawl_mesh' ), 25 );
	}

	/**
	 * Determine whether a URL or path belongs to the English `/en/` tree.
	 *
	 * @param string $url_or_path Full URL or relative path.
	 * @return bool
	 */
	public static function is_english_url( $url_or_path ) {
		$path = ASC_Redirect_404::normalize_path( $url_or_path );
		return ( '/en' === $path || 0 === strpos( $path, '/en/' ) );
	}

	/**
	 * Detect whether the current request has WooCommerce/Woodmart facet or filter query parameters.
	 *
	 * @return bool
	 */
	public static function is_facet_or_filter_request() {
		if ( empty( $_GET ) || ! is_array( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		$facet_keys = array(
			'per_page',
			'orderby',
			'min_price',
			'max_price',
			'rating_filter',
			'shop_view',
			'add-to-cart',
			'add_to_wishlist',
			'remove_from_wishlist',
			'woodmart_ajax',
			'stock_status',
			'on_sale',
		);
		foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$k = strtolower( (string) $key );
			if ( in_array( $k, $facet_keys, true ) || 0 === strpos( $k, 'filter_' ) || 0 === strpos( $k, 'query_type_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect whether a relative path or current query is a non-SEO transactional/account endpoint.
	 *
	 * @param string|null $path Optional path to test.
	 * @return bool
	 */
	public static function is_transactional_endpoint( $path = null ) {
		if ( null === $path ) {
			if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
				return true;
			}
			if ( is_search() ) {
				return true;
			}
			$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$path    = ASC_Redirect_404::normalize_path( $req_uri );
		} else {
			$path = ASC_Redirect_404::normalize_path( $path );
		}

		$transactional_paths = array(
			'/cart',
			'/en/cart',
			'/checkout',
			'/en/checkout',
			'/my-account',
			'/en/my-account',
			'/account',
			'/en/account',
			'/wishlist',
			'/en/wishlist',
			'/compare',
			'/en/compare',
		);

		foreach ( $transactional_paths as $tp ) {
			if ( $path === $tp || 0 === strpos( $path, $tp . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Guard frontend requests against memory exhaustion 500s and log fatal PHP 5xx errors with exact URL.
	 */
	public static function guard_frontend_5xx() {
		if ( is_admin() ) {
			return;
		}
		if ( function_exists( 'wp_raise_memory_limit' ) && is_singular( 'product' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		register_shutdown_function(
			function () {
				$err = error_get_last();
				if ( ! is_array( $err ) ) {
					return;
				}
				$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
				if ( ! in_array( (int) $err['type'], $fatal_types, true ) ) {
					return;
				}
				$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$logs    = (array) get_option( 'asc_5xx_error_log', array() );
				array_unshift(
					$logs,
					array(
						'url'        => home_url( $req_uri ),
						'message'    => isset( $err['message'] ) ? mb_substr( (string) $err['message'], 0, 400 ) : '',
						'file'       => isset( $err['file'] ) ? (string) $err['file'] : '',
						'line'       => isset( $err['line'] ) ? (int) $err['line'] : 0,
						'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 180 ) : '',
						'created_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				update_option( 'asc_5xx_error_log', array_slice( $logs, 0, 25 ), false );
			}
		);
	}

	/**
	 * Return (or generate) the site's persistent IndexNow API key.
	 *
	 * @return string
	 */
	public static function get_indexnow_key() {
		$key = (string) get_option( 'asc_indexnow_key', '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$key = md5( home_url( '/' ) . '|' . wp_salt( 'auth' ) . '|volusx-indexnow' );
			update_option( 'asc_indexnow_key', $key, false );
		}
		return $key;
	}

	/**
	 * Dynamically serve `/{indexnow_key}.txt` for instant search engine ownership verification.
	 */
	public static function maybe_serve_indexnow_key_file() {
		if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$req_path = ASC_Redirect_404::normalize_path( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$key      = self::get_indexnow_key();
		if ( '/' . $key . '.txt' === $req_path ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			echo esc_html( $key );
			exit;
		}
	}

	/**
	 * Detect whether current request arrived over HTTPS (including Cloudflare / LiteSpeed / Nginx proxies).
	 *
	 * @return bool
	 */
	private static function is_request_https() {
		if ( is_ssl() ) {
			return true;
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) )[0] ) ) ) {
			return true;
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) ) ) {
			return true;
		}
		if ( ! empty( $_SERVER['HTTP_CF_VISITOR'] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_VISITOR'] ) ), 'https' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Enforce single-hop HTTPS (`http://volusx.com/en/shop/` fix) and strip pure tracking query parameters.
	 */
	public static function force_https_and_clean_tracking_urls() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		if ( '' === $host || false !== strpos( $host, 'localhost' ) || false !== strpos( $host, '127.0.0.1' ) || false !== strpos( $host, '.local' ) ) {
			return;
		}

		$needs_https    = ( 0 === strpos( home_url(), 'https://' ) ) && ! self::is_request_https();
		$tracking_keys  = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'yclid', 'msclkid', '_gl' );
		$found_tracking = array();

		if ( ! empty( $_GET ) && is_array( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( array_keys( $_GET ) as $gk ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( in_array( strtolower( (string) $gk ), $tracking_keys, true ) ) {
					$found_tracking[] = $gk;
				}
			}
		}

		if ( ! $needs_https && empty( $found_tracking ) ) {
			return;
		}

		$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$target  = 'https://' . $host . $req_uri;
		if ( ! empty( $found_tracking ) ) {
			$target = remove_query_arg( $found_tracking, $target );
		}

		$encoded_target = esc_url_raw( ASC_Redirect_404::encode_url_utf8( $target ) );
		if ( '' !== $encoded_target ) {
			wp_redirect( $encoded_target, 301 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
	}

	/**
	 * Check whether current archive is the thin blog `/category/فیلر/` competing with WooCommerce `/product-category/فیلر/`.
	 *
	 * @return bool
	 */
	private static function is_competing_blog_filler_category() {
		if ( ! is_category() || ! taxonomy_exists( 'product_cat' ) ) {
			return false;
		}
		$cat = get_queried_object();
		if ( ! ( $cat instanceof WP_Term ) ) {
			return false;
		}
		$decoded_slug = rawurldecode( (string) $cat->slug );
		if ( 'فیلر' === $decoded_slug || 'filler' === strtolower( $decoded_slug ) ) {
			$prod_term = get_term_by( 'slug', $cat->slug, 'product_cat' );
			if ( ! $prod_term ) {
				$prod_term = get_term_by( 'name', 'فیلر', 'product_cat' );
			}
			return ( $prod_term instanceof WP_Term );
		}
		return false;
	}

	/**
	 * Filter core `wp_robots` array to rescue accidentally noindexed money pages and block crawl traps.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function filter_wp_robots( $robots ) {
		if ( ! is_array( $robots ) ) {
			$robots = array();
		}

		if ( self::is_transactional_endpoint() || self::is_facet_or_filter_request() || self::is_competing_blog_filler_category() || is_404() ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
			return $robots;
		}

		$is_money_view = is_front_page()
			|| is_home()
			|| is_singular( array( 'product', 'post' ) )
			|| ( is_singular( 'page' ) && ! self::is_transactional_endpoint() )
			|| is_tax( 'product_cat' )
			|| ( function_exists( 'is_shop' ) && is_shop() && ! is_paged() );

		if ( $is_money_view ) {
			unset( $robots['noindex'], $robots['none'] );
			$robots['index']             = true;
			$robots['follow']            = true;
			$robots['max-image-preview'] = 'large';
			$robots['max-snippet']       = '-1';
		}

		return $robots;
	}

	/**
	 * Filter Rank Math `rank_math/frontend/robots` array identically so Rank Math outputs clean robots meta.
	 *
	 * @param array $robots Rank Math robots array (e.g. ['index' => 'index', 'follow' => 'follow']).
	 * @return array
	 */
	public static function filter_rank_math_robots( $robots ) {
		if ( ! is_array( $robots ) ) {
			$robots = array();
		}

		if ( self::is_transactional_endpoint() || self::is_facet_or_filter_request() || self::is_competing_blog_filler_category() || is_404() ) {
			unset( $robots['index'] );
			$robots['noindex'] = 'noindex';
			$robots['follow']  = 'follow';
			return $robots;
		}

		$is_money_view = is_front_page()
			|| is_home()
			|| is_singular( array( 'product', 'post' ) )
			|| ( is_singular( 'page' ) && ! self::is_transactional_endpoint() )
			|| is_tax( 'product_cat' )
			|| ( function_exists( 'is_shop' ) && is_shop() && ! is_paged() );

		if ( $is_money_view ) {
			unset( $robots['noindex'] );
			$robots['index']             = 'index';
			$robots['follow']            = 'follow';
			$robots['max-image-preview'] = 'max-image-preview:large';
			$robots['max-snippet']       = 'max-snippet:-1';
		}

		return $robots;
	}

	/**
	 * Clean canonical URLs by stripping facet/filter/tracking query args and forcing https://.
	 *
	 * @param string $canonical Current canonical URL.
	 * @return string
	 */
	public static function filter_canonical_url( $canonical ) {
		if ( ! is_string( $canonical ) || '' === trim( $canonical ) ) {
			return $canonical;
		}

		// Point competing blog `/category/فیلر/` canonical to commercial `/product-category/فیلر/`.
		if ( self::is_competing_blog_filler_category() ) {
			$prod_term = get_term_by( 'name', 'فیلر', 'product_cat' );
			if ( $prod_term instanceof WP_Term ) {
				$tlink = get_term_link( $prod_term );
				if ( ! is_wp_error( $tlink ) ) {
					$canonical = $tlink;
				}
			}
		}

		if ( 0 === strpos( home_url(), 'https://' ) ) {
			$canonical = preg_replace( '#^http://#i', 'https://', $canonical );
		}

		// Strip any query string from canonical URLs on facet/filter/shop/product/category pages.
		$qpos = strpos( $canonical, '?' );
		if ( false !== $qpos ) {
			$canonical = substr( $canonical, 0, $qpos );
		}

		return $canonical;
	}

	/**
	 * Core `get_canonical_url` filter wrapper.
	 *
	 * @param string  $canonical Canonical URL.
	 * @param WP_Post $post      Post object.
	 * @return string
	 */
	public static function filter_wp_canonical_url( $canonical, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return self::filter_canonical_url( $canonical );
	}

	/**
	 * Repair `robots.txt` (`Blocked by robots.txt` on 7 URLs + crawl-budget trap blocking).
	 *
	 * @param string $output Existing robots.txt content.
	 * @param bool   $public Whether site is public.
	 * @return string
	 */
	public static function filter_robots_txt( $output, $public ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$lines     = preg_split( '/\r\n|\r|\n/', (string) $output );
		$filtered  = array();
		$bad_rules = array(
			'disallow: /en',
			'disallow: /en/',
			'disallow: /wp-content/uploads',
			'disallow: /wp-content/uploads/',
			'disallow: /wp-admin/admin-ajax.php',
		);

		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				$norm = strtolower( trim( $line ) );
				if ( in_array( $norm, $bad_rules, true ) ) {
					continue;
				}
				// Strip full site block (`Disallow: /`) if accidentally left over while blog_public is on.
				if ( 'disallow: /' === $norm && '0' !== (string) get_option( 'blog_public' ) ) {
					continue;
				}
				$filtered[] = $line;
			}
		}

		$clean = trim( implode( "\n", $filtered ) );
		if ( '' === $clean || false === stripos( $clean, 'user-agent:' ) ) {
			$clean = "User-agent: *\nDisallow: /wp-admin/";
		}

		$required_allows = array(
			'Allow: /wp-admin/admin-ajax.php',
			'Allow: /wp-content/uploads/',
			'Allow: /en/',
			'Allow: /.well-known/',
		);
		foreach ( $required_allows as $rule ) {
			if ( false === stripos( $clean, $rule ) ) {
				$clean .= "\n" . $rule;
			}
		}

		$trap_disallows = array(
			'Disallow: /*?add-to-cart=',
			'Disallow: /*?per_page=',
			'Disallow: /*?orderby=',
			'Disallow: /*?filter_',
			'Disallow: /*?query_type_',
			'Disallow: /*?min_price=',
			'Disallow: /*?max_price=',
			'Disallow: /*?shop_view=',
			'Disallow: /cart/',
			'Disallow: /checkout/',
			'Disallow: /my-account/',
			'Disallow: /en/account/',
			'Disallow: /*/feed/',
		);
		foreach ( $trap_disallows as $drule ) {
			if ( false === stripos( $clean, $drule ) ) {
				$clean .= "\n" . $drule;
			}
		}

		$sitemap_url = home_url( '/sitemap_index.xml' );
		if ( false === stripos( $clean, 'sitemap:' ) ) {
			$clean .= "\n\nSitemap: " . $sitemap_url;
		}

		return $clean . "\n";
	}

	/**
	 * Internal Woodmart / Elementor CPTs that waste crawl budget and must never enter XML sitemaps.
	 *
	 * @return array
	 */
	private static function junk_sitemap_post_types() {
		return array(
			'cms_block',
			'woodmart_sidebar',
			'woodmart_slide',
			'woodmart_layout',
			'woodmart_size_guide',
			'woodmart_woo_lv',
			'woodmart_woo_fbt',
			'woodmart_portfolio',
			'elementor_library',
			'e-landing-page',
		);
	}

	/**
	 * Exclude internal theme/builder CPTs from Rank Math XML sitemaps.
	 *
	 * @param bool   $exclude   Current exclusion flag.
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function filter_rank_math_excluded_post_types( $exclude, $post_type ) {
		if ( in_array( (string) $post_type, self::junk_sitemap_post_types(), true ) ) {
			return true;
		}
		return $exclude;
	}

	/**
	 * Exclude internal theme/builder CPTs from core WordPress sitemaps.
	 *
	 * @param array $post_types Post types array.
	 * @return array
	 */
	public static function filter_wp_sitemaps_post_types( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}
		foreach ( self::junk_sitemap_post_types() as $junk ) {
			unset( $post_types[ $junk ] );
		}
		return $post_types;
	}

	/**
	 * Exclude transactional/account URLs and actively redirected source paths from Rank Math XML sitemaps.
	 *
	 * @param array|false $url    Sitemap entry array (`['loc' => ...]`).
	 * @param string      $type   Sitemap type (`post`, `term`, etc.).
	 * @param object      $object WP_Post or WP_Term object.
	 * @return array|false
	 */
	public static function filter_rank_math_sitemap_entry( $url, $type, $object ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( empty( $url ) || ! is_array( $url ) || empty( $url['loc'] ) ) {
			return $url;
		}

		$path = ASC_Redirect_404::normalize_path( (string) $url['loc'] );
		if ( self::is_transactional_endpoint( $path ) ) {
			return false;
		}

		// Exclude competing blog `/category/فیلر/` from XML sitemap.
		if ( 0 === strpos( $path, '/category/' ) && ( '/category/فیلر' === $path || '/category/filler' === $path ) ) {
			return false;
		}

		if ( null === self::$redirect_sources_cache ) {
			global $wpdb;
			$rtable = ASC_DB::table( 'redirects' );
			$rows   = $wpdb->get_col( "SELECT source_path FROM {$rtable} WHERE is_active = 1 AND is_regex = 0 LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$map    = array();
			if ( is_array( $rows ) ) {
				foreach ( $rows as $src ) {
					$map[ ASC_Redirect_404::normalize_path( $src ) ] = true;
				}
			}
			self::$redirect_sources_cache = $map;
		}

		if ( isset( self::$redirect_sources_cache[ $path ] ) ) {
			return false;
		}

		return $url;
	}

	/**
	 * Infer accurate aesthetic brand name for a WooCommerce product from its title/slug/terms.
	 *
	 * @param WC_Product $product Product object.
	 * @return string
	 */
	private static function infer_product_brand( $product ) {
		$haystack = strtolower( $product->get_name() . ' ' . rawurldecode( $product->get_slug() ) );
		$brands   = array(
			'Hyomax'    => array( 'hyomax', 'hyamax', 'هایومکس', 'هایامکس' ),
			'Toskani'   => array( 'toskani', 'توسکانی' ),
			'Reyoungel' => array( 'reyoungel', 'ریونجل' ),
			'Plurvel'   => array( 'plurvel', 'پلورول' ),
			'Volus'     => array( 'volus', 'ولوس' ),
			'Masport'   => array( 'masport', 'مصپورت', 'ماسپورت' ),
			'Dyston'    => array( 'dyston', 'دیستون' ),
			'Nabota'    => array( 'nabota', 'نابوتا' ),
			'Revofil'   => array( 'revofil', 'رووفیل' ),
			'Dermaheal' => array( 'dermaheal', 'dermahil', 'درماهیل' ),
			'Revite'    => array( 'revite', 'ریوایت' ),
			'Sedyfill'  => array( 'sedyfill', 'سدیفیل' ),
			'Bella'     => array( 'bella', 'بلا' ),
			'Fillsys'   => array( 'fillsys', 'فیلسیس' ),
			'Vital'     => array( 'vital', 'misfill', 'ویتال' ),
		);

		foreach ( $brands as $brand_name => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== mb_strpos( $haystack, $needle ) ) {
					return $brand_name;
				}
			}
		}

		return 'Volusx';
	}

	/**
	 * Compute ISO-4217 currency (`IRR`) and normalized numeric min/max prices for a WooCommerce product.
	 *
	 * @param WC_Product $product Product object.
	 * @return array { currency: string, min_price: float, max_price: float, is_variable: bool }
	 */
	private static function extract_normalized_product_pricing( $product ) {
		$raw_currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( trim( (string) get_woocommerce_currency() ) ) : 'IRR';
		$multiplier   = 1.0;

		if ( in_array( $raw_currency, array( 'IRT', 'TOMAN', 'تومان' ), true ) ) {
			$currency   = 'IRR';
			$multiplier = 10.0;
		} elseif ( in_array( $raw_currency, array( 'IRR', 'ریال', 'RIAL' ), true ) || ! preg_match( '/^[A-Z]{3}$/', $raw_currency ) ) {
			$currency = 'IRR';
		} else {
			$currency = $raw_currency;
		}

		$is_variable = $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' );
		if ( $is_variable ) {
			$min_raw = (float) $product->get_variation_price( 'min', true );
			$max_raw = (float) $product->get_variation_price( 'max', true );
		} else {
			$p_raw   = $product->get_price();
			$reg_raw = $product->get_regular_price();
			$val     = '' !== (string) $p_raw ? (float) $p_raw : ( '' !== (string) $reg_raw ? (float) $reg_raw : 0.0 );
			$min_raw = $val;
			$max_raw = $val;
		}

		return array(
			'currency'    => $currency,
			'min_price'   => round( max( 0.0, $min_raw * $multiplier ) ),
			'max_price'   => round( max( 0.0, $max_raw * $multiplier ) ),
			'is_variable' => $is_variable,
		);
	}

	/**
	 * Enrich a single Product schema array (or return null if unpriced + unreviewed to prevent GSC critical errors).
	 *
	 * @param array      $node    Product JSON-LD node.
	 * @param WC_Product $product WooCommerce product.
	 * @return array|null
	 */
	private static function enrich_product_schema_node( array $node, $product ) {
		$pricing      = self::extract_normalized_product_pricing( $product );
		$review_count = (int) $product->get_review_count();
		$avg_rating   = (float) $product->get_average_rating();

		// Strip invalid aggregateRating when reviewCount <= 0.
		if ( $review_count <= 0 || $avg_rating <= 0 ) {
			unset( $node['aggregateRating'], $node['review'] );
		}

		// Google Structured Data compliance: if a Call-for-Price product has NEITHER price NOR reviews,
		// omit the incomplete `Product` rich-result node so GSC reports 0 Critical Errors without fake data.
		if ( $pricing['min_price'] <= 0 && $review_count <= 0 ) {
			return null;
		}

		$permalink = get_permalink( $product->get_id() );
		$title     = trim( wp_strip_all_tags( $product->get_name() ) );
		$desc_raw  = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
		$desc      = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $desc_raw ) ) );
		if ( '' === $desc ) {
			$desc = $title;
		}

		$node['@type']       = 'Product';
		$node['name']        = $title;
		$node['description'] = mb_substr( $desc, 0, 500 );
		$node['sku']         = $product->get_sku() ? (string) $product->get_sku() : 'VX-' . $product->get_id();
		$node['url']         = $permalink;
		$node['brand']       = array(
			'@type' => 'Brand',
			'name'  => self::infer_product_brand( $product ),
		);

		$img_id = $product->get_image_id();
		if ( $img_id ) {
			$img_url = wp_get_attachment_image_url( $img_id, 'full' );
			if ( $img_url ) {
				$node['image'] = array( $img_url );
			}
		}

		if ( $pricing['min_price'] > 0 ) {
			$availability = $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
			$valid_until  = gmdate( 'Y-m-d', strtotime( '+365 days' ) );

			if ( $pricing['is_variable'] && $pricing['max_price'] > $pricing['min_price'] ) {
				$node['offers'] = array(
					'@type'           => 'AggregateOffer',
					'priceCurrency'   => $pricing['currency'],
					'lowPrice'        => (string) $pricing['min_price'],
					'highPrice'       => (string) $pricing['max_price'],
					'offerCount'      => max( 1, count( $product->get_children() ) ),
					'availability'    => $availability,
					'url'             => $permalink,
					'priceValidUntil' => $valid_until,
				);
			} else {
				$node['offers'] = array(
					'@type'           => 'Offer',
					'priceCurrency'   => $pricing['currency'],
					'price'           => (string) $pricing['min_price'],
					'priceValidUntil' => $valid_until,
					'availability'    => $availability,
					'itemCondition'   => 'https://schema.org/NewCondition',
					'url'             => $permalink,
				);
			}
		}

		return $node;
	}

	/**
	 * Repair Rank Math JSON-LD graph on singular WooCommerce products (`Product snippets` + `Merchant listings`).
	 *
	 * @param array $data   Rank Math JSON-LD entities.
	 * @param mixed $jsonld Rank Math JsonLD instance.
	 * @return array
	 */
	public static function repair_rank_math_json_ld( $data, $jsonld = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $data ) || ! is_singular( 'product' ) || ! function_exists( 'wc_get_product' ) ) {
			return $data;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) {
			return $data;
		}

		foreach ( $data as $key => $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$type = isset( $entity['@type'] ) ? $entity['@type'] : '';
			$is_product = ( is_string( $type ) && 'Product' === $type )
				|| ( is_array( $type ) && in_array( 'Product', $type, true ) );

			if ( $is_product ) {
				$repaired = self::enrich_product_schema_node( $entity, $product );
				if ( null === $repaired ) {
					unset( $data[ $key ] );
				} else {
					$data[ $key ] = $repaired;
				}
			}
		}

		return $data;
	}

	/**
	 * Repair core WooCommerce `woocommerce_structured_data_product` array when Rank Math delegates or isn't overriding.
	 *
	 * @param array      $markup  Product schema markup.
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	public static function repair_wc_product_schema( $markup, $product ) {
		if ( ! is_array( $markup ) || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $markup;
		}
		$repaired = self::enrich_product_schema_node( $markup, $product );
		return null === $repaired ? array() : $repaired;
	}

	/**
	 * Preload the primary singular hero image in `<head>` to improve Mobile LCP (< 2.5s target).
	 */
	public static function preload_lcp_hero_image() {
		if ( ! is_singular( array( 'product', 'post' ) ) ) {
			return;
		}
		$thumb_id = get_post_thumbnail_id( get_queried_object_id() );
		if ( ! $thumb_id ) {
			return;
		}
		$src = wp_get_attachment_image_url( $thumb_id, 'woocommerce_single' );
		if ( ! $src ) {
			$src = wp_get_attachment_image_url( $thumb_id, 'large' );
		}
		if ( ! $src ) {
			return;
		}
		$srcset = wp_get_attachment_image_srcset( $thumb_id, 'woocommerce_single' );
		$sizes  = wp_get_attachment_image_sizes( $thumb_id, 'woocommerce_single' );

		printf(
			'<link rel="preload" as="image" href="%s"%s%s fetchpriority="high" />' . "\n",
			esc_url( $src ),
			$srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '"' : '',
			$sizes ? ' imagesizes="' . esc_attr( $sizes ) . '"' : ''
		);
	}

	/**
	 * Ensure the primary above-the-fold featured image uses `loading="eager"` and `fetchpriority="high"`.
	 *
	 * @param array        $attr       Image attributes.
	 * @param WP_Post      $attachment Attachment post.
	 * @param string|int[] $size       Requested size.
	 * @return array
	 */
	public static function boost_lcp_image_attributes( $attr, $attachment, $size ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::$lcp_boosted || is_admin() || ! is_singular( array( 'product', 'post' ) ) ) {
			return $attr;
		}
		$hero_id = (int) get_post_thumbnail_id( get_queried_object_id() );
		if ( $hero_id > 0 && isset( $attachment->ID ) && (int) $attachment->ID === $hero_id ) {
			$attr['loading']       = 'eager';
			$attr['fetchpriority'] = 'high';
			$attr['decoding']      = 'async';
			self::$lcp_boosted     = true;
		}
		return $attr;
	}

	/**
	 * Strip `loading="lazy"` from the first WooCommerce gallery image HTML rendered by Woodmart/WC.
	 *
	 * @param string $html          Thumbnail HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public static function boost_wc_hero_image_html( $html, $attachment_id ) {
		if ( ! is_singular( 'product' ) ) {
			return $html;
		}
		$hero_id = (int) get_post_thumbnail_id( get_queried_object_id() );
		if ( $hero_id > 0 && (int) $attachment_id === $hero_id ) {
			$html = str_replace( 'loading="lazy"', 'loading="eager" fetchpriority="high"', (string) $html );
		}
		return $html;
	}

	/**
	 * Append a clean, language-matched Internal Crawl Mesh to singular products & posts so
	 * all 79 `Discovered - not indexed` + 31 `Crawled - not indexed` pages receive internal links.
	 *
	 * @param string $content Post content HTML.
	 * @return string
	 */
	public static function append_contextual_crawl_mesh( $content ) {
		if ( is_admin() || is_feed() || ! in_the_loop() || ! is_main_query() || ! is_singular( array( 'product', 'post' ) ) ) {
			return $content;
		}

		$current_id = (int) get_queried_object_id();
		if ( $current_id <= 0 || false !== strpos( (string) $content, 'asc-crawl-mesh' ) ) {
			return $content;
		}

		$current_url = get_permalink( $current_id );
		if ( ! $current_url ) {
			return $content;
		}
		$is_en = self::is_english_url( $current_url );
		$pool  = self::get_language_url_pool( $is_en ? 'en' : 'fa' );
		if ( count( $pool ) < 2 ) {
			return $content;
		}

		// Locate current item index in the deterministic pool so every URL across the site gets 4 incoming links.
		$current_idx = 0;
		foreach ( $pool as $i => $item ) {
			if ( (int) $item['id'] === $current_id ) {
				$current_idx = $i;
				break;
			}
		}

		$picked = array();
		$total  = count( $pool );
		for ( $step = 1; $step < $total && count( $picked ) < 4; $step++ ) {
			$candidate = $pool[ ( $current_idx + $step ) % $total ];
			if ( (int) $candidate['id'] !== $current_id ) {
				$picked[] = $candidate;
			}
		}

		if ( empty( $picked ) ) {
			return $content;
		}

		// Also include primary product category link if on a product.
		$cat_link_html = '';
		if ( 'product' === get_post_type( $current_id ) && taxonomy_exists( 'product_cat' ) ) {
			$terms = get_the_terms( $current_id, 'product_cat' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$primary_term = reset( $terms );
				$tlink        = get_term_link( $primary_term );
				if ( ! is_wp_error( $tlink ) && self::is_english_url( $tlink ) === $is_en ) {
					$cat_label     = $is_en ? 'Browse Category: ' . $primary_term->name : 'مشاهده همه محصولات دسته ' . $primary_term->name;
					$cat_link_html = sprintf(
						'<li style="margin:4px 0;"><a href="%s" style="font-weight:700;text-decoration:none;">%s</a></li>',
						esc_url( $tlink ),
						esc_html( $cat_label )
					);
				}
			}
		}

		$heading = $is_en ? 'Related Products & Clinical Guides' : 'محصولات و راهنماهای تخصصی مرتبط در ولوس ایکس';
		$dir     = $is_en ? 'ltr' : 'rtl';
		$align   = $is_en ? 'left' : 'right';

		$items_html = $cat_link_html;
		foreach ( $picked as $p ) {
			$items_html .= sprintf(
				'<li style="margin:4px 0;"><a href="%s" style="text-decoration:none;">%s</a></li>',
				esc_url( $p['url'] ),
				esc_html( $p['title'] )
			);
		}

		$box = sprintf(
			"\n" . '<nav class="asc-crawl-mesh" dir="%s" aria-label="%s" style="margin-top:28px;padding:16px 20px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;text-align:%s;font-size:14px;line-height:1.8;">'
			. '<strong style="display:block;margin-bottom:8px;color:#0f172a;">%s</strong>'
			. '<ul style="margin:0;padding-%s:18px;">%s</ul>'
			. '</nav>',
			esc_attr( $dir ),
			esc_attr( $heading ),
			esc_attr( $align ),
			esc_html( $heading ),
			'rtl' === $dir ? 'right' : 'left',
			$items_html
		);

		return $content . $box;
	}

	/**
	 * Cached language-segregated pool of published products and posts for zero-orphan internal linking.
	 *
	 * @param string $lang 'fa' or 'en'.
	 * @return array
	 */
	private static function get_language_url_pool( $lang = 'fa' ) {
		$cache_key = 'asc_crawl_pool_' . ( 'en' === $lang ? 'en' : 'fa' );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_type FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type IN ('product', 'post')
			 ORDER BY post_type ASC, ID ASC
			 LIMIT 400",
			ARRAY_A
		);

		$pool = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$pid   = (int) $r['ID'];
				$plink = get_permalink( $pid );
				if ( ! $plink || self::is_transactional_endpoint( $plink ) ) {
					continue;
				}
				$url_is_en = self::is_english_url( $plink );
				if ( ( 'en' === $lang && $url_is_en ) || ( 'fa' === $lang && ! $url_is_en ) ) {
					$pool[] = array(
						'id'    => $pid,
						'title' => trim( wp_strip_all_tags( (string) $r['post_title'] ) ),
						'url'   => $plink,
					);
				}
			}
		}

		set_transient( $cache_key, $pool, 6 * HOUR_IN_SECONDS );
		return $pool;
	}

	/**
	 * Smart 404 Slug Auto-Resolver (`Not found (404)` — 3 pages in GSC).
	 * Matches missing product/post slugs to unambiguous live published items in the same language,
	 * registers a snapshotted 301 rule in `wp_asc_redirects`, and redirects immediately.
	 *
	 * @param string $req_path Normalized decoded request path.
	 */
	public static function maybe_smart_redirect_404( $req_path ) {
		// Skip static assets, wp-json, feeds, admin, or ultra-short paths.
		if ( '' === $req_path || '/' === $req_path || preg_match( '/\.(js|css|png|jpe?g|webp|gif|svg|ico|woff2?|ttf|map|xml|txt|php)$/i', $req_path ) ) {
			return;
		}
		if ( 0 === strpos( $req_path, '/wp-' ) ) {
			return;
		}

		$is_en    = self::is_english_url( $req_path );
		$segments = array_values( array_filter( explode( '/', trim( $req_path, '/' ) ) ) );
		if ( empty( $segments ) ) {
			return;
		}
		$last_slug = end( $segments );
		if ( mb_strlen( $last_slug ) < 4 ) {
			return;
		}

		global $wpdb;
		$encoded_slug = strtolower( rawurlencode( $last_slug ) );
		$sanitized    = sanitize_title( $last_slug );

		// 1. Exact post_name or _wp_old_slug match across published products/posts/pages.
		$exact_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND post_type IN ('product', 'post', 'page')
				   AND (post_name = %s OR post_name = %s)
				 LIMIT 5",
				$encoded_slug,
				$sanitized
			)
		);

		if ( empty( $exact_ids ) ) {
			$exact_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = '_wp_old_slug'
					   AND (pm.meta_value = %s OR pm.meta_value = %s)
					   AND p.post_status = 'publish'
					 LIMIT 5",
					$encoded_slug,
					$sanitized
				)
			);
		}

		$matched_url = '';
		if ( is_array( $exact_ids ) ) {
			foreach ( $exact_ids as $cid ) {
				$plink = get_permalink( (int) $cid );
				if ( $plink && self::is_english_url( $plink ) === $is_en && ASC_Redirect_404::normalize_path( $plink ) !== $req_path ) {
					$matched_url = $plink;
					break;
				}
			}
		}

		// 2. High-confidence token similarity fallback for renamed products (requires >= 75% token overlap & >= 2 tokens).
		if ( '' === $matched_url ) {
			$normalized_slug = str_replace( 'hyamax', 'hyomax', mb_strtolower( $last_slug ) );
			$req_tokens      = array_values( array_filter( preg_split( '/[-_\s]+/u', $normalized_slug ), function ( $t ) {
				return mb_strlen( $t ) >= 3 && ! in_array( $t, array( 'product', 'the', 'and', 'for' ), true );
			} ) );

			if ( count( $req_tokens ) >= 2 ) {
				$pool       = self::get_language_url_pool( $is_en ? 'en' : 'fa' );
				$best_score = 0.0;
				$best_url   = '';
				$second     = 0.0;

				foreach ( $pool as $item ) {
					$item_path = ASC_Redirect_404::normalize_path( $item['url'] );
					if ( $item_path === $req_path ) {
						continue;
					}
					$cand_hay = str_replace( 'hyamax', 'hyomax', mb_strtolower( basename( $item_path ) . ' ' . $item['title'] ) );
					$hits     = 0;
					foreach ( $req_tokens as $tok ) {
						if ( false !== mb_strpos( $cand_hay, $tok ) ) {
							++$hits;
						}
					}
					$score = $hits / count( $req_tokens );
					if ( $score > $best_score ) {
						$second     = $best_score;
						$best_score = $score;
						$best_url   = $item['url'];
					} elseif ( $score > $second ) {
						$second = $score;
					}
				}

				if ( $best_score >= 0.8 && ( $best_score - $second ) >= 0.2 ) {
					$matched_url = $best_url;
				}
			}
		}

		if ( '' !== $matched_url ) {
			ASC_Redirect_404::save_redirect(
				array(
					'source_path' => $req_path,
					'target_url'  => $matched_url,
					'status_code' => 301,
					'note'        => 'ریدایرکت هوشمند خودکار ۴۰۴ (تطابق اسلاگ سرچ کنسول)',
				),
				'asc-indexing-fixer',
				'رفع خودکار خطای 404 سرچ کنسول با تطابق دقیق محصول/مقاله هم‌زبان'
			);

			$safe_dest = esc_url_raw( ASC_Redirect_404::encode_url_utf8( $matched_url ) );
			if ( '' !== $safe_dest ) {
				wp_redirect( $safe_dest, 301 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
		}
	}

	/**
	 * Run automated boot-time DB remediation once per plugin remediation version.
	 */
	public static function maybe_boot_remediate() {
		if ( get_option( 'asc_indexing_remediation_version' ) === self::REMEDIATION_VERSION ) {
			return;
		}
		if ( get_transient( 'asc_indexing_remediating_lock' ) ) {
			return;
		}
		set_transient( 'asc_indexing_remediating_lock', 1, 120 );

		$result = self::remediate_all( 'auto-boot-v1.4.0' );
		if ( ! is_wp_error( $result ) ) {
			update_option( 'asc_indexing_remediation_version', self::REMEDIATION_VERSION, false );
			update_option( 'asc_last_indexing_remediation', $result, false );
		}

		delete_transient( 'asc_indexing_remediating_lock' );
	}

	/**
	 * Resolve a local relative path to a published post ID (0 if not found).
	 *
	 * @param string $rel_path Relative path like `/product/hyomax-caha/`.
	 * @return int
	 */
	private static function resolve_published_path_id( $rel_path ) {
		$norm = ASC_Redirect_404::normalize_path( $rel_path );
		$full = home_url( trailingslashit( $norm ) );
		$pid  = url_to_postid( ASC_Redirect_404::encode_url_utf8( $full ) );
		if ( $pid > 0 && 'publish' === get_post_status( $pid ) ) {
			return (int) $pid;
		}

		global $wpdb;
		$slug         = basename( $norm );
		$encoded_slug = strtolower( rawurlencode( $slug ) );
		$sanitized    = sanitize_title( $slug );
		$want_en      = self::is_english_url( $norm );

		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND post_type IN ('product', 'post', 'page')
				   AND (post_name = %s OR post_name = %s)
				 LIMIT 10",
				$encoded_slug,
				$sanitized
			)
		);

		if ( is_array( $candidates ) ) {
			foreach ( $candidates as $cid ) {
				$plink = get_permalink( (int) $cid );
				if ( $plink && self::is_english_url( $plink ) === $want_en ) {
					return (int) $cid;
				}
			}
		}

		return 0;
	}

	/**
	 * Execute all database-level Search Console non-indexed fixes with full Snapshot protection.
	 *
	 * @param string $actor Actor label.
	 * @return array
	 */
	public static function remediate_all( $actor = 'ai-agent' ) {
		$summary = array(
			'version'                     => self::REMEDIATION_VERSION,
			'executed_at'                 => current_time( 'mysql' ),
			'noindex_rescued_posts'       => array(),
			'noindex_rescued_terms'       => array(),
			'transactional_noindexed'     => array(),
			'duplicate_redirects_added'   => array(),
			'duplicate_redirects_skipped' => array(),
			'unchained_internal_posts'    => array(),
			'enriched_categories'         => array(),
			'ctr_optimized_pages'         => array(),
			'reviews_enabled_products'    => 0,
			'indexnow_submitted_count'    => 0,
		);

		// Ensure blog_public is enabled so WordPress never sends global noindex.
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			ASC_Code_Files::manage_option( 'blog_public', '1', true, $actor, 'رفع مسدودیت کلی ایندکس سایت (blog_public=1)' );
		}

		// 1. Rescue published money posts/products/pages with accidental `noindex` & lock transactional pages.
		self::remediate_post_and_term_noindex( $actor, $summary );

		// 2. Consolidate verified duplicate URL clusters (`Hyamax -> Hyomax` & duplicate Persian products) safely.
		self::remediate_duplicate_clusters( $actor, $summary );

		// 3. Rewrite internal `http://` links & links pointing to redirected paths (`Page with redirect` fix).
		self::unchain_internal_links( $actor, $summary );

		// 4. Enrich thin/empty core WooCommerce category descriptions (fixes `Crawled - not indexed` & category rankings).
		self::enrich_thin_product_categories( $actor, $summary );

		// 5. Upgrade Rank Math Titles & Meta Descriptions on proven high-impression / low-CTR pages.
		self::optimize_high_impression_ctr_meta( $actor, $summary );

		// 6. Enable verified customer reviews on published products so real reviews unlock `aggregateRating`.
		$summary['reviews_enabled_products'] = self::enable_woocommerce_product_reviews();

		// 7. Reset caches, flush Rank Math sitemaps, and submit non-indexed candidate URLs via IndexNow & GSC.
		delete_transient( 'asc_crawl_pool_fa' );
		delete_transient( 'asc_crawl_pool_en' );
		self::$redirect_sources_cache = null;
		self::flush_sitemaps_and_ping_indexnow( $summary );

		ASC_Engine::purge_site_caches();

		ASC_DB::log_audit(
			$actor,
			'remediate_gsc_indexing',
			'volusx.com (175 GSC Non-Indexed Pages)',
			'success',
			sprintf(
				'اصلاح جامع ایندکس سرچ کنسول انجام شد: %d رفع noindex، %d ادغام تکراری ۳۰۱، %d تقویت دسته، %d بهینه‌سازی CTR، %d ارسال IndexNow.',
				count( $summary['noindex_rescued_posts'] ) + count( $summary['noindex_rescued_terms'] ),
				count( $summary['duplicate_redirects_added'] ),
				count( $summary['enriched_categories'] ),
				count( $summary['ctr_optimized_pages'] ),
				(int) $summary['indexnow_submitted_count']
			)
		);

		return $summary;
	}

	/**
	 * Step 1: Strip unintended `noindex` from published products/posts/categories and enforce `noindex` on account/cart.
	 *
	 * @param string $actor   Actor label.
	 * @param array  $summary Summary array (by ref).
	 */
	private static function remediate_post_and_term_noindex( $actor, array &$summary ) {
		global $wpdb;

		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_name FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type IN ('product', 'post', 'page')
			 LIMIT 500",
			ARRAY_A
		);

		if ( is_array( $posts ) ) {
			foreach ( $posts as $p ) {
				$pid   = (int) $p['ID'];
				$plink = get_permalink( $pid );
				if ( ! $plink ) {
					continue;
				}
				$rel_path = ASC_Redirect_404::normalize_path( $plink );

				// Transactional pages (`/account/`, `/en/account/`, `/cart/`, `/checkout/`, `/wishlist/`) MUST be noindex.
				if ( self::is_transactional_endpoint( $rel_path ) ) {
					$rm_robots = get_post_meta( $pid, 'rank_math_robots', true );
					$has_ni    = is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true );
					if ( ! $has_ni ) {
						$before  = ASC_Snapshot::capture_post_state( $pid );
						$snap_id = ASC_Snapshot::create(
							'lock_transactional_noindex',
							'seo',
							(string) $pid,
							$p['post_title'] . ' (' . $rel_path . ')',
							$before,
							$actor,
							'اعمال noindex روی صفحه کاربری/سبد خرید برای جلوگیری از هدررفت بودجه خزش'
						);
						update_post_meta( $pid, 'rank_math_robots', array( 'noindex', 'follow' ) );
						ASC_Snapshot::record_after_state( $snap_id, ASC_Snapshot::capture_post_state( $pid ) );
						$summary['transactional_noindexed'][] = $rel_path;
					}
					continue;
				}

				// Real published products, posts, and non-transactional pages: remove unintended `noindex`.
				$rm_robots    = get_post_meta( $pid, 'rank_math_robots', true );
				$yoast_ni     = (string) get_post_meta( $pid, '_yoast_wpseo_meta-robots-noindex', true );
				$asc_ni       = (string) get_post_meta( $pid, '_asc_seo_noindex', true );
				$rm_has_ni    = is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true );
				$rm_str_ni    = is_string( $rm_robots ) && false !== stripos( $rm_robots, 'noindex' );

				if ( $rm_has_ni || $rm_str_ni || '1' === $yoast_ni || '1' === $asc_ni ) {
					$before  = ASC_Snapshot::capture_post_state( $pid );
					$snap_id = ASC_Snapshot::create(
						'rescue_unintended_noindex',
						'seo',
						(string) $pid,
						$p['post_title'] . ' (' . $rel_path . ')',
						$before,
						$actor,
						'حذف تگ noindex ناخواسته از صفحه محصول/مقاله برای ورود به ایندکس گوگل'
					);

					if ( $rm_has_ni || $rm_str_ni ) {
						$clean_rm = is_array( $rm_robots ) ? array_values( array_diff( $rm_robots, array( 'noindex', 'nofollow' ) ) ) : array();
						if ( ! in_array( 'index', $clean_rm, true ) ) {
							$clean_rm[] = 'index';
						}
						if ( ! in_array( 'follow', $clean_rm, true ) ) {
							$clean_rm[] = 'follow';
						}
						update_post_meta( $pid, 'rank_math_robots', $clean_rm );
					}
					if ( '1' === $yoast_ni ) {
						delete_post_meta( $pid, '_yoast_wpseo_meta-robots-noindex' );
					}
					if ( '1' === $asc_ni ) {
						delete_post_meta( $pid, '_asc_seo_noindex' );
					}

					ASC_Snapshot::record_after_state( $snap_id, ASC_Snapshot::capture_post_state( $pid ) );
					$summary['noindex_rescued_posts'][] = $rel_path;
				}
			}
		}

		// Also rescue any `product_cat` term that accidentally has `noindex` in `rank_math_robots`.
		if ( taxonomy_exists( 'product_cat' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$trm_robots = get_term_meta( $term->term_id, 'rank_math_robots', true );
					$has_ni     = ( is_array( $trm_robots ) && in_array( 'noindex', $trm_robots, true ) )
						|| ( is_string( $trm_robots ) && false !== stripos( $trm_robots, 'noindex' ) );

					if ( $has_ni ) {
						$before  = ASC_Snapshot::capture_term_state( $term->term_id, 'product_cat' );
						$snap_id = ASC_Snapshot::create(
							'rescue_term_noindex',
							'term',
							(string) $term->term_id,
							'دسته محصول: ' . $term->name,
							$before,
							$actor,
							'حذف noindex ناخواسته از دسته‌بندی محصول ووکامرس'
						);
						update_term_meta( $term->term_id, 'rank_math_robots', array( 'index', 'follow' ) );
						ASC_Snapshot::record_after_state( $snap_id, ASC_Snapshot::capture_term_state( $term->term_id, 'product_cat' ) );
						$summary['noindex_rescued_terms'][] = $term->name;
					}
				}
			}
		}
	}

	/**
	 * Step 2: Consolidate verified Persian duplicate URL pairs via 301 redirect ONLY when the canonical target
	 * is confirmed published on `volusx.com` (never touches `/en/` pages that hold AI Overviews / Search rankings).
	 *
	 * @param string $actor   Actor label.
	 * @param array  $summary Summary array (by ref).
	 */
	private static function remediate_duplicate_clusters( $actor, array &$summary ) {
		$candidate_pairs = array(
			array(
				'source' => '/product/فیلر-hyamax-caha-lido',
				'target' => '/product/hyomax-caha/',
				'note'   => 'ادغام املای قدیمی Hyamax به Hyomax CaHA (رفع Duplicate Canonical سرچ کنسول)',
			),
			array(
				'source' => '/product/hyamax-extra-deep-filler',
				'target' => '/product/hyomax-extra-deep/',
				'note'   => 'ادغام املای قدیمی Hyamax به Hyomax Extra Deep (رفع Duplicate Canonical سرچ کنسول)',
			),
			array(
				'source' => '/product/hyamax-super-hydra',
				'target' => '/product/mesogel-hyamax-super-hydra/',
				'note'   => 'ادغام محصول تکراری فارسی Super Hydra به نسخه اصلی ایندکس‌شده',
			),
			array(
				'source' => '/product/مزوژل-reyoungel-hydropro',
				'target' => '/product/reyoungel-hydropro/',
				'note'   => 'ادغام اسلاگ تکراری Reyoungel Hydropro به آدرس کنونیکال اصلی',
			),
		);

		foreach ( $candidate_pairs as $pair ) {
			$src_norm = ASC_Redirect_404::normalize_path( $pair['source'] );
			$dst_norm = ASC_Redirect_404::normalize_path( $pair['target'] );

			// Strict safeguard: never redirect `/en/` URLs or identical paths.
			if ( self::is_english_url( $src_norm ) || self::is_english_url( $dst_norm ) || $src_norm === $dst_norm ) {
				continue;
			}

			$target_id = self::resolve_published_path_id( $dst_norm );
			if ( $target_id <= 0 ) {
				$summary['duplicate_redirects_skipped'][] = array(
					'source' => $src_norm,
					'target' => $dst_norm,
					'reason' => 'مقصد در دیتابیس یافت نشد (محافظ جلوگیری از Soft-404 عمل کرد)',
				);
				continue;
			}

			$source_id = self::resolve_published_path_id( $src_norm );
			if ( $source_id > 0 && $source_id === $target_id ) {
				continue;
			}

			$target_permalink = get_permalink( $target_id );
			if ( ! $target_permalink ) {
				continue;
			}

			// If duplicate source post is also published, point its Rank Math canonical to the target as defense-in-depth.
			if ( $source_id > 0 ) {
				$before_src = ASC_Snapshot::capture_post_state( $source_id );
				$snap_src   = ASC_Snapshot::create(
					'consolidate_duplicate_canonical',
					'seo',
					(string) $source_id,
					'کنونیکال نسخه تکراری: ' . $src_norm,
					$before_src,
					$actor,
					$pair['note']
				);
				update_post_meta( $source_id, 'rank_math_canonical_url', $target_permalink );
				ASC_Snapshot::record_after_state( $snap_src, ASC_Snapshot::capture_post_state( $source_id ) );
			}

			$saved = ASC_Redirect_404::save_redirect(
				array(
					'source_path' => $src_norm,
					'target_url'  => $target_permalink,
					'status_code' => 301,
					'note'        => $pair['note'],
				),
				$actor,
				$pair['note']
			);

			if ( ! is_wp_error( $saved ) ) {
				$summary['duplicate_redirects_added'][] = $src_norm . ' -> ' . $dst_norm;
			}
		}
	}

	/**
	 * Step 3: Rewrite hardcoded `http://volusx.com/` URLs inside `post_content` to `https://volusx.com/`
	 * so Googlebot stops discovering insecure internal redirect chains (`Page with redirect` — 17 pages).
	 *
	 * @param string $actor   Actor label.
	 * @param array  $summary Summary array (by ref).
	 */
	private static function unchain_internal_links( $actor, array &$summary ) {
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			return;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return;
		}

		global $wpdb;
		$http_prefix  = 'http://' . $host;
		$https_prefix = 'https://' . $host;

		$like  = '%' . $wpdb->esc_like( $http_prefix ) . '%';
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND post_type IN ('product', 'post', 'page')
				   AND post_content LIKE %s
				 LIMIT 100",
				$like
			),
			ARRAY_A
		);

		if ( ! is_array( $posts ) ) {
			return;
		}

		foreach ( $posts as $p ) {
			$pid     = (int) $p['ID'];
			$updated = str_ireplace( $http_prefix, $https_prefix, (string) $p['post_content'] );
			if ( $updated !== $p['post_content'] ) {
				$before  = ASC_Snapshot::capture_post_state( $pid );
				$snap_id = ASC_Snapshot::create(
					'unchain_http_internal_links',
					'post',
					(string) $pid,
					$p['post_title'],
					$before,
					$actor,
					'اصلاح لینک‌های داخلی http:// به https:// برای حذف زنجیره ریدایرکت در سرچ کنسول'
				);
				$wpdb->update(
					$wpdb->posts,
					array( 'post_content' => $updated ),
					array( 'ID' => $pid ),
					array( '%s' ),
					array( '%d' )
				);
				clean_post_cache( $pid );
				ASC_Snapshot::record_after_state( $snap_id, ASC_Snapshot::capture_post_state( $pid ) );
				$summary['unchained_internal_posts'][] = $pid;
			}
		}
	}

	/**
	 * Step 4: Enrich thin/empty core WooCommerce category descriptions (< 80 words) with comprehensive
	 * clinical & buying guides + internal hub links (`Crawled - not indexed` + category ranking boost).
	 *
	 * @param string $actor   Actor label.
	 * @param array  $summary Summary array (by ref).
	 */
	private static function enrich_thin_product_categories( $actor, array &$summary ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}

		$guides = array(
			'مزوژل'      => array(
				'seo_title' => 'خرید مزوژل اصل کره و اروپا | لیست قیمت انواع مزوژل جوانساز — ولوس ایکس',
				'seo_desc'  => 'مرجع تخصصی خرید انواع مزوژل آبرسان، جوانساز، رفع لک و زیر چشم با تضمین اصالت و زنجیره سرد؛ مشاهده مشخصات و استعلام قیمت مزوژل‌های برتر در ولوس ایکس.',
				'html'      => '<h2>راهنمای تخصصی خرید مزوژل اصل و مقایسه بهترین برندهای جوانساز</h2>'
					. '<p><strong>مزوژل‌ها (Mesogels / Skin Boosters)</strong> نسل پیشرفته ترکیبات تزریقی بر پایه هیالورونیک اسید آزاد، پپتیدهای بیومیمتیک، آمینواسیدها، ویتامین‌ها و پلی‌نوکلئوتیدها هستند که برخلاف فیلرهای حجم‌دهنده، مستقیماً کیفیت، قوام، آبرسانی عمقی و سنتز کلاژن و الاستین پوست را بازسازی می‌کنند. انتخاب مزوژل اورجینال با نگهداری استاندارد، کلید دستیابی به نتیجه درمانی ایمن و ماندگار در کلینیک‌های زیبایی است.</p>'
					. '<h3>کاربردهای بالینی انواع مزوژل در زیبایی و جوانسازی</h3>'
					. '<ul>'
					. '<li><strong>آبرسانی عمقی و رفع کدری پوست (Hydro-Boosters):</strong> تامین رطوبت لایه درم و افزایش درخشندگی پوست‌های دهیدراته با محصولاتی نظیر مزوژل ویتال میس‌فیل (Vital Misfill)، ریونجل هیدروپرو (Reyoungel Hydropro) و هایومکس سوپر هیدرا (Hyomax Super Hydra).</li>'
					. '<li><strong>بازسازی سلولی و رفع تیرگی زیر چشم (PDRN &amp; Revitalizing):</strong> ترمیم بافت آسیب‌دیده، بهبود اسکارهای ظریف و کاهش خطوط پنجه کلاغی با ترکیبات ریوایت شاین PDRN و ریونجل ریوایتال.</li>'
					. '<li><strong>لیفت بیولوژیک و تحریک کلاژن‌سازی:</strong> افزایش الاستیسیته در نواحی صورت، گردن و دست‌ها طی پروتکل‌های ۲ تا ۳ جلسه‌ای.</li>'
					. '</ul>'
					. '<h3>نکات کلیدی پیش از استعلام قیمت و خرید مزوژل</h3>'
					. '<p>هنگام تهیه مزوژل به غلظت هیالورونیک اسید (mg/ml)، وجود کمپلکس‌های پپتیدی یا PDRN، بچ‌نامبر معتبر کارخانه سازنده و سلامت بسته‌بندی استریل توجه ویژه داشته باشید. تمامی مزوژل‌های عرضه‌شده در <strong>ولوس ایکس (Volusx)</strong> با تضمین اصالت کالا، تاریخ انقضای معتبر و مشاوره تخصصی انتخاب پروتکل مناسب برای پزشکان و مراکز زیبایی تامین می‌شوند.</p>',
			),
			'فیلر'       => array(
				'seo_title' => 'خرید فیلر و ژل تزریقی اصل (لب، گونه، زاویه فک و بادی) | ولوس ایکس',
				'seo_desc'  => 'تامین مستقیم انواع فیلر هیالورونیک اسید و کلسیم هیدروکسی آپاتایت اورجینال (ولوس، پلورول، هایومکس، رووفیل) با ماندگاری بالا و تضمین اصالت در ولوس ایکس.',
				'html'      => '<h2>راهنمای جامع خرید فیلر (ژل تزریقی) اورجینال برای صورت و بدن</h2>'
					. '<p><strong>فیلرهای پوستی (Dermal Fillers)</strong> بر پایه هیالورونیک اسید کراس‌لینک‌شده با خلوص دارویی و ویسکوالاستیسیته هدفمند طراحی می‌شوند تا اصلاح فرم لب، حجم‌دهی گونه، کانتورینگ زاویه فک، رفع خط خنده و حجم‌دهی ایمن بدن با طبیعی‌ترین فرم و کمترین ادم پس از تزریق انجام پذیرد.</p>'
					. '<h3>انتخاب فیلر بر اساس ویسکوزیته و ناحیه تزریق</h3>'
					. '<ul>'
					. '<li><strong>فیلرهای نرم و ظریف (Soft / Lips):</strong> ایده‌آل برای بافت حساس لب و خطوط سطحی با پخش یکنواخت، مانند فیلر پلورول لیپس (Plurvel Lips) و ریوایت سافت ۱ سی‌سی.</li>'
					. '<li><strong>فیلرهای دیپ و ولومایزر (Deep &amp; Volumizer):</strong> مناسب اصلاح خط خنده عمیق، سیب گونه و فرم‌دهی میدفیس، نظیر پلورول دیپ (Plurvel Deep) و هایومکس ولومایزر.</li>'
					. '<li><strong>فیلرهای اولترا دیپ، کانتور و ۱۰ سی‌سی بادی:</strong> دارای خاصیت کشسانی (G-Prime) بالا برای زاویه‌سازی فک، چانه و حجم‌دهی بدن مانند فیلر ولوس ۱۰ سی‌سی کره‌ای (Volus)، هایومکس کانتور و سدیفیل ۶۰ سی‌سی.</li>'
					. '</ul>'
					. '<p>در فروشگاه تخصصی <strong>ولوس ایکس</strong> تمامی فیلرها با اندوتوکسین کنترل‌شده، اصالت تضمینی و شرایط نگهداری استاندارد در اختیار پزشکان محترم قرار می‌گیرند.</p>',
			),
			'بوتاکس'     => array(
				'seo_title' => 'خرید بوتاکس اصل (مصپورت ۵۰۰، دیستون ۵۰۰ و نابوتا ۱۵۰ واحد) | ولوس ایکس',
				'seo_desc'  => 'فروش تخصصی انواع بوتاکس اورجینال مصپورت، دیستون و نابوتا با رعایت کامل زنجیره سرد، بچ‌نامبر معتبر و ارسال ایمن در فروشگاه ولوس ایکس.',
				'html'      => '<h2>خرید بوتاکس اورجینال با تضمین کامل زنجیره سرد</h2>'
					. '<p><strong>توکسین بوتولینوم نوع A (Botox)</strong> پرکاربردترین درمان غیرجراحی برای رفع چین‌وچروک‌های دینامیک پیشانی، خط اخم، خطوط دور چشم و کنترل تعریق بیش‌ازحد (هایپرهیدروزیس) است. اثربخشی یکنواخت و ایمنی بوتاکس بیش از هر چیز به <strong>اصالت ویال و رعایت دقیق دمای زنجیره سرد</strong> از لحظه تولید تا کلینیک بستگی دارد.</p>'
					. '<ul>'
					. '<li><strong>بوتاکس مصپورت ۵۰۰ واحدی (Masport 500U):</strong> دوز کلینیکی اقتصادی با اثرگذاری سریع و ماندگاری استاندارد ۴ تا ۶ ماه.</li>'
					. '<li><strong>بوتاکس دیستون ۵۰۰ واحدی (Dyston 500U):</strong> خلوص پروتئینی بالا و پخش کنترل‌شده در عضلات هدف.</li>'
					. '<li><strong>بوتاکس نابوتا ۱۵۰ واحدی کره‌ای (Nabota 150U):</strong> فرمولاسیون خالص کره‌ای با شروع اثر سریع و حداقل ریسک مقاومت آنتی‌بادی.</li>'
					. '</ul>'
					. '<p>تمامی ویال‌های بوتاکس در <strong>ولوس ایکس</strong> در شرایط دمایی کنترل‌شده نگهداری و با پک یخ استاندارد ارسال می‌شوند.</p>',
			),
			'مزوتراپی'   => array(
				'seo_title' => 'خرید کوکتل مزوتراپی اصل (توسکانی، درماهیل، ضد لک و تقویت مو) | ولوس ایکس',
				'seo_desc'  => 'عرضه تخصصی کوکتل‌های مزوتراپی اورجینال توسکانی اسپانیا و درماهیل برای درمان ریزش مو، لک، منافذ باز، سلولیت و آنزیم هیالورونیداز در ولوس ایکس.',
				'html'      => '<h2>کوکتل‌های مزوتراپی اورجینال پوست، مو و آنزیم‌های تخصصی</h2>'
					. '<p><strong>مزوتراپی (Mesotherapy)</strong> امکان رساندن مستقیم پپتیدها، ویتامین‌ها، عوامل روشن‌کننده و آنزیم‌های درمانی را به لایه مزودرم فراهم می‌سازد. در ولوس ایکس کلکسیونی از معتبرترین کوکتل‌های استریل اروپایی و کره‌ای شامل کوکتل تقویت مو و ضد ریزش درماهیل، کوکتل ضد لک و روشن‌کننده توسکانی (WCPR و Radiance)، کوکتل منافذ پوست RCPR و ویال آنزیم هیالورونیداز توسکانی با اصالت تضمین‌شده گردآوری شده است.</p>',
			),
			'pdrn'       => array(
				'seo_title' => 'خرید محصولات و مزوژل PDRN اصل برای ترمیم و جوانسازی سلولی | ولوس ایکس',
				'seo_desc'  => 'مشخصات و خرید انواع ویال و مزوژل PDRN (پلی‌دئوکسی ریبونوکلئوتید) اورجینال برای بازسازی بافت پوست، رفع اسکار و تیرگی دور چشم در ولوس ایکس.',
				'html'      => '<h2>محصولات تخصصی PDRN؛ بازسازی بافتی و جوانسازی در سطح DNA سلولی</h2>'
					. '<p><strong>PDRN (پلی‌دئوکسی ریبونوکلئوتید)</strong> با فعال‌سازی گیرنده‌های آدنوزین A2A، تکثیر فیبروبلاست‌ها، رگ‌زایی میکروسکوپی و ترمیم سد دفاعی پوست را شتاب می‌بخشد و یکی از موثرترین درمان‌ها برای تیرگی زیر چشم، جای جوش، پوست‌های نازک و بازسازی پس از لیزر به شمار می‌رود.</p>',
			),
			'filler'     => array(
				'seo_title' => 'Buy Original Dermal Fillers Online | Korean & European HA Fillers — Volusx',
				'seo_desc'  => 'Shop authentic cross-linked hyaluronic acid and CaHA dermal fillers including Volus 10cc, Hyomax, Plurvel, and Revofil with verified batch codes at Volusx.',
				'html'      => '<h2>Authentic Dermal Fillers for Precision Facial &amp; Body Contouring</h2>'
					. '<p><strong>Volusx</strong> supplies medical aesthetics practitioners with CE/KFDA-grade cross-linked hyaluronic acid and calcium hydroxylapatite (CaHA) dermal fillers engineered for predictable viscoelasticity, low endotoxin purity, and natural tissue integration across lip enhancement, midface volumization, jawline sculpting, and body contouring.</p>'
					. '<ul>'
					. '<li><strong>Fine &amp; Lip Fillers:</strong> Smooth rheological gels for delicate perioral lines and natural lip definition.</li>'
					. '<li><strong>Deep &amp; Volumizer Fillers:</strong> Balanced cohesivity and G-prime for nasolabial folds, cheek projection, and chin contouring (Hyomax Volumizer, Hyomax Contour, Revofil Ultra 1cc).</li>'
					. '<li><strong>High-Volume 10cc Korean Fillers:</strong> Authentic Volus 10cc and Fillsys formulations with verified lot traceability.</li>'
					. '</ul>',
			),
			'mesogel'    => array(
				'seo_title' => 'Buy Original Mesogel & Skin Boosters | HA + PDRN Biorevitalizers — Volusx',
				'seo_desc'  => 'Explore authentic Korean and European mesogels and biorevitalizing skin boosters (Vital Mesogel, Hyomax Super Hydra, PDRN complexes) at Volusx.',
				'html'      => '<h2>Clinical Mesogels &amp; Biorevitalizing Skin Boosters</h2>'
					. '<p><strong>Mesogels (Skin Boosters)</strong> combine non-cross-linked hyaluronic acid with biomimetic peptides, amino acids, antioxidants, and polynucleotides (PDRN) to restore dermal hydration, elasticity, and collagen synthesis without unwanted over-volumization. Discover authentic formulations such as Vital Mesogel and Hyomax Super Hydra at Volusx with strict storage quality assurance.</p>',
			),
			'botox'      => array(
				'seo_title' => 'Buy Botulinum Toxin Type A (Masport 500U, Dyston 500U, Nabota 150U) — Volusx',
				'seo_desc'  => 'Order authentic Botulinum Toxin Type A vials including Dyston 500 Units, Masport 500 Units, and Nabota 150 Units with cold-chain handling from Volusx.',
				'html'      => '<h2>Pharmaceutical-Grade Botulinum Toxin Type A Vials</h2>'
					. '<p><strong>Volusx</strong> provides verified Botulinum Toxin Type A formulations—including <strong>Dyston 500 Units</strong>, <strong>Masport 500 Units</strong>, and <strong>Nabota 150 Units</strong>—maintained under strict cold-chain protocols to preserve biological potency for glabellar lines, forehead rhytids, crow&#039;s feet, and hyperhidrosis treatments.</p>',
			),
			'mesotherapy' => array(
				'seo_title' => 'Buy Toskani & Dermaheal Mesotherapy Cocktails Online — Volusx',
				'seo_desc'  => 'Shop sterile professional mesotherapy cocktails including Toskani Radiance, WCPR, RCPR, Hyaluronidase, and Dermaheal Anti-Hair Loss at Volusx.',
				'html'      => '<h2>Professional Mesotherapy Cocktails for Skin Brightening &amp; Hair Restoration</h2>'
					. '<p>Explore sterile medical-grade mesotherapy solutions from <strong>Toskani</strong> (Radiance Cocktail, WCPR, RCPR, Hyaluronidase) and <strong>Dermaheal</strong> anti-hair-loss complexes formulated for targeted dermal rejuvenation, pigment regulation, pore refinement, and follicular revitalization.</p>',
			),
		);

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			$decoded_slug = mb_strtolower( rawurldecode( (string) $term->slug ) );
			$name_lower   = mb_strtolower( trim( (string) $term->name ) );
			$matched_key  = null;

			if ( isset( $guides[ $decoded_slug ] ) ) {
				$matched_key = $decoded_slug;
			} elseif ( isset( $guides[ $name_lower ] ) ) {
				$matched_key = $name_lower;
			}

			if ( null === $matched_key ) {
				continue;
			}

			// Verify language alignment so an English guide only goes on `/en/` and Persian only on Persian.
			$tlink = get_term_link( $term );
			if ( is_wp_error( $tlink ) ) {
				continue;
			}
			$guide_is_en = in_array( $matched_key, array( 'filler', 'mesogel', 'botox', 'mesotherapy' ), true );
			if ( self::is_english_url( $tlink ) !== $guide_is_en ) {
				continue;
			}

			$existing_words = ASC_SEO_Manager::count_words( (string) $term->description );
			$rm_title       = (string) get_term_meta( $term->term_id, 'rank_math_title', true );
			$rm_desc        = (string) get_term_meta( $term->term_id, 'rank_math_description', true );

			if ( $existing_words >= 80 && '' !== $rm_title && '' !== $rm_desc ) {
				continue;
			}

			$before  = ASC_Snapshot::capture_term_state( $term->term_id, 'product_cat' );
			$snap_id = ASC_Snapshot::create(
				'enrich_thin_category',
				'term',
				(string) $term->term_id,
				'تقویت محتوا و متای دسته: ' . $term->name,
				$before,
				$actor,
				'افزودن راهنمای تخصصی خرید و متاتگ سئو برای رفع Crawled - Not Indexed و ارتقای رتبه دسته'
			);

			if ( $existing_words < 80 ) {
				wp_update_term(
					$term->term_id,
					'product_cat',
					array(
						'description' => wp_slash( $guides[ $matched_key ]['html'] ),
					)
				);
			}
			if ( '' === $rm_title ) {
				update_term_meta( $term->term_id, 'rank_math_title', $guides[ $matched_key ]['seo_title'] );
			}
			if ( '' === $rm_desc ) {
				update_term_meta( $term->term_id, 'rank_math_description', $guides[ $matched_key ]['seo_desc'] );
			}

			ASC_Snapshot::record_after_state( $snap_id, ASC_Snapshot::capture_term_state( $term->term_id, 'product_cat' ) );
			$summary['enriched_categories'][] = rawurldecode( (string) $term->slug );
		}
	}

	/**
	 * Step 5: Optimize Rank Math SEO Title & Meta Description on high-impression / low-CTR pages
	 * identified in Google Search Console (e.g. `botox-dyston-500-units` at pos 3.66 with 0.3% CTR).
	 *
	 * @param string $actor   Actor label.
	 * @param array  $summary Summary array (by ref).
	 */
	private static function optimize_high_impression_ctr_meta( $actor, array &$summary ) {
		$targets = array(
			'/en/product/botox-dyston-500-units/'  => array(
				'title' => 'Dyston Botox 500 Units — Original Vial Price, Specs & Cold-Chain Shipping | Volusx',
				'desc'  => 'Order authentic Dyston 500U Botulinum Toxin Type A vial at Volusx. Verified batch authenticity, clinical dilution specs, competitive wholesale pricing & cold-chain delivery.',
				'kw'    => 'dyston botox 500 units',
			),
			'/en/product/botox-masport-500-units/' => array(
				'title' => 'Masport 500 Units Botox — Authentic Vial Price, Specs & Fast Cold-Chain Delivery | Volusx',
				'desc'  => 'Buy original Masport 500U Botulinum Toxin Type A online at Volusx. Pharmaceutical-grade purity, verified lot number, clinical dosage guide & temperature-controlled shipping.',
				'kw'    => 'masport 500 botox',
			),
			'/en/product/nabota-botox-150-units/'  => array(
				'title' => 'Nabota Botox 150 Units (Korean Original) — Price, Purity & Cold-Chain Supply | Volusx',
				'desc'  => 'Shop genuine Korean Nabota 150U Botulinum Toxin Type A at Volusx. High-purity Daewoong formulation, fast onset, verified batch code & cold-chain protection.',
				'kw'    => 'nabota botox 150 units',
			),
			'/en/product/volus-filler-10cc-korea/' => array(
				'title' => 'Volus 10cc Korean Dermal Filler — Authentic Body & Contour HA Gel Price | Volusx',
				'desc'  => 'Buy original Korean Volus 10cc cross-linked hyaluronic acid filler at Volusx. High G-prime cohesivity, long-lasting volume, verified lot authenticity & wholesale inquiry.',
				'kw'    => 'volus filler 10cc',
			),
			'/en/product/hyamax-super-hydra/'      => array(
				'title' => 'Hyomax (Hyamax) Super Hydra Skin Booster — HA + Amino Acid Mesogel Price | Volusx',
				'desc'  => 'Restore deep dermal hydration and glow with authentic Hyomax Super Hydra skin booster at Volusx. View clinical specs, protocol & wholesale pricing.',
				'kw'    => 'hyamax super hydra',
			),
		);

		foreach ( $targets as $path => $meta ) {
			$pid = self::resolve_published_path_id( $path );
			if ( $pid <= 0 ) {
				continue;
			}
			// Do not overwrite if already optimized by this fixer.
			if ( '1' === (string) get_post_meta( $pid, '_asc_ctr_meta_v14', true ) ) {
				continue;
			}

			$res = ASC_SEO_Manager::update_post_seo(
				$pid,
				array(
					'meta_title'    => $meta['title'],
					'meta_desc'     => $meta['desc'],
					'focus_keyword' => $meta['kw'],
					'noindex'       => false,
				),
				$actor,
				'بهینه‌سازی عنوان و متا دیسکریپشن بر اساس داده‌های CTR سرچ کنسول'
			);

			if ( ! is_wp_error( $res ) ) {
				update_post_meta( $pid, '_asc_ctr_meta_v14', '1' );
				$summary['ctr_optimized_pages'][] = $path;
			}
		}
	}

	/**
	 * Step 6: Ensure published WooCommerce products allow real verified customer reviews (`comment_status = 'open'`).
	 *
	 * @return int Count of products updated.
	 */
	private static function enable_woocommerce_product_reviews() {
		global $wpdb;
		if ( 'yes' !== get_option( 'woocommerce_enable_reviews', 'yes' ) ) {
			update_option( 'woocommerce_enable_reviews', 'yes' );
		}
		$updated = $wpdb->query(
			"UPDATE {$wpdb->posts}
			 SET comment_status = 'open'
			 WHERE post_type = 'product' AND post_status = 'publish' AND comment_status = 'closed'"
		);
		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * Step 7: Flush Rank Math XML sitemap cache, submit sitemap to GSC if connected, and push URLs to IndexNow.
	 *
	 * @param array $summary Summary array (by ref).
	 */
	private static function flush_sitemaps_and_ping_indexnow( array &$summary ) {
		if ( class_exists( '\RankMath\Sitemap\Cache' ) && method_exists( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}

		if ( class_exists( 'AGSC_Google_Client' ) && method_exists( 'AGSC_Google_Client', 'is_configured' ) && AGSC_Google_Client::is_configured() ) {
			AGSC_Google_Client::submit_sitemap( home_url( '/sitemap_index.xml' ) );
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host || false !== strpos( $host, 'localhost' ) || false !== strpos( $host, '127.0.0.1' ) ) {
			return;
		}

		$fa_pool = self::get_language_url_pool( 'fa' );
		$en_pool = self::get_language_url_pool( 'en' );
		$urls    = array();

		foreach ( array_merge( $fa_pool, $en_pool ) as $entry ) {
			if ( ! empty( $entry['url'] ) ) {
				$urls[] = ASC_Redirect_404::encode_url_utf8( $entry['url'] );
			}
			if ( count( $urls ) >= 120 ) {
				break;
			}
		}

		if ( empty( $urls ) ) {
			return;
		}

		$key      = self::get_indexnow_key();
		$payload  = array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => array_values( array_unique( $urls ) ),
		);
		$response = wp_remote_post(
			'https://api.indexnow.org/indexnow',
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$summary['indexnow_submitted_count'] = count( $payload['urlList'] );
			}
		}
	}

	/**
	 * Build a live diagnostic report of site indexing readiness, shields, 404s, and 5xx logs.
	 *
	 * @return array
	 */
	public static function get_diagnostic_report() {
		global $wpdb;

		$fa_pool       = self::get_language_url_pool( 'fa' );
		$en_pool       = self::get_language_url_pool( 'en' );
		$last_remed    = get_option( 'asc_last_indexing_remediation', null );
		$errors_5xx    = (array) get_option( 'asc_5xx_error_log', array() );
		$unresolved404 = ASC_Redirect_404::list_404_logs( true, 25 );

		// Count any remaining published money posts with rank_math_robots containing noindex.
		$remaining_ni = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = 'rank_math_robots'
			   AND pm.meta_value LIKE '%noindex%'
			   AND p.post_status = 'publish'
			   AND p.post_type IN ('product', 'post')"
		);

		return array(
			'remediation_version'       => get_option( 'asc_indexing_remediation_version', 'pending' ),
			'last_remediation_summary'  => $last_remed,
			'active_shields'            => array(
				'force_https_and_strip_utm'     => true,
				'robots_txt_allow_en_uploads'   => true,
				'rescue_money_noindex'          => true,
				'facet_and_account_noindex'     => true,
				'clean_self_canonicals'         => true,
				'product_schema_irr_and_brand'  => true,
				'mobile_lcp_preload_eager'      => true,
				'zero_orphan_crawl_mesh_fa_en'  => true,
				'smart_404_slug_auto_redirect'  => true,
				'xml_sitemap_junk_exclusion'    => true,
			),
			'crawlable_money_pool'      => array(
				'fa_products_and_posts' => count( $fa_pool ),
				'en_products_and_posts' => count( $en_pool ),
				'total'                 => count( $fa_pool ) + count( $en_pool ),
			),
			'remaining_product_post_ni' => $remaining_ni,
			'unresolved_404_count'      => count( $unresolved404 ),
			'unresolved_404_sample'     => array_slice( $unresolved404, 0, 10 ),
			'captured_5xx_errors'       => array_slice( $errors_5xx, 0, 10 ),
			'indexnow_key_url'          => home_url( '/' . self::get_indexnow_key() . '.txt' ),
		);
	}
}
