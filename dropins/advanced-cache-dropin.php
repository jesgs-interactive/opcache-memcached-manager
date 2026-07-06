<?php
/**
 * Plugin Name: OMM Page Cache
 * Description: Full-page cache backed by Memcached. Installed and managed by the "OPcache & Memcached Manager" plugin — do not edit by hand, use the plugin's admin screen to reinstall or remove it. OMM Page Cache Drop-in.
 * Version:     1.0.0
 *
 * OMM_PAGECACHE_DROPIN_MARKER
 *
 * Loaded very early (only if WP_CACHE is true in wp-config.php), before
 * WordPress itself boots. On a cache hit we output the saved HTML and exit
 * immediately, skipping the full WP bootstrap. On a miss, we buffer the
 * page's own output and save it once WordPress finishes rendering it.
 *
 * IMPORTANT: the cache-key logic here must stay in sync with
 * OMM_PageCache::build_key() in includes/class-omm-pagecache.php.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'OMM_PAGECACHE_DROPIN_VERSION' ) ) {
	define( 'OMM_PAGECACHE_DROPIN_VERSION', '1.0.0' );
}

// If the Memcached extension is missing, do nothing — WP boots normally.
if ( ! class_exists( 'Memcached' ) ) {
	return;
}

/**
 * Read server pool + settings written by the plugin whenever its
 * settings are saved. Falls back to sane defaults if missing.
 */
function omm_pagecache_get_config() {
	$defaults = array(
		'enabled'            => false,
		'ttl'                => 3600,
		'excluded_patterns'  => array(
			'#^/wp-admin#',
			'#^/wp-login\.php#',
			'#^/wp-cron\.php#',
			'#^/xmlrpc\.php#',
			'#^/wp-json#',
			'#^/feed#',
		),
	);

	$config_file = WP_CONTENT_DIR . '/omm-pagecache-config.php';
	if ( file_exists( $config_file ) ) {
		$loaded = include $config_file;
		if ( is_array( $loaded ) ) {
			return array_merge( $defaults, $loaded );
		}
	}

	return $defaults;
}

function omm_pagecache_get_servers() {
	$config_file = WP_CONTENT_DIR . '/omm-memcached-servers.php';
	if ( file_exists( $config_file ) ) {
		$list = include $config_file;
		if ( is_array( $list ) && ! empty( $list ) ) {
			return $list;
		}
	}
	return array( array( 'host' => '127.0.0.1', 'port' => 11211 ) );
}

/**
 * Build the cache key for a given scheme/host/path. Must match
 * OMM_PageCache::build_key() exactly.
 */
function omm_pagecache_build_key( $scheme, $host, $path ) {
	$path = '/' . ltrim( (string) $path, '/' );
	return 'omm_page:' . md5( strtolower( $scheme ) . '://' . strtolower( $host ) . $path );
}

/**
 * Whether the current request is eligible to be served from, or saved to,
 * the page cache. Deliberately conservative: GET only, no query string, no
 * logged-in/commenter cookies, not an excluded path.
 */
function omm_pagecache_is_eligible( array $config ) {
	if ( empty( $config['enabled'] ) ) {
		return false;
	}

	if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
		return false;
	}

	if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
		return false;
	}

	$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
	if ( null === $path ) {
		$path = '/';
	}

	foreach ( $config['excluded_patterns'] as $pattern ) {
		if ( @preg_match( $pattern, $path ) ) {
			return false;
		}
	}

	foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
		if ( 0 === strpos( $cookie_name, 'wordpress_logged_in_' ) || 0 === strpos( $cookie_name, 'comment_author_' ) ) {
			return false;
		}
	}

	return true;
}

$omm_pc_config = omm_pagecache_get_config();

if ( ! omm_pagecache_is_eligible( $omm_pc_config ) ) {
	return; // Let WordPress boot and handle the request normally, uncached.
}

$omm_pc_scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
$omm_pc_host   = $_SERVER['HTTP_HOST'] ?? '';
$omm_pc_path   = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$omm_pc_key    = omm_pagecache_build_key( $omm_pc_scheme, $omm_pc_host, $omm_pc_path ?: '/' );

$omm_pc_mc = new Memcached( 'omm-pagecache' );
if ( empty( $omm_pc_mc->getServerList() ) ) {
	foreach ( omm_pagecache_get_servers() as $server ) {
		$omm_pc_mc->addServer( $server['host'], (int) $server['port'] );
	}
}

$omm_pc_cached = $omm_pc_mc->get( $omm_pc_key );

if ( Memcached::RES_SUCCESS === $omm_pc_mc->getResultCode() && is_array( $omm_pc_cached ) && isset( $omm_pc_cached['body'], $omm_pc_cached['time'] ) ) {
	// Cache HIT — serve directly, never boot WordPress for this request.
	if ( ! empty( $omm_pc_cached['content_type'] ) ) {
		header( 'Content-Type: ' . $omm_pc_cached['content_type'] );
	}
	header( 'X-Cache: HIT' );
	header( 'Cache-Control: max-age=' . max( 0, (int) $omm_pc_config['ttl'] ) . ', public' );
	header( 'Age: ' . max( 0, time() - (int) $omm_pc_cached['time'] ) );
	echo $omm_pc_cached['body'];
	exit;
}

// Cache MISS — let WordPress render the page, and capture the output so we
// can save it once rendering finishes.
header( 'X-Cache: MISS' );
header( 'Cache-Control: max-age=' . max( 0, (int) $omm_pc_config['ttl'] ) . ', public' );

ob_start();

register_shutdown_function( function () use ( $omm_pc_mc, $omm_pc_key, $omm_pc_config ) {
	// Only save well-formed, successful responses.
	$code = function_exists( 'http_response_code' ) ? http_response_code() : 200;

	if ( 200 !== $code ) {
		if ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		return;
	}

	$body = ob_get_level() > 0 ? ob_get_clean() : '';

	if ( '' === trim( $body ) ) {
		return;
	}

	$headers_list  = function_exists( 'headers_list' ) ? headers_list() : array();
	$content_type  = 'text/html; charset=UTF-8';
	foreach ( $headers_list as $header_line ) {
		if ( 0 === stripos( $header_line, 'Content-Type:' ) ) {
			$content_type = trim( substr( $header_line, strlen( 'Content-Type:' ) ) );
			break;
		}
	}

	$entry = array(
		'body'         => $body,
		'time'         => time(),
		'content_type' => $content_type,
	);

	$ttl = max( 0, (int) $omm_pc_config['ttl'] );
	$omm_pc_mc->set( $omm_pc_key, $entry, $ttl );

	// Maintain a simple index of live keys so "purge all" doesn't have to
	// (and never does) call Memcached::flush(), which would also wipe out
	// anything else sharing this same server pool (e.g. the object cache).
	$index = $omm_pc_mc->get( 'omm_page:__index__' );
	if ( ! is_array( $index ) ) {
		$index = array();
	}
	$index[ $omm_pc_key ] = time();
	$omm_pc_mc->set( 'omm_page:__index__', $index, 0 );

	echo $body;
} );
