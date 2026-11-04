<?php
/**
 * Plugin detection and capability ownership.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Detector
 */
class PHM_Detector {

	/**
	 * Cached detection result.
	 *
	 * @var array|null
	 */
	private static $state = null;

	/**
	 * Active plugin basenames (including network activated ones).
	 *
	 * @return array
	 */
	public static function active_plugin_files() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			if ( ! empty( $network ) ) {
				$active = array_merge( $active, array_keys( $network ) );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $active ) ) ) );
	}

	/**
	 * Human name of a plugin basename, without loading the plugin file twice.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string
	 */
	public static function plugin_name( $plugin_file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $path ) ) {
			return $plugin_file;
		}
		$data = get_plugin_data( $path, false, false );
		return ! empty( $data['Name'] ) ? $data['Name'] : $plugin_file;
	}

	/**
	 * Detect every known plugin.
	 *
	 * @param bool $refresh Recompute.
	 * @return array
	 */
	public static function detect( $refresh = false ) {
		if ( ! $refresh && is_array( self::$state ) ) {
			return self::$state;
		}

		$active  = self::active_plugin_files();
		$recipes = PHM_Registry::all();
		$state   = array();

		foreach ( $recipes as $id => $recipe ) {
			$matched_file = '';
			$matches      = 0;

			foreach ( $recipe['plugins'] as $plugin_file ) {
				if ( in_array( $plugin_file, $active, true ) ) {
					$matched_file = $plugin_file;
					$matches++;
				}
			}

			// Fallback detection for renamed folders. Only a loaded class or a
			// defined constant proves the plugin is really active — a leftover
			// option row would also exist for deactivated plugins.
			$detected_by = '';
			if ( ! $matched_file && ! empty( $recipe['detect'] ) ) {
				$detect = $recipe['detect'];

				if ( ! empty( $detect['class'] ) && class_exists( $detect['class'] ) ) {
					$detected_by = 'class';
				} elseif ( ! empty( $detect['constant'] ) && defined( $detect['constant'] ) ) {
					$detected_by = 'constant';
				}

				if ( $detected_by && ! empty( $recipe['plugins'] ) ) {
					$matched_file = reset( $recipe['plugins'] );
				}
			}

			$is_core = ( 'core' === $recipe['family'] && empty( $recipe['plugins'] ) );

			$state[ $id ] = array(
				'id'           => $id,
				'name'         => $recipe['name'],
				'family'       => $recipe['family'],
				'family_label' => self::family_label( $recipe['family'] ),
				'plugin_file'  => $matched_file,
				'active'       => ( $is_core || (bool) $matched_file ),
				'detected_by'  => $detected_by,
				'advisory'     => ! empty( $recipe['advisory'] ),
				'priority'     => (int) $recipe['priority'],
				'files'        => $matches,
			);
		}

		self::$state = $state;
		return self::$state;
	}

	/**
	 * Localized family label.
	 *
	 * @param string $family Family slug.
	 * @return string
	 */
	public static function family_label( $family ) {
		$labels = PHM_Registry::family_labels();
		return isset( $labels[ $family ] ) ? $labels[ $family ] : $family;
	}

	/**
	 * Localized capability label.
	 *
	 * @param string $capability Capability slug.
	 * @return string
	 */
	public static function capability_label( $capability ) {
		$labels = PHM_Registry::capability_labels();
		return isset( $labels[ $capability ] ) ? $labels[ $capability ] : $capability;
	}

	/**
	 * Which active plugin owns each capability.
	 *
	 * Score order: capability strength, then recipe priority, then id.
	 *
	 * @param array|null $state Detection state.
	 * @return array capability => array( owner, owners, scores, critical )
	 */
	public static function capabilities( $state = null ) {
		if ( null === $state ) {
			$state = self::detect();
		}

		$recipes    = PHM_Registry::all();
		$critical   = PHM_Registry::critical_capabilities();
		$candidates = array();

		foreach ( $state as $id => $info ) {
			if ( ! $info['active'] ) {
				continue;
			}
			$recipe = isset( $recipes[ $id ] ) ? $recipes[ $id ] : null;
			if ( ! $recipe || empty( $recipe['capabilities'] ) ) {
				continue;
			}
			foreach ( $recipe['capabilities'] as $capability => $score ) {
				if ( ! isset( $candidates[ $capability ] ) ) {
					$candidates[ $capability ] = array();
				}
				$candidates[ $capability ][ $id ] = array(
					'score'    => (int) $score,
					'priority' => (int) $recipe['priority'],
				);
			}
		}

		$caps = array();

		foreach ( $candidates as $capability => $owners ) {
			uasort( $owners, array( __CLASS__, 'compare_owners' ) );
			$ids = array_keys( $owners );

			/**
			 * Filter which plugin is treated as the primary owner of a capability.
			 *
			 * @param string $owner      Recipe id.
			 * @param string $capability Capability slug.
			 * @param array  $ids        Ordered candidate recipe ids.
			 */
			$owner = apply_filters( 'phm_capability_owner', reset( $ids ), $capability, $ids );

			$caps[ $capability ] = array(
				'owner'    => $owner,
				'owners'   => $ids,
				'scores'   => $owners,
				'critical' => in_array( $capability, $critical, true ),
			);
		}

		return $caps;
	}

	/**
	 * Sort capability candidates.
	 *
	 * @param array $a First candidate.
	 * @param array $b Second candidate.
	 * @return int
	 */
	private static function compare_owners( $a, $b ) {
		if ( $a['score'] === $b['score'] ) {
			if ( $a['priority'] === $b['priority'] ) {
				return 0;
			}
			return ( $a['priority'] > $b['priority'] ) ? -1 : 1;
		}
		return ( $a['score'] > $b['score'] ) ? -1 : 1;
	}

	/**
	 * Active recipes grouped by family.
	 *
	 * @param array|null $state Detection state.
	 * @return array family => recipe ids
	 */
	public static function active_by_family( $state = null ) {
		if ( null === $state ) {
			$state = self::detect();
		}

		$out = array();
		foreach ( $state as $id => $info ) {
			if ( ! $info['active'] ) {
				continue;
			}
			if ( ! isset( $out[ $info['family'] ] ) ) {
				$out[ $info['family'] ] = array();
			}
			$out[ $info['family'] ][] = $id;
		}

		return $out;
	}

	/**
	 * Reset caches.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$state = null;
	}
}
