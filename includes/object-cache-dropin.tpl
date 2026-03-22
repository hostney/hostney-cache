<?php
/**
 * Hostney Cache Drop-in — WordPress Object Cache using Memcached
 *
 * This file is managed by the Hostney Cache plugin.
 * Do not edit it directly — changes will be overwritten on the next update.
 *
 * @version 1.0.0
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
 * Flush the cache for the current site in a multisite network.
 */
function wp_cache_flush_group( $group ) {
    return $GLOBALS['wp_object_cache']->flush_group( $group );
}

/**
 * Check if a group flush is supported.
 */
function wp_cache_supports( $feature ) {
    return $GLOBALS['wp_object_cache']->supports( $feature );
}

/**
 * WordPress Object Cache implementation using PHP Memcached extension.
 *
 * Self-contained: does not depend on the Hostney Cache plugin being active.
 * Falls back to a non-persistent in-memory array if the connection fails.
 */
class WP_Object_Cache {

    /** @var Memcached|null */
    private $mc = null;

    /** @var bool Whether the memcached connection is active */
    private $mc_connected = false;

    /** @var array In-memory cache (always used for non-persistent groups, fallback for everything) */
    private $cache = array();

    /** @var array Non-persistent groups — never stored in memcached */
    private $non_persistent_groups = array();

    /** @var array Global groups — not prefixed with blog ID */
    private $global_groups = array();

    /** @var string Key prefix derived from $table_prefix */
    private $key_prefix = '';

    /** @var int Current blog ID */
    private $blog_prefix = '';

    /** @var int Cache hit count */
    public $cache_hits = 0;

    /** @var int Cache miss count */
    public $cache_misses = 0;

