<?php
/**
 * 301/302/307/410 Redirect Engine and 404 Error Logger for AI Site Commander.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Redirect_404 {

	/**
	 * Register frontend hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_redirects_and_404' ), 1 );
	}

	/**
	 * Normalize a URL or path into a clean relative request path (e.g., '/old-slug').
	 *
	 * @param string $url_or_path Input URL or path.
	 * @return string
	 */
	public static function normalize_path( $url_or_path ) {
		$raw  = trim( (string) $url_or_path );
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		if ( '/' !== $path ) {
			$path = untrailingslashit( $path );
		}
		return $path;
	}

	/**
	 * Execute matching redirect rules or capture 404 hits on frontend requests.
	 */
	public static function handle_redirects_and_404() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$settings = ASC_DB::get_settings();
		$req_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$req_path = self::normalize_path( $req_uri );

		// 1. Check active redirect rules first.
		if ( ! empty( $settings['enable_redirects'] ) ) {
			$matched = self::match_redirect( $req_path );
			if ( $matched ) {
				self::increment_redirect_hit( (int) $matched['id'] );
				$code = (int) $matched['status_code'];

				if ( 410 === $code ) {
					status_header( 410 );
					nocache_headers();
					wp_die(
						'این صفحه به‌صورت دائمی حذف شده است (410 Gone).',
						'410 Gone',
						array( 'response' => 410 )
					);
				}

				$target = trim( (string) $matched['target_url'] );
				if ( '' !== $target ) {
					if ( 0 === strpos( $target, '/' ) ) {
						$target = home_url( $target );
					}
					$target_path = self::normalize_path( $target );
					// Guard against self-redirect loops.
					if ( $target_path !== $req_path || wp_parse_url( $target, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
						wp_redirect( esc_url_raw( $target ), in_array( $code, array( 301, 302, 307, 308 ), true ) ? $code : 301 );
						exit;
					}
				}
			}
		}

		// 2. Log 404 errors if not redirected.
		if ( is_404() && ! empty( $settings['enable_404_monitor'] ) ) {
			self::log_404_hit( $req_path );
		}
	}

	/**
	 * Match a normalized request path against exact and regex rules.
	 *
	 * @param string $req_path Normalized path.
	 * @return array|null
	 */
	public static function match_redirect( $req_path ) {
		global $wpdb;
		$table = ASC_DB::table( 'redirects' );

		// Exact match first (fast indexed lookup).
		$exact = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE is_active = 1 AND is_regex = 0 AND (source_path = %s OR source_path = %s) ORDER BY id DESC LIMIT 1",
				$req_path,
				trailingslashit( $req_path )
			),
			ARRAY_A
		);

		if ( $exact ) {
			return $exact;
		}

		// Regex rules fallback.
		$regex_rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE is_active = 1 AND is_regex = 1 ORDER BY id DESC LIMIT 100",
			ARRAY_A
		);

		if ( is_array( $regex_rows ) ) {
			foreach ( $regex_rows as $rule ) {
				$pattern = '#' . str_replace( '#', '\#', $rule['source_path'] ) . '#u';
				if ( @preg_match( $pattern, $req_path ) ) {
					$rule['target_url'] = @preg_replace( $pattern, $rule['target_url'], $req_path );
					return $rule;
				}
			}
		}

		return null;
	}

	/**
	 * Increment hit counter on a matched redirect rule.
	 *
	 * @param int $redirect_id Redirect ID.
	 */
	private static function increment_redirect_hit( $redirect_id ) {
		global $wpdb;
		$table = ASC_DB::table( 'redirects' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1 WHERE id = %d", absint( $redirect_id ) ) );
	}

	/**
	 * Record or increment a 404 hit in wp_asc_404_logs.
	 *
	 * @param string $req_path Normalized path.
	 */
	public static function log_404_hit( $req_path ) {
		// Ignore noisy static asset probes.
		if ( preg_match( '/\.(map|ico|woff2?|ttf|eot)$/i', $req_path ) ) {
			return;
		}

		global $wpdb;
		$table   = ASC_DB::table( '404_logs' );
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( mb_substr( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 250 ) ) : '';
		$now     = current_time( 'mysql' );

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE request_path = %s LIMIT 1", $req_path ),
			ARRAY_A
		);

		if ( $existing ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET hits = hits + 1, last_seen_at = %s, referer = CASE WHEN %s <> '' THEN %s ELSE referer END WHERE id = %d",
					$now,
					$referer,
					$referer,
					(int) $existing['id']
				)
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'request_path'  => $req_path,
					'referer'       => $referer,
					'user_agent'    => $ua,
					'hits'          => 1,
					'resolved'      => 0,
					'first_seen_at' => $now,
					'last_seen_at'  => $now,
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Create or update a redirect rule with Snapshot and automatic 404 resolution marking.
	 *
	 * @param array  $data   Redirect fields: source_path, target_url, status_code, is_regex, note.
	 * @param string $actor  Actor name.
	 * @param string $reason Reason for audit/snapshot.
	 * @return array|WP_Error
	 */
	public static function save_redirect( array $data, $actor = 'ai-agent', $reason = '' ) {
		global $wpdb;

		$is_regex    = ! empty( $data['is_regex'] ) ? 1 : 0;
		$source_raw  = isset( $data['source_path'] ) ? trim( (string) $data['source_path'] ) : '';
		$source_path = $is_regex ? sanitize_text_field( $source_raw ) : self::normalize_path( $source_raw );
		$status_code = isset( $data['status_code'] ) ? absint( $data['status_code'] ) : 301;
		if ( ! in_array( $status_code, array( 301, 302, 307, 410 ), true ) ) {
			$status_code = 301;
		}

		$target_url = isset( $data['target_url'] ) ? trim( (string) $data['target_url'] ) : '';
		if ( 410 !== $status_code && '' === $target_url ) {
			return new WP_Error( 'asc_missing_redirect_target', 'آدرس مقصد برای ریدایرکت الزامی است.', array( 'status' => 400 ) );
		}
		if ( '' !== $target_url && 0 !== strpos( $target_url, '/' ) ) {
			$target_url = esc_url_raw( $target_url );
		}

		if ( '' === $source_path || '/' === $source_path ) {
			return new WP_Error( 'asc_invalid_redirect_source', 'مسیر مبدا ریدایرکت معتبر نیست (نمی‌تواند صفحه اصلی باشد).', array( 'status' => 400 ) );
		}

		$table    = ASC_DB::table( 'redirects' );
		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source_path = %s AND is_regex = %d LIMIT 1", $source_path, $is_regex ),
			ARRAY_A
		);

		$note = isset( $data['note'] ) ? sanitize_text_field( (string) $data['note'] ) : '';

		if ( $existing ) {
			$rid          = (int) $existing['id'];
			$before_state = array( 'exists' => true, 'row' => $existing );
			$wpdb->update(
				$table,
				array(
					'target_url'  => $target_url,
					'status_code' => $status_code,
					'is_active'   => 1,
					'note'        => $note ? $note : $existing['note'],
				),
				array( 'id' => $rid ),
				array( '%s', '%d', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$before_state = array( 'exists' => false );
			$wpdb->insert(
				$table,
				array(
					'source_path' => $source_path,
					'target_url'  => $target_url,
					'status_code' => $status_code,
					'is_regex'    => $is_regex,
					'hits'        => 0,
					'is_active'   => 1,
					'note'        => $note,
					'created_at'  => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
			$rid = (int) $wpdb->insert_id;
		}

		$snapshot_id = ASC_Snapshot::create(
			'save_redirect',
			'redirect',
			(string) $rid,
			sprintf( 'ریدایرکت %d: %s -> %s', $status_code, $source_path, $target_url ? $target_url : '410 Gone' ),
			$before_state,
			$actor,
			$reason ? $reason : 'ایجاد/ویرایش قانون ریدایرکت'
		);

		$after_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rid ), ARRAY_A );
		ASC_Snapshot::record_after_state( $snapshot_id, array( 'exists' => true, 'row' => $after_row ) );

		// Mark matching 404 logs as resolved.
		$logs_table = ASC_DB::table( '404_logs' );
		$wpdb->update(
			$logs_table,
			array( 'resolved' => 1 ),
			array( 'request_path' => $source_path ),
			array( '%d' ),
			array( '%s' )
		);

		return array(
			'id'          => $rid,
			'source_path' => $source_path,
			'target_url'  => $target_url,
			'status_code' => $status_code,
			'snapshot_id' => $snapshot_id,
		);
	}

	/**
	 * Delete a redirect rule by ID.
	 *
	 * @param int    $redirect_id Redirect row ID.
	 * @param string $actor       Actor name.
	 * @return array|WP_Error
	 */
	public static function delete_redirect( $redirect_id, $actor = 'admin' ) {
		global $wpdb;
		$table = ASC_DB::table( 'redirects' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $redirect_id ) ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'asc_redirect_not_found', 'قانون ریدایرکت یافت نشد.', array( 'status' => 404 ) );
		}

		$wpdb->delete( $table, array( 'id' => absint( $redirect_id ) ), array( '%d' ) );
		ASC_DB::log_audit( $actor, 'delete_redirect', $row['source_path'], 'success', 'حذف قانون ریدایرکت' );

		return array( 'deleted' => true, 'id' => absint( $redirect_id ) );
	}

	/**
	 * List active/all redirects.
	 *
	 * @param int $limit Max items.
	 * @return array
	 */
	public static function list_redirects( $limit = 100 ) {
		global $wpdb;
		$table = ASC_DB::table( 'redirects' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", min( 500, max( 1, absint( $limit ) ) ) ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List captured 404 logs ordered by hit frequency.
	 *
	 * @param bool $only_unresolved Only return unresolved 404 paths.
	 * @param int  $limit           Max items.
	 * @return array
	 */
	public static function list_404_logs( $only_unresolved = false, $limit = 100 ) {
		global $wpdb;
		$table = ASC_DB::table( '404_logs' );
		$where = $only_unresolved ? 'WHERE resolved = 0' : '';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY hits DESC, last_seen_at DESC LIMIT %d", min( 500, max( 1, absint( $limit ) ) ) ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
