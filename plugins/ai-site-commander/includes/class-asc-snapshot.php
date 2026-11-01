<?php
/**
 * Snapshot & Rollback manager for AI Site Commander.
 *
 * Captures complete pre-change state before any AI or automated write operation
 * and supports one-click restoration.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Snapshot {

	/**
	 * Record a snapshot row prior to applying a mutation.
	 *
	 * @param string $action_type  Action identifier.
	 * @param string $target_type  post|seo|option|redirect|snippet|theme_file|product|attachment.
	 * @param string $target_id    Target ID or path.
	 * @param string $target_label Human-readable label.
	 * @param array  $before_state State before modification.
	 * @param string $actor        Actor name.
	 * @param string $reason       Why this change was initiated.
	 * @return int Snapshot ID.
	 */
	public static function create( $action_type, $target_type, $target_id, $target_label, array $before_state, $actor = 'ai-agent', $reason = '' ) {
		global $wpdb;

		$wpdb->insert(
			ASC_DB::table( 'snapshots' ),
			array(
				'action_type'  => sanitize_key( $action_type ),
				'target_type'  => sanitize_key( $target_type ),
				'target_id'    => sanitize_text_field( (string) $target_id ),
				'target_label' => sanitize_text_field( mb_substr( (string) $target_label, 0, 250 ) ),
				'before_state' => wp_json_encode( $before_state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'actor'        => sanitize_text_field( (string) $actor ),
				'reason'       => sanitize_textarea_field( (string) $reason ),
				'rolled_back'  => 0,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Attach post-mutation state to an existing snapshot row.
	 *
	 * @param int   $snapshot_id Snapshot ID.
	 * @param array $after_state State after modification.
	 */
	public static function record_after_state( $snapshot_id, array $after_state ) {
		global $wpdb;
		if ( ! $snapshot_id ) {
			return;
		}
		$wpdb->update(
			ASC_DB::table( 'snapshots' ),
			array(
				'after_state' => wp_json_encode( $after_state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			),
			array( 'id' => absint( $snapshot_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Capture full state of a WordPress post (including core fields, taxonomies, and tracked meta).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function capture_post_state( $post_id ) {
		$post = get_post( $post_id, ARRAY_A );
		if ( ! $post ) {
			return array( 'exists' => false, 'ID' => (int) $post_id );
		}

		$all_meta  = get_post_meta( $post_id );
		$clean_meta = array();
		if ( is_array( $all_meta ) ) {
			foreach ( $all_meta as $key => $values ) {
				if ( 0 === strpos( $key, '_edit_' ) ) {
					continue;
				}
				$clean_meta[ $key ] = array_map( 'maybe_unserialize', $values );
			}
		}

		$taxonomies = get_object_taxonomies( $post['post_type'] );
		$terms_map  = array();
		foreach ( $taxonomies as $tax ) {
			$term_ids = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $term_ids ) ) {
				$terms_map[ $tax ] = $term_ids;
			}
		}

		return array(
			'exists' => true,
			'post'   => array(
				'ID'            => (int) $post['ID'],
				'post_title'    => $post['post_title'],
				'post_content'  => $post['post_content'],
				'post_excerpt'  => $post['post_excerpt'],
				'post_name'     => $post['post_name'],
				'post_status'   => $post['post_status'],
				'post_type'     => $post['post_type'],
				'post_parent'   => (int) $post['post_parent'],
				'menu_order'    => (int) $post['menu_order'],
			),
			'meta'   => $clean_meta,
			'terms'  => $terms_map,
		);
	}

	/**
	 * Capture full state of a WordPress taxonomy term (description, core fields, and term meta).
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array
	 */
	public static function capture_term_state( $term_id, $taxonomy ) {
		$term = get_term( (int) $term_id, (string) $taxonomy, ARRAY_A );
		if ( ! $term || is_wp_error( $term ) ) {
			return array(
				'exists'   => false,
				'term_id'  => (int) $term_id,
				'taxonomy' => (string) $taxonomy,
			);
		}

		$all_meta   = get_term_meta( (int) $term_id );
		$clean_meta = array();
		if ( is_array( $all_meta ) ) {
			foreach ( $all_meta as $key => $values ) {
				$clean_meta[ $key ] = array_map( 'maybe_unserialize', $values );
			}
		}

		return array(
			'exists'   => true,
			'term_id'  => (int) $term['term_id'],
			'taxonomy' => (string) $term['taxonomy'],
			'term'     => array(
				'name'        => $term['name'],
				'slug'        => $term['slug'],
				'description' => $term['description'],
				'parent'      => (int) $term['parent'],
			),
			'meta'     => $clean_meta,
		);
	}

	/**
	 * List snapshots with pagination.
	 *
	 * @param int $limit  Number of items.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function list_snapshots( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table  = ASC_DB::table( 'snapshots' );
		$limit  = min( 200, max( 1, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, action_type, target_type, target_id, target_label, actor, reason, rolled_back, rolled_back_at, created_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single snapshot including full before/after JSON payloads.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return array|null
	 */
	public static function get_snapshot( $snapshot_id ) {
		global $wpdb;
		$table = ASC_DB::table( 'snapshots' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $snapshot_id ) ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['before_state'] = json_decode( (string) $row['before_state'], true );
		$row['after_state']  = ! empty( $row['after_state'] ) ? json_decode( (string) $row['after_state'], true ) : null;
		return $row;
	}

	/**
	 * Roll back a snapshot to restore the exact state prior to the change.
	 *
	 * @param int    $snapshot_id Snapshot ID.
	 * @param string $actor       Who triggered rollback.
	 * @return array|WP_Error
	 */
	public static function rollback( $snapshot_id, $actor = 'admin' ) {
		global $wpdb;

		$snapshot = self::get_snapshot( $snapshot_id );
		if ( ! $snapshot ) {
			return new WP_Error( 'asc_snapshot_not_found', 'نسخه پشتیبان مورد نظر یافت نشد.', array( 'status' => 404 ) );
		}

		if ( ! empty( $snapshot['rolled_back'] ) ) {
			return new WP_Error( 'asc_already_rolled_back', 'این نسخه پشتیبان قبلاً بازگردانی شده است.', array( 'status' => 409 ) );
		}

		$before      = is_array( $snapshot['before_state'] ) ? $snapshot['before_state'] : array();
		$target_type = $snapshot['target_type'];
		$target_id   = $snapshot['target_id'];

		switch ( $target_type ) {
			case 'post':
			case 'seo':
			case 'product':
			case 'attachment':
				$post_id = absint( $target_id );
				if ( empty( $before['exists'] ) ) {
					if ( $post_id && get_post( $post_id ) ) {
						wp_trash_post( $post_id );
					}
				} else {
					if ( ! empty( $before['post'] ) && is_array( $before['post'] ) ) {
						$update_args = $before['post'];
						wp_update_post( wp_slash( $update_args ) );
					}
					if ( isset( $before['meta'] ) && is_array( $before['meta'] ) ) {
						foreach ( $before['meta'] as $meta_key => $values ) {
							delete_post_meta( $post_id, $meta_key );
							if ( is_array( $values ) ) {
								foreach ( $values as $val ) {
									add_post_meta( $post_id, $meta_key, wp_slash( $val ) );
								}
							}
						}
					}
					if ( isset( $before['terms'] ) && is_array( $before['terms'] ) ) {
						foreach ( $before['terms'] as $taxonomy => $term_ids ) {
							if ( taxonomy_exists( $taxonomy ) ) {
								wp_set_object_terms( $post_id, array_map( 'intval', (array) $term_ids ), $taxonomy );
							}
						}
					}
					if ( function_exists( 'wc_delete_product_transients' ) && 'product' === $target_type ) {
						wc_delete_product_transients( $post_id );
					}
				}
				break;

			case 'option':
				$option_name = (string) $target_id;
				if ( empty( $before['exists'] ) ) {
					delete_option( $option_name );
				} else {
					update_option( $option_name, $before['value'] );
				}
				break;

			case 'term':
				$tid = absint( $target_id );
				$tax = ! empty( $before['taxonomy'] ) ? (string) $before['taxonomy'] : 'product_cat';
				if ( ! empty( $before['exists'] ) && $tid > 0 && taxonomy_exists( $tax ) ) {
					if ( ! empty( $before['term'] ) && is_array( $before['term'] ) ) {
						wp_update_term( $tid, $tax, wp_slash( $before['term'] ) );
					}
					if ( isset( $before['meta'] ) && is_array( $before['meta'] ) ) {
						$existing_meta = get_term_meta( $tid );
						if ( is_array( $existing_meta ) ) {
							foreach ( array_keys( $existing_meta ) as $mkey ) {
								delete_term_meta( $tid, $mkey );
							}
						}
						foreach ( $before['meta'] as $mkey => $values ) {
							if ( is_array( $values ) ) {
								foreach ( $values as $val ) {
									add_term_meta( $tid, $mkey, wp_slash( $val ) );
								}
							}
						}
					}
				}
				break;

			case 'redirect':
				$redirect_table = ASC_DB::table( 'redirects' );
				$rid            = absint( $target_id );
				if ( empty( $before['exists'] ) ) {
					$wpdb->delete( $redirect_table, array( 'id' => $rid ), array( '%d' ) );
				} elseif ( ! empty( $before['row'] ) && is_array( $before['row'] ) ) {
					$row = $before['row'];
					unset( $row['id'] );
					$wpdb->update( $redirect_table, $row, array( 'id' => $rid ) );
				}
				break;

			case 'snippet':
				$snippet_table = ASC_DB::table( 'snippets' );
				$sid           = absint( $target_id );
				if ( empty( $before['exists'] ) ) {
					$wpdb->delete( $snippet_table, array( 'id' => $sid ), array( '%d' ) );
				} elseif ( ! empty( $before['row'] ) && is_array( $before['row'] ) ) {
					$row = $before['row'];
					unset( $row['id'] );
					$wpdb->update( $snippet_table, $row, array( 'id' => $sid ) );
				}
				break;

			case 'theme_file':
				$restored = ASC_Code_Files::restore_theme_file_snapshot( $target_id, $before );
				if ( is_wp_error( $restored ) ) {
					return $restored;
				}
				break;

			case 'autolinks_seed':
				$after_raw = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT after_state FROM ' . ASC_DB::table( 'snapshots' ) . ' WHERE id = %d',
						absint( $snapshot_id )
					)
				);
				$after     = json_decode( (string) $after_raw, true );
				$table     = isset( $before['table'] ) ? preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $before['table'] ) : $wpdb->prefix . 'daextam_autolink';
				if ( ! empty( $after['inserted_ids'] ) && is_array( $after['inserted_ids'] ) && '' !== $table ) {
					foreach ( $after['inserted_ids'] as $al_id ) {
						$wpdb->delete( $table, array( 'autolink_id' => absint( $al_id ) ), array( '%d' ) );
					}
				}
				ASC_Engine::purge_site_caches();
				break;

			default:
				return new WP_Error( 'asc_unsupported_rollback', 'نوع هدف برای بازگردانی پشتیبانی نمی‌شود.', array( 'status' => 400 ) );
		}

		$wpdb->update(
			ASC_DB::table( 'snapshots' ),
			array(
				'rolled_back'    => 1,
				'rolled_back_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $snapshot_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		ASC_DB::log_audit(
			$actor,
			'rollback_snapshot',
			$snapshot['target_label'] ? $snapshot['target_label'] : $target_id,
			'rolled_back',
			sprintf( 'نسخه پشتیبان #%d با موفقیت بازگردانی شد.', $snapshot_id ),
			(int) $snapshot_id
		);

		return array(
			'snapshot_id' => (int) $snapshot_id,
			'target_type' => $target_type,
			'target_id'   => $target_id,
			'restored'    => true,
			'message'     => sprintf( 'تغییرات مربوط به «%s» با موفقیت به حالت اولیه بازگردانده شد.', $snapshot['target_label'] ),
		);
	}
}
