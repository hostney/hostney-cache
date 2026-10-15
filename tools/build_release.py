#!/usr/bin/env python3
"""Build a Hostney Cache release into _dist/hostney-cache/<version>/, like the
migration plugin's releases:

    hostney-cache.zip           the plugin
    hostney-cache.json          { slug, version, sha256, md5, size, commit, built_at }
    RELEASE_NOTES_v<version>.md the changelog entry, requirements and the SHA256

All three come from ONE commit (HEAD of a clean tree). The zip and the json go
to the R2 bucket behind static.hostney.com, folder downloads/: every web
worker's discovery reads the json to learn the published version and replaces
older copies with the zip (api library/hostney_cache_plugin.js). RELEASING.md
has the whole procedure; the upload order is printed at the end.

    python tools/build_release.py              # _dist next to this repo
    python tools/build_release.py --dist DIR   # another _dist folder

Refuses: a dirty tree; a version that differs between the plugin header,
HOSTNEY_CACHE_VERSION and readme.txt's Stable tag; a version the worker would
not take (1.3.1, 1.4, 2.0.0.1 - no suffixes); no changelog entry for it; and a
version folder already built from ANOTHER commit (it may have been uploaded -
release a new version instead).
"""

import argparse
import datetime
import hashlib
import json
import os
import re
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SLUG = "hostney-cache"
ZIP_NAME = f"{SLUG}.zip"
META_NAME = f"{SLUG}.json"
BASE_URL = "https://static.hostney.com/downloads/"
REPO_URL = "https://github.com/hostney/hostney-cache"

# The worker's own rule (api library/hostney_cache_plugin.js RELEASE_VERSION):
# keep the two equal, or a release it refuses goes out as "not published".
RELEASE_VERSION = re.compile(r"^\d{1,4}(?:\.\d{1,4}){1,3}$")
# The worker reads at most this many bytes of the json (META_MAX_BYTES there).
META_MAX_BYTES = 4096
# Never part of the zip (.gitattributes export-ignore).
TOOLING = ("tools/", "RELEASING.md", ".gitattributes")

HEADER_VERSION = re.compile(r"^\s*\*\s*Version:\s*(\S+)\s*$", re.MULTILINE)
CONSTANT_VERSION = re.compile(r"define\(\s*'HOSTNEY_CACHE_VERSION'\s*,\s*'([^']*)'\s*\)")
STABLE_TAG = re.compile(r"^Stable tag:\s*(\S+)\s*$", re.MULTILINE)


def fail(message):
    print(f"refused: {message}", file=sys.stderr)
    sys.exit(1)


def git(*args):
    # autocrlf off: the zip holds the blobs exactly as committed (LF),
    # whatever this checkout's line endings are.
    result = subprocess.run(["git", "-c", "core.autocrlf=false", *args],
                            cwd=ROOT, capture_output=True, text=True, encoding="utf-8")
    if result.returncode != 0:
        fail(f"git {' '.join(args)}: {result.stderr.strip()}")
    return result.stdout


def exactly_one(pattern, text, what):
    found = pattern.findall(text)
    if len(found) != 1:
        fail(f"{what}: expected exactly one, found {len(found)}")
    return found[0].strip()


def versions(php, readme):
    return (exactly_one(HEADER_VERSION, php, "the plugin header's Version"),
            exactly_one(CONSTANT_VERSION, php, "HOSTNEY_CACHE_VERSION"),
            exactly_one(STABLE_TAG, readme, "readme.txt's Stable tag"))


def changelog_entry(readme, version):
    """The bullets under `= <version> =` in readme.txt's == Changelog ==."""
    lines = readme.splitlines()
    try:
        start = lines.index("== Changelog ==")
        start = lines.index(f"= {version} =", start) + 1
    except ValueError:
        fail(f"readme.txt has no changelog entry '= {version} ='")
    entry = []
    for line in lines[start:]:
        if line.startswith("= ") or line.startswith("== "):
            break
        entry.append(line)
    entry = "\n".join(entry).strip()
    if not entry:
        fail(f"readme.txt's changelog entry for {version} is empty")
    return entry


def readme_field(readme, name):
    match = re.search(rf"^{re.escape(name)}:\s*(.+?)\s*$", readme, re.MULTILINE)
    return match.group(1) if match else None


def release_notes(version, readme, sha256, commit):
    wp = readme_field(readme, "Requires at least")
    tested = readme_field(readme, "Tested up to")
    php = readme_field(readme, "Requires PHP")
    requirements = []
    if wp:
        requirements.append(f"- WordPress {wp}+" + (f" (tested up to {tested})" if tested else ""))
    if php:
        requirements.append(f"- PHP {php}+")
    return "\n".join([
        f"# Hostney Cache {version}",
        "",
        changelog_entry(readme, version),
        "",
        "## Requirements",
        "",
        *requirements,
        "- Hosting on Hostney: the page cache it purges and the Redis or Memcached it",
        "  connects to are Hostney's",
        "",
        "## Verify the download",
        "",
        "```",
        f"SHA256  {sha256}  {ZIP_NAME}",
        "```",
        "",
        f"**Built from:** {REPO_URL}/commit/{commit}",
        "",
    ])


