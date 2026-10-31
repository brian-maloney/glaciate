<?php
/**
 * Runs Strauss on the project in the current directory, then prefixes what it misses (DECISIONS.md D29).
 *
 * Strauss's own bin script loads ./vendor/autoload.php before its own when it finds one, which here is the plugin's
 * autoloader, so this loads the tool's autoloader explicitly.
 *
 * Usage, from the plugin root: php tools/strauss/strauss.php
 *
 * @package Stillpress
 */

require __DIR__ . '/vendor/autoload.php';

$stillpress_strauss = new BrianHenryIE\Strauss\Console\Application( (string) Composer\InstalledVersions::getPrettyVersion( 'brianhenryie/strauss' ) );
$stillpress_strauss->setAutoExit( false );
$stillpress_status = $stillpress_strauss->run();
if ( 0 !== $stillpress_status ) {
	exit( $stillpress_status );
}

// Strauss leaves class names alone inside strings that are themselves PHP code: phpseclib's Eval cipher engine
// builds its ciphers that way, so they would call \phpseclib3\... and fail, or run another plugin's copy. Rewrite a
// leading-backslash name in any string literal; strings that only mention the library by name are left as they are.
$stillpress_prefix = json_decode( (string) file_get_contents( 'composer.json' ), true )['extra']['strauss']['namespace_prefix'];
$stillpress_files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( 'vendor/phpseclib/phpseclib', FilesystemIterator::SKIP_DOTS ) );
foreach ( $stillpress_files as $stillpress_file ) {
	if ( 'php' !== $stillpress_file->getExtension() ) {
		continue;
	}
	$stillpress_code = (string) file_get_contents( $stillpress_file->getPathname() );
	$stillpress_new  = '';
	foreach ( token_get_all( $stillpress_code ) as $stillpress_token ) {
		if ( is_array( $stillpress_token ) && T_CONSTANT_ENCAPSED_STRING === $stillpress_token[0] ) {
			$stillpress_token[1] = preg_replace_callback(
				'/(?<![\w\\\\])\\\\(?=(?:phpseclib3|ParagonIE)\\\\)/',
				static function () use ( $stillpress_prefix ) {
					return '\\' . $stillpress_prefix;
				},
				$stillpress_token[1]
			);
		}
		$stillpress_new .= is_array( $stillpress_token ) ? $stillpress_token[1] : $stillpress_token;
	}
	if ( $stillpress_new !== $stillpress_code ) {
		file_put_contents( $stillpress_file->getPathname(), $stillpress_new );
	}
}
