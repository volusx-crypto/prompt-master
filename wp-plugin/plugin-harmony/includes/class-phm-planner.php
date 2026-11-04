<?php
/**
 * Planner: turns the site profile + detected plugins into a change plan.
 *
 * The planner never writes anything. It only reads the current values and
 * produces a list of proposed changes plus a conflict report.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Planner
 */
class PHM_Planner {

	/**
	 * Build the plan.
	 *
	 * @param array $args {
	 *     Optional. @type string $only_recipe Limit to one recipe id.
	 * }
	 * @return array
	 */
	public static function build( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'only_recipe' => '',
			)
		);

		$settings = PHM_Settings::all();
		$recipes  = PHM_Registry::all();
		$state    = PHM_Detector::detect( true );
		$caps     = PHM_Detector::capabilities( $state );
		$profile  = PHM_Profile::get();

		$context = array(
			'profile'  => $profile,
			'caps'     => $caps,
			'state'    => $state,
			'recipes'  => $recipes,
			'settings' => $settings,
		);

		$plan = array(
			'generated'  => time(),
			'profile'    => $profile,
			'caps'       => $caps,
			'state'      => $state,
			'changes'    => array(),
			'conflicts'  => array(),
			'advisories' => array(),
			'skipped'    => array(),
			'context'    => $context,
		);

		$plan['conflicts'] = self::find_conflicts( $state, $caps );

		foreach ( $state as $recipe_id => $info ) {
			if ( empty( $info['active'] ) ) {
				continue;
			}

			if ( '' !== $args['only_recipe'] && $args['only_recipe'] !== $recipe_id ) {
				continue;
			}

			$recipe = isset( $recipes[ $recipe_id ] ) ? $recipes[ $recipe_id ] : null;
			if ( ! $recipe ) {
				continue;
			}

			if ( ! PHM_Settings::is_family_enabled( $recipe['family'] ) ) {
				$plan['skipped'][] = array(
					'key'    => $recipe_id . ':family',
					'recipe' => $recipe_id,
					'reason' => 'خانوادهٔ «' . PHM_Detector::family_label( $recipe['family'] ) . '» در تنظیمات غیرفعال است.',
				);
				continue;
			}

			if ( PHM_Settings::is_plugin_excluded( $info['plugin_file'] ) ) {
				$plan['skipped'][] = array(
					'key'    => $recipe_id . ':excluded',
					'recipe' => $recipe_id,
					'reason' => 'این افزونه در فهرست استثناها قرار دارد.',
				);
				continue;
			}

			// A report-only recipe becomes writable as soon as the site owner
			// adds a custom rule for it.
			$has_custom_rules = false;
			foreach ( $recipe['rules'] as $candidate_rule ) {
				if ( ! empty( $candidate_rule['custom'] ) ) {
					$has_custom_rules = true;
					break;
				}
			}

			if ( ( ! empty( $recipe['advisory'] ) && ! $has_custom_rules ) || empty( $recipe['rules'] ) ) {
				$plan['advisories'][] = self::advisory_for_recipe( $recipe, $info, $context );
				continue;
			}

			foreach ( $recipe['rules'] as $rule ) {
				$change = self::evaluate_rule( $recipe, $rule, $context, $plan );
				if ( is_array( $change ) ) {
					if ( 'skipped' === $change['status'] ) {
						$plan['skipped'][] = $change;
					} else {
						$plan['changes'][] = $change;
					}
				}
			}
		}

		$plan['advisories'] = array_merge( $plan['advisories'], PHM_Advisor::advise( $state ) );
		$plan['manual']     = self::manual_suggestions( $profile, $caps, $state );

		/**
		 * Filter the proposed changes before they are shown / applied.
		 *
		 * @param array $changes Proposed changes.
		 * @param array $context Planner context.
		 */
		$plan['changes'] = apply_filters( 'phm_plan_changes', $plan['changes'], $context );

		$plan['stats'] = self::stats( $plan );

		return $plan;
	}

	/**
	 * Group changes by option and build statistics.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	private static function stats( $plan ) {
		$stats = array(
			'pending'  => 0,
			'already'  => 0,
			'options'  => array(),
			'families' => array(),
		);

		foreach ( $plan['changes'] as $change ) {
			if ( 'already' === $change['status'] ) {
				$stats['already']++;
				continue;
			}
			$stats['pending']++;
			if ( ! in_array( $change['option'], $stats['options'], true ) ) {
				$stats['options'][] = $change['option'];
			}
			if ( ! isset( $stats['families'][ $change['family'] ] ) ) {
				$stats['families'][ $change['family'] ] = 0;
			}
			$stats['families'][ $change['family'] ]++;
		}

		$stats['conflicts'] = count( $plan['conflicts'] );
		$stats['advisories'] = count( $plan['advisories'] );

		return $stats;
	}

	/**
	 * Find plugin family and capability overlaps.
	 *
	 * @param array $state Detection state.
	 * @param array $caps  Capability owners.
	 * @return array
	 */
	public static function find_conflicts( $state, $caps ) {
		$conflicts = array();
		$recipes   = PHM_Registry::all();

		$by_family = PHM_Detector::active_by_family( $state );
		$family_critical = array( 'seo', 'cache', 'security', 'backup', 'multilingual', 'maintenance', 'builder' );

		foreach ( $by_family as $family => $ids ) {
			if ( 'core' === $family || count( $ids ) < 2 ) {
				continue;
			}

			$names = array();
			foreach ( $ids as $id ) {
				$names[] = isset( $recipes[ $id ] ) ? $recipes[ $id ]['name'] : $id;
			}

			$severity = in_array( $family, $family_critical, true ) ? 'high' : 'medium';

			$conflicts[] = array(
				'id'       => 'family_' . $family,
				'type'     => 'family',
				'severity' => $severity,
				'title'    => 'هم‌پوشانی کامل در خانوادهٔ «' . PHM_Detector::family_label( $family ) . '»',
				'plugins'  => $names,
				'message'  => 'بیش از یک افزونه هم‌زمان در این خانواده فعال است: ' . implode( '، ', $names ) . '. این وضعیت باعث بارگذاری دوبارهٔ کد، تضاد تنظیمات و کندی سایت می‌شود.',
				'action'   => 'یکی از این افزونه‌ها را به‌عنوان مرجع نگه دارید و بقیه را غیرفعال کنید. توصیهٔ خودکار: ' . $names[0],
			);
		}

		foreach ( $caps as $capability => $info ) {
			if ( count( $info['owners'] ) < 2 ) {
				continue;
			}

			$names = array();
			foreach ( $info['owners'] as $id ) {
				$names[] = isset( $recipes[ $id ] ) ? $recipes[ $id ]['name'] : $id;
			}

			$conflicts[] = array(
				'id'       => 'cap_' . $capability,
				'type'     => 'capability',
				'severity' => $info['critical'] ? 'high' : 'medium',
				'title'    => 'دو افزونه یک کار را انجام می‌دهند: ' . PHM_Detector::capability_label( $capability ),
				'plugins'  => $names,
				'message'  => 'قابلیت «' . PHM_Detector::capability_label( $capability ) . '» هم‌زمان توسط ' . implode( '، ', $names ) . ' ارائه می‌شود.',
				'action'   => 'مرجع پیشنهادی: ' . $names[0] . ' — در افزونه‌های دیگر این قابلیت را خاموش کنید.',
			);
		}

		return $conflicts;
	}

	/**
	 * Evaluate one rule against the current site state.
	 *
	 * @param array $recipe  Recipe.
	 * @param array $rule    Rule.
	 * @param array $context Planner context.
	 * @param array $plan    Plan being built (for logging shaped skips).
	 * @return array Change or skip descriptor.
	 */
	private static function evaluate_rule( $recipe, $rule, $context, $plan ) {
		unset( $plan );

		$key = $recipe['id'] . ':' . $rule['id'];

		if ( '' === $rule['option'] ) {
			return array( 'key' => $key, 'status' => 'skipped', 'recipe' => $recipe['id'], 'reason' => 'قاعده گزینهٔ هدف ندارد.' );
		}

		if ( in_array( $rule['option'], PHM_Applier::protected_options(), true ) ) {
			return array( 'key' => $key, 'status' => 'skipped', 'recipe' => $recipe['id'], 'reason' => 'گزینهٔ «' . $rule['option'] . '» محافظت‌شده است.' );
		}

		if ( ! self::conditions_met( $rule, $recipe, $context ) ) {
			return array( 'key' => $key, 'status' => 'skipped', 'recipe' => $recipe['id'], 'reason' => 'شرط اجرای قاعده برقرار نیست.' );
		}

		$option_value = get_option( $rule['option'], null );
		if ( null === $option_value ) {
			return array( 'key' => $key, 'status' => 'skipped', 'recipe' => $recipe['id'], 'reason' => 'گزینهٔ «' . $rule['option'] . '» در سایت وجود ندارد (افزونه آن را نساخته است).' );
		}

		$found = true;
		$current = self::read_path( $option_value, $rule['path'], $found );

		if ( ! $found ) {
			return array( 'key' => $key, 'status' => 'skipped', 'recipe' => $recipe['id'], 'reason' => 'کلید «' . implode( '.', $rule['path'] ) . '» در نسخهٔ نصب‌شدهٔ این افزونه یافت نشد.' );
		}

		$value = $rule['value'];

		if ( ! empty( $rule['value_cb'] ) && is_callable( $rule['value_cb'] ) ) {
			$value = call_user_func( $rule['value_cb'], $context );
			if ( null === $value ) {
				return array( 'key' => $key, 'status' => 'skipped', 'recipe' => $recipe['id'], 'reason' => 'مقدار پیشنهادی برای این سایت قابل محاسبه نبود.' );
			}
		}

		// Some plugins store a comma separated list (exclusions, allowed
		// hosts, ...). Merge into it instead of destroying the owner's list.
		if ( ! empty( $rule['append'] ) && is_string( $value ) && '' !== trim( $value ) ) {
			$separator = (string) $rule['append'];
			$existing  = is_string( $current ) ? $current : '';
			$parts     = array_filter( array_map( 'trim', explode( $separator, $existing ) ) );
			$parts     = array_values( $parts );

			foreach ( explode( $separator, $value ) as $incoming ) {
				$incoming = trim( $incoming );
				if ( '' !== $incoming && ! in_array( $incoming, $parts, true ) ) {
					$parts[] = $incoming;
				}
			}

			$value = implode( $separator, $parts );
		}

		$status = self::values_equal( $current, $value ) ? 'already' : 'pending';

		return array(
			'key'         => $key,
			'status'      => $status,
			'recipe'      => $recipe['id'],
			'recipe_name' => $recipe['name'],
			'family'      => $recipe['family'],
			'label'       => $rule['label'],
			'option'      => $rule['option'],
			'path'        => $rule['path'],
			'old'         => $current,
			'new'         => $value,
			'risk'        => $rule['risk'],
			'custom'      => ! empty( $rule['custom'] ),
		);
	}

	/**
	 * Evaluate rule conditions.
	 *
	 * @param array $rule    Rule.
	 * @param array $recipe  Recipe.
	 * @param array $context Planner context.
	 * @return bool
	 */
	private static function conditions_met( $rule, $recipe, $context ) {
		$conditions = isset( $rule['conditions'] ) ? (array) $rule['conditions'] : array();

		foreach ( $conditions as $name => $expected ) {
			switch ( $name ) {
				case 'profile':
					foreach ( (array) $expected as $flag ) {
						if ( empty( $context['profile'][ $flag ] ) ) {
							return false;
						}
					}
					break;

				case 'not_profile':
					foreach ( (array) $expected as $flag ) {
						if ( ! empty( $context['profile'][ $flag ] ) ) {
							return false;
						}
					}
					break;

				case 'capability_primary':
					if ( empty( $context['caps'][ $expected ]['owner'] ) ) {
						return false;
					}
					if ( $context['caps'][ $expected ]['owner'] !== $recipe['id'] ) {
						return false;
					}
					break;

				case 'capability_secondary':
					if ( empty( $context['caps'][ $expected ]['owner'] ) ) {
						return false;
					}
					if ( $context['caps'][ $expected ]['owner'] === $recipe['id'] ) {
						return false;
					}
					break;

				case 'option_filled':
					$spec = (array) $expected;
					if ( empty( $spec['option'] ) ) {
						return false;
					}
					$raw   = get_option( $spec['option'], null );
					$found = true;
					$value = self::read_path( $raw, isset( $spec['path'] ) ? (array) $spec['path'] : array(), $found );
					if ( ! $found || '' === trim( (string) $value ) ) {
						return false;
					}
					break;

				default:
					break;
			}
		}

		return true;
	}

	/**
	 * Read a value at a path.
	 *
	 * @param mixed $value Source value.
	 * @param array $path  Path segments.
	 * @param bool  $found Set to false when the path does not exist.
	 * @return mixed
	 */
	public static function read_path( $value, $path, &$found ) {
		$found = true;
		$path  = (array) $path;

		if ( empty( $path ) ) {
			return $value;
		}

		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				$found = false;
				return null;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Loose comparison that still treats int 1 and string "1" as different
	 * when the stored type matters (plugins often rely on strict types).
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	public static function values_equal( $a, $b ) {
		if ( is_array( $a ) || is_array( $b ) ) {
			return $a === $b;
		}
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return (bool) $a === (bool) $b;
		}
		if ( is_int( $a ) || is_int( $b ) ) {
			return (string) $a === (string) $b;
		}
		return (string) $a === (string) $b;
	}

	/**
	 * Site-level advice that must stay manual (it depends on business intent).
	 *
	 * @param array $profile Site profile.
	 * @param array $caps    Capability owners.
	 * @param array $state   Detection state.
	 * @return array
	 */
	public static function manual_suggestions( $profile, $caps, $state ) {
		unset( $state );

		$out = array();

		if ( empty( $profile['pretty_permalinks'] ) ) {
			$out[] = array(
				'title'  => 'پیوندهای یکتا روی حالت ساده است',
				'detail' => 'نقشهٔ سایت، ریدایرکت و بخش زیادی از تنظیمات سئو بدون پیوند یکتا درست کار نمی‌کنند. تنظیمات ← پیوندهای یکتا ← «نام نوشته» را انتخاب کنید. این گزینه عمداً خودکار تغییر نمی‌کند، چون لینک‌های موجود را می‌شکند.',
			);
		}

		if ( '0' === (string) $profile['blog_public'] ) {
			$out[] = array(
				'title'  => 'موتورهای جست‌وجو از ایندکس سایت منع شده‌اند',
				'detail' => 'در تنظیمات ← خواندن، تیک «تشویق موتورهای جست‌وجو» برداشته شده است. تا این تیک برنگردد، هیچ تنظیم سئویی اثر بیرونی ندارد.',
			);
		}

		if ( ! empty( $profile['debug_mode'] ) ) {
			$out[] = array(
				'title'  => 'حالت اشکال‌زدایی روشن است',
				'detail' => 'WP_DEBUG روی سایت زنده، خطاها و مسیرها را برای بازدیدکنندگان نمایان می‌کند. پیش از انتشار آن را خاموش کنید.',
			);
		}

		if ( empty( $profile['https'] ) ) {
			$out[] = array(
				'title'  => 'سایت روی HTTPS نیست',
				'detail' => 'افزونه‌های امنیتی، پرداخت و برخی قابلیت‌های سئو به HTTPS نیاز دارند. گواهی SSL را نصب و آدرس سایت را به https تغییر دهید.',
			);
		}

		if ( empty( $caps['backup'] ) ) {
			$out[] = array(
				'title'  => 'هیچ افزونهٔ پشتیبان‌گیری فعالی پیدا نشد',
				'detail' => 'قبل از هر تغییر خودکار، پشتیبان‌گیری لازم است. یک افزونهٔ پشتیبان‌گیری نصب و زمان‌بندی آن را فعال کنید.',
			);
		}

		if ( empty( $caps['firewall'] ) ) {
			$out[] = array(
				'title'  => 'فایروال یا افزونهٔ امنیتی فعال نیست',
				'detail' => 'حداقل محدودسازی تلاش ورود را فعال کنید و برای فرم ورود از ورود دومرحله‌ای استفاده کنید.',
			);
		}

		if ( empty( $caps['spam'] ) && ! empty( $profile['has_blog'] ) ) {
			$out[] = array(
				'title'  => 'ضداسپم دیدگاه فعال نیست',
				'detail' => 'با فعال بودن دیدگاه، یک لایهٔ ضداسپم (Akismet یا Antispam Bee) لازم است؛ وگرنه صف تأیید دیدگاه پر از اسپم می‌شود.',
			);
		}

		if ( ! empty( $caps['page_cache'] ) && ! empty( $profile['object_cache'] ) ) {
			$out[] = array(
				'title'  => 'هم‌زمان کش صفحه و کش شیء فعال است',
				'detail' => 'در افزونهٔ کش صفحه، کش دیتابیس را خاموش کنید تا دو لایه روی هم کار نکنند؛ کش شیء (Redis/Memcached) کار دیتابیس را انجام می‌دهد.',
			);
		}

		if ( ! empty( $profile['woocommerce'] ) && empty( $caps['page_cache'] ) ) {
			$out[] = array(
				'title'  => 'فروشگاه بدون کش صفحه کار می‌کند',
				'detail' => 'برای فروشگاه، یک لایهٔ کش صفحه (با استثنای سبد خرید، تسویه و حساب کاربری) زمان بارگذاری را چند برابر بهتر می‌کند.',
			);
		}

		if ( '1' === (string) $profile['users_can_register'] && empty( $caps['membership'] ) ) {
			$out[] = array(
				'title'  => 'ثبت‌نام کاربران باز است اما افزونهٔ عضویت فعال نیست',
				'detail' => 'ثبت‌نام باز بدون مدیریت نقش‌ها و ضداسپم، ورودی ربات‌ها را زیاد می‌کند. ثبت‌نام را ببندید یا افزونهٔ عضویت با تأیید ایمیل فعال کنید.',
			);
		}

		if ( empty( $profile['families']['seo'] ) ) {
			$out[] = array(
				'title'  => 'افزونهٔ سئو فعال نیست',
				'detail' => 'بدون افزونهٔ سئو، عنوان، توضیح متا، نقشهٔ سایت و دادهٔ ساختاریافته مدیریت نمی‌شوند. یک افزونهٔ سئو انتخاب و بقیه را غیرفعال کنید.',
			);
		}

		if ( version_compare( (string) $profile['php_version'], '8.0', '<' ) ) {
			$out[] = array(
				'title'  => 'نسخهٔ PHP قدیمی است',
				'detail' => 'PHP ' . $profile['php_version'] . ' پشتیبانی امنیتی ندارد و افزونه‌های جدید روی آن کندتر یا ناسازگار هستند. حداقل PHP 8.1 توصیه می‌شود.',
			);
		}

		return $out;
	}

	/**
	 * Advisory entry for a recipe that only supports reporting.
	 *
	 * @param array $recipe  Recipe.
	 * @param array $info    Detection info.
	 * @param array $context Planner context.
	 * @return array
	 */
	private static function advisory_for_recipe( $recipe, $info, $context ) {
		$family_advice = PHM_Advisor::family_advice( $recipe['family'] );

		$related = array();
		if ( ! empty( $context['state'] ) ) {
			foreach ( $context['state'] as $id => $other ) {
				if ( $id === $recipe['id'] || empty( $other['active'] ) ) {
					continue;
				}
				if ( $other['family'] === $recipe['family'] ) {
					$related[] = $other['name'];
				}
			}
		}

		return array(
			'id'      => $recipe['id'],
			'name'    => $recipe['name'],
			'family'  => $recipe['family'],
			'reason'  => ! empty( $recipe['note'] ) ? $recipe['note'] : 'ساختار تنظیمات این افزونه به‌صورت خودکار قابل نوشتن نیست؛ پیشنهادها به‌صورت دستی ارائه می‌شوند.',
			'advice'  => $family_advice,
			'related' => $related,
			'known'   => true,
			'file'    => $info['plugin_file'],
		);
	}
}
