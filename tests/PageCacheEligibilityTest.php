<?php

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * omm_pagecache_is_eligible() decides whether a request may be served from
 * or written to the page cache. It runs before WordPress loads, so it only
 * has $_SERVER and $_COOKIE to work with — these tests drive it directly.
 */
final class PageCacheEligibilityTest extends TestCase {

	/** @var array */
	private $server_backup;

	/** @var array */
	private $cookie_backup;

	protected function set_up() {
		$this->server_backup = $_SERVER;
		$this->cookie_backup = $_COOKIE;

		$_SERVER = array(
			'REQUEST_METHOD' => 'GET',
			'REQUEST_URI'    => '/some-page/',
		);
		$_COOKIE = array();
	}

	protected function tear_down() {
		$_SERVER = $this->server_backup;
		$_COOKIE = $this->cookie_backup;
	}

	private function config( array $overrides = array() ) {
		return array_merge(
			array(
				'enabled'           => true,
				'ttl'               => 3600,
				'excluded_patterns' => array( '#^/wp-admin#', '#^/feed#' ),
			),
			$overrides
		);
	}

	public function test_plain_get_is_eligible() {
		$this->assertTrue( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_disabled_config_is_not_eligible() {
		$this->assertFalse( omm_pagecache_is_eligible( $this->config( array( 'enabled' => false ) ) ) );
	}

	public function test_non_get_is_not_eligible() {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertFalse( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_missing_request_method_is_not_eligible() {
		unset( $_SERVER['REQUEST_METHOD'] );
		$this->assertFalse( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_query_string_is_not_eligible() {
		$_SERVER['QUERY_STRING'] = 'preview=true';
		$this->assertFalse( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_markdown_accept_is_not_eligible() {
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		$this->assertFalse( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_markdown_accept_among_other_types_is_not_eligible() {
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,text/markdown;q=0.9';
		$this->assertFalse( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_plain_html_accept_is_eligible() {
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
		$this->assertTrue( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_logged_in_cookie_is_not_eligible() {
		$_COOKIE['wordpress_logged_in_abc123'] = 'whatever';
		$this->assertFalse( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_comment_author_cookie_is_not_eligible() {
		$_COOKIE['comment_author_abc123'] = 'Jess';
		$this->assertFalse( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_excluded_pattern_is_not_eligible() {
		$_SERVER['REQUEST_URI'] = '/feed/';
		$this->assertFalse( omm_pagecache_is_eligible( $this->config() ) );
	}

	public function test_path_not_matching_any_pattern_is_eligible() {
		$_SERVER['REQUEST_URI'] = '/blog/2026/a-post/';
		$this->assertTrue( omm_pagecache_is_eligible( $this->config() ) );
	}
}
