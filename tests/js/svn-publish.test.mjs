/**
 * WordPress.org Subversion publishing (bin/svn-publish.sh), against a throwaway local repository.
 *
 * Skipped when svn and svnadmin aren't installed.
 *
 * @package Glaciate
 */

import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT = join( dirname( fileURLToPath( import.meta.url ) ), '../../bin/svn-publish.sh' );
const haveSvn = [ 'svn', 'svnadmin' ].every( ( cmd ) => 0 === spawnSync( cmd, [ '--version', '--quiet' ] ).status );
const skip = haveSvn ? false : 'svn and svnadmin are not installed';

let root;
let url;

/**
 * Writes files under a folder, creating folders as needed.
 *
 * @param {string}                 dir   Folder.
 * @param {Object<string, string>} files Relative path to contents.
 * @return {string} The folder.
 */
function tree( dir, files ) {
	rmSync( dir, { recursive: true, force: true } );
	for ( const [ path, contents ] of Object.entries( files ) ) {
		mkdirSync( dirname( join( dir, path ) ), { recursive: true } );
		writeFileSync( join( dir, path ), contents );
	}
	return dir;
}

const plugin = ( version, extra = {} ) => ( {
	'glaciate.php': `<?php\n/**\n * Plugin Name: Glaciate\n * Version:           ${ version }\n */\n`,
	'readme.txt': `=== Glaciate ===\nTested up to: 7.1\nStable tag: ${ version }\n`,
	...extra,
} );

/**
 * Runs the script.
 *
 * @param {string[]} args Arguments after the script name.
 * @return {{status: number, out: string}} Exit status and combined output.
 */
function publish( ...args ) {
	const env = { ...process.env, SVN_USERNAME: '', SVN_PASSWORD: '', SVN_DRY_RUN: '' };
	const result = spawnSync( SCRIPT, args, { env, encoding: 'utf8' } );
	return { status: result.status, out: result.stdout + result.stderr };
}

const svn = ( ...args ) => execFileSync( 'svn', args, { encoding: 'utf8' } );
const list = ( path ) => svn( 'list', '-R', `${ url }/${ path }` ).split( '\n' ).filter( Boolean ).sort();
const cat = ( path ) => svn( 'cat', `${ url }/${ path }` );

before( () => {
	if ( ! haveSvn ) {
		return;
	}
	root = mkdtempSync( join( tmpdir(), 'glaciate-svn-test-' ) );
	execFileSync( 'svnadmin', [ 'create', join( root, 'repo' ) ] );
	url = `file://${ join( root, 'repo' ) }`;
	svn( 'mkdir', '-q', '-m', 'Layout', `${ url }/trunk`, `${ url }/tags`, `${ url }/assets` );
} );

after( () => root && rmSync( root, { recursive: true, force: true } ) );

test( 'a release fills trunk, tags the version and adds assets with image types', { skip }, () => {
	const dir = tree( join( root, 'p1' ), plugin( '1.0.0', { 'src/A.php': 'a', 'src/Old/B.php': 'b', 'vendor/x.so': 'kept' } ) );
	const assets = tree( join( root, 'a1' ), { 'icon-256x256.png': 'png', 'banner-772x250.jpg': 'jpg' } );
	const result = publish( 'release', url, '1.0.0', dir, assets );
	assert.equal( result.status, 0, result.out );
	assert.match( result.out, /Committed "Release 1\.0\.0"/ );
	const files = [ 'glaciate.php', 'readme.txt', 'src/', 'src/A.php', 'src/Old/', 'src/Old/B.php', 'vendor/', 'vendor/x.so' ];
	assert.deepEqual( list( 'trunk' ), files, 'Names svn ignores by default are still published.' );
	assert.deepEqual( list( 'tags/1.0.0' ), files );
	assert.equal( svn( 'propget', 'svn:mime-type', `${ url }/assets/icon-256x256.png` ).trim(), 'image/png' );
	assert.equal( svn( 'propget', 'svn:mime-type', `${ url }/assets/banner-772x250.jpg` ).trim(), 'image/jpeg' );
} );