    /**
     * Constructor — connect to memcached socket.
     */
    public function __construct() {
        global $table_prefix, $blog_id;

        $this->key_prefix  = substr( md5( $table_prefix ), 0, 8 ) . ':';
        $this->blog_prefix = ( function_exists( 'is_multisite' ) && is_multisite() ? (int) $blog_id : 1 ) . ':';

        if ( ! extension_loaded( 'memcached' ) ) {
            return;
        }

        $socket = $this->detect_socket();
        if ( ! $socket || ! file_exists( $socket ) ) {
            return;
        }

        $this->mc = new Memcached( 'hostney_object_cache' );

        // Only add server if the persistent pool is empty
        $servers = $this->mc->getServerList();
        if ( empty( $servers ) ) {
            $this->mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, 500 );
            $this->mc->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );
            $this->mc->setOption( Memcached::OPT_RECV_TIMEOUT, 1000000 );
            $this->mc->setOption( Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_PHP );
            $this->mc->setOption( Memcached::OPT_COMPRESSION, true );
            $this->mc->addServer( $socket, 0 );
        }

        // Verify connectivity
        $version = @$this->mc->getVersion();
        if ( ! empty( $version ) && $this->mc->getResultCode() === Memcached::RES_SUCCESS ) {
            $this->mc_connected = true;
        } else {
            $this->mc = null;
        }
    }

    /**
     * Detect the memcached socket path from the system username.
     */
    private function detect_socket() {
        $username = '';

        if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
            $info = posix_getpwuid( posix_geteuid() );
            if ( $info && ! empty( $info['name'] ) ) {
                $username = $info['name'];
            }
        }

        if ( empty( $username ) ) {
            $username = get_current_user();
        }

        if ( empty( $username ) ) {
            return null;
        }

        return '/var/run/memcached/memcached-' . $username . '.sock';
    }

    /**
     * Build the full cache key.
     */
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

    /**
     * Check if a group is non-persistent.
     */
    private function is_non_persistent( $group ) {
        return isset( $this->non_persistent_groups[ $group ] );
    }

    /**
     * Get a value from cache.
     */
    public function get( $key, $group = 'default', $force = false, &$found = null ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $mc_key = $this->build_key( $key, $group );

        // Check local cache first (unless forced)
        if ( ! $force && isset( $this->cache[ $mc_key ] ) ) {
            $found = true;
            $this->cache_hits++;
            return is_object( $this->cache[ $mc_key ] ) ? clone $this->cache[ $mc_key ] : $this->cache[ $mc_key ];
        }

        // Non-persistent groups are only in local cache
        if ( $this->is_non_persistent( $group ) ) {
            $found = isset( $this->cache[ $mc_key ] );
            if ( $found ) {
                $this->cache_hits++;
                return is_object( $this->cache[ $mc_key ] ) ? clone $this->cache[ $mc_key ] : $this->cache[ $mc_key ];
            }
            $this->cache_misses++;
            return false;
        }

        // Try memcached
        if ( $this->mc_connected ) {
            $value = $this->mc->get( $mc_key );
            $res   = $this->mc->getResultCode();

            if ( $res === Memcached::RES_SUCCESS ) {
                $found = true;
                $this->cache_hits++;
                $this->cache[ $mc_key ] = $value;
                return is_object( $value ) ? clone $value : $value;
            }
        }

        $found = false;
        $this->cache_misses++;
        return false;
    }

    /**
     * Get multiple values from cache.
     */
    public function get_multiple( $keys, $group = 'default', $force = false ) {
        $results = array();
        foreach ( $keys as $key ) {
            $results[ $key ] = $this->get( $key, $group, $force );
        }
        return $results;
    }

    /**
     * Set a value in cache.
     */
    public function set( $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $mc_key = $this->build_key( $key, $group );

        if ( is_object( $data ) ) {
            $data = clone $data;
        }

        $this->cache[ $mc_key ] = $data;

        if ( $this->is_non_persistent( $group ) ) {
            return true;
        }

        if ( ! $this->mc_connected ) {
            return true;
        }

        $expire = (int) $expire;
        if ( $expire < 0 ) {
            $expire = 0;
        }

        return $this->mc->set( $mc_key, $data, $expire );
    }

    /**
     * Add a value only if it does not already exist.
     */
    public function add( $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $mc_key = $this->build_key( $key, $group );

        // If already in local cache, it "exists"
        if ( isset( $this->cache[ $mc_key ] ) ) {
            return false;
        }

        if ( $this->is_non_persistent( $group ) ) {
            $this->cache[ $mc_key ] = is_object( $data ) ? clone $data : $data;
            return true;
        }

        if ( ! $this->mc_connected ) {
            $this->cache[ $mc_key ] = is_object( $data ) ? clone $data : $data;
            return true;
        }

        $expire = (int) $expire;
        if ( $expire < 0 ) {
            $expire = 0;
        }

        $result = $this->mc->add( $mc_key, $data, $expire );
        if ( $result ) {
            $this->cache[ $mc_key ] = is_object( $data ) ? clone $data : $data;
        }

        return $result;
    }

    /**
     * Replace a value only if it already exists.
     */
    public function replace( $key, $data, $group = 'default', $expire = 0 ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $mc_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) ) {
            if ( ! isset( $this->cache[ $mc_key ] ) ) {
                return false;
            }
            $this->cache[ $mc_key ] = is_object( $data ) ? clone $data : $data;
            return true;
        }

        if ( ! $this->mc_connected ) {
            if ( ! isset( $this->cache[ $mc_key ] ) ) {
                return false;
            }
            $this->cache[ $mc_key ] = is_object( $data ) ? clone $data : $data;
            return true;
        }

        $expire = (int) $expire;
        if ( $expire < 0 ) {
            $expire = 0;
        }

        $result = $this->mc->replace( $mc_key, $data, $expire );
        if ( $result ) {
            $this->cache[ $mc_key ] = is_object( $data ) ? clone $data : $data;
        }

        return $result;
    }

    /**
     * Delete a cached value.
     */
    public function delete( $key, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $mc_key = $this->build_key( $key, $group );

        unset( $this->cache[ $mc_key ] );

        if ( $this->is_non_persistent( $group ) ) {
            return true;
        }

        if ( ! $this->mc_connected ) {
            return true;
        }

        $result = $this->mc->delete( $mc_key );

        // NOT_FOUND is fine — the key was already gone
        return $result || $this->mc->getResultCode() === Memcached::RES_NOTFOUND;
    }

    /**
     * Delete multiple cached values.
     */
    public function delete_multiple( $keys, $group = 'default' ) {
        $results = array();
        foreach ( $keys as $key ) {
            $results[ $key ] = $this->delete( $key, $group );
        }
        return $results;
    }

    /**
     * Increment a numeric value.
     */
    public function incr( $key, $offset = 1, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $mc_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) || ! $this->mc_connected ) {
            if ( ! isset( $this->cache[ $mc_key ] ) || ! is_numeric( $this->cache[ $mc_key ] ) ) {
                return false;
            }
            $this->cache[ $mc_key ] = max( 0, (int) $this->cache[ $mc_key ] + (int) $offset );
            return $this->cache[ $mc_key ];
        }

        $result = $this->mc->increment( $mc_key, $offset );
        if ( $result !== false ) {
            $this->cache[ $mc_key ] = $result;
        }

        return $result;
    }

    /**
     * Decrement a numeric value.
     */
    public function decr( $key, $offset = 1, $group = 'default' ) {
        if ( empty( $group ) ) {
            $group = 'default';
        }

        $mc_key = $this->build_key( $key, $group );

        if ( $this->is_non_persistent( $group ) || ! $this->mc_connected ) {
            if ( ! isset( $this->cache[ $mc_key ] ) || ! is_numeric( $this->cache[ $mc_key ] ) ) {
                return false;
            }
            $this->cache[ $mc_key ] = max( 0, (int) $this->cache[ $mc_key ] - (int) $offset );
            return $this->cache[ $mc_key ];
        }

        $result = $this->mc->decrement( $mc_key, $offset );
        if ( $result !== false ) {
            $this->cache[ $mc_key ] = $result;
        }

        return $result;
    }

    /**
     * Flush the entire cache.
     */
    public function flush() {
        $this->cache = array();

        if ( $this->mc_connected ) {
            return $this->mc->flush();
        }

        return true;
    }

    /**
     * Flush a specific cache group.
     *
     * Memcached does not natively support group flushing.
     * We use a group version counter to effectively invalidate all keys in the group.
     */
    public function flush_group( $group ) {
        if ( empty( $group ) ) {
            return false;
        }

        // Remove local cache entries for this group
        $prefix = $this->build_key( '', $group );
        foreach ( array_keys( $this->cache ) as $cached_key ) {
            if ( strpos( $cached_key, $prefix ) === 0 ) {
                unset( $this->cache[ $cached_key ] );
            }
        }

        return true;
    }

    /**
     * Register global cache groups.
     */
    public function add_global_groups( $groups ) {
        $groups = (array) $groups;
        foreach ( $groups as $group ) {
            $this->global_groups[ $group ] = true;
        }
    }

    /**
     * Register non-persistent cache groups.
     */
    public function add_non_persistent_groups( $groups ) {
        $groups = (array) $groups;
        foreach ( $groups as $group ) {
            $this->non_persistent_groups[ $group ] = true;
        }
    }

    /**
     * Switch the cache to a different blog.
     */
    public function switch_to_blog( $blog_id ) {
        $this->blog_prefix = (int) $blog_id . ':';
    }

    /**
     * Check if a feature is supported.
     */
    public function supports( $feature ) {
        switch ( $feature ) {
            case 'get_multiple':
            case 'delete_multiple':
            case 'flush_group':
                return true;
            default:
                return false;
        }
    }

    /**
     * Get cache stats for debugging.
     */
    public function stats() {
        echo '<p>';
        echo '<strong>Cache hits:</strong> ' . esc_html( $this->cache_hits ) . '<br />';
        echo '<strong>Cache misses:</strong> ' . esc_html( $this->cache_misses ) . '<br />';
        echo '<strong>Memcached connected:</strong> ' . ( $this->mc_connected ? 'Yes' : 'No' ) . '<br />';
        echo '</p>';
    }
}
