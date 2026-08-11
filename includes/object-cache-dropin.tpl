<?php
/**
 * Hostney Cache Drop-in — WordPress Object Cache
 *
 * This file is managed by the Hostney Cache plugin.
 * Do not edit it directly — changes will be overwritten on the next update.
 *
 * Supports Redis (ext-redis) and Memcached (ext-memcached). The backend is
 * chosen AT RUNTIME by probing for each engine's socket, so switching engines
 * in the Hostney control panel needs no change here and no action on the site.
 *
 * @version 1.2.0
 */

/* Hostney Cache Drop-in */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Initialise the object cache.
 */
function wp_cache_init() {
    $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}

/**
 * Retrieve a cached value.
 */
function wp_cache_get( $key, $group = 'default', $force = false, &$found = null ) {
    return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
}

/**
 * Store a value in the cache.
 */
function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) {
    return $GLOBALS['wp_object_cache']->set( $key, $data, $group, $expire );
}

/**
 * Add a value only if it does not already exist.
 */
function wp_cache_add( $key, $data, $group = 'default', $expire = 0 ) {
    return $GLOBALS['wp_object_cache']->add( $key, $data, $group, $expire );
}

/**
 * Replace a value only if it already exists.
 */
function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) {
    return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, $expire );
}

/**
 * Delete a cached value.
 */
function wp_cache_delete( $key, $group = 'default' ) {
    return $GLOBALS['wp_object_cache']->delete( $key, $group );
}

/**
 * Increment a numeric value.
 */
function wp_cache_incr( $key, $offset = 1, $group = 'default' ) {
    return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group );
}

/**
 * Decrement a numeric value.
 */
function wp_cache_decr( $key, $offset = 1, $group = 'default' ) {
    return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group );
}

/**
 * Flush the entire cache.
 */
function wp_cache_flush() {
    return $GLOBALS['wp_object_cache']->flush();
}

/**
 * Close the connection.
 */
function wp_cache_close() {
    return true;
}

/**
 * Register global cache groups.
 */
function wp_cache_add_global_groups( $groups ) {
    $GLOBALS['wp_object_cache']->add_global_groups( $groups );
}

/**
 * Register non-persistent cache groups (kept in local memory only).
 */
function wp_cache_add_non_persistent_groups( $groups ) {
    $GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
}

/**
 * Switch the cache to a different blog (multisite).
 */
function wp_cache_switch_to_blog( $blog_id ) {
    $GLOBALS['wp_object_cache']->switch_to_blog( $blog_id );
}

/**
 * Retrieve multiple cached values at once.
 */
function wp_cache_get_multiple( $keys, $group = 'default', $force = false ) {
    return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force );
}

/**
 * Delete multiple cached values at once.
 */
function wp_cache_delete_multiple( $keys, $group = 'default' ) {
    return $GLOBALS['wp_object_cache']->delete_multiple( $keys, $group );
}

/**
 * Flush the cache for one group.
 */
function wp_cache_flush_group( $group ) {
    return $GLOBALS['wp_object_cache']->flush_group( $group );
}

/**
 * Check whether a feature is supported.
 */
function wp_cache_supports( $feature ) {
    return $GLOBALS['wp_object_cache']->supports( $feature );
}

/**
 * WordPress Object Cache backed by Redis or Memcached.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * SELF-CONTAINED. This file is loaded during WordPress bootstrap, long before
 * plugins exist, so it must not depend on the Hostney Cache plugin being active
 * or even installed. Everything it needs is in this file.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * THE BACKEND IS CHOSEN PER REQUEST, by probing for a socket. That is the whole
 * point of the 1.2.0 rewrite: an account that switches from Memcached to Redis
 * in the control panel needs no plugin action, no re-install and no drop-in
 * rewrite. The next PHP request finds the new socket and connects.
 *
 * Falls back to a non-persistent in-memory array when neither engine answers,
 * which is what WordPress does with no drop-in at all - the site works, it just
 * rebuilds every value per request.
 */
class WP_Object_Cache {

    /** @var Redis|Memcached|null */
    private $client = null;

    /** @var string '' | 'redis' | 'memcached' */
    private $engine = '';

    /** @var bool Whether a persistent backend is connected */
    private $connected = false;

