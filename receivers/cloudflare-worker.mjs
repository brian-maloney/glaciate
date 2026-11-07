/**
 * Glaciate form receiver as a Cloudflare Worker.
 *
 * Deploy this file as a Worker (`wrangler deploy cloudflare-worker.mjs --name glaciate-forms`), add the secrets with
 * `wrangler secret put GLACIATE_FORMS_SECRET` (and GLACIATE_FORMS_CAPTCHA_SECRET with a captcha), and set the
 * Worker's address as the receiver address in Glaciate. For a rate limit shared by all of Cloudflare's locations,
 * bind a KV namespace as GLACIATE_RATE; without it each instance limits on its own.
 *
 * `wp glaciate forms receiver cloudflare` writes this file with the settings below filled in.
 *
 * @license GPL-2.0-or-later
 */
import { handle, kvLimiter, memoryLimiter, settings } from './receiver-core.mjs'; // glaciate:import

// glaciate:config:start
const DEFAULTS = {
	allowedOrigin: '',
	forwardUrl: '',
	captcha: 'none',
	captchaSiteKey: '',
	rateLimit: 5,
	rateWindow: 600,
};
// glaciate:config:end

let localLimiter = null;

export default {
	async fetch( request, env ) {
		const config = settings( DEFAULTS, env );
		if ( ! localLimiter ) {
			localLimiter = memoryLimiter( config.rateLimit, config.rateWindow );
		}
		return handle( request, config, {
			fetch: ( url, options ) => fetch( url, options ),
			now: () => Math.floor( Date.now() / 1000 ),
			limiter: env && env.GLACIATE_RATE ? kvLimiter( env.GLACIATE_RATE, config.rateLimit, config.rateWindow ) : localLimiter,
			ip: request.headers.get( 'cf-connecting-ip' ) || '',
		} );
	},
};
