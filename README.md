# Stillpress

Publish a WordPress site as static files. Every feature is free: there's no premium version, no license key and no
tracking.

You keep writing in WordPress. Stillpress renders each page through your own site, exactly as a visitor sees it,
rewrites its addresses to the public site, and publishes plain HTML, CSS and images that any host can serve without
PHP or a database.

- **Works with your theme and plugins.** Block themes, classic themes and page builders all render as they do live.
- **Rebuilds only what changed.** While a page renders, Stillpress records the posts, terms, authors, settings and
  queries it used. Edit a post and only the pages that show it are rebuilt and redeployed.
- **Publishes where you host.** A folder on the server, a ZIP file, Amazon S3 or S3-compatible storage (with CloudFront
  invalidation), SFTP, a Git branch (GitHub Pages, GitLab Pages and similar), Netlify or Cloudflare. Credentials are
  stored encrypted, or set by constants and environment variables so they're never stored.
- **Search and forms keep working.** Search runs in the visitor's browser from an index published with the site.
  Contact Form 7, WPForms, Gravity Forms and comment forms send their submissions back to WordPress, directly or
  through a small receiver you host anywhere.
- **Feels like WordPress.** A "Publish to static" button in the editor, a status in the admin bar, Site Health checks,
  and a WP-CLI command for everything.
- **Contacts nothing you didn't choose.** The only outside requests go to the deploy target and captcha you configure.

[readme.txt](readme.txt) is the WordPress.org page, with the FAQ, the external services list and the changelog.

## Requirements

- WordPress 6.6 or later (tested up to 7.1), single site or multisite
- PHP 7.4 to 8.5
- Pretty permalinks (anything but "Plain")
- A server that can make HTTP requests to its own address. Behind a proxy or in a container, point it at the web
  server with `define( 'STILLPRESS_CONNECT_TO', '127.0.0.1:80' );` in wp-config.php.

[docs/SUPPORT.md](docs/SUPPORT.md) has the full support matrix and what each deploy target, search mode and form
plugin needs.

## Install

Use a release ZIP: it includes the plugin's PHP dependencies. Upload it under Plugins → Add New → Upload Plugin and
activate it.

A Git checkout has no dependencies installed. Run `composer install --no-dev` in the plugin folder, or build the
release ZIP yourself with `npm run build:zip` (see [Development](#development)).

## Quick start

1. Go to **Static Site** in the admin menu.
2. Choose where the static site goes.
3. Click **Publish my site**.

From then on, changes are published right after you save, at set times, or when you click Publish. A full rebuild
runs nightly as a safety net.

The same works from WP-CLI:

```bash
wp stillpress target set s3 bucket www.example.org
wp stillpress target set s3 region eu-west-1
wp stillpress target set s3 access_key_id AKIA...
printf %s "$AWS_SECRET_ACCESS_KEY" | wp stillpress secret set s3 secret_access_key
wp stillpress target check s3
wp stillpress settings set target s3
wp stillpress build --full --sync
wp stillpress status
wp stillpress why /about/        # what a page depends on
wp stillpress doctor             # the Site Health checks
```

`wp help stillpress` lists every command.

## Extending

Every stage has actions and filters, listed in [docs/HOOKS.md](docs/HOOKS.md). Deploy targets are pluggable through
`Stillpress\Deploy\DeployerInterface` and the `stillpress_register_deployers` action. Themes can mark what search
indexes with `data-stillpress-search` and `data-stillpress-search-ignore`, and other forms with
`data-stillpress-form="name"`.

## How it works

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): the build pipeline, dependency tracking, deployers, search and forms.
- [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md): what Stillpress protects and how.
- [docs/DECISIONS.md](docs/DECISIONS.md): the non-obvious choices and why they were made.

## Development

There's no PHP on the host. Everything runs in Docker through [wp-env](https://www.npmjs.com/package/@wordpress/env),
so you need Docker and Node.js 20 or later.

```bash
npm install
npm run env:start            # WordPress on http://localhost:8888 (admin / password), tests site on :8889
npm run composer install
```

```bash
npm run lint:php             # WordPress Coding Standards and PHP 7.4 compatibility
npm run test:unit            # no WordPress loaded
npm run test:integration     # against the WordPress test suite
npm run test:js              # search and forms clients, in Node
npm run test:system          # real builds over HTTP (erases the :8889 site's content)
npm run test:e2e             # Playwright: live vs static screenshots (erases the :8889 site's content)
npm run build:zip            # build/stillpress-<version>.zip from the committed tree
```

`npm run sftp:start` and `npm run git:start` start the throwaway servers the SFTP and Git deploy tests need; without
them those tests skip. [CLAUDE.md](CLAUDE.md) has the project's conventions and rules for changes.

## Security

Please report vulnerabilities privately; see [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later; see [LICENSE](LICENSE). The bundled Action Scheduler is GPL-3.0-or-later, so the distributed plugin
as a whole is under GPLv3 terms. phpseclib and its paragonie dependencies are MIT.
