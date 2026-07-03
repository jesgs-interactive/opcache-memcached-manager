<?php
/**
 * Plugin Name: OMM Memcached Object Cache
 * Description: Memcached-backed WP_Object_Cache implementation. Installed and managed by the "OPcache & Memcached Manager" plugin — do not edit by hand, use the plugin's admin screen to reinstall or remove it. OMM Memcached Object Cache Drop-in.
 * Version:     1.0.0
 *
 * OMM_MEMCACHED_DROPIN_MARKER
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'OMM_MEMCACHED_DROPIN_VERSION' ) ) {
	define( 'OMM_MEMCACHED_DROPIN_VERSION', '1.0.0' );
}

// If the Memcached extension isn't present, fall back to WordPress's
// built-in non-persistent cache instead of fataling.
if ( ! class_exists( 'Memcached' ) ) {
	require_once ABSPATH . WPINC . '/cache.php';
	return;
}

/**
 * Reads the server pool written by the OPcache & Memcached Manager plugin
 * whenever its settings are saved. Falls back to localhost if the config
 * file is missing (e.g. drop-in copied to a server before first save).
 */
function omm_dropin_get_servers() {
	$config_file = WP_CONTENT_DIR . '/omm-memcached-servers.php';

	if ( file_exists( $config_file ) ) {
		$list = include $config_file;
		if ( is_array( $list ) && ! empty( $list ) ) {
			return $list;
		}
	}

	return array( array( 'host' => '127.0.0.1', 'port' => 11211 ) );
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, $expire );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	global $wp_object_cache;
	return $wp_object_cache->get_multiple( $keys, $group, $force );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, $expire );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, $expire );
}

function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

function wp_cache_close() {
	return true;
}

function wp_cache_init() {
	global $wp_object_cache;
	$wp_object_cache = new OMM_Memcached_Object_Cache();
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	$wp_object_cache->switch_to_blog( $blog_id );
}

/**
 * Minimal WP_Object_Cache-compatible implementation backed by Memcached,
 * with an in-request array cache layered on top so repeated reads in the
 * same request don't round-trip to the server.
 */
class OMM_Memcached_Object_Cache {

	/** @var Memcached */
	private $mc;

	/** @var array Per-group, per-key runtime cache for this request. */
	private $cache = array();

	/** @var string[] Groups shared across all sites on a multisite install. */
	private $global_groups = array();

	/** @var string[] Groups that never touch Memcached (request-local only). */
	private $non_persistent_groups = array();

	private $blog_prefix = '';
	private $multisite   = false;

	public $cache_hits   = 0;
	public $cache_misses = 0;

	public function __construct() {
		$this->mc = new Memcached( 'omm-object-cache' );

		if ( empty( $this->mc->getServerList() ) ) {
			foreach ( omm_dropin_get_servers() as $server ) {
				$this->mc->addServer( $server['host'], (int) $server['port'] );
			}
		}

		$this->multisite   = function_exists( 'is_multisite' ) && is_multisite();
		$this->blog_prefix = $this->multisite ? get_current_blog_id() . ':' : '';
	}

	private function build_key( $key, $group ) {
		$group  = $group ?: 'default';
		$prefix = in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix;
		return 'omm:' . $prefix . $group . ':' . $key;
	}

	private function is_non_persistent( $group ) {
		return in_array( $group ?: 'default', $this->non_persistent_groups, true );
	}

	public function add_global_groups( $groups ) {
		$this->global_groups = array_unique( array_merge( $this->global_groups, (array) $groups ) );
	}

	public function add_non_persistent_groups( $groups ) {
		$this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, (array) $groups ) );
	}

	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = $this->multisite ? ( (int) $blog_id ) . ':' : '';
	}

	public function add( $key, $data, $group = '', $expire = 0 ) {
		$group = $group ?: 'default';

		if ( $this->is_non_persistent( $group ) ) {
			if ( isset( $this->cache[ $group ][ $key ] ) ) {
				return false;
			}
			$this->cache[ $group ][ $key ] = $data;
			return true;
		}

		$result = $this->mc->add( $this->build_key( $key, $group ), $data, $expire );
		if ( $result ) {
			$this->cache[ $group ][ $key ] = $data;
		}
		return $result;
	}

	public function get( $key, $group = '', $force = false, &$found = null ) {
		$group = $group ?: 'default';

		if ( ! $force && isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] ) ) {
			$found = true;
			$this->cache_hits++;
			$value = $this->cache[ $group ][ $key ];
			return is_object( $value ) ? clone $value : $value;
		}

		if ( $this->is_non_persistent( $group ) ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}

		$value = $this->mc->get( $this->build_key( $key, $group ) );

		if ( Memcached::RES_SUCCESS !== $this->mc->getResultCode() ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}

		$found = true;
		$this->cache_hits++;
		$this->cache[ $group ][ $key ] = $value;
		return $value;
	}

	public function get_multiple( $keys, $group = '', $force = false ) {
		$values = array();
		foreach ( (array) $keys as $key ) {
			$values[ $key ] = $this->get( $key, $group, $force );
		}
		return $values;
	}

	public function set( $key, $data, $group = '', $expire = 0 ) {
		$group = $group ?: 'default';
		$this->cache[ $group ][ $key ] = $data;

		if ( $this->is_non_persistent( $group ) ) {
			return true;
		}

		return $this->mc->set( $this->build_key( $key, $group ), $data, $expire );
	}

	public function replace( $key, $data, $group = '', $expire = 0 ) {
		$group = $group ?: 'default';

		if ( $this->is_non_persistent( $group ) ) {
			if ( ! isset( $this->cache[ $group ][ $key ] ) ) {
				return false;
			}
			$this->cache[ $group ][ $key ] = $data;
			return true;
		}

		$result = $this->mc->replace( $this->build_key( $key, $group ), $data, $expire );
		if ( $result ) {
			$this->cache[ $group ][ $key ] = $data;
		}
		return $result;
	}

	public function delete( $key, $group = '' ) {
		$group = $group ?: 'default';
		unset( $this->cache[ $group ][ $key ] );

		if ( $this->is_non_persistent( $group ) ) {
			return true;
		}

		return $this->mc->delete( $this->build_key( $key, $group ) );
	}

	public function incr( $key, $offset = 1, $group = '' ) {
		$group = $group ?: 'default';

		if ( $this->is_non_persistent( $group ) ) {
			if ( ! isset( $this->cache[ $group ][ $key ] ) ) {
				return false;
			}
			$this->cache[ $group ][ $key ] = max( 0, (int) $this->cache[ $group ][ $key ] + $offset );
			return $this->cache[ $group ][ $key ];
		}

		$result = $this->mc->increment( $this->build_key( $key, $group ), $offset );
		if ( false !== $result ) {
			$this->cache[ $group ][ $key ] = $result;
		}
		return $result;
	}

	public function decr( $key, $offset = 1, $group = '' ) {
		$group = $group ?: 'default';

		if ( $this->is_non_persistent( $group ) ) {
			if ( ! isset( $this->cache[ $group ][ $key ] ) ) {
				return false;
			}
			$this->cache[ $group ][ $key ] = max( 0, (int) $this->cache[ $group ][ $key ] - $offset );
			return $this->cache[ $group ][ $key ];
		}

		$result = $this->mc->decrement( $this->build_key( $key, $group ), $offset );
		if ( false !== $result ) {
			$this->cache[ $group ][ $key ] = $result;
		}
		return $result;
	}

	public function flush() {
		$this->cache = array();
		return $this->mc->flush();
	}
}
