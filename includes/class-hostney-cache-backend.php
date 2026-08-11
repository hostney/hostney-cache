<?php
/**
 * Hostney Cache - Object cache backend contract
 *
 * Hostney accounts run ONE object cache: Redis or Memcached, never both. That
 * is not an arbitrary rule - WordPress has exactly one wp-content/object-cache.php
 * slot, so two caches cannot both be the cache, and a second one would only
 * consume memory nothing reads from.
 *
 * This class is the shape both engines present to the admin UI, so the plugin
 * asks "what is running" once and never branches on the engine again.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Hostney_Cache_Backend {

    /** @var int Connect timeout in milliseconds. Local socket: if it does not answer at once it is not going to. */
    const CONNECT_TIMEOUT_MS = 500;

    /** @var int Availability-check cache TTL in seconds */
    const AVAILABILITY_TTL = 300;

    /** Machine name: 'redis' or 'memcached'. */
    abstract public function get_engine();

    /** Customer-facing name. 'Redis', not 'Valkey' - see the note in get_label() on the Redis subclass. */
    abstract public function get_label();

    /** Absolute path to this engine's unix socket for the current account. */
    abstract public function get_socket_path();

    /** Whether the PHP extension this engine needs is loaded. */
    abstract public function is_extension_loaded();

    /** A connected client, or null. Callers must treat null as "not available". */
    abstract public function get_connection();

    /**
     * Normalised stats, or null when not connected.
     *
     * ⚠ THE KEYS ARE THE SAME FOR BOTH ENGINES. The admin page renders one
     * table and must not know which engine produced the numbers.
     *
     * @return array{hits:int,misses:int,hit_ratio:float,memory_used:int,memory_limit:int,items:int,uptime:int}|null
     */
    abstract public function get_stats();

    /** @return array{success:bool,message:string} */
    abstract public function flush();

    /**
     * The Linux account PHP is running as. Both engines' socket paths are
     * derived from it, and it is the only thing that varies between accounts.
     */
    public function get_system_username() {
        if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
            $info = posix_getpwuid( posix_geteuid() );
            if ( $info && ! empty( $info['name'] ) ) {
                return $info['name'];
            }
        }
        return get_current_user();
    }

    /**
     * Whether this engine is loaded, running and answering.
     *
     * Cached in a transient for five minutes. The check opens a socket and
     * issues a command, which is far too much to repeat on every admin page
     * load, and the answer only changes when somebody flips a switch in the
     * control panel.
     *
     * ⚠ THE TRANSIENT KEY IS PER-ENGINE. One shared key would have Redis
     * answering "yes" from a cached Memcached probe the moment an account
     * switched, which is exactly the confusion this plugin exists to avoid.
     */
    public function is_available() {
        if ( ! $this->is_extension_loaded() ) {
            return false;
        }

        $transient_key = 'hostney_' . $this->get_engine() . '_available';

        $cached = get_transient( $transient_key );
        if ( $cached !== false ) {
            return $cached === 'yes';
        }

        $available = ( $this->get_connection() !== null );
        set_transient( $transient_key, $available ? 'yes' : 'no', self::AVAILABILITY_TTL );

        return $available;
    }

    /** Forget the cached availability answer. Called after a drop-in change. */
    public function clear_availability_cache() {
        delete_transient( 'hostney_' . $this->get_engine() . '_available' );
    }

    /**
     * Ratio helper, so both engines round the same way.
     *
     * Returns 0 rather than dividing by zero on a cache nothing has read yet.
     */
    protected function hit_ratio( $hits, $misses ) {
        $total = $hits + $misses;
        return $total > 0 ? round( ( $hits / $total ) * 100, 1 ) : 0;
    }

    public function format_bytes( $bytes ) {
        if ( $bytes >= 1073741824 ) {
            return round( $bytes / 1073741824, 1 ) . ' GB';
        }
        if ( $bytes >= 1048576 ) {
            return round( $bytes / 1048576, 1 ) . ' MB';
        }
        if ( $bytes >= 1024 ) {
            return round( $bytes / 1024, 1 ) . ' KB';
        }
        return $bytes . ' B';
    }

    public function format_uptime( $seconds ) {
        $days    = floor( $seconds / 86400 );
        $hours   = floor( ( $seconds % 86400 ) / 3600 );
        $minutes = floor( ( $seconds % 3600 ) / 60 );

        $parts = array();
        if ( $days > 0 ) {
            $parts[] = $days . 'd';
        }
        if ( $hours > 0 ) {
            $parts[] = $hours . 'h';
        }
        if ( $minutes > 0 || empty( $parts ) ) {
            $parts[] = $minutes . 'm';
        }

        return implode( ' ', $parts );
    }
}
