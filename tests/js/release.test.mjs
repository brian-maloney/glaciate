/**
 * Release helpers: version agreement, changelog extraction and the Plugin Check gate (bin/release.mjs).
 *
 * @package Stillpress
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { changelogSection, parseReport, unexpectedErrors, versionProblems } from '../../bin/release.mjs';

const plugin = ( version, constant = version ) => ` * Plugin Name: Stillpress\n * Version:           ${ version }\n */\ndefine( 'STILLPRESS_VERSION', '${ constant }' );\n`;
const readme = ( stable, entries ) =>
	`=== Stillpress ===\nStable tag: ${ stable }\n\n== Changelog ==\n\n${ entries }\n\n== Privacy ==\n\n= 9.9.9 =\nNot a changelog.\n`;

test( 'a tag releases when every version agrees and it has a changelog entry', () => {
	assert.deepEqual( versionProblems( '1.2.3', plugin( '1.2.3' ), readme( '1.2.3', '= 1.2.3 =\n* Fix: x.' ) ), [] );
} );

test( 'each disagreeing version is named', () => {
	const problems = versionProblems( '1.2.3', plugin( '1.2.3', '1.2.2' ), readme( '1.2.2', '= 1.2.2 =\n* Old.' ) );
	assert.equal( problems.length, 3 );
	assert.match( problems[ 0 ], /STILLPRESS_VERSION is 1\.2\.2/ );
	assert.match( problems[ 1 ], /Stable tag in readme\.txt is 1\.2\.2/ );
	assert.match( problems[ 2 ], /no changelog entry "= 1\.2\.3 ="/ );
} );

test( 'tags that are not x.y.z are refused', () => {
	assert.match( versionProblems( 'v1.2.3', plugin( 'v1.2.3' ), readme( 'v1.2.3', '= v1.2.3 =\n* x' ) )[ 0 ], /isn't a release version/ );
} );

test( 'the changelog section stops at the next version and ignores other sections', () => {
	const text = readme( '2.0.0', '= 2.0.0 =\n* New: a.\n* Fix: b.\n\n= 1.0.0 =\n* First.' );
	assert.equal( changelogSection( text, '2.0.0' ), '* New: a.\n* Fix: b.' );
	assert.equal( changelogSection( text, '1.0.0' ), '* First.' );
	assert.equal( changelogSection( text, '9.9.9' ), null, 'Headings outside the changelog are not versions.' );
} );

test( 'the real readme has an entry for its stable tag', () => {
	const real = readFileSync( new URL( '../../readme.txt', import.meta.url ), 'utf8' );
	const stable = real.match( /^Stable tag:\s*(\S+)/m )[ 1 ];
	assert.ok( changelogSection( real, stable ) );
} );

const report = [
	'FILE: src/A.php',
	'[{"line":3,"column":1,"type":"ERROR","code":"X.Offloading","message":"m"},{"line":9,"column":1,"type":"WARNING","code":"Y","message":"w"}]',
	'',
	'FILE: src/B.php',
	'[{"line":5,"column":1,"type":"ERROR","code":"X.Offloading","message":"m"}]',
	'',
].join( '\n' );

test( 'reports parse into findings; a clean run is an empty list; anything else is not a report', () => {
	const findings = parseReport( report );
	assert.deepEqual( findings.map( ( f ) => `${ f.type } ${ f.file }:${ f.line }` ), [ 'ERROR src/A.php:3', 'WARNING src/A.php:9', 'ERROR src/B.php:5' ] );
	assert.deepEqual( parseReport( 'Success: Checks complete. No errors found.\n' ), [] );
	assert.equal( parseReport( 'Error: Plugin not found.\n' ), null, 'A failed run must not pass the gate.' );
} );

test( 'allowed errors are matched by file, code and count; warnings never fail', () => {
	const findings = parseReport( report );
	assert.deepEqual( unexpectedErrors( findings, [ { file: 'src/A.php', code: 'X.Offloading', count: 1 } ] ).map( ( f ) => f.file ), [ 'src/B.php' ] );
	assert.deepEqual(
		unexpectedErrors( findings, [ { file: 'src/A.php', code: 'X.Offloading', count: 1 }, { file: 'src/B.php', code: 'X.Offloading', count: 1 } ] ),
		[]
	);
	const twice = parseReport( report.replace( 'src/B.php', 'src/A.php' ) );
	assert.equal( unexpectedErrors( twice, [ { file: 'src/A.php', code: 'X.Offloading', count: 1 } ] ).length, 1, 'A second error in an allowed file fails.' );
} );
