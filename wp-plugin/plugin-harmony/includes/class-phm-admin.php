<?php
/**
 * Admin UI: dashboard, history, settings, custom rules, help.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Admin
 */
class PHM_Admin {

	/**
	 * Menu slug.
	 */
	const SLUG = 'plugin-harmony';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'admin_post_phm_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_phm_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_phm_rollback', array( __CLASS__, 'handle_rollback' ) );
		add_action( 'admin_post_phm_add_rule', array( __CLASS__, 'handle_add_rule' ) );
		add_action( 'admin_post_phm_delete_rule', array( __CLASS__, 'handle_delete_rule' ) );
		add_action( 'admin_post_phm_rescan', array( __CLASS__, 'handle_rescan' ) );
	}

	/**
	 * Add the admin menu entry.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			'هماهنگ‌ساز افزونه‌ها',
			'هماهنگ‌ساز افزونه‌ها',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-admin-links',
			81
		);
	}

	/**
	 * Enqueue assets on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'phm-admin', PHM_URL . 'assets/css/admin.css', array(), PHM_VERSION );
		wp_enqueue_script( 'phm-admin', PHM_URL . 'assets/js/admin.js', array(), PHM_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Request handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Redirect back to a tab with a message.
	 *
	 * @param array $args Query args.
	 * @return void
	 */
	private static function redirect( $args = array() ) {
		$url = add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Guard admin-post requests.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'شما اجازهٔ انجام این کار را ندارید.' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		self::guard( 'phm_save_settings' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'suggest';
		if ( ! in_array( $mode, array( 'off', 'suggest', 'safe', 'full' ), true ) ) {
			$mode = 'suggest';
		}

		$families = array();
		if ( isset( $_POST['families'] ) ) {
			$raw      = (array) wp_unslash( $_POST['families'] );
			$families = array_map( 'sanitize_key', $raw );
		}

		$valid = array_keys( PHM_Registry::family_labels() );
		$families = array_values( array_intersect( $families, $valid ) );

		// Nothing checked means "none selected" for the user, but internally an
		// empty array means "all". Use an explicit marker.
		if ( empty( $families ) ) {
			$families = array( '__none__' );
		} elseif ( count( $families ) === count( $valid ) ) {
			$families = array();
		}

		$exclude = '';
		if ( isset( $_POST['exclude_plugins'] ) ) {
			$exclude = sanitize_textarea_field( wp_unslash( $_POST['exclude_plugins'] ) );
		}

		PHM_Settings::update(
			array(
				'mode'               => $mode,
				'auto_on_activation' => empty( $_POST['auto_on_activation'] ) ? 0 : 1,
				'auto_on_update'     => empty( $_POST['auto_on_update'] ) ? 0 : 1,
				'daily_scan'         => empty( $_POST['daily_scan'] ) ? 0 : 1,
				'delete_data'        => empty( $_POST['delete_data'] ) ? 0 : 1,
				'keep_backups'       => isset( $_POST['keep_backups'] ) ? max( 1, min( 100, (int) $_POST['keep_backups'] ) ) : 20,
				'families'           => $families,
				'exclude_plugins'    => PHM_Settings::parse_lines( $exclude ),
			)
		);

		PHM_Profile::flush();
		PHM_Detector::flush();

		self::redirect(
			array(
				'tab'     => 'settings',
				'phm_msg' => 'saved',
			)
		);
	}

	/**
	 * Apply selected changes.
	 *
	 * @return void
	 */
	public static function handle_apply() {
		self::guard( 'phm_apply' );

		$keys = array();
		if ( isset( $_POST['changes'] ) ) {
			$keys = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['changes'] ) );
		}

		if ( empty( $keys ) ) {
			self::redirect(
				array(
					'tab'     => 'dashboard',
					'phm_msg' => 'nothing_selected',
				)
			);
		}

		$plan     = PHM_Planner::build();
		$selected = PHM_Applier::filter_selected( $plan['changes'], $keys );

		$result = PHM_Applier::apply(
			$selected,
			array(
				'trigger' => 'manual',
				'auto'    => false,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::redirect(
				array(
					'tab'     => 'dashboard',
					'phm_msg' => 'error',
					'phm_why' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		self::redirect(
			array(
				'tab'     => 'history',
				'phm_msg' => 'applied',
				'phm_n'   => count( $result['items'] ),
			)
		);
	}

	/**
	 * Roll a batch back.
	 *
	 * @return void
	 */
	public static function handle_rollback() {
		self::guard( 'phm_rollback' );

		$batch = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
		$done  = PHM_Applier::rollback( $batch );

		if ( is_wp_error( $done ) ) {
			self::redirect(
				array(
					'tab'     => 'history',
					'phm_msg' => 'error',
					'phm_why' => rawurlencode( $done->get_error_message() ),
				)
			);
		}

		self::redirect(
			array(
				'tab'     => 'history',
				'phm_msg' => 'rolled_back',
				'phm_n'   => count( $done ),
			)
		);
	}

	/**
	 * Add a custom rule.
	 *
	 * @return void
	 */
	public static function handle_add_rule() {
		self::guard( 'phm_add_rule' );

		$recipe = isset( $_POST['rule_recipe'] ) ? sanitize_key( wp_unslash( $_POST['rule_recipe'] ) ) : '';
		$option = isset( $_POST['rule_option'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_option'] ) ) : '';
		$path   = isset( $_POST['rule_path'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_path'] ) ) : '';
		$value  = isset( $_POST['rule_value'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_value'] ) ) : '';
		$label  = isset( $_POST['rule_label'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_label'] ) ) : '';
		$risk   = isset( $_POST['rule_risk'] ) ? sanitize_key( wp_unslash( $_POST['rule_risk'] ) ) : 'medium';

		if ( '' === $recipe || '' === $option ) {
			self::redirect(
				array(
					'tab'     => 'rules',
					'phm_msg' => 'rule_invalid',
				)
			);
		}

		if ( ! in_array( $risk, array( 'low', 'medium', 'high' ), true ) ) {
			$risk = 'medium';
		}

		$rules = PHM_Settings::get( 'custom_rules', array() );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		$rules[] = array(
			'id'     => 'custom_' . substr( md5( $recipe . $option . $path . microtime() ), 0, 8 ),
			'recipe' => $recipe,
			'option' => $option,
			'path'   => $path,
			'value'  => $value,
			'label'  => $label,
			'risk'   => $risk,
		);

		PHM_Settings::update( array( 'custom_rules' => $rules ) );
		PHM_Registry::flush();

		self::redirect(
			array(
				'tab'     => 'rules',
				'phm_msg' => 'rule_added',
			)
		);
	}

	/**
	 * Delete a custom rule.
	 *
	 * @return void
	 */
	public static function handle_delete_rule() {
		self::guard( 'phm_delete_rule' );

		$id    = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_id'] ) ) : '';
		$rules = PHM_Settings::get( 'custom_rules', array() );
		$kept  = array();

		if ( is_array( $rules ) ) {
			foreach ( $rules as $rule ) {
				if ( isset( $rule['id'] ) && $rule['id'] === $id ) {
					continue;
				}
				$kept[] = $rule;
			}
		}

		PHM_Settings::update( array( 'custom_rules' => $kept ) );
		PHM_Registry::flush();

		self::redirect(
			array(
				'tab'     => 'rules',
				'phm_msg' => 'rule_deleted',
			)
		);
	}

	/**
	 * Force a fresh scan.
	 *
	 * @return void
	 */
	public static function handle_rescan() {
		self::guard( 'phm_rescan' );

		PHM_Profile::flush();
		PHM_Detector::flush();
		PHM_Registry::flush();

		self::redirect(
			array(
				'tab'     => 'dashboard',
				'phm_msg' => 'rescanned',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Screen entry point.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'dashboard' => 'داشبورد و اسکن',
			'history'   => 'تاریخچه و بازگردانی',
			'settings'  => 'تنظیمات',
			'rules'     => 'قواعد سفارشی',
			'help'      => 'راهنما',
		);

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}

		echo '<div class="wrap phm-wrap">';
		echo '<div class="phm-header">';
		echo '<div><h1>هماهنگ‌ساز افزونه‌ها</h1>';
		echo '<p class="phm-sub">تنظیمات همهٔ افزونه‌های فعال، بر اساس کارکرد واقعی سایت و هماهنگ با یکدیگر.</p></div>';
		echo '<div class="phm-mode">حالت فعلی: <strong>' . esc_html( self::mode_label( PHM_Settings::mode() ) ) . '</strong></div>';
		echo '</div>';

		self::render_notice();

		echo '<nav class="nav-tab-wrapper phm-tabs">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page' => self::SLUG,
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			$class = ( $slug === $tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'history':
				self::render_history();
				break;
			case 'settings':
				self::render_settings();
				break;
			case 'rules':
				self::render_rules();
				break;
			case 'help':
				self::render_help();
				break;
			default:
				self::render_dashboard();
				break;
		}

		echo '</div>';
	}

	/**
	 * Flash message.
	 *
	 * @return void
	 */
	private static function render_notice() {
		if ( ! isset( $_GET['phm_msg'] ) ) {
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['phm_msg'] ) );
		$n    = isset( $_GET['phm_n'] ) ? (int) $_GET['phm_n'] : 0;
		$why  = isset( $_GET['phm_why'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['phm_why'] ) ) ) : '';

		$messages = array(
			'saved'            => array( 'success', 'تنظیمات ذخیره شد.' ),
			'applied'          => array( 'success', sprintf( '%d تنظیم با موفقیت نوشته شد. نسخهٔ پشتیبان برای بازگردانی در تاریخچه ذخیره است.', $n ) ),
			'rolled_back'      => array( 'success', sprintf( '%d گزینه به مقدار قبلی بازگردانده شد.', $n ) ),
			'rescanned'        => array( 'success', 'اسکن دوباره انجام شد و کش‌ها پاک شدند.' ),
			'rule_added'       => array( 'success', 'قاعدهٔ سفارشی اضافه شد.' ),
			'rule_deleted'     => array( 'success', 'قاعدهٔ سفارشی حذف شد.' ),
			'nothing_selected' => array( 'warning', 'هیچ تغییری انتخاب نشده بود.' ),
			'rule_invalid'     => array( 'error', 'افزونهٔ هدف و نام گزینه الزامی است.' ),
			'error'            => array( 'error', 'انجام عملیات ممکن نشد: ' . $why ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$type = $messages[ $code ][0];
		$text = $messages[ $code ][1];

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	/**
	 * Localized mode label.
	 *
	 * @param string $mode Mode slug.
	 * @return string
	 */
	private static function mode_label( $mode ) {
		$labels = array(
			'off'     => 'غیرفعال',
			'suggest' => 'فقط پیشنهاد (بدون نوشتن)',
			'safe'    => 'اعمال خودکار تنظیمات کم‌خطر',
			'full'    => 'اعمال خودکار کم‌خطر و متوسط',
		);
		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
	}

	/**
	 * Format a stored value for display.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function format_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'فعال (true)' : 'غیرفعال (false)';
		}
		if ( null === $value ) {
			return '—';
		}
		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $json ) ) {
				return 'آرایه';
			}
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $json ) > 90 ) {
				return mb_substr( $json, 0, 87 ) . '…';
			}
			if ( strlen( $json ) > 90 ) {
				return substr( $json, 0, 87 ) . '…';
			}
			return $json;
		}
		if ( '' === $value ) {
			return '«خالی»';
		}
		return (string) $value;
	}

	/**
	 * Risk badge.
	 *
	 * @param string $risk Risk slug.
	 * @return string
	 */
	private static function risk_badge( $risk ) {
		$labels = array(
			'low'    => 'کم‌خطر',
			'medium' => 'متوسط',
			'high'   => 'دستی',
		);
		$label = isset( $labels[ $risk ] ) ? $labels[ $risk ] : $risk;
		return '<span class="phm-badge phm-' . esc_attr( $risk ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Conflict severity badge.
	 *
	 * @param string $severity high|medium.
	 * @return string
	 */
	private static function severity_badge( $severity ) {
		if ( 'high' === $severity ) {
			return '<span class="phm-badge phm-high">جدی</span>';
		}
		return '<span class="phm-badge phm-medium">متوسط</span>';
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Dashboard tab.
	 *
	 * @return void
	 */
	private static function render_dashboard() {
		$plan  = PHM_Planner::build();
		$stats = $plan['stats'];
		$profile = $plan['profile'];

		// Toolbar.
		echo '<div class="phm-toolbar">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'phm_rescan' );
		echo '<input type="hidden" name="action" value="phm_rescan" />';
		echo '<button type="submit" class="button">اسکن دوبارهٔ سایت</button>';
		echo '</form>';
		echo '<span class="phm-muted">آخرین اسکن: ' . esc_html( date_i18n( 'Y-m-d H:i', (int) $plan['generated'] ) ) . '</span>';
		echo '</div>';

		// Metrics.
		echo '<div class="phm-grid">';
		self::metric( 'پیشنهاد آمادهٔ اعمال', (int) $stats['pending'], 'good' );
		self::metric( 'تنظیمات درست فعلی', (int) $stats['already'], 'muted' );
		self::metric( 'تضاد بین افزونه‌ها', (int) $stats['conflicts'], $stats['conflicts'] ? 'bad' : 'good' );
		self::metric( 'افزونه‌های نیازمند بررسی دستی', (int) $stats['advisories'], 'muted' );
		echo '</div>';

		// Profile chips.
		echo '<div class="phm-card">';
		echo '<h2>پروفایل سایت</h2>';
		echo '<div class="phm-chips">';
		self::chip( 'وردپرس', $profile['wp_version'] );
		self::chip( 'PHP', $profile['php_version'] );
		self::chip( 'زبان', $profile['locale'] );
		self::chip( 'HTTPS', $profile['https'] ? 'فعال' : 'غیرفعال', $profile['https'] ? 'ok' : 'warn' );
		self::chip( 'پیوند یکتا', $profile['pretty_permalinks'] ? 'فعال' : 'ساده', $profile['pretty_permalinks'] ? 'ok' : 'warn' );
		self::chip( 'کش شیء', $profile['object_cache'] ? 'فعال' : 'غیرفعال', $profile['object_cache'] ? 'ok' : '' );
		self::chip( 'ووکامرس', $profile['woocommerce'] ? 'فعال' : 'غیرفعال' );
		self::chip( 'افزونه‌های فعال', (string) $profile['active_plugins'] );
		self::chip( 'سرور', $profile['server'] ? $profile['server'] : 'نامشخص' );
		echo '</div>';
		echo '<p class="phm-muted">' . esc_html( $profile['stack'] ) . '</p>';
		echo '</div>';

		// Manual suggestions.
		if ( ! empty( $plan['manual'] ) ) {
			echo '<div class="phm-card">';
			echo '<h2>کارهایی که باید دستی انجام دهید</h2>';
			echo '<ul class="phm-list">';
			foreach ( $plan['manual'] as $item ) {
				echo '<li><strong>' . esc_html( $item['title'] ) . '</strong><br />' . esc_html( $item['detail'] ) . '</li>';
			}
			echo '</ul></div>';
		}

		// Conflicts.
		echo '<div class="phm-card">';
		echo '<h2>تضادها و هم‌پوشانی‌ها</h2>';

		if ( empty( $plan['conflicts'] ) ) {
			echo '<p class="phm-ok">هیچ تضادی بین افزونه‌های فعال پیدا نشد.</p>';
		} else {
			echo '<table class="widefat striped phm-table"><thead><tr>';
			echo '<th>شدت</th><th>موضوع</th><th>افزونه‌ها</th><th>توضیح و اقدام پیشنهادی</th>';
			echo '</tr></thead><tbody>';
			foreach ( $plan['conflicts'] as $conflict ) {
				echo '<tr>';
				echo '<td>' . self::severity_badge( 'high' === $conflict['severity'] ? 'high' : 'medium' ) . '</td>';
				echo '<td><strong>' . esc_html( $conflict['title'] ) . '</strong></td>';
				echo '<td>' . esc_html( implode( '، ', $conflict['plugins'] ) ) . '</td>';
				echo '<td>' . esc_html( $conflict['message'] ) . '<br /><em>' . esc_html( $conflict['action'] ) . '</em></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		// Changes.
		echo '<div class="phm-card">';
		echo '<h2>پیشنهادهای تنظیمات</h2>';

		if ( empty( $plan['changes'] ) ) {
			echo '<p class="phm-ok">برای افزونه‌های شناسایی‌شده، تنظیمات قابل نوشتنی نیست یا همه از قبل درست است.</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="phm-apply-form">';
			wp_nonce_field( 'phm_apply' );
			echo '<input type="hidden" name="action" value="phm_apply" />';

			echo '<p class="phm-muted">موارد دلخواه را انتخاب کنید. قبل از نوشتن، مقدار فعلی هر گزینه ذخیره می‌شود و از تب «تاریخچه» قابل بازگردانی است.</p>';

			echo '<table class="widefat striped phm-table phm-changes"><thead><tr>';
			echo '<th class="phm-check"><input type="checkbox" id="phm-select-all" checked /></th>';
			echo '<th>افزونه</th><th>تنظیم</th><th>گزینه</th><th>مقدار فعلی</th><th>مقدار پیشنهادی</th><th>ریسک</th>';
			echo '</tr></thead><tbody>';

			$current_recipe = '';
			foreach ( $plan['changes'] as $change ) {
				$disabled = ( 'already' === $change['status'] );

				if ( $change['recipe'] !== $current_recipe ) {
					$current_recipe = $change['recipe'];
					echo '<tr class="phm-group"><td colspan="7"><strong>' . esc_html( $change['recipe_name'] ) . '</strong> — ' . esc_html( PHM_Detector::family_label( $change['family'] ) ) . '</td></tr>';
				}

				echo '<tr>';
				echo '<td class="phm-check">';
				if ( ! $disabled ) {
					echo '<input type="checkbox" name="changes[]" value="' . esc_attr( $change['key'] ) . '" checked class="phm-change-box" />';
				} else {
					echo '<span class="phm-dash">✓</span>';
				}
				echo '</td>';
				echo '<td>' . esc_html( $change['recipe_name'] ) . '</td>';
				echo '<td>' . esc_html( $change['label'] ) . ( $change['custom'] ? ' <em>(سفارشی)</em>' : '' ) . '</td>';
				echo '<td><code>' . esc_html( $change['option'] ) . ( $change['path'] ? '.' . esc_html( implode( '.', $change['path'] ) ) : '' ) . '</code></td>';

				if ( $disabled ) {
					echo '<td colspan="2" class="phm-muted">از قبل درست است</td>';
				} else {
					echo '<td>' . esc_html( self::format_value( $change['old'] ) ) . '</td>';
					echo '<td><strong>' . esc_html( self::format_value( $change['new'] ) ) . '</strong></td>';
				}
				echo '<td>' . self::risk_badge( $change['risk'] ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			echo '<p class="phm-actions"><button type="submit" class="button button-primary">اعمال موارد انتخاب‌شده</button> ';
			echo '<span class="phm-muted" id="phm-count"></span></p>';
			echo '</form>';
		}

		echo '</div>';

		// Advisories.
		if ( ! empty( $plan['advisories'] ) ) {
			echo '<div class="phm-card">';
			echo '<h2>پیشنهادهای دستی برای افزونه‌های بدون تنظیم خودکار</h2>';
			echo '<p class="phm-muted">این افزونه‌ها شناسایی شده‌اند اما ساختار تنظیماتشان اجازهٔ نوشتن امن نمی‌دهد؛ قانون طلایی هر خانواده را رعایت کنید.</p>';

			foreach ( $plan['advisories'] as $advisory ) {
				echo '<details class="phm-details">';
				echo '<summary><strong>' . esc_html( $advisory['name'] ) . '</strong>';
				if ( ! empty( $advisory['family'] ) ) {
					echo ' <span class="phm-tag">' . esc_html( PHM_Detector::family_label( $advisory['family'] ) ) . '</span>';
				}
				if ( ! empty( $advisory['related'] ) ) {
					echo ' <span class="phm-tag phm-tag-warn">هم‌خانواده: ' . esc_html( implode( '، ', $advisory['related'] ) ) . '</span>';
				}
				echo '</summary>';
				echo '<div class="phm-details-body">';
				echo '<p><em>' . esc_html( $advisory['reason'] ) . '</em></p>';
				echo '<p>' . esc_html( $advisory['advice'] ) . '</p>';
				echo '</div></details>';
			}

			echo '</div>';
		}

		// Skipped.
		if ( ! empty( $plan['skipped'] ) ) {
			echo '<div class="phm-card">';
			echo '<details class="phm-details"><summary>موارد رد‌شده در این اسکن (' . esc_html( (string) count( $plan['skipped'] ) ) . ')</summary>';
			echo '<div class="phm-details-body"><ul class="phm-list">';
			foreach ( $plan['skipped'] as $skip ) {
				$name = isset( $skip['recipe'] ) && isset( $plan['state'][ $skip['recipe'] ] ) ? $plan['state'][ $skip['recipe'] ]['name'] : $skip['recipe'];
				echo '<li><strong>' . esc_html( $name ) . ':</strong> ' . esc_html( $skip['reason'] ) . '</li>';
			}
			echo '</ul></div></details></div>';
		}
	}

	/**
	 * A metric box.
	 *
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @param string $tone  good|bad|muted.
	 * @return void
	 */
	private static function metric( $label, $value, $tone = 'muted' ) {
		printf(
			'<div class="phm-metric phm-%1$s"><span class="phm-metric-value">%2$s</span><span class="phm-metric-label">%3$s</span></div>',
			esc_attr( $tone ),
			esc_html( (string) $value ),
			esc_html( $label )
		);
	}

	/**
	 * A profile chip.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $tone  ok|warn|empty.
	 * @return void
	 */
	private static function chip( $label, $value, $tone = '' ) {
		printf(
			'<span class="phm-chip %1$s"><span class="phm-chip-label">%2$s</span> %3$s</span>',
			esc_attr( $tone ? 'phm-chip-' . $tone : '' ),
			esc_html( $label ),
			esc_html( (string) $value )
		);
	}

	/* ---------------------------------------------------------------------
	 * History
	 * ------------------------------------------------------------------ */

	/**
	 * History tab.
	 *
	 * @return void
	 */
	private static function render_history() {
		$log = PHM_Applier::get_log();

		echo '<div class="phm-card">';
		echo '<h2>تاریخچهٔ تغییرات</h2>';

		if ( empty( $log ) ) {
			echo '<p class="phm-muted">هنوز هیچ تنظیمی توسط هماهنگ‌ساز نوشته نشده است.</p>';
			echo '</div>';
			return;
		}

		foreach ( $log as $entry ) {
			$count = isset( $entry['items'] ) ? count( $entry['items'] ) : 0;
			$rolled = ! empty( $entry['rolled_back'] );

			echo '<details class="phm-details phm-log">';
			echo '<summary>';
			echo '<strong>' . esc_html( isset( $entry['time'] ) ? $entry['time'] : '' ) . '</strong> · ';
			echo esc_html( sprintf( '%d تغییر', $count ) ) . ' · ';
			echo '<span class="phm-tag">' . esc_html( self::trigger_label( isset( $entry['trigger'] ) ? $entry['trigger'] : '' ) ) . '</span>';
			if ( $rolled ) {
				echo ' <span class="phm-badge phm-medium">بازگردانده شد</span>';
			}
			echo '</summary>';

			echo '<div class="phm-details-body">';
			echo '<table class="widefat striped phm-table"><thead><tr><th>افزونه</th><th>تنظیم</th><th>گزینه</th><th>قبلی</th><th>جدید</th><th>وضعیت</th></tr></thead><tbody>';

			foreach ( $entry['items'] as $item ) {
				$status = isset( $item['status'] ) ? $item['status'] : '';
				echo '<tr>';
				echo '<td>' . esc_html( isset( $item['recipe_name'] ) ? $item['recipe_name'] : '' ) . '</td>';
				echo '<td>' . esc_html( isset( $item['label'] ) ? $item['label'] : '' ) . '</td>';
				echo '<td><code>' . esc_html( isset( $item['option'] ) ? $item['option'] : '' ) . ( ! empty( $item['path'] ) ? '.' . esc_html( implode( '.', (array) $item['path'] ) ) : '' ) . '</code></td>';
				echo '<td>' . esc_html( self::format_value( isset( $item['old'] ) ? $item['old'] : null ) ) . '</td>';
				echo '<td>' . esc_html( self::format_value( isset( $item['new'] ) ? $item['new'] : null ) ) . '</td>';
				echo '<td>' . esc_html( self::status_label( $status ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			if ( ! $rolled && ! empty( $entry['id'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="phm-inline-form" onsubmit="return confirm(\'همهٔ گزینه‌های این دسته به مقدار قبلی بازگردانده می‌شوند. ادامه می‌دهید؟\');">';
				wp_nonce_field( 'phm_rollback' );
				echo '<input type="hidden" name="action" value="phm_rollback" />';
				echo '<input type="hidden" name="batch" value="' . esc_attr( $entry['id'] ) . '" />';
				echo '<button type="submit" class="button">بازگردانی این دسته</button>';
				echo '</form>';
			}

			echo '</div></details>';
		}

		echo '</div>';
	}

	/**
	 * Trigger label.
	 *
	 * @param string $trigger Trigger slug.
	 * @return string
	 */
	private static function trigger_label( $trigger ) {
		$labels = array(
			'manual'     => 'دستی',
			'activation' => 'فعال‌سازی افزونه',
			'update'     => 'به‌روزرسانی افزونه',
			'cron'       => 'اسکن زمان‌بندی‌شده',
			'cli'        => 'خط فرمان',
		);
		return isset( $labels[ $trigger ] ) ? $labels[ $trigger ] : $trigger;
	}

	/**
	 * Item status label.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'applied'  => 'اعمال شد',
			'rejected' => 'افزونه نپذیرفت',
			'failed'   => 'ناموفق',
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private static function render_settings() {
		$settings = PHM_Settings::all();
		$families = PHM_Registry::family_labels();

		$selected = $settings['families'];
		if ( empty( $selected ) || ! is_array( $selected ) ) {
			$selected = array_keys( $families );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'phm_save_settings' );
		echo '<input type="hidden" name="action" value="phm_save_settings" />';

		echo '<div class="phm-card">';
		echo '<h2>حالت عملکرد</h2>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">حالت</th><td><select name="mode">';
		foreach ( array( 'suggest', 'safe', 'full', 'off' ) as $mode ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $mode ),
				selected( $settings['mode'], $mode, false ),
				esc_html( self::mode_label( $mode ) )
			);
		}
		echo '</select>';
		echo '<p class="description">«فقط پیشنهاد» امن‌ترین حالت است: هیچ چیزی خودکار نوشته نمی‌شود. «اعمال خودکار» تنظیمات را پس از فعال‌سازی افزونهٔ جدید و در اسکن روزانه می‌نویسد (همیشه با نسخهٔ پشتیبان).</p></td></tr>';

		echo '<tr><th scope="row">رویدادها</th><td>';
		self::checkbox( 'auto_on_activation', (bool) $settings['auto_on_activation'], 'بررسی و هماهنگ‌سازی هنگام فعال‌سازی افزونهٔ جدید' );
		self::checkbox( 'auto_on_update', (bool) $settings['auto_on_update'], 'بررسی مجدد پس از به‌روزرسانی افزونه‌ها' );
		self::checkbox( 'daily_scan', (bool) $settings['daily_scan'], 'اسکن روزانه برای شناسایی تنظیمات ناهماهنگ' );
		echo '</td></tr>';

		echo '<tr><th scope="row">نگهداری نسخهٔ پشتیبان</th><td>';
		printf(
			'<input type="number" min="1" max="100" name="keep_backups" value="%d" class="small-text" /> <span class="description">دستهٔ تغییرات</span>',
			(int) $settings['keep_backups']
		);
		echo '</td></tr>';

		echo '</tbody></table></div>';

		echo '<div class="phm-card">';
		echo '<h2>خانواده‌های مجاز</h2>';
		echo '<p class="phm-muted">اگر یک خانواده را خاموش کنید، هیچ تنظیمی از آن خانواده خوانده یا نوشته نمی‌شود.</p>';
		echo '<div class="phm-family-grid">';
		foreach ( $families as $slug => $label ) {
			$checked = in_array( $slug, $selected, true );
			printf(
				'<label class="phm-family"><input type="checkbox" name="families[]" value="%s"%s /> %s</label>',
				esc_attr( $slug ),
				checked( $checked, true, false ),
				esc_html( $label )
			);
		}
		echo '</div></div>';

		echo '<div class="phm-card">';
		echo '<h2>افزونه‌های مستثنی</h2>';
		echo '<p class="phm-muted">هر افزونه در یک خط؛ چیزی مثل <code>wp-rocket</code> یا <code>wordpress-seo/wp-seo.php</code>.</p>';
		echo '<textarea name="exclude_plugins" rows="4" class="large-text code">' . esc_textarea( implode( "\n", (array) $settings['exclude_plugins'] ) ) . '</textarea>';
		echo '</div>';

		echo '<div class="phm-card">';
		echo '<h2>پاک‌سازی</h2>';
		self::checkbox( 'delete_data', (bool) $settings['delete_data'], 'با حذف افزونه، همهٔ تنظیمات، تاریخچه و نسخه‌های پشتیبان هم پاک شوند' );
		echo '</div>';

		submit_button( 'ذخیرهٔ تنظیمات' );
		echo '</form>';
	}

	/**
	 * Checkbox helper.
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked Checked state.
	 * @param string $label   Label.
	 * @return void
	 */
	private static function checkbox( $name, $checked, $label ) {
		printf(
			'<label class="phm-check-line"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/* ---------------------------------------------------------------------
	 * Custom rules
	 * ------------------------------------------------------------------ */

	/**
	 * Rules tab.
	 *
	 * @return void
	 */
	private static function render_rules() {
		$recipes = PHM_Registry::all();
		$custom  = PHM_Settings::get( 'custom_rules', array() );

		echo '<div class="phm-card">';
		echo '<h2>قاعدهٔ سفارشی جدید</h2>';
		echo '<p class="phm-muted">اگر افزونه‌ای در فهرست دانش نیست، می‌توانید خودتان یک قاعده بسازید. قاعده فقط زمانی اجرا می‌شود که گزینه و کلید مسیر از قبل در سایت وجود داشته باشند؛ بنابراین نوشتن مقدار اشتباه در گزینه‌ای که افزونه نساخته است ممکن نیست.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'phm_add_rule' );
		echo '<input type="hidden" name="action" value="phm_add_rule" />';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="phm-rule-recipe">افزونهٔ هدف</label></th><td><select name="rule_recipe" id="phm-rule-recipe" required>';
		foreach ( $recipes as $id => $recipe ) {
			printf( '<option value="%s">%s (%s)</option>', esc_attr( $id ), esc_html( $recipe['name'] ), esc_html( PHM_Detector::family_label( $recipe['family'] ) ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="phm-rule-label">عنوان</label></th><td><input type="text" class="regular-text" name="rule_label" id="phm-rule-label" placeholder="مثلاً: خاموش‌کردن نقشهٔ سایت" /></td></tr>';
		echo '<tr><th scope="row"><label for="phm-rule-option">نام گزینه</label></th><td><input type="text" class="regular-text code" name="rule_option" id="phm-rule-option" placeholder="wpseo" required /><p class="description">همان نامی که در جدول options وردپرس ذخیره می‌شود.</p></td></tr>';
		echo '<tr><th scope="row"><label for="phm-rule-path">مسیر داخل گزینه</label></th><td><input type="text" class="regular-text code" name="rule_path" id="phm-rule-path" placeholder="enable_xml_sitemap" /><p class="description">با نقطه جدا می‌شود، مثل <code>mail.mailer</code>. برای گزینه‌های ساده خالی بگذارید.</p></td></tr>';
		echo '<tr><th scope="row"><label for="phm-rule-value">مقدار</label></th><td><input type="text" class="regular-text code" name="rule_value" id="phm-rule-value" placeholder="true / false / 1 / «متن»" /><p class="description">true و false به بولین، اعداد به عدد و متن‌های JSON به آرایه تبدیل می‌شوند.</p></td></tr>';

		echo '<tr><th scope="row">ریسک</th><td><select name="rule_risk">';
		echo '<option value="low">کم‌خطر — قابل اعمال خودکار در حالت «کم‌خطر»</option>';
		echo '<option value="medium" selected>متوسط — فقط در حالت «کامل» خودکار اعمال می‌شود</option>';
		echo '<option value="high">دستی — هیچ‌وقت خودکار اعمال نمی‌شود</option>';
		echo '</select></td></tr>';

		echo '</tbody></table>';
		submit_button( 'افزودن قاعده' );
		echo '</form></div>';

		echo '<div class="phm-card">';
		echo '<h2>قواعد سفارشی موجود</h2>';

		if ( empty( $custom ) ) {
			echo '<p class="phm-muted">قاعدهٔ سفارشی ثبت نشده است.</p>';
		} else {
			echo '<table class="widefat striped phm-table"><thead><tr><th>افزونه</th><th>عنوان</th><th>گزینه</th><th>مسیر</th><th>مقدار</th><th>ریسک</th><th></th></tr></thead><tbody>';
			foreach ( $custom as $rule ) {
				echo '<tr>';
				echo '<td>' . esc_html( isset( $rule['recipe'] ) ? $rule['recipe'] : '' ) . '</td>';
				echo '<td>' . esc_html( isset( $rule['label'] ) && '' !== $rule['label'] ? $rule['label'] : '—' ) . '</td>';
				echo '<td><code>' . esc_html( isset( $rule['option'] ) ? $rule['option'] : '' ) . '</code></td>';
				echo '<td><code>' . esc_html( isset( $rule['path'] ) ? $rule['path'] : '' ) . '</code></td>';
				echo '<td><code>' . esc_html( isset( $rule['value'] ) ? (string) $rule['value'] : '' ) . '</code></td>';
				echo '<td>' . self::risk_badge( isset( $rule['risk'] ) ? $rule['risk'] : 'medium' ) . '</td>';
				echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'phm_delete_rule' );
				echo '<input type="hidden" name="action" value="phm_delete_rule" />';
				echo '<input type="hidden" name="rule_id" value="' . esc_attr( isset( $rule['id'] ) ? $rule['id'] : '' ) . '" />';
				echo '<button type="submit" class="button-link-delete">حذف</button>';
				echo '</form></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Help
	 * ------------------------------------------------------------------ */

	/**
	 * Help tab.
	 *
	 * @return void
	 */
	private static function render_help() {
		echo '<div class="phm-card">';
		echo '<h2>این افزونه چطور کار می‌کند؟</h2>';
		echo '<ol class="phm-list">';
		echo '<li><strong>پروفایل سایت:</strong> نسخهٔ وردپرس و PHP، زبان، HTTPS، پیوند یکتا، کش شیء، وجود ووکامرس، سرور و تعداد محتوا خوانده می‌شود.</li>';
		echo '<li><strong>شناسایی افزونه‌ها:</strong> هر افزونهٔ فعال با فهرست دانش (Recipe) مطابقت داده می‌شود؛ هم با نام فایل و هم با کلاس/ثابت/گزینه، تا تغییر نام پوشه تشخیص را خراب نکند.</li>';
		echo '<li><strong>نقشهٔ قابلیت‌ها:</strong> برای هر کار (نقشهٔ سایت، کش صفحه، فایروال، پشتیبان‌گیری، …) یک افزونهٔ مرجع انتخاب می‌شود و تضادها گزارش می‌شوند.</li>';
		echo '<li><strong>ساخت نقشهٔ تغییرات:</strong> قواعد هر افزونه در برابر مقدار فعلی گزینه‌ها سنجیده می‌شود. فقط کلیدهایی که از قبل وجود دارند قابل نوشتن‌اند.</li>';
		echo '<li><strong>نوشتن امن:</strong> قبل از هر نوشتن، مقدار کل گزینه ذخیره می‌شود، بعد از نوشتن مقدار واقعی دوباره خوانده می‌شود و اگر افزونه مقدار را نپذیرفت، «افزونه نپذیرفت» ثبت می‌شود.</li>';
		echo '<li><strong>هنگام فعال‌سازی افزونهٔ جدید:</strong> هوک <code>activated_plugin</code> اجرا می‌شود، در درخواست بعدی مدیر کل سایت دوباره اسکن می‌شود و تنظیمات افزونهٔ تازه با بقیه هم‌راستا می‌شود.</li>';
		echo '</ol></div>';

		echo '<div class="phm-card">';
		echo '<h2>محدودیت‌های واقعی</h2>';
		echo '<p>وردپرس یک API استاندارد برای «تنظیمات همهٔ افزونه‌ها» ندارد؛ هر افزونه ساختار خودش را دارد. بنابراین این افزونه سه لایه دارد:</p>';
		echo '<ul class="phm-list">';
		echo '<li><strong>نوشتن خودکار</strong> فقط برای افزونه‌هایی که ساختار تنظیماتشان در فهرست دانش تعریف شده و کلید هدف از قبل در سایت وجود دارد.</li>';
		echo '<li><strong>پیشنهاد دستی</strong> برای افزونه‌هایی مثل Wordfence، LiteSpeed یا WPML که نوشتن اشتباه در آن‌ها می‌تواند آسیب واقعی بزند.</li>';
		echo '<li><strong>قاعدهٔ سفارشی</strong> برای بقیهٔ افزونه‌ها؛ با نام گزینه و مسیر کلید، بدون نیاز به کدنویسی.</li>';
		echo '</ul>';
		echo '<p>هیچ‌وقت این گزینه‌ها نوشته نمی‌شوند: <code>active_plugins</code>، <code>siteurl</code>، <code>home</code>، <code>permalink_structure</code>، <code>users_can_register</code>، <code>default_role</code>، رمزهای SMTP و کلیدهای امنیتی.</p>';
		echo '</div>';

		echo '<div class="phm-card">';
		echo '<h2>برای توسعه‌دهنده</h2>';
		echo '<p>می‌توانید فهرست دانش را با فیلتر گسترش دهید:</p>';
		echo '<pre class="phm-code">add_filter( \'phm_recipes\', function ( $recipes ) {
    $recipes[\'my-plugin\'] = array(
        \'id\'       => \'my-plugin\',
        \'name\'     => \'My Plugin\',
        \'family\'   => \'seo\',
        \'plugins\'  => array( \'my-plugin/my-plugin.php\' ),
        \'rules\'    => array(
            array(
                \'id\'     => \'my_rule\',
                \'label\'  => \'خاموش‌کردن نقشهٔ سایت تکراری\',
                \'option\' => \'my_plugin_settings\',
                \'path\'   => array( \'sitemap\' ),
                \'value\'  => false,
                \'risk\'   => \'low\',
            ),
        ),
    );
    return $recipes;
} );</pre>';
		echo '<p>فیلترهای دیگر: <code>phm_plan_changes</code>، <code>phm_protected_options</code>، <code>phm_capability_owner</code>، اکشن <code>phm_after_apply</code>.</p>';
		echo '</div>';

		echo '<div class="phm-card">';
		echo '<h2>خط فرمان (WP-CLI)</h2>';
		echo '<pre class="phm-code">wp harmony scan          # گزارش وضعیت و تضادها
wp harmony preview       # نمایش پیشنهادها بدون نوشتن
wp harmony apply         # اعمال همهٔ پیشنهادها
wp harmony apply --family=seo
wp harmony rollback &lt;batch&gt;</pre>';
		echo '</div>';
	}
}
