<?php
/**
 * Hostney Cache - Admin UI
 *
 * Top-level admin menu page, post editor meta box, and AJAX handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Admin {

    /** @var Hostney_Cache_Purger */
    private $purger;

    /** @var Hostney_Cache_Object_Cache */
    private $object_cache;

    /** @var Hostney_Cache_Warmer */
    private $warmer;

    public function __construct( Hostney_Cache_Purger $purger, Hostney_Cache_Object_Cache $object_cache, Hostney_Cache_Warmer $warmer ) {
        $this->purger       = $purger;
        $this->object_cache = $object_cache;
        $this->warmer       = $warmer;

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );

        // AJAX handlers — nginx cache
        add_action( 'wp_ajax_hostney_cache_purge_all', array( $this, 'ajax_purge_all' ) );
        add_action( 'wp_ajax_hostney_cache_purge_post', array( $this, 'ajax_purge_post' ) );
        add_action( 'wp_ajax_hostney_cache_clear_log', array( $this, 'ajax_clear_log' ) );

        // AJAX handlers — flush and pre-fetch
        add_action( 'wp_ajax_hostney_cache_warm_start', array( $this, 'ajax_warm_start' ) );
        add_action( 'wp_ajax_hostney_cache_warm_status', array( $this, 'ajax_warm_status' ) );
        add_action( 'wp_ajax_hostney_cache_warm_stop', array( $this, 'ajax_warm_stop' ) );

        // AJAX handler — object cache flush (whichever engine is running)
        //
        // ⚠ RENAMED FROM hostney_memcached_flush IN 1.2.0, and admin/js/cache.js
        // was changed in the same commit. Both sides are in this plugin, so
        // nothing external breaks - but they must move together, and a stale
        // browser cache during the upgrade will simply get "Bad Request" from
        // admin-ajax until it reloads.
        // ⚠ THIS ACTION NOW MEANS "THIS SITE ONLY". It used to flush the whole
        // instance, i.e. every site on the account. Repointing the EXISTING name
        // at the narrower operation is deliberate: a browser holding cached JS
        // from an older version keeps working, and what it does is the safe half
        // of what it used to do. Adding the scoped call under a new name and
        // leaving this one account-wide would have had exactly the reverse
        // property.
        add_action( 'wp_ajax_hostney_object_cache_flush', array( $this, 'ajax_object_cache_flush_site' ) );
        add_action( 'wp_ajax_hostney_object_cache_flush_account', array( $this, 'ajax_object_cache_flush_account' ) );
        add_action( 'wp_ajax_hostney_object_cache_keyspace', array( $this, 'ajax_object_cache_keyspace' ) );

        // Form POST handlers — drop-in (redirect-based, not AJAX)
        add_action( 'admin_post_hostney_object_cache_install_dropin', array( $this, 'handle_install_dropin' ) );
        add_action( 'admin_post_hostney_object_cache_remove_dropin', array( $this, 'handle_remove_dropin' ) );
    }

    /**
     * Add top-level admin menu page
     */
    public function add_admin_menu() {
        add_menu_page(
            'Hostney Cache',
            'Hostney Cache',
            'manage_options',
            'hostney-cache',
            array( $this, 'render_admin_page' ),
            'dashicons-performance',
            81
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts( $hook ) {
        // Admin page CSS (only on plugin page)
        if ( 'toplevel_page_hostney-cache' === $hook ) {
            wp_enqueue_style(
                'hostney-cache-css',
                HOSTNEY_CACHE_PLUGIN_URL . 'admin/css/cache.css',
                array(),
                HOSTNEY_CACHE_VERSION
            );
        }

        // Single JS for admin page, meta box, and admin bar (all admin pages)
        if ( current_user_can( 'manage_options' ) ) {
            wp_enqueue_script(
                'hostney-cache-js',
                HOSTNEY_CACHE_PLUGIN_URL . 'admin/js/cache.js',
                array( 'jquery' ),
                HOSTNEY_CACHE_VERSION,
                true
            );

            wp_localize_script( 'hostney-cache-js', 'hostneyCache', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'hostney_cache_nonce' ),
            ) );
        }
    }

    /**
     * Add meta box on public post type edit screens
     */
    public function add_meta_boxes() {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'hostney-cache-metabox',
                'Hostney Cache',
                array( $this, 'render_meta_box' ),
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render meta box content
     */
    public function render_meta_box( $post ) {
        if ( $post->post_status !== 'publish' ) {
            echo '<p style="color:#656871;font-size:13px;margin:0;">Publish this post to enable cache purging.</p>';
            return;
        }
        ?>
        <p style="margin:0 0 10px;">
            <button type="button" class="button hostney-purge-post-btn" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                Purge cache for this page
            </button>
        </p>
        <div class="hostney-metabox-feedback" style="display:none;font-size:13px;margin:0;"></div>
        <?php
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        include HOSTNEY_CACHE_PLUGIN_DIR . 'admin/views/cache-page.php';
    }

    /**
     * AJAX: Purge all cache
     */
    public function ajax_purge_all() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $result = $this->purger->purge_all();

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => 'Cache purged successfully.' ) );
        } else {
            wp_send_json_error( array( 'message' => $result['message'] ?? 'Failed to purge cache.' ) );
        }
    }

    /**
     * AJAX: Purge a specific post's cache
     */
    public function ajax_purge_post() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid post.' ) );
        }

        $result = $this->purger->purge_post( $post_id );

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => $result['message'] ) );
        } else {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }
    }

    /**
     * AJAX: Clear the purge log
     */
    public function ajax_clear_log() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        delete_option( 'hostney_cache_log' );
        wp_send_json_success( array( 'message' => 'Log cleared.' ) );
    }

    /**
     * AJAX: Flush the page cache, then start warming it in the background.
     */
    public function ajax_warm_start() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $result = $this->warmer->start();

        // The state goes back with both outcomes so the browser can render the
        // bar without a second round trip, and so a refusal ("already running")
        // shows the run it is refusing to duplicate.
        $result['state'] = $this->warmer->get_state();

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result );
    }

    /**
     * AJAX: Progress of the current or last warm-up.
     *
     * ⚠ This also NUDGES cron. WP-Cron rides on real traffic, and a site that
     * is fully cached has very little of it reaching PHP — so while somebody
     * watches the progress bar, this poll is what keeps the run moving. Drop
     * the nudge and the bar sits still on exactly the sites the feature is for.
     */
    public function ajax_warm_status() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $this->warmer->nudge();

        wp_send_json_success( array( 'state' => $this->warmer->get_state() ) );
    }

    /**
     * AJAX: Cancel a running warm-up.
     */
    public function ajax_warm_stop() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $result          = $this->warmer->stop();
        $result['state'] = $this->warmer->get_state();

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Flush THIS site's object cache entries only.
     */
    public function ajax_object_cache_flush_site() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $result = $this->object_cache->flush_site();

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => $result['message'] ) );
        } else {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }
    }

    /**
     * AJAX: Flush every site on the account.
     *
     * ⚠ THE CONFIRMATION IS NOT ENOUGH ON ITS OWN. The browser dialog names the
     * other sites, but a dialog is advisory - this re-reads the registry and
     * puts the affected sites in the RESPONSE, so what actually happened is
     * recorded in what the customer is shown rather than only in what they were
     * asked. `confirmed` is required so this can never be triggered by a stale
     * or replayed request that was aimed at the scoped flush.
     */
    public function ajax_object_cache_flush_account() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        if ( ! isset( $_POST['confirmed'] ) || $_POST['confirmed'] !== '1' ) {
            wp_send_json_error( array( 'message' => 'This action clears every site on the account and was not confirmed.' ) );
        }

        $others = $this->object_cache->registry()->other_sites();
        $result = $this->object_cache->flush();

        if ( ! $result['success'] ) {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }

        $message = 'Object cache cleared for every site on this account.';
        if ( ! empty( $others ) ) {
            $labels = array();
            foreach ( $others as $site ) {
                $labels[] = Hostney_Cache_Registry::label( $site );
            }
            $message .= ' This also cleared: ' . implode( ', ', $labels ) . '.';
        }

        wp_send_json_success( array( 'message' => $message ) );
    }

    /**
     * AJAX: Key counts grouped by site.
     *
     * ⚠ ON DEMAND ONLY, NEVER ON PAGE RENDER. This walks the whole keyspace of
     * an instance that is serving other people's live sites. It is behind a
     * button so that cost is always something a human just asked for.
     */
    public function ajax_object_cache_keyspace() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        // Make sure this site is in the list it is about to render. Otherwise
        // the first thing a new site shows you is a breakdown that does not
        // include the site you are looking at.
        $this->object_cache->registry()->announce();

        $keyspace = $this->object_cache->keyspace();
        if ( $keyspace === null ) {
            $backend = $this->object_cache->active_backend();
            wp_send_json_error(
                array(
                    'message' => $backend
                        ? $backend->get_label() . ' cannot list what is in its cache, so a per-site breakdown is not available on this engine.'
                        : 'No object cache is running for this account.',
                )
            );
        }

        wp_send_json_success( $keyspace );
    }

    /**
     * Form POST: Install the object cache drop-in
     *
     * Uses a regular form submission + redirect instead of AJAX because
     * installing the drop-in changes the WordPress bootstrap environment.
     * A full page reload ensures the new object-cache.php is loaded cleanly.
     */
    public function handle_install_dropin() {
        check_admin_referer( 'hostney_dropin_action', '_hostney_nonce' );


        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        $force  = isset( $_POST['force'] ) && $_POST['force'] === '1';
        $result = $this->object_cache->dropin()->install(
            $force,
            $this->object_cache->active_backend()
        );

        if ( $result['success'] ) {
            // ⚠⚠ THIS USED TO CALL flush(), WHICH EMPTIES THE WHOLE INSTANCE.
            // On a shared per-account Redis that meant installing a drop-in on
            // one site dropped every neighbour's cache - a stall on somebody
            // else's site, caused by an action they never took and are never
            // told about.
            //
            // Scoped now, and NOT widened when the scope cannot be worked out.
            // On a FIRST install the prefix is unknowable here by construction:
            // it is defined by the drop-in, and the drop-in did not exist when
            // this request booted, so $GLOBALS['wp_object_cache'] is still the
            // old one. flush_site() correctly declines, and declining is right -
            // any keys already under our new prefix were written by our own
            // drop-in from the same salt, so they are in our format and safe to
            // read. On a REINSTALL over our own drop-in the prefix is known and
            // this clears exactly this site.
            $this->object_cache->flush_site();
            $this->object_cache->clear_availability_cache();

            // The prefix may be brand new, and maybe_announce() would otherwise
            // sit behind its 12-hour guard - leaving this site invisible in
            // every other site's "who else is affected" warning for half a day,
            // which is precisely when it matters most.
            delete_transient( Hostney_Cache_Registry::REFRESH_TRANSIENT );
        }

        $redirect = add_query_arg(
            array(
                'page'            => 'hostney-cache',
                'hostney-notice'  => $result['success'] ? 'dropin-installed' : 'dropin-error',
                'hostney-message' => rawurlencode( $result['message'] ),
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Form POST: Remove the object cache drop-in
     */
    public function handle_remove_dropin() {
        check_admin_referer( 'hostney_dropin_action', '_hostney_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        // ⚠ ORDER: BOTH OF THESE MUST HAPPEN BEFORE THE FILE GOES, in the sense
        // that both depend on knowing this site's key prefix - and the prefix
        // comes from the drop-in. It is still loaded in THIS request (it was
        // there when the request booted, and unlinking a file does not unload
        // it), but nothing after this request will be able to work it out. Once
        // the drop-in is gone the keys are unattributable forever: they would
        // sit in the account's Redis as "unknown" until eviction, counting
        // against the memory limit of sites that had nothing to do with them.
        $prefix  = $this->object_cache->registry()->local_prefix();
        $cleared = $this->object_cache->flush_site();

        // No return value checked on purpose. A site with no registry entry is
        // the ordinary case on Memcached and behind a foreign drop-in, so a
        // false here is "nothing to do", not a failure worth surfacing.
        if ( $prefix !== '' ) {
            $this->object_cache->registry()->forget( $prefix );
        }

        $result = $this->object_cache->dropin()->remove();

        if ( $result['success'] ) {
            $this->object_cache->clear_availability_cache();

            if ( ! empty( $cleared['deleted'] ) ) {
                $result['message'] .= sprintf( ' %d cached entries for this site were cleared.', (int) $cleared['deleted'] );
            }
        }

        $redirect = add_query_arg(
            array(
                'page'            => 'hostney-cache',
                'hostney-notice'  => $result['success'] ? 'dropin-removed' : 'dropin-error',
                'hostney-message' => rawurlencode( $result['message'] ),
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }
}
