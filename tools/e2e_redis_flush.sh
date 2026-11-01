#!/usr/bin/env bash
# `wp hostney-cache flush-site` against a REAL Redis (1.3.3): this site's keys
# go, a neighbour's keys on the same account Redis stay. The platform's update
# worker runs it after every update run, so this is the path that matters.
#
# The WordPress images have no phpredis, so this builds a WP-CLI image with it
# (and a user for uid 33: the drop-in finds the account's socket by the name
# PHP runs as - redis-<user>.sock - and a uid with no name finds none).
#
#   bash tools/e2e_redis_flush.sh
#
# E2E_PLUGIN_DIR=<a copy> runs it against another copy; E2E_KEEP=1 keeps it up.

set -uo pipefail

HERE=$(cd "$(dirname "$0")/.." && (pwd -W 2>/dev/null || pwd))
PLUGIN=${E2E_PLUGIN_DIR:-$HERE}
export MSYS_NO_PATHCONV=1

PORT=${E2E_PORT:-18192}
NAME="hcredis-$PORT"
WP_IMAGE=${E2E_WP_IMAGE:-wordpress:php8.3-apache}
CLI_IMAGE=hostney-cache-e2e/cli-redis
SOCK=/var/run/redis/redis-hnysite.sock
URL="http://localhost:$PORT"
OUT=$(cygpath -m "$(mktemp)" 2>/dev/null || mktemp)
ERRS=$(cygpath -m "$(mktemp)" 2>/dev/null || mktemp)
BUILD=$(mktemp -d)

teardown() {
    docker rm -f "$NAME-wp" "$NAME-db" "$NAME-redis" >/dev/null 2>&1 || true
    docker network rm "$NAME" >/dev/null 2>&1 || true
    docker volume rm "$NAME-html" "$NAME-sock" >/dev/null 2>&1 || true
}
cleanup() {
    rm -rf "$OUT" "$ERRS" "$BUILD"
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

cat > "$BUILD/Dockerfile" <<'EOF'
FROM wordpress:cli
USER root
RUN apk add --no-cache $PHPIZE_DEPS \
 && yes '' | pecl install redis \
 && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS \
 && (addgroup -g 33 hnysite 2>/dev/null || true) \
 && adduser -u 33 -D -H -G "$(getent group 33 | cut -d: -f1)" hnysite
USER 33:33
EOF
docker build -q -t "$CLI_IMAGE" "$(cygpath -m "$BUILD" 2>/dev/null || echo "$BUILD")" >/dev/null || { echo "could not build $CLI_IMAGE"; exit 1; }

mounts=(-v "$NAME-html:/var/www/html" -v "$PLUGIN:/var/www/html/wp-content/plugins/hostney-cache:ro" -v "$NAME-sock:/var/run/redis")
db_env=(-e WORDPRESS_DB_HOST="$NAME-db" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp)
wpcli() {
    docker run --rm --network "$NAME" "${mounts[@]}" --user 33:33 "${db_env[@]}" "$CLI_IMAGE" wp "$@" 2>>"$ERRS"
}
wpw() { # as the platform's worker runs it (HTTP_HOST='.')
    docker run --rm --network "$NAME" "${mounts[@]}" --user 33:33 "${db_env[@]}" -e HTTP_HOST=. "$CLI_IMAGE" wp "$@" 2>>"$ERRS"
}
rcli() { docker exec "$NAME-redis" redis-cli -s "$SOCK" "$@"; }
answer() { tail -n 1 "$OUT"; }
field() { answer | grep -o "\"$1\":[^,}]*" | head -1 | cut -d: -f2- | tr -d '"'; }

echo "=== real Redis + $CLI_IMAGE (port $PORT)"
docker network create "$NAME" >/dev/null
docker volume create "$NAME-sock" >/dev/null
docker run -d --name "$NAME-db" --network "$NAME" \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp \
    mariadb:10.6 >/dev/null
# As root and straight to redis-server: the image's entrypoint would drop to
# its redis user, which cannot create the socket in the root-owned volume.
docker run -d --name "$NAME-redis" --user 0:0 --entrypoint redis-server -v "$NAME-sock:/var/run/redis" \
    redis:7-alpine --port 0 --unixsocket "$SOCK" --unixsocketperm 777 --save '' --appendonly no >/dev/null
for _ in $(seq 1 90); do
    docker exec -e MYSQL_PWD=wp "$NAME-db" mysql -h127.0.0.1 -uwp -e 'SELECT 1' wp >/dev/null 2>&1 && break
    sleep 2
done
docker volume create "$NAME-html" >/dev/null
docker run -d --name "$NAME-wp" --network "$NAME" -p "$PORT:80" "${db_env[@]}" -v "$NAME-html:/var/www/html" \
    -v "$PLUGIN:/var/www/html/wp-content/plugins/hostney-cache:ro" "$WP_IMAGE" >/dev/null
for _ in $(seq 1 90); do
    curl -fs "$URL/wp-admin/install.php" >/dev/null && break
    sleep 2
done
[ "$(rcli PING 2>/dev/null)" = PONG ] || { echo "Redis did not come up on $SOCK"; exit 1; }

wpcli core install --url="$URL" --title=E2E --admin_user=admin --admin_password=e2e-pass --admin_email=e2e@example.com --skip-email >/dev/null
wpcli plugin activate hostney-cache >/dev/null
wpcli plugin is-active hostney-cache || { echo "hostney-cache did not activate - nothing below would mean anything"; exit 1; }

[ "$(wpcli eval 'echo wp_using_ext_object_cache() ? "redis" : "none";')" = redis ]; check $? "WP-CLI reaches the account Redis through the drop-in (the 11-01 posix trap would read none)"
PREFIX=$(wpcli eval 'echo $GLOBALS["wp_object_cache"]->hostney_key_prefix();')
[ -n "$PREFIX" ]; check $? "the site has its key prefix ($PREFIX)"

wpcli cache set hny_probe 42 e2e >/dev/null
rcli SET "deadbeefcafe0000:e2e:neighbour" keep >/dev/null
[ "$(wpcli cache get hny_probe e2e)" = 42 ] && [ "$(rcli --scan --pattern "${PREFIX}*" | wc -l)" -gt 0 ]; check $? "a value of this site is in Redis under its prefix"

wpw hostney-cache flush-site >"$OUT"; rc=$?
[ "$rc" = 0 ] && [ "$(field scope)" = site ] && [ "$(field engine)" = redis ] && [ "$(field success)" = true ]; check $? "flush-site as the worker runs it: this site, Redis ($(answer))"
! wpcli cache get hny_probe e2e >/dev/null 2>&1; check $? "this site's value is gone"
[ "$(rcli GET "deadbeefcafe0000:e2e:neighbour")" = keep ]; check $? "a neighbour's key on the same Redis is untouched"

# What the worker falls back to without 1.3.3: the whole account.
wpcli cache set hny_probe 43 e2e >/dev/null
wpcli cache flush >/dev/null
[ -z "$(rcli GET "deadbeefcafe0000:e2e:neighbour")" ]; check $? "(for contrast) wp cache flush through the drop-in empties the neighbour too"

log=$(grep -iE 'PHP (Warning|Fatal|Notice|Deprecated|Parse)|^(Warning|Fatal error|Deprecated|Notice):' "$ERRS" | grep -i hostney-cache || true)
[ -z "$log" ]; check $? "no PHP warning, notice or error from the plugin"
[ -n "$log" ] && echo "$log" | head -5

echo
echo "$((total - failed))/$total passed (real Redis)"
[ "$failed" = 0 ]
