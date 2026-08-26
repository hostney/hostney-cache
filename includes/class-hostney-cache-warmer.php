<?php
/**
 * Hostney Cache - Cache warmer
 *
 * Flushes the nginx page cache and then walks the site's public URLs so the
 * first real visitor to each page gets a HIT instead of paying for the render.
 *
 * DESIGN NOTES
 *
 * Runs in the BACKGROUND, on WP-Cron, one URL at a time.
 *
 * ⚠ ONE AT A TIME IS NOT CAUTION, IT IS THE WHOLE CONSTRAINT. A Hostney site
 * gets five PHP-FPM children. Warming is by definition a stream of uncached
 * requests, so every one of them occupies a child for a full page render. Fire
 * them in parallel and the warmer takes the site down for real visitors while
 * telling the customer it is making the site faster.
 *
 * ⚠ WP-Cron rides on real traffic, and a fully cached site has very little of
 * it reaching PHP — which is exactly the site somebody just asked to warm. So
 * the admin page's progress poll also nudges cron (see Hostney_Cache_Admin).
 * While the tab is open the poll IS the engine; close it and the run continues
 * whenever the site is next hit. Neither half is load-bearing on its own.
 *
 * ⚠ Fetches go to 127.0.0.1 with a Host header, exactly like the purger, NOT
 * to the public URL. Three reasons, all of which have bitten something:
 *   - PHP-FPM runs with --network host, so 127.0.0.1 really is the nginx that
 *     holds this site's cache.
 *   - a site behind the Hostney edge would otherwise warm a POP's cache and
 *     might never reach this origin at all.
 *   - the request arrives from a private address, so ip_lists.is_server_ip()
 *     passes it at step 1 of the bot chain. It is never scored and can never
 *     be handed a challenge — and a challenge page written into the page cache
 *     would be served to every subsequent visitor.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Warmer {

    /** Cron hook processing one batch of URLs. */
    const CRON_HOOK = 'hostney_cache_warm_tick';

    /** Run state; small, read on every poll. */
    const STATE_OPTION = 'hostney_cache_warm_state';

    /** The URL list for the current run. Separate option so the polled state stays small. */
    const QUEUE_OPTION = 'hostney_cache_warm_queue';

    /**
     * How long one cron tick may spend warming, in seconds.
     *
     * ⚠ TIME-BOXED, NOT A URL COUNT, and the reason is spawn_cron(): it will
     * not spawn again within WP_CRON_LOCK_TIMEOUT (60s by default), so a tick
     * happens roughly once a minute no matter how often anything asks. A tick
     * that did a fixed five URLs would therefore warm five URLs a minute, and
     * a 500-page site would take a day and a half. A time box fills the minute
     * instead, and stays far inside any sane max_execution_time.
     */
    const BATCH_SECONDS = 20;

    /** Backstop on one tick, in case pages are being served from somewhere very fast. */
    const BATCH_MAX_URLS = 100;

    /**
     * A run whose state has not moved for this long is treated as dead, so a
     * cron that was never fired (or a PHP fatal mid-batch) cannot wedge the
     * button forever.
     */
    const STALE_AFTER = 300;

    /** @var Hostney_Cache_Purger */
    private $purger;

    public function __construct( Hostney_Cache_Purger $purger ) {
        $this->purger = $purger;
    }

    /**
     * Register the cron handler.
     *
     * ⚠ Must be called on EVERY request, not just admin. Cron runs on the
     * front end, so an is_admin() guard here would mean the tick hook exists
     * only on the one request type that never fires it.
     */
    public function register() {
        add_action( self::CRON_HOOK, array( $this, 'run_batch' ) );
    }

    // ---------------------------------------------------------------- state

    /**
     * Current run state, always a full array so callers never guard on shape.
     *
     * @return array
     */
    public function get_state() {
        $defaults = array(
            'status'      => 'idle',
            'total'       => 0,
            'done'        => 0,
            'warmed'      => 0,
            'failed'      => 0,
            'started_at'  => 0,
            'updated_at'  => 0,
            'finished_at' => 0,
            'current'     => '',
            'message'     => '',
            // Whether any response carried a page-cache header. A whole run
            // without one means page caching is off for this vhost, which is
            // worth saying plainly rather than reporting a warm cache that
            // does not exist.
            'cache_seen'  => false,
        );

        $state = get_option( self::STATE_OPTION, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }

        $state = array_merge( $defaults, $state );

        // A run nothing has advanced in STALE_AFTER seconds is over, whatever
        // it says about itself.
        if ( $state['status'] === 'running' && ( time() - (int) $state['updated_at'] ) > self::STALE_AFTER ) {
            $state['status']  = 'failed';
            $state['message'] = 'The warm-up stopped responding and was cancelled. Scheduled tasks may not be running on this site.';
        }

        return $state;
    }

    /**
     * Merge a partial update into the run state.
     *
     * @param array $changes
     * @return array the merged state
     */
    private function set_state( array $changes ) {
        $state = array_merge( $this->get_state(), $changes );
        $state['updated_at'] = time();
        update_option( self::STATE_OPTION, $state, false );
        return $state;
    }

    /**
     * Is a run currently in progress?
     *
     * @return bool
     */
    public function is_running() {
        return $this->get_state()['status'] === 'running';
    }

    // ---------------------------------------------------------------- control

    /**
     * Flush the page cache, then queue every public URL for warming.
     *
     * ⚠ FLUSH FIRST, COLLECT SECOND, WARM THIRD, and never overlap them. Warming
     * into a cache that is about to be cleared is work thrown away.
     *
     * @return array {success, message}
     */
    public function start() {
        if ( $this->is_running() ) {
            return array(
                'success' => false,
                'message' => 'A warm-up is already running.',
            );
        }

        // Reaching here with an event still queued means the previous run went
        // stale (see get_state). Clear it, or schedule_next() sees the orphan,
        // decides one is already scheduled, and never queues ours.
        wp_clear_scheduled_hook( self::CRON_HOOK );

        $purge = $this->purger->purge_all();
        if ( empty( $purge['success'] ) ) {
            return array(
                'success' => false,
                // Say which half failed. "Warm-up failed" on a purge error sends
                // the reader looking in the wrong place.
                'message' => 'Could not clear the cache, so nothing was warmed. ' . ( $purge['message'] ?? '' ),
            );
        }

        $urls = $this->collect_urls();
        if ( empty( $urls ) ) {
            return array(
                'success' => false,
                'message' => 'Cache cleared, but no public URLs were found to warm.',
            );
        }

        update_option( self::QUEUE_OPTION, $urls, false );
        update_option( self::STATE_OPTION, array(
            'status'      => 'running',
            'total'       => count( $urls ),
            'done'        => 0,
            'warmed'      => 0,
            'failed'      => 0,
            'started_at'  => time(),
            'updated_at'  => time(),
            'finished_at' => 0,
            'current'     => '',
            'message'     => '',
            'cache_seen'  => false,
        ), false );

        $this->schedule_next();

        return array(
            'success' => true,
            'message' => sprintf( 'Cache cleared. Warming %d URLs in the background.', count( $urls ) ),
            'total'   => count( $urls ),
        );
    }

    /**
     * Cancel a run. What is already warmed stays warmed.
     *
     * @return array {success, message}
     */
    public function stop() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        delete_option( self::QUEUE_OPTION );

        $state = $this->get_state();
        $this->set_state( array(
            'status'      => 'stopped',
            'finished_at' => time(),
            'current'     => '',
            'message'     => sprintf( 'Stopped after %d of %d URLs.', $state['done'], $state['total'] ),
        ) );

        return array( 'success' => true, 'message' => 'Warm-up stopped.' );
    }

    /**
     * Queue the next batch and try to make it run now.
     *
     * ⚠ spawn_cron() is a best-effort nudge, not a guarantee — it declines if
     * another spawn happened in the last 60s (WP_CRON_LOCK_TIMEOUT), and it
     * does nothing at all under DISABLE_WP_CRON. That is the case the admin
     * poll covers.
     */
    private function schedule_next() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK );
        }
        spawn_cron();
    }

    /**
     * Nudge a stalled-but-live run from the admin poll.
     *
     * The poll runs in wp-admin, where WP-Cron would otherwise only fire on
     * whatever admin request happens along.
     */
    public function nudge() {
        if ( $this->is_running() ) {
            $this->schedule_next();
        }
    }

    // ---------------------------------------------------------------- the work

    /**
     * Warm one batch of URLs, then queue the next.
     */
    public function run_batch() {
        if ( ! $this->is_running() ) {
            return;
        }

        $queue = get_option( self::QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || empty( $queue ) ) {
            $this->finish();
            return;
        }

        $state      = $this->get_state();
        $delay_us   = (int) apply_filters( 'hostney_cache_warm_delay_ms', 200 ) * 1000;
        $budget     = (int) apply_filters( 'hostney_cache_warm_batch_seconds', self::BATCH_SECONDS );
        $deadline   = time() + max( 1, $budget );
        $warmed     = (int) $state['warmed'];
        $failed     = (int) $state['failed'];
        $done       = (int) $state['done'];
        $cache_seen = (bool) $state['cache_seen'];
        $current    = '';
        $in_batch   = 0;

        // Strictly one URL at a time. See the class docblock: a Hostney site has
        // five PHP children and every one of these is a full uncached render.
        while ( ! empty( $queue ) && $in_batch < self::BATCH_MAX_URLS && time() < $deadline ) {
            $url    = array_shift( $queue );
            $result = $this->fetch( $url );

            $done++;
            $in_batch++;
            if ( $result['ok'] ) {
                $warmed++;
            } else {
                $failed++;
            }
            if ( $result['cache_header'] ) {
                $cache_seen = true;
            }
            $current = $url;

            // Breathe between requests. One render at a time is the point; this
            // just widens the gap so a visitor arriving mid-run is never queued
            // behind us.
            if ( $delay_us > 0 ) {
                usleep( $delay_us );
            }
        }

        update_option( self::QUEUE_OPTION, $queue, false );
        $this->set_state( array(
            'done'       => $done,
            'warmed'     => $warmed,
            'failed'     => $failed,
            'current'    => $current,
            'cache_seen' => $cache_seen,
        ) );

        if ( empty( $queue ) ) {
            $this->finish();
            return;
        }

        $this->schedule_next();
    }

    /**
     * Close out a completed run.
     */
    private function finish() {
        delete_option( self::QUEUE_OPTION );
        wp_clear_scheduled_hook( self::CRON_HOOK );

        $state = $this->get_state();

        if ( ! $state['cache_seen'] && $state['done'] > 0 ) {
            // Every page rendered and nothing was stored. Saying "warmed N
            // pages" here would be a lie the customer only discovers when the
            // site is still slow.
            $message = sprintf(
                'Requested %d pages, but no page-cache header came back on any of them — page caching looks switched off for this site, so nothing was stored.',
                $state['done']
            );
        } elseif ( $state['failed'] > 0 ) {
            $message = sprintf(
                'Warmed %d of %d pages. %d could not be fetched.',
                $state['warmed'],
                $state['total'],
                $state['failed']
            );
        } else {
            $message = sprintf( 'Warmed %d pages.', $state['warmed'] );
        }

        $this->set_state( array(
            'status'      => 'done',
            'finished_at' => time(),
            'current'     => '',
            'message'     => $message,
        ) );
    }

    /**
     * Request one URL so nginx stores it.
     *
     * @param string $url absolute public URL
     * @return array {ok: bool, cache_header: bool}
     */
    private function fetch( $url ) {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! $path ) {
            $path = '/';
        }

        // Scheme comes from the SITE, not from is_ssl(). nginx puts $scheme in
        // the cache key, and this runs inside a cron request whose own scheme
        // is incidental — warming over http a site that visitors reach over
        // https would fill a key nobody ever asks for.
        $scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
        if ( $scheme !== 'http' && $scheme !== 'https' ) {
            $scheme = is_ssl() ? 'https' : 'http';
        }

        // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- Intentional: see the class docblock. The public hostname would warm the edge, not this origin.
        $local = $scheme . '://127.0.0.1' . $path;

        $response = wp_remote_get( $local, array(
            'timeout'     => 15,
            'sslverify'   => false,
            'redirection' => 0,
            'headers'     => array(
                'Host' => $this->purger->get_domain(),
                // Named so this traffic is identifiable in access logs and can
                // be excluded from analytics. It is deliberately NOT a browser
                // string: something pretending to be Chrome from 127.0.0.1 is
                // exactly what an operator reading the log does not need.
                'User-Agent' => 'Hostney-Cache-Warmer/' . HOSTNEY_CACHE_VERSION . ' (+https://www.hostney.com)',
                // No cookies, deliberately. A logged-in cookie makes nginx skip
                // the cache, so the whole run would render pages and store none.
                'Cache-Control' => 'no-cache',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'ok' => false, 'cache_header' => false );
        }

        $code = wp_remote_retrieve_response_code( $response );

        // Any of the headers nginx or the edge may use to report cache state.
        // Presence is what matters, not the value: a MISS proves the cache is
        // there and that this request is what populated it.
        $cache_header = false;
        foreach ( array( 'x-cache-status', 'x-fastcgi-cache', 'x-proxy-cache', 'cf-cache-status' ) as $header ) {
            if ( wp_remote_retrieve_header( $response, $header ) !== '' ) {
                $cache_header = true;
                break;
            }
        }

        return array(
            'ok'           => $code >= 200 && $code < 400,
            'cache_header' => $cache_header,
        );
    }

    // ---------------------------------------------------------------- the list

    /**
     * Collect the public URLs worth warming, most valuable first.
     *
     * Order matters on a site that hits the cap or gets stopped half way: the
     * front page and the newest posts are what the next visitor asks for.
     *
     * ⚠ Only URLs nginx can actually cache. A query string bypasses the cache
     * on the default Hostney configuration, so warming one renders a page and
     * stores nothing.
     *
     * @return string[]
     */
    public function collect_urls() {
        $max = (int) apply_filters( 'hostney_cache_warm_max_urls', 500 );
        if ( $max < 1 ) {
            return array();
        }

        $urls = array( home_url( '/' ) );

        $post_types = get_post_types( array( 'public' => true ), 'names' );
        unset( $post_types['attachment'] );

        if ( ! empty( $post_types ) ) {
            $posts = get_posts( array(
                'post_type'        => array_values( $post_types ),
                'post_status'      => 'publish',
                'numberposts'      => $max,
                'orderby'          => 'modified',
                'order'            => 'DESC',
                'suppress_filters' => false,
                // Only the IDs are needed and this can be several hundred rows;
                // hydrating full post objects would be the expensive part of
                // an operation that is meant to be cheap.
                'fields'           => 'ids',
            ) );

            foreach ( $posts as $post_id ) {
                $urls[] = get_permalink( $post_id );
            }
        }

        // Archives are the pages that cost the most to render and are hit by
        // the most visitors, so they earn their place even though there are
        // usually far fewer of them than posts.
        $taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
        if ( ! empty( $taxonomies ) ) {
            $terms = get_terms( array(
                'taxonomy'   => array_values( $taxonomies ),
                'hide_empty' => true,
                'number'     => $max,
            ) );

            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $link = get_term_link( $term );
                    if ( ! is_wp_error( $link ) ) {
                        $urls[] = $link;
                    }
                }
            }
        }

        /**
         * Filter the URL list before it is trimmed and queued.
         *
         * @param string[] $urls
         */
        $urls = apply_filters( 'hostney_cache_warm_urls', $urls );

        $home = home_url();
        $keep = array();
        foreach ( $urls as $url ) {
            if ( ! is_string( $url ) || $url === '' ) {
                continue;
            }
            // Off-site URLs would warm somebody else's cache, and a query
            // string bypasses ours.
            if ( strpos( $url, $home ) !== 0 ) {
                continue;
            }
            if ( strpos( $url, '?' ) !== false ) {
                continue;
            }
            $keep[] = $url;
        }

        return array_slice( array_values( array_unique( $keep ) ), 0, $max );
    }
}
