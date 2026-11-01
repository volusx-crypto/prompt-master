<?php
/**
 * Custom Snippets (with crash-safe killswitch), WP Options manager, and Theme File Editor.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Code_Files {

	/**
	 * Currently executing PHP snippet ID (for fatal error shutdown recovery).
	 *
	 * @var int
	 */
	private static $executing_snippet_id = 0;

	/**
	 * Sensitive WordPress options protected from external API mutation.
	 *
	 * @var array
	 */
	private static $protected_options = array(
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'active_plugins',
		'uninstall_plugins',
		'asc_settings',
		'asc_db_version',
		'agsc_settings',
		'agsc_oauth_tokens',
		'cron',
	);

	/**
	 * Initialize snippet execution hooks and fatal-error shutdown monitor.
	 */
	public static function init() {
		if ( self::is_safe_mode() ) {
			return;
		}

		$settings = ASC_DB::get_settings();
		if ( empty( $settings['enable_snippets'] ) ) {
			return;
		}

		register_shutdown_function( array( __CLASS__, 'handle_fatal_shutdown' ) );

		add_action( 'init', array( __CLASS__, 'run_php_snippets' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'render_head_snippets' ), 90 );
		add_action( 'wp_footer', array( __CLASS__, 'render_footer_snippets' ), 90 );
	}

	/**
	 * Check whether emergency Safe Mode is active via query token or wp-config constant.
	 *
	 * @return bool
	 */
	public static function is_safe_mode() {
		if ( defined( 'ASC_SAFE_MODE' ) && ASC_SAFE_MODE ) {
			return true;
		}
		if ( isset( $_GET['asc_safe_mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$settings = ASC_DB::get_settings();
			$token    = sanitize_text_field( wp_unslash( $_GET['asc_safe_mode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $settings['safe_mode_token'] ) && hash_equals( (string) $settings['safe_mode_token'], $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shutdown handler that automatically disables any PHP snippet that caused a fatal error.
	 */
	public static function handle_fatal_shutdown() {
		if ( ! self::$executing_snippet_id ) {
			return;
		}
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			global $wpdb;
			$wpdb->update(
				ASC_DB::table( 'snippets' ),
				array(
					'is_active'  => 0,
					'last_error' => sprintf( 'Fatal Error: %s on line %d', $error['message'], $error['line'] ),
				),
				array( 'id' => (int) self::$executing_snippet_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Execute active PHP snippets matching current request location.
	 */
	public static function run_php_snippets() {
		global $wpdb;
		$table = ASC_DB::table( 'snippets' );
		$rows  = $wpdb->get_results(
			"SELECT id, code, location FROM {$table} WHERE is_active = 1 AND snippet_type = 'php' ORDER BY id ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		$in_admin = is_admin();
		foreach ( $rows as $row ) {
			if ( 'frontend' === $row['location'] && $in_admin ) {
				continue;
			}
			if ( 'admin' === $row['location'] && ! $in_admin ) {
				continue;
			}

			$code = trim( (string) $row['code'] );
			$code = preg_replace( '/^<\?(php)?/i', '', $code );
			$code = preg_replace( '/\?>$/', '', $code );

			self::$executing_snippet_id = (int) $row['id'];
			try {
				eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Intentional admin/API snippet runner protected by scope + shutdown killswitch.
			} catch ( \Throwable $e ) {
				$wpdb->update(
					$table,
					array(
						'is_active'  => 0,
						'last_error' => $e->getMessage(),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%d', '%s' ),
					array( '%d' )
				);
			}
			self::$executing_snippet_id = 0;
		}
	}

	/**
	 * Output active CSS and HTML Head snippets on frontend.
	 */
	public static function render_head_snippets() {
		global $wpdb;
		$table = ASC_DB::table( 'snippets' );
		$rows  = $wpdb->get_results(
			"SELECT id, snippet_type, code FROM {$table} WHERE is_active = 1 AND snippet_type IN ('css', 'html_head') ORDER BY id ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( 'css' === $row['snippet_type'] ) {
				echo '<style id="asc-snippet-' . (int) $row['id'] . '">' . "\n" . wp_strip_all_tags( $row['code'] ) . "\n</style>\n";
			} else {
				echo $row['code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}

	/**
	 * Output active JS and HTML Footer snippets on frontend.
	 */
	public static function render_footer_snippets() {
		global $wpdb;
		$table = ASC_DB::table( 'snippets' );
		$rows  = $wpdb->get_results(
			"SELECT id, snippet_type, code FROM {$table} WHERE is_active = 1 AND snippet_type IN ('js', 'html_footer') ORDER BY id ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( 'js' === $row['snippet_type'] ) {
				echo '<script id="asc-snippet-' . (int) $row['id'] . '">' . "\n" . $row['code'] . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo $row['code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}

	/**
	 * List all stored snippets.
	 *
	 * @return array
	 */
	public static function list_snippets() {
		global $wpdb;
		$table = ASC_DB::table( 'snippets' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create or update a custom code snippet with Snapshot.
	 *
	 * @param array  $data   Snippet payload.
	 * @param string $actor  Actor name.
	 * @param string $reason Audit reason.
	 * @return array|WP_Error
	 */
	public static function save_snippet( array $data, $actor = 'ai-agent', $reason = '' ) {
		global $wpdb;

		$table = ASC_DB::table( 'snippets' );
		$id    = ! empty( $data['id'] ) ? absint( $data['id'] ) : 0;
		$title = ! empty( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : 'کد سفارشی هوش مصنوعی';
		$type  = ! empty( $data['snippet_type'] ) ? sanitize_key( (string) $data['snippet_type'] ) : 'css';
		if ( ! in_array( $type, array( 'css', 'js', 'php', 'html_head', 'html_footer' ), true ) ) {
			return new WP_Error( 'asc_invalid_snippet_type', 'نوع قطعه کد معتبر نیست.', array( 'status' => 400 ) );
		}

		$location  = ! empty( $data['location'] ) && in_array( $data['location'], array( 'frontend', 'admin', 'everywhere' ), true )
			? $data['location']
			: 'frontend';
		$code      = isset( $data['code'] ) ? (string) $data['code'] : '';
		$is_active = isset( $data['is_active'] ) ? ( (int) (bool) $data['is_active'] ) : 1;
		$now       = current_time( 'mysql' );

		if ( $id > 0 ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			if ( ! $existing ) {
				return new WP_Error( 'asc_snippet_not_found', 'قطعه کد مورد نظر یافت نشد.', array( 'status' => 404 ) );
			}
			$before_state = array( 'exists' => true, 'row' => $existing );
			$wpdb->update(
				$table,
				array(
					'title'        => $title,
					'snippet_type' => $type,
					'code'         => $code,
					'location'     => $location,
					'is_active'    => $is_active,
					'last_error'   => null,
					'updated_at'   => $now,
				),
				array( 'id' => $id )
			);
		} else {
			$before_state = array( 'exists' => false );
			$wpdb->insert(
				$table,
				array(
					'title'        => $title,
					'snippet_type' => $type,
					'code'         => $code,
					'location'     => $location,
					'is_active'    => $is_active,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
			$id = (int) $wpdb->insert_id;
		}

		$snapshot_id = ASC_Snapshot::create(
			'save_snippet',
			'snippet',
			(string) $id,
			sprintf( 'کد سفارشی (%s): %s', strtoupper( $type ), $title ),
			$before_state,
			$actor,
			$reason ? $reason : 'افزودن/ویرایش کد سفارشی'
		);

		$after_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		ASC_Snapshot::record_after_state( $snapshot_id, array( 'exists' => true, 'row' => $after_row ) );

		return array(
			'id'           => $id,
			'title'        => $title,
			'snippet_type' => $type,
			'is_active'    => $is_active,
			'snapshot_id'  => $snapshot_id,
		);
	}

	/**
	 * Read or update a non-protected WordPress option with Snapshot.
	 *
	 * @param string $option_name Option key.
	 * @param mixed  $new_value   Value to write (if updating).
	 * @param bool   $is_write    True to update, false to read.
	 * @param string $actor       Actor label.
	 * @param string $reason      Audit reason.
	 * @return array|WP_Error
	 */
	public static function manage_option( $option_name, $new_value = null, $is_write = false, $actor = 'ai-agent', $reason = '' ) {
		$key = sanitize_key( (string) $option_name );
		if ( '' === $key ) {
			return new WP_Error( 'asc_invalid_option_key', 'نام تنظیمات (option_name) نامعتبر است.', array( 'status' => 400 ) );
		}

		if ( in_array( $key, self::$protected_options, true ) ) {
			return new WP_Error( 'asc_protected_option', 'این گزینه سیستمی وردپرس به دلایل امنیتی محافظت شده است.', array( 'status' => 403 ) );
		}

		$sentinel = new stdClass();
		$current  = get_option( $key, $sentinel );
		$exists   = ( $current !== $sentinel );

		if ( ! $is_write ) {
			return array(
				'option_name' => $key,
				'exists'      => $exists,
				'value'       => $exists ? $current : null,
			);
		}

		$before_state = array(
			'exists' => $exists,
			'value'  => $exists ? $current : null,
		);

		$snapshot_id = ASC_Snapshot::create(
			'update_option',
			'option',
			$key,
			'تنظیمات وردپرس: ' . $key,
			$before_state,
			$actor,
			$reason ? $reason : 'تغییر گزینه wp_options'
		);

		update_option( $key, $new_value );
		ASC_Snapshot::record_after_state(
			$snapshot_id,
			array(
				'exists' => true,
				'value'  => get_option( $key ),
			)
		);

		return array(
			'option_name' => $key,
			'value'       => get_option( $key ),
			'snapshot_id' => $snapshot_id,
		);
	}

	/**
	 * List editable files inside the currently active theme (and parent theme if child theme).
	 *
	 * @return array
	 */
	public static function list_theme_files() {
		$theme      = wp_get_theme();
		$theme_dir  = $theme->get_stylesheet_directory();
		$files_list = array();

		if ( ! is_dir( $theme_dir ) ) {
			return array( 'theme' => $theme->get( 'Name' ), 'files' => array() );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $theme_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		$allowed_ext = array( 'php', 'css', 'js', 'json', 'html', 'txt' );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_ext, true ) ) {
				continue;
			}
			$rel_path = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $theme_dir ) ) ), '/' );
			if ( 0 === strpos( $rel_path, 'node_modules/' ) || 0 === strpos( $rel_path, '.git/' ) ) {
				continue;
			}
			$files_list[] = array(
				'relative_path' => $rel_path,
				'size'          => $file->getSize(),
				'modified'      => gmdate( 'Y-m-d H:i:s', $file->getMTime() ),
			);
			if ( count( $files_list ) >= 300 ) {
				break;
			}
		}

		return array(
			'theme_name' => $theme->get( 'Name' ),
			'stylesheet' => $theme->get_stylesheet(),
			'files'      => $files_list,
		);
	}

	/**
	 * Validate and resolve a relative path strictly inside the active theme directory.
	 *
	 * @param string $relative_path Relative path inside active theme.
	 * @return string|WP_Error Full path on disk.
	 */
	private static function resolve_safe_theme_path( $relative_path ) {
		$settings = ASC_DB::get_settings();
		if ( empty( $settings['enable_theme_editor'] ) ) {
			return new WP_Error( 'asc_theme_editor_disabled', 'ویرایش فایل‌های قالب در تنظیمات افزونه غیرفعال است.', array( 'status' => 403 ) );
		}

		$rel = ltrim( str_replace( '\\', '/', trim( (string) $relative_path ) ), '/' );
		if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
			return new WP_Error( 'asc_invalid_file_path', 'مسیر فایل قالب نامعتبر است.', array( 'status' => 400 ) );
		}

		$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'php', 'css', 'js', 'json', 'html', 'txt' ), true ) ) {
			return new WP_Error( 'asc_disallowed_file_ext', 'پسوند فایل برای ویرایش مجاز نیست.', array( 'status' => 403 ) );
		}

		$theme_root = realpath( wp_get_theme()->get_stylesheet_directory() );
		if ( ! $theme_root ) {
			return new WP_Error( 'asc_theme_root_missing', 'پوشه قالب فعال یافت نشد.', array( 'status' => 500 ) );
		}

		$candidate = $theme_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
		$parent    = realpath( dirname( $candidate ) );
		if ( ! $parent || 0 !== strpos( $parent, $theme_root ) ) {
			return new WP_Error( 'asc_path_traversal_blocked', 'دسترسی به خارج از پوشه قالب فعال مسدود شد.', array( 'status' => 403 ) );
		}

		return $candidate;
	}

	/**
	 * Read content of a file inside the active theme.
	 *
	 * @param string $relative_path Relative path.
	 * @return array|WP_Error
	 */
	public static function read_theme_file( $relative_path ) {
		$full_path = self::resolve_safe_theme_path( $relative_path );
		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}
		if ( ! file_exists( $full_path ) || ! is_readable( $full_path ) ) {
			return new WP_Error( 'asc_file_not_found', 'فایل مورد نظر در قالب یافت نشد.', array( 'status' => 404 ) );
		}

		return array(
			'relative_path' => ltrim( (string) $relative_path, '/' ),
			'content'       => (string) file_get_contents( $full_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		);
	}

	/**
	 * Write/update a file inside the active theme with full prior-file Snapshot for 1-click Rollback.
	 *
	 * @param string $relative_path Relative path inside active theme.
	 * @param string $new_content   Full file content to write.
	 * @param string $actor         Actor name.
	 * @param string $reason        Audit reason.
	 * @return array|WP_Error
	 */
	public static function write_theme_file( $relative_path, $new_content, $actor = 'ai-agent', $reason = '' ) {
		$full_path = self::resolve_safe_theme_path( $relative_path );
		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		$existed      = file_exists( $full_path );
		$before_state = array(
			'exists'        => $existed,
			'relative_path' => ltrim( (string) $relative_path, '/' ),
			'content'       => $existed ? (string) file_get_contents( $full_path ) : '', // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		);

		$written = file_put_contents( $full_path, (string) $new_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return new WP_Error( 'asc_file_write_failed', 'خطا در نوشتن فایل قالب؛ دسترسی سطح فایل (Permissions) را بررسی کنید.', array( 'status' => 500 ) );
		}

		$snapshot_id = ASC_Snapshot::create(
			'write_theme_file',
			'theme_file',
			ltrim( (string) $relative_path, '/' ),
			'فایل قالب: ' . ltrim( (string) $relative_path, '/' ),
			$before_state,
			$actor,
			$reason ? $reason : 'ویرایش فایل قالب'
		);

		ASC_Snapshot::record_after_state(
			$snapshot_id,
			array(
				'exists'        => true,
				'relative_path' => ltrim( (string) $relative_path, '/' ),
				'bytes'         => (int) $written,
			)
		);

		return array(
			'relative_path' => ltrim( (string) $relative_path, '/' ),
			'bytes_written' => (int) $written,
			'snapshot_id'   => $snapshot_id,
		);
	}

	/**
	 * Restore a theme file from a Snapshot record.
	 *
	 * @param string $relative_path Relative path inside active theme.
	 * @param array  $before_state  Snapshot before_state array.
	 * @return true|WP_Error
	 */
	public static function restore_theme_file_snapshot( $relative_path, array $before_state ) {
		$full_path = self::resolve_safe_theme_path( $relative_path );
		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		if ( empty( $before_state['exists'] ) ) {
			if ( file_exists( $full_path ) ) {
				wp_delete_file( $full_path );
			}
			return true;
		}

		$written = file_put_contents( $full_path, (string) $before_state['content'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return new WP_Error( 'asc_rollback_file_failed', 'امکان بازگردانی فایل قالب روی دیسک وجود ندارد.', array( 'status' => 500 ) );
		}
		return true;
	}
}
