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
 * ⚠⚠ TWO ENGINES, AND THE OPEN PAGE IS THE ONE THAT ALWAYS WORKS. While the
 * settings page - or any page showing the admin bar - is open, its progress
 * poll warms the next page itself (advance()). With nothing open, the run
 * continues on WP-Cron (run_batch()).
 *
 * Until 1.3.1 the poll only re-sent WP-Cron's request, and the docs called that
 * "the poll is the engine". It never was: WP-Cron is a request the site sends
 * to its OWN PUBLIC ADDRESS - out through DNS, any CDN and any proxy in front
 * of the site, and back - and WordPress never reads the answer. Anything on
 * that route that refuses it (a CDN challenge, a proxy, a domain that does not
 * point here yet) stops cron with no error anywhere - and so did a cron lock
 * that could not be kept: on every site with our drop-in and no cache service
 * running, cron never ran at all until 1.3.1 (wp_cache_init() in
 * object-cache-dropin.tpl). The poll re-sent the same doomed request every
 * minute: the bar sat at "0 of N" for five minutes and then failed. The poll reaches this code over the administrator's own
 * connection, and the page fetches go to 127.0.0.1, so it needs no public
 * route at all. Cron also rides on real traffic, and a fully cached site has
 * very little of it reaching PHP - which is exactly the site somebody just
 * asked to warm.
 *
 * ⚠ ONE BATCH AT A TIME, WHOEVER RUNS IT: both engines take the same MySQL lock
 * (acquire_lock()), so they take turns and never render two pages at once.
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
     * How many pages one progress poll warms: one.
     *
     * The poll answers the browser with the new state, so it has to come back
     * in about the time one page takes to render, and the browser waits for
     * that answer before it asks again. One page per poll keeps the open page
     * to the same one-render-at-a-time rule as a cron batch.
     */
    const POLL_MAX_URLS = 1;

    /**
     * A run whose state has not moved for this long is treated as dead, so a
     * cron that was never fired (or a PHP fatal mid-batch) cannot wedge the
     * button forever.
     */
    const STALE_AFTER = 300;

    /**
     * A run that has fetched this many pages without one page-cache header
     * stops: page caching is off for the site, so every further page would be
     * rendered and thrown away. Until 1.3.3 it rendered the whole list (up to
     * 500 pages) before saying so.
     */
    const UNCACHED_STOP_AFTER = 5;

    /**
     * Cron hook that starts a fresh run after an update made in wp-admin
     * (Hostney_Cache_Hooks::on_site_update).
     */
    const AFTER_CHANGE_HOOK = 'hostney_cache_warm_after_change';

    /**
     * How long after the LAST update the fresh run starts, in seconds.
     *
     * ⚠ A DELAY, MOVED ON BY EVERY UPDATE. wp-admin updates plugins one request
     * at a time, and a run started after the first would render pages while the
     * next one sits in maintenance mode (503s, nothing stored), then have its
     * work purged by the next update's purge anyway.
     */
    const AFTER_CHANGE_DELAY = 60;

    /** @var Hostney_Cache_Purger */
    private $purger;

    /** @var bool Whether this request holds the run's lock right now. */
    private $lock_held = false;

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
        add_action( self::AFTER_CHANGE_HOOK, array( $this, 'run_after_change' ) );
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
            // Which run this is. "Running" alone cannot tell a batch that its
            // run was stopped and a new one started while it fetched - both
            // read "running" - see is_current_run().
            'run_id'      => '',
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
            $state['message'] = 'The warm-up stopped: nothing moved it on for five minutes. It moves on its own while this page is open; otherwise it waits for WordPress\'s scheduled tasks (WP-Cron), and those did not run. Start it again and keep this page open until it finishes.';
            // Saved, not only answered (1.3.4): the option said "running" for good
            // after a run died, and whatever read it raw - WP-CLI, the platform -
            // believed it. finished_at = when it last moved, the moment it stopped.
            if ( empty( $state['finished_at'] ) ) {
                $state['finished_at'] = (int) $state['updated_at'];
            }
            update_option( self::STATE_OPTION, $state, false );
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
     * @param bool $purge false when the caller has just cleared the page cache
     *                    itself (the platform's update worker does, through the
     *                    server - see Hostney_Cache_CLI::warm)
     * @return array {success, message}
     */
    public function start( $purge = true ) {
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

        if ( $purge ) {
            $purged = $this->purger->purge_all();
            if ( empty( $purged['success'] ) ) {
                return array(
                    'success' => false,
                    // Say which half failed. "Warm-up failed" on a purge error sends
                    // the reader looking in the wrong place.
                    'message' => 'Could not clear the cache, so nothing was warmed. ' . ( $purged['message'] ?? '' ),
                );
            }
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
            'run_id'      => wp_generate_uuid4(),
        ), false );

        $this->schedule_next();

        return array(
            'success' => true,
            'message' => sprintf( 'Cache cleared. Warming %d URLs in the background.', count( $urls ) ),
            'total'   => count( $urls ),
        );
    }

    /**
     * Start a fresh run: stop the one in progress, if any, then start().
     *
     * For a site that just CHANGED. A run that is already going walks a list
     * built before the change and warms pages a purge has since thrown away.
     *
     * @param bool $purge see start()
     * @return array {success, message}
     */
    public function restart( $purge = true ) {
        if ( $this->is_running() ) {
            $this->stop();
        }
        return $this->start( $purge );
    }

    /**
     * Ask for a fresh run AFTER_CHANGE_DELAY seconds from now, moving any one
     * already asked for (see AFTER_CHANGE_DELAY).
     */
    public function schedule_after_change() {
        wp_clear_scheduled_hook( self::AFTER_CHANGE_HOOK );
        wp_schedule_single_event( time() + self::AFTER_CHANGE_DELAY, self::AFTER_CHANGE_HOOK );
    }

    /**
     * WP-Cron: the fresh run asked for after an update. The update's own request
     * purged the page cache already (Hostney_Cache_Hooks); purging again here
     * also clears whatever visitors re-cached during the update's last steps.
     */
    public function run_after_change() {
        $this->restart( true );
    }

    /**
     * Warm in THIS process until the run ends or the time is up - for WP-CLI,
     * where nothing polls and a cron request may never come.
     *
     * Takes the same lock as the other two engines, so an open admin page or a
     * cron batch never renders a page beside it: while one of them holds the
     * lock this waits a second and asks again.
     *
     * @param int $seconds the most this may take before it hands what is left
     *                     to WP-Cron (it finishes the page it is on first)
     * @return array the run state at the end
     */
    public function run_here( $seconds ) {
        $deadline = time() + max( 1, (int) $seconds );

        while ( $this->is_running() && time() < $deadline ) {
            $budget = min( self::BATCH_SECONDS, $deadline - time() );
            if ( ! $this->work( $budget, self::BATCH_MAX_URLS ) ) {
                // Another engine holds the lock for this run.
                sleep( 1 );
            }
        }

        // Out of time with pages left: WP-Cron takes the rest, as it would for a
        // run started from the admin page and then left. No spawn_cron(): under
        // WP-CLI it would post to the site's own address from here, which is
        // the platform's "http://." placeholder (see Hostney_Cache_Purger::is_wp_cli()).
        if ( $this->is_running() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK );
        }

        return $this->get_state();
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
     * Queue the next cron batch and try to make it run now.
     *
     * ⚠ spawn_cron() is a best-effort nudge, not a guarantee. It declines if
     * another spawn happened in the last 60s (WP_CRON_LOCK_TIMEOUT), it fires
     * at the site's public address without waiting for an answer, and inside a
     * cron request it does nothing. That is why an open page warms pages itself
     * (advance()) instead of relying on this.
     *
     * ⚠⚠ INSIDE A CRON REQUEST THE RUN STARTS ITS OWN NEXT BATCH (1.3.4). Until
     * then the next batch waited for WP-Cron, and WP-Cron runs only when a
     * visit reaches PHP - which on a cached site almost none do, while the run's
     * own fetches arrive while this batch holds the cron lock. More than
     * STALE_AFTER without a batch and the run was dead (get_state()), its
     * scheduled tick did nothing when it finally fired, and the state said
     * "running" for good: a shop's after-update run stopped at 33 of 128 pages
     * and stayed so for 12 hours (2026-11-02). kick_at_shutdown() fires the next
     * cron request once WordPress has let go of this one's lock.
     *
     * @param bool $kick false when this batch warmed nothing (another engine
     *                   holds the run - it moves it on itself)
     */
    private function schedule_next( $kick = true ) {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK );
        }
        spawn_cron();

        if ( $kick && wp_doing_cron() ) {
            $this->kick_at_shutdown();
        }
    }

    /** @var bool Whether this request already asked for the next cron request. */
    private $kick_registered = false;

    /**
     * Fire the next cron request when this one ends (see schedule_next()).
     *
     * At shutdown, not now: wp-cron.php holds WordPress's cron lock while it
     * runs the events, and a request fired now would find the lock taken and do
     * nothing. wp-cron.php lets go of it right before it ends, and shutdown runs
     * after that.
     */
    private function kick_at_shutdown() {
        if ( $this->kick_registered ) {
            return;
        }
        $this->kick_registered = true;
        add_action( 'shutdown', array( $this, 'kick_cron' ), PHP_INT_MAX );
    }

    /**
     * Start the next cron request: what spawn_cron() does, which it refuses
     * inside a cron request (DOING_CRON) - take WordPress's cron lock with a new
     * key, then ask wp-cron.php to run under that key without waiting for it.
     *
     * ⚠ OVER 127.0.0.1 WITH THE SITE'S HOST, like every fetch of this class -
     * never the site's public address (see the class docblock: a CDN, a proxy or
     * a domain not pointing here yet refuses that route, and nothing says so).
     * Public: the shutdown action calls it.
     */
    public function kick_cron() {
        $this->forget_cached_run();
        if ( ! $this->is_running() || ! wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        // A lock still held belongs to another cron request: it runs the tick.
        // ⚠ Read the way wp-cron.php reads it - _get_cron_lock(), fresh from the
        // database or the object cache - NEVER get_transient(): wp-cron.php has
        // just deleted the lock, and this request's own copy still answered the
        // old key, so the kick stood down after every batch (the e2e caught it).
        $timeout = defined( 'WP_CRON_LOCK_TIMEOUT' ) ? (int) WP_CRON_LOCK_TIMEOUT : 60;
        $held    = (float) ( function_exists( '_get_cron_lock' ) ? _get_cron_lock() : get_transient( 'doing_cron' ) );
        if ( $held > microtime( true ) - $timeout ) {
            return;
        }

        $key = sprintf( '%.22F', microtime( true ) );
        set_transient( 'doing_cron', $key );

        $path = wp_parse_url( site_url( 'wp-cron.php' ), PHP_URL_PATH );
        if ( ! $path ) {
            $path = '/wp-cron.php';
        }

        // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- Intentional: see the class docblock.
        wp_remote_post( $this->purger->site_scheme() . '://127.0.0.1' . $path . '?doing_wp_cron=' . $key, array(
            // Long enough for a local TLS handshake and the request to leave;
            // wp-cron.php carries on without us (ignore_user_abort), as it does
            // for WordPress's own spawn.
            'timeout'   => 1,
            'blocking'  => false,
            'sslverify' => false,
            'headers'   => array(
                'Host'       => $this->purger->get_domain(),
                'User-Agent' => 'Hostney-Cache-Warmer/' . HOSTNEY_CACHE_VERSION . ' (+https://www.hostney.com)',
            ),
        ) );
    }

    /**
     * Warm the next page from a progress poll: the settings page's, or the
     * admin bar's on any page while a run is in flight.
     *
     * ⚠⚠ THIS DOES THE WORK, it does not just ask WP-Cron to. See the class
     * docblock: the cron request travels the site's public route, and on a site
     * where that route refuses it the run never moved however long somebody
     * watched it.
     */
    public function advance() {
        if ( ! $this->is_running() ) {
            return;
        }

        $this->work( 1, self::POLL_MAX_URLS );

        // Keep the background engine armed too, for when the page is closed.
        if ( $this->is_running() ) {
            $this->schedule_next();
        }
    }

    // ---------------------------------------------------------------- the work

    /**
     * WP-Cron: warm one time-boxed batch, then queue the next.
     */
    public function run_batch() {
        $worked = $this->work(
            (int) apply_filters( 'hostney_cache_warm_batch_seconds', self::BATCH_SECONDS ),
            self::BATCH_MAX_URLS
        );

        if ( $this->is_running() ) {
            // Start the next batch ourselves only after warming: a batch that found
            // the lock taken leaves the run to its holder, or two engines would
            // chase each other in a loop of empty cron requests.
            $this->schedule_next( $worked );
        }
    }

    /**
     * Warm the next pages of the run under its lock. Shared by both engines.
     *
     * @param int $budget   seconds this call may keep starting new pages
     * @param int $max_urls the most pages this call may warm
     * @return bool false when nothing was done: no run, or another engine holds the lock
     */
    private function work( $budget, $max_urls ) {
        if ( ! $this->is_running() ) {
            return false;
        }

        // Taken: the other engine is warming a page right now. Rendering a
        // second one beside it is the one thing this class must never do, and
        // both would write back the same queue - so this caller answers with
        // the state as it stands and the holder carries on.
        if ( ! $this->acquire_lock() ) {
            return false;
        }

        try {
            $this->warm_next( $budget, $max_urls );
        } finally {
            $this->release_lock();
        }
        return true;
    }

    /**
     * The body of a batch. Only ever runs with the lock held (work()).
     *
     * @param int $budget
     * @param int $max_urls
     */
    private function warm_next( $budget, $max_urls ) {
        // Read the run AFTER taking the lock, never before: the batch that held
        // it may have moved the queue on, or finished the run, a moment ago -
        // and this request can already hold an older copy in its object cache.
        $this->forget_cached_run();

        $state = $this->get_state();
        if ( 'running' !== $state['status'] ) {
            return;
        }
        $run_id = (string) $state['run_id'];

        $queue = get_option( self::QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || empty( $queue ) ) {
            $this->finish();
            return;
        }

        // Mark the run alive BEFORE the fetches. A batch that picks up a run
        // close to STALE_AFTER would otherwise find it "failed" when it comes
        // to write back - made stale by the batch's own fetch time.
        $state = $this->set_state( array() );

        $delay_us   = (int) apply_filters( 'hostney_cache_warm_delay_ms', 200 ) * 1000;
        $deadline   = time() + max( 1, (int) $budget );
        $warmed     = (int) $state['warmed'];
        $failed     = (int) $state['failed'];
        $done       = (int) $state['done'];
        $cache_seen = (bool) $state['cache_seen'];
        $current    = '';
        $in_batch   = 0;

        // Strictly one URL at a time. See the class docblock: a Hostney site has
        // five PHP children and every one of these is a full uncached render.
        while ( ! empty( $queue ) && $in_batch < $max_urls ) {
            if ( $in_batch > 0 ) {
                // The time box is checked before every page but the first, so a
                // batch always warms at least one: a one-page poll that started
                // just as the clock ticked over would otherwise do nothing.
                if ( time() >= $deadline ) {
                    break;
                }

                // Between pages too, not only at the end: whoever pressed Stop
                // wants the renders to stop, not to run out the time box.
                if ( ! $this->is_current_run( $run_id ) ) {
                    return;
                }

                // Breathe between requests. One render at a time is the point;
                // this just widens the gap so a visitor arriving mid-run is
                // never queued behind us.
                if ( $delay_us > 0 ) {
                    usleep( $delay_us );
                }
            }

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

            // Nothing to fill: see UNCACHED_STOP_AFTER. finish() says why.
            if ( ! $cache_seen && $done >= self::UNCACHED_STOP_AFTER ) {
                $queue = array();
                break;
            }
        }

        // Write back only into the run this batch started on. Stop can land
        // while it fetches - and a new run can start straight after - and this
        // batch's copy written on top would restart the old run or overwrite
        // the new one's queue.
        if ( ! $this->is_current_run( $run_id ) ) {
            return;
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
        }
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

    // ---------------------------------------------------------------- the lock

    /**
     * Take the run's lock without waiting.
     *
     * ⚠ A MySQL named lock, not an option row: it belongs to this request's
     * database connection, so a batch that dies half way - a PHP fatal, a
     * killed worker, a timeout - lets go of it with the connection, and nothing
     * can be left holding the run for good.
     *
     * @return bool true when this request may warm
     */
    private function acquire_lock() {
        global $wpdb;

        $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock_name() ) );

        if ( null === $got ) {
            // NULL is an error, not "taken" (that is "0"): a database with no
            // named locks. Going ahead loses the overlap guard there; refusing
            // would stop every warm-up on that database for good.
            return true;
        }

        $this->lock_held = ( '1' === (string) $got );
        return $this->lock_held;
    }

    /**
     * Let go of the lock, if this request took it.
     */
    private function release_lock() {
        global $wpdb;

        if ( ! $this->lock_held ) {
            return;
        }
        $this->lock_held = false;

        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) );
    }

    /**
     * The lock's name.
     *
     * ⚠ MySQL lock names are shared by every database on the server - on a
     * Hostney box, by every site on it - so this one is keyed on the site's own
     * secret salt: unique to the site, and not a name a neighbour could work out
     * and hold to stop this site's warm-ups. Cut to 32 characters so the name
     * stays inside MySQL's 64 even if wp_hash() moves to a longer digest.
     *
     * @return string
     */
    private function lock_name() {
        return 'hostney_warm_' . substr( wp_hash( 'hostney_cache_warm_lock|' . get_current_blog_id() ), 0, 32 );
    }

    /**
     * Is the run a batch started on still the one in progress? Read from the
     * database, never from this request's copy.
     *
     * @param string $run_id the run_id the batch read when it took the lock
     * @return bool
     */
    private function is_current_run( $run_id ) {
        $this->forget_cached_run();
        $state = $this->get_state();

        return 'running' === $state['status'] && (string) $state['run_id'] === $run_id;
    }

    /**
     * Drop this request's copy of the run, so the next read comes from the
     * database. Both options are saved with autoload off, so each is cached
     * under its own key in 'options', never inside alloptions.
     */
    private function forget_cached_run() {
        wp_cache_delete( self::STATE_OPTION, 'options' );
        wp_cache_delete( self::QUEUE_OPTION, 'options' );
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

        // Scheme comes from the SITE, not from is_ssl(): nginx puts $scheme in
        // the cache key, and this runs inside a cron request or WP-CLI whose own
        // scheme is incidental. See Hostney_Cache_Purger::site_scheme().
        // phpcs:ignore PluginCheck.CodeAnalysis.Localhost.Found -- Intentional: see the class docblock. The public hostname would warm the edge, not this origin.
        $local = $this->purger->site_scheme() . '://127.0.0.1' . $path;

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
        //
        // ⚠ THE LIST LIVES ON THE PURGER, and there is only one of it. This had
        // its own copy; a header added to one and not the other means the warmer
        // and the admin page disagree about whether the same site is cached.
        $cache_header = false;
        foreach ( Hostney_Cache_Purger::CACHE_HEADERS as $header ) {
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
