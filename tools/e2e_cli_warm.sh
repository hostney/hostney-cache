#!/usr/bin/env bash
# 1.3.3 end to end: `wp hostney-cache flush-site` / `warm`, the purge on updates
# and plugin switches, the pre-fetch after updates, and purges from WP-CLI going
# over https. A real WordPress + WP-CLI running this working copy; the rig
# (tools/e2e/hostney-e2e-rig.php) plays this server's nginx for 127.0.0.1.
#
# Run by hand before a release - it needs Docker. Everything it starts is
# removed again.
#
#   bash tools/e2e_cli_warm.sh
#
# E2E_PLUGIN_DIR=<a copy> runs it against another copy of the plugin (to prove a
# check fails on a broken one); E2E_KEEP=1 leaves the containers up.

set -uo pipefail

HERE=$(cd "$(dirname "$0")/.." && (pwd -W 2>/dev/null || pwd))
PLUGIN=${E2E_PLUGIN_DIR:-$HERE}
RIG="$HERE/tools/e2e/hostney-e2e-rig.php"
export MSYS_NO_PATHCONV=1

PORT=${E2E_PORT:-18191}
NAME="hcwarm-$PORT"
WP_IMAGE=${E2E_WP_IMAGE:-wordpress:php8.3-apache}
CLI_IMAGE=${E2E_CLI_IMAGE:-wordpress:cli}
URL="http://localhost:$PORT"
LOG=/var/www/html/wp-content/hny-rig.log
OUT=$(cygpath -m "$(mktemp)" 2>/dev/null || mktemp)
ERRS=$(cygpath -m "$(mktemp)" 2>/dev/null || mktemp)

# The platform's dynamic wp-config block (api library/wp_config.js): WP_HOME from
# the Host header - under the worker's WP-CLI (HTTP_HOST='.') home is "http://.".
EXTRA="define('WP_DEBUG', true); define('WP_DEBUG_LOG', '/tmp/wp-debug.log'); define('WP_DEBUG_DISPLAY', false); if (!empty(\$_SERVER['HTTP_HOST'])) { define('WP_HOME', 'http://' . \$_SERVER['HTTP_HOST']); define('WP_SITEURL', 'http://' . \$_SERVER['HTTP_HOST']); }"

