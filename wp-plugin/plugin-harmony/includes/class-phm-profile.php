<?php
/**
 * Site profiling: what kind of site are we configuring?
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Profile
 */
class PHM_Profile {

	/**
	 * Transient key.
	 */
	const CACHE_KEY = 'phm_site_profile';

	/**
	 * In-request cache.
	 *
	 * @var array|null
	 */
	private static $profile = null;

	/**
	 * Build (or read) the site profile.
	 *
	 * @param bool $refresh Force a rebuild.
	 * @return array
	 */
	public static function get( $refresh = false ) {
		if ( ! $refresh && is_array( self::$profile ) ) {
			return self::$profile;
		}

		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				self::$profile = $cached;
				return self::$profile;
			}
		}

		$profile = self::build();
		set_transient( self::CACHE_KEY, $profile, 5 * MINUTE_IN_SECONDS );
		self::$profile = $profile;

		return $profile;
	}

	/**
	 * Drop the cached profile.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$profile = null;
		delete_transient( self::CACHE_KEY );
		PHM_Planner::flush();
	}

	/**
	 * Collect the profile data.
	 *
	 * @return array
	 */
	private static function build() {
		$server   = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$post     = wp_count_posts( 'post' );
		$page     = wp_count_posts( 'page' );
		$home     = home_url( '/' );

		$profile = array(
			'wp_version'        => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'locale'            => get_locale(),
			'is_rtl'            => is_rtl(),
			'multisite'         => is_multisite(),
			'https'             => ( 0 === strpos( $home, 'https://' ) ),
			'pretty_permalinks' => ( '' !== (string) get_option( 'permalink_structure' ) ),
			'permalink'         => (string) get_option( 'permalink_structure' ),
			'object_cache'      => wp_using_ext_object_cache(),
			'debug_mode'        => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'server'            => $server,
			'nginx'             => ( false !== stripos( $server, 'nginx' ) ),
			'litespeed'         => ( false !== stripos( $server, 'litespeed' ) || defined( 'LSCWP_V' ) ),
			'blog_public'       => (string) get_option( 'blog_public' ),
			'users_can_register' => (string) get_option( 'users_can_register' ),
			'comment_status'    => (string) get_option( 'default_comment_status' ),
			'post_count'        => isset( $post->publish ) ? (int) $post->publish : 0,
			'page_count'        => isset( $page->publish ) ? (int) $page->publish : 0,
			'woocommerce'       => ( class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' ) ),
			'wc_pages'          => array(),
			'theme'             => wp_get_theme()->get( 'Name' ),
			'active_plugins'    => 0,
			'families'          => array(),
		);

		if ( $profile['woocommerce'] ) {
			$profile['wc_pages'] = self::woocommerce_pages();
		}

		$active = PHM_Detector::active_plugin_files();
		$profile['active_plugins'] = count( $active );

		$detected = PHM_Detector::detect();
		foreach ( $detected as $id => $info ) {
			if ( ! $info['active'] ) {
				continue;
			}
			$family = $info['family'];
			if ( ! isset( $profile['families'][ $family ] ) ) {
				$profile['families'][ $family ] = array();
			}
			$profile['families'][ $family ][] = $id;
		}

		$profile['has_comments']  = ( 'open' === $profile['comment_status'] );
		$profile['has_blog']      = ( $profile['post_count'] > 0 );
		$profile['stack']         = self::stack_label( $profile );

		return $profile;
	}

	/**
	 * WooCommerce key page IDs.
	 *
	 * @return array
	 */
	private static function woocommerce_pages() {
		$ids = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount', 'shop' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * A short human description of the hosting stack.
	 *
	 * @param array $profile Profile data.
	 * @return string
	 */
	private static function stack_label( $profile ) {
		$bits = array();
		$bits[] = 'WordPress ' . $profile['wp_version'];
		$bits[] = 'PHP ' . $profile['php_version'];

		if ( $profile['litespeed'] ) {
			$bits[] = 'LiteSpeed';
		} elseif ( $profile['nginx'] ) {
			$bits[] = 'Nginx';
		} elseif ( ! empty( $profile['server'] ) ) {
			$bits[] = $profile['server'];
		}

		if ( $profile['object_cache'] ) {
			$bits[] = 'کش شیء فعال';
		}
		if ( $profile['woocommerce'] ) {
			$bits[] = 'ووکامرس';
		}

		return implode( ' · ', $bits );
	}
}
