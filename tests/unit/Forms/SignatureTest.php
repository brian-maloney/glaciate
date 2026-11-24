<?php
/**
 * Relay signatures have exactly one spelling, so the relay's once-only check can't be sidestepped by respelling one.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Forms;

use PHPUnit\Framework\TestCase;
use Glaciate\Forms\Signature;

final class SignatureTest extends TestCase {

	public function test_parses_what_sign_writes(): void {
		$header = Signature::sign( '{"a":1}', 'k', 2000000000 );
		$this->assertSame( array( 2000000000, hash_hmac( 'sha256', '2000000000.{"a":1}', 'k' ) ), Signature::parse( $header ) );
	}

	/**
	 * Spellings of a valid header that a server might pass through, or an attacker might send.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function respellings(): array {
		return array(
			'trailing space'       => array(
				static function ( string $h ): string {
					return $h . ' ';
				},
			),
			'leading space'        => array(
				static function ( string $h ): string {
					return ' ' . $h;
				},
			),
			'trailing tab'         => array(
				static function ( string $h ): string {
					return $h . "\t";
				},
			),
			'trailing newline'     => array(
				static function ( string $h ): string {
					return $h . "\n";
				},
			),
			'trailing CRLF'        => array(
				static function ( string $h ): string {
					return $h . "\r\n";
				},
			),
			'trailing NUL'         => array(
				static function ( string $h ): string {
					return $h . "\0";
				},
			),
			'vertical tab'         => array(
				static function ( string $h ): string {
					return $h . "\x0B";
				},
			),
			'leading zero in time' => array(
				static function ( string $h ): string {
					return str_replace( 't=', 't=0', $h );
				},
			),
			'upper case hex'       => array(
				static function ( string $h ): string {
					return 't=' . substr( $h, 2, 10 ) . ',v1=' . strtoupper( substr( $h, 16 ) );
				},
			),
			'space after comma'    => array(
				static function ( string $h ): string {
					return str_replace( ',', ', ', $h );
				},
			),
		);
	}

	/**
	 * @dataProvider respellings
	 *
	 * @param callable $respell Turns the header into another spelling.
	 */
	public function test_other_spellings_neither_parse_nor_verify( callable $respell ): void {
		$header = Signature::sign( '{"a":1}', 'k', 2000000000 );
		$other  = $respell( $header );
		$this->assertNotSame( $header, $other );
		$this->assertNull( Signature::parse( $other ) );
		$this->assertFalse( Signature::verify( $other, '{"a":1}', 'k', 2000000000 ) );
		$this->assertTrue( Signature::verify( $header, '{"a":1}', 'k', 2000000000 ), 'the original still verifies' );
	}

	public function test_refuses_malformed_headers(): void {
		foreach ( array( '', 't=,v1=', 't=0,v1=' . str_repeat( 'a', 64 ), 't=1000,v1=' . str_repeat( 'a', 63 ), 't=1000,v1=' . str_repeat( 'a', 65 ), 't=1234567890123,v1=' . str_repeat( 'a', 64 ), 'v1=' . str_repeat( 'a', 64 ) . ',t=1000' ) as $header ) {
			$this->assertNull( Signature::parse( $header ), $header );
		}
	}
}
