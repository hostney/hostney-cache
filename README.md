# Hostney Cache

A WordPress plugin that automatically purges nginx cached pages when content changes on [Hostney](https://www.hostney.com) hosting. Zero configuration required — activate it and caching just works.

## How it works

Hostney uses nginx reverse proxy caching to serve WordPress pages without hitting PHP. When you publish, update, or delete content, this plugin tells nginx exactly which cached pages to invalidate.

1. WordPress content changes (post publish, update, comment, taxonomy edit, etc.)
2. The plugin collects all affected URLs (permalink, homepage, feeds, archives)
3. At the end of the request, it sends a single batched purge to the local nginx endpoint
4. Nginx clears only the affected cached pages

The plugin communicates with nginx through a local endpoint (`/.well-known/hostney-cache-purge`) that is restricted to localhost and container IPs only. No tokens, no API keys, no configuration.

## Features

- **Automatic purge on content changes** — posts, pages, custom post types, taxonomies, comments
- **Smart URL collection** — purges the post permalink, homepage, RSS feed, sitemap, and related archive pages (category, tag, author)
- **Deduplication** — multiple changes in a single request are batched and deduplicated before purging
- **Prefix purging** — archive pages are purged by path prefix to cover paginated pages
- **Batch overflow protection** — if more than 15 URLs are queued, falls back to a full cache clear instead of hammering nginx with individual requests
- **Gutenberg debounce** — handles Gutenberg's concurrent save requests without triggering duplicate purges
- **Admin page** — status overview, manual purge button, and activity log
- **Admin bar button** — "Hostney: Purge cache" available on both admin and frontend
- **Post editor meta box** — "Purge cache for this page" button on every public post type

## Security

The plugin relies on nginx-level access control rather than application-level authentication:

- **IP restriction** — the purge endpoint only accepts requests from `127.0.0.1`, `::1`, `10.0.0.0/8`, and `172.16.0.0/12`. External requests get a 403 before any code runs.
- **Domain validation** — the Lua module validates that URLs in purge requests match the requesting domain (`ngx.var.host`), preventing cross-site cache poisoning.
- **FQDN from nginx** — the domain is determined by nginx's `$host` variable, not by PHP. A compromised WordPress site cannot purge another site's cache.
- **Rate limiting** — the endpoint shares nginx's `limit_req` zone to prevent abuse.
- **WordPress capability checks** — manual purge actions (admin page, admin bar, meta box) require `manage_options` capability and valid nonce verification.

## What triggers a purge

| Event | What gets purged |
|-------|-----------------|
| Post published or updated | Post URL, homepage, feed, sitemap, category/tag/author archives |
| Post trashed or restored | Same as above |
| Post permanently deleted | Same as above |
| Taxonomy term edited or created | Term archive (prefix), homepage |
| Taxonomy term deleted | Full cache clear |
| Comment approved | Parent post's URLs |
| Comment unapproved/trashed | Parent post's URLs |
| Manual purge (admin page/bar) | Full cache clear |

## Admin interface

The plugin adds a top-level "Hostney Cache" menu in the WordPress admin sidebar with:

- **Status card** — shows detected domain, whether page caching and the purge endpoint are available, and auto-purge status
- **Purge card** — manual "Purge all cache" button with success/error feedback
- **Activity log** — last 20 purge operations with timestamp, action type, URLs affected, and result

## Architecture

```
WordPress (PHP in Podman container)
    ↓ wp_remote_post to 127.0.0.1
Nginx purge endpoint (/.well-known/hostney-cache-purge)
    ↓ allow/deny (IP restriction)
Lua module (validates request + domain match)
    ↓ ngx.location.capture (internal subrequest)
Worker API (127.0.0.1:4000)
    ↓ executes CLI tool
Nginx cache files (deleted from disk)
```

## File structure

```
hostney-cache/
├── hostney-cache.php                        # Main plugin file, singleton, constants
├── readme.txt                               # WordPress Plugin Check metadata
├── includes/
│   ├── class-hostney-cache-purger.php       # URL collection, dedup, HTTP calls, logging
│   ├── class-hostney-cache-hooks.php        # WordPress hook registrations
│   └── class-hostney-cache-admin.php        # Admin page, meta box, AJAX handlers
└── admin/
    ├── views/cache-page.php                 # Admin page template
    ├── css/cache.css                        # Admin styles (IBM Plex Sans, Hostney design tokens)
    └── js/cache.js                          # AJAX handlers for purge buttons
```

## Requirements

- WordPress 5.0 or later
- PHP 7.4 or later
- Hostney hosting (nginx caching with OpenResty/Lua)

## Installation

This plugin is automatically installed on Hostney hosting accounts. No manual installation is required.

## Non-Hostney environments

The plugin activates without errors on any WordPress installation, but purge requests will fail silently since the nginx endpoint doesn't exist. The admin page will show "Purge endpoint: Not reachable" and purge attempts will log as failures. The site itself is unaffected.

## License

GPL v2 or later. See [LICENSE](LICENSE) for details.

---

Built by [Hostney](https://www.hostney.com) - Web hosting with container isolation, ML-based bot protection, and a custom control panel built from the ground up.
