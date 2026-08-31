# OPcache & Memcached Manager

[![CI](https://github.com/jesgs-interactive/opcache-memcached-manager/actions/workflows/ci.yml/badge.svg)](https://github.com/jesgs-interactive/opcache-memcached-manager/actions/workflows/ci.yml)

A WordPress plugin for monitoring and managing **OPcache** and **Memcached** from
wp-admin, with matching WP-CLI commands. Optionally installs a Memcached-backed
object cache and/or a full-page cache.

This plugin is **not** on WordPress.org — it updates itself from this repository's
GitHub Releases through the normal plugin update UI.

## What it does

A single **Cache Manager** screen (Administrators only) showing:

- **OPcache** — enabled state, memory usage, hit rate, cached script/key counts,
  cache-full and restart status. Reset the whole cache, or invalidate one file.
- **Memcached** — per-server reachability and stats (items, memory, hit rate,
  connections, evictions) for a configurable server pool, and whether Memcached
  is currently acting as WordPress's object cache. Flush the pool directly, or
  flush the WP object cache.
- **Object cache drop-in** — install/remove a Memcached-backed
  `WP_Object_Cache` (`wp-content/object-cache.php`).
- **Page cache** — install/remove a Memcached-backed full-page cache
  (`wp-content/advanced-cache.php`), with a TTL, configurable exclusion
  patterns, and targeted purging on content changes.

There's also a **"Clear all caches"** action (button, WP-CLI command, and
toolbar entry) that clears all four at once, and a **"Cache" menu in the admin
toolbar** with stats and clear controls for administrators.

The two drop-ins are optional and independent. Full detail on how the page cache
makes its decisions and what it purges is in [`readme.txt`](readme.txt).

## Requirements

- WordPress 6.5+
- PHP 7.4+
- The PHP `memcached` extension for the Memcached features; the Zend OPcache
  extension for the OPcache features. Each section degrades gracefully with a
  clear message when its extension is missing.

## Installation

1. Download `opcache-memcached-manager.zip` from the
   [latest release](https://github.com/jesgs-interactive/opcache-memcached-manager/releases/latest).
2. Plugins → Add New → Upload Plugin, or unzip into `wp-content/plugins/`.
3. Activate. Configure the Memcached server pool under **Cache Manager**.

Once installed, new releases appear in Plugins → Installed Plugins like any other
update.

Installing from a plain `git clone` also works, but the version will read
`{{VERSION}}` until a build stamps it (see [Releases](#releases) below), and the
update check stays disabled while that placeholder is in place.

## WP-CLI

```
wp cache-manager clear-all

wp cache-manager opcache status
wp cache-manager opcache clear
wp cache-manager opcache invalidate <file>

wp cache-manager memcached status
wp cache-manager memcached flush
wp cache-manager memcached flush-object-cache
wp cache-manager memcached install-dropin [--overwrite]
wp cache-manager memcached remove-dropin

wp cache-manager pagecache status
wp cache-manager pagecache purge
wp cache-manager pagecache purge-url <url>
wp cache-manager pagecache install-dropin [--overwrite]
wp cache-manager pagecache remove-dropin
```

All commands require an administrator (the `manage_options` capability).

## Development

```bash
composer install
composer test        # PHPUnit — plain unit tests, no WordPress bootstrap
```

The test suite (`tests/`) covers the pure logic: the page-cache cache-key
builder, the request-eligibility rules, and the Memcached server-list parser.
`tests/bootstrap.php` shims the few WordPress functions those units touch, so the
tests run without a WordPress install. CI runs them on PHP 7.4 through 8.3.

Runtime code has **no** dependencies — `composer.json` carries dev tooling only,
and classes load through a `require_once` chain in the main plugin file rather
than an autoloader.

### The drop-in key-sync invariant

`omm_pagecache_build_key()` in `dropins/advanced-cache-dropin.php` runs before
WordPress loads; `OMM_PageCache::build_key()` in
`includes/class-omm-pagecache.php` rebuilds the same key when purging. They must
produce identical keys or purges silently stop matching. `PageCacheKeyTest` locks
this — run it after touching either.

More conventions (security baseline, when to bump a drop-in version, etc.) are in
[`AGENTS.md`](AGENTS.md).

## Releases

The plugin header, `OMM_VERSION`, and the `readme.txt` stable tag carry a
`{{VERSION}}` placeholder in source — never a real number. Pushing a tag runs
[`.github/workflows/release.yml`](.github/workflows/release.yml), which runs the
tests, stamps the tag into those placeholders, packages the plugin using
`.distignore` as the exclude list, and attaches
`opcache-memcached-manager.zip` to the GitHub Release.

```bash
git tag 1.3.0
git push origin 1.3.0
```

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
