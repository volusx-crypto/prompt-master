<?php
/**
 * Recipe registry: the knowledge base of known plugins.
 *
 * A "recipe" describes a plugin family, how to detect the plugin, which
 * site capabilities it provides, and which settings may be tuned.
 *
 * Recipe shape:
 *   id           string  Unique slug.
 *   name         string  Human readable plugin name.
 *   family       string  Family slug (seo, cache, ...).
 *   priority     int     Preference when two plugins of a family are active.
 *   plugins      array   Known plugin basenames.
 *   detect       array   option|class|constant fallbacks for renamed folders.
 *   capabilities array   capability => strength score.
 *   advisory     bool    True when the plugin's option schema cannot be
 *                        written safely (report only).
 *   rules        array   Setting rules (see below).
 *   note         string  Persian note shown in the UI.
 *
 * Rule shape:
 *   id          string Unique slug within the recipe.
 *   label       string Persian description.
 *   option      string Option name (must already exist).
 *   path        array  Path inside the option value.
 *   value       mixed  Desired value.
 *   value_cb    string Callback returning the value (null = skip).
 *   risk        string low|medium|high.
 *   conditions  array  profile|not_profile|capability_primary|capability_secondary|option_filled
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Registry
 */
class PHM_Registry {

	/**
	 * Cached recipes.
	 *
	 * @var array|null
	 */
	private static $recipes = null;

	/**
	 * Family labels.
	 *
	 * @return array
	 */
	public static function family_labels() {
		return array(
			'core'         => 'هستهٔ وردپرس',
			'seo'          => 'سئو',
			'cache'        => 'کش و سرعت',
			'performance'  => 'بهینه‌سازی رسانه',
			'security'     => 'امنیت',
			'forms'        => 'فرم‌ها',
			'ecommerce'    => 'فروشگاه',
			'backup'       => 'پشتیبان‌گیری',
			'analytics'    => 'آمار و رهگیری',
			'redirects'    => 'تغییر مسیر',
			'mail'         => 'ایمیل',
			'comments'     => 'دیدگاه و ضداسپم',
			'multilingual' => 'چندزبانه',
			'maintenance'  => 'حالت تعمیر / به‌زودی',
			'builder'      => 'صفحه‌ساز',
			'membership'   => 'عضویت',
		);
	}

	/**
	 * Capability labels.
	 *
	 * @return array
	 */
	public static function capability_labels() {
		return array(
			'sitemap'     => 'نقشهٔ سایت',
			'meta'        => 'عنوان و متای صفحات',
			'schema'      => 'دادهٔ ساختاریافته',
			'page_cache'  => 'کش صفحات',
			'db_cache'    => 'کش دیتابیس',
			'minify'      => 'فشرده‌سازی CSS/JS',
			'lazyload'    => 'بارگذاری تنبل',
			'image_opt'   => 'بهینه‌سازی تصویر',
			'webp'        => 'تبدیل به WebP',
			'firewall'    => 'فایروال',
			'malware'     => 'اسکن بدافزار',
			'two_factor'  => 'ورود دومرحله‌ای',
			'login_limit' => 'محدودسازی تلاش ورود',
			'forms'       => 'ساخت فرم تماس',
			'spam'        => 'ضداسپم',
			'redirect'    => 'تغییر مسیر',
			'log_404'     => 'ثبت خطای ۴۰۴',
			'backup'      => 'پشتیبان‌گیری',
			'smtp'        => 'ارسال ایمیل با SMTP',
			'analytics'   => 'آمار بازدید',
			'ecommerce'   => 'فروشگاه آنلاین',
			'multilingual' => 'چندزبانه',
			'maintenance' => 'حالت تعمیر',
			'builder'     => 'صفحه‌ساز',
			'membership'  => 'عضویت و اشتراک',
			'comments'    => 'مدیریت دیدگاه',
		);
	}

	/**
	 * Capabilities for which two active plugins are a real problem.
	 *
	 * @return array
	 */
	public static function critical_capabilities() {
		return array(
			'sitemap',
			'page_cache',
			'image_opt',
			'firewall',
			'malware',
			'two_factor',
			'backup',
			'smtp',
			'redirect',
			'log_404',
			'multilingual',
			'maintenance',
			'analytics',
			'ecommerce',
			'forms',
			'builder',
		);
	}

	/**
	 * Load every recipe.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$recipes ) {
			return self::$recipes;
		}

		$recipes = array();
		$files   = glob( PHM_DIR . 'includes/recipes/*.php' );

		if ( is_array( $files ) ) {
			sort( $files );
			foreach ( $files as $file ) {
				$loaded = require $file;
				if ( ! is_array( $loaded ) ) {
					continue;
				}
				foreach ( $loaded as $recipe ) {
					if ( empty( $recipe['id'] ) ) {
						continue;
					}
					$recipes[ $recipe['id'] ] = self::normalize( $recipe );
				}
			}
		}

		/**
		 * Filter the recipe library. Allows themes/plugins to register their
		 * own recipes without touching this plugin.
		 *
		 * @param array $recipes Recipe collection keyed by recipe id.
		 */
		$recipes = apply_filters( 'phm_recipes', $recipes );

