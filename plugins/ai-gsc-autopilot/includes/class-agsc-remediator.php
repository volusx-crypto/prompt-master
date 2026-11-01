<?php
/**
 * Fix Executor & Autopilot Orchestrator bridging AI Search Console Autopilot to AI Site Commander.
 *
 * @package AI_GSC_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGSC_Remediator {

	/**
	 * Check whether AI Site Commander plugin is active and ready to execute fixes.
	 *
	 * @return bool
	 */
	public static function is_commander_bridge_ready() {
		return class_exists( 'AI_Site_Commander' ) && method_exists( 'AI_Site_Commander', 'execute' );
	}

	/**
	 * Execute the analyzed (or externally supplied) fix plan for an issue.
	 *
	 * @param int        $issue_id    Issue ID in wp_agsc_issues.
	 * @param array|null $custom_plan Optional custom fix plan provided by an external AI agent.
	 * @return array|WP_Error
	 */
	public static function execute_issue_fix( $issue_id, $custom_plan = null ) {
		global $wpdb;

		if ( ! self::is_commander_bridge_ready() ) {
			return new WP_Error(
				'agsc_commander_missing',
				'افزونه اول (فرمانده هوشمند سایت — AI Site Commander) فعال نیست. برای اعمال مستقیم اصلاحات روی سایت هر دو افزونه باید فعال باشند.',
				array( 'status' => 424 )
			);
		}

		$table = AGSC_DB::table( 'issues' );
		$issue = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $issue_id ) ), ARRAY_A );
		if ( ! $issue ) {
			return new WP_Error( 'agsc_issue_not_found', 'مشکل مورد نظر در سرچ کنسول یافت نشد.', array( 'status' => 404 ) );
		}

		// Use custom plan if provided by external AI agent; otherwise ensure issue is analyzed.
		if ( is_array( $custom_plan ) && ! empty( $custom_plan['actions'] ) ) {
			$plan = $custom_plan;
			$wpdb->update(
				$table,
				array(
					'ai_diagnosis'  => sanitize_textarea_field( (string) ( $plan['diagnosis_fa'] ?? $issue['ai_diagnosis'] ) ),
					'fix_plan_json' => wp_json_encode( $plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				),
				array( 'id' => (int) $issue['id'] )
			);
		} else {
			$plan = ! empty( $issue['fix_plan_json'] ) ? json_decode( (string) $issue['fix_plan_json'], true ) : null;
			if ( ! is_array( $plan ) || empty( $plan['actions'] ) ) {
				$analyzed = AGSC_AI_Analyzer::analyze_issue( (int) $issue['id'] );
				if ( is_wp_error( $analyzed ) ) {
					return $analyzed;
				}
				$plan = $analyzed['plan'];
			}
		}

		if ( empty( $plan['actions'] ) || ! is_array( $plan['actions'] ) ) {
			return new WP_Error( 'agsc_empty_fix_plan', 'هیچ عملیات اجرایی در برنامه اصلاح این مشکل وجود ندارد.', array( 'status' => 400 ) );
		}

		$step_results    = array();
		$first_snapshot  = null;
		$any_queued      = false;

		foreach ( $plan['actions'] as $step ) {
			$plugin = $step['plugin'] ?? 'ai-site-commander';
			$action = $step['action'] ?? '';
			$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : array();

			if ( 'ai-gsc-autopilot' === $plugin && 'submit_sitemap' === $action ) {
				if ( AGSC_Google_Client::is_configured() ) {
					$sm_res         = AGSC_Google_Client::submit_sitemap( $params['sitemap_url'] ?? '' );
					$step_results[] = array(
						'action' => 'submit_sitemap',
						'result' => is_wp_error( $sm_res ) ? array( 'warning' => $sm_res->get_error_message() ) : $sm_res,
					);
				} else {
					$step_results[] = array(
						'action' => 'submit_sitemap',
						'result' => array( 'skipped' => 'اتصال گوگل فعال نیست؛ پس از اتصال ارسال می‌شود.' ),
					);
				}
				continue;
			}

			$exec = AI_Site_Commander::execute(
				$action,
				$params,
				array(
					'actor'  => 'gsc-autopilot',
					'reason' => sprintf( 'رفع مشکل سرچ کنسول #%d (%s)', (int) $issue['id'], $issue['issue_type'] ),
				)
			);

			if ( is_wp_error( $exec ) ) {
				return $exec;
			}

			if ( ! empty( $exec['queued'] ) ) {
				$any_queued = true;
			}
			if ( null === $first_snapshot && ! empty( $exec['snapshot_id'] ) ) {
				$first_snapshot = (int) $exec['snapshot_id'];
			}

			$step_results[] = array(
				'action' => $action,
				'result' => $exec,
			);
		}

		$final_status = $any_queued ? 'queued_approval' : 'fixed';
		$wpdb->update(
			$table,
			array(
				'status'                => $final_status,
				'execution_result_json' => wp_json_encode( $step_results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'snapshot_id'           => $first_snapshot,
				'updated_at'            => current_time( 'mysql' ),
				'fixed_at'              => 'fixed' === $final_status ? current_time( 'mysql' ) : null,
			),
			array( 'id' => (int) $issue['id'] )
		);

		return array(
			'issue_id'    => (int) $issue['id'],
			'status'      => $final_status,
			'snapshot_id' => $first_snapshot,
			'steps'       => $step_results,
			'message'     => 'fixed' === $final_status
				? 'اصلاحات با موفقیت روی سایت اجرا شد و اسنپ‌شات پشتیبان ثبت گردید.'
				: 'اصلاحات در صف تایید فرمانده هوشمند سایت قرار گرفت.',
		);
	}

	/**
	 * Run full automated Autopilot batch: analyze and fix up to $limit safe open issues.
	 *
	 * @param int $limit Max issues to remediate in one batch.
	 * @return array
	 */
	public static function run_autopilot_batch( $limit = 5 ) {
		global $wpdb;

		$settings   = AGSC_DB::get_settings();
		$safe_types = ! empty( $settings['auto_fix_safe_types'] ) && is_array( $settings['auto_fix_safe_types'] )
			? $settings['auto_fix_safe_types']
			: array( 'low_ctr_high_impressions', 'not_found_404', 'canonical_mismatch', 'noindex_blocked' );

		$placeholders = implode( ',', array_fill( 0, count( $safe_types ), '%s' ) );
		$table        = AGSC_DB::table( 'issues' );

		$query_args   = $safe_types;
		$query_args[] = min( 25, max( 1, absint( $limit ) ) );

		$open_issues = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, issue_type, url FROM {$table} WHERE status IN ('open', 'analyzed') AND issue_type IN ({$placeholders}) ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low'), id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$query_args
			),
			ARRAY_A
		);

		$fixed   = array();
		$errors  = array();

		if ( is_array( $open_issues ) ) {
			foreach ( $open_issues as $item ) {
				$res = self::execute_issue_fix( (int) $item['id'] );
				if ( is_wp_error( $res ) ) {
					$errors[] = array(
						'issue_id' => (int) $item['id'],
						'error'    => $res->get_error_message(),
					);
				} else {
					$fixed[] = $res;
				}
			}
		}

		AGSC_DB::log_sync(
			'autopilot',
			empty( $errors ) ? 'success' : 'partial',
			count( (array) $open_issues ),
			count( $fixed ),
			sprintf( 'اجرای اتوپایلوت: %d مشکل به‌صورت خودکار اصلاح شد.', count( $fixed ) )
		);

		return array(
			'processed'     => count( (array) $open_issues ),
			'fixed_count'   => count( $fixed ),
			'fixed_items'   => $fixed,
			'errors'        => $errors,
		);
	}
}
