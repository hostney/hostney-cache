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
}
