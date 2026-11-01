<?php
/**
 * Database schema, default options, and audit logging for AI Site Commander.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_DB {

	const DB_VERSION = '1.0.0';

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::ensure_default_options();
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		// Intentionally preserve snapshots, redirects, and keys on temporary deactivation.
	}

	/**
	 * Upgrade schema if plugin version changed.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'asc_db_version', '' );
		if ( self::DB_VERSION !== $installed ) {
			self::create_tables();
			self::ensure_default_options();
		}
	}

	/**
	 * Return full table name for a short table slug.
	 *
	 * @param string $slug Short slug: api_keys, snapshots, queue, redirects, 404_logs, snippets, audit_logs.
	 * @return string
	 */
	public static function table( $slug ) {
		global $wpdb;
		return $wpdb->prefix . 'asc_' . $slug;
	}

	/**
	 * Create or update custom tables using dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$api_keys   = self::table( 'api_keys' );
		$snapshots  = self::table( 'snapshots' );
		$queue      = self::table( 'queue' );
		$redirects  = self::table( 'redirects' );
		$logs_404   = self::table( '404_logs' );
		$snippets   = self::table( 'snippets' );
		$audit_logs = self::table( 'audit_logs' );

		$sql = "CREATE TABLE {$api_keys} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(191) NOT NULL,
			key_prefix varchar(32) NOT NULL,
			key_hash char(64) NOT NULL,
			hmac_secret varchar(128) DEFAULT '' NOT NULL,
			scopes text NOT NULL,
			is_active tinyint(1) DEFAULT 1 NOT NULL,
			last_used_at datetime DEFAULT NULL,
			last_ip varchar(64) DEFAULT '' NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_hash (key_hash),
			KEY is_active (is_active)
		) {$charset_collate};

		CREATE TABLE {$snapshots} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			action_type varchar(100) NOT NULL,
			target_type varchar(60) NOT NULL,
			target_id varchar(255) NOT NULL,
			target_label varchar(255) DEFAULT '' NOT NULL,
			before_state longtext NOT NULL,
			after_state longtext DEFAULT NULL,
			actor varchar(120) DEFAULT 'ai-agent' NOT NULL,
			reason text DEFAULT NULL,
			rolled_back tinyint(1) DEFAULT 0 NOT NULL,
			rolled_back_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY target_lookup (target_type,target_id(100)),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			action_type varchar(100) NOT NULL,
			payload longtext NOT NULL,
			summary text NOT NULL,
			actor varchar(120) DEFAULT 'ai-agent' NOT NULL,
			status varchar(24) DEFAULT 'pending' NOT NULL,
			result_data longtext DEFAULT NULL,
			snapshot_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$redirects} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_path varchar(500) NOT NULL,
			target_url varchar(500) DEFAULT '' NOT NULL,
			status_code smallint(5) unsigned DEFAULT 301 NOT NULL,
			is_regex tinyint(1) DEFAULT 0 NOT NULL,
			hits bigint(20) unsigned DEFAULT 0 NOT NULL,
			is_active tinyint(1) DEFAULT 1 NOT NULL,
			note varchar(255) DEFAULT '' NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source_lookup (source_path(191)),
			KEY is_active (is_active)
		) {$charset_collate};

		CREATE TABLE {$logs_404} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_path varchar(500) NOT NULL,
			referer varchar(500) DEFAULT '' NOT NULL,
			user_agent varchar(255) DEFAULT '' NOT NULL,
			hits bigint(20) unsigned DEFAULT 1 NOT NULL,
			resolved tinyint(1) DEFAULT 0 NOT NULL,
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_path (request_path(191)),
			KEY resolved_hits (resolved,hits)
		) {$charset_collate};

		CREATE TABLE {$snippets} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(191) NOT NULL,
			snippet_type varchar(24) NOT NULL,
			code longtext NOT NULL,
			location varchar(40) DEFAULT 'frontend' NOT NULL,
			is_active tinyint(1) DEFAULT 1 NOT NULL,
			last_error text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY active_type (is_active,snippet_type)
		) {$charset_collate};

		CREATE TABLE {$audit_logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor varchar(120) NOT NULL,
			action_type varchar(100) NOT NULL,
			target varchar(255) DEFAULT '' NOT NULL,
			status varchar(30) NOT NULL,
			snapshot_id bigint(20) unsigned DEFAULT NULL,
			message text DEFAULT NULL,
			ip_address varchar(64) DEFAULT '' NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'asc_db_version', self::DB_VERSION );
	}

	/**
	 * Ensure baseline plugin settings exist.
	 */
	public static function ensure_default_options() {
		$defaults = array(
			'execution_mode'       => 'auto', // 'auto' (direct + snapshot) or 'approval' (staged queue).
			'rate_limit_per_min'   => 120,
			'require_hmac'         => 0,
			'ip_allowlist'         => '',
			'enable_404_monitor'   => 1,
			'enable_redirects'     => 1,
			'enable_snippets'      => 1,
			'enable_theme_editor'  => 1,
			'enable_native_seo'    => 1,
			'safe_mode_token'      => wp_generate_password( 24, false, false ),
			'custom_robots_append' => '',
		);

		$current = get_option( 'asc_settings', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( 'asc_settings', array_merge( $defaults, $current ) );
	}

	/**
	 * Retrieve plugin settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( 'asc_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args(
			$saved,
			array(
				'execution_mode'       => 'auto',
				'rate_limit_per_min'   => 120,
				'require_hmac'         => 0,
				'ip_allowlist'         => '',
				'enable_404_monitor'   => 1,
				'enable_redirects'     => 1,
				'enable_snippets'      => 1,
				'enable_theme_editor'  => 1,
				'enable_native_seo'    => 1,
				'safe_mode_token'      => '',
				'custom_robots_append' => '',
			)
		);
	}

	/**
	 * Write an audit log row.
	 *
	 * @param string   $actor       Who executed the action.
	 * @param string   $action_type Action identifier.
	 * @param string   $target      Target label/ID.
	 * @param string   $status      success|queued|error|rolled_back.
	 * @param string   $message     Human-readable message.
	 * @param int|null $snapshot_id Optional snapshot ID.
	 */
	public static function log_audit( $actor, $action_type, $target, $status, $message = '', $snapshot_id = null ) {
		global $wpdb;

		$wpdb->insert(
			self::table( 'audit_logs' ),
			array(
				'actor'       => sanitize_text_field( (string) $actor ),
				'action_type' => sanitize_key( (string) $action_type ),
				'target'      => sanitize_text_field( mb_substr( (string) $target, 0, 250 ) ),
				'status'      => sanitize_key( (string) $status ),
				'snapshot_id' => $snapshot_id ? absint( $snapshot_id ) : null,
				'message'     => sanitize_textarea_field( (string) $message ),
				'ip_address'  => ASC_Security::get_client_ip(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}
}
