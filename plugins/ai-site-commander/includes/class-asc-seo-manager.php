<?php
/**
 * Universal SEO & Schema manager supporting Rank Math, Yoast SEO, AIOSEO, and Native Fallback.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_SEO_Manager {

	/**
	 * Register frontend hooks for native SEO fallback, custom JSON-LD schema, and robots.txt rules.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'render_head_tags' ), 2 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_document_title' ), 20 );
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 99, 2 );
	}

	/**
	 * Detect which major SEO plugin is currently active on this WordPress site.
	 *
	 * @return string rank_math|yoast|aioseo|native
	 */
	public static function detect_seo_plugin() {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rank_math';
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}
		return 'native';
	}

	/**
	 * Read unified SEO metadata for a given post ID across Rank Math, Yoast, AIOSEO, and Native meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function get_post_seo( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'asc_post_not_found', 'نوشته یا برگه مورد نظر یافت نشد.', array( 'status' => 404 ) );
		}

		$provider = self::detect_seo_plugin();

		$title          = '';
		$description    = '';
		$focus_keyword  = '';
		$canonical      = '';
		$noindex        = false;
		$nofollow       = false;

		if ( 'rank_math' === $provider ) {
			$title         = (string) get_post_meta( $post_id, 'rank_math_title', true );
			$description   = (string) get_post_meta( $post_id, 'rank_math_description', true );
			$focus_keyword = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
			$canonical     = (string) get_post_meta( $post_id, 'rank_math_canonical_url', true );
			$rm_robots     = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $rm_robots ) ) {
				$noindex  = in_array( 'noindex', $rm_robots, true );
				$nofollow = in_array( 'nofollow', $rm_robots, true );
			}
		} elseif ( 'yoast' === $provider ) {
			$title         = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
			$description   = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
			$focus_keyword = (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
			$canonical     = (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
			$noindex       = '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			$nofollow      = '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
		} elseif ( 'aioseo' === $provider ) {
			$title       = (string) get_post_meta( $post_id, '_aioseo_title', true );
			$description = (string) get_post_meta( $post_id, '_aioseo_description', true );
			$canonical   = (string) get_post_meta( $post_id, '_aioseo_canonical_url', true );
			$noindex     = '1' === (string) get_post_meta( $post_id, '_aioseo_noindex', true );
		}

		// Fallback to native _asc_seo_* fields if empty.
		if ( '' === $title ) {
			$title = (string) get_post_meta( $post_id, '_asc_seo_title', true );
		}
		if ( '' === $description ) {
			$description = (string) get_post_meta( $post_id, '_asc_seo_description', true );
		}
		if ( '' === $focus_keyword ) {
			$focus_keyword = (string) get_post_meta( $post_id, '_asc_seo_focus_keyword', true );
		}
		if ( '' === $canonical ) {
			$canonical = (string) get_post_meta( $post_id, '_asc_seo_canonical', true );
		}
		if ( 'native' === $provider ) {
			$noindex  = '1' === (string) get_post_meta( $post_id, '_asc_seo_noindex', true );
			$nofollow = '1' === (string) get_post_meta( $post_id, '_asc_seo_nofollow', true );
		}

		$schema_raw = (string) get_post_meta( $post_id, '_asc_schema_jsonld', true );

		return array(
			'post_id'       => (int) $post->ID,
			'url'           => get_permalink( $post->ID ),
			'post_title'    => $post->post_title,
			'seo_provider'  => $provider,
			'meta_title'    => $title,
			'meta_desc'     => $description,
			'focus_keyword' => $focus_keyword,
			'canonical_url' => $canonical,
			'noindex'       => (bool) $noindex,
			'nofollow'      => (bool) $nofollow,
			'schema_jsonld' => $schema_raw ? json_decode( $schema_raw, true ) : null,
			'word_count'    => self::count_words( $post->post_content ),
			'headings'      => self::extract_headings( $post->post_content ),
		);
	}

	/**
	 * Update SEO metadata for a post with automatic Snapshot before mutating.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $data    SEO fields to update.
	 * @param string $actor   Actor identifier.
	 * @param string $reason  Reason for audit/snapshot.
	 * @return array|WP_Error
	 */
	public static function update_post_seo( $post_id, array $data, $actor = 'ai-agent', $reason = '' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'asc_post_not_found', 'نوشته یا برگه مورد نظر یافت نشد.', array( 'status' => 404 ) );
		}

		$before_state = ASC_Snapshot::capture_post_state( $post_id );
		$snapshot_id  = ASC_Snapshot::create(
			'update_seo',
			'seo',
			(string) $post_id,
			$post->post_title . ' (#' . $post_id . ')',
			$before_state,
			$actor,
			$reason ? $reason : 'به‌روزرسانی متادیتای سئو و اسکیما'
		);

		if ( array_key_exists( 'meta_title', $data ) ) {
			$val = sanitize_text_field( (string) $data['meta_title'] );
			update_post_meta( $post_id, '_asc_seo_title', $val );
			update_post_meta( $post_id, 'rank_math_title', $val );
			update_post_meta( $post_id, '_yoast_wpseo_title', $val );
			update_post_meta( $post_id, '_aioseo_title', $val );
		}

		if ( array_key_exists( 'meta_desc', $data ) ) {
			$val = sanitize_textarea_field( (string) $data['meta_desc'] );
			update_post_meta( $post_id, '_asc_seo_description', $val );
			update_post_meta( $post_id, 'rank_math_description', $val );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $val );
			update_post_meta( $post_id, '_aioseo_description', $val );
		}

		if ( array_key_exists( 'focus_keyword', $data ) ) {
			$val = sanitize_text_field( (string) $data['focus_keyword'] );
			update_post_meta( $post_id, '_asc_seo_focus_keyword', $val );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $val );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $val );
		}

		if ( array_key_exists( 'canonical_url', $data ) ) {
			$val = '' !== trim( (string) $data['canonical_url'] ) ? esc_url_raw( (string) $data['canonical_url'] ) : '';
			update_post_meta( $post_id, '_asc_seo_canonical', $val );
			update_post_meta( $post_id, 'rank_math_canonical_url', $val );
			update_post_meta( $post_id, '_yoast_wpseo_canonical', $val );
			update_post_meta( $post_id, '_aioseo_canonical_url', $val );
		}

		if ( array_key_exists( 'noindex', $data ) || array_key_exists( 'nofollow', $data ) ) {
			$current  = self::get_post_seo( $post_id );
			$noindex  = array_key_exists( 'noindex', $data ) ? (bool) $data['noindex'] : $current['noindex'];
			$nofollow = array_key_exists( 'nofollow', $data ) ? (bool) $data['nofollow'] : $current['nofollow'];

			update_post_meta( $post_id, '_asc_seo_noindex', $noindex ? '1' : '0' );
			update_post_meta( $post_id, '_asc_seo_nofollow', $nofollow ? '1' : '0' );

			// Rank Math robots array.
			$rm_robots = array( $noindex ? 'noindex' : 'index', $nofollow ? 'nofollow' : 'follow' );
			update_post_meta( $post_id, 'rank_math_robots', $rm_robots );

			// Yoast robots flags ('1' = noindex, '2' or delete = index).
			if ( $noindex ) {
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
			} else {
				delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex' );
			}
			if ( $nofollow ) {
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', '1' );
			} else {
				delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow' );
			}

			// AIOSEO flag.
			update_post_meta( $post_id, '_aioseo_noindex', $noindex ? '1' : '0' );
		}

		if ( array_key_exists( 'schema_jsonld', $data ) ) {
			if ( empty( $data['schema_jsonld'] ) ) {
				delete_post_meta( $post_id, '_asc_schema_jsonld' );
			} else {
				$schema_payload = is_string( $data['schema_jsonld'] )
					? json_decode( $data['schema_jsonld'], true )
					: $data['schema_jsonld'];

				if ( ! is_array( $schema_payload ) ) {
					return new WP_Error( 'asc_invalid_schema', 'فرمت JSON-LD اسکیما معتبر نیست.', array( 'status' => 400 ) );
				}
				update_post_meta(
					$post_id,
					'_asc_schema_jsonld',
					wp_slash( wp_json_encode( $schema_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) )
				);
			}
		}

		ASC_Snapshot::record_after_state( $snapshot_id, ASC_Snapshot::capture_post_state( $post_id ) );

		$updated = self::get_post_seo( $post_id );
		$updated['snapshot_id'] = $snapshot_id;
		return $updated;
	}

	/**
	 * Run a site-wide technical SEO audit across published posts/pages/products.
	 *
	 * @param int $limit Max items to scan.
	 * @return array
	 */
	public static function bulk_seo_audit( $limit = 100 ) {
		$post_types = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$post_types[] = 'product';
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => min( 250, max( 1, absint( $limit ) ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$issues = array();
		foreach ( $posts as $p ) {
			$seo      = self::get_post_seo( $p->ID );
			$findings = array();

			if ( empty( $seo['meta_title'] ) ) {
				$findings[] = 'missing_meta_title';
			} elseif ( mb_strlen( $seo['meta_title'] ) > 65 ) {
				$findings[] = 'meta_title_too_long';
			}

			if ( empty( $seo['meta_desc'] ) ) {
				$findings[] = 'missing_meta_description';
			} elseif ( mb_strlen( $seo['meta_desc'] ) < 70 || mb_strlen( $seo['meta_desc'] ) > 165 ) {
				$findings[] = 'meta_description_suboptimal_length';
			}

			if ( ! empty( $seo['noindex'] ) ) {
				$findings[] = 'marked_noindex';
			}

			if ( $seo['word_count'] < 250 && 'page' !== $p->post_type ) {
				$findings[] = 'thin_content';
			}

			if ( empty( $seo['headings']['h2'] ) && $seo['word_count'] > 300 ) {
				$findings[] = 'missing_h2_headings';
			}

			if ( ! empty( $findings ) ) {
				$issues[] = array(
					'post_id'    => (int) $p->ID,
					'post_type'  => $p->post_type,
					'title'      => $p->post_title,
					'url'        => get_permalink( $p->ID ),
					'word_count' => $seo['word_count'],
					'findings'   => $findings,
				);
			}
		}

		return array(
			'scanned_count' => count( $posts ),
			'seo_provider'  => self::detect_seo_plugin(),
			'issues_count'  => count( $issues ),
			'items'         => $issues,
		);
	}

	/**
	 * Output native SEO tags (when no third-party SEO plugin is active) and custom JSON-LD schema.
	 */
	public static function render_head_tags() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$settings = ASC_DB::get_settings();
		$provider = self::detect_seo_plugin();

		if ( 'native' === $provider && ! empty( $settings['enable_native_seo'] ) ) {
			$desc      = (string) get_post_meta( $post_id, '_asc_seo_description', true );
			$canonical = (string) get_post_meta( $post_id, '_asc_seo_canonical', true );
			$noindex   = '1' === (string) get_post_meta( $post_id, '_asc_seo_noindex', true );
			$nofollow  = '1' === (string) get_post_meta( $post_id, '_asc_seo_nofollow', true );

			if ( '' !== $desc ) {
				echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
			}
			if ( '' !== $canonical ) {
				echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
			}
			if ( $noindex || $nofollow ) {
				$directives = array(
					$noindex ? 'noindex' : 'index',
					$nofollow ? 'nofollow' : 'follow',
				);
				echo '<meta name="robots" content="' . esc_attr( implode( ', ', $directives ) ) . '" />' . "\n";
			}
		}

		// Always output custom AI-injected JSON-LD schema if present on this singular entity.
		$schema_raw = (string) get_post_meta( $post_id, '_asc_schema_jsonld', true );
		if ( '' !== $schema_raw ) {
			$decoded = json_decode( $schema_raw, true );
			if ( is_array( $decoded ) ) {
				echo '<script type="application/ld+json" id="asc-custom-schema">' . wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
			}
		}
	}

	/**
	 * Filter document <title> when running in native SEO mode.
	 *
	 * @param string $title Existing document title.
	 * @return string
	 */
	public static function filter_document_title( $title ) {
		if ( ! is_singular() || 'native' !== self::detect_seo_plugin() ) {
			return $title;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return $title;
		}
		$custom = (string) get_post_meta( $post_id, '_asc_seo_title', true );
		return '' !== $custom ? $custom : $title;
	}

	/**
	 * Append custom robots.txt rules managed via AI Site Commander.
	 *
	 * @param string $output Existing robots.txt content.
	 * @param bool   $public Site visibility flag.
	 * @return string
	 */
	public static function filter_robots_txt( $output, $public ) {
		$settings = ASC_DB::get_settings();
		if ( ! empty( $settings['custom_robots_append'] ) ) {
			$output .= "\n# Managed by AI Site Commander\n" . trim( (string) $settings['custom_robots_append'] ) . "\n";
		}
		return $output;
	}

	/**
	 * Count words cleanly across Persian/Arabic/UTF-8 and Latin text.
	 *
	 * @param string $html HTML content.
	 * @return int
	 */
	public static function count_words( $html ) {
		$plain = trim( wp_strip_all_tags( strip_shortcodes( (string) $html ) ) );
		if ( '' === $plain ) {
			return 0;
		}
		$tokens = preg_split( '/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $tokens ) ? count( $tokens ) : 0;
	}

	/**
	 * Extract H1-H4 headings from HTML content.
	 *
	 * @param string $html HTML content.
	 * @return array
	 */
	public static function extract_headings( $html ) {
		$result = array(
			'h1' => array(),
			'h2' => array(),
			'h3' => array(),
		);
		if ( preg_match_all( '/<(h[1-3])\b[^>]*>(.*?)<\/\1>/isu', (string) $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$tag              = strtolower( $match[1] );
				$result[ $tag ][] = trim( wp_strip_all_tags( $match[2] ) );
			}
		}
		return $result;
	}
}