teardown() {
    docker rm -f "$NAME-wp" "$NAME-db" >/dev/null 2>&1 || true
    docker network rm "$NAME" >/dev/null 2>&1 || true
    docker volume rm "$NAME-html" >/dev/null 2>&1 || true
}
cleanup() {
    rm -f "$OUT" "$ERRS"
    if [ -n "${E2E_KEEP:-}" ]; then echo "kept: $URL"; return; fi
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
mounts=(-v "$NAME-html:/var/www/html" -v "$PLUGIN:/var/www/html/wp-content/plugins/hostney-cache:ro" -v "$RIG:/var/www/html/wp-content/mu-plugins/hostney-e2e-rig.php:ro")
db_env=(-e WORDPRESS_DB_HOST="$NAME-db" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp -e WORDPRESS_CONFIG_EXTRA="$EXTRA")
wpcli() { # plain WP-CLI, as an admin over SSH would run it
    docker run --rm --network "$NAME" "${mounts[@]}" --user 33:33 "${db_env[@]}" "$CLI_IMAGE" wp "$@" 2>>"$ERRS"
}
wpw() { # WP-CLI the way the platform's worker runs it (api library/wp_cli.js WP_CLI_ENV)
    docker run --rm --network "$NAME" "${mounts[@]}" --user 33:33 "${db_env[@]}" -e HTTP_HOST=. "$CLI_IMAGE" wp "$@" 2>>"$ERRS"
}
rig_reset() { docker exec -u 33:33 "$NAME-wp" sh -c ": > $LOG"; }
rig_log() { docker exec "$NAME-wp" sh -c "cat $LOG 2>/dev/null"; }
answer() { tail -n 1 "$OUT"; }
field() { answer | grep -o "\"$1\":[^,}]*" | head -1 | cut -d: -f2- | tr -d '"'; }
events() { wpcli cron event list --fields=hook,next_run_gmt --format=csv | grep -c '^hostney_cache_warm_after_change,' ; }

echo "=== $WP_IMAGE + $CLI_IMAGE (port $PORT)"
docker network create "$NAME" >/dev/null
docker run -d --name "$NAME-db" --network "$NAME" \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp \
    mariadb:10.6 >/dev/null
for _ in $(seq 1 90); do
    docker exec -e MYSQL_PWD=wp "$NAME-db" mysql -h127.0.0.1 -uwp -e 'SELECT 1' wp >/dev/null 2>&1 && break
    sleep 2
done

docker volume create "$NAME-html" >/dev/null
docker run -d --name "$NAME-wp" --network "$NAME" -p "$PORT:80" "${db_env[@]}" "${mounts[@]}" "$WP_IMAGE" >/dev/null
for _ in $(seq 1 90); do
    curl -fs "$URL/wp-admin/install.php" >/dev/null && break
    sleep 2
done

wpcli core install --url="$URL" --title=E2E --admin_user=admin --admin_password=e2e-pass --admin_email=e2e@example.com --skip-email >/dev/null
wpcli plugin activate hostney-cache >/dev/null
wpcli plugin is-active hostney-cache || { echo "hostney-cache did not activate - nothing below would mean anything"; exit 1; }
for i in 1 2 3 4 5 6 7 8; do wpcli post create --post_title="Post $i" --post_status=publish >/dev/null; done
wpcli rewrite structure '/%postname%/' >/dev/null

# --- flush-site, no cache service running (the drop-in steps back) ---
wpw hostney-cache flush-site >"$OUT"; rc=$?
[ "$rc" = 0 ] && [ "$(field scope)" = wordpress ] && [ "$(field success)" = true ]; check $? "flush-site without a cache service: WordPress's own flush, exit 0 ($(answer))"

# --- warm the way the worker runs it ---
rig_reset
wpw hostney-cache warm --seconds=60 >"$OUT"; rc=$?
[ "$rc" = 0 ] && [ "$(field status)" = done ] && [ "$(field cache_seen)" = true ]; check $? "warm: done, the cache answered ($(answer))"
total_pages=$(field total); warmed=$(field warmed)
[ "${total_pages:-0}" -ge 9 ] && [ "$warmed" = "$total_pages" ]; check $? "warm: every page of the list warmed ($warmed of $total_pages)"
rig_log | grep '"method":"POST"' | grep -F '"path":"/.well-known/hostney-cache-purge"' | grep -F '"scheme":"https"' | grep -F '"host":"www.e2e.test"' | grep -qF '"redirection":0'; check $? "warm purged first: https, the site's own Host, no redirects followed"
! rig_log | grep '"cli":true' | grep -qF '"scheme":"http"'; check $? "nothing from WP-CLI went over http"
rig_log | grep -F 'Hostney-Cache-Warmer/' | grep -F '"path":"/post-3/"' | grep -F '"scheme":"https"' | grep -qF '"host":"www.e2e.test"'; check $? "pages fetched by path over https with the site's Host (home is http://. under the worker)"
[ "$(rig_log | grep -cF 'Hostney-Cache-Warmer/')" = "$total_pages" ]; check $? "one fetch per page, none twice"

rig_reset
wpw hostney-cache warm --no-purge --seconds=60 >"$OUT"; rc=$?
[ "$rc" = 0 ] && [ "$(field status)" = done ] && ! rig_log | grep -qF 'hostney-cache-purge'; check $? "--no-purge: warmed, and no purge sent"

rig_reset
wpw hostney-cache warm --seconds=1 >"$OUT"; rc=$?
st=$(field status)
{ [ "$st" = continuing ] && [ -n "$(wpcli cron event list --fields=hook --format=csv | grep -x hostney_cache_warm_tick)" ]; } || [ "$st" = done ]; check $? "--seconds=1: out of time = the rest goes to WP-Cron (status $st)"

# --- no page cache ---
wpcli option update hny_rig_cache off >/dev/null
rig_reset
wpw hostney-cache warm >"$OUT"; rc=$?
[ "$rc" = 0 ] && [ "$(field status)" = no_page_cache ] && ! rig_log | grep -qF 'Hostney-Cache-Warmer/'; check $? "no page cache: nothing fetched, nothing to do ($(field status))"

wpcli option update hny_rig_cache probe_only >/dev/null
rig_reset
wpw hostney-cache warm --no-purge >"$OUT"; rc=$?
[ "$(field done)" = 5 ] && answer | grep -qF 'page caching looks switched off'; check $? "pages answered uncached: stops after 5 and says so ($(field done) done)"
wpcli option update hny_rig_cache on >/dev/null

# --- the switch ---
wpcli eval 'Hostney_Cache_Settings::save( array( "warm_after_updates" => "" ) );' >/dev/null
wpw hostney-cache warm --after-updates >"$OUT"; rc=$?
[ "$rc" = 0 ] && [ "$(field status)" = off ]; check $? "switched off: --after-updates does nothing"
wpcli eval 'Hostney_Cache_Settings::save( array( "warm_after_updates" => "1" ) );' >/dev/null
[ "$(wpcli eval 'echo Hostney_Cache_Settings::get( "warm_after_updates" ) ? "on" : "off";')" = on ]; check $? "switched back on"
# ON before this save, so a save that reads "not sent" as "off" cannot pass it.
wpcli eval 'Hostney_Cache_Settings::save( array( "disable_emoji" => "1" ) );' >/dev/null
[ "$(wpcli eval 'echo Hostney_Cache_Settings::get( "warm_after_updates" ) ? "on" : "off";')" = on ]; check $? "a save without the switch keeps it as it was (on)"
wpcli option delete hostney_cache_settings >/dev/null
[ "$(wpcli eval 'echo Hostney_Cache_Settings::get( "warm_after_updates" ) ? "on" : "off";')" = on ]; check $? "a site that never saved settings: on"

# --- updates under WP-CLI: purge, no run asked for ---
rig_reset
wpcli cron event delete hostney_cache_warm_after_change >/dev/null 2>&1
wpw eval 'hny_rig_fire( "update" );' >/dev/null
rig_log | grep '"cli":true' | grep -F 'hostney-cache-purge' | grep -F '"scheme":"https"' | grep -qF '"body":"{\"action\":\"clear\"}"'; check $? "an update under WP-CLI clears the page cache, over https"
[ "$(events)" = 0 ]; check $? "an update under WP-CLI asks for no pre-fetch (the worker asks once, after the run)"
rig_reset
wpw eval 'hny_rig_fire( "install" );' >/dev/null
! rig_log | grep -qF 'hostney-cache-purge'; check $? "an install clears nothing"

# --- updates in wp-admin: purge, and a pre-fetch a minute after the last one ---
rig_reset
curl -s -o /dev/null "$URL/?hny_rig_fire=update"
rig_log | grep '"cli":false' | grep -F 'hostney-cache-purge' | grep -qF '"redirection":0'; check $? "an update in wp-admin clears the page cache (redirects never followed)"
[ "$(events)" = 1 ]; check $? "an update in wp-admin asks for one pre-fetch"
first=$(wpcli cron event list --fields=hook,next_run_gmt --format=csv | grep '^hostney_cache_warm_after_change,' | cut -d, -f2)
sleep 2
curl -s -o /dev/null "$URL/?hny_rig_fire=update"
second=$(wpcli cron event list --fields=hook,next_run_gmt --format=csv | grep '^hostney_cache_warm_after_change,' | cut -d, -f2)
[ "$(events)" = 1 ] && [[ "$second" > "$first" ]]; check $? "a second update moves the one pre-fetch later ($first -> $second)"
wpcli cron event delete hostney_cache_warm_after_change >/dev/null 2>&1
curl -s -o /dev/null "$URL/?hny_rig_fire=activate"
[ "$(events)" = 0 ]; check $? "a plugin switched on: purge only, no pre-fetch"
wpcli eval 'Hostney_Cache_Settings::save( array( "warm_after_updates" => "" ) );' >/dev/null
curl -s -o /dev/null "$URL/?hny_rig_fire=update"
[ "$(events)" = 0 ]; check $? "switched off: an update asks for no pre-fetch"
wpcli eval 'Hostney_Cache_Settings::save( array( "warm_after_updates" => "1" ) );' >/dev/null

# --- the asked-for run itself ---
rig_reset
wpcli eval 'delete_option( Hostney_Cache_Warmer::STATE_OPTION );' >/dev/null
wpw eval 'do_action( Hostney_Cache_Warmer::AFTER_CHANGE_HOOK );' >/dev/null
st=$(wpcli eval '$s = get_option( Hostney_Cache_Warmer::STATE_OPTION ); echo is_array( $s ) ? $s["status"] . " " . $s["total"] : "none";')
[[ "$st" == running* ]] && rig_log | grep -qF 'hostney-cache-purge'; check $? "the asked-for pre-fetch purges and starts a run ($st)"
wpw hostney-cache warm --no-purge --seconds=60 >"$OUT"
[ "$(field status)" = done ] && [ "$(field done)" = "$(field total)" ]; check $? "warm restarts a run already in progress from a fresh list"

# --- deactivation ---
curl -s -o /dev/null "$URL/?hny_rig_fire=update"
wpcli plugin deactivate hostney-cache >/dev/null
[ "$(events)" = 0 ]; check $? "deactivate() clears an asked-for pre-fetch"
wpcli plugin activate hostney-cache >/dev/null

# Nothing above may have warned (WP-CLI's stderr and the site's debug log).
log=$( { cat "$ERRS"; docker exec "$NAME-wp" sh -c 'cat /tmp/wp-debug.log 2>/dev/null'; } | grep -iE 'PHP (Warning|Fatal|Notice|Deprecated|Parse)|^(Warning|Fatal error|Deprecated|Notice):' | grep -i hostney-cache || true)
[ -z "$log" ]; check $? "no PHP warning, notice or error from the plugin"
[ -n "$log" ] && echo "$log" | head -5

echo
echo "$((total - failed))/$total passed ($WP_IMAGE + $CLI_IMAGE)"
[ "$failed" = 0 ]
