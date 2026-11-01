<?php
/**
 * Hybrid AI Root-Cause Analyzer & Executable Fix Plan Builder.
 *
 * Supports OpenAI, Anthropic Claude, Google Gemini, OpenRouter/Custom endpoints,
 * plus an intelligent local SEO diagnostic planner when no external LLM key is set
 * or when an external MCP AI agent drives execution.
 *
 * @package AI_GSC_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGSC_AI_Analyzer {

	/**
	 * Analyze a single issue from wp_agsc_issues and store its diagnosis + executable fix_plan_json.
	 *
	 * @param int $issue_id Issue ID.
	 * @return array|WP_Error
	 */
	public static function analyze_issue( $issue_id ) {
		global $wpdb;

		$table = AGSC_DB::table( 'issues' );
		$issue = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $issue_id ) ), ARRAY_A );
		if ( ! $issue ) {
			return new WP_Error( 'agsc_issue_not_found', 'مشکل مورد نظر یافت نشد.', array( 'status' => 404 ) );
		}

		$post_context = self::gather_local_page_context( (int) $issue['post_id'], $issue['url'] );
		$top_queries  = self::get_top_queries_for_url( $issue['url'] );

		// Build baseline deterministic plan first (fast, reliable, guaranteed schema).
		$plan = self::build_smart_baseline_plan( $issue, $post_context, $top_queries );

		// If an AI API key is configured, enrich diagnosis & SEO copy with LLM intelligence!
		$ai_key = AGSC_DB::get_secret( 'ai_api_key' );
		if ( '' !== $ai_key ) {
			$llm_plan = self::call_llm_for_fix_plan( $issue, $post_context, $top_queries, $plan, $ai_key );
			if ( ! is_wp_error( $llm_plan ) && is_array( $llm_plan ) && ! empty( $llm_plan['actions'] ) ) {
				$plan = $llm_plan;
			}
		}

		$wpdb->update(
			$table,
			array(
				'ai_diagnosis'  => sanitize_textarea_field( (string) ( $plan['diagnosis_fa'] ?? '' ) ),
				'fix_plan_json' => wp_json_encode( $plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'        => 'fixed' === $issue['status'] ? 'fixed' : 'analyzed',
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => (int) $issue['id'] )
		);

		return array(
			'issue_id' => (int) $issue['id'],
			'status'   => 'analyzed',
			'plan'     => $plan,
		);
	}

	/**
	 * Gather WordPress post content, current SEO meta, and site context for an affected URL.
	 *
	 * @param int    $post_id Resolved post ID (or 0).
	 * @param string $url     Affected URL.
	 * @return array
	 */
	private static function gather_local_page_context( $post_id, $url ) {
		if ( ! $post_id && class_exists( 'ASC_Content_Manager' ) ) {
			$post_id = ASC_Content_Manager::resolve_url_to_post_id( $url );
		}

		if ( $post_id > 0 && get_post( $post_id ) ) {
			$post    = get_post( $post_id );
			$seo     = class_exists( 'ASC_SEO_Manager' ) ? ASC_SEO_Manager::get_post_seo( $post_id ) : array();
			$excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 80, '...' );
			return array(
				'exists'       => true,
				'post_id'      => (int) $post->ID,
				'post_type'    => $post->post_type,
				'post_title'   => $post->post_title,
				'permalink'    => get_permalink( $post->ID ),
				'excerpt_text' => $excerpt,
				'seo'          => is_array( $seo ) ? $seo : array(),
			);
		}

		return array(
			'exists'  => false,
			'post_id' => 0,
			'url'     => $url,
		);
	}

	/**
	 * Fetch top Search Console queries recorded for a given URL.
	 *
	 * @param string $url Page URL.
	 * @return array
	 */
	private static function get_top_queries_for_url( $url ) {
		global $wpdb;
		$table = AGSC_DB::table( 'analytics' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_text, clicks, impressions, ctr, position FROM {$table} WHERE row_type = 'page_query' AND page_url = %s ORDER BY impressions DESC LIMIT 8",
				$url
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build a rock-solid deterministic diagnosis and executable AI Site Commander action list.
	 *
	 * @param array $issue        Issue row from wp_agsc_issues.
	 * @param array $post_context Local WP post context.
	 * @param array $top_queries  Top GSC queries for this page.
	 * @return array
	 */
	public static function build_smart_baseline_plan( array $issue, array $post_context, array $top_queries ) {
		$type        = $issue['issue_type'];
		$url         = $issue['url'];
		$post_id     = ! empty( $post_context['post_id'] ) ? (int) $post_context['post_id'] : 0;
		$site_name   = get_bloginfo( 'name' );
		$primary_kw  = ! empty( $top_queries[0]['query_text'] ) ? $top_queries[0]['query_text'] : ( $post_context['post_title'] ?? '' );
		$actions     = array();
		$diagnosis   = '';

		switch ( $type ) {
			case 'not_found_404':
				$closest = class_exists( 'ASC_Content_Manager' )
					? ASC_Content_Manager::find_closest_matching_post( $url )
					: null;
				$source_path = wp_parse_url( $url, PHP_URL_PATH );
				if ( $closest && untrailingslashit( $closest['url'] ) !== untrailingslashit( $url ) ) {
					$diagnosis = sprintf(
						'این آدرس در گوگل یا مراجعات کاربران با خطای ۴۰۴ مواجه شده است. نزدیک‌ترین محتوای مرتبط در سایت شما «%s» شناسایی شد؛ با ثبت ریدایرکت ۳۰۱ اعتبار سئو و ورودی به صفحه فعال منتقل می‌شود.',
						$closest['title']
					);
					$actions[] = array(
						'plugin' => 'ai-site-commander',
						'action' => 'save_redirect',
						'params' => array(
							'source_path' => $source_path ? $source_path : $url,
							'target_url'  => $closest['url'],
							'status_code' => 301,
							'note'        => 'رفع خودکار خطای ۴۰۴ سرچ کنسول',
						),
					);
				} else {
					$diagnosis = 'این آدرس وجود خارجی ندارد و محصول یا مقاله جایگزین مشابهی در سایت یافت نشد. بازگرداندن کد وضعیت ۴۱۰ (Gone) به گوگل اعلام می‌کند این آدرس دائماً حذف شده و بدون ایجاد خطای Soft-404 از صف خزش خارج می‌شود.';
					$actions[] = array(
						'plugin' => 'ai-site-commander',
						'action' => 'save_redirect',
						'params' => array(
							'source_path' => $source_path ? $source_path : $url,
							'target_url'  => '',
							'status_code' => 410,
							'note'        => 'حذف دائمی ۴۱۰ برای جلوگیری از Soft-404 در سرچ کنسول',
						),
					);
				}
				break;

			case 'noindex_blocked':
				$diagnosis = 'این صفحه منتشر شده اما به دلیل فعال بودن تگ متا noindex از نتایج گوگل حذف شده است. با برداشتن تگ noindex، تنظیم کنونیکال استاندارد و ارسال مجدد سایت‌مپ مشکل برطرف می‌شود.';
				if ( $post_id > 0 ) {
					$actions[] = array(
						'plugin' => 'ai-site-commander',
						'action' => 'update_seo',
						'params' => array(
							'post_id'       => $post_id,
							'noindex'       => false,
							'canonical_url' => get_permalink( $post_id ),
						),
					);
				}
				$actions[] = array(
					'plugin' => 'ai-gsc-autopilot',
					'action' => 'submit_sitemap',
					'params' => array(),
				);
				break;

			case 'canonical_mismatch':
				$target_canonical = $post_id > 0 ? get_permalink( $post_id ) : $url;
				$diagnosis        = 'آدرس کنونیکال انتخابی گوگل با آدرس استاندارد صفحه مغایرت دارد که باعث تقسیم اعتبار سئو می‌شود. تنظیم صریح تگ Canonical خودارجاع (Self-Referencing) در متادیتای صفحه این ابهام را رفع می‌کند.';
				if ( $post_id > 0 ) {
					$actions[] = array(
						'plugin' => 'ai-site-commander',
						'action' => 'update_seo',
						'params' => array(
							'post_id'       => $post_id,
							'canonical_url' => $target_canonical,
							'noindex'       => false,
						),
					);
				}
				break;

			case 'low_ctr_high_impressions':
			case 'missing_seo_metadata':
			case 'traffic_drop':
				$url_path   = (string) wp_parse_url( $url, PHP_URL_PATH );
				$is_en_page = ( '/en' === untrailingslashit( $url_path ) || 0 === strpos( $url_path, '/en/' ) );
				$base_title = ! empty( $post_context['post_title'] ) ? $post_context['post_title'] : $primary_kw;

				if ( $is_en_page ) {
					$opt_title = mb_substr( trim( $base_title ) . ' — Authentic Price, Specs & Shipping | Volusx', 0, 65 );
					$opt_desc  = ! empty( $post_context['excerpt_text'] )
						? mb_substr( 'Shop authentic ' . $base_title . ' at Volusx — ' . $post_context['excerpt_text'], 0, 155 )
						: mb_substr( 'Order original ' . $base_title . ' with verified batch authenticity, clinical specs, and cold-chain shipping at Volusx.', 0, 155 );
				} else {
					$opt_title = mb_substr( trim( $base_title ) . ' | مشخصات، اصالت و قیمت روز — ' . $site_name, 0, 65 );
					$opt_desc  = ! empty( $post_context['excerpt_text'] )
						? mb_substr( 'بررسی تخصصی و خرید ' . $primary_kw . ' — ' . $post_context['excerpt_text'], 0, 155 )
						: mb_substr( 'مشاهده مشخصات، تضمین اصالت و استعلام قیمت ' . $base_title . ' با ارسال استاندارد در ' . $site_name . '.', 0, 155 );
				}

				$diagnosis = 'این صفحه در نتایج جستجوی گوگل نمایش بالایی دارد اما نرخ کلیک (CTR) آن کمتر از حد استاندارد است. بازنویسی عنوان سئو (Meta Title) متناسب با زبان صفحه و توضیحات متای ترغیب‌کننده نرخ کلیک را مستقیماً افزایش می‌دهد.';

				if ( $post_id > 0 ) {
					$seo_params = array(
						'post_id'       => $post_id,
						'meta_title'    => $opt_title,
						'meta_desc'     => $opt_desc,
						'focus_keyword' => $primary_kw,
					);
					if ( 'product' !== ( $post_context['post_type'] ?? '' ) ) {
						$seo_params['schema_jsonld'] = array(
							'@context'         => 'https://schema.org',
							'@type'            => 'Article',
							'headline'         => $base_title,
							'description'      => $opt_desc,
							'mainEntityOfPage' => array(
								'@type' => 'WebPage',
								'@id'   => get_permalink( $post_id ),
							),
						);
					}

					$actions[] = array(
						'plugin' => 'ai-site-commander',
						'action' => 'update_seo',
						'params' => $seo_params,
					);
				}
				break;

			case 'striking_distance_keywords':
				$metrics  = ! empty( $issue['metrics_json'] ) ? json_decode( $issue['metrics_json'], true ) : array();
				$kw_items = ! empty( $metrics['keywords'] ) ? $metrics['keywords'] : array();
				$main_kw  = ! empty( $kw_items[0]['query'] ) ? $kw_items[0]['query'] : $primary_kw;

				$diagnosis = sprintf(
					'این صفحه برای عبارت کلیدی ارزشمند «%s» در آستانه صفحه اول گوگل قرار دارد. با بهینه‌سازی کلمه کلیدی کانونی، تقویت متا تایتل/دیسکریپشن و لینک‌سازی داخلی از صفحات مرتبط، شانس ورود به ۳ رتبه اول به شکل چشمگیری افزایش می‌یابد.',
					$main_kw
				);

				if ( $post_id > 0 ) {
					$base_title = $post_context['post_title'] ?? $main_kw;
					$actions[]  = array(
						'plugin' => 'ai-site-commander',
						'action' => 'update_seo',
						'params' => array(
							'post_id'       => $post_id,
							'focus_keyword' => $main_kw,
							'meta_title'    => mb_substr( $main_kw . ' — ' . $base_title, 0, 60 ),
							'meta_desc'     => mb_substr( 'راهنمای کامل ' . $main_kw . '؛ بررسی نکات کاربردی و تخصصی در ' . $site_name . ' که باید بدانید.', 0, 155 ),
						),
					);

					$donor_post = self::find_internal_link_donor_post( $post_id, $main_kw );
					if ( $donor_post ) {
						$actions[] = array(
							'plugin' => 'ai-site-commander',
							'action' => 'inject_internal_link',
							'params' => array(
								'source_post_id' => $donor_post['id'],
								'anchor_text'    => $main_kw,
								'target_url'     => get_permalink( $post_id ),
							),
						);
					}
				}
				break;

			case 'robots_blocked':
			case 'redirect_page':
			case 'discovered_not_indexed':
			case 'crawled_not_indexed':
			case 'rich_results_error':
			case 'keyword_cannibalization':
			default:
				$diagnosis = 'این صفحه در سرچ کنسول ایندکس نشده است. با اجرای موتور جامع اصلاح ایندکس (آزادسازی robots.txt، حذف noindex ناخواسته، تکمیل اسکیمای Product با واحد IRR، تزریق شبکه لینک داخلی هم‌زبان و ارسال IndexNow + نقشه سایت) سیگنال‌های خزش و ایندکس کامل می‌شوند.';

				$actions[] = array(
					'plugin' => 'ai-site-commander',
					'action' => 'remediate_gsc_indexing',
					'params' => array(),
				);

				if ( $post_id > 0 ) {
					$base_title = $post_context['post_title'] ?? get_bloginfo( 'name' );
					$seo_params = array(
						'post_id'       => $post_id,
						'noindex'       => false,
						'canonical_url' => get_permalink( $post_id ),
					);
					if ( 'product' !== ( $post_context['post_type'] ?? '' ) ) {
						$seo_params['schema_jsonld'] = array(
							'@context'     => 'https://schema.org',
							'@type'        => 'Article',
							'headline'     => $base_title,
							'url'          => get_permalink( $post_id ),
							'dateModified' => gmdate( 'c' ),
						);
					}

					$actions[] = array(
						'plugin' => 'ai-site-commander',
						'action' => 'update_seo',
						'params' => $seo_params,
					);

					$donor = self::find_internal_link_donor_post( $post_id, $base_title );
					if ( $donor ) {
						$actions[] = array(
							'plugin' => 'ai-site-commander',
							'action' => 'inject_internal_link',
							'params' => array(
								'source_post_id' => $donor['id'],
								'anchor_text'    => $base_title,
								'target_url'     => get_permalink( $post_id ),
							),
						);
					}
				}

				$actions[] = array(
					'plugin' => 'ai-gsc-autopilot',
					'action' => 'submit_sitemap',
					'params' => array(),
				);
				break;
		}

		return array(
			'generator'    => 'smart-baseline',
			'confidence'   => 88,
			'diagnosis_fa' => $diagnosis,
			'actions'      => $actions,
		);
	}

	/**
	 * Find another published post on the site suitable for passing internal link equity to target post.
	 *
	 * @param int    $exclude_post_id Target post ID to exclude.
	 * @param string $keyword         Related search keyword.
	 * @return array|null
	 */
	private static function find_internal_link_donor_post( $exclude_post_id, $keyword ) {
		$target_url  = get_permalink( absint( $exclude_post_id ) );
		$target_path = (string) wp_parse_url( (string) $target_url, PHP_URL_PATH );
		$want_en     = ( '/en' === untrailingslashit( $target_path ) || 0 === strpos( $target_path, '/en/' ) );

		$candidates = get_posts(
			array(
				's'                => sanitize_text_field( $keyword ),
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'post__not_in'     => array( absint( $exclude_post_id ) ),
				'posts_per_page'   => 10,
				'suppress_filters' => true,
			)
		);

		if ( empty( $candidates ) ) {
			$candidates = get_posts(
				array(
					'post_type'        => 'post',
					'post_status'      => 'publish',
					'post__not_in'     => array( absint( $exclude_post_id ) ),
					'posts_per_page'   => 15,
					'orderby'          => 'comment_count',
					'order'            => 'DESC',
					'suppress_filters' => true,
				)
			);
		}

		foreach ( (array) $candidates as $cand ) {
			$c_path = (string) wp_parse_url( (string) get_permalink( $cand->ID ), PHP_URL_PATH );
			$is_en  = ( '/en' === untrailingslashit( $c_path ) || 0 === strpos( $c_path, '/en/' ) );
			if ( $is_en === $want_en ) {
				return array(
					'id'    => (int) $cand->ID,
					'title' => $cand->post_title,
				);
			}
		}
		return null;
	}

	/**
	 * Enrich diagnosis and SEO copy via configured LLM provider (OpenAI / Anthropic / Gemini / Custom).
	 *
	 * @param array  $issue        Issue DB row.
	 * @param array  $post_context Local post context.
	 * @param array  $top_queries  GSC queries.
	 * @param array  $baseline     Deterministic baseline plan.
	 * @param string $api_key      Decrypted LLM API key.
	 * @return array|WP_Error
	 */
	private static function call_llm_for_fix_plan( array $issue, array $post_context, array $top_queries, array $baseline, $api_key ) {
		$settings = AGSC_DB::get_settings();
		$provider = $settings['ai_provider'];
		$model    = $settings['ai_model'] ? $settings['ai_model'] : 'gpt-4o';

		$system_prompt = 'You are an expert technical SEO engineer for a WordPress site. Analyze the Google Search Console issue and return ONLY valid JSON matching this exact structure: {"diagnosis_fa": "string in fluent Persian", "confidence": 95, "actions": [{"plugin": "ai-site-commander", "action": "update_seo|save_redirect|update_post|inject_internal_link", "params": {...}}]}. Preserve exact post_id and valid action names from the provided baseline plan while improving meta_title (<=60 chars), meta_desc (<=155 chars), schema_jsonld, and diagnosis_fa.';

		$user_payload = wp_json_encode(
			array(
				'issue'        => array(
					'type'     => $issue['issue_type'],
					'severity' => $issue['severity'],
					'url'      => $issue['url'],
					'title'    => $issue['title'],
					'metrics'  => json_decode( (string) $issue['metrics_json'], true ),
				),
				'page_context' => $post_context,
				'top_queries'  => $top_queries,
				'baseline'     => $baseline,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$raw_reply = '';

		if ( 'anthropic' === $provider ) {
			$res = wp_remote_post(
				'https://api.anthropic.com/v1/messages',
				array(
					'timeout' => 35,
					'headers' => array(
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
						'Content-Type'      => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model'      => $model,
							'max_tokens' => 1500,
							'system'     => $system_prompt,
							'messages'   => array(
								array( 'role' => 'user', 'content' => $user_payload ),
							),
						)
					),
				)
			);
			if ( ! is_wp_error( $res ) ) {
				$body      = json_decode( wp_remote_retrieve_body( $res ), true );
				$raw_reply = $body['content'][0]['text'] ?? '';
			}
		} elseif ( 'gemini' === $provider ) {
			$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );
			$res      = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 35,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'contents'         => array(
								array(
									'parts' => array(
										array( 'text' => $system_prompt . "\n\n" . $user_payload ),
									),
								),
							),
							'generationConfig' => array( 'responseMimeType' => 'application/json' ),
						)
					),
				)
			);
			if ( ! is_wp_error( $res ) ) {
				$body      = json_decode( wp_remote_retrieve_body( $res ), true );
				$raw_reply = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
			}
		} else {
			// OpenAI or OpenAI-compatible (OpenRouter, DeepSeek, Custom).
			$base_url = ( 'custom' === $provider && ! empty( $settings['ai_custom_base_url'] ) )
				? rtrim( $settings['ai_custom_base_url'], '/' )
				: 'https://api.openai.com/v1';

			$res = wp_remote_post(
				$base_url . '/chat/completions',
				array(
					'timeout' => 35,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model'           => $model,
							'response_format' => array( 'type' => 'json_object' ),
							'messages'        => array(
								array( 'role' => 'system', 'content' => $system_prompt ),
								array( 'role' => 'user', 'content' => $user_payload ),
							),
						)
					),
				)
			);
			if ( ! is_wp_error( $res ) ) {
				$body      = json_decode( wp_remote_retrieve_body( $res ), true );
				$raw_reply = $body['choices'][0]['message']['content'] ?? '';
			}
		}

		if ( '' === $raw_reply ) {
			return new WP_Error( 'agsc_llm_empty', 'پاسخی از سرویس هوش مصنوعی دریافت نشد.' );
		}

		// Strip optional markdown code fences if present.
		$cleaned = trim( preg_replace( '/^```(?:json)?|```$/m', '', trim( $raw_reply ) ) );
		$parsed  = json_decode( $cleaned, true );

		if ( ! is_array( $parsed ) || empty( $parsed['actions'] ) || ! is_array( $parsed['actions'] ) ) {
			return new WP_Error( 'agsc_llm_invalid_json', 'خروجی هوش مصنوعی ساختار JSON معتبر نداشت.' );
		}

		$parsed['generator'] = 'llm:' . $provider . '/' . $model;
		return $parsed;
	}
}
