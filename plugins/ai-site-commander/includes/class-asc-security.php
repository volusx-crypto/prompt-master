<?php
/**
 * Authentication, API keys, scopes, rate limiting, and IP filtering for AI Site Commander.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Security {

	/**
	 * Active key record authenticated for the current request.
	 *
	 * @var array|null
	 */
	private static $current_key = null;

	/**
	 * Supported permission scopes.
	 *
	 * @return array
	 */
	public static function available_scopes() {
		return array(
			'*'                 => 'دسترسی کامل به تمام بخش‌ها (Full Control)',
			'read'              => 'خواندن وضعیت، محتوا، سئو و لاگ‌ها',
			'content:write'     => 'ایجاد و ویرایش نوشته‌ها، برگه‌ها، دسته‌ها و رسانه‌ها',
			'seo:write'         => 'ویرایش متا تایتل، دیسکریپشن، اسکیما، ایندکس و Alt تصاویر',
			'redirects:write'   => 'مدیریت ریدایرکت‌های ۳۰۱/۴۱۰ و خطاهای ۴۰۴',
			'woocommerce:write' => 'مدیریت محصولات، قیمت و موجودی ووکامرس',
			'snippets:write'    => 'افزودن و مدیریت کدهای سفارشی CSS/JS/PHP',
			'options:write'     => 'تغییر تنظیمات وردپرس (wp_options)',
			'files:write'       => 'ویرایش فایل‌های قالب همراه با بکاپ خودکار',
			'rollback:write'    => 'بازگردانی تغییرات (Rollback Snapshots)',
		);
	}

	/**
	 * Generate and persist a new API key; returns the raw token ONCE.
	 *
	 * @param string $label  Human-friendly key name.
	 * @param array  $scopes Array of scope strings.
	 * @return array
	 */
	public static function create_api_key( $label, array $scopes = array( '*' ) ) {
		global $wpdb;

		$label = sanitize_text_field( $label );
		if ( '' === $label ) {
			$label = 'AI Agent Key';
		}

		$valid_scopes = array_keys( self::available_scopes() );
		$clean_scopes = array_values( array_intersect( $scopes, $valid_scopes ) );
		if ( empty( $clean_scopes ) ) {
			$clean_scopes = array( '*' );
		}

		$random_part = bin2hex( random_bytes( 24 ) );
		$raw_key     = 'asc_live_' . $random_part;
		$key_prefix  = substr( $raw_key, 0, 16 );
		$key_hash    = hash( 'sha256', $raw_key );
		$hmac_secret = 'asc_sec_' . bin2hex( random_bytes( 20 ) );

		$wpdb->insert(
			ASC_DB::table( 'api_keys' ),
			array(
				'label'       => $label,
				'key_prefix'  => $key_prefix,
				'key_hash'    => $key_hash,
				'hmac_secret' => $hmac_secret,
				'scopes'      => wp_json_encode( $clean_scopes ),
				'is_active'   => 1,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return array(
			'id'          => (int) $wpdb->insert_id,
			'label'       => $label,
			'api_key'     => $raw_key,
			'key_prefix'  => $key_prefix,
			'hmac_secret' => $hmac_secret,
			'scopes'      => $clean_scopes,
		);
	}

	/**
	 * Revoke (deactivate or delete) an API key by ID.
	 *
	 * @param int $key_id Key row ID.
	 * @return bool
	 */
	public static function revoke_api_key( $key_id ) {
		global $wpdb;
		$deleted = $wpdb->delete(
			ASC_DB::table( 'api_keys' ),
			array( 'id' => absint( $key_id ) ),
			array( '%d' )
		);
		return false !== $deleted;
	}

	/**
	 * List stored API keys (never exposing hashes).
	 *
	 * @return array
	 */
	public static function list_api_keys() {
		global $wpdb;
		$table = ASC_DB::table( 'api_keys' );
		$rows  = $wpdb->get_results( "SELECT id, label, key_prefix, scopes, is_active, last_used_at, last_ip, created_at FROM {$table} ORDER BY id DESC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$decoded       = json_decode( (string) $row['scopes'], true );
			$row['scopes'] = is_array( $decoded ) ? $decoded : array();
		}
		return $rows;
	}

	/**
	 * Authenticate incoming WP_REST_Request and enforce scope, IP allowlist, rate limit, and optional HMAC.
	 *
	 * @param WP_REST_Request $request        Incoming request.
	 * @param string          $required_scope Scope needed for this endpoint.
	 * @return true|WP_Error
	 */
	public static function authorize_rest_request( WP_REST_Request $request, $required_scope = 'read' ) {
		// Allow authenticated WordPress admins directly (e.g. WP Admin AJAX/REST or Application Passwords).
		if ( current_user_can( 'manage_options' ) ) {
			self::$current_key = array(
				'id'     => 0,
				'label'  => 'wp-admin:' . wp_get_current_user()->user_login,
				'scopes' => array( '*' ),
			);
			return true;
		}

		$settings  = ASC_DB::get_settings();
		$client_ip = self::get_client_ip();

		// 1. Check IP allowlist if configured.
		if ( ! empty( $settings['ip_allowlist'] ) ) {
			$allowed_ips = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $settings['ip_allowlist'] ) ) );
			if ( ! empty( $allowed_ips ) && ! in_array( $client_ip, $allowed_ips, true ) ) {
				return new WP_Error(
					'asc_ip_forbidden',
					'آدرس IP درخواست‌کننده در لیست مجاز قرار ندارد.',
					array( 'status' => 403 )
				);
			}
		}

		// 2. Extract API key from X-ASC-API-Key or Authorization: Bearer <token>.
		$raw_key = trim( (string) $request->get_header( 'X-ASC-API-Key' ) );
		if ( '' === $raw_key ) {
			$auth_header = trim( (string) $request->get_header( 'Authorization' ) );
			if ( 0 === stripos( $auth_header, 'Bearer ' ) ) {
				$raw_key = trim( substr( $auth_header, 7 ) );
			}
		}

		if ( '' === $raw_key || 0 !== strpos( $raw_key, 'asc_live_' ) ) {
			return new WP_Error(
				'asc_missing_api_key',
				'کلید API معتبر ارسال نشده است. هدر X-ASC-API-Key یا Authorization: Bearer را تنظیم کنید.',
				array( 'status' => 401 )
			);
		}

		// 3. Rate limiting check per IP + key prefix.
		$limit = max( 10, absint( $settings['rate_limit_per_min'] ) );
		$rl_id = 'asc_rl_' . md5( $client_ip . '|' . substr( $raw_key, 0, 16 ) . '|' . gmdate( 'YmdHi' ) );
		$hits  = (int) get_transient( $rl_id );
		if ( $hits >= $limit ) {
			return new WP_Error(
				'asc_rate_limited',
				'تعداد درخواست‌ها در دقیقه بیش از حد مجاز است. لطفاً چند ثانیه صبر کنید.',
				array( 'status' => 429 )
			);
		}
		set_transient( $rl_id, $hits + 1, 70 );

		// 4. Lookup SHA-256 hash in database.
		global $wpdb;
		$table    = ASC_DB::table( 'api_keys' );
		$key_hash = hash( 'sha256', $raw_key );
		$record   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE key_hash = %s AND is_active = 1 LIMIT 1", $key_hash ),
			ARRAY_A
		);

		if ( ! $record || ! hash_equals( (string) $record['key_hash'], $key_hash ) ) {
			return new WP_Error(
				'asc_invalid_api_key',
				'کلید API نامعتبر یا غیرفعال است.',
				array( 'status' => 401 )
			);
		}

		// 5. Optional HMAC signature verification.
		if ( ! empty( $settings['require_hmac'] ) ) {
			$timestamp = (string) $request->get_header( 'X-ASC-Timestamp' );
			$signature = (string) $request->get_header( 'X-ASC-Signature' );
			if ( '' === $timestamp || '' === $signature || abs( time() - (int) $timestamp ) > 300 ) {
				return new WP_Error(
					'asc_invalid_hmac_timestamp',
					'امضای HMAC یا زمان درخواست نامعتبر است.',
					array( 'status' => 401 )
				);
			}
			$expected = hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), (string) $record['hmac_secret'] );
			if ( ! hash_equals( $expected, $signature ) ) {
				return new WP_Error(
					'asc_invalid_hmac',
					'امضای امنیتی HMAC مطابقت ندارد.',
					array( 'status' => 401 )
				);
			}
		}

		// 6. Check required scope.
		$scopes = json_decode( (string) $record['scopes'], true );
		$scopes = is_array( $scopes ) ? $scopes : array();
		if ( ! in_array( '*', $scopes, true ) && ! in_array( $required_scope, $scopes, true ) ) {
			return new WP_Error(
				'asc_insufficient_scope',
				sprintf( 'این کلید API مجوز دسترسی به اسکوپ (%s) را ندارد.', $required_scope ),
				array( 'status' => 403 )
			);
		}

		// Update last used metadata asynchronously-light.
		$wpdb->update(
			$table,
			array(
				'last_used_at' => current_time( 'mysql' ),
				'last_ip'      => $client_ip,
			),
			array( 'id' => (int) $record['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$record['scopes']  = $scopes;
		self::$current_key = $record;

		return true;
	}

	/**
	 * Return label of currently authenticated actor.
	 *
	 * @return string
	 */
	public static function current_actor_label() {
		if ( is_array( self::$current_key ) && ! empty( self::$current_key['label'] ) ) {
			return (string) self::$current_key['label'];
		}
		if ( is_user_logged_in() ) {
			return 'wp-user:' . wp_get_current_user()->user_login;
		}
		return 'internal-bridge';
	}

	/**
	 * Safely determine client IP address.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}
}
