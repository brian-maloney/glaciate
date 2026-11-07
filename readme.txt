=== Glaciate ===
Contributors: brianmaloney
Tags: static site, static html, performance, security, export
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your WordPress site as fast, secure static files. Every feature is free.

== Description ==

Glaciate turns your WordPress site into plain HTML, CSS and images that any web host can serve, without PHP or a database. You keep writing in WordPress; visitors get a static copy that is fast, cheap to host and hard to break.

* **Works with your theme and plugins.** Pages are rendered by your own site, exactly as a visitor sees them, so block themes, classic themes and page builders all work.
* **Only what changed is rebuilt.** While a page renders, Glaciate records which posts, terms, authors and settings it used. Edit a post, and only the pages that show it are rebuilt and redeployed.
* **One choice to get started.** Activate, pick where the static site goes, and publish.
* **Publishes where you host.** A folder on your server, a ZIP file, Amazon S3 or any S3-compatible storage (with CloudFront invalidation), another server over SFTP, a Git repository for GitHub Pages, GitLab Pages and similar hosts, Netlify, or Cloudflare. Each target is configured on the settings screen or from WP-CLI, and credentials are stored encrypted and never shown again.
* **Publishes when you want.** Right after you save, at set times of day, or only when you click Publish. A full rebuild runs nightly (or weekly, or never) at a time you choose.
* **Forms keep working.** Contact Form 7, WPForms, Gravity Forms and comment forms on the static site send their submissions back to WordPress, where the form plugin handles them as usual. WordPress can receive them itself, or a small receiver you put on any PHP host, Cloudflare or Netlify can pass them on, so WordPress can stay private. Spam is held off with a hidden field, a minimum time on the page and a limit per visitor, plus an optional captcha.
* **Search keeps working.** Your search forms search the static copy in the visitor's browser, using an index published with the site. No server, no outside service.
* **Feels like WordPress.** A "Publish to static" button in the editor, a status in the admin bar, a Site Health check, and a WP-CLI command for everything.
* **No upsells.** There is no premium version, no license key and no tracking. The plugin contacts no outside service except the deploy target you choose.

Links, images, srcset, stylesheets, scripts, structured data, feeds and sitemaps are all rewritten to your public address. A 404 page, a `_redirects` file and a `_headers` file (so feeds keep their type on Netlify and Cloudflare) are generated for you.

= For developers =

