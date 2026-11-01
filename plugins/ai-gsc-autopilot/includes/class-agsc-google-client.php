<?php
/**
 * Zero-dependency Google Search Console API Client (Service Account RS256 JWT + OAuth 2.0).
 *
 * Uses native WordPress HTTP API (wp_remote_request) and PHP OpenSSL.
 *
 * @package AI_GSC_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGSC_Google_Client {

	const SCOPE_WEBMASTERS = 'https://www.googleapis.com/auth/webmasters';
	const TOKEN_URI        = 'https://oauth2.googleapis.com/token';
	const AUTH_URI         = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * Check whether Google credentials have been configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = AGSC_DB::get_settings();
		if ( 'service_account' === $settings['auth_method'] ) {
			$json = AGSC_DB::get_secret( 'service_account_json' );
			return '' !== $json && false !== strpos( $json, 'private_key' );
		}
		$refresh = AGSC_DB::get_secret( 'oauth_refresh_token' );
		return '' !== $refresh && ! empty( $settings['oauth_client_id'] );
	}

	/**
	 * Base64URL encode helper for JWT signing.
	 *
	 * @param string $input Raw bytes.
	 * @return string
	 */
	private static function base64url_encode( $input ) {
		return rtrim( strtr( base64_encode( $input ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Obtain a valid Google OAuth2 Access Token (cached for 52 minutes).
	 *
	 * @param bool $force_refresh Bypass transient cache.
	 * @return string|WP_Error
	 */
	public static function get_access_token( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( 'agsc_google_access_token' );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$settings = AGSC_DB::get_settings();

		if ( 'service_account' === $settings['auth_method'] ) {
			return self::fetch_service_account_token();
		}

		return self::fetch_oauth_refresh_token();
	}

	/**
	 * Sign an RS256 JWT assertion using a Google Cloud Service Account JSON key and exchange for an access token.
	 *
	 * @return string|WP_Error
	 */
	private static function fetch_service_account_token() {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'agsc_openssl_missing', 'اکستنشن OpenSSL در PHP سرور فعال نیست.' );
		}

		$raw_json = AGSC_DB::get_secret( 'service_account_json' );
		$sa       = json_decode( $raw_json, true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return new WP_Error( 'agsc_invalid_sa_json', 'فایل JSON اکانت سرویس گوگل تنظیم نشده یا نامعتبر است.' );
		}

		$now     = time();
		$header  = self::base64url_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$payload = self::base64url_encode(
			wp_json_encode(
				array(
					'iss'   => $sa['client_email'],
					'scope' => self::SCOPE_WEBMASTERS,
					'aud'   => self::TOKEN_URI,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);

		$signing_input = $header . '.' . $payload;
		$signature     = '';
		$signed        = openssl_sign( $signing_input, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256 );
		if ( ! $signed ) {
			return new WP_Error( 'agsc_jwt_sign_failed', 'خطا در امضای کلید خصوصی Service Account گوگل.' );
		}

		$jwt = $signing_input . '.' . self::base64url_encode( $signature );

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

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$err = isset( $body['error_description'] ) ? $body['error_description'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'agsc_sa_token_error', 'خطای دریافت توکن گوگل: ' . $err );
		}

		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 300 ) : 3100;
		set_transient( 'agsc_google_access_token', $body['access_token'], $ttl );

		return (string) $body['access_token'];
	}

	/**
	 * Build Google OAuth 2.0 consent URL for Web Application flow.
	 *
	 * @return string
	 */
	public static function get_oauth_authorize_url() {
		$settings     = AGSC_DB::get_settings();
		$redirect_uri = admin_url( 'admin.php?page=ai-gsc-autopilot&agsc_oauth_callback=1' );
		$state        = wp_create_nonce( 'agsc_oauth_state' );

		return add_query_arg(
			array(
				'client_id'              => $settings['oauth_client_id'],
				'redirect_uri'           => $redirect_uri,
				'response_type'          => 'code',
				'scope'                  => self::SCOPE_WEBMASTERS,
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => $state,
			),
			self::AUTH_URI
		);
	}

	/**
	 * Exchange OAuth 2.0 authorization code for access_token & refresh_token.
	 *
	 * @param string $code Authorization code returned by Google.
	 * @return true|WP_Error
	 */
	public static function exchange_oauth_code( $code ) {
		$settings      = AGSC_DB::get_settings();
		$client_secret = AGSC_DB::get_secret( 'oauth_client_secret' );
		$redirect_uri  = admin_url( 'admin.php?page=ai-gsc-autopilot&agsc_oauth_callback=1' );

		$response = wp_remote_post(
			self::TOKEN_URI,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => sanitize_text_field( $code ),
					'client_id'     => $settings['oauth_client_id'],
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$err = isset( $body['error_description'] ) ? $body['error_description'] : 'خطا در تبادل کد OAuth گوگل';
			return new WP_Error( 'agsc_oauth_exchange_failed', $err );
		}

		if ( ! empty( $body['refresh_token'] ) ) {
			AGSC_DB::save_secret( 'oauth_refresh_token', $body['refresh_token'] );
		}

		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 300 ) : 3100;
		set_transient( 'agsc_google_access_token', $body['access_token'], $ttl );

		return true;
	}

	/**
	 * Refresh OAuth 2.0 access token using stored refresh_token.
	 *
	 * @return string|WP_Error
	 */
	private static function fetch_oauth_refresh_token() {
		$settings      = AGSC_DB::get_settings();
		$client_secret = AGSC_DB::get_secret( 'oauth_client_secret' );
		$refresh_token = AGSC_DB::get_secret( 'oauth_refresh_token' );

		if ( '' === $refresh_token || empty( $settings['oauth_client_id'] ) ) {
			return new WP_Error( 'agsc_oauth_not_connected', 'حساب سرچ کنسول گوگل هنوز متصل نشده است.' );
		}

		$response = wp_remote_post(
			self::TOKEN_URI,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $settings['oauth_client_id'],
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'agsc_oauth_refresh_failed', 'تمدید خودکار توکن گوگل ناموفق بود؛ لطفاً مجدداً متصل شوید.' );
		}

		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 300 ) : 3100;
		set_transient( 'agsc_google_access_token', $body['access_token'], $ttl );

		return (string) $body['access_token'];
	}

	/**
	 * Perform an authenticated HTTP request against Google Search Console APIs.
	 *
	 * @param string     $method   GET|POST|PUT|DELETE.
	 * @param string     $endpoint Full Google API URL.
	 * @param array|null $payload  JSON body array if POST/PUT.
	 * @return array|WP_Error
	 */
	public static function request( $method, $endpoint, $payload = null ) {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $payload ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$response = wp_remote_request( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = '' !== $raw ? json_decode( $raw, true ) : array();

		if ( $code >= 400 ) {
			$err_msg = isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			return new WP_Error(
				'agsc_google_api_error',
				sprintf( 'خطای API سرچ کنسول گوگل (%d): %s', $code, $err_msg ),
				array( 'status' => $code )
			);
		}

		return is_array( $json ) ? $json : array();
	}

	/**
	 * List all verified Search Console properties accessible to the authenticated identity.
	 *
	 * @return array|WP_Error
	 */
	public static function list_sites() {
		return self::request( 'GET', 'https://www.googleapis.com/webmasters/v3/sites' );
	}

	/**
	 * Query Search Analytics data for the configured property.
	 *
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @param array  $dimensions e.g. array('page'), array('query'), array('page', 'query').
	 * @param int    $row_limit  Max rows (1..25000).
	 * @param string $property   Optional override property URL.
	 * @return array|WP_Error
	 */
	public static function query_search_analytics( $start_date, $end_date, array $dimensions = array( 'page' ), $row_limit = 250, $property = '' ) {
		$settings = AGSC_DB::get_settings();
		$site_url = '' !== $property ? $property : $settings['property_url'];
		$encoded  = rawurlencode( $site_url );
		$url      = "https://www.googleapis.com/webmasters/v3/sites/{$encoded}/searchAnalytics/query";

		return self::request(
			'POST',
			$url,
			array(
				'startDate'  => $start_date,
				'endDate'    => $end_date,
				'dimensions' => array_values( $dimensions ),
				'rowLimit'   => min( 5000, max( 1, absint( $row_limit ) ) ),
			)
		);
	}

	/**
	 * Inspect a live URL in Google's index using the URL Inspection API v1.
	 *
	 * @param string $inspection_url Full URL on the site to inspect.
	 * @param string $property       Optional override property URL.
	 * @return array|WP_Error
	 */
	public static function inspect_url( $inspection_url, $property = '' ) {
		$settings = AGSC_DB::get_settings();
		$site_url = '' !== $property ? $property : $settings['property_url'];

		return self::request(
			'POST',
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			array(
				'inspectionUrl' => esc_url_raw( $inspection_url ),
				'siteUrl'       => $site_url,
				'languageCode'  => 'fa-IR',
			)
		);
	}

	/**
	 * List sitemaps registered in Google Search Console for this property.
	 *
	 * @return array|WP_Error
	 */
	public static function list_sitemaps() {
		$settings = AGSC_DB::get_settings();
		$encoded  = rawurlencode( $settings['property_url'] );
		return self::request( 'GET', "https://www.googleapis.com/webmasters/v3/sites/{$encoded}/sitemaps" );
	}

	/**
	 * Submit or resubmit an XML sitemap URL in Google Search Console.
	 *
	 * @param string $sitemap_url Full sitemap URL (defaults to wp-sitemap.xml or sitemap_index.xml).
	 * @return array|WP_Error
	 */
	public static function submit_sitemap( $sitemap_url = '' ) {
		if ( '' === $sitemap_url ) {
			$sitemap_url = home_url( '/sitemap_index.xml' );
		}
		$settings     = AGSC_DB::get_settings();
		$encoded_site = rawurlencode( $settings['property_url'] );
		$encoded_feed = rawurlencode( esc_url_raw( $sitemap_url ) );

		$res = self::request( 'PUT', "https://www.googleapis.com/webmasters/v3/sites/{$encoded_site}/sitemaps/{$encoded_feed}" );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'submitted'   => true,
			'sitemap_url' => $sitemap_url,
		);
	}
}
