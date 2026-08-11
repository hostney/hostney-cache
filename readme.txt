=== Hostney Cache ===
Contributors: hostney
Tags: cache, nginx, redis, memcached, performance
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Page cache purging and Redis or Memcached object caching for Hostney hosting.

== Description ==

Hostney Cache manages both caching layers on a Hostney site: it purges the nginx page cache when content changes, and it wires WordPress up to the account's object cache.

**Features:**

* Automatic cache purge on post publish, update, trash, and restore
* Automatic cache purge on taxonomy and comment changes
* Manual "Purge all cache" button in admin bar and admin page
* Per-page "Purge cache for this page" button in the post editor
* Object caching via Redis or Memcached, whichever the account is running
* Activity log showing recent purge operations
* Zero configuration required

== Object caching ==

A Hostney account runs one object cache: Redis or Memcached, never both. WordPress has exactly one object-cache slot, so a second engine would only consume memory nothing reads from.

The drop-in this plugin installs detects which engine is running on every request. That means switching engines in the Hostney control panel needs no action here: the next request finds the new socket and connects. If neither engine is running, the drop-in falls back to a per-request in-memory cache, which is what WordPress does with no drop-in at all.

== Installation ==

This plugin is automatically installed on Hostney hosting accounts. No manual installation is required.

== Changelog ==

= 1.2.0 =
* Object cache now supports Redis as well as Memcached
* The drop-in detects the running engine at runtime, so switching engines in the control panel needs no action on the site
* Drop-in management moved out of the Memcached class; it is one file serving either engine
* wp_cache_supports('flush_group') now reports false. It reported true while only clearing the in-process array, which told WordPress a group flush had invalidated persistent entries when it had not

= 1.1.0 =
* Memcached object cache support and drop-in management

= 1.0.0 =
* Initial release
