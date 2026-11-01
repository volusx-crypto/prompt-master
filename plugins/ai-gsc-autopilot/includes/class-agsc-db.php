<?php
/**
 * Database schema, encrypted secret storage, and default settings for AI Search Console Autopilot.
 *
 * @package AI_GSC_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGSC_DB {

	const DB_VERSION = '1.0.0';

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::ensure_default_options();

		if ( ! wp_next_scheduled( 'agsc_scheduled_sync_event' ) ) {
			wp_schedule_event( time() + 600, 'every_six_hours', 'agsc_scheduled_sync_event' );
		}
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'agsc_scheduled_sync_event' );
	}

	/**
	 * Upgrade database tables if needed.
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'agsc_db_version', '' ) ) {
			self::create_tables();
			self::ensure_default_options();
		}
	}

	/**
	 * Full table name helper.
	 *
	 * @param string $slug Short slug: issues, analytics, sync_logs.
	 * @return string
	 */
	public static function table( $slug ) {
		global $wpdb;
		return $wpdb->prefix . 'agsc_' . $slug;
	}

	/**
	 * Create custom database tables via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$issues          = self::table( 'issues' );
		$analytics       = self::table( 'analytics' );
		$sync_logs       = self::table( 'sync_logs' );

		$sql = "CREATE TABLE {$issues} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fingerprint char(64) NOT NULL,
			issue_type varchar(80) NOT NULL,
			severity varchar(20) DEFAULT 'medium' NOT NULL,
			url varchar(500) NOT NULL,
			post_id bigint(20) unsigned DEFAULT 0 NOT NULL,
			title varchar(255) DEFAULT '' NOT NULL,
			metrics_json longtext DEFAULT NULL,
			gsc_evidence_json longtext DEFAULT NULL,
			ai_diagnosis text DEFAULT NULL,
			fix_plan_json longtext DEFAULT NULL,
			status varchar(24) DEFAULT 'open' NOT NULL,
			execution_result_json longtext DEFAULT NULL,
			snapshot_id bigint(20) unsigned DEFAULT NULL,
			detected_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			fixed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY status_severity (status,severity),
			KEY issue_type (issue_type)
		) {$charset_collate};

		CREATE TABLE {$analytics} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			row_type varchar(30) NOT NULL,
			page_url varchar(500) DEFAULT '' NOT NULL,
			query_text varchar(255) DEFAULT '' NOT NULL,
			clicks int(11) DEFAULT 0 NOT NULL,
			impressions int(11) DEFAULT 0 NOT NULL,
			ctr decimal(8,4) DEFAULT 0.0000 NOT NULL,
			position decimal(8,2) DEFAULT 0.00 NOT NULL,
			prev_clicks int(11) DEFAULT 0 NOT NULL,
			prev_impressions int(11) DEFAULT 0 NOT NULL,
			prev_position decimal(8,2) DEFAULT 0.00 NOT NULL,
			synced_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY row_type_impressions (row_type,impressions),
			KEY page_lookup (page_url(191))
		) {$charset_collate};

		CREATE TABLE {$sync_logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sync_type varchar(50) NOT NULL,
			status varchar(24) NOT NULL,
			issues_found int(11) DEFAULT 0 NOT NULL,
			fixes_applied int(11) DEFAULT 0 NOT NULL,
			message text DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'agsc_db_version', self::DB_VERSION );
	}

	/**
	 * Initialize default settings.
	 */
	public static function ensure_default_options() {
		$defaults = array(
			'auth_method'            => 'service_account', // 'service_account' or 'oauth2'.
			'property_url'           => home_url( '/' ),
			'oauth_client_id'        => '',
			'ai_provider'            => 'openai', // 'openai', 'anthropic', 'gemini', 'custom'.
			'ai_model'               => 'gpt-4o',
			'ai_custom_base_url'     => '',
			'autopilot_auto_fix'     => 0,
			'auto_fix_safe_types'    => array( 'low_ctr_high_impressions', 'not_found_404', 'canonical_mismatch', 'noindex_blocked', 'robots_blocked', 'discovered_not_indexed', 'crawled_not_indexed', 'rich_results_error', 'redirect_page' ),
			'inspect_urls_per_sync'  => 25,
			'standalone_api_key'     => 'agsc_' . wp_generate_password( 32, false, false ),
		);

		$current = get_option( 'agsc_settings', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( 'agsc_settings', array_merge( $defaults, $current ) );
	}

	/**
	 * Get merged plugin settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( 'agsc_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args(
			$saved,
			array(
				'auth_method'           => 'service_account',
				'property_url'          => home_url( '/' ),
				'oauth_client_id'       => '',
				'ai_provider'           => 'openai',
				'ai_model'              => 'gpt-4o',
				'ai_custom_base_url'    => '',
				'autopilot_auto_fix'    => 0,
				'auto_fix_safe_types'   => array( 'low_ctr_high_impressions', 'not_found_404', 'canonical_mismatch', 'noindex_blocked', 'robots_blocked', 'discovered_not_indexed', 'crawled_not_indexed', 'rich_results_error', 'redirect_page' ),
				'inspect_urls_per_sync' => 25,
				'standalone_api_key'    => '',
			)
		);
	}

	/**
	 * Encrypt a sensitive secret before storing in wp_options using WordPress salts + AES-256-CBC.
	 *
	 * @param string $plaintext Secret string.
	 * @return void
	 * @param string $option_key Option key suffix.
	 */
	public static function save_secret( $option_key, $plaintext ) {
		$raw = (string) $plaintext;
		if ( '' === $raw ) {
			delete_option( 'agsc_sec_' . sanitize_key( $option_key ) );
			return;
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$key    = hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
			$iv     = random_bytes( 16 );
			$cipher = openssl_encrypt( $raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $cipher ) {
				$stored = 'enc1:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				update_option( 'agsc_sec_' . sanitize_key( $option_key ), $stored, false );
				return;
			}
		}

		update_option( 'agsc_sec_' . sanitize_key( $option_key ), 'raw:' . base64_encode( $raw ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret from wp_options.
	 *
	 * @param string $option_key Option key suffix.
	 * @return string
	 */
	public static function get_secret( $option_key ) {
		$stored = (string) get_option( 'agsc_sec_' . sanitize_key( $option_key ), '' );
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'enc1:' ) && function_exists( 'openssl_decrypt' ) ) {
			$decoded = base64_decode( substr( $stored, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false !== $decoded && strlen( $decoded ) > 16 ) {
				$iv     = substr( $decoded, 0, 16 );
				$cipher = substr( $decoded, 16 );
				$key    = hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
				$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
				return false !== $plain ? $plain : '';
			}
		}

		if ( 0 === strpos( $stored, 'raw:' ) ) {
			$decoded = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return false !== $decoded ? $decoded : '';
		}

		return '';
	}

	/**
	 * Record sync/autopilot activity log.
	 *
	 * @param string $sync_type     sync|autopilot|url_inspect.
	 * @param string $status        success|error.
	 * @param int    $issues_found  Count of issues detected.
	 * @param int    $fixes_applied Count of fixes applied.
	 * @param string $message       Summary message.
	 */
	public static function log_sync( $sync_type, $status, $issues_found = 0, $fixes_applied = 0, $message = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'sync_logs' ),
			array(
				'sync_type'     => sanitize_key( $sync_type ),
				'status'        => sanitize_key( $status ),
				'issues_found'  => absint( $issues_found ),
				'fixes_applied' => absint( $fixes_applied ),
				'message'       => sanitize_textarea_field( (string) $message ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}
}