def write_atomically(path, data):
    temp = path.with_name(path.name + ".tmp")
    temp.write_bytes(data)
    os.replace(temp, path)


def main():
    parser = argparse.ArgumentParser(description="Build a Hostney Cache release into _dist/hostney-cache/<version>/.")
    parser.add_argument("--dist", type=Path, default=ROOT.parent / "_dist",
                        help="the _dist folder (default: _dist next to this repository)")
    dist_root = parser.parse_args().dist.resolve()
    if not dist_root.is_dir():
        fail(f"{dist_root} does not exist - pass the _dist folder with --dist")

    if git("status", "--porcelain", "--untracked-files=all").strip():
        fail("the working tree is not clean - a release is built from a commit; commit first")

    # The versions as COMMITTED - that is what the zip will hold.
    php = git("show", f"HEAD:{SLUG}.php")
    readme = git("show", "HEAD:readme.txt")
    header, constant, stable = versions(php, readme)
    if not header == constant == stable:
        fail(f"the versions disagree: header {header}, HOSTNEY_CACHE_VERSION {constant}, Stable tag {stable}")
    if not RELEASE_VERSION.match(header):
        fail(f"{header!r} is not a release version the worker accepts (1.3.1, 1.4, 2.0.0.1 - no suffix)")
    version = header
    commit = git("rev-parse", "HEAD").strip()
    changelog_entry(readme, version)  # refuses before anything is written

    out = dist_root / SLUG / version
    previous = out / META_NAME
    if previous.is_file():
        try:
            built_from = json.loads(previous.read_text(encoding="utf-8")).get("commit")
        except (ValueError, OSError):
            built_from = None
        if built_from != commit:
            fail(f"{out} already holds {version} built from {built_from or 'an unreadable json'} - "
                 f"it may have been uploaded. Release a new version instead.")
    out.mkdir(parents=True, exist_ok=True)

    built = out / f"{ZIP_NAME}.tmp"
    git("archive", "--format=zip", f"--prefix={SLUG}/", "-o", str(built), "HEAD")

    with zipfile.ZipFile(built) as archive:
        names = archive.namelist()
        if f"{SLUG}/{SLUG}.php" not in names:
            fail(f"the zip has no {SLUG}/{SLUG}.php")
        outside = [n for n in names if not n.startswith(f"{SLUG}/")]
        if outside:
            fail(f"entries outside {SLUG}/: {outside}")
        tooling = [n for n in names if n[len(SLUG) + 1:].startswith(TOOLING)]
        if tooling:
            fail(f"release tooling in the zip (.gitattributes export-ignore lost?): {tooling}")
        crlf = [n for n in names if not n.endswith("/") and b"\r\n" in archive.read(n)]
        if crlf:
            fail(f"CRLF line endings in the zip: {crlf}")
        zipped = versions(archive.read(f"{SLUG}/{SLUG}.php").decode("utf-8"),
                          archive.read(f"{SLUG}/readme.txt").decode("utf-8"))
        if zipped != (version, version, version):
            fail(f"the zip says {zipped}, the commit says {version}")

    data = built.read_bytes()
    sha256 = hashlib.sha256(data).hexdigest()
    md5 = hashlib.md5(data, usedforsecurity=False).hexdigest()
    meta = {
        "slug": SLUG,
        "version": version,
        "sha256": sha256,
        "md5": md5,
        "size": len(data),
        "commit": commit,
        "built_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    body = (json.dumps(meta, indent=2) + "\n").encode("utf-8")
    if len(body) > META_MAX_BYTES:
        fail(f"the json is {len(body)} bytes - the worker reads at most {META_MAX_BYTES}")
    notes = release_notes(version, readme, sha256, commit).encode("utf-8")

    os.replace(built, out / ZIP_NAME)
    write_atomically(out / META_NAME, body)
    write_atomically(out / f"RELEASE_NOTES_v{version}.md", notes)

    print(f"Hostney Cache {version} from {commit[:12]} -> {out}")
    print(f"  {ZIP_NAME}   {len(data)} bytes, md5 {md5}")
    print(f"  {META_NAME}")
    print(f"  RELEASE_NOTES_v{version}.md")
    print()
    print("Upload to the R2 bucket behind static.hostney.com, folder downloads/ - IN THIS ORDER:")
    print(f"  1. {ZIP_NAME}")
    print("  2. check the zip is the new one - its ETag is the md5 above:")
    print(f"       curl -sI {BASE_URL}{ZIP_NAME} | grep -i etag")
    print(f"  3. {META_NAME}  <- the release starts here: discovery replaces older copies")
    print(f"  4. curl -s {BASE_URL}{META_NAME}")
    print()
    print("A json ahead of its zip makes every replace install the old zip: each worker")
    print("logs one ERROR and stops replacing for 6 hours. Zip first.")


if __name__ == "__main__":
    main()
