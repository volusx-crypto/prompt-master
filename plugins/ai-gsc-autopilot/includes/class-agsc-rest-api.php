<?php
/**
 * REST API endpoints (/wp-json/ai-gsc-autopilot/v1/*) for AI Search Console Autopilot.
 *
 * @package AI_GSC_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGSC_REST_API {

	const NAMESPACE_V1 = 'ai-gsc-autopilot/v1';

	/**
	 * Register REST API routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Authorize incoming REST request via WP Admin, AI Site Commander API key, or standalone AGSC key.
	 *
	 * @param WP_REST_Request $request        Incoming request.
	 * @param string          $required_scope Scope when delegating to ASC_Security.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request, $required_scope = 'read' ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Delegate to AI Site Commander API Key system when active (single unified key for both plugins!).
		if ( class_exists( 'ASC_Security' ) ) {
			$asc_auth = ASC_Security::authorize_rest_request( $request, $required_scope );
			if ( true === $asc_auth ) {
				return true;
			}
		}

		// Fallback standalone AGSC key check.
		$settings = AGSC_DB::get_settings();
		$provided = trim( (string) $request->get_header( 'X-AGSC-API-Key' ) );
		if ( '' === $provided ) {
			$auth = trim( (string) $request->get_header( 'Authorization' ) );
			if ( 0 === stripos( $auth, 'Bearer ' ) ) {
				$provided = trim( substr( $auth, 7 ) );
			}
		}

		if ( '' !== $provided && ! empty( $settings['standalone_api_key'] ) && hash_equals( (string) $settings['standalone_api_key'], $provided ) ) {
			return true;
		}

		return new WP_Error(
			'agsc_unauthorized',
			'کلید API معتبر برای دسترسی به اتوپایلوت سرچ کنسول ارسال نشده است.',
			array( 'status' => 401 )
		);
	}

	/**
	 * Register all v1 endpoints.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => function ( WP_REST_Request $r ) {
					return self::authorize( $r, 'read' );
				},
				'callback'            => array( __CLASS__, 'handle_status' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => function ( WP_REST_Request $r ) {
					return self::authorize( $r, 'seo:write' );
				},
				'callback'            => function () {
					return rest_ensure_response( AGSC_Issue_Detector::sync_and_detect() );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => function ( WP_REST_Request $r ) {
					return self::authorize( $r, 'read' );
				},
				'callback'            => array( __CLASS__, 'handle_analytics' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/inspect-url',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => function ( WP_REST_Request $r ) {
					return self::authorize( $r, 'read' );
				},
				'callback'            => array( __CLASS__, 'handle_inspect_url' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/issues',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => function ( WP_REST_Request $r ) {
					return self::authorize( $r, 'read' );
				},
				'callback'            => array( __CLASS__, 'handle_list_issues' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/issues/(?P<id>\d+)/analyze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => function ( WP_REST_Request $r ) {
					return self::authorize( $r, 'seo:write' );
				},
				'callback'            => function ( WP_REST_Request $req ) {
					return rest_ensure_response( AGSC_AI_Analyzer::analyze_issue( (int) $req['id'] ) );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/issues/(?P<id>\d+)/execute-fix',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => function ( WP_REST_Request $r ) {
					return self::authorize( $r, 'seo:write' );
				},
				'callback'            => function ( WP_REST_Request $req ) {
					$body        = $req->get_json_params() ? $req->get_json_params() : $req->get_params();
					$custom_plan = isset( $body['custom_plan'] ) && is_array( $body['custom_plan'] ) ? $body['custom_plan'] : null;
					return rest_ensure_response( AGSC_Remediator::execute_issue_fix( (int) $req['id'], $custom_plan ) );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/autopilot/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => function ( WP_REST_Request $r ) {
					return self::authorize( $r, 'seo:write' );
				},
				'callback'            => function ( WP_REST_Request $req ) {
					$sync  = AGSC_Issue_Detector::sync_and_detect();
					$limit = $req->get_param( 'limit' ) ? absint( $req->get_param( 'limit' ) ) : 5;
					$batch = AGSC_Remediator::run_autopilot_batch( $limit );
					return rest_ensure_response(
						array(
							'sync'      => is_wp_error( $sync ) ? array( 'error' => $sync->get_error_message() ) : $sync,
							'autopilot' => $batch,
						)
					);
				},
			)
		);
	}

	/**
	 * Return plugin status, summary metrics, and issue breakdown.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_status() {
		global $wpdb;
		$settings     = AGSC_DB::get_settings();
		$issues_table = AGSC_DB::table( 'issues' );
		$an_table     = AGSC_DB::table( 'analytics' );

		$open_cnt     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$issues_table} WHERE status IN ('open', 'analyzed')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$critical_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$issues_table} WHERE status IN ('open', 'analyzed') AND severity = 'critical'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fixed_cnt    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$issues_table} WHERE status = 'fixed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$totals = $wpdb->get_row(
			"SELECT COALESCE(SUM(clicks),0) AS total_clicks, COALESCE(SUM(impressions),0) AS total_impressions, COALESCE(AVG(position),0) AS avg_position FROM {$an_table} WHERE row_type = 'page'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$clicks = (int) ( $totals['total_clicks'] ?? 0 );
		$impr   = (int) ( $totals['total_impressions'] ?? 0 );

		return rest_ensure_response(
			array(
				'version'            => AGSC_VERSION,
				'google_connected'   => AGSC_Google_Client::is_configured(),
				'property_url'       => $settings['property_url'],
				'commander_bridge'   => AGSC_Remediator::is_commander_bridge_ready(),
				'ai_provider'        => $settings['ai_provider'],
				'autopilot_auto_fix' => (bool) $settings['autopilot_auto_fix'],
				'performance_28d'    => array(
					'clicks'       => $clicks,
					'impressions'  => $impr,
					'avg_ctr_pct'  => $impr > 0 ? round( ( $clicks / $impr ) * 100, 2 ) : 0,
					'avg_position' => round( (float) ( $totals['avg_position'] ?? 0 ), 1 ),
				),
				'issues_summary'     => array(
					'open'     => $open_cnt,
					'critical' => $critical_cnt,
					'fixed'    => $fixed_cnt,
				),
			)
		);
	}

	/**
	 * Return cached Search Analytics pages and query opportunities.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_analytics( WP_REST_Request $request ) {
		global $wpdb;
		$table    = AGSC_DB::table( 'analytics' );
		$row_type = $request->get_param( 'type' ) === 'page_query' ? 'page_query' : 'page';
		$limit    = min( 250, max( 1, absint( $request->get_param( 'limit' ) ?: 50 ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE row_type = %s ORDER BY impressions DESC LIMIT %d", $row_type, $limit ),
			ARRAY_A
		);

		return rest_ensure_response(
			array(
				'type'  => $row_type,
				'items' => is_array( $rows ) ? $rows : array(),
			)
		);
	}

	/**
	 * Live inspect a URL via Google URL Inspection API + local WordPress check.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_inspect_url( WP_REST_Request $request ) {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( '' === $url ) {
			return new WP_Error( 'agsc_missing_url', 'آدرس URL برای بررسی ارسال نشده است.', array( 'status' => 400 ) );
		}

		$local_post_id = class_exists( 'ASC_Content_Manager' ) ? ASC_Content_Manager::resolve_url_to_post_id( $url ) : url_to_postid( $url );
		$local_seo     = ( $local_post_id > 0 && class_exists( 'ASC_SEO_Manager' ) ) ? ASC_SEO_Manager::get_post_seo( $local_post_id ) : null;

		if ( ! AGSC_Google_Client::is_configured() ) {
			return rest_ensure_response(
				array(
					'url'              => $url,
					'google_connected' => false,
					'local_seo'        => $local_seo,
					'message'          => 'اطلاعات اتصال سرچ کنسول گوگل هنوز تنظیم نشده است؛ فقط وضعیت محلی وردپرس بررسی شد.',
				)
			);
		}

		$gsc = AGSC_Google_Client::inspect_url( $url );
		if ( is_wp_error( $gsc ) ) {
			return $gsc;
		}

		if ( ! empty( $gsc['inspectionResult'] ) ) {
			AGSC_Issue_Detector::evaluate_url_inspection_result( $url, $gsc['inspectionResult'] );
		}

		return rest_ensure_response(
			array(
				'url'        => $url,
				'gsc_result' => $gsc['inspectionResult'] ?? array(),
				'local_seo'  => $local_seo,
			)
		);
	}

	/**
	 * List detected issues filterable by status and severity.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_list_issues( WP_REST_Request $request ) {
		global $wpdb;
		$table    = AGSC_DB::table( 'issues' );
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$severity = sanitize_key( (string) $request->get_param( 'severity' ) );
		$limit    = min( 200, max( 1, absint( $request->get_param( 'limit' ) ?: 50 ) ) );

		$where = array( '1=1' );
		$args  = array();

		if ( '' !== $status && 'all' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $severity && 'all' !== $severity ) {
			$where[] = 'severity = %s';
			$args[]  = $severity;
		}

		$args[]     = $limit;
		$where_sql  = implode( ' AND ', $where );
		$query      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low'), id DESC LIMIT %d";
		$rows       = $wpdb->get_results( $wpdb->prepare( $query, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$type_names = AGSC_Issue_Detector::issue_type_labels();

		if ( is_array( $rows ) ) {
			foreach ( $rows as &$r ) {
				$r['issue_type_label'] = $type_names[ $r['issue_type'] ] ?? $r['issue_type'];
				$r['metrics']          = ! empty( $r['metrics_json'] ) ? json_decode( $r['metrics_json'], true ) : array();
				$r['fix_plan']         = ! empty( $r['fix_plan_json'] ) ? json_decode( $r['fix_plan_json'], true ) : null;
				unset( $r['metrics_json'], $r['gsc_evidence_json'], $r['fix_plan_json'] );
			}
		}

		return rest_ensure_response(
			array(
				'items' => is_array( $rows ) ? $rows : array(),
			)
		);
	}
}
