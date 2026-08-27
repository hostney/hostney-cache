=== Hostney Cache ===
Contributors: hostney
Tags: cache, nginx, redis, memcached, performance
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Page cache purging and Redis or Memcached object caching for Hostney hosting.

== Description ==

Hostney Cache manages both caching layers on a Hostney site: it purges the nginx page cache when content changes, and it wires WordPress up to the account's object cache.

**Features:**

* Automatic cache purge on post publish, update, trash, and restore
* Automatic cache purge on taxonomy and comment changes
* Hostney Cache menu in the admin bar with Purge cache, Flush and pre-fetch, and Cache settings
* Manual "Purge all cache" button in admin bar and admin page
* "Flush and pre-fetch" button that clears the cache and then warms it back up in the background
* Per-page "Purge cache for this page" button in the post editor
* Object caching via Redis or Memcached, whichever the account is running
* Activity log showing recent purge operations
* Zero configuration required

== Object caching ==

A Hostney account runs one object cache: Redis or Memcached, never both. WordPress has exactly one object-cache slot, so a second engine would only consume memory nothing reads from.

The drop-in this plugin installs detects which engine is running on every request. That means switching engines in the Hostney control panel needs no action here: the next request finds the new socket and connects. If neither engine is running, the drop-in falls back to a per-request in-memory cache, which is what WordPress does with no drop-in at all.

== Installation ==

This plugin is automatically installed on Hostney hosting accounts. No manual installation is required.

== Flush and pre-fetch ==

Purging the cache is instant, but it leaves the next visitor to each page waiting while it is rendered again. On a site with a few hundred pages that is a slow half hour for whoever happens to arrive first.

"Flush and pre-fetch" clears the page cache and then requests every public page once, so the cache is full again before anyone asks for it. Useful after a theme change, a bulk edit, or a plugin update that touches the front end.

It runs in the background, **one page at a time**. A hosting account has a small, fixed number of PHP workers, and warming is by definition a stream of uncached requests - so a fast warm-up would slow the site down for real visitors while claiming to speed it up. Progress is shown on the plugin page and you can leave and come back to it.

== Changelog ==

= 1.2.3 =
* Fixed: two WordPress sites on the same hosting account shared one set of object cache keys. There is one Redis or Memcached instance per account, and the cache key prefix was built from the database table prefix alone, so two installs both using the default wp_ prefix read and wrote each other's cached data. A site could serve a blank page with no error in the logs, because it was reading the other site's options and looking for a theme it does not have
* Cache keys are now namespaced by database name, install path and table prefix, so every site on an account is isolated
* WP_CACHE_KEY_SALT is honoured if it is defined, for installs that deliberately want to share a cache
* Upgrading starts every affected site with an empty object cache, which fills again on the next few requests
* Flushing the object cache now clears THIS site only. It previously emptied the whole instance, which cleared the cache of every other site on the account without telling anyone
* Installing or removing the drop-in is likewise scoped to this site, instead of clearing the account
* Removing the drop-in, or deactivating the plugin, now clears this site's entries and de-registers it, so nothing is left stranded in the shared instance
* New "Flush all sites on this account" button, which names the other sites before it does anything
* New "Account keyspace" panel showing how many entries each site holds, and how many are left over from sites that no longer exist
* Sites register themselves in the account instance so the panel and the hostney CLI can tell whose entries are whose
* Per-site clearing and the keyspace breakdown need Redis. Memcached cannot look up keys by prefix, so it offers only the account-wide flush and says so

= 1.2.2 =
* The admin bar item is now a menu: Purge cache, Flush and pre-fetch, and Cache settings, reachable from any page on the site. While a pre-fetch is running the menu label carries its progress
* New "Flush and pre-fetch" button: clears the page cache and then warms it back up in the background, with a progress bar on the plugin page
* Warming runs one page at a time so it never competes with real visitors for the site's PHP workers
* Requests go through the local nginx rather than the public hostname, so a site behind a CDN warms its own origin cache rather than an edge node
* If a whole run comes back with no page-cache header, the result says page caching looks switched off rather than reporting pages as warmed

= 1.2.1 =
* The object cache drop-in now updates itself when the plugin updates. Before this, updating the plugin left wp-content/object-cache.php untouched, so a site upgraded from 1.1.0 kept a Memcached-only drop-in and would have silently lost its object cache if the account switched to Redis
* The drop-in is stamped with the plugin version, and the admin page reports an out-of-date one instead of showing it as current
* Activating the plugin now installs the drop-in when nothing else owns the object-cache.php slot. Deactivating has always removed it, so the plugin could take the file away but never put it back - the only thing that created one was a button in the admin page

= 1.2.0 =
* Object cache now supports Redis as well as Memcached
* The drop-in detects the running engine at runtime, so switching engines in the control panel needs no action on the site
* Drop-in management moved out of the Memcached class; it is one file serving either engine
* wp_cache_supports('flush_group') now reports false. It reported true while only clearing the in-process array, which told WordPress a group flush had invalidated persistent entries when it had not

= 1.1.0 =
* Memcached object cache support and drop-in management

= 1.0.0 =
* Initial release
