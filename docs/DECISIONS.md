# Decisions

A running log of non-obvious choices. Newest at the bottom. Each entry says what we chose, why, and what would
make us revisit it.

## D1. Support WordPress 6.6+ and PHP 7.4+

WordPress 7.0 raised core's own floor to PHP 7.4, and Action Scheduler requires 7.2 and WordPress 6.5. WordPress 6.6
moved the editor SlotFills into `@wordpress/editor`, so one integration covers the post and site editors. 6.6 is about
two years of releases back from 7.1. Revisit when WordPress raises its PHP floor.

## D2. Bundle Action Scheduler (GPL-3.0-or-later) for background work

It survives timeouts, retries failed actions, and resolves multiple bundled copies by version. Our own code stays
GPL-2.0-or-later. Because GPLv3 code is bundled, the distributed plugin as a whole is effectively under GPLv3 terms,
which WordPress.org accepts. A `QueueInterface` keeps a WP-Cron fallback possible.

## D3. Rewrite URLs as text, not through a DOM

A DOM rewrite misses inline JavaScript, JSON-LD, JSON-escaped URLs in data attributes, and CSS. A single boundary-aware
text replacement over every encoded form of the origin covers all of them, and works the same for HTML, XML feeds,
sitemaps and CSS. Link *discovery* does use `WP_HTML_Tag_Processor`, because there we need attribute structure.

## D4. Dependency tracking is a conservative over-approximation

Exact tracking can't be done (direct `$wpdb`, transients, external APIs, time). We record objects, query signatures
and options, mark URLs with unknown dependencies as volatile, run a scheduled full rebuild (setting: off/daily/weekly,
default daily), and require differential tests to show incremental output equals full-rebuild output.

## D5. Development runs entirely in Docker through wp-env

The host has no PHP. PHP, Composer, PHPUnit, PHPCS and WP-CLI all run in the wp-env containers. Composer's platform is
pinned to PHP 7.4 so resolved dependencies stay installable on the lowest supported version.

wp-env 12 deprecates its built-in tests environment in favor of separate config files. It still works and is what
`npm run test:integration` uses; move to a `--config` file if it's removed.

## D6. PHPUnit 9.6 with Yoast polyfills 2.x

PHPUnit 9.6 is the newest line that runs on PHP 7.4 and is supported by the WordPress test suite. The container also
ships PHPUnit 10; we always run the Composer-installed `vendor/bin/phpunit` so results don't depend on the image.

## D7. Loopback spike results (WordPress 7.1.3, PHP 8.3, wp-env)

Tested before writing the renderer:

- **The site often can't reach its own public URL.** From the wp-env CLI container, `http://localhost:8888` fails with
  "connection refused", because the port is published on the host, not inside the container. The web container can reach
  it. WP-CLI builds therefore need the connect-to pin. `CURLOPT_CONNECT_TO` (`localhost:8888:wordpress:8888`) keeps the URL,
  Host header and TLS SNI unchanged while changing where the socket connects. We use it rather than `CURLOPT_RESOLVE`,
  because it can change the port too.
- **The pin must be attached in two places.** `wp_remote_request` goes through `WP_Http`, which fires the `http_api_curl`
  action. `WpOrg\Requests\Requests::request_multiple` (used for concurrent renders) bypasses `WP_Http`, so the pin goes in
  a Requests `curl.before_request` hook. That hook fires for single and multi handles alike; `curl.before_send` doesn't
  fire for multi requests.
- **The render header round trip works**: a request header reaches the render, which can send back a response header.
- **`pre_option` captures every option read**, including `_transient_*` and `_site_transient_*` rows and Action Scheduler
  internals. Those must be filtered out as dependency triggers. The home page of Twenty Twenty-Five read about 80 options.
- **`the_posts` catches block-theme database objects** (here a `wp_navigation` post), so menus, synced patterns and
  customized templates become post dependencies without special handling.
- **Concurrent requests work**: four renders via `request_multiple` in about 0.03 s on this setup.
- Unpinned and shortlink URLs (`/?p=1`, `/hello-world/`) come back as 301s to the canonical permalink. With `follow_redirects` off,
  the crawler records them as redirects.

## D8. URL rewriting: absolute URLs only in Milestone 1

