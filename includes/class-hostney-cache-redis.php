<?php
/**
 * Hostney Cache - Redis backend
 *
 * Admin-side view of the account's Redis instance: availability, stats, flush.
 * The actual caching is done by the drop-in (object-cache-dropin.tpl), which is
 * self-contained and does not load this file.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Redis extends Hostney_Cache_Backend {

    public function get_engine() {
        return 'redis';
    }

    /**
     * ⚠ "Redis", NOT "Valkey".
     *
     * What actually runs on the server is Valkey, the Linux Foundation's fork.
     * It is wire-compatible, phpredis talks to it unchanged, and nothing a
     * customer does differs. But "Redis" is what they searched for, what the
     * hosting comparison table says, and what every WordPress tutorial calls
     * it. The control panel makes the same call. Valkey is named in the docs
     * page and nowhere in the UI.
     */
    public function get_label() {
        return 'Redis';
    }

    public function get_socket_path() {
        return '/var/run/redis/redis-' . $this->get_system_username() . '.sock';
    }

    public function is_extension_loaded() {
        return extension_loaded( 'redis' );
    }

    /**
     * A connected Redis client, or null.
     *
     * ⚠ NOT PERSISTENT (connect, not pconnect). This is the ADMIN path - it
     * runs on page loads and AJAX calls, not on every front-end request - and a
     * persistent pool held open by admin traffic would sit against the account's
     * connection budget for nothing. The drop-in makes its own decision for the
     * hot path.
     */
    public function get_connection() {
        if ( ! $this->is_extension_loaded() ) {
            return null;
        }

        $socket = $this->get_socket_path();
        if ( ! file_exists( $socket ) ) {
            return null;
        }

        try {
            $redis = new Redis();

            // phpredis treats a path as a unix socket when the port is 0.
            // The timeout is in SECONDS here, unlike the Memcached extension's
            // milliseconds - a mismatch that is easy to copy wrong.
            $connected = $redis->connect( $socket, 0, self::CONNECT_TIMEOUT_MS / 1000 );
            if ( ! $connected ) {
                return null;
            }

            // A socket that accepts a connection is not the same as a server
            // that answers. Verify before reporting availability.
            if ( $redis->ping() === false ) {
                return null;
            }

            return $redis;
        } catch ( Exception $e ) {
            // phpredis throws on connection failure rather than returning
            // false, so this catch is the normal "not running" path, not an
            // exceptional one.
            return null;
        }
    }

    /**
     * Stats in the shared shape.
     *
     * INFO field names differ from Memcached's; this is where that difference
     * stops. maxmemory reads 0 when unset, which the server means as
     * "unlimited" - rendering that as a 0-byte cache would be exactly backwards,
     * so it is passed through and the admin page treats 0 as unknown.
     */
    public function get_stats() {
        $redis = $this->get_connection();
        if ( ! $redis ) {
            return null;
        }

        try {
            $info = $redis->info();
        } catch ( Exception $e ) {
            return null;
        }

        if ( empty( $info ) || ! is_array( $info ) ) {
            return null;
        }

        $hits   = isset( $info['keyspace_hits'] ) ? (int) $info['keyspace_hits'] : 0;
        $misses = isset( $info['keyspace_misses'] ) ? (int) $info['keyspace_misses'] : 0;

        return array(
            'hits'         => $hits,
            'misses'       => $misses,
            'hit_ratio'    => $this->hit_ratio( $hits, $misses ),
            'memory_used'  => isset( $info['used_memory'] ) ? (int) $info['used_memory'] : 0,
            'memory_limit' => isset( $info['maxmemory'] ) ? (int) $info['maxmemory'] : 0,
            'items'        => $this->count_keys( $redis, $info ),
            'uptime'       => isset( $info['uptime_in_seconds'] ) ? (int) $info['uptime_in_seconds'] : 0,
        );
    }

    /**
     * How many keys are cached.
     *
     * ⚠ NEVER `KEYS *` AND NEVER `SCAN` HERE. Both walk the whole keyspace on a
     * cache serving a live site; KEYS blocks the server outright. dbSize() is
     * O(1) and gives the same number.
     *
     * The INFO db0 line is preferred when present because it is already in hand,
     * but INFO OMITS THAT LINE ENTIRELY ON AN EMPTY CACHE - so its absence means
     * zero keys, not "unknown", and falling through to dbSize() covers both.
     */
    private function count_keys( $redis, $info ) {
        if ( isset( $info['db0'] ) && is_string( $info['db0'] ) ) {
            if ( preg_match( '/keys=(\d+)/', $info['db0'], $m ) ) {
                return (int) $m[1];
            }
        }

        try {
            return (int) $redis->dbSize();
        } catch ( Exception $e ) {
            return 0;
        }
    }

    /**
     * Empty the cache.
     *
     * flushDB, not flushAll: the instance is configured with a single database,
     * so they are equivalent today, and flushDB stays correct if that ever
     * changes.
     */
    public function flush() {
        $redis = $this->get_connection();
        if ( ! $redis ) {
            return array(
                'success' => false,
                'message' => 'Could not connect to Redis.',
            );
        }

        try {
            $result = $redis->flushDB();
        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'message' => 'Failed to flush object cache: ' . $e->getMessage(),
            );
        }

        if ( $result ) {
            return array(
                'success' => true,
                'message' => 'Object cache flushed.',
            );
        }

        return array(
            'success' => false,
            'message' => 'Failed to flush object cache.',
        );
    }

    /* ── Per-site keyspace operations ────────────────────────────────── */

    /** Keys requested per SCAN round trip. */
    const SCAN_COUNT = 500;

    /**
     * Hard ceiling on keys examined in one pass.
     *
     * A cap that is hit must be REPORTED, never silently applied - a truncated
     * count rendered as a total reads as "this is everything" and is the one
     * way a keyspace breakdown can actively mislead.
     */
    const SCAN_MAX_KEYS = 250000;

    /**
     * What a site key prefix looks like: 12 hex characters and a colon.
     *
     * ⚠ LOAD-BEARING, AND NOT COSMETIC. It is validated before being used as a
     * SCAN MATCH pattern, and hex-plus-colon contains no glob metacharacter -
     * no *, ?, [ or backslash. That is what makes it safe to concatenate a
     * pattern here without escaping. Widen this and you have to escape.
     */
    const PREFIX_PATTERN = '/^[0-9a-f]{12}:$/';

    public function supports_scoped_flush() {
        return true;
    }

    public function supports_keyspace_registry() {
        return true;
    }

    /**
     * Delete every key belonging to one site, leaving the rest of the account
     * untouched.
     *
     * ⚠⚠ YES, THIS SCANS, AND count_keys() ABOVE SAYS NEVER TO. Both are right.
     * That prohibition is about the STATS PATH, which runs on every admin page
     * load and has an O(1) alternative in dbSize(). This runs only when somebody
     * presses a flush button, and there is no alternative at all: Redis cannot
     * delete by prefix without walking the keyspace. The rule is "never scan on
     * a path that runs by itself", not "never scan".
     *
     * SCAN, never KEYS: KEYS blocks the server for the whole walk, on an
     * instance serving other live sites.
     *
     * @param  string $prefix Key prefix including its trailing colon.
     * @return array{success:bool,message:string,deleted:int}
     */
    public function flush_prefix( $prefix ) {
        if ( ! is_string( $prefix ) || ! preg_match( self::PREFIX_PATTERN, $prefix ) ) {
            return array(
                'success' => false,
                'deleted' => 0,
                'message' => 'Refusing to clear an unrecognised key prefix.',
            );
        }

        $redis = $this->get_connection();
        if ( ! $redis ) {
            return array(
                'success' => false,
                'deleted' => 0,
                'message' => 'Could not connect to Redis.',
            );
        }

        $deleted = 0;
        $scanned = 0;

        try {
            $this->arm_scan( $redis );

            $iterator = null;
            while ( ( $keys = $redis->scan( $iterator, $prefix . '*', self::SCAN_COUNT ) ) !== false ) {
                if ( empty( $keys ) ) {
                    continue;
                }

                $scanned += count( $keys );
                $deleted += (int) $this->delete_keys( $redis, $keys );

                if ( $scanned >= self::SCAN_MAX_KEYS ) {
                    return array(
                        'success' => true,
                        'deleted' => $deleted,
                        'message' => sprintf(
                            'Cleared %d cache entries for this site and stopped at the safety limit. Run it again to clear the rest.',
                            $deleted
                        ),
                    );
                }
            }
        } catch ( Exception $e ) {
            // Partial progress is real progress and the count is not a lie, so
            // report what was deleted rather than implying nothing happened.
            return array(
                'success' => false,
                'deleted' => $deleted,
                'message' => sprintf( 'Cleared %d cache entries, then failed: %s', $deleted, $e->getMessage() ),
            );
        }

        return array(
            'success' => true,
            'deleted' => $deleted,
            'message' => $deleted > 0
                ? sprintf( 'Cleared %d cache entries for this site. Other sites on this account were not touched.', $deleted )
                : 'This site had nothing cached. Other sites on this account were not touched.',
        );
    }

    /**
     * Count the keys in the instance, grouped by site.
     *
     * One pass over the keyspace, on demand only - this is behind a button, not
     * on the admin page render. Anything not matching a known prefix is counted
     * separately rather than being attributed to a site or dropped: unattributed
     * keys are exactly what a deleted domain leaves behind, so they are the most
     * interesting number on the page.
     *
     * @param  string[] $prefixes Known site prefixes from the registry.
     * @return array{counts:array<string,int>,unknown:int,meta:int,scanned:int,partial:bool}|null
     */
    public function scan_keyspace( $prefixes ) {
        $redis = $this->get_connection();
        if ( ! $redis ) {
            return null;
        }

        $counts = array();
        foreach ( (array) $prefixes as $prefix ) {
            if ( is_string( $prefix ) && preg_match( self::PREFIX_PATTERN, $prefix ) ) {
                $counts[ $prefix ] = 0;
            }
        }

        $unknown = 0;
        $meta    = 0;
        $scanned = 0;
        $partial = false;

        try {
            $this->arm_scan( $redis );

            $iterator = null;
            while ( ( $keys = $redis->scan( $iterator, null, self::SCAN_COUNT ) ) !== false ) {
                foreach ( $keys as $key ) {
                    ++$scanned;

                    // The registry hash and anything else the account keeps
                    // outside a site namespace. Counted, never attributed.
                    if ( strpos( $key, 'hostney:' ) === 0 ) {
                        ++$meta;
                        continue;
                    }

                    // A site prefix is a fixed 13 characters, so this is a
                    // substring compare rather than a loop over every prefix
                    // for every key.
                    $candidate = substr( $key, 0, 13 );
                    if ( isset( $counts[ $candidate ] ) ) {
                        ++$counts[ $candidate ];
                    } else {
                        ++$unknown;
                    }
                }

                if ( $scanned >= self::SCAN_MAX_KEYS ) {
                    $partial = true;
                    break;
                }
            }
        } catch ( Exception $e ) {
            return null;
        }

        return array(
            'counts'  => $counts,
            'unknown' => $unknown,
            'meta'    => $meta,
            'scanned' => $scanned,
            'partial' => $partial,
        );
    }

    /**
     * Put the client into retrying SCAN mode.
     *
     * ⚠⚠ WITHOUT THIS THE LOOPS ABOVE STOP EARLY AND REPORT SUCCESS. Plain SCAN
     * returns an EMPTY array for any round trip whose slice of the keyspace
     * matched nothing, which is completely normal mid-iteration. The idiomatic
     * `while ( $keys = $redis->scan(...) )` then treats that empty array as
     * falsey and breaks out with the cursor still open - so a flush silently
     * leaves keys behind and a count silently under-reports, both while looking
     * like they finished. SCAN_RETRY makes phpredis keep going until it has
     * keys or the cursor genuinely closes, and is why the loops here compare
     * against false explicitly rather than relying on truthiness.
     */
    private function arm_scan( $redis ) {
        if ( defined( 'Redis::OPT_SCAN' ) && defined( 'Redis::SCAN_RETRY' ) ) {
            $redis->setOption( Redis::OPT_SCAN, Redis::SCAN_RETRY );
        }
    }

    /**
     * Remove a batch of keys.
     *
     * UNLINK where the server has it: it frees the memory on a background
     * thread, so a large batch does not stall an instance that is serving other
     * people's sites. DEL is the fallback and blocks for the duration.
     */
    private function delete_keys( $redis, array $keys ) {
        if ( method_exists( $redis, 'unlink' ) ) {
            $removed = $redis->unlink( $keys );
            if ( $removed !== false ) {
                return $removed;
            }
        }

        return $redis->del( $keys );
    }
}
