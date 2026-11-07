<?php
/**
 * Runs Strauss on the project in the current directory, then prefixes what it misses (DECISIONS.md D29).
 *
 * Strauss's own bin script loads ./vendor/autoload.php before its own when it finds one, which here is the plugin's
 * autoloader, so this loads the tool's autoloader explicitly.
 *
 * Usage, from the plugin root: php tools/strauss/strauss.php
 *
 * @package Glaciate
 */

require __DIR__ . '/vendor/autoload.php';

$glaciate_strauss = new BrianHenryIE\Strauss\Console\Application( (string) Composer\InstalledVersions::getPrettyVersion( 'brianhenryie/strauss' ) );
$glaciate_strauss->setAutoExit( false );
$glaciate_status = $glaciate_strauss->run();
if ( 0 !== $glaciate_status ) {
	exit( $glaciate_status );
}

// Strauss leaves class names alone inside strings that are themselves PHP code: phpseclib's Eval cipher engine
// builds its ciphers that way, so they would call \phpseclib3\... and fail, or run another plugin's copy. Rewrite a
// leading-backslash name in any string literal; strings that only mention the library by name are left as they are.
$glaciate_prefix = json_decode( (string) file_get_contents( 'composer.json' ), true )['extra']['strauss']['namespace_prefix'];
$glaciate_files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( 'vendor/phpseclib/phpseclib', FilesystemIterator::SKIP_DOTS ) );
foreach ( $glaciate_files as $glaciate_file ) {
	if ( 'php' !== $glaciate_file->getExtension() ) {
		continue;
	}
	$glaciate_code = (string) file_get_contents( $glaciate_file->getPathname() );
	$glaciate_new  = '';
	foreach ( token_get_all( $glaciate_code ) as $glaciate_token ) {
		if ( is_array( $glaciate_token ) && T_CONSTANT_ENCAPSED_STRING === $glaciate_token[0] ) {
			$glaciate_token[1] = preg_replace_callback(
				'/(?<![\w\\\\])\\\\(?=(?:phpseclib3|ParagonIE)\\\\)/',
				static function () use ( $glaciate_prefix ) {
					return '\\' . $glaciate_prefix;
				},
				$glaciate_token[1]
			);
		}
		$glaciate_new .= is_array( $glaciate_token ) ? $glaciate_token[1] : $glaciate_token;
	}
	if ( $glaciate_new !== $glaciate_code ) {
		file_put_contents( $glaciate_file->getPathname(), $glaciate_new );
	}
}
