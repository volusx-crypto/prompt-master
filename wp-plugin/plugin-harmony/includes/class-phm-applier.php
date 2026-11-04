<?php
/**
 * Applier: writes settings, keeps snapshots, and supports rollback.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Applier
 */
class PHM_Applier {

	/**
	 * Options that must never be written by this plugin.
	 *
	 * @return array
	 */
	public static function protected_options() {
		$protected = array(
			'active_plugins',
			'active_sitewide_plugins',
			'siteurl',
			'home',
			'users_can_register',
			'default_role',
			'admin_email',
			'new_admin_email',
			'db_version',
			'wp_user_roles',
			'cron',
			'rewrite_rules',
			'permalink_structure',
			'category_base',
			'tag_base',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'template',
			'stylesheet',
			'WPLANG',
			'secret',
			'auth_key',
			'sticky_posts',
			'upload_path',
			'upload_url_path',
			PHM_OPTION,
			PHM_LOG_OPTION,
			PHM_BACKUP_OPTION,
			PHM_PENDING_OPTION,
		);

		/**
		 * Filter the list of options Plugin Harmony refuses to touch.
		 *
		 * @param array $protected Option names.
		 */
		return (array) apply_filters( 'phm_protected_options', $protected );
	}

	/**
	 * Apply a list of changes.
	 *
	 * @param array $changes Change list from the planner.
	 * @param array $args {
	 *     Optional. @type string $trigger manual|activation|update|cron|cli.
	 *               @type bool   $auto    Whether this run was automatic.
	 * }
	 * @return array|WP_Error Report array on success.
	 */
	public static function apply( $changes, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'trigger' => 'manual',
				'auto'    => false,
			)
		);

		$mode = PHM_Settings::mode();

		if ( 'off' === $mode ) {
			return new WP_Error( 'phm_mode_off', 'حالت هماهنگ‌ساز روی «غیرفعال» تنظیم شده است؛ هیچ تنظیمی نوشته نشد.' );
		}

		if ( empty( $changes ) || ! is_array( $changes ) ) {
			return new WP_Error( 'phm_nothing', 'هیچ تغییری برای اعمال وجود ندارد.' );
		}

		$batch_id = 'phm_' . gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
		$auto     = ! empty( $args['auto'] );

		$grouped = array();

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || empty( $change['option'] ) ) {
				continue;
			}
			if ( isset( $change['status'] ) && 'pending' !== $change['status'] ) {
				continue;
			}
			if ( in_array( $change['option'], self::protected_options(), true ) ) {
				continue;
			}
			$risk = isset( $change['risk'] ) ? $change['risk'] : 'medium';
			if ( 'high' === $risk ) {
				continue;
			}
			if ( $auto && ! PHM_Settings::allows_risk( $risk ) ) {
				continue;
			}
			$grouped[ $change['option'] ][] = $change;
		}

		if ( empty( $grouped ) ) {
			return new WP_Error( 'phm_filtered', 'هیچ‌کدام از تغییرات با حالت فعلی هماهنگ‌ساز قابل اعمال نبود.' );
		}

		$snapshots   = array();
		$applied     = array();
		$rejected    = 0;

		foreach ( $grouped as $option => $list ) {
			$before = get_option( $option, null );

			if ( null === $before ) {
				continue;
			}

			$after = $before;
			$items = array();

			foreach ( $list as $change ) {
				$found = true;
				$old   = PHM_Planner::read_path( $after, $change['path'], $found );

				if ( ! $found ) {
					continue;
				}

				$new = $change['new'];

				if ( PHM_Planner::values_equal( $old, $new ) ) {
					continue;
				}

				$after = self::set_path( $after, $change['path'], $new );

				$items[] = array(
					'recipe'  => isset( $change['recipe'] ) ? $change['recipe'] : '',
					'recipe_name' => isset( $change['recipe_name'] ) ? $change['recipe_name'] : '',
					'label'   => isset( $change['label'] ) ? $change['label'] : '',
					'option'  => $option,
					'path'    => $change['path'],
					'old'     => $old,
					'new'     => $new,
					'risk'    => isset( $change['risk'] ) ? $change['risk'] : 'medium',
					'status'  => 'applied',
				);
			}

			if ( empty( $items ) ) {
				continue;
			}

			$snapshots[ $option ] = $before;

			$updated = update_option( $option, $after );

			if ( ! $updated ) {
				foreach ( $items as $index => $item ) {
					$items[ $index ]['status'] = 'failed';
				}
				$applied = array_merge( $applied, $items );
				continue;
			}

			// Verify what the plugin actually stored — sanitize filters may
			// reject a value, in which case we report it instead of pretending.
			$verify = get_option( $option, null );

			foreach ( $items as $index => $item ) {
				$found  = true;
				$stored = PHM_Planner::read_path( $verify, $item['path'], $found );

				if ( ! $found || ! PHM_Planner::values_equal( $stored, $item['new'] ) ) {
					$items[ $index ]['status'] = 'rejected';
					$items[ $index ]['stored'] = $stored;
					$rejected++;
				}
			}

			$applied = array_merge( $applied, $items );
		}

		if ( empty( $applied ) ) {
			return new WP_Error( 'phm_noop', 'هیچ تغییری لازم نبود؛ تنظیمات از قبل درست بودند.' );
		}

		// Count first, then store a bounded number of items so the log option
		// can never grow without limit.
		$counts = array(
			'applied'  => 0,
			'rejected' => 0,
			'failed'   => 0,
		);

		foreach ( $applied as $item ) {
			if ( isset( $counts[ $item['status'] ] ) ) {
				$counts[ $item['status'] ]++;
			}
		}

		$max_items = (int) apply_filters( 'phm_max_log_items_per_batch', 300 );
		if ( $max_items > 0 && count( $applied ) > $max_items ) {
			$stored_items = array_slice( $applied, 0, $max_items );
		} else {
			$stored_items = $applied;
		}

		self::store_batch( $batch_id, $stored_items, $snapshots, $args, $mode );

		// The plan is stale now; a later build in this request must not reuse it.
		PHM_Planner::flush();

		/**
		 * Fires after a batch of settings was written.
		 *
		 * @param string $batch_id Batch id.
		 * @param array  $applied  Applied items.
		 * @param array  $args     Run arguments.
		 */
		do_action( 'phm_after_apply', $batch_id, $applied, $args );

		return array(
			'batch'    => $batch_id,
			'items'    => $applied,
			'counts'   => $counts,
			'snapshot' => $snapshots,
			'rejected' => $rejected,
		);
	}

	/**
	 * Write a value at a path, returning a new array (no references).
	 *
	 * @param mixed $data  Source data.
	 * @param array $path  Path segments.
	 * @param mixed $value New value.
	 * @return mixed
	 */
	public static function set_path( $data, $path, $value ) {
		$path = (array) $path;

		if ( empty( $path ) ) {
			return $value;
		}

		$segment = array_shift( $path );

		if ( ! is_array( $data ) || ! array_key_exists( $segment, $data ) ) {
			return $data;
		}

		$data[ $segment ] = self::set_path( $data[ $segment ], $path, $value );

		return $data;
	}

	/**
	 * Persist the batch log entry and its snapshots.
	 *
	 * @param string $batch_id  Batch id.
	 * @param array  $items     Result items.
	 * @param array  $snapshots option => previous value.
	 * @param array  $args      Run arguments.
	 * @param string $mode      Operating mode.
	 * @return void
	 */
	private static function store_batch( $batch_id, $items, $snapshots, $args, $mode ) {
		$entry = array(
			'id'        => $batch_id,
			'time'      => current_time( 'mysql' ),
			'timestamp' => time(),
			'trigger'   => isset( $args['trigger'] ) ? $args['trigger'] : 'manual',
			'mode'      => $mode,
			'user_id'   => get_current_user_id(),
			'items'     => $items,
			'rolled_back' => false,
		);

		$log = get_option( PHM_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 100 );
		update_option( PHM_LOG_OPTION, $log, false );

		if ( ! empty( $snapshots ) ) {
			$backups = get_option( PHM_BACKUP_OPTION, array() );
			if ( ! is_array( $backups ) ) {
				$backups = array();
			}

			$backups[ $batch_id ] = array(
				'time'    => time(),
				'options' => $snapshots,
			);

			$limit = (int) PHM_Settings::get( 'keep_backups', 20 );
			if ( $limit < 1 ) {
				$limit = 20;
			}

			// Keep the newest batches by timestamp.
			uasort(
				$backups,
				function ( $a, $b ) {
					return ( isset( $a['time'] ) ? $a['time'] : 0 ) < ( isset( $b['time'] ) ? $b['time'] : 0 ) ? 1 : -1;
				}
			);
			$backups = array_slice( $backups, 0, $limit, true );

			update_option( PHM_BACKUP_OPTION, $backups, false );
		}
	}

	/**
	 * Restore the option values of one batch.
	 *
	 * @param string $batch_id Batch id.
	 * @return array|WP_Error Restored option names.
	 */
	public static function rollback( $batch_id ) {
		$batch_id = sanitize_key( $batch_id );
		$backups  = get_option( PHM_BACKUP_OPTION, array() );

		if ( ! is_array( $backups ) || ! isset( $backups[ $batch_id ]['options'] ) ) {
			return new WP_Error( 'phm_no_backup', 'نسخهٔ پشتیبان برای این دسته پیدا نشد.' );
		}

		$restored = array();

		foreach ( $backups[ $batch_id ]['options'] as $option => $value ) {
			if ( in_array( $option, self::protected_options(), true ) ) {
				continue;
			}
			update_option( $option, $value );
			$restored[] = $option;
		}

		// Flag the log entry so the UI shows it was undone.
		$log = get_option( PHM_LOG_OPTION, array() );
		if ( is_array( $log ) ) {
			foreach ( $log as $index => $entry ) {
				if ( isset( $entry['id'] ) && $entry['id'] === $batch_id ) {
					$log[ $index ]['rolled_back'] = true;
					$log[ $index ]['rolled_back_at'] = current_time( 'mysql' );
				}
			}
			update_option( PHM_LOG_OPTION, $log, false );
		}

		unset( $backups[ $batch_id ] );
		update_option( PHM_BACKUP_OPTION, $backups, false );

		PHM_Planner::flush();

		return $restored;
	}

	/**
	 * Change log, newest first.
	 *
	 * @return array
	 */
	public static function get_log() {
		$log = get_option( PHM_LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Batch snapshots.
	 *
	 * @return array
	 */
	public static function get_backups() {
		$backups = get_option( PHM_BACKUP_OPTION, array() );
		return is_array( $backups ) ? $backups : array();
	}

	/**
	 * Filter a plan's changes down to the ones the user selected.
	 *
	 * @param array $changes Plan changes.
	 * @param array $keys    Selected keys (recipe:rule).
	 * @return array
	 */
	public static function filter_selected( $changes, $keys ) {
		$keys = array_map( 'strval', (array) $keys );
		$out  = array();

		foreach ( $changes as $change ) {
			if ( isset( $change['key'] ) && in_array( $change['key'], $keys, true ) ) {
				$out[] = $change;
			}
		}

		return $out;
	}
}