Every stage has actions and filters ([list of hooks](https://github.com/brian-maloney/glaciate/blob/main/docs/HOOKS.md)), and deploy targets are pluggable through a small interface. All features are available from WP-CLI: `wp glaciate build`, `status`, `why`, `deploy`, `zip`, `settings`, `target`, `secret`, `schedule`, `forms`, `doctor` and more.

== Installation ==

1. Install and activate the plugin.
2. Go to **Static Site** in the admin menu.
3. Choose where the static site should go and click **Publish my site**.

Your site needs pretty permalinks (anything but "Plain" under Settings → Permalinks).

If your server can't reach its own address (common behind proxies and in containers), set `define( 'GLACIATE_CONNECT_TO', '127.0.0.1:80' );` in wp-config.php to an address that reaches the web server.

== Frequently Asked Questions ==

= Do I need to pay for anything? =

No. Every feature is free and stays free.

= Does the plugin send data anywhere? =

No. It makes requests to your own site, to render pages, and to the deploy target you configure (for example your S3 bucket or Netlify site), to publish. Nothing else, and nothing until you set a target up.

= How are deploy credentials stored? =

Encrypted in the database, with a key derived from the security keys in wp-config.php, and never displayed again. You can also set any of them with a constant or an environment variable (for example `GLACIATE_S3_SECRET_ACCESS_KEY`) so they are never stored at all. If the security keys change, saved credentials can no longer be read; Site Health says which ones to enter again.

= Can it replace files that aren't its own? =

No. On a folder, SFTP server or S3 bucket it only deletes files it published there itself, and a folder must be empty or marked as its own first. A Git branch or Cloudflare Worker that already has content from elsewhere is refused unless you allow replacing it.

= Does search work on the static site? =

Yes. Glaciate publishes a search index with your site, and your theme's search forms open a results window that searches it in the browser. Results include every published page (title and text from the page itself), and the index is updated along with the pages that change. You can turn it off under Settings → Search.

= Can I use Pagefind instead? =

Yes, if your host builds the site: a GitHub Actions workflow on the Git target, Netlify or Cloudflare Pages. Add `npx pagefind --site .` to that build and choose "Pagefind, run by your host's build step" under Settings → Search. Glaciate doesn't include or run Pagefind, because it is a separate program. Your search forms keep the same results window. Themes can choose what is searchable by marking elements with `data-glaciate-search`, or leave parts out with `data-glaciate-search-ignore`.

= Do forms and comments work on the static site? =

Yes, once you turn on Forms under Settings → Forms. A static host can't run them, so Glaciate points them at a receiver:

* **This site**, if your WordPress site is reachable from the internet at its own address. Nothing else to install.
* **A receiver you run elsewhere**, if WordPress is private. Download it from the settings screen (or `wp glaciate forms receiver php|cloudflare|netlify`): a single PHP file for any PHP host, a Cloudflare Worker or a Netlify Function. It checks each submission and forwards it to your site, signed with a secret you set on both sides. It can also send submissions to a webhook, or (the PHP file) by email, without WordPress.

Contact Form 7, WPForms, Gravity Forms and the comment form are recognized automatically, and each submission is handled by that plugin as if it had been sent on your site: its validation, emails and stored entries work as usual. Other forms work if your theme marks them with `data-glaciate-form="name"`; their submissions are emailed to you. File uploads aren't supported.

= How are forms protected from spam? =

Every form gets a hidden field that people don't see and bots fill in, the time the page was open is checked (bots are quicker than people), each visitor can send five forms in ten minutes, and submissions must come from your static site. For more protection choose Cloudflare Turnstile or hCaptcha; these are outside services, so they are off unless you pick one.

= Which pages are left out? =

Drafts, private posts and password-protected posts are never published. You can leave out more addresses in Settings.

== Changelog ==

= 1.1.1 =
* Changed: the search option "Pagefind, run on this server" is removed, so the plugin never starts a program on your server. Sites that used it switch to the built-in search, the whole site is rebuilt once to build its index, and the old Pagefind files are removed from the static site. "Pagefind, run by your host's build step" is unchanged.

= 1.1.0 =
* New: the Publish page shows what's waiting to be published: the edits made since the last publish and the pages they will rebuild. Also `wp glaciate changes preview` and the REST route `glaciate/v1/changes`.
* New: Settings → Advanced → "Link depth limit" (100 by default). A crawler trap such as a calendar with a "next month" link on every page now stops there, instead of filling the build with addresses until the address limit. The build log says when the limit is reached.
* New: when the site answers that it is overloaded or in maintenance (HTTP 429, 500, 502, 503 or 504), the build waits, as long as the site's Retry-After asks (up to 5 minutes), tries the page again, and continues with half as many requests at once. A page fails only on its third try.
* New: favicon.ico is published. WordPress redirects it to your site icon (or its own logo), which a static host can't do, so browsers got a 404. It is now a copy of that icon.
* New: Site Health checks the PHP extensions Glaciate needs (cURL, ZIP) and your public address (a private address, http on an https site, or a separate WordPress address). The stored credentials check now shows in Site Health too, not only in `wp glaciate doctor`.
* New: the build log lists links that were skipped because they have a query string, which a static host can't serve.
* Fix: pages whose inline scripts carry security tokens (nonces), which WordPress and many plugins add, were redeployed after every full rebuild even when nothing on them had changed, because those tokens change every twelve hours. They are now left empty in the static copy, where there is nothing to send them to. The glaciate_strip_nonces filter turns this off.
* Fix: a redirect from an address that isn't an HTML page (such as /old.pdf) no longer publishes an HTML page at that address; it is listed in `_redirects` only.
* Fix: a feed with no posts left (all unpublished or deleted) takes its "last updated" date from the newest change to any post or page, but editing a page didn't update that date in the published feed until the next full rebuild.

= 1.0.3 =
* New: Settings → "Other addresses of this site". If your site is edited at one address while the live site keeps another (for example edit.example.org and www.example.org), list the live address there. Links and images that point at it are then published and rewritten to your public address, instead of being skipped and left pointing at the old server. Nothing is downloaded from those addresses.
* Fix: pages linked to the comments feed and to post, author and category feeds even when the Feeds setting didn't publish them, so those links returned 404 on the static site. Pages now link only to the feeds that are published.

= 1.0.2 =
* Fix: when the site's own address used a self-signed or otherwise untrusted HTTPS certificate, every page failed to build with "SSL certificate problem". Requests to the site itself now skip certificate checks, as WordPress's own loopback requests do. If a plugin turns those checks on with the https_local_ssl_verify filter, the error now says so.

= 1.0.1 =
* Fix: before the first publish, the Publish screen offered only a folder or a ZIP file, even when another destination was set up in Settings, and publishing from it switched the site to the folder. It now offers the destination from Settings, already selected.

= 1.0.0 =
* Large sites: tested with 100,000 addresses. Paginated archives are listed from the database instead of found one link at a time, the build queue no longer slows down as it grows, and links are looked up a batch at a time.
* Incremental builds rebuild far less: editing a post's text or title no longer rebuilds every archive page and every page that shows its categories.
* The address limit now counts only pages found by following links, on top of everything listed from the database, and files such as images don't count (default 100,000).
* Translations: the build log, deploy messages and the static search's result count (in every plural form) are translatable; admin styles follow right-to-left languages.
* Accessibility: the search dialog and form answers announce reliably to screen readers, settings fields carry their help text, and publishing is announced when it starts and finishes.
* The standalone form receiver is stored as a text template inside the plugin, so it can't run on the WordPress site.

Versions before 1.0.0 were development milestones and weren't published.

= 0.4.0 =
* Forms on the static site: Contact Form 7, WPForms, Gravity Forms, comments and forms marked with `data-glaciate-form` post to a receiver, and WordPress hands each submission to the form plugin. Answers and field errors appear in the form; without JavaScript a short page answers.
* Receivers: this WordPress site, or a standalone PHP file, Cloudflare Worker or Netlify Function that forwards signed submissions to WordPress (or to a webhook, or by email).
* Spam protection: honeypot, minimum time on the page, per-visitor rate limit, origin check, and optional Cloudflare Turnstile or hCaptcha. A signed webhook can get a copy of every submission.

= 0.3.0 =
* Static search: an index of every published page is built with the site and searched in the browser. Search forms and `?s=` links open a results window; only the index files a query needs are downloaded, and only the parts of the index that changed are redeployed.
* Optional Pagefind search: run a Pagefind program you install (named in wp-config.php), or let your host's build step run it.

= 0.2.0 =
* Remote deploy targets: Amazon S3 and S3-compatible storage with CloudFront invalidation, SFTP, Git over HTTPS (GitHub Pages, GitLab Pages and others), Netlify, and Cloudflare (Workers static assets). Large deploys resume across background runs.
* Encrypted storage for deploy credentials, with constant and environment-variable overrides, and settings panels for each target, including a connection test.
* Publish at set times, right after changes, or only by hand; full rebuilds at a chosen time and weekday, kept on the local clock across daylight-saving changes.
* Feeds are saved as feed/index.html so /feed/ works on static hosts, with their real type sent wherever the host allows.
* Fixes: redirects that started answering 404 left the _redirects file; deploy errors from WP-CLI showed a "critical error" page instead of the reason.

= 0.1.0 =
* First release: loopback renderer, URL rewriting, folder and ZIP deploys, dependency-tracked incremental builds, editor and admin bar integration, Site Health, WP-CLI.

== Privacy ==

The plugin doesn't collect or store personal data of its own. It contacts no outside service on its own: the only remote
requests go to deploy targets you configure (Amazon S3 or another S3-compatible service, CloudFront, an SFTP
server, a Git repository, Netlify, or Cloudflare), and they carry your built site and the credentials you entered for that target.

When you turn on Forms, what visitors type into forms on the static site, with their IP address and browser name, is sent
to the receiver you chose and handed to the form plugin, which may email or store it as it does on your site. Glaciate
itself keeps only a hashed IP address for ten minutes, for its rate limit. If you set up a webhook, a copy of each
submission goes there. If you choose a captcha, visitors' browsers load it from Cloudflare (Turnstile) or hCaptcha on
pages with forms, and each answer is checked with that provider along with the visitor's IP address. Mention these in
your site's privacy policy.

== External services ==

Glaciate contacts no outside service on its own. Each service below is used only when you choose it in Settings,
and only for what is described.

* **Amazon S3 and CloudFront** (Amazon Web Services), when the deploy target is Amazon S3 or an S3-compatible
  service. Each deploy lists the bucket and sends the built site's files to the S3 endpoint of the bucket's region
  (`s3.<region>.amazonaws.com`), signed with the access key you entered; with a CloudFront distribution set, it then
  asks CloudFront (`cloudfront.amazonaws.com`) to refresh the changed paths. An S3-compatible service whose endpoint
  you enter receives the same requests instead of Amazon.
  [Terms](https://aws.amazon.com/service-terms/), [privacy policy](https://aws.amazon.com/privacy/).
* **Git hosting** (for example GitHub), when the deploy target is Git. Each deploy reads the branch Glaciate publishes
  to and pushes the built site to it, with the token you entered, at the repository address you entered. For GitHub:
  [terms](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service),
  [privacy statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement).
* **Netlify**, when the deploy target is Netlify. Each deploy sends the list of the built site's files and their
  hashes, then the files Netlify doesn't have yet, to `api.netlify.com` with the access token you entered.
  [Terms](https://www.netlify.com/legal/terms-of-use/), [privacy policy](https://www.netlify.com/privacy/).
* **Cloudflare**, when the deploy target is Cloudflare. Each deploy sends the built site's file list and changed files to
  the Workers API (`api.cloudflare.com`) of the account you entered, with your API token.
  [Terms](https://www.cloudflare.com/terms/), [privacy policy](https://www.cloudflare.com/privacypolicy/).
* **Cloudflare Turnstile**, when you choose it as the form captcha. Visitors' browsers load its script from
  challenges.cloudflare.com on static pages with forms; each form answer is sent there for checking
  (`challenges.cloudflare.com/turnstile/v0/siteverify`), with the visitor's IP address, when the form is submitted.
  [Terms](https://www.cloudflare.com/website-terms/), [Turnstile privacy policy](https://www.cloudflare.com/turnstile-privacy-policy/).
* **hCaptcha**, when you choose it as the form captcha. Visitors' browsers load its script from js.hcaptcha.com on
  static pages with forms; each form answer is sent to hCaptcha for checking (`api.hcaptcha.com/siteverify`), with the
  visitor's IP address, when the form is submitted. [Terms](https://www.hcaptcha.com/terms), [privacy policy](https://www.hcaptcha.com/privacy).

A webhook address or SFTP server you enter receives what is described in Settings next to it; those are your own
services.

== Third-party libraries ==

* Action Scheduler by Automattic (GPL-3.0-or-later), used to run builds in the background.
* phpseclib (MIT), with paragonie/constant_time_encoding and paragonie/random_compat (MIT), used for SFTP deploys. They are bundled under the Glaciate\Vendor namespace, so another plugin's copy of phpseclib can't replace them.
