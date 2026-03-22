<?php
/**
 * Hostney Cache - Purger
 *
 * Collects URLs to purge during a request lifecycle, deduplicates them,
 * and executes purge calls to the Nginx endpoint at shutdown.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Purger {

    /** @var string[] Exact URLs to purge */
    private static $urls_to_purge = array();

    /** @var string[] Path prefixes to purge */
    private static $prefixes_to_purge = array();

    /** @var bool Whether to purge all cache */
    private static $purge_all = false;

    /** @var bool Whether shutdown handler is registered */
    private static $shutdown_registered = false;

    /** @var string Detected FQDN */
    private $domain;

    public function __construct() {
        $this->domain = $this->detect_domain();
    }

    /**
     * Detect the site's FQDN from WordPress home URL
     */
    private function detect_domain() {
        $home = get_option( 'home' );
        return wp_parse_url( $home, PHP_URL_HOST );
    }

    /**
     * Get the detected domain
     */
    public function get_domain() {
        return $this->domain;
    }

    /**
     * Queue a post's related URLs for purging
     */
    public function queue_post_purge( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        // Post permalink
        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            $this->add_url( $permalink );
        }

        // Homepage
        $this->add_url( home_url( '/' ) );

        // RSS feed
        $this->add_url( home_url( '/feed/' ) );

        // Sitemap
        $this->add_url( home_url( '/wp-sitemap.xml' ) );

        // Category archives (prefix purge for pagination coverage)
        $categories = get_the_terms( $post_id, 'category' );
        if ( $categories && ! is_wp_error( $categories ) ) {
            foreach ( $categories as $cat ) {
                $link = get_term_link( $cat );
                if ( ! is_wp_error( $link ) ) {
                    $path = wp_parse_url( $link, PHP_URL_PATH );
                    if ( $path ) {
                        $this->add_prefix( $path );
                    }
                }
            }
        }

        // Tag archives (prefix purge)
        $tags = get_the_terms( $post_id, 'post_tag' );
        if ( $tags && ! is_wp_error( $tags ) ) {
            foreach ( $tags as $tag ) {
                $link = get_term_link( $tag );
                if ( ! is_wp_error( $link ) ) {
                    $path = wp_parse_url( $link, PHP_URL_PATH );
                    if ( $path ) {
                        $this->add_prefix( $path );
                    }
                }
            }
        }

        // Author archive (prefix purge)
        if ( $post->post_author ) {
            $author_link = get_author_posts_url( $post->post_author );
            if ( $author_link ) {
                $path = wp_parse_url( $author_link, PHP_URL_PATH );
                if ( $path ) {
                    $this->add_prefix( $path );
                }
            }
        }

        $this->ensure_shutdown_handler();
    }

    /**
     * Queue a taxonomy term's URLs for purging
     */
    public function queue_term_purge( $term_id, $taxonomy ) {
        $link = get_term_link( (int) $term_id, $taxonomy );
        if ( ! is_wp_error( $link ) ) {
            $path = wp_parse_url( $link, PHP_URL_PATH );
            if ( $path ) {
                $this->add_prefix( $path );
            }
        }

        // Homepage
        $this->add_url( home_url( '/' ) );

        $this->ensure_shutdown_handler();
    }

    /**
     * Queue a full cache purge
     */
    public function queue_full_purge() {
        self::$purge_all = true;
        $this->ensure_shutdown_handler();
    }

    /**
     * Immediately purge all cache (for manual purge actions)
     */
    public function purge_all() {
        return $this->send_purge_request( array( 'action' => 'clear' ) );
    }

    /**
     * Immediately purge a specific post's URLs (for editor meta box)
     */
    public function purge_post( $post_id ) {
        // Temporarily collect URLs, then send immediately
        // Save and restore all static state to avoid side effects
        $saved_urls = self::$urls_to_purge;
        $saved_prefixes = self::$prefixes_to_purge;
        $saved_shutdown = self::$shutdown_registered;

        self::$urls_to_purge = array();
        self::$prefixes_to_purge = array();
        self::$shutdown_registered = true; // Prevent registering a duplicate shutdown handler

        $this->queue_post_purge( $post_id );

        $urls = self::$urls_to_purge;
        $prefixes = self::$prefixes_to_purge;

        // Restore saved state
        self::$urls_to_purge = $saved_urls;
        self::$prefixes_to_purge = $saved_prefixes;
        self::$shutdown_registered = $saved_shutdown;

        $results = array();

        // Purge exact URLs
        if ( ! empty( $urls ) ) {
            $result = $this->send_purge_request( array(
                'action' => 'purge_urls',
                'urls'   => array_values( $urls ),
            ) );
            $results[] = $result;
        }

        // Purge prefixes
        foreach ( $prefixes as $prefix ) {
            $result = $this->send_purge_request( array(
                'action' => 'purge_prefix',
                'prefix' => $prefix,
            ) );
            $results[] = $result;
        }

        // Return combined result
        $all_success = true;
        foreach ( $results as $r ) {
            if ( ! $r['success'] ) {
                $all_success = false;
                break;
            }
        }

        return array(
            'success' => $all_success,
            'message' => $all_success ? 'Cache purged for this page.' : 'Some purge operations failed.',
        );
    }

    /**
     * Execute pending purges (called at shutdown)
     */
    public function execute_purge() {
        // Full cache clear
        if ( self::$purge_all ) {
            $result = $this->send_purge_request( array( 'action' => 'clear' ) );
            $this->log_purge( 'clear', array( '*' ), $result );
            $this->reset();
            return;
        }

        $total_items = count( self::$urls_to_purge ) + count( self::$prefixes_to_purge );

        // If too many items, fall back to full clear
        if ( $total_items > 15 ) {
            $result = $this->send_purge_request( array( 'action' => 'clear' ) );
            $this->log_purge( 'clear', array( "Batch ({$total_items} items)" ), $result );
            $this->reset();
            return;
        }

        // Purge exact URLs
        if ( ! empty( self::$urls_to_purge ) ) {
            $urls = array_values( self::$urls_to_purge );
            $result = $this->send_purge_request( array(
                'action' => 'purge_urls',
                'urls'   => $urls,
            ) );
            $this->log_purge( 'purge_urls', $urls, $result );
        }

        // Purge prefixes
        foreach ( self::$prefixes_to_purge as $prefix ) {
            $result = $this->send_purge_request( array(
                'action' => 'purge_prefix',
                'prefix' => $prefix,
            ) );
            $this->log_purge( 'purge_prefix', array( $prefix ), $result );
        }

        $this->reset();
    }

    /**
     * Add an exact URL to the purge queue (deduplicated)
     */
    private function add_url( $url ) {
        self::$urls_to_purge[ $url ] = $url;
    }

    /**
     * Add a path prefix to the purge queue (deduplicated)
     */
    private function add_prefix( $path ) {
        // Reject root path — would wipe the entire cache
        if ( $path === '/' ) {
            return;
        }
        self::$prefixes_to_purge[ $path ] = $path;
    }

    /**
     * Register shutdown handler if not already registered
     */
    private function ensure_shutdown_handler() {
        if ( ! self::$shutdown_registered ) {
            self::$shutdown_registered = true;
            $purger = $this;
            add_action( 'shutdown', function () use ( $purger ) {
                $purger->execute_purge();
            } );
        }
    }

    /**
     * Reset static state after purge execution
     */
    private function reset() {
        self::$urls_to_purge = array();
        self::$prefixes_to_purge = array();
        self::$purge_all = false;
    }

    /**
     * Send a purge request to the Nginx endpoint
     */
    private function send_purge_request( $body ) {
        // Call localhost directly — DNS resolves the domain to the public IP,
        // which would arrive from an external address and get blocked by allow/deny.
        // The Host header lets nginx match the correct server block.
        $scheme = is_ssl() ? 'https' : 'http';
        // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- Intentional: must call localhost to stay within nginx allow/deny rules
        $url = $scheme . '://127.0.0.1/.well-known/hostney-cache-purge';

        $response = wp_remote_post( $url, array(
            'timeout'   => 30,
            'sslverify' => false,
            'body'      => wp_json_encode( $body ),
            'headers'   => array(
                'Content-Type' => 'application/json',
                'Host'         => $this->domain,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logs purge failures for server-side debugging
            error_log( '[Hostney Cache] Purge request failed: ' . $response->get_error_message() );
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code === 200 && ! empty( $response_body['success'] ) ) {
            return array(
                'success' => true,
                'message' => $response_body['message'] ?? 'Purge successful.',
                'data'    => $response_body,
            );
        }

        $error_msg = $response_body['message'] ?? "HTTP {$status_code}";
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logs purge failures for server-side debugging
        error_log( '[Hostney Cache] Purge failed: ' . $error_msg );
        return array(
            'success' => false,
            'message' => $error_msg,
        );
    }

    /**
     * Log a purge action to wp_options
     */
    private function log_purge( $action, $urls, $result ) {
        $log = get_option( 'hostney_cache_log', array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        array_unshift( $log, array(
            'time'    => current_time( 'mysql' ),
            'action'  => $action,
            'urls'    => array_slice( $urls, 0, 5 ), // Keep first 5 for display
            'count'   => count( $urls ),
            'success' => $result['success'],
            'message' => substr( sanitize_text_field( $result['message'] ?? '' ), 0, 200 ),
        ) );

        // Cap at 50 entries
        $log = array_slice( $log, 0, 50 );

        update_option( 'hostney_cache_log', $log, false );
    }

    /**
     * Check if the purge endpoint is reachable
     */
    public function check_endpoint() {
        // Call localhost directly with Host header (same reason as send_purge_request)
        $scheme = is_ssl() ? 'https' : 'http';
        // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- Intentional: must call localhost to stay within nginx allow/deny rules
        $url = $scheme . '://127.0.0.1/.well-known/hostney-cache-purge';

        $response = wp_remote_post( $url, array(
            'timeout'   => 5,
            'sslverify' => false,
            'body'      => wp_json_encode( array( 'action' => 'status' ) ),
            'headers'   => array(
                'Content-Type' => 'application/json',
                'Host'         => $this->domain,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        // 400 means the endpoint exists but rejected the invalid action — that's fine
        return $status_code === 200 || $status_code === 400;
    }
}
