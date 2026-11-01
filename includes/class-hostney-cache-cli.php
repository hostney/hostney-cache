<?php
/**
 * Hostney Cache - WP-CLI commands (1.3.3)
 *
 *   wp hostney-cache flush-site     empty this site's object-cache entries
 *   wp hostney-cache warm           pre-fetch every public page
 *
 * The platform's update worker runs both after an update run (api
 * library/wordpress_cache_refresh.js and queues/wordpress_cache_warm_worker.js);
 * anyone with SSH can run them too.
 *
 * ⚠⚠ THE ANSWER IS ONE LINE OF JSON ON STDOUT. The worker reads the last line
 * that parses as a JSON object, so a plugin printing notices around it does not
 * matter. Its keys are a contract with api library/wordpress_cache_refresh.js:
 * rename one only together with that file. Exit 0 when the command did what it
 * says (including "nothing to do"), 1 when it could not.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_CLI {

    /** How long `warm` runs when --seconds is not given. */
    const WARM_DEFAULT_SECONDS = 600;

    /** The longest `warm` may run before it hands what is left to WP-Cron. */
    const WARM_MAX_SECONDS = 3600;

    /**
     * Empty this site's object-cache entries.
     *
     * With Redis, only this site's keys: the other sites on the account keep
     * theirs. Memcached cannot tell sites apart, so there the whole account's
     * cache is emptied (scope "account"). A site that does not run Hostney
     * Cache's drop-in, or whose account has no cache service running, gets
     * WordPress's own flush - the same as `wp cache flush` (scope "wordpress").
     *
     * ## EXAMPLES
     *
     *     wp hostney-cache flush-site
     *
     * @subcommand flush-site
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function flush_site( $args, $assoc_args ) {
        $object_cache = new Hostney_Cache_Object_Cache();
        $dropin       = $object_cache->dropin()->get_status();

        // Only OUR drop-in tells us whose keys are whose. A foreign one (Object
        // Cache Pro, Redis Object Cache) is flushed the way WordPress would.
        $backend = in_array( $dropin, array( 'installed', 'outdated' ), true )
            ? $object_cache->active_backend()
            : null;

        if ( ! $backend ) {
            $ok = (bool) wp_cache_flush();
            $this->answer( array(
                'success' => $ok,
                'scope'   => 'wordpress',
                'engine'  => '',
                'dropin'  => $dropin,
                'message' => $ok ? 'Flushed WordPress\'s object cache.' : 'WordPress\'s object cache could not be flushed.',
            ), $ok );
            return;
        }

        if ( $backend->supports_scoped_flush() ) {
            // ⚠ NEVER WIDENED HERE. flush_site() refuses rather than empty the
            // account (an outdated drop-in with no prefix); the caller decides
            // whether an account-wide flush is acceptable instead.
            $result = $object_cache->flush_site();
            $scope  = 'site';
        } else {
            $result = $object_cache->flush();
            $scope  = 'account';
        }

        $ok = ! empty( $result['success'] );
        $this->answer( array(
            'success' => $ok,
            'scope'   => $scope,
            'engine'  => $backend->get_engine(),
            'dropin'  => $dropin,
            'deleted' => isset( $result['deleted'] ) ? (int) $result['deleted'] : null,
            'message' => (string) ( $result['message'] ?? '' ),
        ), $ok );
    }

    /**
     * Pre-fetch every public page, so the page cache is full before visitors
     * arrive.
     *
     * One page at a time, like the button on the plugin's page, under the same
     * lock: an open admin page or a background batch never renders a page
     * beside this one. A run already in progress is stopped and started again
     * from a fresh list.
     *
     * ## OPTIONS
     *
     * [--seconds=<seconds>]
     * : The longest this command runs. Pages left after it are pre-fetched in
     * the background (WP-Cron). Default 600, at most 3600.
     *
     * [--[no-]purge]
     * : Clear the page cache first (the default). --no-purge when the caller
     * has just cleared it.
     *
     * [--after-updates]
     * : This is the automatic pre-fetch after updates: do nothing when the site
     * has "Pre-fetch after updates" switched off.
     *
     * ## EXAMPLES
     *
     *     wp hostney-cache warm
     *     wp hostney-cache warm --seconds=120
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function warm( $args, $assoc_args ) {
        $after_updates = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'after-updates', false );
        $purge         = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'purge', true );
        $seconds       = isset( $assoc_args['seconds'] ) ? (int) $assoc_args['seconds'] : self::WARM_DEFAULT_SECONDS;
        $seconds       = max( 1, min( self::WARM_MAX_SECONDS, $seconds ) );

        if ( $after_updates && ! Hostney_Cache_Settings::get( 'warm_after_updates' ) ) {
            $this->answer( array(
                'success' => true,
                'status'  => 'off',
                'message' => 'Pre-fetch after updates is switched off for this site.',
            ), true );
            return;
        }

        $purger = new Hostney_Cache_Purger();
        if ( (string) $purger->get_domain() === '' ) {
            $this->answer( array(
                'success' => false,
                'status'  => 'no_domain',
                'message' => 'This site\'s own address is not known here, so its pages cannot be requested.',
            ), false );
            return;
        }

        // The same question the plugin's page asks before it offers the button:
        // without page caching every page would be rendered and thrown away.
        if ( ! $purger->detect_page_cache() ) {
            $this->answer( array(
                'success' => true,
                'status'  => 'no_page_cache',
                'message' => 'Page caching is not enabled for this site, so there is nothing to pre-fetch into.',
            ), true );
            return;
        }

        $warmer  = new Hostney_Cache_Warmer( $purger );
        $started = $warmer->restart( $purge );
        if ( empty( $started['success'] ) ) {
            $this->answer( array(
                'success' => false,
                'status'  => 'not_started',
                'message' => (string) ( $started['message'] ?? '' ),
            ), false );
            return;
        }

        $state  = $warmer->run_here( $seconds );
        $status = $state['status'] === 'running' ? 'continuing' : (string) $state['status'];

        // 'stopped' is somebody pressing Stop on the plugin's page meanwhile -
        // their call, not this command failing.
        $ok = in_array( $status, array( 'done', 'continuing', 'stopped' ), true );
        $this->answer( array(
            'success'    => $ok,
            'status'     => $status,
            'total'      => (int) $state['total'],
            'done'       => (int) $state['done'],
            'warmed'     => (int) $state['warmed'],
            'failed'     => (int) $state['failed'],
            'cache_seen' => (bool) $state['cache_seen'],
            'message'    => $status === 'continuing'
                ? sprintf( 'Pre-fetched %d of %d pages; the rest continue in the background.', (int) $state['done'], (int) $state['total'] )
                : (string) $state['message'],
        ), $ok );
    }

    /**
     * Print the answer (see the file docblock) and set the exit code.
     *
     * @param array $answer
     * @param bool  $ok
     */
    private function answer( array $answer, $ok ) {
        WP_CLI::line( wp_json_encode( $answer ) );
        if ( ! $ok ) {
            WP_CLI::halt( 1 );
        }
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
    WP_CLI::add_command( 'hostney-cache', 'Hostney_Cache_CLI' );
}