    /** @var array In-memory cache. Always used for non-persistent groups, and the whole cache when no backend answers. */
    private $cache = array();

    /** @var array Non-persistent groups — never sent to the backend */
    private $non_persistent_groups = array();

    /** @var array Global groups — not prefixed with the blog ID */
    private $global_groups = array();

    /** @var string Key prefix derived from $table_prefix */
    private $key_prefix = '';

    /** @var string Current blog prefix */
    private $blog_prefix = '';

    /** @var int */
    public $cache_hits = 0;

    /** @var int */
    public $cache_misses = 0;

    public function __construct() {
        global $table_prefix, $blog_id;

        $this->key_prefix  = substr( md5( $table_prefix ), 0, 8 ) . ':';
        $this->blog_prefix = ( function_exists( 'is_multisite' ) && is_multisite() ? (int) $blog_id : 1 ) . ':';

        $this->connect();
    }

    /**
     * Probe for a backend and connect to the first one that answers.
     *
     * ⚠ REDIS IS TRIED FIRST, AND THE SAME ORDER IS IN THE PLUGIN
     * (Hostney_Cache_Object_Cache::__construct). The two must agree, or the
     * admin page reports one engine while the site caches into the other.
     *
     * Accounts run exactly one engine, so the order should never decide
     * anything. It matters only in the seconds during a switch when both
     * sockets can exist.
     */
    private function connect() {
        $username = $this->detect_username();
        if ( $username === '' ) {
            return;
        }

        if ( $this->connect_redis( '/var/run/redis/redis-' . $username . '.sock' ) ) {
            return;
        }

        $this->connect_memcached( '/var/run/memcached/memcached-' . $username . '.sock' );
    }

    /**
     * The Linux account PHP runs as. Both socket paths are derived from it.
     */
    private function detect_username() {
        if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
            $info = posix_getpwuid( posix_geteuid() );
            if ( $info && ! empty( $info['name'] ) ) {
                return $info['name'];
            }
        }

