<?php
/**
 * Unified Action Dispatcher, Cache Purger, and Staged Approval Queue for AI Site Commander.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Engine {

	/**
	 * Dispatch any site action (either executing immediately with Snapshot or queueing for admin approval).
	 *
	 * @param string $action_type Action identifier.
	 * @param array  $params      Action parameters.
	 * @param array  $context     Optional { actor, reason, stage, bypass_queue }.
	 * @return array|WP_Error
	 */
	public static function dispatch( $action_type, array $params = array(), array $context = array() ) {
		$action_type  = sanitize_key( (string) $action_type );
		$actor        = ! empty( $context['actor'] ) ? sanitize_text_field( (string) $context['actor'] ) : ASC_Security::current_actor_label();
		$reason       = ! empty( $context['reason'] ) ? sanitize_textarea_field( (string) $context['reason'] ) : '';
		$settings     = ASC_DB::get_settings();
		$force_stage  = ! empty( $context['stage'] );
		$bypass_queue = ! empty( $context['bypass_queue'] );

		$read_only_actions = array(
			'get_status',
			'list_posts',
			'get_post',
			'get_seo',
			'bulk_seo_audit',
			'list_missing_alts',
			'list_redirects',
			'list_404_logs',
			'list_woo_products',
			'list_snippets',
			'get_option',
			'list_theme_files',
			'read_theme_file',
			'list_snapshots',
		);

		$is_write = ! in_array( $action_type, $read_only_actions, true );

		// Stage write operations if site is in 'approval' mode (unless bypassed by admin approval).
		if ( $is_write && ! $bypass_queue && ( $force_stage || 'approval' === $settings['execution_mode'] ) ) {
			return self::enqueue_action( $action_type, $params, $actor, $reason );
		}

		$result = self::execute_immediately( $action_type, $params, $actor, $reason );

		if ( is_wp_error( $result ) ) {
			if ( $is_write ) {
				ASC_DB::log_audit( $actor, $action_type, self::summarize_target( $action_type, $params ), 'error', $result->get_error_message() );
			}
			return $result;
		}

		if ( $is_write ) {
			$snap_id = isset( $result['snapshot_id'] ) ? (int) $result['snapshot_id'] : null;
			ASC_DB::log_audit(
				$actor,
				$action_type,
				self::summarize_target( $action_type, $params ),
				'success',
				$reason ? $reason : 'عملیات با موفقیت روی سایت اجرا شد.',
				$snap_id
			);
			self::purge_site_caches( isset( $params['post_id'] ) ? absint( $params['post_id'] ) : ( isset( $params['id'] ) ? absint( $params['id'] ) : 0 ) );
		}

		return $result;
	}

	/**
	 * Execute an action directly without staging.
	 *
	 * @param string $action_type Action identifier.
	 * @param array  $params      Action parameters.
	 * @param string $actor       Actor name.
	 * @param string $reason      Audit reason.
	 * @return array|WP_Error
	 */
	private static function execute_immediately( $action_type, array $params, $actor, $reason ) {
		switch ( $action_type ) {
			case 'get_status':
				return self::get_site_status();

			case 'list_posts':
				return ASC_Content_Manager::list_posts( $params );

			case 'get_post':
				$post_id = ! empty( $params['post_id'] ) ? absint( $params['post_id'] ) : ( ! empty( $params['id'] ) ? absint( $params['id'] ) : ASC_Content_Manager::resolve_url_to_post_id( $params['url'] ?? '' ) );
				return ASC_Content_Manager::get_post_detail( $post_id );

			case 'save_post':
			case 'update_post':
			case 'create_post':
				if ( empty( $params['id'] ) && ! empty( $params['post_id'] ) ) {
					$params['id'] = absint( $params['post_id'] );
				}
				if ( empty( $params['id'] ) && ! empty( $params['url'] ) ) {
					$params['id'] = ASC_Content_Manager::resolve_url_to_post_id( $params['url'] );
				}
				return ASC_Content_Manager::save_post( $params, $actor, $reason );

			case 'get_seo':
				$post_id = ! empty( $params['post_id'] ) ? absint( $params['post_id'] ) : ASC_Content_Manager::resolve_url_to_post_id( $params['url'] ?? '' );
				return ASC_SEO_Manager::get_post_seo( $post_id );

			case 'update_seo':
				$post_id = ! empty( $params['post_id'] ) ? absint( $params['post_id'] ) : ASC_Content_Manager::resolve_url_to_post_id( $params['url'] ?? '' );
				return ASC_SEO_Manager::update_post_seo( $post_id, $params, $actor, $reason );

			case 'bulk_seo_audit':
				return ASC_SEO_Manager::bulk_seo_audit( $params['limit'] ?? 100 );

			case 'inject_internal_link':
				$source_id = ! empty( $params['source_post_id'] ) ? absint( $params['source_post_id'] ) : ASC_Content_Manager::resolve_url_to_post_id( $params['source_url'] ?? '' );
				return ASC_Content_Manager::inject_internal_link(
					$source_id,
					$params['anchor_text'] ?? '',
					$params['target_url'] ?? '',
					$actor
				);

			case 'list_missing_alts':
				return array( 'items' => ASC_Content_Manager::list_missing_image_alts( $params['limit'] ?? 50 ) );

			case 'update_image_alts':
				return ASC_Content_Manager::update_image_alts( $params['updates'] ?? array(), $actor );

			case 'list_redirects':
				return array( 'items' => ASC_Redirect_404::list_redirects( $params['limit'] ?? 100 ) );

			case 'save_redirect':
			case 'create_redirect':
				return ASC_Redirect_404::save_redirect( $params, $actor, $reason );

			case 'delete_redirect':
				return ASC_Redirect_404::delete_redirect( $params['id'] ?? 0, $actor );

			case 'list_404_logs':
				return array( 'items' => ASC_Redirect_404::list_404_logs( ! empty( $params['only_unresolved'] ), $params['limit'] ?? 100 ) );

			case 'list_woo_products':
				return ASC_Content_Manager::list_woocommerce_products( $params );

			case 'update_woo_product':
				$prod_id = ! empty( $params['product_id'] ) ? absint( $params['product_id'] ) : absint( $params['id'] ?? 0 );
				return ASC_Content_Manager::update_woocommerce_product( $prod_id, $params, $actor, $reason );

			case 'list_snippets':
				return array( 'items' => ASC_Code_Files::list_snippets() );

			case 'save_snippet':
				return ASC_Code_Files::save_snippet( $params, $actor, $reason );

			case 'get_option':
				return ASC_Code_Files::manage_option( $params['option_name'] ?? '', null, false, $actor );

			case 'update_option':
				return ASC_Code_Files::manage_option( $params['option_name'] ?? '', $params['value'] ?? '', true, $actor, $reason );

			case 'list_theme_files':
				return ASC_Code_Files::list_theme_files();

			case 'read_theme_file':
				return ASC_Code_Files::read_theme_file( $params['relative_path'] ?? '' );

			case 'write_theme_file':
				return ASC_Code_Files::write_theme_file( $params['relative_path'] ?? '', $params['content'] ?? '', $actor, $reason );

			case 'list_snapshots':
				return array( 'items' => ASC_Snapshot::list_snapshots( $params['limit'] ?? 50, $params['offset'] ?? 0 ) );

			case 'rollback_snapshot':
				return ASC_Snapshot::rollback( $params['snapshot_id'] ?? 0, $actor );

			default:
				return new WP_Error(
					'asc_unknown_action',
					sprintf( 'اکشن «%s» توسط فرمانده هوشمند سایت شناخته نشده است.', $action_type ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Enqueue a write operation for administrator approval.
	 *
	 * @param string $action_type Action identifier.
	 * @param array  $params      Payload parameters.
	 * @param string $actor       Actor name.
	 * @param string $reason      Human-readable explanation.
	 * @return array
	 */
	public static function enqueue_action( $action_type, array $params, $actor = 'ai-agent', $reason = '' ) {
		global $wpdb;

		$summary = $reason
			? $reason
			: sprintf( 'درخواست اجرای عملیات %s روی %s', $action_type, self::summarize_target( $action_type, $params ) );

		$wpdb->insert(
			ASC_DB::table( 'queue' ),
			array(
				'action_type' => $action_type,
				'payload'     => wp_json_encode( $params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'summary'     => $summary,
				'actor'       => $actor,
				'status'      => 'pending',
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$queue_id = (int) $wpdb->insert_id;
		ASC_DB::log_audit( $actor, $action_type, self::summarize_target( $action_type, $params ), 'queued', $summary );

		return array(
			'queued'   => true,
			'queue_id' => $queue_id,
			'status'   => 'pending_approval',
			'summary'  => $summary,
			'message'  => 'تغییرات در صف تایید مدیر سایت ثبت شد و پس از تایید یک‌کلیکی اجرا می‌شود.',
		);
	}

	/**
	 * Approve or reject a staged queue item.
	 *
	 * @param int    $queue_id Queue ID.
	 * @param string $decision approve|reject.
	 * @param string $actor    Approving admin label.
	 * @return array|WP_Error
	 */
	public static function process_queue_item( $queue_id, $decision = 'approve', $actor = 'admin' ) {
		global $wpdb;
		$table = ASC_DB::table( 'queue' );
		$item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $queue_id ) ), ARRAY_A );

		if ( ! $item ) {
			return new WP_Error( 'asc_queue_not_found', 'آیتم مورد نظر در صف تایید یافت نشد.', array( 'status' => 404 ) );
		}
		if ( 'pending' !== $item['status'] ) {
			return new WP_Error( 'asc_queue_already_processed', 'این آیتم قبلاً بررسی شده است.', array( 'status' => 409 ) );
		}

		if ( 'reject' === $decision ) {
			$wpdb->update(
				$table,
				array(
					'status'       => 'rejected',
					'processed_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $item['id'] )
			);
			return array( 'queue_id' => (int) $item['id'], 'status' => 'rejected' );
		}

		$payload = json_decode( (string) $item['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();

		$exec = self::dispatch(
			$item['action_type'],
			$payload,
			array(
				'actor'        => $item['actor'] . ' (approved by ' . $actor . ')',
				'reason'       => $item['summary'],
				'bypass_queue' => true,
			)
		);

		if ( is_wp_error( $exec ) ) {
			$wpdb->update(
				$table,
				array(
					'status'       => 'failed',
					'result_data'  => wp_json_encode( array( 'error' => $exec->get_error_message() ), JSON_UNESCAPED_UNICODE ),
					'processed_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $item['id'] )
			);
			return $exec;
		}

		$wpdb->update(
			$table,
			array(
				'status'       => 'approved',
				'result_data'  => wp_json_encode( $exec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'snapshot_id'  => isset( $exec['snapshot_id'] ) ? (int) $exec['snapshot_id'] : null,
				'processed_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $item['id'] )
		);

		return array(
			'queue_id' => (int) $item['id'],
			'status'   => 'approved',
			'result'   => $exec,
		);
	}

	/**
	 * Build comprehensive site health & capability overview.
	 *
	 * @return array
	 */
	public static function get_site_status() {
		global $wpdb, $wp_version;

		$settings      = ASC_DB::get_settings();
		$theme         = wp_get_theme();
		$post_counts   = wp_count_posts( 'post' );
		$page_counts   = wp_count_posts( 'page' );
		$snapshots_cnt = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ASC_DB::table( 'snapshots' ) );
		$pending_cnt   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . ASC_DB::table( 'queue' ) . " WHERE status = 'pending'" );
		$redirects_cnt = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ASC_DB::table( 'redirects' ) );
		$err404_cnt    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ASC_DB::table( '404_logs' ) . ' WHERE resolved = 0' );

		return array(
			'plugin_version'     => ASC_VERSION,
			'site_url'           => home_url( '/' ),
			'site_title'         => get_bloginfo( 'name' ),
			'language'           => get_locale(),
			'wp_version'         => $wp_version,
			'php_version'        => PHP_VERSION,
			'active_theme'       => $theme->get( 'Name' ),
			'seo_provider'       => ASC_SEO_Manager::detect_seo_plugin(),
			'woocommerce_active' => class_exists( 'WooCommerce' ),
			'gsc_plugin_active'  => class_exists( 'AI_GSC_Autopilot' ),
			'execution_mode'     => $settings['execution_mode'],
			'counts'             => array(
				'published_posts' => isset( $post_counts->publish ) ? (int) $post_counts->publish : 0,
				'published_pages' => isset( $page_counts->publish ) ? (int) $page_counts->publish : 0,
				'snapshots'       => $snapshots_cnt,
				'pending_queue'   => $pending_cnt,
				'redirects'       => $redirects_cnt,
				'unresolved_404s' => $err404_cnt,
			),
		);
	}

	/**
	 * Flush popular WordPress caching plugins after a live modification so changes appear immediately.
	 *
	 * @param int $post_id Optional specific post ID.
	 */
	public static function purge_site_caches( $post_id = 0 ) {
		if ( $post_id > 0 ) {
			clean_post_cache( $post_id );
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
			LiteSpeed_Cache_API::purge_all();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}

	/**
	 * Derive a concise target label for audit logs.
	 *
	 * @param string $action_type Action name.
	 * @param array  $params      Parameters.
	 * @return string
	 */
	private static function summarize_target( $action_type, array $params ) {
		foreach ( array( 'url', 'source_path', 'relative_path', 'option_name', 'title', 'post_id', 'id', 'snapshot_id' ) as $k ) {
			if ( ! empty( $params[ $k ] ) ) {
				return (string) $params[ $k ];
			}
		}
		return $action_type;
	}
}
