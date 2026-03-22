<?php
/**
 * Hostney Cache - Memcached
 *
 * Detects memcached availability, manages the object-cache.php drop-in,
 * and provides flush / stats operations via the PHP Memcached extension.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Memcached {

    /** @var string Transient key for caching availability checks */
    private const AVAILABILITY_TRANSIENT = 'hostney_memcached_available';

    /** @var string Marker comment placed in the drop-in header */
    private const DROPIN_MARKER = 'Hostney Cache Drop-in';

    /** @var int Connect timeout in milliseconds */
    private const CONNECT_TIMEOUT_MS = 500;

    /** @var int Availability check cache TTL in seconds */
    private const AVAILABILITY_TTL = 300;

    /**
     * Get the Linux system username running PHP
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
     * Get the memcached unix socket path for this account
     */
    public function get_socket_path() {
        $username = $this->get_system_username();
        return '/var/run/memcached/memcached-' . $username . '.sock';
    }

    /**
     * Check if the PHP Memcached extension is loaded
     */
    public function is_extension_loaded() {
        return extension_loaded( 'memcached' );
    }

    /**
     * Check if memcached is available and connectable
     *
     * Result is cached in a transient for 5 minutes.
     */
    public function is_available() {
        if ( ! $this->is_extension_loaded() ) {
            return false;
        }

        $cached = get_transient( self::AVAILABILITY_TRANSIENT );
        if ( $cached !== false ) {
            return $cached === 'yes';
        }

        $mc = $this->get_connection();
        $available = ( $mc !== null );

        set_transient( self::AVAILABILITY_TRANSIENT, $available ? 'yes' : 'no', self::AVAILABILITY_TTL );

        return $available;
    }

    /**
     * Get a connected Memcached instance or null on failure
     */
    public function get_connection() {
        if ( ! $this->is_extension_loaded() ) {
            return null;
        }

        $socket = $this->get_socket_path();
        if ( ! file_exists( $socket ) ) {
            return null;
        }

        $mc = new Memcached( 'hostney_cache_admin' );

        // Only add server if not already in the pool (persistent connection ID reuses pool)
        $servers = $mc->getServerList();
        if ( empty( $servers ) ) {
            $mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, self::CONNECT_TIMEOUT_MS );
            $mc->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );    // 1 second in microseconds
            $mc->setOption( Memcached::OPT_RECV_TIMEOUT, 1000000 );    // 1 second in microseconds
            $mc->addServer( $socket, 0 );
        }

        // Verify the connection works
        $version = $mc->getVersion();
        if ( empty( $version ) || $mc->getResultCode() !== Memcached::RES_SUCCESS ) {
            return null;
        }

        return $mc;
    }

    /**
     * Get memcached statistics
     *
     * @return array|null Stats array or null if not connected
     */
    public function get_stats() {
        $mc = $this->get_connection();
        if ( ! $mc ) {
            return null;
        }

        $raw = $mc->getStats();
        if ( empty( $raw ) ) {
            return null;
        }

        // Stats are keyed by server identifier — get the first (only) entry
        $stats = reset( $raw );
        if ( ! is_array( $stats ) ) {
            return null;
        }

        $hits   = isset( $stats['get_hits'] ) ? (int) $stats['get_hits'] : 0;
        $misses = isset( $stats['get_misses'] ) ? (int) $stats['get_misses'] : 0;
        $total  = $hits + $misses;

        return array(
            'hits'         => $hits,
            'misses'       => $misses,
            'hit_ratio'    => $total > 0 ? round( ( $hits / $total ) * 100, 1 ) : 0,
            'memory_used'  => isset( $stats['bytes'] ) ? (int) $stats['bytes'] : 0,
            'memory_limit' => isset( $stats['limit_maxbytes'] ) ? (int) $stats['limit_maxbytes'] : 0,
            'items'        => isset( $stats['curr_items'] ) ? (int) $stats['curr_items'] : 0,
            'uptime'       => isset( $stats['uptime'] ) ? (int) $stats['uptime'] : 0,
        );
    }

    /**
     * Flush all data from memcached
     *
     * @return array Result with success and message keys
     */
    public function flush() {
        $mc = $this->get_connection();
        if ( ! $mc ) {
            return array(
                'success' => false,
                'message' => 'Could not connect to memcached.',
            );
        }

        $result = $mc->flush();

        if ( $result ) {
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

    /**
     * Check if the object-cache.php drop-in is installed
     */
    public function is_dropin_installed() {
        return file_exists( WP_CONTENT_DIR . '/object-cache.php' );
    }

    /**
     * Check if the installed drop-in belongs to this plugin
     */
    public function is_dropin_ours() {
        $path = WP_CONTENT_DIR . '/object-cache.php';
        if ( ! file_exists( $path ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file
        $contents = file_get_contents( $path, false, null, 0, 512 );
        return $contents !== false && strpos( $contents, self::DROPIN_MARKER ) !== false;
    }

    /**
     * Get the drop-in status
     *
     * @return string 'installed' | 'foreign' | 'not_installed'
     */
    public function get_dropin_status() {
        if ( ! $this->is_dropin_installed() ) {
            return 'not_installed';
        }
        return $this->is_dropin_ours() ? 'installed' : 'foreign';
    }

    /**
     * Install the object-cache.php drop-in
     *
     * @param bool $force Whether to overwrite a foreign drop-in
     * @return array Result with success and message keys
     */
    public function install_dropin( $force = false ) {
        if ( ! $this->is_extension_loaded() ) {
            return array(
                'success' => false,
                'message' => 'PHP Memcached extension is not available.',
            );
        }

        $dest = WP_CONTENT_DIR . '/object-cache.php';
        $source = HOSTNEY_CACHE_PLUGIN_DIR . 'includes/object-cache-dropin.tpl';

        if ( ! file_exists( $source ) ) {
            return array(
                'success' => false,
                'message' => 'Drop-in template not found.',
            );
        }

        // Check for foreign drop-in
        if ( $this->is_dropin_installed() && ! $this->is_dropin_ours() && ! $force ) {
            return array(
                'success' => false,
                'message' => 'Another object cache drop-in is already installed. Use the replace option to overwrite it.',
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file
        $contents = file_get_contents( $source );
        if ( $contents === false ) {
            return array(
                'success' => false,
                'message' => 'Could not read drop-in template.',
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing wp-content drop-in
        $written = file_put_contents( $dest, $contents );
        if ( $written === false ) {
            return array(
                'success' => false,
                'message' => 'Could not write object-cache.php. Check file permissions on wp-content/.',
            );
        }

        // Invalidate opcache so PHP loads the new file, not a stale cached version
        if ( function_exists( 'opcache_invalidate' ) ) {
            opcache_invalidate( $dest, true );
        }

        // Clear availability transient so next check is fresh
        delete_transient( self::AVAILABILITY_TRANSIENT );

        return array(
            'success' => true,
            'message' => 'Object cache drop-in installed.',
        );
    }

    /**
     * Remove the object-cache.php drop-in (only if installed by this plugin)
     *
     * @return array Result with success and message keys
     */
    public function remove_dropin() {
        if ( ! $this->is_dropin_installed() ) {
            return array(
                'success' => false,
                'message' => 'No object cache drop-in is installed.',
            );
        }

        if ( ! $this->is_dropin_ours() ) {
            return array(
                'success' => false,
                'message' => 'The installed drop-in was not created by Hostney Cache.',
            );
        }

        $path = WP_CONTENT_DIR . '/object-cache.php';

        // Invalidate opcache before removing so PHP doesn't serve the cached version
        if ( function_exists( 'opcache_invalidate' ) ) {
            opcache_invalidate( $path, true );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing wp-content drop-in
        $removed = unlink( $path );
        if ( ! $removed ) {
            return array(
                'success' => false,
                'message' => 'Could not remove object-cache.php. Check file permissions.',
            );
        }

        delete_transient( self::AVAILABILITY_TRANSIENT );

        return array(
            'success' => true,
            'message' => 'Object cache drop-in removed.',
        );
    }

    /**
     * Format bytes into a human-readable string
     *
     * @param int $bytes Byte count
     * @return string Formatted string (e.g. "12.5 MB")
     */
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

    /**
     * Format uptime seconds into a human-readable string
     *
     * @param int $seconds Uptime in seconds
     * @return string Formatted string (e.g. "2d 5h 30m")
     */
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
