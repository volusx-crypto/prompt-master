<?php
/**
 * Context aware value callbacks used by recipes.
 *
 * @package Plugin_Harmony
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PHM_Values
 */
class PHM_Values {

	/**
	 * Comma separated list of WooCommerce pages that must never be cached.
	 *
	 * Returns null when WooCommerce has no pages, so the rule is skipped.
	 *
	 * @param array $context Planner context.
	 * @return string|null
	 */
	public static function wc_cache_exclude_pages( $context ) {
		if ( empty( $context['profile']['woocommerce'] ) || empty( $context['profile']['wc_pages'] ) ) {
			return null;
		}
		return implode( ',', array_map( 'intval', $context['profile']['wc_pages'] ) );
	}

	/**
	 * Loopback-safe site URL without trailing slash, used by cache plugins.
	 *
	 * @param array $context Planner context.
	 * @return string|null
	 */
	public static function home_domain( $context ) {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return $host ? $host : null;
	}

	/**
	 * Recommended backup retention: sites with more content keep more copies.
	 *
	 * @param array $context Planner context.
	 * @return int
	 */
	public static function backup_retain( $context ) {
		$items = 0;
		if ( isset( $context['profile']['post_count'] ) ) {
			$items += (int) $context['profile']['post_count'];
		}
		if ( isset( $context['profile']['page_count'] ) ) {
			$items += (int) $context['profile']['page_count'];
		}
		if ( isset( $context['profile']['woocommerce'] ) && $context['profile']['woocommerce'] ) {
			return 10;
		}
		return ( $items > 100 ) ? 8 : 4;
	}

	/**
	 * Daily database backup frequency for stores, weekly otherwise.
	 *
	 * @param array $context Planner context.
	 * @return string
	 */
	public static function db_backup_interval( $context ) {
		$is_store = ! empty( $context['profile']['woocommerce'] );
		return $is_store ? 'daily' : 'weekly';
	}
}
