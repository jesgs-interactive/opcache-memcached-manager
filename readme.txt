=== OPcache & Memcached Manager ===
Contributors: jessg
Tags: opcache, memcached, cache, performance, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
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

== Object cache drop-in ==

By default this plugin only *monitors and flushes* Memcached — WordPress keeps using whatever object cache it normally would (usually none, i.e. a single-request cache). To have WordPress actually store its object cache data in Memcached, install the bundled drop-in from the "Object Cache Drop-in" section of the Cache Manager screen (or `wp cache-manager memcached install-dropin`).

This copies a `object-cache.php` file into `wp-content/` and writes the currently configured server list to `wp-content/omm-memcached-servers.php`, which the drop-in reads at runtime. Whenever you update the server list in plugin settings, that config file is kept in sync automatically as long as the installed drop-in is this plugin's own.

The plugin will refuse to overwrite or remove a pre-existing `object-cache.php` it didn't create unless you explicitly confirm the overwrite, so it won't clobber a drop-in from another caching plugin.

== Notes ==

* This plugin talks to Memcached directly via the configured server list, independent of whether WordPress uses Memcached as its object cache. If you *do* run a Memcached-backed `object-cache.php` drop-in, point the server list at the same server(s) it uses so "Flush Memcached pool" and "Flush WP object cache" stay in sync.
* Requires the PHP `memcached` extension (Memcached class) for Memcached features, and the Zend OPcache extension for OPcache features. Both sections degrade gracefully with a clear message if the relevant extension isn't installed.
* All admin actions and CLI commands require the `manage_options` capability / an administrator running WP-CLI.

== Changelog ==

= 1.1.0 =
* Add optional object-cache.php drop-in: installs a Memcached-backed WP_Object_Cache implementation so WordPress actually uses the configured Memcached pool as its object cache, with install/remove controls in both wp-admin and WP-CLI.

= 1.0.0 =
* Initial release.
