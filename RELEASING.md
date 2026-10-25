# Releasing Hostney Cache

A release is three files in the R2 bucket behind static.hostney.com, folder
`downloads/`:

- `plugin-checksums/hostney-cache/<version>.json` - every file of the zip with
  its md5 and sha256, in wordpress.org's plugin-checksums shape. The panel's
  WordPress integrity check compares an installed copy with it. wordpress.org
  has no list for a plugin it does not host, so without this file the plugin
  reads "Not verified" on every site (hostney-cli `WPChecksumHostneyURL`).
- `hostney-cache.zip` - the plugin. New sites, migrations and the panel's
  "Install Hostney Cache" button install it from this fixed address.
- `hostney-cache.json` - `{ "slug": "hostney-cache", "version": "1.3.1", ... }`.
  Every web worker's discovery reads it (api `library/hostney_cache_plugin.js`)
  and replaces installed copies older than that version with the zip.

A release needs no worker deploy.

## Steps

1. Set the new version in all three places: the `Version:` header and
   `HOSTNEY_CACHE_VERSION` in `hostney-cache.php`, and `Stable tag:` in
   `readme.txt` (plus its changelog). A release version is `1.3.2`, `1.4` or
   `2.0.0.1`, with no suffix. The worker ignores any other shape.
2. Commit. The build refuses a tree with uncommitted changes.
3. `python tools/build_release.py` builds from that commit into
   `_dist/hostney-cache/<version>/` next to this repository, like the
   migration plugin's releases: `hostney-cache.zip`, `hostney-cache.json`,
   `RELEASE_NOTES_v<version>.md` (the changelog entry, requirements and the
   SHA256) and `plugin-checksums/hostney-cache/<version>.json` (read from that
   zip). It prints the upload steps. It refuses to overwrite a version folder
   built from another commit, because that one may already be uploaded.
4. Upload the **checksum list first**, to the same path under `downloads/`. Then
   the zip: check its ETag against the printed md5. Only then the json.

## The checksum list

The list goes up first because a version no site has yet is never asked for.
After the zip, new installs start getting the new version at once. A check that
finds no list for a version waits an hour before it asks again.

A worker keeps a published list for good, because a version's files never change.
So **never replace a published list**: the script refuses to write a different
one over a file that is already there. If a wrong list ever went up, release a
new version. Then delete the wrong copy on every web worker:
`/var/cache/hostney/wp-checksums/hostney-plugins/hostney-cache/<version>.json`.

`python tools/build_release.py --lists-for-built` writes the list for every
version already in `_dist`, from that version's own zip, checked against its
json. Nothing is rebuilt. This is how releases built before the lists existed
get theirs. Versions zipped by hand before `build_release.py` existed have none,
and read "Not verified" until the site is updated.

## Why the order matters

The json is what starts the rollout. If it goes up before its zip, every
replacement installs the old zip again. Each worker notices when the first
replacement comes back older than the published version. It then logs one
ERROR ("went up before its zip") and stops replacing for 6 hours.

## How fast it reaches sites

Each worker reads the json at most every 10 minutes. A site is replaced at its
next discovery. The discovery cron runs every 4 hours, but only for sites with
at least one auto-update switched on. Other sites are replaced when someone
presses "Run discovery" on their Overview tab.

## The floor

`HOSTNEY_CACHE_MIN_VERSION` in api `queues/wordpress_discovery_worker.js` is the
version enforced even when the json cannot be read. Discovery uses the higher of
the floor and the published version. Raise the floor only for a fix that must
not wait on the json, such as 1.3.1's drop-in fix.

## Stopping a bad release

Discovery never downgrades, because it only replaces copies older than the
target. So:

1. Put the previous zip back, so new installs get it, then the previous
   version's json. Rollout stops; sites already on the bad version keep it.
2. Ship the fix as a new, higher version.
