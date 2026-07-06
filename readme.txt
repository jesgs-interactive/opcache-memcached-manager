=== OPcache & Memcached Manager ===
Contributors: jessg
Tags: opcache, memcached, cache, performance, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor and manage OPcache and Memcached from wp-admin, with matching WP-CLI commands.

== Description ==

A single "Cache Manager" screen in wp-admin (Administrators only) that shows:

* **OPcache**: enabled/disabled state, memory usage, hit rate, cached script/key counts, cache-full and restart status.
* **Memcached**: per-server reachability and stats (items, memory, hit rate, connections, evictions) for a configurable pool of servers, plus whether Memcached is currently acting as WordPress's own object cache.

Actions available from the UI:

* Reset the entire OPcache, or invalidate a single file by path.
* Flush the configured Memcached server pool directly.
* Flush the WordPress object cache (`wp_cache_flush()`), which only touches Memcached data if WordPress is actually using it as the object cache backend.
* Edit the list of Memcached servers (host:port, one per line).

Everything above is also available via WP-CLI:

    wp cache-manager opcache status
    wp cache-manager opcache clear
    wp cache-manager opcache invalidate wp-content/plugins/my-plugin/my-plugin.php

    wp cache-manager memcached status
    wp cache-manager memcached flush
    wp cache-manager memcached flush-object-cache
    wp cache-manager memcached install-dropin
    wp cache-manager memcached remove-dropin

    wp cache-manager pagecache status
    wp cache-manager pagecache purge
    wp cache-manager pagecache purge-url <url>
    wp cache-manager pagecache install-dropin
    wp cache-manager pagecache remove-dropin

== Object cache drop-in ==

By default this plugin only *monitors and flushes* Memcached — WordPress keeps using whatever object cache it normally would (usually none, i.e. a single-request cache). To have WordPress actually store its object cache data in Memcached, install the bundled drop-in from the "Object Cache Drop-in" section of the Cache Manager screen (or `wp cache-manager memcached install-dropin`).

This copies a `object-cache.php` file into `wp-content/` and writes the currently configured server list to `wp-content/omm-memcached-servers.php`, which the drop-in reads at runtime. Whenever you update the server list in plugin settings, that config file is kept in sync automatically as long as the installed drop-in is this plugin's own.

The plugin will refuse to overwrite or remove a pre-existing `object-cache.php` it didn't create unless you explicitly confirm the overwrite, so it won't clobber a drop-in from another caching plugin.

== Page cache ==

A separate, optional full-page cache, also backed by Memcached (same server pool). It's installed and controlled from the same "Page Cache" section of the Cache Manager screen.

**Setup:**

1. Install the drop-in from the Page Cache section (or `wp cache-manager pagecache install-dropin`). This copies `advanced-cache.php` into `wp-content/`.
2. Add this line near the top of `wp-config.php`, right after the opening `<?php` tag and before the "That's all, stop editing!" comment — this plugin does not edit `wp-config.php` for you:

       define( 'WP_CACHE', true );

3. Tick "Enabled" in the Page Cache settings and save.

**How caching decisions are made:** only `GET` requests with no query string are ever cached or served from cache; requests from logged-in users or anyone with a comment cookie are always excluded, as are `wp-admin`, `wp-login.php`, `wp-cron.php`, `xmlrpc.php`, `wp-json`, and `/feed` by default (configurable). Cache hits are served directly from `advanced-cache.php` before WordPress even loads, for maximum speed; on a miss, the rendered page is captured and stored once WordPress finishes generating it.

**Purging:** publishing, editing, or deleting a post purges just the URLs it affects — the post's own permalink, the home page, the relevant author archive, date archives, and taxonomy term archives. Approving a comment does the same for its post. Theme switches and plugin updates purge the entire page cache, since their impact isn't easily scoped to specific URLs. You can also purge everything manually from the admin screen or via `wp cache-manager pagecache purge`.

Because the page cache shares its Memcached server pool with the object cache drop-in, "purge everything" only ever deletes the page cache's own tracked keys — it never calls a raw `flush()`, which would otherwise wipe out the object cache too.

**Site Health / "Page cache" detection:** every response includes `Cache-Control` and `X-Cache: HIT`/`MISS` headers, which WordPress's own Site Health check looks for.

== Notes ==

* This plugin talks to Memcached directly via the configured server list, independent of whether WordPress uses Memcached as its object cache. If you *do* run a Memcached-backed `object-cache.php` drop-in, point the server list at the same server(s) it uses so "Flush Memcached pool" and "Flush WP object cache" stay in sync.
* Requires the PHP `memcached` extension (Memcached class) for Memcached features, and the Zend OPcache extension for OPcache features. Both sections degrade gracefully with a clear message if the relevant extension isn't installed.
* All admin actions and CLI commands require the `manage_options` capability / an administrator running WP-CLI.

== Changelog ==

= 1.2.0 =
* Add optional full-page cache backed by Memcached: advanced-cache.php drop-in, targeted purge on content changes, admin UI and WP-CLI parity.

= 1.1.0 =
* Add optional object-cache.php drop-in: installs a Memcached-backed WP_Object_Cache implementation so WordPress actually uses the configured Memcached pool as its object cache, with install/remove controls in both wp-admin and WP-CLI.

= 1.0.0 =
* Initial release.
