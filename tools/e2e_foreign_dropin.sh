#!/usr/bin/env bash
# The foreign object-cache.php banner (1.3.2), end to end: a real WordPress
# running this working copy, driven over HTTP as a logged-in admin.
#
# Run by hand before a release - it needs Docker. Everything it starts is
# removed again.
#
#   bash tools/e2e_foreign_dropin.sh
#
# Checks: the banner names the foreign drop-in's plugin (escaped) on the
# Dashboard, the Plugins screen and the Hostney Cache page, and nowhere else;
# "Keep this drop-in" quiets the first two for THAT plugin only; another
# plugin's drop-in brings it back; "Replace" writes Hostney Cache's drop-in and
# forgets the kept choice; no PHP warning or error in the log.

set -uo pipefail

HERE=$(cd "$(dirname "$0")/.." && (pwd -W 2>/dev/null || pwd))
export MSYS_NO_PATHCONV=1

PORT=${E2E_PORT:-18190}
NAME="hce2e-$PORT"
WP_IMAGE=${E2E_WP_IMAGE:-wordpress:php8.3-apache}
URL="http://localhost:$PORT"
# Mixed (C:/...) paths: with MSYS_NO_PATHCONV set, a Windows curl reads /tmp/x as
# \tmp\x on the current drive while grep reads the real /tmp/x - two different files.
JAR=$(cygpath -m "$(mktemp)" 2>/dev/null || mktemp)
PAGE=$(cygpath -m "$(mktemp)" 2>/dev/null || mktemp)

teardown() {
    docker rm -f "$NAME-wp" "$NAME-db" >/dev/null 2>&1 || true
    docker network rm "$NAME" >/dev/null 2>&1 || true
    docker volume rm "$NAME-html" >/dev/null 2>&1 || true
}
cleanup() {
    rm -f "$JAR" "$PAGE"
    # E2E_KEEP=1 leaves the containers up to look at; the next run removes them.
    if [ -n "${E2E_KEEP:-}" ]; then echo "kept: $URL (admin / e2e-pass)"; return; fi
    teardown
}
trap cleanup EXIT
teardown

failed=0
total=0
check() { # check <ok 0|1> <label>
    total=$((total + 1))
    if [ "$1" = 0 ]; then echo "PASS  $2"; else echo "FAIL  $2"; failed=$((failed + 1)); fi
}
has() { grep -qF -- "$1" "$PAGE"; }
wpcli() {
    # The plugin is a bind mount over the volume in the web container, so this
    # container needs the same mount or WP-CLI sees no hostney-cache at all.
    docker run --rm --network "$NAME" -v "$NAME-html:/var/www/html" --user 33:33 \
        -v "$HERE:/var/www/html/wp-content/plugins/hostney-cache:ro" \
        -e WORDPRESS_DB_HOST="$NAME-db" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
        wordpress:cli wp "$@"
}
dropin() { # dropin <plugin name>: a header-only foreign object-cache.php (harmless: WordPress falls back to its own cache)
    printf '<?php\n/**\n * Plugin Name: %s\n */\n' "$1" | docker exec -i -u 33:33 "$NAME-wp" sh -c 'cat > /var/www/html/wp-content/object-cache.php'
}
fetch() { curl -s -b "$JAR" -c "$JAR" -o "$PAGE" "$URL$1"; }
nonce() { grep -o 'name="_hostney_nonce" value="[^"]*"' "$PAGE" | head -1 | sed 's/.*value="//; s/"$//'; }
# A real nonce, captured once from a page that shows the banner and reused, so a
# POST made while no form is on screen is still a VALID request - the test must
# prove the handler refuses, not that a missing nonce does.
NONCE=""
post() { # post <action> [extra form fields...]
    local args=(-s -b "$JAR" -c "$JAR" -o "$PAGE" -e "$URL/wp-admin/" --data-urlencode "action=$1" --data-urlencode "_hostney_nonce=$NONCE" --data-urlencode "_wp_http_referer=/wp-admin/")
    shift
    for f in "$@"; do args+=(--data-urlencode "$f"); done
    curl "${args[@]}" "$URL/wp-admin/admin-post.php"
}

BANNER="This site's object cache is not Hostney Cache's."
KEEP="Keep this drop-in"

echo "=== $WP_IMAGE (port $PORT)"
docker network create "$NAME" >/dev/null
docker run -d --name "$NAME-db" --network "$NAME" \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp \
    mariadb:10.6 >/dev/null
for _ in $(seq 1 90); do
    docker exec -e MYSQL_PWD=wp "$NAME-db" mysql -h127.0.0.1 -uwp -e 'SELECT 1' wp >/dev/null 2>&1 && break
    sleep 2
done

docker volume create "$NAME-html" >/dev/null
docker run -d --name "$NAME-wp" --network "$NAME" -p "$PORT:80" \
    -e WORDPRESS_DB_HOST="$NAME-db" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
    -e WORDPRESS_CONFIG_EXTRA="define('WP_DEBUG', true); define('WP_DEBUG_LOG', '/tmp/wp-debug.log'); define('WP_DEBUG_DISPLAY', false);" \
    -v "$NAME-html:/var/www/html" \
    -v "$HERE:/var/www/html/wp-content/plugins/hostney-cache:ro" \
    "$WP_IMAGE" >/dev/null
