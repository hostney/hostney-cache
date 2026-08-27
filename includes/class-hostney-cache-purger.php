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
     * The hostname every purge is addressed to.
     *
     * ⚠⚠ THE PLATFORM CONSTANT FIRST, AND home_url() ONLY AS A FALLBACK.
     * Hostney defines WP_HOME/WP_SITEURL per request from the incoming Host
     * header, and WordPress filters get_option('home') through
     * _config_wp_home() - so home_url() reports WHICHEVER ADDRESS THE CURRENT
     * ADMIN IS BROWSING ON, not the site's own domain.
     *
     * That silently broke purging. Administering a LIVE site over its
     * .hostney.app preview address made this the preview hostname, so the purge
     * POST selected the preview vhost - which deliberately does not cache and
     * therefore has no purge location. The request 404s and THE LIVE DOMAIN'S
     * CACHE IS NEVER CLEARED. Nothing surfaces it except the purge log, so the
     * symptom is "my edits do not show up" with no error anywhere.
     *
     * HOSTNEY_SITE_FQDN is written by the hostney-platform mu-plugin, which the
     * discovery job regenerates on every run. A site that has not been reached
     * yet keeps the old behaviour, which is correct on its own domain and no
     * worse than before anywhere else.
     */
    private function detect_domain() {
        if ( defined( 'HOSTNEY_SITE_FQDN' ) && HOSTNEY_SITE_FQDN !== '' ) {
            return HOSTNEY_SITE_FQDN;
        }

        $home = get_option( 'home' );
        return wp_parse_url( $home, PHP_URL_HOST );
    }

    /**
     * Move a URL onto the canonical hostname.
     *
     * ⚠⚠ THE HOST HEADER IS NOT ENOUGH ON ITS OWN. cache_purge.lua keys on
     * ngx.var.host AND rejects any submitted URL that does not belong to it, so
     * sending canonical Host with preview URLs would be refused rather than
     * silently mis-targeted. Both halves have to move together, which is why
     * this sits in add_url() - the one funnel every purge URL passes through.
     *
     * Port, fragment and userinfo are dropped rather than carried: a purge URL
     * has never had any of them, and a port surviving here would not match the
     * cache key anyway.
     */
    private function canonical_url( $url ) {
        if ( ! is_string( $url ) || $url === '' || empty( $this->domain ) ) {
            return (string) $url;
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) || $parts['host'] === $this->domain ) {
            return $url;
        }

        $scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
        $path   = ! empty( $parts['path'] ) ? $parts['path'] : '/';
        $query  = ! empty( $parts['query'] ) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $this->domain . $path . $query;
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
        // Canonicalised HERE rather than at each call site: every purge URL in
        // this class comes through add_url(), and a single one that skipped the
        // rewrite would be rejected by the endpoint's own host check while the
        // rest of the batch succeeded - a partial purge that reports success.
        $url = $this->canonical_url( $url );
        if ( $url === '' ) {
            return;
        }
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

    /**
     * Headers any of our layers may use to report page-cache state.
     *
     * Presence is what matters, not the value: a MISS proves the cache is there
     * and that this request is what populated it.
     *
     * ⚠ ONE LIST, USED BY BOTH READERS. Hostney_Cache_Warmer::fetch() reads it
     * too. It had its own copy, and a header added to one and not the other
     * means the warmer and the admin page disagree about whether the same site
     * is cached — which is exactly the class of bug this plugin keeps finding
     * in its own formatters.
     */
    const CACHE_HEADERS = array( 'x-cache-status', 'x-fastcgi-cache', 'x-proxy-cache', 'cf-cache-status' );

    /**
     * Whether nginx is actually caching this site's pages.
     *
     * ⚠⚠ THIS IS NOT check_endpoint(), AND CONFLATING THEM WAS A REAL BUG. The
     * admin page rendered BOTH the "Page caching" and "Purge endpoint" rows from
     * the purge check alone, so any vhost that caches but has no purge location
     * reported "Page caching: not detected".
     *
     * That combination is not hypothetical - it is what a HUC (.hostney.app) or
     * staging address was. Those vhosts include fastcgi_caching and did not
     * include the purge partial, so the panel told the truth about the purge
     * endpoint and a falsehood about caching, and the falsehood is the row people
     * act on. Somebody goes looking for a caching problem that does not exist,
     * while the real one - a cache that can never be purged - is described as a
     * missing endpoint.
     *
     * Asks the origin directly, for the same reason the purge does: the public
     * hostname may resolve to an edge PoP, which would answer with ITS cache
     * state rather than this server's.
     */
    public function detect_page_cache() {
        // Scheme from the SITE, not is_ssl(). nginx puts $scheme in the cache
        // key, and an admin request's own scheme is incidental.
        $scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
        if ( $scheme !== 'http' && $scheme !== 'https' ) {
            $scheme = is_ssl() ? 'https' : 'http';
        }

        // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- Intentional: the public hostname may answer from an edge PoP, not this origin
        $response = wp_remote_get( $scheme . '://127.0.0.1/', array(
            'timeout'     => 5,
            'sslverify'   => false,
            'redirection' => 0,
            'headers'     => array(
                'Host'       => $this->domain,
                'User-Agent' => 'Hostney-Cache/' . HOSTNEY_CACHE_VERSION . ' (+https://www.hostney.com)',
                // No cookies. A logged-in cookie makes nginx skip the cache, so
                // the probe would report "no caching" on every site an admin
                // ever looked at - which is every site this row is shown on.
                'Cache-Control' => 'no-cache',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        foreach ( self::CACHE_HEADERS as $header ) {
            if ( wp_remote_retrieve_header( $response, $header ) !== '' ) {
                return true;
            }
        }

        return false;
    }
}
