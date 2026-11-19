#!/usr/bin/env node
/**
 * Draws the WordPress.org directory artwork (icon and banner) and renders it into .wordpress-org/ (DECISIONS.md D51).
 *
 *   node tools/artwork/build.mjs
 *
 * The picture is a faceted glacier whose reflection breaks into square blocks: a site frozen into static files. It
 * has no text, so no font is involved, and the directory overlays the plugin name on the banner's lower left, which is
 * kept to flat ice. Rendering uses Playwright's Chromium (`npx playwright install chromium`), or any Chromium-based
 * browser named by CHROMIUM_PATH.
 *
 * @package Glaciate
 */

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '../..' );
const OUT = join( ROOT, '.wordpress-org' );

const SKY_TOP = '#1f5fa8';
const SKY_BOTTOM = '#0a2342';
const WATER_TOP = '#0c2b4e';
const WATER_BOTTOM = '#071a31';
const HAZE = '#3a74b5';
const ICE = { light: '#f2f8ff', mid: '#cfe5f8', dark: '#9cc6ea' };

/**
 * Mixes two #rrggbb colours.
 *
 * @param {string} a First colour.
 * @param {string} b Second colour.
 * @param {number} t Share of the second, 0 to 1.
 * @return {string} The mix.
 */
function mix( a, b, t ) {
	const channel = ( hex, i ) => parseInt( hex.slice( 1 + 2 * i, 3 + 2 * i ), 16 );
	return '#' + [ 0, 1, 2 ].map( ( i ) => Math.round( channel( a, i ) * ( 1 - t ) + channel( b, i ) * t ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

const pts = ( ...points ) => points.map( ( [ x, y ] ) => `${ +x.toFixed( 1 ) },${ +y.toFixed( 1 ) }` ).join( ' ' );

/**
 * One faceted peak: a sunlit left face, a shaded right face and a ridge facet between them.
 *
 * @param {Object} p       Peak.
 * @param {number} p.x     Apex x.
 * @param {number} p.y     Apex y.
 * @param {number} p.left  Base left x.
 * @param {number} p.right Base right x.
 * @param {number} base    Base y (the horizon).
 * @param {number} depth   0 for the front, up to about 0.6 for far peaks, which take on the sky's haze.
 * @return {string} SVG polygons.
 */
function peak( { x, y, left, right }, base, depth ) {
	const seam = left + ( right - left ) * 0.46;
	const ridge = [ x + ( right - x ) * 0.32, y + ( base - y ) * 0.42 ];
	const colour = ( c ) => mix( c, HAZE, depth );
	return [
		`<polygon points="${ pts( [ x, y ], [ left, base ], [ seam, base ] ) }" fill="${ colour( ICE.light ) }"/>`,
		`<polygon points="${ pts( [ x, y ], [ seam, base ], [ right, base ] ) }" fill="${ colour( ICE.dark ) }"/>`,
		`<polygon points="${ pts( [ x, y ], ridge, [ seam, base ] ) }" fill="${ colour( ICE.mid ) }"/>`,
	].join( '' );
}

/**
 * The reflection: rows of squares under a span of the horizon, narrower and fainter with each row.
 *
 * @param {number} left  Span left x.
 * @param {number} right Span right x.
 * @param {number} top   First row's y.
 * @param {number} size  Square size.
 * @param {number} rows  Number of rows.
 * @param {number} seed  Varies which squares are left out.
 * @return {string} SVG rects.
 */
function reflection( left, right, top, size, rows, seed ) {
	const gap = Math.round( size * 0.38 );
	const step = size + gap;
	const out = [];
	for ( let row = 0; row < rows; row++ ) {
		const inset = row * step * 0.9;
		const count = Math.floor( ( right - left - 2 * inset + gap ) / step );
		const start = ( left + right ) / 2 - ( count * step - gap ) / 2;
		for ( let i = 0; i < count; i++ ) {
			// Deterministic gaps, so every render is the same picture.
			const skip = ( ( i * 7 + row * 3 + seed ) % 11 ) === 0;
			if ( skip ) {
				continue;
			}
			const shade = ( i * 5 + row + seed ) % 3;
			const fill = [ ICE.light, ICE.mid, ICE.dark ][ shade ];
			const opacity = ( 0.55 - row * 0.16 ) * ( 1 - shade * 0.12 );
			out.push( `<rect x="${ +( start + i * step ).toFixed( 1 ) }" y="${ top + row * step }" width="${ size }" height="${ size }" rx="${ size * 0.12 }" fill="${ fill }" fill-opacity="${ opacity.toFixed( 2 ) }"/>` );
		}
	}
	return out.join( '' );
}

/**
 * A complete picture.
 *
 * @param {number}   width   Width.
 * @param {number}   height  Height.
 * @param {number}   horizon Horizon y.
 * @param {string[]} layers  SVG drawn over sky and water.
 * @param {string}   extra   SVG drawn between the sky and the layers.
 * @return {string} SVG document.
 */
function picture( width, height, horizon, layers, extra = '' ) {
	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${ width } ${ height }" width="${ width }" height="${ height }">
<defs>
<linearGradient id="sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${ SKY_TOP }"/><stop offset="1" stop-color="${ mix( SKY_BOTTOM, HAZE, 0.35 ) }"/></linearGradient>
<linearGradient id="water" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${ WATER_TOP }"/><stop offset="1" stop-color="${ WATER_BOTTOM }"/></linearGradient>
</defs>
<rect width="${ width }" height="${ horizon }" fill="url(#sky)"/>
<rect y="${ horizon }" width="${ width }" height="${ height - horizon }" fill="url(#water)"/>
${ extra }${ layers.join( '\n' ) }
</svg>
`;
}

function icon() {
	const horizon = 180;
	return picture( 256, 256, horizon, [
		peak( { x: 82, y: 98, left: 18, right: 132 }, horizon, 0.45 ),
		peak( { x: 142, y: 46, left: 50, right: 230 }, horizon, 0 ),
		reflection( 50, 230, horizon + 12, 18, 3, 2 ),
	] );
}

function banner() {
	const horizon = 330;
	const far = [
		{ x: 700, y: 196, left: 560, right: 840 },
		{ x: 1180, y: 150, left: 1040, right: 1330 },
		{ x: 1530, y: 160, left: 1400, right: 1660 },
	];
	const near = [
		{ x: 560, y: 236, left: 450, right: 690 },
		{ x: 860, y: 104, left: 660, right: 1060 },
		{ x: 1330, y: 76, left: 1110, right: 1520 },
	];
	// A few faint stars, placed by hand so the picture never changes between renders.
	const stars = [ [ 140, 60 ], [ 260, 128 ], [ 410, 44 ], [ 610, 92 ], [ 1010, 40 ], [ 1090, 118 ], [ 1450, 52 ], [ 1240, 30 ], [ 330, 210 ], [ 90, 170 ] ]
		.map( ( [ x, y ], i ) => `<circle cx="${ x }" cy="${ y }" r="${ 1.6 + ( i % 3 ) * 0.6 }" fill="#ffffff" fill-opacity="${ 0.35 + ( i % 2 ) * 0.2 }"/>` )
		.join( '' );
	return picture( 1544, 500, horizon, [
		...far.map( ( p ) => peak( p, horizon, 0.55 ) ),
		...near.map( ( p ) => peak( p, horizon, 0 ) ),
		reflection( 670, 1050, horizon + 18, 22, 4, 1 ),
		reflection( 1120, 1510, horizon + 18, 22, 4, 4 ),
		reflection( 460, 680, horizon + 18, 22, 2, 7 ),
	], stars );
}

/**
 * Renders an SVG to a PNG of the given size.
 *
 * @param {import('playwright').Page} page   Browser page.
 * @param {string}                    svg    SVG document.
 * @param {number}                    width  Output width.
 * @param {number}                    height Output height.
 * @param {string}                    file   Output path.
 */
async function render( page, svg, width, height, file ) {
	// Drawn onto a canvas of exactly the output size, so the window size of a headless browser plays no part.
	const data = await page.evaluate( async ( [ source, w, h ] ) => {
		const image = new Image();
		image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( source );
		await image.decode();
		const canvas = document.createElement( 'canvas' );
		canvas.width = w;
		canvas.height = h;
		canvas.getContext( '2d' ).drawImage( image, 0, 0, w, h );
		return canvas.toDataURL( 'image/png' );
	}, [ svg, width, height ] );
	writeFileSync( file, Buffer.from( data.split( ',' )[ 1 ], 'base64' ) );
	console.log( file.replace( ROOT + '/', '' ) );
}

mkdirSync( OUT, { recursive: true } );
const iconSvg = icon();
const bannerSvg = banner();
writeFileSync( join( OUT, 'icon.svg' ), iconSvg );

const browser = await chromium.launch( process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {} );
const page = await browser.newPage();
await render( page, iconSvg, 128, 128, join( OUT, 'icon-128x128.png' ) );
await render( page, iconSvg, 256, 256, join( OUT, 'icon-256x256.png' ) );
await render( page, bannerSvg, 772, 250, join( OUT, 'banner-772x250.png' ) );
await render( page, bannerSvg, 1544, 500, join( OUT, 'banner-1544x500.png' ) );
await browser.close();
