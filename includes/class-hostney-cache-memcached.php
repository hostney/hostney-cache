<?php
/**
 * Hostney Cache - Memcached backend
 *
 * Admin-side view of the account's Memcached instance: availability, stats,
 * flush. The actual caching is done by the drop-in (object-cache-dropin.tpl),
 * which is self-contained and does not load this file.
 *
 * ⚠ REFACTORED IN 1.2.0. This class used to own the object-cache.php drop-in as
 * well. It does not any more: the drop-in is ONE file serving whichever engine
 * is running, so managing it from inside a per-engine class meant Memcached
 * owned Redis's drop-in too. That moved to Hostney_Cache_Dropin.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Memcached extends Hostney_Cache_Backend {

    public function get_engine() {
        return 'memcached';
    }

    public function get_label() {
        return 'Memcached';
    }

    /**
     * ⚠ THIS IS THE REAL PATH, AND IT IS WORTH BEING SURE ABOUT.
     *
     * The Hostney control panel spent months displaying
     * /home/<user>/.memcached/cache.sock, which is the retired CloudLinux
     * layout and has not existed since the move to Rocky. This plugin has
     * always had it right, which is why object caching kept working while the
     * panel handed customers a path that does not exist.
     */
    public function get_socket_path() {
        return '/var/run/memcached/memcached-' . $this->get_system_username() . '.sock';
    }

    public function is_extension_loaded() {
        return extension_loaded( 'memcached' );
    }

    public function get_connection() {
        if ( ! $this->is_extension_loaded() ) {
            return null;
        }

        $socket = $this->get_socket_path();
        if ( ! file_exists( $socket ) ) {
            return null;
        }

        $mc = new Memcached( 'hostney_cache_admin' );

        // The persistent-id constructor reuses a pooled server list, so adding
        // the server again on every call would grow the pool without bound.
        $servers = $mc->getServerList();
        if ( empty( $servers ) ) {
            $mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, self::CONNECT_TIMEOUT_MS );
            $mc->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );    // microseconds
            $mc->setOption( Memcached::OPT_RECV_TIMEOUT, 1000000 );    // microseconds
            $mc->addServer( $socket, 0 );
        }

        // A pooled server entry is not proof anything is listening.
        $version = $mc->getVersion();
        if ( empty( $version ) || $mc->getResultCode() !== Memcached::RES_SUCCESS ) {
            return null;
        }

        return $mc;
    }

    public function get_stats() {
        $mc = $this->get_connection();
        if ( ! $mc ) {
            return null;
        }

        $raw = $mc->getStats();
        if ( empty( $raw ) ) {
            return null;
        }

        // Stats are keyed by server identifier; there is only ever one.
        $stats = reset( $raw );
        if ( ! is_array( $stats ) ) {
            return null;
        }

        $hits   = isset( $stats['get_hits'] ) ? (int) $stats['get_hits'] : 0;
        $misses = isset( $stats['get_misses'] ) ? (int) $stats['get_misses'] : 0;

        return array(
            'hits'         => $hits,
            'misses'       => $misses,
            'hit_ratio'    => $this->hit_ratio( $hits, $misses ),
            'memory_used'  => isset( $stats['bytes'] ) ? (int) $stats['bytes'] : 0,
            'memory_limit' => isset( $stats['limit_maxbytes'] ) ? (int) $stats['limit_maxbytes'] : 0,
            'items'        => isset( $stats['curr_items'] ) ? (int) $stats['curr_items'] : 0,
            'uptime'       => isset( $stats['uptime'] ) ? (int) $stats['uptime'] : 0,
        );
    }

    public function flush() {
        $mc = $this->get_connection();
        if ( ! $mc ) {
            return array(
                'success' => false,
                'message' => 'Could not connect to Memcached.',
            );
        }

        if ( $mc->flush() ) {
            return array(
                'success' => true,
                'message' => 'Object cache flushed.',
            );
        }

        return array(
            'success' => false,
            'message' => 'Failed to flush object cache: ' . $mc->getResultMessage(),
        );
    }
}