		$recipes = self::attach_custom_rules( $recipes );

		self::$recipes = $recipes;

		return self::$recipes;
	}

	/**
	 * Get one recipe.
	 *
	 * @param string $id Recipe id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$recipes = self::all();
		return isset( $recipes[ $id ] ) ? $recipes[ $id ] : null;
	}

	/**
	 * Flush the in-memory cache (used after saving custom rules).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$recipes = null;
	}

	/**
	 * Fill in missing recipe keys.
	 *
	 * @param array $recipe Raw recipe.
	 * @return array
	 */
	private static function normalize( $recipe ) {
		$defaults = array(
			'id'           => '',
			'name'         => '',
			'family'       => 'core',
			'priority'     => 10,
			'plugins'      => array(),
			'detect'       => array(),
			'capabilities' => array(),
			'advisory'     => false,
			'rules'        => array(),
			'note'         => '',
		);

		$recipe = array_merge( $defaults, $recipe );

		$recipe['plugins']      = (array) $recipe['plugins'];
		$recipe['detect']       = (array) $recipe['detect'];
		$recipe['capabilities'] = (array) $recipe['capabilities'];
		$recipe['rules']        = (array) $recipe['rules'];

		foreach ( $recipe['rules'] as $index => $rule ) {
			$rule = array_merge(
				array(
					'id'         => $recipe['id'] . '_rule_' . $index,
					'label'      => '',
					'option'     => '',
					'path'       => array(),
					'value'      => null,
					'value_cb'   => '',
					'append'     => '',
					'risk'       => 'low',
					'conditions' => array(),
				),
				$rule
			);

			$rule['path'] = (array) $rule['path'];
			$recipe['rules'][ $index ] = $rule;
		}

		return $recipe;
	}

	/**
	 * Turn user defined custom rules into real rules on their target recipe.
	 *
	 * @param array $recipes Recipes.
	 * @return array
	 */
	private static function attach_custom_rules( $recipes ) {
		$settings = PHM_Settings::get( 'custom_rules', array() );

		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return $recipes;
		}

		foreach ( $settings as $custom ) {
			if ( empty( $custom['recipe'] ) || empty( $custom['option'] ) ) {
				continue;
			}
			$recipe_id = sanitize_key( $custom['recipe'] );
			if ( ! isset( $recipes[ $recipe_id ] ) ) {
				continue;
			}

			$path = isset( $custom['path'] ) ? (string) $custom['path'] : '';
			$path = trim( $path, '.' );
			$path = '' === $path ? array() : array_map( 'trim', explode( '.', $path ) );

			$recipes[ $recipe_id ]['rules'][] = array(
				'id'         => isset( $custom['id'] ) ? sanitize_key( $custom['id'] ) : 'custom_' . md5( $recipe_id . $custom['option'] . $path ),
				'label'      => isset( $custom['label'] ) && '' !== $custom['label'] ? $custom['label'] : 'قاعدهٔ دستی',
				'option'     => $custom['option'],
				'path'       => $path,
				'value'      => self::parse_value( isset( $custom['value'] ) ? $custom['value'] : '' ),
				'value_cb'   => '',
				'risk'       => isset( $custom['risk'] ) ? $custom['risk'] : 'medium',
				'conditions' => array(),
				'custom'     => true,
			);
		}

		return $recipes;
	}

	/**
	 * Convert an admin text field into a typed value.
	 *
	 * @param string $raw Raw value.
	 * @return mixed
	 */
	public static function parse_value( $raw ) {
		if ( is_bool( $raw ) || is_int( $raw ) || is_float( $raw ) || is_array( $raw ) ) {
			return $raw;
		}

		$raw = trim( (string) $raw );
		$low = strtolower( $raw );

		if ( in_array( $low, array( 'true', 'on', 'yes', '1' ), true ) && '1' !== $raw ) {
			return true;
		}
		if ( in_array( $low, array( 'false', 'off', 'no', '0' ), true ) && '0' !== $raw ) {
			return false;
		}
		if ( 'true' === $low ) {
			return true;
		}
		if ( 'false' === $low ) {
			return false;
		}
		if ( is_numeric( $raw ) ) {
			return false === strpos( $raw, '.' ) ? (int) $raw : (float) $raw;
		}

		// JSON arrays / objects.
		if ( '' !== $raw && ( '{' === $raw[0] || '[' === $raw[0] ) ) {
			$decoded = json_decode( $raw, true );
			if ( null !== $decoded ) {
				return $decoded;
			}
		}

		return $raw;
	}
}
