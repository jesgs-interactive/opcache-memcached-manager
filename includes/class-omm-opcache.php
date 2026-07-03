<?php
/**
 * Thin wrapper around PHP's OPcache functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OMM_OPcache {

	/**
	 * Whether the opcache extension is loaded and its functions exist.
	 */
	public static function is_available() {
		return function_exists( 'opcache_get_status' ) && function_exists( 'opcache_get_configuration' );
	}

	/**
	 * Whether OPcache is enabled (vs. just installed but disabled).
	 */
	public static function is_enabled() {
		if ( ! self::is_available() ) {
			return false;
		}
		$status = @opcache_get_status( false );
		return is_array( $status ) && ! empty( $status['opcache_enabled'] );
	}

	/**
	 * Get a normalized status array for display / CLI output.
	 *
	 * @return array|WP_Error
	 */
	public static function get_status() {
		if ( ! self::is_available() ) {
			return new WP_Error( 'omm_opcache_unavailable', __( 'The OPcache extension is not installed on this server.', 'opcache-memcached-manager' ) );
		}

		if ( ! self::is_enabled() ) {
			return new WP_Error( 'omm_opcache_disabled', __( 'OPcache is installed but currently disabled (opcache.enable is off).', 'opcache-memcached-manager' ) );
		}

		$status = opcache_get_status( false );
		$config = opcache_get_configuration();

		$mem   = isset( $status['memory_usage'] ) ? $status['memory_usage'] : array();
		$stats = isset( $status['opcache_statistics'] ) ? $status['opcache_statistics'] : array();

		$used  = isset( $mem['used_memory'] ) ? (int) $mem['used_memory'] : 0;
		$free  = isset( $mem['free_memory'] ) ? (int) $mem['free_memory'] : 0;
		$waste = isset( $mem['wasted_memory'] ) ? (int) $mem['wasted_memory'] : 0;
		$total = $used + $free + $waste;

		$hits   = isset( $stats['hits'] ) ? (int) $stats['hits'] : 0;
		$misses = isset( $stats['misses'] ) ? (int) $stats['misses'] : 0;
		$lookups = $hits + $misses;

		return array(
			'enabled'              => true,
			'cache_full'           => ! empty( $status['cache_full'] ),
			'restart_pending'      => ! empty( $status['restart_pending'] ),
			'memory_used_bytes'    => $used,
			'memory_free_bytes'    => $free,
			'memory_wasted_bytes'  => $waste,
			'memory_total_bytes'   => $total,
			'memory_used_pct'      => $total > 0 ? round( ( $used / $total ) * 100, 2 ) : 0,
			'num_cached_scripts'   => isset( $stats['num_cached_scripts'] ) ? (int) $stats['num_cached_scripts'] : 0,
			'num_cached_keys'      => isset( $stats['num_cached_keys'] ) ? (int) $stats['num_cached_keys'] : 0,
			'max_cached_keys'      => isset( $stats['max_cached_keys'] ) ? (int) $stats['max_cached_keys'] : 0,
			'hits'                 => $hits,
			'misses'               => $misses,
			'hit_rate_pct'         => $lookups > 0 ? round( ( $hits / $lookups ) * 100, 2 ) : 0,
			'start_time'           => isset( $stats['start_time'] ) ? (int) $stats['start_time'] : 0,
			'last_restart_time'    => isset( $stats['last_restart_time'] ) ? (int) $stats['last_restart_time'] : 0,
			'version'              => isset( $config['version']['version'] ) ? $config['version']['version'] : '',
			'file_cache_only'      => isset( $config['directives']['opcache.file_cache_only'] ) ? (bool) $config['directives']['opcache.file_cache_only'] : false,
			'validate_timestamps'  => isset( $config['directives']['opcache.validate_timestamps'] ) ? (bool) $config['directives']['opcache.validate_timestamps'] : true,
		);
	}

	/**
	 * Reset (clear) the entire OPcache.
	 *
	 * @return true|WP_Error
	 */
	public static function reset() {
		if ( ! self::is_available() ) {
			return new WP_Error( 'omm_opcache_unavailable', __( 'The OPcache extension is not installed on this server.', 'opcache-memcached-manager' ) );
		}

		if ( ! self::is_enabled() ) {
			return new WP_Error( 'omm_opcache_disabled', __( 'OPcache is currently disabled.', 'opcache-memcached-manager' ) );
		}

		$result = opcache_reset();

		if ( ! $result ) {
			return new WP_Error( 'omm_opcache_reset_failed', __( 'opcache_reset() returned false. Check that opcache.restrict_api does not block the current script/user.', 'opcache-memcached-manager' ) );
		}

		return true;
	}

	/**
	 * Invalidate a single cached file.
	 *
	 * @param string $path Absolute path to the file.
	 * @return true|WP_Error
	 */
	public static function invalidate_file( $path ) {
		if ( ! self::is_available() || ! function_exists( 'opcache_invalidate' ) ) {
			return new WP_Error( 'omm_opcache_unavailable', __( 'The OPcache extension is not installed on this server.', 'opcache-memcached-manager' ) );
		}

		$path = wp_normalize_path( $path );

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'omm_opcache_file_missing', sprintf( __( 'File not found: %s', 'opcache-memcached-manager' ), $path ) );
		}

		$result = opcache_invalidate( $path, true );

		if ( ! $result ) {
			return new WP_Error( 'omm_opcache_invalidate_failed', sprintf( __( 'Could not invalidate (file may not be cached): %s', 'opcache-memcached-manager' ), $path ) );
		}

		return true;
	}

	/**
	 * Human-readable byte formatting helper.
	 */
	public static function format_bytes( $bytes ) {
		return size_format( $bytes, 2 );
	}
}
