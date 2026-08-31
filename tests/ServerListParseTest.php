<?php

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * OMM_Admin::parse_server_lines() turns the free-text "Memcached servers"
 * textarea into a normalized pool. Malformed lines are common (stray
 * whitespace, a bad port, a blank line), so the parsing needs to be
 * predictable.
 */
final class ServerListParseTest extends TestCase {

	public function test_host_only_gets_default_port_and_weight() {
		$this->assertSame(
			array(
				array( 'host' => 'cache1.local', 'port' => 11211, 'weight' => 0 ),
			),
			OMM_Admin::parse_server_lines( 'cache1.local' )
		);
	}

	public function test_multiple_lines() {
		$parsed = OMM_Admin::parse_server_lines( "cache1.local\ncache2.local" );
		$this->assertCount( 2, $parsed );
		$this->assertSame( 'cache2.local', $parsed[1]['host'] );
	}

	public function test_host_and_port() {
		$parsed = OMM_Admin::parse_server_lines( 'cache1.local:11212' );
		$this->assertSame( 11212, $parsed[0]['port'] );
	}

	public function test_host_port_and_weight() {
		$parsed = OMM_Admin::parse_server_lines( 'cache1.local:11211:20' );
		$this->assertSame( 20, $parsed[0]['weight'] );
	}

	public function test_blank_and_whitespace_only_lines_are_dropped() {
		$parsed = OMM_Admin::parse_server_lines( "cache1.local\n\n   \n\ncache2.local\n" );
		$this->assertCount( 2, $parsed );
	}

	public function test_surrounding_whitespace_is_trimmed() {
		$parsed = OMM_Admin::parse_server_lines( "  cache1.local : 11212 \n" );
		$this->assertSame( 'cache1.local', $parsed[0]['host'] );
		$this->assertSame( 11212, $parsed[0]['port'] );
	}

	public function test_non_numeric_port_falls_back_to_default() {
		$parsed = OMM_Admin::parse_server_lines( 'cache1.local:nope' );
		$this->assertSame( 11211, $parsed[0]['port'] );
	}

	public function test_negative_weight_is_clamped_to_zero() {
		$parsed = OMM_Admin::parse_server_lines( 'cache1.local:11211:-5' );
		$this->assertSame( 0, $parsed[0]['weight'] );
	}

	public function test_line_with_no_host_is_dropped() {
		$this->assertSame( array(), OMM_Admin::parse_server_lines( ':11211' ) );
	}

	public function test_empty_input_is_empty_list() {
		$this->assertSame( array(), OMM_Admin::parse_server_lines( '' ) );
	}
}
