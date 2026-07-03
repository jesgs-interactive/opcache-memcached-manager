<?php
/**
 * Thin wrapper around the PHP Memcached extension.
 *
 * Connects to a configurable pool of servers independent of WordPress's
 * object cache, but also detects and can flush the WP object cache when
 * it happens to be backed by Memcached (e.g. via an object-cache.php
 * drop-in).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OMM_Memcached {

	/** @var Memcached|null */
	private static $instance = null;

	/**
	 * Whether the Memcached PHP extension is loaded.
	 */
	public static function is_available() {
		return class_exists( 'Memcached' );
	}

	/**
	 * Get (or lazily create) a Memcached client connected to the
	 * servers configured in plugin settings.
	 *
	 * @return Memcached|WP_Error
	 */
	public static function get_instance() {
		if ( ! self::is_available() ) {
			return new WP_Error( 'omm_memcached_unavailable', __( 'The Memcached PHP extension is not installed on this server.', 'opcache-memcached-manager' ) );
		}

		if ( self::$instance instanceof Memcached ) {
			return self::$instance;
		}

		$servers = self::get_configured_servers();

		if ( empty( $servers ) ) {
			return new WP_Error( 'omm_memcached_no_servers', __( 'No Memcached servers are configured.', 'opcache-memcached-manager' ) );
		}

		$m = new Memcached( 'omm-pool' );

		// Avoid re-adding servers to the same persistent pool repeatedly.
		$existing = $m->getServerList();
		if ( empty( $existing ) ) {
			foreach ( $servers as $server ) {
				$m->addServer( $server['host'], (int) $server['port'], (int) ( $server['weight'] ?? 0 ) );
			}
		}

		self::$instance = $m;

		return self::$instance;
	}

	/**
	 * Read the configured server pool from settings.
	 */
	public static function get_configured_servers() {
		$settings = omm_get_settings();
		$servers  = isset( $settings['memcached_servers'] ) ? $settings['memcached_servers'] : array();

		$clean = array();
		foreach ( (array) $servers as $server ) {
			if ( empty( $server['host'] ) ) {
				continue;
			}
			$clean[] = array(
				'host'   => sanitize_text_field( $server['host'] ),
				'port'   => isset( $server['port'] ) ? (int) $server['port'] : 11211,
				'weight' => isset( $server['weight'] ) ? (int) $server['weight'] : 0,
			);
		}

		return $clean;
	}

	/**
	 * Ping each configured server individually so a dead node doesn't
	 * hide behind an otherwise-healthy pool.
	 *
	 * @return array List of ['host'=>, 'port'=>, 'reachable'=>bool]
	 */
	public static function get_server_reachability() {
		$results = array();

		if ( ! self::is_available() ) {
			return $results;
		}

		foreach ( self::get_configured_servers() as $server ) {
			$probe = new Memcached();
			$probe->setOption( Memcached::OPT_CONNECT_TIMEOUT, 300 );
			$probe->addServer( $server['host'], $server['port'] );
			$probe_stats = $probe->getStats();
			$key         = $server['host'] . ':' . $server['port'];

			$reachable = isset( $probe_stats[ $key ] ) && is_array( $probe_stats[ $key ] ) && ! empty( $probe_stats[ $key ] );

			$results[] = array(
				'host'      => $server['host'],
				'port'      => $server['port'],
				'reachable' => $reachable,
			);
		}

		return $results;
	}

	/**
	 * Get aggregated + per-server stats.
	 *
	 * @return array|WP_Error
	 */
	public static function get_stats() {
		$m = self::get_instance();
		if ( is_wp_error( $m ) ) {
			return $m;
		}

		$raw = $m->getStats();

		if ( empty( $raw ) ) {
			return new WP_Error( 'omm_memcached_no_stats', __( 'Could not reach any configured Memcached server.', 'opcache-memcached-manager' ) );
		}

		$servers    = array();
		$total_hits = 0;
		$total_gets = 0;
		$total_bytes = 0;
		$total_items = 0;
		$total_limit = 0;

		foreach ( $raw as $server_key => $stats ) {
			if ( ! is_array( $stats ) || empty( $stats ) ) {
				$servers[] = array(
					'server'    => $server_key,
					'reachable' => false,
				);
				continue;
			}

			$hits    = isset( $stats['get_hits'] ) ? (int) $stats['get_hits'] : 0;
			$misses  = isset( $stats['get_misses'] ) ? (int) $stats['get_misses'] : 0;
			$gets    = $hits + $misses;

			$total_hits  += $hits;
			$total_gets  += $gets;
			$total_bytes += isset( $stats['bytes'] ) ? (int) $stats['bytes'] : 0;
			$total_items += isset( $stats['curr_items'] ) ? (int) $stats['curr_items'] : 0;
			$total_limit += isset( $stats['limit_maxbytes'] ) ? (int) $stats['limit_maxbytes'] : 0;

			$servers[] = array(
				'server'         => $server_key,
				'reachable'      => true,
				'version'        => $stats['version'] ?? '',
				'uptime'         => isset( $stats['uptime'] ) ? (int) $stats['uptime'] : 0,
				'curr_items'     => isset( $stats['curr_items'] ) ? (int) $stats['curr_items'] : 0,
				'bytes'          => isset( $stats['bytes'] ) ? (int) $stats['bytes'] : 0,
				'limit_maxbytes' => isset( $stats['limit_maxbytes'] ) ? (int) $stats['limit_maxbytes'] : 0,
				'get_hits'       => $hits,
				'get_misses'     => $misses,
				'hit_rate_pct'   => $gets > 0 ? round( ( $hits / $gets ) * 100, 2 ) : 0,
				'curr_connections' => isset( $stats['curr_connections'] ) ? (int) $stats['curr_connections'] : 0,
				'evictions'      => isset( $stats['evictions'] ) ? (int) $stats['evictions'] : 0,
			);
		}

		return array(
			'servers'           => $servers,
			'total_hit_rate_pct' => $total_gets > 0 ? round( ( $total_hits / $total_gets ) * 100, 2 ) : 0,
			'total_bytes'       => $total_bytes,
			'total_items'       => $total_items,
			'total_limit_bytes' => $total_limit,
			'is_object_cache'   => self::is_wp_object_cache_backend(),
		);
	}

	/**
	 * Flush the configured Memcached pool directly (bypasses WP object cache API).
	 *
	 * @return true|WP_Error
	 */
	public static function flush() {
		$m = self::get_instance();
		if ( is_wp_error( $m ) ) {
			return $m;
		}

		$result = $m->flush();

		if ( ! $result ) {
			$code = $m->getResultCode();
			return new WP_Error(
				'omm_memcached_flush_failed',
				sprintf( __( 'Memcached flush() failed (result code %d: %s).', 'opcache-memcached-manager' ), $code, $m->getResultMessage() )
			);
		}

		return true;
	}

	/**
	 * Detect whether WordPress's own object cache is backed by Memcached
	 * (e.g. a memcached.php / memcached-object-cache.php drop-in is active).
	 * This is a best-effort heuristic based on the global object cache's
	 * class name and internal properties.
	 */
	public static function is_wp_object_cache_backend() {
		global $wp_object_cache;

		if ( ! wp_using_ext_object_cache() || ! is_object( $wp_object_cache ) ) {
			return false;
		}

		$class = strtolower( get_class( $wp_object_cache ) );
		if ( false !== strpos( $class, 'memcache' ) ) {
			return true;
		}

		// Some drop-ins keep the class name generic (e.g. WP_Object_Cache)
		// but store Memcached/Memcache client objects in a property.
		foreach ( get_object_vars( $wp_object_cache ) as $value ) {
			if ( $value instanceof Memcached ) {
				return true;
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( $item instanceof Memcached ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Flush the WordPress object cache via the standard API. Only meaningful
	 * when is_wp_object_cache_backend() is true, but safe to call regardless.
	 */
	public static function flush_wp_object_cache() {
		return (bool) wp_cache_flush();
	}
}
