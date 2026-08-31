# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0/).
Dates before 1.2.1 are approximate — they predate tagged releases.

## [Unreleased]

## [1.3.0] — 2026-08-30

### Added

- **Clear all caches** in one step — a button on the Cache Manager screen, a
  `wp cache-manager clear-all` command, and an entry in the new toolbar menu.
  Resets OPcache, flushes the entire Memcached pool, flushes the WordPress
  object cache, and purges the page cache. Steps that don't apply (OPcache
  disabled, no Memcached extension) are reported and skipped.
- **"Cache" menu in the admin toolbar** — a compact set of cache stats
  (OPcache hit rate and memory, configured server count, object-cache
  backend, page-cache size) and one-click clear controls, shown to
  administrators on the front end as well as in wp-admin. Actions return you
  to the page you triggered them from.
- Self-hosted update support: releases published on GitHub appear in the
  standard Plugins → Installed Plugins update UI (`OMM_Update_Checker`).
- `uninstall.php`: deleting the plugin now removes its two option rows and,
  when they still carry this plugin's marker, the `object-cache.php` /
  `advanced-cache.php` drop-ins and their generated config files. Deactivation
  remains non-destructive.
- "Settings" link on the plugins list screen.
- PHPUnit suite covering the page-cache cache-key builder, the request
  eligibility rules, and the Memcached server-list parser; a CI workflow
  (PHP 7.4–8.3); and a tag-triggered release-packaging workflow.
- The plugin text domain is now loaded for translations on self-hosted
  installs.
- `README.md`, `CHANGELOG.md`, and `AGENTS.md`.

### Changed

- Admin notices are delivered through a one-time per-user transient instead
  of the redirect URL, so they can appear on whatever screen you land on
  (this is what lets the toolbar actions report back in place) and stay out
  of browser history and server logs.
- Tightened input sanitization and internationalization string extraction in
  the admin screen. No change to behavior.
- License metadata normalized to the SPDX identifier `GPL-2.0-or-later`.
- The plugin header, `OMM_VERSION`, and the `readme.txt` stable tag now carry a
  `{{VERSION}}` placeholder in source, stamped at release build time.
- Project layout brought in line with the `wordpress-plugin-scaffold`
  conventions, keeping the dependency-free `require_once` class loading.

## [1.2.1] — 2026-08-27

### Fixed

- **Page cache vs. Markdown representations.** The page cache no longer serves
  or stores a response for a request that asks for a non-HTML representation
  (`Accept: text/markdown`). The cache is keyed on scheme + host + path only,
  with no `Accept` dimension, so without this a Markdown response could be
  cached and then served to browsers asking for HTML, and vice versa. Fixes a
  conflict with the wp-markdown-pages plugin.
- The page cache is skipped entirely under WP-CLI, where there is no real
  request context to classify.
- A request with no `REQUEST_METHOD` is treated as not cacheable, instead of
  being assumed to be a `GET`.

### Changed

- Both drop-ins guard every function and class definition with
  `function_exists()` / `class_exists()`, so a second include of a drop-in
  within one request can no longer fatal with a redeclaration error. Both
  drop-ins are now version 1.0.1 — reinstall them from the Cache Manager screen
  (or `wp cache-manager pagecache install-dropin` /
  `wp cache-manager memcached install-dropin`) to pick up the change.

## [1.2.0] — 2026-07-08

### Added

- Optional full-page cache backed by Memcached: an `advanced-cache.php`
  drop-in, targeted purge on content changes (posts, comments, theme switches,
  plugin updates), and admin UI plus WP-CLI parity.

## [1.1.0] — 2026-07-03

### Added

- Optional `object-cache.php` drop-in: a Memcached-backed `WP_Object_Cache`
  implementation so WordPress uses the configured Memcached pool as its object
  cache, with install/remove controls in both wp-admin and WP-CLI.

## [1.0.0]

### Added

- Initial release: a Cache Manager admin screen (Administrators only) and
  matching WP-CLI commands for monitoring and managing OPcache and Memcached —
  status and stats, OPcache reset and single-file invalidation, and direct
  Memcached pool flushing.

<!-- Release tags start at 1.3.0; earlier versions predate tagged releases. -->
[1.3.0]: https://github.com/jesgs-interactive/opcache-memcached-manager/releases/tag/1.3.0
