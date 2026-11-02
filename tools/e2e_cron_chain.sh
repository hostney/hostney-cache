#!/usr/bin/env bash
# 1.3.4 end to end: a pre-fetch run on WP-Cron moves itself on. Until 1.3.4 each
# batch waited for WP-Cron, which runs only when a visit reaches PHP - on a cached
# site almost none do - and a run more than five minutes without a batch was dead
# while its state said "running" for good (a shop stopped at 33 of 128 pages for
# 12 hours, 2026-11-02). Now each cron batch starts the next cron request itself,
# over 127.0.0.1 with the site's Host, once WordPress has let go of the cron lock.
#
# The run is started on WP-Cron with nothing open, then exactly ONE request reaches
# wp-cron.php from outside - the visitor who misses the cache. Nothing else comes
# from outside: the run must finish on its own. The rig (tools/e2e/hostney-e2e-rig.php)
# plays nginx for the page fetches and lets the cron kicks through to this container.
#
# ⚠ During the run the state is read from the DATABASE, never through WP-CLI: WP-CLI
# runs WordPress's own spawn_cron(), which takes the cron lock for a minute (its
# request to the public address goes nowhere here) - the chain would wait it out.
#
#   bash tools/e2e_cron_chain.sh
#
# E2E_PLUGIN_DIR=<a copy> runs it against another copy of the plugin (to prove a
# check fails on a broken one); E2E_KEEP=1 leaves the containers up.

set -uo pipefail

HERE=$(cd "$(dirname "$0")/.." && (pwd -W 2>/dev/null || pwd))
PLUGIN=${E2E_PLUGIN_DIR:-$HERE}
RIG="$HERE/tools/e2e/hostney-e2e-rig.php"
export MSYS_NO_PATHCONV=1

PORT=${E2E_PORT:-18192}
NAME="hccron-$PORT"
WP_IMAGE=${E2E_WP_IMAGE:-wordpress:php8.3-apache}
CLI_IMAGE=${E2E_CLI_IMAGE:-wordpress:cli}
URL="http://localhost:$PORT"
LOG=/var/www/html/wp-content/hny-rig.log
POSTS=${E2E_POSTS:-30}

EXTRA="define('WP_DEBUG', true); define('WP_DEBUG_LOG', '/tmp/wp-debug.log'); define('WP_DEBUG_DISPLAY', false); if (!empty(\$_SERVER['HTTP_HOST'])) { define('WP_HOME', 'http://' . \$_SERVER['HTTP_HOST']); define('WP_SITEURL', 'http://' . \$_SERVER['HTTP_HOST']); }"

teardown() {
    docker rm -f "$NAME-wp" "$NAME-db" >/dev/null 2>&1 || true
    docker network rm "$NAME" >/dev/null 2>&1 || true
    docker volume rm "$NAME-html" >/dev/null 2>&1 || true
}
cleanup() {
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
wpcli() { docker run --rm --network "$NAME" "${mounts[@]}" --user 33:33 "${db_env[@]}" "$CLI_IMAGE" wp "$@" 2>/dev/null; }
sql() { docker exec -e MYSQL_PWD=wp "$NAME-db" mysql -h127.0.0.1 -uwp -N -e "$1" wp; }
state_raw() { sql "SELECT option_value FROM wp_options WHERE option_name='hostney_cache_warm_state'"; }
state_str() { state_raw | grep -o "s:${#1}:\"$1\";s:[0-9]*:\"[a-z_]*\"" | sed 's/.*:"\([a-z_]*\)"$/\1/'; }
state_int() { state_raw | grep -o "s:${#1}:\"$1\";i:[0-9]*" | sed 's/.*;i://'; }
kicks() { docker exec "$NAME-wp" sh -c "cat $LOG 2>/dev/null" | grep -F '"path":"/wp-cron.php"' | grep -cF '"cli":false'; }

echo "=== $WP_IMAGE + $CLI_IMAGE (port $PORT, plugin $PLUGIN)"
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
for i in $(seq 1 "$POSTS"); do wpcli post create --post_title="Post $i" --post_status=publish >/dev/null; done
wpcli rewrite structure '/%postname%/' >/dev/null
# Each page takes 150 ms and a batch warms for 1 s: the run needs several batches.
wpcli option update hny_rig_page_ms 150 >/dev/null
wpcli option update hny_rig_batch_seconds 1 >/dev/null
wpcli option update hny_rig_warm_delay_ms 0 >/dev/null

# --- a run on WP-Cron, nothing open: what an update in wp-admin leaves behind ---
# Started through WP-CLI, whose own cron spawn (WordPress runs one as that request ends: the tick is
# due) takes the lock for a minute and posts to a public address that goes nowhere here. The lock is
# cleared in the database AFTER that process has ended - inside it, the spawn takes it again.
started=$(wpcli eval '$w = new Hostney_Cache_Warmer( new Hostney_Cache_Purger() ); $r = $w->start( false ); echo $r["success"] ? "started" : "no: " . $r["message"];')
sql "DELETE FROM wp_options WHERE option_name IN ('_transient_doing_cron', '_transient_timeout_doing_cron')"
[ "$started" = started ]; check $? "a run started ($started)"
pages=$(state_int total)
[ "${pages:-0}" -gt 20 ]; check $? "the run has more pages than one batch can warm ($pages)"
docker exec -u 33:33 "$NAME-wp" sh -c ": > $LOG"

# ONE request reaches PHP from outside - the visitor who misses the cache.
curl -s -o /dev/null --max-time 120 "$URL/wp-cron.php"

for _ in $(seq 1 60); do
    [ "$(state_str status)" = done ] && break
    sleep 2
done
st=$(state_str status); done_n=$(state_int done); warmed=$(state_int warmed)
[ "$st" = done ] && [ "$warmed" = "$pages" ]; check $? "the run finished on its own after one outside request (status $st, $warmed of $pages warmed, $done_n done)"
n=$(kicks)
[ "${n:-0}" -ge 2 ]; check $? "it started its own cron requests ($n), one per batch"
docker exec "$NAME-wp" sh -c "cat $LOG" | grep -F '"path":"/wp-cron.php"' | grep -F '"method":"POST"' | grep -F '"host":"www.e2e.test"' | grep -qF 'Hostney-Cache-Warmer/'; check $? "each one went to 127.0.0.1 with the site's own Host, named"
sleep 6
[ "$(kicks)" = "$n" ]; check $? "a finished run starts no more cron requests (still $n)"

# --- a dead run is saved as failed, not left "running" for good ---
wpcli eval 'update_option( "hostney_cache_warm_state", array( "status" => "running", "total" => 9, "done" => 3, "warmed" => 3, "started_at" => time() - 1200, "updated_at" => time() - 1000, "finished_at" => 0, "run_id" => "e2e" ), false );' >/dev/null
wpcli eval '( new Hostney_Cache_Warmer( new Hostney_Cache_Purger() ) )->get_state();' >/dev/null
[ "$(state_str status)" = failed ]; check $? "a run nothing moved for five minutes is SAVED as failed ($(state_str status))"
[ "$(state_int finished_at)" = "$(state_int updated_at)" ]; check $? "its finished_at is when it last moved"

echo
echo "$((total - failed)) passed, $failed failed"
[ "$failed" = 0 ]
