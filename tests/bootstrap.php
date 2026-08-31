<?php
/**
 * PHPUnit bootstrap.
 *
 * These are plain unit tests — they exercise the pure logic in the page
 * cache drop-in and a couple of plugin helpers without loading WordPress.
 * The handful of WP functions those units touch are shimmed below with
 * faithful-enough stand-ins.
 *
 * The advanced-cache.php drop-in is written to run on include (it's a
 * drop-in, not a library), but it returns at the eligibility gate before
 * touching Memcached whenever page caching isn't enabled — which is the
 * case here, since WP_CONTENT_DIR points at an empty temp dir with no
 * config file. So including it just defines its functions.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$omm_test_content_dir = sys_get_temp_dir() . '/omm-phpunit-content';
if ( ! is_dir( $omm_test_content_dir ) ) {
	mkdir( $omm_test_content_dir, 0777, true );
}
define( 'WP_CONTENT_DIR', $omm_test_content_dir );

// The drop-in bails early unless the Memcached class exists; give it a bare
// stand-in so the function definitions past that check are reached.
if ( ! class_exists( 'Memcached' ) ) {
	class Memcached {
		const RES_SUCCESS = 0;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = strip_tags( $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}
}

require_once dirname( __DIR__ ) . '/dropins/advanced-cache-dropin.php';
require_once dirname( __DIR__ ) . '/includes/class-omm-pagecache.php';
require_once dirname( __DIR__ ) . '/includes/class-omm-admin.php';