test( 'the next release removes what the ZIP no longer has and keeps earlier tags as they were', { skip }, () => {
	const dir = tree( join( root, 'p2' ), plugin( '1.1.0', { 'src/A.php': 'a2', 'src/with space@2x.php': 'c' } ) );
	const result = publish( 'release', url, '1.1.0', dir );
	assert.equal( result.status, 0, result.out );
	assert.deepEqual( list( 'trunk' ), [ 'glaciate.php', 'readme.txt', 'src/', 'src/A.php', 'src/with space@2x.php' ] );
	assert.equal( cat( 'trunk/src/A.php' ), 'a2' );
	assert.equal( cat( 'tags/1.0.0/src/A.php' ), 'a' );
	assert.ok( list( 'assets' ).includes( 'icon-256x256.png' ), 'Without an assets folder, assets/ is left alone.' );
} );

test( 'a version is never published twice, and mismatched versions are refused', { skip }, () => {
	let result = publish( 'release', url, '1.1.0', join( root, 'p2' ) );
	assert.equal( result.status, 1 );
	assert.match( result.out, /tags\/1\.1\.0 already exists/ );

	result = publish( 'release', url, '1.2.0', join( root, 'p2' ) );
	assert.equal( result.status, 1 );
	assert.match( result.out, /Version 1\.1\.0, not 1\.2\.0/ );

	result = publish( 'release', url, 'v1.2.0', join( root, 'p2' ) );
	assert.match( result.out, /isn't a release version/ );
} );

test( 'meta updates the readme in trunk and the stable tag, without touching code', { skip }, () => {
	const readme = join( root, 'readme.txt' );
	writeFileSync( readme, '=== Glaciate ===\nTested up to: 7.2\nStable tag: 1.1.0\n' );
	const assets = tree( join( root, 'a2' ), { 'icon-256x256.png': 'png2', 'screenshot-1.png': 's' } );
	const result = publish( 'meta', url, readme, assets );
	assert.equal( result.status, 0, result.out );
	assert.match( cat( 'trunk/readme.txt' ), /Tested up to: 7\.2/ );
	assert.match( cat( 'tags/1.1.0/readme.txt' ), /Tested up to: 7\.2/ );
	assert.match( cat( 'tags/1.0.0/readme.txt' ), /Tested up to: 7\.1/ );
	assert.equal( cat( 'trunk/src/A.php' ), 'a2' );
	assert.deepEqual( list( 'assets' ), [ 'icon-256x256.png', 'screenshot-1.png' ], 'Assets are mirrored.' );
} );

test( 'a dry run lists the changes and commits nothing', { skip }, () => {
	const readme = join( root, 'readme.txt' );
	writeFileSync( readme, '=== Glaciate ===\nTested up to: 7.3\nStable tag: 1.1.0\n' );
	const env = { ...process.env, SVN_DRY_RUN: '1' };
	const result = spawnSync( SCRIPT, [ 'meta', url, readme ], { env, encoding: 'utf8' } );
	assert.equal( result.status, 0, result.stderr );
	assert.match( result.stdout, /Dry run; would commit/ );
	assert.match( result.stdout, /^M .*trunk\/readme\.txt$/m );
	assert.match( cat( 'trunk/readme.txt' ), /Tested up to: 7\.2/ );
} );

test( 'meta refuses a readme whose Stable tag is not the released one', { skip }, () => {
	const readme = join( root, 'readme.txt' );
	writeFileSync( readme, '=== Glaciate ===\nStable tag: 9.9.9\n' );
	let result = publish( 'meta', url, readme );
	assert.equal( result.status, 1 );
	assert.match( result.out, /tags\/9\.9\.9 doesn't exist/ );

	writeFileSync( readme, '=== Glaciate ===\nStable tag: 1.0.0\n' );
	result = publish( 'meta', url, readme );
	assert.equal( result.status, 1 );
	assert.match( result.out, /different Stable tag/ );
	assert.match( readFileSync( readme, 'utf8' ), /1\.0\.0/ );
} );