Every encoded form of the origin (raw, JSON `\/`, JavaScript `/`, URL-encoded, HTML entities; with or without a scheme)
is rewritten to the public base URL, spelled in the same encoding. Root-relative output is deferred: deciding where a
relative URL is safe (it isn't in canonical links, Open Graph tags, feeds, sitemaps or share links) needs context the textual
rewriter doesn't have. Absolute URLs work on any host once the public URL is set.

When home is in a subdirectory (`https://origin/blog`), paths under it map to the public base path and paths outside it
(`/wp-includes/…`) keep their path on the public host. When the public base has a path (`https://example.org/static`),
files outside it can't be placed in the output root and are reported instead of written.

Protocol-relative matches (`//host`) must not follow a URL character, so `https://elsewhere//origin/x` isn't touched.

## D9. Direct file operations instead of WP_Filesystem for build output

The build cache, ZIP archives and local deploy targets are written with PHP's file functions (atomic temp file + rename).
WP_Filesystem exists so plugins can write into WordPress's own code directories through FTP credentials when the web server
user can't. We never write there, and a site build means thousands of files, where the abstraction costs time and gives
no safety. All paths still go through `PathMapper`, and targets through `TargetPolicy`. Files are created with the process
umask, like core's uploads.

## D10. A page stays published while the origin still serves it

Full builds re-render every URL that is currently published, then crawl from the seeds. A URL leaves the static site only
when the origin answers 404/410 (or it starts redirecting). We don't garbage-collect pages that merely stopped being linked:
WordPress still serves them, so removing them would break inbound links and bookmarks. This also gives the differential
test a precise target: after any edits, an incremental build must produce exactly what a full build produces.

## D11. Incremental builds follow only links to new URLs

While re-rendering affected pages, an incremental build queues a discovered link only if that URL isn't published right
now. That's how a new tag archive or a newly uploaded image gets picked up without re-crawling the site. URLs that returned
404 or redirected before count as unpublished: the differential test found that a deleted post can push `/page/4/` to a
404 and a later new post can bring it back, and only re-checking such links catches that. Assets
whose files change in place keep their URL and are refreshed by the next full build. Theme and plugin updates trigger a full
build anyway (`upgrader_process_complete`).

## D12. Secrets are keyed from the wp-config salts, per site and slot

Stored deploy credentials are sealed with sodium `secretbox` under a key derived by HKDF-SHA256 from
`wp_salt('auth') . wp_salt('secure_auth')`. The salts are the only per-install secret that lives outside the database,
so a leaked database dump alone doesn't reveal credentials. `wp_salt()` (rather than reading the constants) also covers
installs whose wp-config.php still has the sample phrases, where core generates salts into the database; there the
protection against a dump is weaker, which matches what core's own cookies get. The HKDF context is
`<blog_id>|<deployer>|<field>`: secretbox has no associated data, and binding the key to the slot stops anyone who can
write options from moving a ciphertext into a field whose value is shown or sent elsewhere. Rotating the salts loses stored
secrets on purpose; Site Health and `secret list` say which ones to enter again. Constants and environment variables
always win, so hosts that inject credentials never store them at all.

## D13. Hand-written SigV4 instead of the AWS SDK

The AWS SDK for PHP 3.x requires PHP 8.1 and pulls in Guzzle 7/8, JMESPath and `aws-crt-php`. Bundling it would mean
raising Composer's platform pin (D5), turning off Composer's platform check so 7.4 sites still load, and prefixing Guzzle
(Strauss or similar) so it can't clash with other plugins' copies; S3 would then be unavailable below PHP 8.1. A static-site
deploy needs five S3 calls and one CloudFront call, so we sign requests ourselves (`Deploy\Aws\SigV4`, about 250 lines).

This is request authentication, not new cryptography: an HMAC-SHA256 over a canonical request, computed by PHP's
`hash_hmac()`, with TLS from curl. The risk is a canonicalization mistake, which fails loudly (403) rather than silently.
It is tested against AWS's SigV4 test suite (`tests/fixtures/aws4_testsuite`, Apache-2.0, from botocore's copy, with its
LICENSE and NOTICE kept) and against the four worked examples from AWS's S3 signing documentation (retrieved from an
archived copy of `sig-v4-header-based-auth.html`, since that page now redirects). S3 mode doesn't normalize paths, as AWS
requires. Not supported, on purpose: instance-profile, SSO and assume-role credential providers (use constants or
environment variables with temporary credentials and a session token instead), and chunked/streaming signatures (large files
use multipart uploads). Revisit if we raise the PHP floor to 8.1+ and want those credential providers.

## D14. SFTP through phpseclib 3.0, unprefixed, with pinned host keys

phpseclib (MIT) is the only maintained pure-PHP SSH implementation, so SFTP works without the `ssh2` extension, which few
hosts install. The 4.x line requires PHP 8.1; the 3.0 line is still maintained alongside it and supports PHP 5.6+,
so `^3.0` resolves to 3.0.x under our 7.4 platform pin (D5).

We don't prefix its namespace for now. phpseclib 4 moved to a `phpseclib4` namespace, so only other plugins' copies of
3.0.x can collide with ours; whichever autoloader registers first wins. Within 3.0.x the API we use (`SFTP`,
`PublicKeyLoader`) is stable, so an older foreign copy should still work. Revisit when release packaging lands (M5):
prefixing with a build-time tool (Strauss or PHP-Scoper) is cheap then.

Host keys are pinned by fingerprint, required before the first deploy. Trust on first use would make the very first
connection, which also sends the password, the one an attacker needs to intercept. `target check` prints the presented
fingerprint so the admin can compare it with `ssh-keygen -lf` on the server.

rsync, which the plan listed beside SFTP, isn't included: it needs the `rsync` and `ssh` binaries plus `proc_open`, which
most WordPress hosts forbid. SFTP's change-only uploads cover the same need. Revisit if users on VPS hosts ask for it.

Integration tests run against a real OpenSSH server built from `alpine` (tests/sftp), not a mock: host-key, login and
rename behavior are what matter, and only a real server shows them.

## D15. Git deploys: pure-PHP smart-HTTP push to a branch Glaciate owns