for _ in $(seq 1 90); do
    curl -fs "$URL/wp-admin/install.php" >/dev/null && break
    sleep 2
done

wpcli core install --url="$URL" --title=E2E --admin_user=admin --admin_password=e2e-pass --admin_email=e2e@example.com --skip-email >/dev/null
wpcli plugin activate hostney-cache >/dev/null
wpcli option get siteurl >/dev/null || { echo "WordPress did not install"; exit 1; }
# Without this, every "no banner" check below passes for the wrong reason.
wpcli plugin is-active hostney-cache || { echo "hostney-cache did not activate - nothing below would mean anything"; exit 1; }

# activate() installed Hostney's own drop-in into the free slot - no banner then.
curl -s -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" \
    --data-urlencode "log=admin" --data-urlencode "pwd=e2e-pass" --data-urlencode "testcookie=1" \
    -o "$PAGE" "$URL/wp-login.php"
fetch /wp-admin/
! has "$BANNER" && has "Dashboard"; check $? "our own drop-in (from activate()): no banner, and we are logged in"

# A foreign drop-in, with markup in its name.
dropin 'Redis Object Cache <b>Drop-In</b>'
fetch /wp-admin/
has "$BANNER" && has 'Redis Object Cache &lt;b&gt;Drop-In&lt;/b&gt;' && ! has '<b>Drop-In</b>'; check $? "Dashboard: banner names the plugin, escaped"
has "$KEEP"; check $? "Dashboard: offers Keep this drop-in"
NONCE=$(nonce)
[ -n "$NONCE" ]; check $? "the banner's forms carry a nonce"
fetch /wp-admin/plugins.php
has "$BANNER"; check $? "Plugins screen: banner"
fetch "/wp-admin/admin.php?page=hostney-cache"
has "$BANNER" && ! has "$KEEP"; check $? "Hostney Cache page: banner, no Keep (the plugin's own page always tells)"
fetch /wp-admin/edit.php
! has "$BANNER"; check $? "Posts screen: no banner (not on every admin screen)"
fetch /wp-admin/options-general.php
! has "$BANNER"; check $? "Settings screen: no banner"

# Keep it.
fetch /wp-admin/
post hostney_object_cache_keep_foreign_dropin
[ "$(wpcli option get hostney_cache_kept_foreign_dropin 2>/dev/null)" = 'Redis Object Cache <b>Drop-In</b>' ]; check $? "Keep stores the drop-in's own name (read from the file)"
fetch /wp-admin/
! has "$BANNER"; check $? "kept: Dashboard quiet"
fetch /wp-admin/plugins.php
! has "$BANNER"; check $? "kept: Plugins screen quiet"
fetch "/wp-admin/admin.php?page=hostney-cache"
has "$BANNER"; check $? "kept: Hostney Cache page still tells"

# Another plugin's drop-in brings it back.
dropin 'Object Cache Pro (Drop-in)'
fetch /wp-admin/
has "$BANNER" && has 'Object Cache Pro (Drop-in)'; check $? "a different plugin's drop-in: banner back"

# Replace.
post hostney_object_cache_install_dropin force=1
head -c 400 < <(docker exec "$NAME-wp" cat /var/www/html/wp-content/object-cache.php) | grep -qF "Hostney Cache Drop-in"; check $? "Replace writes Hostney Cache's drop-in"
wpcli option get hostney_cache_kept_foreign_dropin >/dev/null 2>&1; [ $? != 0 ]; check $? "Replace forgets the kept choice"
fetch /wp-admin/
! has "$BANNER"; check $? "replaced: no banner"

# A kept choice cannot be written without a foreign drop-in in place.
post hostney_object_cache_keep_foreign_dropin
wpcli option get hostney_cache_kept_foreign_dropin >/dev/null 2>&1; [ $? != 0 ]; check $? "Keep with our own drop-in in place stores nothing"

# Deactivation tidies the option.
dropin 'Redis Object Cache Drop-In'
fetch /wp-admin/
post hostney_object_cache_keep_foreign_dropin
wpcli plugin deactivate hostney-cache >/dev/null
wpcli option get hostney_cache_kept_foreign_dropin >/dev/null 2>&1; [ $? != 0 ]; check $? "deactivate() removes the kept choice (and runs under WP-CLI)"

# Nothing above may have warned.
log=$(docker exec "$NAME-wp" sh -c 'cat /tmp/wp-debug.log 2>/dev/null' | grep -iE 'PHP (Warning|Fatal|Notice|Deprecated|Parse)' | grep -i hostney-cache || true)
[ -z "$log" ]; check $? "no PHP warning, notice or error from the plugin"
[ -n "$log" ] && echo "$log" | head -5

echo
echo "$((total - failed))/$total passed ($WP_IMAGE)"
[ "$failed" = 0 ]
