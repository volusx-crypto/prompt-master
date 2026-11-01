<?php
/**
 * Content, Media Alt, Internal Links, Taxonomy, and WooCommerce Product Manager.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Content_Manager {

	/**
	 * Query posts/pages/custom post types with SEO summary.
	 *
	 * @param array $args Filter parameters.
	 * @return array
	 */
	public static function list_posts( array $args = array() ) {
		$post_type   = ! empty( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'any';
		$post_status = ! empty( $args['post_status'] ) ? sanitize_key( $args['post_status'] ) : 'publish';
		$per_page    = isset( $args['per_page'] ) ? min( 100, max( 1, absint( $args['per_page'] ) ) ) : 20;
		$paged       = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$search      = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		$query_args = array(
			'post_type'      => 'any' === $post_type ? array( 'post', 'page', 'product' ) : $post_type,
			'post_status'    => $post_status,
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query = new WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$seo     = ASC_SEO_Manager::get_post_seo( $post->ID );
			$items[] = array(
				'id'            => (int) $post->ID,
				'title'         => $post->post_title,
				'slug'          => $post->post_name,
				'type'          => $post->post_type,
				'status'        => $post->post_status,
				'url'           => get_permalink( $post->ID ),
				'modified'      => $post->post_modified,
				'word_count'    => is_array( $seo ) ? $seo['word_count'] : 0,
				'meta_title'    => is_array( $seo ) ? $seo['meta_title'] : '',
				'meta_desc'     => is_array( $seo ) ? $seo['meta_desc'] : '',
				'focus_keyword' => is_array( $seo ) ? $seo['focus_keyword'] : '',
				'noindex'       => is_array( $seo ) ? $seo['noindex'] : false,
			);
		}

		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $paged,
			'items'       => $items,
		);
	}

	/**
	 * Get full details of a single post/page/CPT including content, SEO, categories/tags, and images.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function get_post_detail( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post ) {
			return new WP_Error( 'asc_post_not_found', 'محتوای مورد نظر یافت نشد.', array( 'status' => 404 ) );
		}

		$seo        = ASC_SEO_Manager::get_post_seo( $post->ID );
		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

		return array(
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'type'           => $post->post_type,
			'status'         => $post->post_status,
			'url'            => get_permalink( $post->ID ),
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'featured_image' => (int) get_post_thumbnail_id( $post->ID ),
			'categories'     => is_wp_error( $categories ) ? array() : $categories,
			'tags'           => is_wp_error( $tags ) ? array() : $tags,
			'seo'            => $seo,
		);
	}

	/**
	 * Resolve a URL on this site to its WordPress Post ID (if singular).
	 *
	 * @param string $url_or_path Full URL or relative path.
	 * @return int 0 if not resolved.
	 */
	public static function resolve_url_to_post_id( $url_or_path ) {
		$url = trim( (string) $url_or_path );
		if ( '' === $url ) {
			return 0;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$post_id = url_to_postid( $url );
		if ( $post_id > 0 ) {
			return (int) $post_id;
		}

		// Fallback by slug lookup.
		$parsed_path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $parsed_path ) {
			$slug = sanitize_title( basename( untrailingslashit( $parsed_path ) ) );
			if ( '' !== $slug ) {
				$found = get_posts(
					array(
						'name'           => $slug,
						'post_type'      => 'any',
						'post_status'    => array( 'publish', 'draft', 'private' ),
						'posts_per_page' => 1,
						'fields'         => 'ids',
					)
				);
				if ( ! empty( $found ) ) {
					return (int) $found[0];
				}
			}
		}

		return 0;
	}

	/**
	 * Find the closest published post matching a broken 404 slug (used for smart 301 redirects).
	 *
	 * @param string $broken_path Broken URL or path.
	 * @return array|null
	 */
	public static function find_closest_matching_post( $broken_path ) {
		$path = (string) wp_parse_url( (string) $broken_path, PHP_URL_PATH );
		$slug = sanitize_title( urldecode( basename( untrailingslashit( $path ) ) ) );
		if ( '' === $slug ) {
			return null;
		}

		$keywords = str_replace( array( '-', '_' ), ' ', $slug );
		$matches  = get_posts(
			array(
				's'              => $keywords,
				'post_type'      => array( 'post', 'page', 'product' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		if ( ! empty( $matches ) ) {
			$best = $matches[0];
			return array(
				'id'    => (int) $best->ID,
				'title' => $best->post_title,
				'url'   => get_permalink( $best->ID ),
			);
		}

		return null;
	}

	/**
	 * Create or update a post/page/CPT with automatic Snapshot & optional inline SEO update.
	 *
	 * @param array  $data   Post fields + optional seo sub-array.
	 * @param string $actor  Actor name.
	 * @param string $reason Reason for change.
	 * @return array|WP_Error
	 */
	public static function save_post( array $data, $actor = 'ai-agent', $reason = '' ) {
		$post_id = ! empty( $data['id'] ) ? absint( $data['id'] ) : 0;
		$is_new  = ( 0 === $post_id );

		if ( ! $is_new ) {
			$existing = get_post( $post_id );
			if ( ! $existing ) {
				return new WP_Error( 'asc_post_not_found', 'نوشته مورد نظر برای ویرایش یافت نشد.', array( 'status' => 404 ) );
			}
			$before_state = ASC_Snapshot::capture_post_state( $post_id );
		} else {
			$before_state = array( 'exists' => false, 'ID' => 0 );
		}

		$post_arr = array();
		if ( ! $is_new ) {
			$post_arr['ID'] = $post_id;
		} else {
			$post_arr['post_type']   = ! empty( $data['type'] ) ? sanitize_key( $data['type'] ) : 'post';
			$post_arr['post_status'] = ! empty( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft';
		}

		if ( isset( $data['title'] ) ) {
			$post_arr['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['content'] ) ) {
			$post_arr['post_content'] = wp_kses_post( (string) $data['content'] );
		}
		if ( isset( $data['excerpt'] ) ) {
			$post_arr['post_excerpt'] = sanitize_textarea_field( (string) $data['excerpt'] );
		}
		if ( isset( $data['slug'] ) ) {
			$post_arr['post_name'] = sanitize_title( (string) $data['slug'] );
		}
		if ( isset( $data['status'] ) ) {
			$post_arr['post_status'] = sanitize_key( (string) $data['status'] );
		}

		// Support targeted search-and-replace or append_html without replacing whole post body.
		if ( ! $is_new && ! isset( $data['content'] ) ) {
			$current_body = $existing->post_content;
			$modified     = false;

			if ( ! empty( $data['search_replace'] ) && is_array( $data['search_replace'] ) ) {
				foreach ( $data['search_replace'] as $pair ) {
					if ( ! empty( $pair['search'] ) && isset( $pair['replace'] ) ) {
						$current_body = str_replace( (string) $pair['search'], wp_kses_post( (string) $pair['replace'] ), $current_body );
						$modified     = true;
					}
				}
			}

			if ( ! empty( $data['append_html'] ) ) {
				$current_body .= "\n" . wp_kses_post( (string) $data['append_html'] );
				$modified      = true;
			}

			if ( $modified ) {
				$post_arr['post_content'] = $current_body;
			}
		}

		$result_id = $is_new
			? wp_insert_post( wp_slash( $post_arr ), true )
			: wp_update_post( wp_slash( $post_arr ), true );

		if ( is_wp_error( $result_id ) ) {
			return $result_id;
		}

		$post_id     = (int) $result_id;
		$saved_post  = get_post( $post_id );
		$snapshot_id = ASC_Snapshot::create(
			$is_new ? 'create_post' : 'update_post',
			'post',
			(string) $post_id,
			$saved_post ? $saved_post->post_title . ' (#' . $post_id . ')' : '#' . $post_id,
			$before_state,
			$actor,
			$reason ? $reason : ( $is_new ? 'ایجاد محتوای جدید' : 'ویرایش محتوای نوشته/برگه' )
		);

		// Handle categories & tags if provided.
		if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$cat_ids = array();
			foreach ( $data['categories'] as $cat ) {
				if ( is_numeric( $cat ) ) {
					$cat_ids[] = (int) $cat;
				} else {
					$term = term_exists( sanitize_text_field( $cat ), 'category' );
					if ( ! $term ) {
						$term = wp_insert_term( sanitize_text_field( $cat ), 'category' );
					}
					if ( ! is_wp_error( $term ) ) {
						$cat_ids[] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
					}
				}
			}
			wp_set_post_categories( $post_id, $cat_ids );
		}

		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $data['tags'] ), false );
		}

		// Apply inline SEO fields if included in the same payload.
		if ( ! empty( $data['seo'] ) && is_array( $data['seo'] ) ) {
			ASC_SEO_Manager::update_post_seo( $post_id, $data['seo'], $actor, $reason );
		}

		ASC_Snapshot::record_after_state( $snapshot_id, ASC_Snapshot::capture_post_state( $post_id ) );

		$detail                = self::get_post_detail( $post_id );
		$detail['snapshot_id'] = $snapshot_id;
		return $detail;
	}

	/**
	 * Inject an internal contextual link into a source post pointing to a target URL.
	 *
	 * @param int    $source_post_id Post where the link will be placed.
	 * @param string $anchor_text    Anchor text to link (or append a related block).
	 * @param string $target_url     Destination URL.
	 * @param string $actor          Actor identifier.
	 * @return array|WP_Error
	 */
	public static function inject_internal_link( $source_post_id, $anchor_text, $target_url, $actor = 'ai-agent' ) {
		$post = get_post( absint( $source_post_id ) );
		if ( ! $post ) {
			return new WP_Error( 'asc_post_not_found', 'نوشته مبدا برای لینک‌سازی داخلی یافت نشد.', array( 'status' => 404 ) );
		}

		$anchor     = sanitize_text_field( (string) $anchor_text );
		$clean_url  = esc_url( (string) $target_url );
		if ( '' === $anchor || '' === $clean_url ) {
			return new WP_Error( 'asc_invalid_link_params', 'انکر تکست و آدرس مقصد الزامی است.', array( 'status' => 400 ) );
		}

		$before_state = ASC_Snapshot::capture_post_state( $post->ID );
		$content      = $post->post_content;

		// Skip if URL is already linked inside this post.
		if ( false !== strpos( $content, $clean_url ) ) {
			return array(
				'post_id' => (int) $post->ID,
				'skipped' => true,
				'message' => 'این لینک داخلی قبلاً در محتوا وجود دارد.',
			);
		}

		$link_html = '<a href="' . $clean_url . '">' . esc_html( $anchor ) . '</a>';
		$quoted    = preg_quote( $anchor, '/' );

		// Match anchor text outside existing HTML tags or anchor elements (first occurrence only).
		$new_content = preg_replace(
			'/(?<!["\'>])(' . $quoted . ')(?![^<]*>|[^<>]*<\/a>)/u',
			$link_html,
			$content,
			1,
			$count
		);

		if ( empty( $count ) ) {
			// Append a clean contextual "بیشتر بخوانید" box at the end of the post.
			$new_content = $content . "\n" . '<p class="asc-related-link"><strong>بیشتر بخوانید:</strong> ' . $link_html . '</p>';
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $new_content,
				)
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$snapshot_id = ASC_Snapshot::create(
			'inject_internal_link',
			'post',
			(string) $post->ID,
			$post->post_title,
			$before_state,
			$actor,
			sprintf( 'افزودن لینک داخلی با انکر «%s» به %s', $anchor, $clean_url )
		);
		ASC_Snapshot::record_after_state( $snapshot_id, ASC_Snapshot::capture_post_state( $post->ID ) );

		return array(
			'post_id'     => (int) $post->ID,
			'snapshot_id' => $snapshot_id,
			'linked_url'  => $clean_url,
			'anchor_text' => $anchor,
		);
	}

	/**
	 * List image attachments missing _wp_attachment_image_alt.
	 *
	 * @param int $limit Max images to return.
	 * @return array
	 */
	public static function list_missing_image_alts( $limit = 50 ) {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => min( 200, max( 1, absint( $limit ) ) ),
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_wp_attachment_image_alt',
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);

		$items = array();
		foreach ( $attachments as $att ) {
			$parent_title = $att->post_parent ? get_the_title( $att->post_parent ) : '';
			$items[]      = array(
				'id'           => (int) $att->ID,
				'title'        => $att->post_title,
				'filename'     => basename( (string) get_attached_file( $att->ID ) ),
				'url'          => wp_get_attachment_url( $att->ID ),
				'parent_id'    => (int) $att->post_parent,
				'parent_title' => $parent_title,
			);
		}

		return $items;
	}

	/**
	 * Update alt text on one or multiple media attachments with Snapshot support.
	 *
	 * @param array  $updates Array of { id, alt_text }.
	 * @param string $actor   Actor name.
	 * @return array
	 */
	public static function update_image_alts( array $updates, $actor = 'ai-agent' ) {
		$results = array();
		foreach ( $updates as $item ) {
			$att_id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$alt_text = isset( $item['alt_text'] ) ? sanitize_text_field( (string) $item['alt_text'] ) : '';
			$att      = $att_id ? get_post( $att_id ) : null;

			if ( ! $att || 'attachment' !== $att->post_type || '' === $alt_text ) {
				continue;
			}

			$before      = ASC_Snapshot::capture_post_state( $att_id );
			$snapshot_id = ASC_Snapshot::create(
				'update_image_alt',
				'attachment',
				(string) $att_id,
				'تصویر: ' . $att->post_title . ' (#' . $att_id . ')',
				$before,
				$actor,
				'افزودن متن جایگزین (Alt) تصویر'
			);

			update_post_meta( $att_id, '_wp_attachment_image_alt', $alt_text );
			ASC_Snapshot::record_after_state( $snapshot_id, ASC_Snapshot::capture_post_state( $att_id ) );

			$results[] = array(
				'id'          => $att_id,
				'alt_text'    => $alt_text,
				'snapshot_id' => $snapshot_id,
			);
		}

		return array(
			'updated_count' => count( $results ),
			'items'         => $results,
		);
	}

	/**
	 * List WooCommerce products (if WooCommerce is active).
	 *
	 * @param array $args Query filters.
	 * @return array|WP_Error
	 */
	public static function list_woocommerce_products( array $args = array() ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'asc_woocommerce_inactive', 'افزونه ووکامرس در حال حاضر روی این سایت فعال نیست.', array( 'status' => 400 ) );
		}

		$limit    = isset( $args['per_page'] ) ? min( 100, max( 1, absint( $args['per_page'] ) ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;
		$products = wc_get_products(
			array(
				'limit'  => $limit,
				'page'   => $page,
				'status' => array( 'publish', 'draft', 'private' ),
			)
		);

		$items = array();
		foreach ( $products as $product ) {
			$items[] = array(
				'id'             => $product->get_id(),
				'name'           => $product->get_name(),
				'sku'            => $product->get_sku(),
				'status'         => $product->get_status(),
				'regular_price'  => $product->get_regular_price(),
				'sale_price'     => $product->get_sale_price(),
				'stock_status'   => $product->get_stock_status(),
				'stock_quantity' => $product->get_stock_quantity(),
				'permalink'      => get_permalink( $product->get_id() ),
			);
		}

		return array(
			'page'  => $page,
			'items' => $items,
		);
	}

	/**
	 * Update a WooCommerce product (price, sale_price, stock, SKU, descriptions, SEO) with Snapshot.
	 *
	 * @param int    $product_id Product ID.
	 * @param array  $data       Fields to update.
	 * @param string $actor      Actor identifier.
	 * @param string $reason     Reason for audit/snapshot.
	 * @return array|WP_Error
	 */
	public static function update_woocommerce_product( $product_id, array $data, $actor = 'ai-agent', $reason = '' ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'asc_woocommerce_inactive', 'افزونه ووکامرس روی این سایت فعال نیست.', array( 'status' => 400 ) );
		}

		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product ) {
			return new WP_Error( 'asc_product_not_found', 'محصول مورد نظر در ووکامرس یافت نشد.', array( 'status' => 404 ) );
		}

		$before_state = ASC_Snapshot::capture_post_state( $product->get_id() );
		$snapshot_id  = ASC_Snapshot::create(
			'update_woo_product',
			'product',
			(string) $product->get_id(),
			'محصول: ' . $product->get_name() . ' (#' . $product->get_id() . ')',
			$before_state,
			$actor,
			$reason ? $reason : 'ویرایش محصول ووکامرس'
		);

		if ( isset( $data['name'] ) ) {
			$product->set_name( sanitize_text_field( (string) $data['name'] ) );
		}
		if ( isset( $data['description'] ) ) {
			$product->set_description( wp_kses_post( (string) $data['description'] ) );
		}
		if ( isset( $data['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( (string) $data['short_description'] ) );
		}
		if ( isset( $data['regular_price'] ) ) {
			$product->set_regular_price( wc_format_decimal( $data['regular_price'] ) );
		}
		if ( isset( $data['sale_price'] ) ) {
			$product->set_sale_price( '' === (string) $data['sale_price'] ? '' : wc_format_decimal( $data['sale_price'] ) );
		}
		if ( isset( $data['sku'] ) ) {
			$product->set_sku( sanitize_text_field( (string) $data['sku'] ) );
		}
		if ( isset( $data['stock_status'] ) ) {
			$product->set_stock_status( sanitize_key( (string) $data['stock_status'] ) );
		}
		if ( array_key_exists( 'stock_quantity', $data ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $data['stock_quantity'] );
		}

		$product->save();

		if ( ! empty( $data['seo'] ) && is_array( $data['seo'] ) ) {
			ASC_SEO_Manager::update_post_seo( $product->get_id(), $data['seo'], $actor, $reason );
		}

		ASC_Snapshot::record_after_state( $snapshot_id, ASC_Snapshot::capture_post_state( $product->get_id() ) );

		return array(
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'stock_status'  => $product->get_stock_status(),
			'snapshot_id'   => $snapshot_id,
		);
	}
}
