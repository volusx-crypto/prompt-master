<?php
/**
 * WP-CLI commands.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_CLI
 */
class PHM_CLI {

	/**
	 * Register the WP-CLI command (only called in a CLI context).
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		WP_CLI::add_command( 'harmony', __CLASS__ );
	}

	/**
	 * Report the site profile, plugin families and conflicts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp harmony scan
	 *
	 * @return void
	 */
	public function scan() {
		PHM_Profile::flush();
		PHM_Detector::flush();

		$plan = PHM_Planner::build();

		WP_CLI::log( 'پروفایل: ' . $plan['profile']['stack'] );
		WP_CLI::log( 'افزونه‌های فعال: ' . $plan['profile']['active_plugins'] );

		$rows = array();
		foreach ( $plan['state'] as $info ) {
			if ( ! $info['active'] ) {
				continue;
			}
			$rows[] = array(
				'plugin'   => $info['name'],
				'family'   => $info['family_label'],
				'writable' => $info['advisory'] ? 'no' : 'yes',
				'file'     => $info['plugin_file'] ? $info['plugin_file'] : '-',
			);
		}

		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'plugin', 'family', 'writable', 'file' ) );
		}

		if ( empty( $plan['conflicts'] ) ) {
			WP_CLI::success( 'تضادی پیدا نشد.' );
		} else {
			foreach ( $plan['conflicts'] as $conflict ) {
				WP_CLI::warning( $conflict['title'] . ' — ' . implode( '، ', $conflict['plugins'] ) );
			}
		}
	}

	/**
	 * Show the proposed changes without writing anything.
	 *
	 * ## OPTIONS
	 *
	 * [--family=<family>]
	 * : Limit to one family.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function preview( $args, $assoc_args ) {
		unset( $args );

		$plan  = PHM_Planner::build();
		$rows  = array();
		$family = isset( $assoc_args['family'] ) ? $assoc_args['family'] : '';

		foreach ( $plan['changes'] as $change ) {
			if ( 'pending' !== $change['status'] ) {
				continue;
			}
			if ( '' !== $family && $change['family'] !== $family ) {
				continue;
			}
			$rows[] = array(
				'plugin' => $change['recipe_name'],
				'setting' => $change['label'],
				'option' => $change['option'] . ( $change['path'] ? '.' . implode( '.', $change['path'] ) : '' ),
				'current' => is_scalar( $change['old'] ) ? (string) $change['old'] : wp_json_encode( $change['old'] ),
				'proposed' => is_scalar( $change['new'] ) ? (string) $change['new'] : wp_json_encode( $change['new'] ),
				'risk'    => $change['risk'],
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( 'هیچ پیشنهادی نیست؛ تنظیمات از قبل هماهنگ است.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'plugin', 'setting', 'option', 'current', 'proposed', 'risk' ) );
		WP_CLI::log( sprintf( '%d پیشنهاد. برای نوشتن: wp harmony apply', count( $rows ) ) );
	}

	/**
	 * Apply proposed changes.
	 *
	 * ## OPTIONS
	 *
	 * [--family=<family>]
	 * : Limit to one family.
	 *
	 * [--dry-run]
	 * : Only print what would change.
	 *
	 * [--auto]
	 * : Use the automatic risk rules from the settings screen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp harmony apply --family=seo
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function apply( $args, $assoc_args ) {
		unset( $args );

		$plan    = PHM_Planner::build();
		$family  = isset( $assoc_args['family'] ) ? $assoc_args['family'] : '';
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$auto    = ! empty( $assoc_args['auto'] );

		$changes = array();
		foreach ( $plan['changes'] as $change ) {
			if ( 'pending' !== $change['status'] ) {
				continue;
			}
			if ( '' !== $family && $change['family'] !== $family ) {
				continue;
			}
			$changes[] = $change;
		}

		if ( empty( $changes ) ) {
			WP_CLI::success( 'چیزی برای اعمال نیست.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'حالت آزمایشی: %d تغییر نوشته نشد.', count( $changes ) ) );
			return;
		}

		$result = PHM_Applier::apply(
			$changes,
			array(
				'trigger' => 'cli',
				'auto'    => $auto,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'%d تنظیم نوشته شد (رد‌شده توسط افزونه: %d). دسته: %s',
				$result['counts']['applied'],
				$result['counts']['rejected'],
				$result['batch']
			)
		);
	}

	/**
	 * Restore a batch.
	 *
	 * ## OPTIONS
	 *
	 * <batch>
	 * : Batch id, e.g. phm_20260101120000_1234.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function rollback( $args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'شناسهٔ دسته را وارد کنید.' );
		}

		$done = PHM_Applier::rollback( $args[0] );

		if ( is_wp_error( $done ) ) {
			WP_CLI::error( $done->get_error_message() );
		}

		WP_CLI::success( sprintf( '%d گزینه بازگردانده شد.', count( $done ) ) );
	}

	/**
	 * Show the change log.
	 *
	 * @return void
	 */
	public function log() {
		$log = PHM_Applier::get_log();

		if ( empty( $log ) ) {
			WP_CLI::log( 'تاریخچه خالی است.' );
			return;
		}

		$rows = array();
		foreach ( $log as $entry ) {
			$rows[] = array(
				'batch'   => $entry['id'],
				'time'    => $entry['time'],
				'trigger' => $entry['trigger'],
				'changes' => count( $entry['items'] ),
				'undone'  => ! empty( $entry['rolled_back'] ) ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'batch', 'time', 'trigger', 'changes', 'undone' ) );
	}
}
