<?php
/**
 * Google Search Console Sync & Actionable Issue Detector.
 *
 * Pulls Search Analytics (28d vs prev 28d), Sitemaps, and URL Inspection verdicts,
 * cross-references local WordPress post/SEO state, and populates wp_agsc_issues.
 *
 * @package AI_GSC_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGSC_Issue_Detector {

	/**
	 * Human-readable Persian labels for issue types.
	 *
	 * @return array
	 */
	public static function issue_type_labels() {
		return array(
			'not_found_404'              => 'خطای ۴۰۴ / آدرس یافت‌نشده در گوگل',
			'noindex_blocked'            => 'مسدود شدن ایندکس به دلیل تگ noindex',
			'robots_blocked'             => 'مسدود شده توسط فایل robots.txt',
			'canonical_mismatch'         => 'عدم تطابق آدرس کنونیکال (Canonical Mismatch)',
			'rich_results_error'         => 'خطای اسکیما و داده‌های ساختاریافته (Rich Results)',
			'crawled_not_indexed'        => 'خزش شده اما ایندکس نشده (Crawled - Not Indexed)',
			'traffic_drop'               => 'افت محسوس کلیک و رتبه در ۲۸ روز اخیر',
			'low_ctr_high_impressions'   => 'ایمپرشن بالا اما نرخ کلیک (CTR) پایین',
			'striking_distance_keywords' => 'کلمات کلیدی در آستانه صفحه اول (رتبه ۵ تا ۱۸)',
			'keyword_cannibalization'    => 'هم‌خواری کلمات کلیدی (Keyword Cannibalization)',
			'missing_seo_metadata'       => 'نقص متا تایتل یا متا دیسکریپشن در صفحه',
		);
	}

	/**
	 * Synchronize with Google Search Console APIs and detect issues.
	 *
	 * @return array|WP_Error
	 */
	public static function sync_and_detect() {
		global $wpdb;

		if ( ! AGSC_Google_Client::is_configured() ) {
			// Run local WordPress + 404 bridge audit if Google credentials aren't set yet.
			return self::scan_local_site_issues( 'پیش‌اسکن داخلی سایت (بدون اتصال گوگل)' );
		}

		$settings   = AGSC_DB::get_settings();
		$end_curr   = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$start_curr = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
		$end_prev   = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$start_prev = gmdate( 'Y-m-d', strtotime( '-57 days' ) );

		// 1. Fetch Page-level Search Analytics (current 28d & previous 28d).
		$curr_pages = AGSC_Google_Client::query_search_analytics( $start_curr, $end_curr, array( 'page' ), 300 );
		if ( is_wp_error( $curr_pages ) ) {
			AGSC_DB::log_sync( 'sync', 'error', 0, 0, $curr_pages->get_error_message() );
			return $curr_pages;
		}

		$prev_pages = AGSC_Google_Client::query_search_analytics( $start_prev, $end_prev, array( 'page' ), 300 );
		$prev_map   = array();
		if ( ! is_wp_error( $prev_pages ) && ! empty( $prev_pages['rows'] ) ) {
			foreach ( $prev_pages['rows'] as $prow ) {
				$purl              = $prow['keys'][0] ?? '';
				$prev_map[ $purl ] = $prow;
			}
		}

		// 2. Fetch Page + Query combinations for striking-distance & cannibalization analysis.
		$page_queries = AGSC_Google_Client::query_search_analytics( $start_curr, $end_curr, array( 'page', 'query' ), 500 );

		// Refresh analytics cache table.
		$analytics_table = AGSC_DB::table( 'analytics' );
		$wpdb->query( "TRUNCATE TABLE {$analytics_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$now = current_time( 'mysql' );

		$detected_count = 0;
		$urls_to_inspect = array();

		if ( ! empty( $curr_pages['rows'] ) && is_array( $curr_pages['rows'] ) ) {
			foreach ( $curr_pages['rows'] as $row ) {
				$url         = $row['keys'][0] ?? '';
				$clicks      = (int) ( $row['clicks'] ?? 0 );
				$impressions = (int) ( $row['impressions'] ?? 0 );
				$ctr         = (float) ( $row['ctr'] ?? 0 );
				$position    = (float) ( $row['position'] ?? 0 );

				$prev        = isset( $prev_map[ $url ] ) ? $prev_map[ $url ] : array();
				$prev_clicks = (int) ( $prev['clicks'] ?? 0 );
				$prev_impr   = (int) ( $prev['impressions'] ?? 0 );
				$prev_pos    = (float) ( $prev['position'] ?? 0 );

				$wpdb->insert(
					$analytics_table,
					array(
						'row_type'         => 'page',
						'page_url'         => $url,
						'query_text'       => '',
						'clicks'           => $clicks,
						'impressions'      => $impressions,
						'ctr'              => $ctr,
						'position'         => $position,
						'prev_clicks'      => $prev_clicks,
						'prev_impressions' => $prev_impr,
						'prev_position'    => $prev_pos,
						'synced_at'        => $now,
					)
				);

				if ( count( $urls_to_inspect ) < (int) $settings['inspect_urls_per_sync'] ) {
					$urls_to_inspect[] = $url;
				}

				// Detect Low CTR despite high impressions & decent position.
				if ( $impressions >= 80 && $ctr < 0.025 && $position <= 15 ) {
					$detected_count += self::upsert_issue(
						'low_ctr_high_impressions',
						'medium',
						$url,
						sprintf( 'ایمپرشن بالا (%d نمایش) اما CTR پایین (%.2f%%) در رتبه %.1f', $impressions, $ctr * 100, $position ),
						array(
							'clicks'      => $clicks,
							'impressions' => $impressions,
							'ctr'         => round( $ctr * 100, 2 ),
							'position'    => round( $position, 1 ),
						),
						array( 'reason' => 'عنوان سئو و توضیحات متا جذابیت کافی برای جذب کلیک در نتایج گوگل ندارند.' )
					);
				}

				// Detect Traffic Drop (>= 30% click drop on meaningful traffic pages).
				if ( $prev_clicks >= 15 && $clicks <= ( $prev_clicks * 0.7 ) ) {
					$drop_pct        = round( ( ( $prev_clicks - $clicks ) / max( 1, $prev_clicks ) ) * 100 );
					$detected_count += self::upsert_issue(
						'traffic_drop',
						'high',
						$url,
						sprintf( 'افت %d%% کلیک ارگانیک (%d به %d کلیک در ۲۸ روز اخیر)', $drop_pct, $prev_clicks, $clicks ),
						array(
							'clicks'        => $clicks,
							'prev_clicks'   => $prev_clicks,
							'drop_percent'  => $drop_pct,
							'position'      => round( $position, 1 ),
							'prev_position' => round( $prev_pos, 1 ),
						),
						array( 'reason' => 'نیاز به به‌روزرسانی محتوا، تقویت هدینگ‌ها، اسکیما و لینک‌سازی داخلی.' )
					);
				}
			}
		}

		// 3. Analyze Page+Query combinations for Striking Distance & Cannibalization.
		$query_pages_buckets = array();
		$page_striking_map   = array();

		if ( ! is_wp_error( $page_queries ) && ! empty( $page_queries['rows'] ) ) {
			foreach ( $page_queries['rows'] as $pq ) {
				$p_url  = $pq['keys'][0] ?? '';
				$q_text = $pq['keys'][1] ?? '';
				$clicks = (int) ( $pq['clicks'] ?? 0 );
				$impr   = (int) ( $pq['impressions'] ?? 0 );
				$ctr    = (float) ( $pq['ctr'] ?? 0 );
				$pos    = (float) ( $pq['position'] ?? 0 );

				$wpdb->insert(
					$analytics_table,
					array(
						'row_type'         => 'page_query',
						'page_url'         => $p_url,
						'query_text'       => $q_text,
						'clicks'           => $clicks,
						'impressions'      => $impr,
						'ctr'              => $ctr,
						'position'         => $pos,
						'prev_clicks'      => 0,
						'prev_impressions' => 0,
						'prev_position'    => 0,
						'synced_at'        => $now,
					)
				);

				if ( $impr >= 25 ) {
					$query_pages_buckets[ $q_text ][] = array(
						'url'         => $p_url,
						'clicks'      => $clicks,
						'impressions' => $impr,
						'position'    => round( $pos, 1 ),
					);
				}

				if ( $pos >= 4.5 && $pos <= 18.0 && $impr >= 30 ) {
					$page_striking_map[ $p_url ][] = array(
						'query'       => $q_text,
						'impressions' => $impr,
						'clicks'      => $clicks,
						'position'    => round( $pos, 1 ),
					);
				}
			}
		}

		foreach ( $page_striking_map as $p_url => $kw_list ) {
			usort(
				$kw_list,
				function ( $a, $b ) {
					return $b['impressions'] <=> $a['impressions'];
				}
			);
			$top_kws         = array_slice( $kw_list, 0, 5 );
			$detected_count += self::upsert_issue(
				'striking_distance_keywords',
				'medium',
				$p_url,
				sprintf( 'فرصت جهش به رتبه‌های برتر برای عبارت «%s» (رتبه %.1f)', $top_kws[0]['query'], $top_kws[0]['position'] ),
				array( 'keywords' => $top_kws ),
				array( 'top_keywords' => $top_kws )
			);
		}

		foreach ( $query_pages_buckets as $q_text => $competing ) {
			if ( count( $competing ) >= 2 ) {
				usort(
					$competing,
					function ( $a, $b ) {
						return $b['impressions'] <=> $a['impressions'];
					}
				);
				// Flag cannibalization if at least two URLs each hold >= 25% of impressions.
				if ( $competing[1]['impressions'] >= ( $competing[0]['impressions'] * 0.3 ) ) {
					$detected_count += self::upsert_issue(
						'keyword_cannibalization',
						'medium',
						$competing[0]['url'],
						sprintf( 'رقابت داخلی (کنیبالیزیشن) روی کلمه «%s» بین %d صفحه', $q_text, count( $competing ) ),
						array( 'query' => $q_text, 'competing_pages' => array_slice( $competing, 0, 4 ) ),
						array( 'competing_pages' => array_slice( $competing, 0, 4 ) )
					);
				}
			}
		}

		// 4. Add recent published posts to URL Inspection list so unindexed pages are caught too!
		$recent_posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page', 'product' ),
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);
		foreach ( $recent_posts as $pid ) {
			if ( count( $urls_to_inspect ) >= (int) $settings['inspect_urls_per_sync'] ) {
				break;
			}
			$plink = get_permalink( $pid );
			if ( $plink && ! in_array( $plink, $urls_to_inspect, true ) ) {
				$urls_to_inspect[] = $plink;
			}
		}

		// 5. Inspect URLs via Google URL Inspection API v1.
		foreach ( $urls_to_inspect as $inspect_target ) {
			$insp = AGSC_Google_Client::inspect_url( $inspect_target );
			if ( is_wp_error( $insp ) || empty( $insp['inspectionResult'] ) ) {
				continue;
			}
			$detected_count += self::evaluate_url_inspection_result( $inspect_target, $insp['inspectionResult'] );
		}

		// 6. Also merge unresolved local 404 hits from AI Site Commander if active.
		$detected_count += self::import_commander_404_issues();

		AGSC_DB::log_sync(
			'sync',
			'success',
			$detected_count,
			0,
			sprintf( 'همگام‌سازی کامل سرچ کنسول انجام شد؛ %d مشکل جدید یا به‌روزرسانی‌شده شناسایی شد.', $detected_count )
		);

		return array(
			'synced'          => true,
			'issues_detected' => $detected_count,
			'inspected_urls'  => count( $urls_to_inspect ),
			'period'          => array( 'start' => $start_curr, 'end' => $end_curr ),
		);
	}

	/**
	 * Evaluate a Google URL Inspection API result and record any indexing/technical/schema issues.
	 *
	 * @param string $url    Inspected URL.
	 * @param array  $result inspectionResult object from Google API.
	 * @return int Count of issues upserted.
	 */
	public static function evaluate_url_inspection_result( $url, array $result ) {
		$count = 0;
		$idx   = isset( $result['indexStatusResult'] ) && is_array( $result['indexStatusResult'] ) ? $result['indexStatusResult'] : array();

		$verdict          = $idx['verdict'] ?? '';
		$coverage_state   = $idx['coverageState'] ?? '';
		$robots_state     = $idx['robotsTxtState'] ?? '';
		$indexing_state   = $idx['indexingState'] ?? '';
		$page_fetch_state = $idx['pageFetchState'] ?? '';
		$google_canonical = $idx['googleCanonical'] ?? '';
		$user_canonical   = $idx['userCanonical'] ?? '';

		// 404 / Soft 404.
		if ( in_array( $page_fetch_state, array( 'NOT_FOUND', 'SOFT_404' ), true ) || false !== stripos( $coverage_state, '404' ) ) {
			$count += self::upsert_issue(
				'not_found_404',
				'critical',
				$url,
				sprintf( 'صفحه در سرچ کنسول با خطای %s مواجه است', $page_fetch_state ? $page_fetch_state : '404' ),
				array( 'pageFetchState' => $page_fetch_state, 'coverageState' => $coverage_state ),
				$idx
			);
		}

		// Blocked by noindex.
		if ( false !== stripos( $indexing_state, 'NOINDEX' ) || false !== stripos( $coverage_state, 'noindex' ) ) {
			$count += self::upsert_issue(
				'noindex_blocked',
				'critical',
				$url,
				'ایندکس صفحه توسط تگ یا هدر noindex متوقف شده است',
				array( 'indexingState' => $indexing_state, 'coverageState' => $coverage_state ),
				$idx
			);
		}

		// Blocked by robots.txt.
		if ( 'DISALLOWED' === $robots_state ) {
			$count += self::upsert_issue(
				'robots_blocked',
				'critical',
				$url,
				'ربات گوگل توسط فایل robots.txt از خزش این صفحه منع شده است',
				array( 'robotsTxtState' => $robots_state ),
				$idx
			);
		}

		// Canonical mismatch.
		if ( '' !== $google_canonical && '' !== $user_canonical && untrailingslashit( $google_canonical ) !== untrailingslashit( $user_canonical ) ) {
			$count += self::upsert_issue(
				'canonical_mismatch',
				'high',
				$url,
				'گوگل آدرس کنونیکال متفاوتی نسبت به تگ Canonical صفحه انتخاب کرده است',
				array( 'userCanonical' => $user_canonical, 'googleCanonical' => $google_canonical ),
				$idx
			);
		}

		// Crawled / Discovered - currently not indexed.
		if ( 'PASS' !== $verdict && ( false !== stripos( $coverage_state, 'not indexed' ) ) && 'NOT_FOUND' !== $page_fetch_state ) {
			$count += self::upsert_issue(
				'crawled_not_indexed',
				'high',
				$url,
				sprintf( 'صفحه ایندکس نشده است (%s)', $coverage_state ),
				array( 'verdict' => $verdict, 'coverageState' => $coverage_state ),
				$idx
			);
		}

		// Rich Results / Schema errors.
		if ( ! empty( $result['richResultsResult']['verdict'] ) && 'FAIL' === $result['richResultsResult']['verdict'] ) {
			$count += self::upsert_issue(
				'rich_results_error',
				'high',
				$url,
				'خطای ساختاری در اسکیما (Rich Results) در سرچ کنسول گزارش شده است',
				array( 'verdict' => 'FAIL' ),
				$result['richResultsResult']
			);
		}

		return $count;
	}

	/**
	 * Import frequent unresolved 404 paths captured by AI Site Commander into actionable GSC/SEO issues.
	 *
	 * @return int
	 */
	public static function import_commander_404_issues() {
		if ( ! class_exists( 'ASC_Redirect_404' ) ) {
			return 0;
		}
		$count = 0;
		$logs  = ASC_Redirect_404::list_404_logs( true, 20 );
		foreach ( $logs as $err ) {
			if ( (int) $err['hits'] < 2 ) {
				continue;
			}
			$full_url = home_url( $err['request_path'] );
			$count   += self::upsert_issue(
				'not_found_404',
				'critical',
				$full_url,
				sprintf( 'آدرس شکسته ۴۰۴ با %d بار مراجعه کاربران/ربات‌ها (%s)', (int) $err['hits'], $err['request_path'] ),
				array( 'hits' => (int) $err['hits'], 'referer' => $err['referer'] ),
				$err
			);
		}
		return $count;
	}

	/**
	 * Local fallback audit when Google credentials are not yet configured.
	 *
	 * @param string $log_note Note for sync log.
	 * @return array
	 */
	public static function scan_local_site_issues( $log_note = '' ) {
		$count = self::import_commander_404_issues();

		if ( class_exists( 'ASC_SEO_Manager' ) ) {
			$audit = ASC_SEO_Manager::bulk_seo_audit( 60 );
			if ( ! empty( $audit['items'] ) ) {
				foreach ( $audit['items'] as $item ) {
					if ( in_array( 'marked_noindex', $item['findings'], true ) ) {
						$count += self::upsert_issue(
							'noindex_blocked',
							'critical',
							$item['url'],
							sprintf( 'صفحه منتشرشده «%s» دارای تگ noindex است', $item['title'] ),
							array( 'word_count' => $item['word_count'] ),
							$item
						);
					} elseif ( in_array( 'missing_meta_title', $item['findings'], true ) || in_array( 'missing_meta_description', $item['findings'], true ) ) {
						$count += self::upsert_issue(
							'missing_seo_metadata',
							'medium',
							$item['url'],
							sprintf( 'نقص متا تایتل یا توضیحات سئو در «%s»', $item['title'] ),
							array( 'word_count' => $item['word_count'], 'findings' => $item['findings'] ),
							$item
						);
					}
				}
			}
		}

		AGSC_DB::log_sync( 'local_scan', 'success', $count, 0, $log_note ? $log_note : 'اسکن محلی سایت انجام شد.' );

		return array(
			'synced'          => false,
			'local_scan'      => true,
			'issues_detected' => $count,
			'message'         => 'اسکن داخلی سایت انجام شد. برای دریافت داده‌های زنده گوگل، اطلاعات سرچ کنسول را در تب تنظیمات وارد کنید.',
		);
	}

	/**
	 * Upsert a detected issue into wp_agsc_issues without duplicating open issues.
	 *
	 * @param string $issue_type Issue type slug.
	 * @param string $severity   critical|high|medium|low.
	 * @param string $url        Affected URL.
	 * @param string $title      Concise Persian summary.
	 * @param array  $metrics    Key numbers.
	 * @param array  $evidence   Raw GSC / diagnostic evidence.
	 * @return int 1 if inserted/updated, 0 otherwise.
	 */
	public static function upsert_issue( $issue_type, $severity, $url, $title, array $metrics = array(), array $evidence = array() ) {
		global $wpdb;

		$clean_url   = esc_url_raw( (string) $url );
		$fingerprint = hash( 'sha256', $issue_type . '|' . untrailingslashit( $clean_url ) );
		$table       = AGSC_DB::table( 'issues' );
		$post_id     = class_exists( 'ASC_Content_Manager' )
			? ASC_Content_Manager::resolve_url_to_post_id( $clean_url )
			: url_to_postid( $clean_url );

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status FROM {$table} WHERE fingerprint = %s LIMIT 1", $fingerprint ),
			ARRAY_A
		);

		$now = current_time( 'mysql' );

		if ( $existing ) {
			// Re-open if previously fixed and still occurring, otherwise refresh metrics.
			$new_status = ( 'ignored' === $existing['status'] ) ? 'ignored' : ( 'fixed' === $existing['status'] ? 'open' : $existing['status'] );
			$wpdb->update(
				$table,
				array(
					'severity'          => sanitize_key( $severity ),
					'post_id'           => absint( $post_id ),
					'title'             => sanitize_text_field( mb_substr( $title, 0, 250 ) ),
					'metrics_json'      => wp_json_encode( $metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'gsc_evidence_json' => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'status'            => $new_status,
					'updated_at'        => $now,
				),
				array( 'id' => (int) $existing['id'] )
			);
			return 1;
		}

		$wpdb->insert(
			$table,
			array(
				'fingerprint'       => $fingerprint,
				'issue_type'        => sanitize_key( $issue_type ),
				'severity'          => sanitize_key( $severity ),
				'url'               => $clean_url,
				'post_id'           => absint( $post_id ),
				'title'             => sanitize_text_field( mb_substr( $title, 0, 250 ) ),
				'metrics_json'      => wp_json_encode( $metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'gsc_evidence_json' => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'            => 'open',
				'detected_at'       => $now,
				'updated_at'        => $now,
			)
		);

		return 1;
	}
}
