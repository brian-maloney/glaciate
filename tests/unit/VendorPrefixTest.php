<?php
/**
 * Bundled libraries that other plugins also bundle load under Glaciate's own namespace (DECISIONS.md D29).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VendorPrefixTest extends TestCase {

	/** Original namespace => a class in it. */
	const LIBRARIES = array(
		'phpseclib3\\'              => 'phpseclib3\\Net\\SFTP',
		'ParagonIE\\ConstantTime\\' => 'ParagonIE\\ConstantTime\\Base64',
	);

	public function test_libraries_load_only_under_the_prefix(): void {
		foreach ( self::LIBRARIES as $class ) {
			$this->assertTrue( class_exists( 'Glaciate\\Vendor\\' . $class ), "Glaciate\\Vendor\\{$class} doesn't load. Run composer install with scripts enabled, which runs Strauss." );
			$this->assertFalse( class_exists( $class ), "{$class} loads under its original name, where another plugin's copy can replace it." );
		}
	}

	public function test_pure_php_ciphers_call_the_prefixed_classes(): void {
		// Without OpenSSL, phpseclib runs ciphers in PHP, by default as code it builds in strings and evaluates (the
		// Eval engine). Strauss doesn't rewrite class names inside that code; tools/strauss/strauss.php does.
		$expected = null;
		foreach ( array( 'OpenSSL', 'Eval', 'PHP' ) as $engine ) {
			$aes = new \Glaciate\Vendor\phpseclib3\Crypt\AES( 'ctr' );
			$aes->setPreferredEngine( $engine );
			$this->assertSame( $engine, $aes->getEngine() );
			$aes->setKey( str_repeat( 'k', 16 ) );
			$aes->setIV( str_repeat( 'i', 16 ) );
			$aes->enableContinuousBuffer();
			$ciphertext = $aes->encrypt( str_repeat( 'Glaciate ', 20 ) ) . $aes->encrypt( 'and more' );
			$this->assertSame( $expected ?? $ciphertext, $ciphertext, $engine );
			$expected = $ciphertext;
		}
	}

	public function test_source_uses_only_prefixed_names(): void {
		$root     = dirname( __DIR__, 2 );
		$problems = array();
		$files    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( file( $file->getPathname() ) as $number => $line ) {
				foreach ( array_keys( self::LIBRARIES ) as $namespace ) {
					// A use statement, or a fully qualified \name not preceded by the prefix.
					if ( preg_match( '/(?:^\s*use\s+|(?<!Vendor)\\\\)' . preg_quote( $namespace, '/' ) . '/', $line ) ) {
						$problems[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . ( $number + 1 ) . ': ' . trim( $line );
					}
				}
			}
		}
		$this->assertSame( array(), $problems, 'Use Glaciate\\Vendor\\ in front of the library namespace.' );
	}
}
