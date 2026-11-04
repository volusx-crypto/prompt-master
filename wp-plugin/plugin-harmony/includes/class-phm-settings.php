<?php
/**
 * Settings storage for Plugin Harmony.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Settings
 */
class PHM_Settings {

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// off | suggest | safe | full
			'mode'               => 'suggest',
			'auto_on_activation' => 1,
			'auto_on_update'     => 0,
			'daily_scan'         => 1,
			'families'           => array(),
			'exclude_plugins'    => array(),
			'keep_backups'       => 20,
			'delete_data'        => 0,
			'custom_rules'       => array(),
		);
	}

	/**
	 * Full settings array merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( PHM_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::defaults() );

		if ( ! is_array( $settings['families'] ) ) {
			$settings['families'] = array();
		}
		if ( ! is_array( $settings['exclude_plugins'] ) ) {
			$settings['exclude_plugins'] = array();
		}
		if ( ! is_array( $settings['custom_rules'] ) ) {
			$settings['custom_rules'] = array();
		}

		return $settings;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::all();
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}
		return $default;
	}

	/**
	 * Persist a partial settings array.
	 *
	 * @param array $values Values to merge.
	 * @return array Stored settings.
	 */
	public static function update( $values ) {
		$settings = self::all();
		$values   = is_array( $values ) ? $values : array();
		$settings = array_merge( $settings, $values );
		update_option( PHM_OPTION, $settings );
		return $settings;
	}

	/**
	 * Current operating mode.
	 *
	 * @return string off|suggest|safe|full
	 */
	public static function mode() {
		$mode = self::get( 'mode', 'suggest' );
		$valid = array( 'off', 'suggest', 'safe', 'full' );
		return in_array( $mode, $valid, true ) ? $mode : 'suggest';
	}

	/**
	 * Whether the plugin is allowed to write settings at all.
	 *
	 * @return bool
	 */
	public static function can_write() {
		return in_array( self::mode(), array( 'safe', 'full' ), true );
	}

	/**
	 * Whether a given risk level may be applied automatically.
	 *
	 * High risk changes are never applied automatically — they are only ever
	 * reported as manual advice.
	 *
	 * @param string $risk low|medium|high.
	 * @return bool
	 */
	public static function allows_risk( $risk ) {
		$mode = self::mode();

		if ( 'off' === $mode || 'suggest' === $mode ) {
			return false;
		}

		if ( 'high' === $risk ) {
			return false;
		}

		if ( 'safe' === $mode ) {
			return 'low' === $risk;
		}

		return in_array( $risk, array( 'low', 'medium' ), true );
	}

	/**
	 * Whether writes for a family are enabled.
	 *
	 * @param string $family Family slug.
	 * @return bool
	 */
	public static function is_family_enabled( $family ) {
		$families = self::get( 'families', array() );

		// An empty list means "all families" (the default).
		if ( empty( $families ) || ! is_array( $families ) ) {
			return true;
		}

		// An explicit "none" marker from the settings screen.
		if ( in_array( '__none__', $families, true ) ) {
			return false;
		}

		return in_array( $family, $families, true );
	}

	/**
	 * Whether a plugin file is excluded by the user.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return bool
	 */
	public static function is_plugin_excluded( $plugin_file ) {
		if ( ! $plugin_file ) {
			return false;
		}
		$excluded = self::get( 'exclude_plugins', array() );
		if ( ! is_array( $excluded ) ) {
			return false;
		}
		foreach ( $excluded as $entry ) {
			if ( $entry && ( $entry === $plugin_file || false !== strpos( $plugin_file, $entry ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a textarea of plugin file names into an array.
	 *
	 * @param string $text Raw textarea value.
	 * @return array
	 */
	public static function parse_lines( $text ) {
		$lines = preg_split( '/[\r\n,]+/', (string) $text );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
