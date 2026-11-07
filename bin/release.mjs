#!/usr/bin/env node
/**
 * Release helpers for .github/workflows/release.yml (DECISIONS.md D40). No dependencies, so it runs on the host.
 *
 * Run from the root of the tree being released.
 *
 *   node bin/release.mjs check-version <tag>      Tag, plugin header, GLACIATE_VERSION and Stable tag must agree.
 *   node bin/release.mjs notes <version>          Prints that version's changelog section from readme.txt as Markdown.
 *   node bin/release.mjs gate <plugin-check.txt>  Fails on Plugin Check errors not in bin/plugin-check-allowed.json.
 *
 * @package Glaciate
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// The tree being released: the current folder, so the workflow can run these tools from its own commit over an older
// tag's checkout. The allowlist belongs to the tools and is read next to this script.
const ROOT = process.cwd();
const TOOLS = join( dirname( fileURLToPath( import.meta.url ) ), '..' );

/**
 * Versions the release files declare.
 *
 * @param {string} plugin Contents of glaciate.php.
 * @param {string} readme Contents of readme.txt.
 * @return {{header: ?string, constant: ?string, stable: ?string}} Each version, or null when missing.
 */
export function declaredVersions( plugin, readme ) {
	const find = ( text, pattern ) => ( text.match( pattern ) || [] )[ 1 ] || null;
	return {
		header: find( plugin, /^ \* Version:\s*(\S+)\s*$/m ),
		constant: find( plugin, /define\(\s*'GLACIATE_VERSION',\s*'([^']+)'\s*\)/ ),
		stable: find( readme, /^Stable tag:\s*(\S+)\s*$/m ),
	};
}

/**
 * Problems with releasing a tag, or an empty list.
 *
 * @param {string} tag    Git tag, e.g. 1.1.1.
 * @param {string} plugin Contents of glaciate.php.
 * @param {string} readme Contents of readme.txt.
 * @return {string[]} Problems.
 */
export function versionProblems( tag, plugin, readme ) {
	const problems = [];
	if ( ! /^\d+\.\d+\.\d+$/.test( tag ) ) {
		problems.push( `Tag "${ tag }" isn't a release version (x.y.z).` );
	}
	const versions = declaredVersions( plugin, readme );
	const labels = { header: 'Version header in glaciate.php', constant: 'GLACIATE_VERSION', stable: 'Stable tag in readme.txt' };
	for ( const [ key, label ] of Object.entries( labels ) ) {
		if ( versions[ key ] !== tag ) {
			problems.push( `${ label } is ${ versions[ key ] ?? 'missing' }, not ${ tag }.` );
		}
	}
	if ( null === changelogSection( readme, tag ) ) {
		problems.push( `readme.txt has no changelog entry "= ${ tag } =".` );
	}
	return problems;
}

/**
 * One version's changelog entries from readme.txt, or null when there is none.
 *
 * @param {string} readme  Contents of readme.txt.
 * @param {string} version Version.
 * @return {?string} The section's lines, trimmed.
 */
export function changelogSection( readme, version ) {
	const changelog = readme.split( /^== Changelog ==\s*$/m )[ 1 ];
	if ( undefined === changelog ) {
		return null;
	}
	const body = changelog.split( /^== .+ ==\s*$/m )[ 0 ];
	const parts = body.split( /^= (.+?) =\s*$/m );
	for ( let i = 1; i < parts.length; i += 2 ) {
		if ( parts[ i ].trim() === version ) {
			const text = parts[ i + 1 ].trim();
			return '' === text ? null : text;
		}
	}
	return null;
}

/**
 * Findings from `wp plugin check --format=json`: "FILE: <path>" lines, each followed by a JSON array.
 *
 * @param {string} report Plugin Check output.
 * @return {?Array<{file: string, line: number, type: string, code: string, message: string}>} Findings, or null when
 *                                                                                              the output isn't a report.
 */
export function parseReport( report ) {
	if ( /Success: Checks complete\. No errors found\./.test( report ) ) {
		return [];
	}
	const findings = [];
	const pattern = /^FILE: (.+)\n(\[.*\])\s*$/gm;
	let match;
	while ( null !== ( match = pattern.exec( report ) ) ) {
		for ( const item of JSON.parse( match[ 2 ] ) ) {
			findings.push( { file: match[ 1 ].trim(), line: item.line, type: item.type, code: item.code, message: item.message } );
		}
	}
	return findings.length ? findings : null;
}

/**
 * Errors that the allowlist doesn't cover. An entry allows up to `count` errors with that code in that file, so a
 * new finding of the same kind elsewhere, or a second one in the same file, still fails.
 *
 * @param {Array<{file: string, type: string, code: string}>} findings Findings.
 * @param {Array<{file: string, code: string, count: number}>} allowed  Allowlist.
 * @return {Array<Object>} Errors not allowed.
 */
export function unexpectedErrors( findings, allowed ) {
	const left = new Map( allowed.map( ( entry ) => [ `${ entry.file }\n${ entry.code }`, entry.count ] ) );
	return findings.filter( ( finding ) => {
		if ( 'ERROR' !== finding.type ) {
			return false;
		}
		const key = `${ finding.file }\n${ finding.code }`;
		const count = left.get( key ) ?? 0;
		if ( count > 0 ) {
			left.set( key, count - 1 );
			return false;
		}
		return true;
	} );
}

const [ command, argument ] = process.argv.slice( 2 );
if ( fileURLToPath( import.meta.url ) === process.argv[ 1 ] ) {
	const readme = () => readFileSync( join( ROOT, 'readme.txt' ), 'utf8' );
	if ( 'check-version' === command && argument ) {
		const problems = versionProblems( argument, readFileSync( join( ROOT, 'glaciate.php' ), 'utf8' ), readme() );
		problems.forEach( ( problem ) => console.error( problem ) );
		if ( problems.length ) {
			process.exit( 1 );
		}
		console.log( `Release ${ argument }: versions agree.` );
	} else if ( 'notes' === command && argument ) {
		const section = changelogSection( readme(), argument );
		if ( null === section ) {
			console.error( `No changelog entry for ${ argument } in readme.txt.` );
			process.exit( 1 );
		}
		console.log( section );
	} else if ( 'gate' === command && argument ) {
		const findings = parseReport( readFileSync( argument, 'utf8' ) );
		if ( null === findings ) {
			console.error( `${ argument } isn't a Plugin Check report.` );
			process.exit( 1 );
		}
		const allowed = JSON.parse( readFileSync( join( TOOLS, 'bin/plugin-check-allowed.json' ), 'utf8' ) ).allowed;
		for ( const finding of findings ) {
			console.log( `${ finding.type } ${ finding.file }:${ finding.line } ${ finding.code }` );
		}
		const errors = unexpectedErrors( findings, allowed );
		if ( errors.length ) {
			console.error( `\n${ errors.length } Plugin Check error(s) not in bin/plugin-check-allowed.json:` );
			errors.forEach( ( error ) => console.error( `  ${ error.file }:${ error.line } ${ error.code }: ${ error.message }` ) );
			process.exit( 1 );
		}
		console.log( `\nPlugin Check: ${ findings.length } finding(s), no unexpected errors.` );
	} else {
		console.error( 'Usage: node bin/release.mjs check-version <tag> | notes <version> | gate <plugin-check-report>' );
		process.exit( 2 );
	}
}
