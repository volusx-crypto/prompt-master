<?php
/**
 * Two-Way Google Sheets Cloud Bridge for AI Site Commander.
 *
 * Connects WordPress (`volusx.com`) directly to the user's private Google Sheet
 * (`Volusx AI Live Bridge`) using the Google Cloud Service Account already configured
 * in AI Search Console Autopilot (or a custom Service Account key), enabling live
 * command execution and live Search Console / site telemetry inside cloud AI chats.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Cloud_Bridge {

	const DEFAULT_SPREADSHEET_ID = '1cLiGV8iBcHr37JeICHin8wCXQuLX0f0b_9Wmjvakfa8';
	const SCOPE_SPREADSHEETS     = 'https://www.googleapis.com/auth/spreadsheets';
	const TOKEN_URI              = 'https://oauth2.googleapis.com/token';
	const SHEETS_API_BASE        = 'https://sheets.googleapis.com/v4/spreadsheets/';
	const CRON_HOOK              = 'asc_cloud_bridge_cron';

	/**
	 * Register WP-Cron schedule and background sync hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync_now' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'asc_five_minutes', self::CRON_HOOK );
		}

		// Lightweight throttled sync when an admin visits WP Admin (at most once every 2 minutes).
		if ( is_admin() && ! wp_doing_ajax() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_background_admin_sync' ), 40 );
		}
	}

	/**
	 * Add 5-minute interval to WP-Cron.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function register_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['asc_five_minutes'] ) ) {
			$schedules['asc_five_minutes'] = array(
				'interval' => 300,
				'display'  => 'هر ۵ دقیقه (پل ابری فرمانده هوشمند)',
			);
		}
		return $schedules;
	}

	/**
	 * Throttled sync on admin page views if credentials exist.
	 */
	public static function maybe_background_admin_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = ASC_DB::get_settings();
		if ( empty( $settings['enable_cloud_bridge'] ) ) {
			return;
		}
		if ( get_transient( 'asc_bridge_recent_ping' ) ) {
			return;
		}
		if ( null === self::get_service_account_data() ) {
			return;
		}
		set_transient( 'asc_bridge_recent_ping', 1, 120 );
		self::sync_now();
	}

	/**
	 * Retrieve parsed Service Account JSON array (reusing AI GSC Autopilot credentials automatically).
	 *
	 * @return array|null
	 */
	public static function get_service_account_data() {
		// 1. Check custom override in AI Site Commander if stored.
		$custom_raw = get_option( 'asc_bridge_sa_json', '' );
		if ( is_string( $custom_raw ) && '' !== trim( $custom_raw ) ) {
			$parsed = json_decode( $custom_raw, true );
			if ( is_array( $parsed ) && ! empty( $parsed['client_email'] ) && ! empty( $parsed['private_key'] ) ) {
				return $parsed;
			}
		}

		// 2. Automatically reuse Service Account JSON from AI Search Console Autopilot (`AGSC_DB`).
		if ( class_exists( 'AGSC_DB' ) && method_exists( 'AGSC_DB', 'get_secret' ) ) {
			$agsc_raw = AGSC_DB::get_secret( 'service_account_json' );
			if ( is_string( $agsc_raw ) && '' !== trim( $agsc_raw ) ) {
				$parsed = json_decode( $agsc_raw, true );
				if ( is_array( $parsed ) && ! empty( $parsed['client_email'] ) && ! empty( $parsed['private_key'] ) ) {
					return $parsed;
				}
			}
		}

		return null;
	}

	/**
	 * Return the active Service Account `client_email` (for easy sharing instructions in UI).
	 *
	 * @return string
	 */
	public static function get_service_account_email() {
		$sa = self::get_service_account_data();
		return is_array( $sa ) && ! empty( $sa['client_email'] ) ? (string) $sa['client_email'] : '';
	}

	/**
	 * Return active target Google Spreadsheet ID.
	 *
	 * @return string
	 */
	public static function get_spreadsheet_id() {
		$settings = ASC_DB::get_settings();
		$raw      = isset( $settings['bridge_spreadsheet_id'] ) ? trim( (string) $settings['bridge_spreadsheet_id'] ) : '';
		if ( '' === $raw ) {
			return self::DEFAULT_SPREADSHEET_ID;
		}
		// Allow pasting full Google Sheets URL as well as raw spreadsheet ID.
		if ( preg_match( '#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $raw, $m ) ) {
			return $m[1];
		}
		return preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw );
	}

	/**
	 * Base64URL encode helper.
	 *
	 * @param string $input Raw bytes.
	 * @return string
	 */
	private static function base64url_encode( $input ) {
		return rtrim( strtr( base64_encode( $input ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Obtain an OAuth2 access token scoped to Google Sheets API v4.
	 *
	 * @param bool $force_refresh Ignore transient cache.
	 * @return string|WP_Error
	 */
	public static function get_sheets_access_token( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( 'asc_sheets_access_token' );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'asc_openssl_missing', 'اکستنشن OpenSSL در PHP هاست فعال نیست.' );
		}

		$sa = self::get_service_account_data();
		if ( ! is_array( $sa ) ) {
			return new WP_Error(
				'asc_missing_sa',
				'کلید JSON اکانت سرویس گوگل یافت نشد. ابتدا آن را در اتوپایلوت سرچ کنسول یا بخش پل ابری وارد کنید.'
			);
		}

		$now     = time();
		$header  = self::base64url_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$payload = self::base64url_encode(
			wp_json_encode(
				array(
					'iss'   => $sa['client_email'],
					'scope' => self::SCOPE_SPREADSHEETS,
					'aud'   => self::TOKEN_URI,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);

		$signing_input = $header . '.' . $payload;
		$signature     = '';
		if ( ! openssl_sign( $signing_input, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'asc_jwt_failed', 'خطا در امضای دیجیتال کلید خصوصی اکانت سرویس گوگل.' );
		}

		$jwt      = $signing_input . '.' . self::base64url_encode( $signature );
		$response = wp_remote_post(
			self::TOKEN_URI,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
			$err_desc = isset( $body['error_description'] ) ? $body['error_description'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'asc_token_http_error', 'خطا در دریافت توکن Google Sheets: ' . $err_desc );
		}

		set_transient( 'asc_sheets_access_token', $body['access_token'], 50 * MINUTE_IN_SECONDS );
		return (string) $body['access_token'];
	}

	/**
	 * Perform a Google Sheets v4 REST request.
	 *
	 * @param string     $method   GET|PUT|POST.
	 * @param string     $subpath  Path after `/v4/spreadsheets/{id}`.
	 * @param array|null $body     JSON payload.
	 * @return array|WP_Error
	 */
	private static function sheets_request( $method, $subpath, $body = null ) {
		$token = self::get_sheets_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$sheet_id = self::get_spreadsheet_id();
		$url      = self::SHEETS_API_BASE . rawurlencode( $sheet_id ) . $subpath;

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json; charset=utf-8',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : $raw;
			if ( 403 === $code ) {
				if ( false !== strpos( $msg, 'sheets.googleapis.com' ) || false !== strpos( $msg, 'has not been used' ) || false !== strpos( $msg, 'disabled' ) ) {
					return new WP_Error(
						'asc_sheets_api_disabled',
						'سرویس Google Sheets API در پروژه Google Cloud شما هنوز فعال نشده است. یک‌بار وارد Google Cloud -> APIs & Services -> Library شوید و Google Sheets API را Enable کنید. (' . $msg . ')'
					);
				}
				return new WP_Error(
					'asc_sheets_permission_denied',
					sprintf(
						'گوگل شیت پل ارتباطی هنوز با ایمیل اکانت سرویس شما به اشتراک گذاشته نشده است. کافی است ایمیل «%s» را در چت بفرستید (یا در دکمه Share گوگل شیت به عنوان Editor اضافه کنید).',
						self::get_service_account_email()
					)
				);
			}
			return new WP_Error( 'asc_sheets_http_' . $code, 'خطای Google Sheets (' . $code . '): ' . $msg );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Execute a full two-way sync cycle with the Google Sheet bridge:
	 * 1. Pull & execute pending commands from `Commands!A2:G200`
	 * 2. Push fresh `Site_Report` telemetry
	 * 3. Push fresh `Search_Console` issues & analytics
	 *
	 * @return array|WP_Error
	 */
	public static function sync_now() {
		$sheet_id = self::get_spreadsheet_id();
		if ( '' === $sheet_id ) {
			return new WP_Error( 'asc_empty_sheet_id', 'شناسه Google Sheet پل ابری خالی است.' );
		}

		// 1. Read queued commands.
		$cmd_range = rawurlencode( 'Commands!A2:G200' );
		$cmd_res   = self::sheets_request( 'GET', '/values/' . $cmd_range );
		if ( is_wp_error( $cmd_res ) ) {
			update_option(
				'asc_bridge_status',
				array(
					'ok'         => false,
					'checked_at' => current_time( 'mysql' ),
					'error_code' => $cmd_res->get_error_code(),
					'message'    => $cmd_res->get_error_message(),
				),
				false
			);
			return $cmd_res;
		}

		$rows          = isset( $cmd_res['values'] ) && is_array( $cmd_res['values'] ) ? $cmd_res['values'] : array();
		$executed_ids  = (array) get_option( 'asc_executed_bridge_cmds', array() );
		$executed_now  = 0;

		foreach ( $rows as $idx => $row ) {
			$row_num    = $idx + 2; // 1-based sheet row (row 1 is header).
			$cmd_id     = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
			$action     = isset( $row[1] ) ? sanitize_key( trim( (string) $row[1] ) ) : '';
			$params_raw = isset( $row[2] ) ? trim( (string) $row[2] ) : '{}';
			$status     = isset( $row[3] ) ? strtoupper( trim( (string) $row[3] ) ) : '';

			if ( '' === $cmd_id || '' === $action || 'PENDING' !== $status ) {
				continue;
			}

			if ( in_array( $cmd_id, $executed_ids, true ) ) {
				self::update_command_row( $row_num, 'SKIPPED_DUPLICATE', array( 'note' => 'Already executed earlier' ) );
				continue;
			}

			$params = json_decode( $params_raw, true );
			if ( ! is_array( $params ) ) {
				$params = array();
			}

			$exec_result = self::execute_bridge_command( $action, $params, 'cloud-bridge:' . $cmd_id );
			$executed_ids[] = $cmd_id;
			if ( count( $executed_ids ) > 500 ) {
				$executed_ids = array_slice( $executed_ids, -500 );
			}
			update_option( 'asc_executed_bridge_cmds', $executed_ids, false );

			if ( is_wp_error( $exec_result ) ) {
				self::update_command_row(
					$row_num,
					'ERROR',
					array(
						'code'    => $exec_result->get_error_code(),
						'message' => $exec_result->get_error_message(),
					)
				);
			} else {
				self::update_command_row( $row_num, 'DONE', $exec_result );
				++$executed_now;
			}
		}

		// 2. Push fresh Site_Report.
		self::push_site_report();

		// 3. Push fresh Search_Console issues if Plugin 2 is installed.
		self::push_gsc_report();

		$status_payload = array(
			'ok'            => true,
			'checked_at'    => current_time( 'mysql' ),
			'executed_cmds' => $executed_now,
			'message'       => sprintf( 'اتصال دوطرفه ابری برقرار است (%d دستور جدید اجرا شد).', $executed_now ),
		);
		update_option( 'asc_bridge_status', $status_payload, false );

		return $status_payload;
	}

	/**
	 * Dispatch a single command coming from the Cloud Bridge.
	 *
	 * @param string $action Action key.
	 * @param array  $params Action payload.
	 * @param string $actor  Actor label.
	 * @return array|WP_Error
	 */
	private static function execute_bridge_command( $action, array $params, $actor ) {
		if ( 'sync_gsc' === $action ) {
			if ( class_exists( 'AGSC_Issue_Detector' ) && method_exists( 'AGSC_Issue_Detector', 'sync_and_detect' ) ) {
				$sync = AGSC_Issue_Detector::sync_and_detect();
				if ( is_wp_error( $sync ) ) {
					return $sync;
				}
				return array(
					'synced' => true,
					'detail' => $sync,
				);
			}
			return new WP_Error( 'asc_gsc_inactive', 'افزونه اتوپایلوت سرچ کنسول فعال نیست.' );
		}

		if ( 'auto_fix_gsc_issue' === $action && ! empty( $params['issue_id'] ) ) {
			if ( class_exists( 'AGSC_Remediator' ) && method_exists( 'AGSC_Remediator', 'execute_issue_fix' ) ) {
				return AGSC_Remediator::execute_issue_fix( absint( $params['issue_id'] ) );
			}
			return new WP_Error( 'asc_gsc_remediator_missing', 'ماژول اصلاح خودکار سرچ کنسول یافت نشد.' );
		}

		if ( 'run_gsc_autopilot' === $action ) {
			if ( class_exists( 'AGSC_Remediator' ) && method_exists( 'AGSC_Remediator', 'run_autopilot_batch' ) ) {
				$limit = ! empty( $params['limit'] ) ? absint( $params['limit'] ) : 10;
				return AGSC_Remediator::run_autopilot_batch( $limit );
			}
			return new WP_Error( 'asc_gsc_remediator_missing', 'ماژول اتوپایلوت سرچ کنسول یافت نشد.' );
		}

		if ( 'configure_autolinks_manager' === $action ) {
			$seed = ! isset( $params['seed_links'] ) || ! empty( $params['seed_links'] );
			return ASC_Plugin_Inspector::auto_configure_autolinks_manager( $actor, $seed );
		}

		if ( 'remediate_gsc_indexing' === $action ) {
			$res = ASC_Indexing_Fixer::remediate_all( $actor );
			if ( ! is_wp_error( $res ) ) {
				update_option( 'asc_indexing_remediation_version', ASC_Indexing_Fixer::REMEDIATION_VERSION, false );
				update_option( 'asc_last_indexing_remediation', $res, false );
			}
			return $res;
		}

		return ASC_Engine::dispatch(
			$action,
			$params,
			array(
				'actor'  => $actor,
				'reason' => 'اجرای مستقیم از طریق پل ابری هوش مصنوعی (Volusx AI Live Bridge)',
			)
		);
	}

	/**
	 * Write execution status & JSON output back to `Commands!D{row}:F{row}`.
	 *
	 * @param int    $row_num 1-based sheet row.
	 * @param string $status  DONE|ERROR|SKIPPED_DUPLICATE.
	 * @param mixed  $result  Result array.
	 */
	private static function update_command_row( $row_num, $status, $result ) {
		$range      = sprintf( 'Commands!D%d:F%d', (int) $row_num, (int) $row_num );
		$json_str   = wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( strlen( $json_str ) > 45000 ) {
			$json_str = mb_substr( $json_str, 0, 45000 ) . '...[truncated]';
		}

		self::sheets_request(
			'PUT',
			'/values/' . rawurlencode( $range ) . '?valueInputOption=RAW',
			array(
				'range'          => $range,
				'majorDimension' => 'ROWS',
				'values'         => array(
					array(
						$status,
						current_time( 'mysql' ),
						$json_str,
					),
				),
			)
		);
	}

	/**
	 * Push live WordPress status, active plugins, and Autolinks Manager state to `Site_Report`.
	 */
	private static function push_site_report() {
		$overview = ASC_Engine::get_site_status();
		$audit    = ASC_Plugin_Inspector::audit_conflicts();
		$idx_diag = class_exists( 'ASC_Indexing_Fixer' ) ? ASC_Indexing_Fixer::get_diagnostic_report() : array();
		$now      = current_time( 'mysql' );

		$rows = array(
			array( 'key', 'value', 'updated_at', 'details' ),
			array( 'bridge_status', 'CONNECTED_LIVE', $now, home_url( '/' ) ),
			array( 'commander_version', ASC_VERSION, $now, 'PHP ' . PHP_VERSION . ' | WP ' . get_bloginfo( 'version' ) ),
			array( 'service_account_email', self::get_service_account_email(), $now, 'SEO Provider: ' . $overview['seo_provider'] ),
			array( 'published_counts', wp_json_encode( $overview['counts'], JSON_UNESCAPED_UNICODE ), $now, 'Theme: ' . $overview['active_theme']['name'] ),
			array( 'indexing_remediation_version', isset( $idx_diag['remediation_version'] ) ? (string) $idx_diag['remediation_version'] : 'n/a', $now, wp_json_encode( $idx_diag['last_remediation_summary'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
			array( 'crawlable_money_pool', wp_json_encode( $idx_diag['crawlable_money_pool'] ?? array(), JSON_UNESCAPED_UNICODE ), $now, 'Remaining unintended noindex: ' . (int) ( $idx_diag['remaining_product_post_ni'] ?? 0 ) ),
			array( 'unresolved_404_sample', wp_json_encode( $idx_diag['unresolved_404_sample'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $now, 'Unresolved 404 count: ' . (int) ( $idx_diag['unresolved_404_count'] ?? 0 ) ),
			array( 'captured_5xx_errors', wp_json_encode( $idx_diag['captured_5xx_errors'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $now, 'Frontend PHP fatal monitor' ),
			array( 'active_plugins_count', (string) $audit['active_plugins_count'], $now, wp_json_encode( array_column( $audit['active_plugins'], 'file' ) ) ),
			array( 'autolinks_rules_count', (string) $audit['autolinks_rules_count'], $now, 'daextam options: ' . $audit['daam_options_found'] ),
			array( 'autolinks_key_options', wp_json_encode( array_intersect_key( $audit['daam_options'], array_flip( array(
				'daextam_defaults_post_types',
				'daextam_defaults_left_boundary',
				'daextam_defaults_right_boundary',
				'daextam_defaults_limit',
				'daextam_advanced_random_prioritization',
				'daextam_advanced_general_limit_amount',
				'daextam_advanced_same_url_limit',
				'daextam_advanced_protect_attributes',
				'daextam_advanced_ignore_self_autolinks',
			) ) ), JSON_UNESCAPED_UNICODE ), $now, 'Live Daextam core settings' ),
		);

		foreach ( array_slice( $audit['autolinks_rules'], 0, 35 ) as $r ) {
			$rows[] = array(
				'autolink_rule_' . ( isset( $r['autolink_id'] ) ? $r['autolink_id'] : '' ),
				isset( $r['keyword'] ) ? (string) $r['keyword'] : '',
				$now,
				( isset( $r['url'] ) ? rawurldecode( (string) $r['url'] ) : '' ) . ' (prio=' . ( isset( $r['priority'] ) ? $r['priority'] : '' ) . ')',
			);
		}

		self::sheets_request( 'POST', '/values/' . rawurlencode( 'Site_Report!A1:D100' ) . ':clear', new stdClass() );
		self::sheets_request(
			'PUT',
			'/values/' . rawurlencode( 'Site_Report!A1' ) . '?valueInputOption=RAW',
			array(
				'range'          => 'Site_Report!A1',
				'majorDimension' => 'ROWS',
				'values'         => $rows,
			)
		);
	}

	/**
	 * Push detected Search Console issues & top pages from `AI Search Console Autopilot` to `Search_Console`.
	 */
	private static function push_gsc_report() {
		$rows = array(
			array( 'issue_id', 'url', 'issue_type', 'severity', 'impressions', 'clicks', 'position', 'ai_diagnosis' ),
		);

		if ( class_exists( 'AGSC_DB' ) ) {
			global $wpdb;
			$issues_table = AGSC_DB::table( 'issues' );
			$found_table  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $issues_table ) );
			if ( $found_table === $issues_table ) {
				$issues = $wpdb->get_results(
					"SELECT id, url, post_id, title, issue_type, severity, metrics_json, ai_diagnosis, status
					 FROM `{$issues_table}`
					 ORDER BY CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END, id DESC
					 LIMIT 120", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				);
				if ( is_array( $issues ) && ! empty( $issues ) ) {
					foreach ( $issues as $iss ) {
						$metrics = json_decode( (string) $iss['metrics_json'], true );
						$impr    = isset( $metrics['impressions'] ) ? (string) $metrics['impressions'] : '0';
						$clicks  = isset( $metrics['clicks'] ) ? (string) $metrics['clicks'] : '0';
						$pos     = isset( $metrics['position'] ) ? (string) $metrics['position'] : '0';
						$rows[]  = array(
							(string) $iss['id'],
							rawurldecode( (string) $iss['url'] ),
							(string) $iss['issue_type'] . ' [' . $iss['status'] . ']',
							(string) $iss['severity'],
							$impr,
							$clicks,
							$pos,
							mb_substr( (string) ( $iss['ai_diagnosis'] ? $iss['ai_diagnosis'] : $iss['title'] ), 0, 500 ),
						);
					}
				} else {
					$settings = method_exists( 'AGSC_DB', 'get_settings' ) ? AGSC_DB::get_settings() : array();
					$rows[]   = array(
						'0',
						isset( $settings['property_url'] ) ? (string) $settings['property_url'] : 'sc-domain:volusx.com',
						'NO_ISSUES_SYNCED_YET',
						'info',
						'0',
						'0',
						'0',
						'هنوز دکمه همگام‌سازی سرچ کنسول زده نشده یا خطایی ثبت نشده است.',
					);
				}
			}
		}

		self::sheets_request( 'POST', '/values/' . rawurlencode( 'Search_Console!A1:H150' ) . ':clear', new stdClass() );
		self::sheets_request(
			'PUT',
			'/values/' . rawurlencode( 'Search_Console!A1' ) . '?valueInputOption=RAW',
			array(
				'range'          => 'Search_Console!A1',
				'majorDimension' => 'ROWS',
				'values'         => $rows,
			)
		);
	}
}
