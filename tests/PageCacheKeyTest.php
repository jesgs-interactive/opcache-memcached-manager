<?php

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The advanced-cache.php drop-in builds its cache key before WordPress
 * loads; OMM_PageCache::build_key() rebuilds the same key from a full URL
 * when purging. If these two ever drift, purges silently stop matching
 * what the drop-in stored. This locks them together.
 */
final class PageCacheKeyTest extends TestCase {

	/**
	 * @dataProvider url_provider
	 */
	public function test_dropin_and_class_agree( $url ) {
		$parts = parse_url( $url );

		$dropin_key = omm_pagecache_build_key(
			$parts['scheme'],
			$parts['host'],
			isset( $parts['path'] ) ? $parts['path'] : '/'
		);

		$this->assertSame(
			OMM_PageCache::build_key( $url ),
			$dropin_key,
			"key mismatch for {$url}"
		);
	}

	public static function url_provider() {
		return array(
			'root'                  => array( 'https://example.com/' ),
			'no path'               => array( 'https://example.com' ),
			'http scheme'           => array( 'http://example.com/hello-world/' ),
			'nested path'           => array( 'https://example.com/a/b/c/' ),
			'no trailing slash'     => array( 'https://example.com/about' ),
			'mixed-case host'       => array( 'https://EXAMPLE.com/about' ),
			'mixed-case path kept'  => array( 'https://example.com/Path/To/Thing' ),
			'subdomain'             => array( 'https://cdn.example.com/x/' ),
		);
	}

	public function test_scheme_and_host_are_case_insensitive() {
		$this->assertSame(
			OMM_PageCache::build_key( 'https://example.com/about' ),
			OMM_PageCache::build_key( 'HTTPS://Example.COM/about' )
		);
	}

	public function test_trailing_slash_is_significant() {
		$this->assertNotSame(
			OMM_PageCache::build_key( 'https://example.com/about' ),
			OMM_PageCache::build_key( 'https://example.com/about/' )
		);
	}

	public function test_key_is_namespaced() {
		$this->assertStringStartsWith( 'omm_page:', OMM_PageCache::build_key( 'https://example.com/' ) );
	}
}
