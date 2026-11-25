<?php
/**
 * What the form rate limit counts an address as, and when requests look proxied.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Forms;

use Glaciate\Forms\ClientAddress;
use PHPUnit\Framework\TestCase;

final class ClientAddressTest extends TestCase {

	public function test_ipv4_counts_per_address(): void {
		$this->assertSame( '203.0.113.5', ClientAddress::bucket( '203.0.113.5' ) );
		$this->assertNotSame( ClientAddress::bucket( '203.0.113.5' ), ClientAddress::bucket( '203.0.113.6' ) );
	}

	public function test_ipv6_counts_per_64(): void {
		$this->assertSame( '2001:db8:1:2::/64', ClientAddress::bucket( '2001:db8:1:2::1' ) );
		$this->assertSame( '2001:db8:1:2::/64', ClientAddress::bucket( '2001:DB8:1:2:ffff:eeee:dddd:cccc' ), 'any interface ID, any case' );
		$this->assertSame( '2001:db8:1:3::/64', ClientAddress::bucket( '2001:db8:1:3::1' ), 'the next /64 is another bucket' );
		$this->assertSame( '::/64', ClientAddress::bucket( '::1' ) );
	}

	public function test_ipv4_mapped_ipv6_counts_as_ipv4(): void {
		$this->assertSame( '192.0.2.7', ClientAddress::bucket( '::ffff:192.0.2.7' ) );
	}

	public function test_anything_else_has_no_bucket(): void {
		foreach ( array( '', 'unknown', '203.0.113', '203.0.113.5, 10.0.0.1', '2001:db8::1%eth0', '[2001:db8::1]' ) as $value ) {
			$this->assertSame( '', ClientAddress::bucket( $value ), $value );
		}
	}

	public function test_no_forwarding_headers_is_not_proxied(): void {
		$this->assertFalse( ClientAddress::proxied( array( 'REMOTE_ADDR' => '203.0.113.5' ) ) );
		$this->assertFalse(
			ClientAddress::proxied(
				array(
					'REMOTE_ADDR'          => '203.0.113.5',
					'HTTP_X_FORWARDED_FOR' => '  ',
				)
			)
		);
		$this->assertFalse( ClientAddress::proxied( array() ) );
	}

	public function test_forwarding_headers_that_remote_addr_is_not_in_are_proxied(): void {
		$cases = array(
			array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.9' ),
			array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.2' ),
			array( 'HTTP_CF_CONNECTING_IP' => '2001:db8::9' ),
			array( 'HTTP_X_REAL_IP' => '198.51.100.9' ),
			array( 'HTTP_TRUE_CLIENT_IP' => '198.51.100.9' ),
			array( 'HTTP_FORWARDED' => 'for=198.51.100.9;proto=https' ),
			array( 'HTTP_X_FORWARDED_FOR' => 'unknown' ),
		);
		foreach ( $cases as $headers ) {
			$this->assertTrue( ClientAddress::proxied( array( 'REMOTE_ADDR' => '10.0.0.1' ) + $headers ), key( $headers ) . ': ' . current( $headers ) );
		}
		$this->assertTrue( ClientAddress::proxied( array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.9' ) ), 'no REMOTE_ADDR' );
	}

	public function test_remote_addr_already_set_to_the_visitor_is_not_proxied(): void {
		$cases = array(
			array( '198.51.100.9', array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.9' ) ),
			array( '198.51.100.9', array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.2' ) ),
			array( '198.51.100.9', array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.9:52113' ) ),
			array( '2001:db8::9', array( 'HTTP_CF_CONNECTING_IP' => '2001:DB8:0::9' ) ),
			array( '2001:db8::9', array( 'HTTP_FORWARDED' => 'for="[2001:db8::9]:4711";proto=https' ) ),
			array( '198.51.100.9', array( 'HTTP_FORWARDED' => 'for=192.0.2.1, proto=https;for=198.51.100.9' ) ),
		);
		foreach ( $cases as $case ) {
			$this->assertFalse( ClientAddress::proxied( array( 'REMOTE_ADDR' => $case[0] ) + $case[1] ), $case[0] );
		}
	}
}