The maintained PHP Git libraries shell out to the `git` binary, which needs `proc_open` and an installed Git, and most
WordPress hosts have neither. Provider APIs (GitHub's Git Data API) are provider-specific and cost one call per file. So
the deployer speaks Git's smart-HTTP protocol itself: it builds blob, tree and commit objects, writes a version-2 pack of
whole objects (no deltas; zlib only) and sends it to `git-receive-pack` with a token over HTTPS. Object IDs and tree
ordering are checked against IDs computed by Git itself, and the integration tests push to a real `git-http-backend` and
run `git fsck` on the result. SSH remotes and SHA-256 repositories aren't supported.

The deployer owns one branch and commits the whole site as its tree, with the current head as parent. That keeps every
push a fast-forward, keeps history (a bad publish can be reverted with Git), and needs no fetch: we never read the
remote tree, only its refs. A branch whose head isn't the commit we last pushed is refused unless the admin opts in
("Replace the branch's contents"), which mirrors the marker rule for folders. When the head is unknown, every blob is
sent, since the server's contents can't be known without a fetch.

Large first deploys would exceed one slice, PHP's memory and some hosts' request limits, so blobs beyond 16 MB go up in
batches, each in a commit on a temporary `glaciate-upload-<key>` branch that keeps them reachable (unreachable objects
may be garbage-collected) until the final push moves the real branch and deletes the temporary one.

Testing against GitHub showed that in an empty repository the first branch pushed becomes the default branch, and GitHub
refuses to delete it: the upload branch was undeletable, and the atomic final push failed with it. In an empty
repository the real branch is now created first, with a preparing commit that holds only the marker files, and the
upload branch is deleted in a separate push whose failure is only a warning.

Writing the tests also showed that Requests ignores its `verify` option inside `request_multiple()`, so S3's concurrent
uploads had been verified against the system CA store rather than WordPress's bundle. `RequestsTransport` now sets the
bundle on every curl handle.

## D16. Netlify through file-digest deploys, never spending a deploy on an unchanged site

Netlify's API offers ZIP deploys and file-digest deploys. ZIP deploys re-upload the whole site every time and are capped
at 25,000 files; with digests, Netlify asks only for content it doesn't already store, so an incremental publish uploads
a few files. Every deploy still lists every file, because a Netlify deploy is always the whole site.

The API allows three deploys a minute and 100 a day. Auto-publish debounces content changes by a minute, so a busy site
could otherwise run out; a publish whose files match the last deploy therefore makes no API call at all, and a 429 stops
the deploy with a message while the changes stay pending for the next publish.

Requests over 30 seconds are cut off, so deploys are created `async` and polled for up to 20 seconds per call; the deploy ID
and a fingerprint of its file list are saved, and the next slice resumes it rather than creating another (which would
spend another deploy). Serverless functions aren't uploaded: a static export has none.

Testing against the real API showed that a deploy which has started receiving files reports `required` as empty while
it is still `uploading`. Resuming from that answer declared a half-uploaded deploy finished, and Netlify kept serving
the previous one. The hashes left to upload are now tracked in our own state, and success waits for `uploaded`,
`processing` or `ready`. The in-memory test API reproduces the quirk.

## D17. Cloudflare through Workers static assets, not Pages direct upload

Cloudflare documents only the last step of a Pages direct upload (create a deployment from a manifest of "uploaded
content hashes"). The upload steps (`upload-token`, `check-missing`, `assets/upload`, `upsert-hashes`) exist only in
`wrangler`'s source, and its content hash is BLAKE3, which PHP lacks: we would depend on undocumented endpoints and
either a new dependency or our own hash implementation. Workers static assets has a documented direct upload, its
documentation doesn't fix a hash algorithm, and Cloudflare's own example uses truncated SHA-256, so the deployer uses
that with PHP's built-in `hash()`. Where the documentation is ambiguous (its prose says multipart, its example sends
JSON) we follow `wrangler`'s implementation: one multipart part per file, named by its hash, with base64 contents and the
served content type (`application/null` for none). An assets-only Worker is uploaded with only a metadata part, again as
`wrangler` does.

Existing Cloudflare Pages projects can keep Pages by connecting it to the repository the Git deployer pushes to. Revisit
if Cloudflare documents the Pages upload API, or if it changes or starts verifying the Workers asset hash.

## D18. Schedules are local wall-clock times, re-armed as single actions

"Publish at set times" means times people read on a clock: 09:00 should stay 09:00 after a daylight-saving change.
The nightly full rebuild used one recurring Action Scheduler action every 86,400 seconds, anchored at 03:00 with the
site's UTC offset at the moment it was scheduled, so after each clock change it ran an hour early or late, and stayed
that way. Both schedules now compute their next run with `Build\Schedule` (pure, unit-tested across the 2026 European and
US changes) in `wp_timezone()`, schedule it as a single action, and schedule the following run when it fires. A run
that never got scheduled (a fatal error in a handler) is re-armed by `ensure_recurring()` on the next request, and
existing recurring rebuilds from earlier versions are replaced the first time it runs.

On the night clocks go forward, a time inside the skipped hour fires just after it (02:30 becomes 03:30); on the night
they go back, a repeated time fires once. The publishing mode replaced the `auto_publish` switch: stored values and
`settings set auto_publish` from scripts map to `immediate` or `manual`. In scheduled mode a publishing time with
nothing pending starts no build, so frequent times cost nothing on quiet days.

## D19. Every folder URL is saved as index.html, with its real type recorded

WordPress serves feeds at folder URLs (`/feed/`, `/category/news/feed/`, `/comments/feed/`), and every page links to them.
Glaciate used to save non-HTML folder responses as `index.xml`, `index.json` or `index.txt`, but static hosts (S3,
Netlify, Cloudflare, GitHub Pages) map a folder request only to `index.html`, so a live test on Cloudflare returned 404
for `/feed/`. The local e2e server tried every index name, which hid it.

The alternative, keeping `index.xml` and adding `_redirects` rewrites (`/feed/ /feed/index.xml 200`), works on Netlify and
perhaps Cloudflare but can't on S3 or GitHub Pages. Saving `index.html` works everywhere; the type is the only cost.
So the real Content-Type is recorded per URL and sent wherever a host lets us: S3 and Cloudflare per uploaded file,
Netlify and Cloudflare through a generated `_headers` file (capped at Cloudflare's 100 rules, shortest paths first).
Where a host decides by extension alone, feeds are served as `text/html`; feed readers detect RSS and Atom from the
content. The e2e static server now behaves like a real host. Existing sites move to the new paths at their next full
build, and deployers delete the old `index.xml` files as usual.


## D20. Static search: an index built in PHP from the published pages, kept in the database, written as shards

Search on the static copy needs an index made at publish time and a client that reads it. The ready-made tool, Pagefind,
is a compiled binary: plugins hosted on WordPress.org may not ship or download executables, and most hosts can't run
one, so it can only be an option where the user supplies the binary or the deploy target builds the site itself (CI).
The default is our own: PHP builds the index, a small bundled script (unminified, no build step) searches it.

- **Source: the published HTML, not the posts.** Text comes from each rendered page (the main content region when the
  theme marks one, `<main>` or `[data-glaciate-search]`, else the body without header, nav, footer, script and style).
  That covers page builders, archives and anything a plugin prints, and the index can't hold text that isn't on the
  public site. Pages Glaciate doesn't publish (drafts, excluded, password-protected) are never rendered, so never indexed.
- **Incremental in the database.** Each page's terms and scores are stored per URL when it is rendered and replaced
  when it is rendered again; removing a page removes its rows. Finalizing writes only the shards whose terms changed.
  Holding the inverted index in PHP memory doesn't scale to the 50,000-URL limit; streaming it from a table ordered by
  term does. Unique terms per page are capped (highest-scoring kept) so the table stays proportional to the site.
- **Stable document IDs.** A page's document ID is its URL row ID, which survives rebuilds, so a new post changes only
  its own terms' shards and one document chunk, not the whole index.
- **Shards by term prefix.** Terms are grouped by their first two characters; a group with too many postings splits by
  one more character, recursively. `meta.json` lists every shard key, so the client fetches only the shards a query
  needs and never asks for a file that doesn't exist. File names are the hex of the key's UTF-8 bytes, so any script is
  a safe path.
- **One tokenizer, written twice.** PHP and the client must cut text the same way: lowercase, accents removed (NFD and
  drop combining marks; without intl, WordPress's `remove_accents()`), runs of letters and digits, single characters
  dropped, and Han/Hiragana/Katakana runs indexed as overlapping pairs because those scripts don't separate words.
  Both implementations are tested against one shared table of examples (`tests/fixtures/search-tokenize.json`).

- **Results in a dialog, not a results page.** A themed results page would need a template rendered for a query
  that doesn't exist and a client that understands every theme's markup. A `<dialog>` with system colors and the
  theme's font works on every theme, and the script is added only to pages rendered for the static copy, so the live
  site keeps WordPress search. Forms without JavaScript land on `/?s=…`, which static hosts answer with the home page;
  the script opens the dialog there too.
- **Every word must match.** The word still being typed also matches longer terms. Scores are the stored per-page
  scores times inverse document frequency; nothing is computed at publish time that a query could compute.

No typo tolerance or stemming in the first version: both are language-specific, and the user can switch to Pagefind
where it is available. Revisit if real sites show the table growing past what the cap intends.

## D21. Pagefind is optional, never shipped, and named only in wp-config.php

*The server-run `pagefind` mode below was removed in 1.1.1; see D39.*

Pagefind indexes faster than PHP, handles stemming and very large sites, and some people already use it. It is a
compiled program, so Glaciate can't ship or download it (D20), and most shared hosts can't run programs at all. Two
modes use it, and both keep the same search dialog, which talks to Pagefind's own API instead of our index:

- **`pagefind`**: each build runs the binary named by `GLACIATE_PAGEFIND_BINARY` over the build cache, into a private
  temporary folder, and copies the bundle into `pagefind/` as generated files. A failed run keeps the published bundle.
  The path is a constant rather than a setting because whoever sets it chooses a program the server runs: an
  administrator who can't install plugins (`DISALLOW_FILE_MODS`) mustn't get that through a settings screen (T19).
- **`pagefind_host`**: the host builds the site from what we deploy (GitHub Actions on the Git target, Netlify or
  Cloudflare Pages builds) and runs `npx pagefind --site .` there. Glaciate publishes no index; the client expects
  `/pagefind/` to exist and says search is unavailable if it doesn't.

Pagefind indexes every HTML file, so pages that aren't content are marked `data-pagefind-ignore="all"` on `<body>`: the
404 page and the meta-refresh pages of redirects. The marking is in every mode, so switching modes doesn't change the
pages, and a host-side run sees it too. `[data-glaciate-search-ignore]` is passed as Pagefind's exclude selector, so
themes mark ignored parts once for both indexes. Pagefind's result addresses are paths from the site root; the client
sets Pagefind's `baseUrl` to the public site's path, for sites published under a folder.

The run isn't sliced: Pagefind processes the whole site at once, and the build log records how long it took. A site
whose run outgrows the slice budget would need its builds to run from the CLI. Revisit if that happens.


## D22. Forms post to a receiver; WordPress processes them through the form plugin's own code

A static host can't run PHP, so a form on the static copy has to post somewhere else. Three choices were weighed:
replace every form plugin with our own form builder (people would rebuild their forms, and lose the plugins'
notifications, entries and add-ons), send submissions to a third-party form service (an outside service by default,
against the "no external calls" rule), or keep the plugins and send each submission back to WordPress. We do the
last: an adapter per plugin recognizes its forms in the rendered page and, when a submission arrives, calls the
plugin's own submission code, so its validation, mail templates, stored entries and add-ons keep working.

- **Where submissions arrive.** WordPress itself when it is publicly reachable (`site` mode, the simplest), or a small
  receiver somewhere else (`receiver` mode) for sites whose WordPress is private: a single PHP file for any PHP host, or
  a function for Cloudflare Workers or Netlify. That receiver applies the same spam rules and forwards each accepted
  submission to WordPress's relay route, signed with a secret both sides know; WordPress then needs to be reachable only
  from the receiver. A receiver can also send to any webhook, or (the PHP file) email, without WordPress.
- **Rewriting after URL rewriting.** The receiver may live on the origin (`site` mode); the URL rewriter would turn its
  address into the public site's, which can't answer. So forms are rewritten after it.
- **No signed form tokens.** Nonce-like tokens on the page prove nothing on a static site: every visitor, bot or not,
  gets the same page, so a token is as available to a bot as to a person, and it would go stale between builds. The
  checks are what a static page allows: a honeypot field (catches bots that fill every field), the time the page was
  open, measured in the browser (`performance.now()`, so clock differences don't matter; missing without JavaScript,
  then skipped), a per-IP rate limit, an `Origin` check, size limits, and an optional captcha. Turnstile and hCaptcha
  are outside services, so they are off unless the owner picks one.
- **Plugins' own anti-spam.** WPForms checks a signed render time and a token its script computes, and that the
  submission came through its AJAX action; on a page rendered hours earlier and posted through a receiver these can't
  pass. The adapter bypasses exactly those checks through the filters WPForms offers for it, for that one call. Contact
  Form 7 checks nonces only for logged-in users and leaves the rest to add-ons, which still run. The same reasoning
  removes WPForms' per-render tokens from the published markup, which would otherwise change the page on every build.
- **One format, three implementations.** The rules, the relay payload and the signature are small enough to write in
  PHP inside the plugin, in the standalone PHP file (which can't load the plugin) and in JavaScript, and are tested
  against one shared table, as the search tokenizer is (D20).
- **Messages are text.** Plugins' confirmation messages may contain HTML. The answer crosses a receiver the site owner
  may not fully control, and the client inserts it into the page, so markup is removed and the client uses
  `textContent`.

Not supported: file uploads, multi-page Gravity Forms, and plugins' own captcha widgets (use the receiver's captcha
instead). Revisit if people need uploads; they would need storage on the receiver.


## D23. Incremental builds skip what an edit provably can't change

The 100,000-address benchmark (M5) showed that editing one post's text rebuilt every page. Saving a post re-sets its
terms and recounts them, which was recorded as a change to each term; every page that printed its post's categories
depended on "any term in the taxonomy" and on those terms. The paginated archives (`/page/2/` … `/page/8000/`) all share
one query signature, which matched any post. A one-line edit cost a full build. Three narrowings, each sound under the
assumptions stated:

- **A post's own terms depend on the post.** A term query scoped to `object_ids` (`get_the_terms()`, `the_category()`)
  records the posts and the terms it returned, not `tq:<taxonomy>`. Its result changes only when those posts' term
  assignments change (a change to the post) or one of those terms changes (`term:<id>`). Term *lists* (widgets, tag
  clouds, the categories block) still record `tq`.
- **Only a term count that moved is a change, and only to term lists.** The count is read before and after
  `edited_term_taxonomy`; when it moved, a `term_count` change invalidates `tq:<taxonomy>`. Assigning a term to a post is
  no longer a change to the term: the term's archive is found through the post's query signatures. Counts printed outside
  a term list (a theme reading `$term->count` from `get_the_terms()`) are not tracked.
- **Edits that move nothing a simple query uses don't match it.** A signature is marked `fix` when the query uses only
  vars the signature or the snapshot covers (no search, meta, unknown vars a plugin may filter on, or random, meta or
  comment ordering), and records the fields it is ordered by. The change listener records which snapshot fields an
  edit moved (status, type, author, parent, slug, full date, terms, password, title, menu order, modified; any meta
  write counts as moving). A post that moved none of the fields a `fix` query selects or sorts on is exactly where it
  was in that query's results, and every page that showed it already depends on `post:<id>`. New posts have no
  trustworthy "before" and match as before. Not covered: plugins that filter `posts_where` on a post's content.

The differential test now has a page with a category list and a tag cloud, both with counts, and passes 200 random
sequences; with the count change removed it fails within a few iterations. On the benchmark site a content or title
edit renders 19 addresses instead of all of them.


## D24. Builds of 100,000 addresses: list what can be listed, never rescan the queue

Measured with `npm run perf` (tests/perf): 80,000 posts in 50 categories and 500 tags, by 5 authors, 10 a page, on the
one-file benchmark theme; wp-env on an Apple-silicon laptop, concurrency 8. That is about 128,000 addresses.

- **The queue is never rescanned per batch.** Claiming the next URLs sorted the whole pending queue
  (`ORDER BY depth, url_id` on an index that only had `build_id, state`), and every batch of 8 recounted the queue by
  state, once per page with links. Both grow with the queue, so a build was quadratic. The `claim` index covers the
  order, and `Frontier` keeps its counts in memory once read: a slice holds the build lock, so only it changes them.
  Pages are marked done a batch at a time.
- **Links are looked up a batch at a time.** A page's links were each a `SELECT` (and an `INSERT` when new): 25 a page,
  2.5 million per build. One lookup and one multi-row insert per batch now. Seeds are inserted the same way, and
  permalinks are primed a batch at a time.
- **Paginated archives are listed, not crawled.** `/page/N/` of the blog, each term, each author and each post type
  archive is only linked from the page before it, so the crawler found them one level at a time. In the first
  100k run, 24 minutes in, 92,000 pages were done and the archives were 12 levels deep, each level taking about 20
  seconds; the blog alone needs about 4,000 levels, with a level holding two pages once the shorter archives end. The seeds now list them from the counts and
  `posts_per_page`. A theme that shows more posts per page makes the last few answer 404 (dropped, at the cost of a
  render); one that shows fewer has the rest found through links as before.
- **The address limit counts pages that links lead to.** It exists to stop crawler traps, which only links to pages
  create. It used to count every address in the queue, files included, so its default (50,000) cut big sites short. It
  now counts pages a build queued by following links, beyond everything listed from the database, and defaults to
  100,000.

Results with all of the above (wp-env, concurrency 8; times include the benchmark's query logging):

| Build | Pages rendered | Time | Notes |
|---|---|---|---|
| Full | 127,962 | 42.6 min | 35.5 min rendering (~60 a second), 7 min search index, generated files and folder deploy; 0 failed |
| No change | 0 | 1.2 s | |
| Edit a post's text | 58 | 9.7 s | the post, its neighbours, the archive pages that show it |
| Edit a post's title | 58 | 4.3 s | |
| New post | 16,055 | 5.9 min | every later page of the blog, its category and its author shifts by one post |

Memory stays flat while rendering (54 MB over 3,000 pages in one process) and peaks at 53 MB when finalizing and
deploying the whole 133,000-file manifest. The Publish screen's status takes 30 ms and Site Health 72 ms.

Not addressed: rendering itself (WordPress's own time per page) dominates; raise concurrency where the server allows.


## D25. The build log is written in the site's language; pure classes stay in English

The build log is read on the Publish screen and through WP-CLI, so its lines are translated, with `__()` / `_n()` at
the moment they are written. Builds run in the background, so a line is in the site's language, not the reading
user's: storing message IDs and arguments to translate on display would add a format to keep stable for little gain.
Counts are phrased as labels ("Files added: 3") wherever a plural would otherwise be needed.

Classes that run without WordPress (`PathMapper`, `OriginSet`, the Git pack writer, the standalone receivers) can't call
gettext, so their exception text stays English; it appears after a translated prefix ("Unsafe output path: …").

Scripts on the static site have no gettext. Their labels are translated in PHP when the page is rendered. The search
result count needs every plural form of the language: `Frontend::result_counts()` sends the distinct forms and one
digit per count from 0 to 199 naming the form (cut after the last change, so English sends "010"); gettext plural rules
depend only on n, n % 10 and n % 100, so the client maps larger counts to 100 + n % 100. `npm run i18n:check` (CI)
fails on any `make-pot` warning.


## D26. Accessibility: WCAG 2.2 AA for what Glaciate adds, checked with axe-core in Playwright

`tests/e2e/a11y.spec.mjs` runs axe-core (MPL-2.0, a test-only dev dependency) with the WCAG 2.0–2.2 A and AA rules over
the search dialog and form answers on the static site, and over the plugin's own part of its admin screens (core's
admin chrome is WordPress's to audit). Axe can't hear a screen reader, so the spec also checks what it misses: focus
moves into the dialog and back to where search started, keyboard order, a visible focus ring, live regions that exist
before they speak, and that every settings field has a name and its help text attached.

Fixes from the audit: the search status region was `display: none` while empty, so screen readers missed its first
announcement; result lists lost their list role in Safari; the forms answer region was created at the moment it spoke;
two schedule controls were named "on" and "at"; most settings help text wasn't tied to its field; a `<label for>`
pointed at a fieldset; the progress bar had no name. Publishing start and finish are announced with `wp.a11y.speak()`,
the finish across the reload that refreshes the screen. Admin CSS uses logical properties, so it follows RTL languages.


## D27. Release packaging and WordPress.org readiness

`npm run build:zip` builds the release from a commit: `git archive` (honouring `export-ignore`), runtime Composer
packages from `composer.lock`, minus libraries' own build scripts and signing keys. Plugin Check (2.1.0) runs on that
ZIP's contents rather than the working tree.

- The standalone PHP form receiver is a template that runs on another host. Stored as `receivers/glaciate-form.php`
  it could be requested inside the plugin folder on the WordPress site and run there with empty settings, and Plugin
  Check rightly flagged it. It is now `glaciate-form-php.txt` (no `.php` anywhere in the name, since some servers run
  `x.php.txt`) and is still linted as PHP and tested; downloads keep the `.php` name.
- The readme lists each outside service under "External services": when it is contacted, what is sent, and its terms
  and privacy policy, as the plugin directory requires.
- Plugin Check reports two kinds of finding that are expected: URLs of services the owner chooses (captcha scripts and
  verification, AWS endpoints) as "offloading", and `proc_open()` for the optional Pagefind binary named in
  wp-config.php (D21). The first is the documented use of those services. The second was settled by removing that mode
  (D39).

## D28. readme.txt for WordPress.org, README.md for the repository

The plugin directory reads `readme.txt` in its own format (`=== Name ===`, header fields such as `Stable tag`, `== Section ==`),
which only looks like Markdown, so it can't be renamed. `README.md` is the repository's front page: what the plugin does,
how to install it from a checkout, and how to develop it, with links into `docs/` and to `readme.txt` for the FAQ and
changelog rather than a copy of them. `README.md` and `SECURITY.md` are `export-ignore`d, so the release ZIP carries only
`readme.txt`. The readme links to `docs/HOOKS.md` on GitHub, since `docs/` isn't in the ZIP.

## D29. Bundled phpseclib loads as `Glaciate\Vendor\`, prefixed by Strauss at build time

This closes D14's "revisit when release packaging lands". phpseclib 3.0.x and its paragonie dependencies are rewritten
in place in `vendor/` to `Glaciate\Vendor\phpseclib3\` and `Glaciate\Vendor\ParagonIE\ConstantTime\`
(random_compat is empty on PHP 7 and has nothing to rename). Another plugin's older 3.0.x can no longer be the copy
that loads. Action Scheduler isn't prefixed: it picks the newest bundled copy itself (D2), and renaming it would defeat
that. Constants aren't prefixed either. phpseclib defines some from computed names, which no prefixer can follow, and
every one is guarded by `defined()` with the same value in every copy.

Strauss (MIT) over PHP-Scoper (MIT): Strauss is made for plugins that keep Composer's autoloader. It rewrites
`vendor/composer/installed.json` too, so a later `composer dump-autoload` still maps the new names. PHP-Scoper writes
a separate build tree and its current releases need PHP 8.2. Neither is shipped.

Strauss can't be a root `require-dev`. Under the PHP 7.4 platform pin (D5) every version needs a flysystem that is
either PHP 8-only or blocked by Composer's security-advisory policy. So it is its own Composer project in
`tools/strauss` with its own lock, `export-ignore`d. That makes PHP 8.1 the minimum wherever Composer runs (the wp-env
container has 8.3), including `composer install` in a Git checkout. The code it produces still runs on 7.4. Composer's
`post-install-cmd` and `post-update-cmd` run it in a checkout. `bin/build-zip.sh` installs with `--no-scripts` and runs
it explicitly, taking `tools/strauss` from the same commit with `git show`, since `git archive` leaves out
export-ignored paths even when they're named.

Strauss 0.31 needed working around in five places, all in `composer.json` scripts or `tools/strauss/strauss.php`:

- Its bin script loads `./vendor/autoload.php` (the plugin's) before its own, so a launcher loads the tool's autoloader.
  Parallel analysis is off because the workers go through that bin script.
- Its regenerated autoloader drops the project's own `Glaciate\` map and moves Composer's loader classes into the
  prefix, so `composer dump-autoload` runs again afterwards.
- It writes `vendor/composer/autoload_aliases.php`, which registers the old names again in development. That would bring
  the collision back and hide unprefixed references, so it is deleted in both a checkout and the build.
- It forces a classmap-authoritative autoloader by default, which in a checkout wouldn't find new `src/` classes.
  The dump above replaces that loader.
- It doesn't rewrite class names inside strings of PHP code. phpseclib's Eval cipher engine (AES-CTR and others when
  OpenSSL isn't available) builds its ciphers that way. The launcher prefixes leading-backslash names in string
  literals after Strauss runs. Two exception messages that mention `phpseclib3\` by name stay as they are.

`VendorPrefixTest` fails if a checkout skipped the prefixing, if `src/` names a library by its original namespace, or if
the Eval engine's AES-CTR output differs from OpenSSL's. Revisit when Strauss fixes the points above, or when a PHP 8.1
floor allows phpseclib 4. Its `phpseclib4` namespace would collide with other plugins' 4.x copies in the same way, so
keep prefixing.

## D30. Loopback renders don't check TLS certificates unless a filter asks

Builds request every page from the site itself. Sites often reach themselves through an address whose certificate the
server doesn't trust: a self-signed certificate on an internal host, a proxy that terminates TLS, or a
`GLACIATE_CONNECT_TO` pin. So `LoopbackClient` follows WordPress's own loopback requests (cron, Site Health): it
doesn't check certificates, and the core `https_local_ssl_verify` filter can turn checks on. The filter gets each
request's URL, as in core, because filters often decide by host.

The choice is set on each curl handle by a `curl.before_request` hook, not only through the `verify` option. Requests'
curl transport applies `verify` to single requests but not to `request_multiple()` handles, which then check against
the system CA store. Up to 1.0.1 that made every render of a self-signed origin fail with cURL error 60 while `verify`
was false. `RequestsTransport` works around the same gap for deploy targets (THREAT_MODEL.md T15), where certificates
are always checked. Each request gets its own hooks object so the filter's answer can differ by URL.

When checks are on and the certificate fails, the error says which filter turned them on, because the bare cURL message
doesn't explain why a site's requests to itself check certificates at all.

## D31. Origin aliases are rewritten and crawled, but fetched from the origin

Sites are often edited on one host (`edit.example.org`) while the live site keeps its own address
(`www.example.org`). Content and theme settings then contain URLs on the live host: pasted from the live site, or left
over from before the move. Those URLs weren't the origin's, so the crawler skipped them and the rewriter left them
alone. Pages looked right only while the old live site still answered; the files they pointed to were never published.

The `origin_aliases` setting lists such hosts. An alias is a host (with an optional port) that serves the same site
with the same paths; a path in the setting is ignored. Its URLs are handled as origin URLs everywhere except
fetching: `UrlClassifier` moves them onto the origin host (`OriginSet::to_origin`) before `UrlPolicy` sees them, and
`UrlPolicy` never learns the alias hosts. Builds still contact only the site itself, and an alias can't be used to
reach another server (THREAT_MODEL.md T1). The rewriter matches alias hosts with the same boundaries and encodings as
the origin host.

Rewriting the database instead (search-replace of the live host) fixes what exists on the day it runs, but editors keep
pasting live URLs afterwards. The public address isn't an implicit alias: its paths can differ from the origin's when
the public URL has a base path, and an explicit list keeps the behavior visible in Settings.

## D32. Pages don't advertise feeds that aren't published

With the default `feeds` setting (`main`), only the site feed is published, but WordPress still prints head links to
the comments feed, each post's comments feed and the author, term, post type and search feeds. On the static site those
links return 404. Feed readers and validators follow them, and link checkers report them on every page.

During a render, the Recorder turns those links off with core's `feed_links_show_*` and `feed_links_extra_show_*`
filters (WordPress 6.1+), as part of the head clean-up that `glaciate_clean_head` controls; `none` also drops the
site feed. Links a theme prints itself aren't touched. The alternative, publishing every feed by default, adds a feed
per post, term and author for something most sites don't use.

## D33. npm overrides for vulnerable dev-tool dependencies

Security fixes for `qs` (6.16.1), `minimatch` (11.0.1) and `glob` (14.0.2) can't reach the lockfile through normal
updates: `@wp-playground/cli` (under `@wordpress/env`) pins `express@4.22.2`, which allows only `qs@~6.15`, and
`rimraf@5` allows only `glob@^10`, which allows only `minimatch@^9`. Dependabot's security updates fail for the same
reason. `package.json` therefore overrides `qs` and `glob`; `glob@14` brings `minimatch@11`. Both packages are
development tools only and never ship in the plugin ZIP.

The overrides cross version ranges their parents declare. `rimraf@5` uses only `glob()` and `globSync()`, and only when
called with its `glob` option, which `@wordpress/env` doesn't pass. `qs@6.16+` keeps the parse and stringify behavior
that Express uses. Remove each override once `@wordpress/env` depends on fixed versions itself (`npm ls qs glob`).

## D34. Nonces in inline scripts are emptied, not kept or stabilized

Anonymous renders still print nonces: core's `wp.apiFetch.createNonceMiddleware( "…" )` wherever `wp-api-fetch` loads,
`wpApiSettings.nonce`, and the `*_nonce` keys plugins pass through `wp_localize_script()`. `wp_create_nonce()` changes
every twelve hours, so a page carrying one hashes differently on every full rebuild, and every deployer sends it again
although nothing on it changed.

`Rewrite\NonceStripper` empties them in the processor (HTML only, before `glaciate_output`). It touches only
`<script>` contents, and only a value of exactly ten lowercase hex characters, the shape `wp_create_nonce()` returns,
under a key or variable whose name contains "nonce". On a static site those values are useless (there is no REST API
or `admin-ajax.php` to send them to) and core's middleware skips an empty nonce. Form fields and `data-` attributes are
left alone: forms go through their adapters (D22), and a broader pattern would start matching content.

Stabilizing the value instead (a `nonce_life` filter on render requests that pins the tick) would publish a nonce that
never expires and hides the problem from anything that reads it. `glaciate_strip_nonces` turns the pass off per URL.

## D35. favicon.ico is published as a copy of the icon WordPress redirects to

Browsers request `/favicon.ico` whatever a page links to. Unless the server has a real file there, WordPress answers
with a redirect to the 32-pixel site icon, or to core's logo when none is set (`do_favicon()`). Static sites answered
404, and the default build log filled with nothing about it.

`/favicon.ico` is a seed. A real file is published like any other. A redirect is recorded for `_redirects` as usual,
but no meta-refresh page is written there: a static host serves `favicon.ico` by its extension, so an HTML page would
be a broken icon. The same now holds for every redirect from an address that doesn't map to an HTML file (`/old.pdf`).
At finalizing, `Generated` copies the published file the redirect points to into `favicon.ico`, recorded with that
file's content type (browsers read PNG data in a `.ico` file). Hosts that ignore `_redirects`, such as GitHub Pages
or a plain web server, then serve the icon too. The copy gives way when a real `favicon.ico` appears, and is removed
when the address stops redirecting. A redirect to another host is left to `_redirects`.

## D36. A link depth limit, counted by the shortest path, that published pages can't reset

The address limit alone let a crawler trap (an events calendar with a "next month" link on every page) fill up to
100,000 addresses before stopping. `max_depth` (default 100, 0 for none) stops following links on pages that many
links from a seed. Seeds include every post, term, author and listed archive page (D24), so real sites rarely need
more than a few steps; the long chains are date-archive pagination and traps. Files are never limited, so a page at
the limit still gets its images and stylesheets.

Depth had to mean the shortest path. The frontier kept whichever depth inserted a URL first, so `Frontier::add()` and
`lower()` now move a waiting URL to the lower depth when a shorter path reaches it; claiming in depth order keeps the
crawl breadth-first, so a page is lowered before it is rendered. Full builds re-queue every published page so pages
nothing links to any more get re-checked. They used to go in at depth 0, which would have let a trap grow by the limit
on every build. They now go in at the limit: re-checked, but their links aren't followed unless this build reaches
them by a shorter path.

## D37. Back off when the origin says it is overloaded, inside the build rather than per request

A full build sends four requests at once for as long as it runs. When the origin answered 503 (overloaded, or in
maintenance mode during an update), every address in the batch failed, and the build carried on at the same pace
against a server that had just asked it to stop. The threat model (T9) promised a back-off; there was none.

A 429/500/502/503/504 answer now sends the address back to the queue, until its third try, which fails as before. The
build then stops rendering until the wait is over: `Retry-After` when the site sends one (maintenance mode sends 600;
we cap it at 300 s so an update doesn't hold a build for ten minutes at a time), otherwise 5 s doubling with each
back-off in a row up to 120 s. Concurrency halves at each back-off and doesn't come back up during that build: a site
that struggled at four requests at once gains little from trying four again. The wait is a scheduled Action Scheduler
action, not `sleep()`, so no PHP worker is held while waiting; only `--sync` sleeps, in its own process.

Waiting per request (retrying inside `LoopbackClient`) would hold a worker and the build lock through every wait, and
would keep the other requests of the batch going at full speed. Transport errors aren't retried: a refused connection
or a bad certificate doesn't get better in a few seconds, and the loopback Site Health check explains those.

## D38. An empty feed depends on every post, through the build date only

`get_feed_build_date()` returns the newest `post_modified` among the feed's posts. A feed with no posts falls back to
`get_lastpostmodified()`, the newest change to any public post type, pages included, which reads the database
directly and never reaches a recorded query. Editing a page then changed the date in an empty feed that no dependency
pointed to, and the incremental build left it stale (found by the 500-iteration differential run).

The recorder watches `get_feed_build_date` and records `lastmod` `*` when the main query has no posts left to loop
over, the same condition core uses; any post change then rebuilds those addresses. Recording on
`get_lastpostmodified` itself would be simpler but too broad: core also calls it for every feed's `Last-Modified`
header, which never reaches the published file, so every feed would rebuild on every edit.


## D39. No server-run Pagefind; the plugin starts no programs

The `pagefind` search mode (D21) ran a binary named in wp-config.php through `proc_open()`. Plugin Check reports
`proc_open()` as a forbidden function, and the plugin directory doesn't accept plugins that run programs on the server,
however the path is chosen. Few sites could use it anyway: most hosts can't run programs, and a site that can usually
also has a build step. Keeping it in a separate GitHub-only build would mean two products to test and support.

So the mode is removed from every build, and `pagefind_host` (the host's build step runs Pagefind) is the one Pagefind
option. A site that had `pagefind` stored reads it as `builtin`, the search that works with nothing else to set up,
rather than `pagefind_host`, which would show "search isn't available" until a build step existed. `Settings::migrate()`
(on `init`) saves the new value once, so it is tracked as a settings change and the next build is a full one, which
builds the built-in index. Each build's finalizing step removes any `pagefind/` files the old mode published, so the next
deploy deletes them from the target.


## D40. Releases are built once, by CI, from the tag

Release ZIPs were built on a developer machine, from whatever its wp-env and Composer cache held. A pushed `x.y.z`
tag now starts `.github/workflows/release.yml`, which builds the one ZIP that every channel gets:

1. **CI on the tag.** `ci.yml` is also a reusable workflow with a `ref` input, so the release runs the full matrix,
   system and end-to-end jobs on the tagged commit rather than trusting an earlier run on `main`.
2. **Versions agree.** `bin/release.mjs check-version` requires the tag, the `Version` header, `GLACIATE_VERSION`
   and `Stable tag` to be the same x.y.z, with a changelog entry for it. The tag can't choose the version.
3. **Build and check.** `npm run build:zip` (D27), then Plugin Check, at a pinned version, on the ZIP's contents.
   Plugin Check exits 0 even when it reports errors, so `bin/release.mjs gate` reads its JSON and fails on any error
   not listed in `bin/plugin-check-allowed.json`, matched by file, code and count, so a new finding of a known kind
   still fails. Output that isn't a report (a failed run) fails too. Warnings are logged, not enforced.
4. **Publish.** A separate job, the only one with `contents: write`, downloads the built files and creates the GitHub
   Release with the ZIP, its SHA-256 file and that version's changelog section from `readme.txt` as notes. It runs no
   project code, so `npm ci` and the build never hold a write token. An existing release is never replaced.

Run by hand ("Run workflow" with a tag), it releases an existing tag. The build checks out the tag but takes
`bin/release.mjs` and the allowlist from the workflow's own commit, so tags made before this tooling existed (1.1.1)
can be released.

The plugin gets no GitHub-based updater: a plugin in the WordPress.org directory may only update from WordPress.org
(guideline 8). Deploying the same ZIP to the directory's Subversion repository is the planned next step for this
workflow, once the plugin is approved.


## D41. The plugin is renamed Glaciate, with no migration from Stillpress

The plugin was called Stillpress through 1.1.1. That name belongs to an existing WordPress static-site project, and
the WordPress.org review also objected to its "-press" ending, which plays on the WordPress trademark. It is now
Glaciate everywhere: the slug and folder, `Glaciate\` namespace, `glaciate_` hooks, options, tables, meta keys and
capability, `GLACIATE_` constants, the `glaciate/v1` REST namespace, `wp glaciate`, the text domain and the
`.glaciate-target` marker on deploy targets.

1.x was only distributed through GitHub Releases and had no known users besides its author, so 2.0.0 is a clean break:
it reads nothing that Stillpress stored, and a site moving over sets Glaciate up from scratch. Keeping the old names
for compatibility would have kept the conflicting name in the plugin indefinitely.

wp-env mounts the plugin through `mappings` as `wp-content/plugins/glaciate` (and activates it in `afterStart`), and the
SFTP and Git test servers find the wp-env network from the checkout folder's name, so nothing depends on what the
checkout is called.


## D42. The data directory is in uploads

The build cache, ZIP archives and the default local target lived in `wp-content/glaciate/`. The WordPress.org guidelines
only allow plugins to write to the uploads directory, in a folder named after the plugin, unless the plugin's nature
requires otherwise; `wp-content` isn't guaranteed to be writable or to persist. The data directory is now
`glaciate/` inside the uploads directory, resolved with `wp_upload_dir()` at runtime, so it follows `UPLOADS`, the
`upload_dir` filter and multisite's per-site folders. `GLACIATE_DATA_DIR` and the `glaciate_data_dir` filter still
move it, for hosts whose uploads directory is on shared or remote storage.

Uploads is served by the web server and is a root the asset copier reads from, so the data directory is excluded twice:
`AssetCopier` never returns a file below it, and the build excludes its URL path, so a link to a cached page or a ZIP
can't put it into the static site. The cache and ZIP folders keep their index.php and .htaccess guards; the data
directory itself stays open so the default local target can be served from it.

A local target chosen by an administrator (a super admin on multisite) can still be any folder `TargetPolicy` allows,
outside WordPress included: writing the static site where the web server serves it is what that target is for.