        $name = get_current_user();
        return is_string( $name ) ? $name : '';
    }

    /**
     * @return bool True when connected.
     */
    private function connect_redis( $socket ) {
        if ( ! extension_loaded( 'redis' ) || ! file_exists( $socket ) ) {
            return false;
        }

        try {
            $redis = new Redis();

            // Port 0 means "the first argument is a unix socket".
            // ⚠ THE TIMEOUT IS IN SECONDS HERE. The Memcached extension below
            // wants milliseconds for the same idea. 0.5s is generous for a
            // socket on the same box, and short enough that a dead cache costs
            // half a second rather than hanging the page.
            if ( ! @$redis->connect( $socket, 0, 0.5 ) ) {
                return false;
            }

            // Store PHP-serialized values, so objects and arrays survive the
            // round trip. Without this phpredis stores everything as a string
            // and every cached array comes back as "Array".
            $redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );

            // A socket that accepts a connection is not a server that answers.
            if ( @$redis->ping() === false ) {
                return false;
            }

            $this->client    = $redis;
            $this->engine    = 'redis';
            $this->connected = true;
            return true;
        } catch ( Exception $e ) {
            // phpredis THROWS on connection failure rather than returning
            // false, so this is the ordinary "not running" path. An uncaught
            // exception here would be a fatal error during bootstrap, i.e. a
            // white screen on every page of the site.
            return false;
        }
    }

    /**
     * @return bool True when connected.
     */
    private function connect_memcached( $socket ) {
        if ( ! extension_loaded( 'memcached' ) || ! file_exists( $socket ) ) {
            return false;
        }

        $mc = new Memcached( 'hostney_object_cache' );

        // The persistent-id constructor reuses a pooled server list, so adding
        // the server again on every request would grow the pool without bound.
        $servers = $mc->getServerList();
        if ( empty( $servers ) ) {
            $mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, 500 );   // milliseconds
            $mc->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );  // microseconds
            $mc->setOption( Memcached::OPT_RECV_TIMEOUT, 1000000 );  // microseconds
            $mc->setOption( Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_PHP );
            $mc->setOption( Memcached::OPT_COMPRESSION, true );
            $mc->addServer( $socket, 0 );
        }

        $version = @$mc->getVersion();
        if ( empty( $version ) || $mc->getResultCode() !== Memcached::RES_SUCCESS ) {
            return false;
        }

        $this->client    = $mc;
        $this->engine    = 'memcached';
        $this->connected = true;
        return true;
    }

    /* ── Backend primitives ──────────────────────────────────────────────
       The only four places this class knows which engine it is talking to.
       Everything below them is engine-agnostic.
       ──────────────────────────────────────────────────────────────────── */

    /**
     * @param mixed $found Set to true/false so a legitimately cached `false`
     *                     is not mistaken for a miss. That distinction is the
     *                     reason this returns via a reference rather than
     *                     returning false for both cases.
     */
    private function backend_get( $key, &$found ) {
        $found = false;
        if ( ! $this->connected ) {
            return false;
        }

        if ( $this->engine === 'redis' ) {
            try {
                $value = $this->client->get( $key );
            } catch ( Exception $e ) {
                return false;
            }
            // phpredis returns false for a missing key AND for a stored false.
            // exists() is the only way to tell them apart.
            if ( $value === false ) {
                try {
                    if ( ! $this->client->exists( $key ) ) {
                        return false;
                    }
                } catch ( Exception $e ) {
                    return false;
                }
            }
            $found = true;
            return $value;
        }

        $value = $this->client->get( $key );
        if ( $this->client->getResultCode() !== Memcached::RES_SUCCESS ) {
            return false;
        }
        $found = true;
        return $value;
    }

    private function backend_set( $key, $data, $expire ) {
        if ( ! $this->connected ) {
            return true;
        }

        $expire = max( 0, (int) $expire );

        if ( $this->engine === 'redis' ) {
            try {
                // setex refuses a TTL of 0; a zero expiry means "no expiry",
                // which is plain set.
                return $expire > 0
                    ? (bool) $this->client->setex( $key, $expire, $data )
                    : (bool) $this->client->set( $key, $data );
            } catch ( Exception $e ) {
                return false;
            }
        }

        return $this->client->set( $key, $data, $expire );
    }

    private function backend_add( $key, $data, $expire ) {
        if ( ! $this->connected ) {
            return true;
        }

        $expire = max( 0, (int) $expire );

        if ( $this->engine === 'redis' ) {
            try {
                // NX makes this atomic; a get-then-set would race.
                $options = array( 'nx' );
                if ( $expire > 0 ) {
                    $options['ex'] = $expire;
                }
                return (bool) $this->client->set( $key, $data, $options );
            } catch ( Exception $e ) {
                return false;
            }
        }

        return $this->client->add( $key, $data, $expire );
    }

    private function backend_replace( $key, $data, $expire ) {
        if ( ! $this->connected ) {
            return false;
        }

        $expire = max( 0, (int) $expire );

        if ( $this->engine === 'redis' ) {
            try {
                $options = array( 'xx' );
                if ( $expire > 0 ) {
                    $options['ex'] = $expire;
                }
                return (bool) $this->client->set( $key, $data, $options );
            } catch ( Exception $e ) {
                return false;
            }
        }

        return $this->client->replace( $key, $data, $expire );
    }

    private function backend_delete( $key ) {
        if ( ! $this->connected ) {
            return true;
        }

        if ( $this->engine === 'redis' ) {
            try {
                // A key that was already gone is a successful delete as far as
                // the caller is concerned.
                $this->client->del( $key );
                return true;
            } catch ( Exception $e ) {
                return false;
            }
        }

        $result = $this->client->delete( $key );
        return $result || $this->client->getResultCode() === Memcached::RES_NOTFOUND;
    }

    /* ── WordPress object cache API ──────────────────────────────────── */

    private function build_key( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $prefix = $this->key_prefix;

        if ( ! isset( $this->global_groups[ $group ] ) ) {
            $prefix .= $this->blog_prefix;
        }

        return $prefix . $group . ':' . $key;
    }

    private function is_non_persistent( $group ) {
        return isset( $this->non_persistent_groups[ $group ] );
    }

    public function get( $key, $group = 'default', $force = false, &$found = null ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $cache_key = $this->build_key( $key, $group );

        if ( ! $force && array_key_exists( $cache_key, $this->cache ) ) {
            $found = true;
            $this->cache_hits++;
            return is_object( $this->cache[ $cache_key ] ) ? clone $this->cache[ $cache_key ] : $this->cache[ $cache_key ];
        }

        if ( $this->is_non_persistent( $group ) ) {
            $found = array_key_exists( $cache_key, $this->cache );
            if ( $found ) {
                $this->cache_hits++;
                return is_object( $this->cache[ $cache_key ] ) ? clone $this->cache[ $cache_key ] : $this->cache[ $cache_key ];
            }
            $this->cache_misses++;
            return false;
        }

        $backend_found = false;
        $value         = $this->backend_get( $cache_key, $backend_found );

        if ( $backend_found ) {
            $found = true;
            $this->cache_hits++;
            $this->cache[ $cache_key ] = $value;
            return is_object( $value ) ? clone $value : $value;
        }

        $found = false;
        $this->cache_misses++;
        return false;
    }

    public function get_multiple( $keys, $group = 'default', $force = false ) {
        $results = array();
        foreach ( $keys as $key ) {
            $results[ $key ] = $this->get( $key, $group, $force );
        }
        return $results;
    }

    public function set( $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $cache_key = $this->build_key( $key, $group );

        if ( is_object( $data ) ) {
            $data = clone $data;
        }

        $this->cache[ $cache_key ] = $data;

        if ( $this->is_non_persistent( $group ) ) {
            return true;
        }

        return $this->backend_set( $cache_key, $data, $expire );
    }

    public function add( $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $cache_key = $this->build_key( $key, $group );

        if ( array_key_exists( $cache_key, $this->cache ) ) {
            return false;
        }

        if ( $this->is_non_persistent( $group ) || ! $this->connected ) {
            $this->cache[ $cache_key ] = is_object( $data ) ? clone $data : $data;
            return true;
        }

        $result = $this->backend_add( $cache_key, $data, $expire );
        if ( $result ) {
            $this->cache[ $cache_key ] = is_object( $data ) ? clone $data : $data;
        }

        return $result;
    }

    public function replace( $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $cache_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) || ! $this->connected ) {
            if ( ! array_key_exists( $cache_key, $this->cache ) ) {
                return false;
            }
            $this->cache[ $cache_key ] = is_object( $data ) ? clone $data : $data;
            return true;
        }

        $result = $this->backend_replace( $cache_key, $data, $expire );
        if ( $result ) {
            $this->cache[ $cache_key ] = is_object( $data ) ? clone $data : $data;
        }

        return $result;
    }

    public function delete( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $cache_key = $this->build_key( $key, $group );

        unset( $this->cache[ $cache_key ] );

        if ( $this->is_non_persistent( $group ) ) {
            return true;
        }

        return $this->backend_delete( $cache_key );
    }

    public function delete_multiple( $keys, $group = 'default' ) {
        $results = array();
        foreach ( $keys as $key ) {
            $results[ $key ] = $this->delete( $key, $group );
        }
        return $results;
    }

    public function incr( $key, $offset = 1, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $cache_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) || ! $this->connected ) {
            if ( ! isset( $this->cache[ $cache_key ] ) || ! is_numeric( $this->cache[ $cache_key ] ) ) {
                return false;
            }
            $this->cache[ $cache_key ] = max( 0, (int) $this->cache[ $cache_key ] + (int) $offset );
            return $this->cache[ $cache_key ];
        }

        if ( $this->engine === 'redis' ) {
            try {
                // ⚠ INCRBY NEEDS A RAW INTEGER, and OPT_SERIALIZER wrapped
                // every value in a PHP-serialized string. Without switching the
                // serializer off for this call, incr on a value we set() would
                // fail with a type error. Restored immediately afterwards.
                $this->client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE );
                if ( ! $this->client->exists( $cache_key ) ) {
                    $this->client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
                    return false;
                }
                $result = $this->client->incrBy( $cache_key, (int) $offset );
                if ( $result < 0 ) {
                    // WordPress clamps counters at zero.
                    $this->client->set( $cache_key, 0 );
                    $result = 0;
                }
                $this->client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
            } catch ( Exception $e ) {
                $this->client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
                return false;
            }
        } else {
            $result = $this->client->increment( $cache_key, $offset );
        }

        if ( $result !== false ) {
            $this->cache[ $cache_key ] = $result;
        }

        return $result;
    }

    public function decr( $key, $offset = 1, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $cache_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) || ! $this->connected ) {
            if ( ! isset( $this->cache[ $cache_key ] ) || ! is_numeric( $this->cache[ $cache_key ] ) ) {
                return false;
            }
            $this->cache[ $cache_key ] = max( 0, (int) $this->cache[ $cache_key ] - (int) $offset );
            return $this->cache[ $cache_key ];
        }

        if ( $this->engine === 'redis' ) {
            try {
                $this->client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE );
                if ( ! $this->client->exists( $cache_key ) ) {
                    $this->client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
                    return false;
                }
                $result = $this->client->decrBy( $cache_key, (int) $offset );
                if ( $result < 0 ) {
                    $this->client->set( $cache_key, 0 );
                    $result = 0;
                }
                $this->client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
            } catch ( Exception $e ) {
                $this->client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
                return false;
            }
        } else {
            $result = $this->client->decrement( $cache_key, $offset );
        }

        if ( $result !== false ) {
            $this->cache[ $cache_key ] = $result;
        }

        return $result;
    }

    public function flush() {
        $this->cache = array();

        if ( ! $this->connected ) {
            return true;
        }

        if ( $this->engine === 'redis' ) {
            try {
                // flushDB, not flushAll: the instance is configured with a
                // single database, so they are equivalent today and flushDB
                // stays correct if that ever changes.
                return (bool) $this->client->flushDB();
            } catch ( Exception $e ) {
                return false;
            }
        }

        return $this->client->flush();
    }

    /**
     * Flush one cache group.
     *
     * ⚠ THIS ONLY CLEARS THE IN-PROCESS ARRAY. Neither engine can delete by
     * prefix without walking the keyspace, which is not something to do on a
     * cache serving a live site.
     *
     * supports('flush_group') therefore returns FALSE — see the note there.
     * This method stays because WordPress may still call it, and clearing the
     * local copy is strictly better than doing nothing.
     */
    public function flush_group( $group ) {
        if ( empty( $group ) ) {
            return false;
        }

        $prefix = $this->build_key( '', $group );
        foreach ( array_keys( $this->cache ) as $cached_key ) {
            if ( strpos( $cached_key, $prefix ) === 0 ) {
                unset( $this->cache[ $cached_key ] );
            }
        }

        return false;
    }

    public function add_global_groups( $groups ) {
        foreach ( (array) $groups as $group ) {
            $this->global_groups[ $group ] = true;
        }
    }

    public function add_non_persistent_groups( $groups ) {
        foreach ( (array) $groups as $group ) {
            $this->non_persistent_groups[ $group ] = true;
        }
    }

    public function switch_to_blog( $blog_id ) {
        $this->blog_prefix = (int) $blog_id . ':';
    }

    /**
     * ⚠ flush_group NOW REPORTS FALSE. It reported true through 1.1.0 while
     * only clearing the in-process array, which is worse than not supporting it:
     * WordPress takes a true here as a promise that a group flush really
     * invalidates persistent entries, and skips its own fallback. Anything
     * relying on that promise was reading stale data from the cache for the rest
     * of the entry's TTL.
     *
     * Doing it properly needs a per-group version counter folded into build_key.
     * That is a change to the hottest path in WordPress and belongs in its own
     * release, not bundled with the engine switch.
     */
    public function supports( $feature ) {
        switch ( $feature ) {
            case 'get_multiple':
            case 'delete_multiple':
                return true;
            case 'flush_group':
                return false;
            default:
                return false;
        }
    }

    /** Which engine is serving, for the plugin's admin page and for debugging. */
    public function hostney_engine() {
        return $this->connected ? $this->engine : '';
    }

    /**
     * Debug output. WordPress core calls this from wp_cache_stats().
     */
    public function stats() {
        echo '<p>';
        echo '<strong>Cache hits:</strong> ' . esc_html( $this->cache_hits ) . '<br />';
        echo '<strong>Cache misses:</strong> ' . esc_html( $this->cache_misses ) . '<br />';
        echo '<strong>Backend:</strong> ' . ( $this->connected ? esc_html( $this->engine ) : 'none (in-memory only)' ) . '<br />';
        echo '</p>';
    }
}
