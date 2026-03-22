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

    /** @var Hostney_Cache_Memcached */
    private $memcached;

    public function __construct( Hostney_Cache_Purger $purger, Hostney_Cache_Memcached $memcached ) {
        $this->purger    = $purger;
        $this->memcached = $memcached;

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );

        // AJAX handlers — nginx cache
        add_action( 'wp_ajax_hostney_cache_purge_all', array( $this, 'ajax_purge_all' ) );
        add_action( 'wp_ajax_hostney_cache_purge_post', array( $this, 'ajax_purge_post' ) );
        add_action( 'wp_ajax_hostney_cache_clear_log', array( $this, 'ajax_clear_log' ) );

        // AJAX handler — memcached flush
        add_action( 'wp_ajax_hostney_memcached_flush', array( $this, 'ajax_memcached_flush' ) );

        // Form POST handlers — memcached drop-in (redirect-based, not AJAX)
        add_action( 'admin_post_hostney_memcached_install_dropin', array( $this, 'handle_install_dropin' ) );
        add_action( 'admin_post_hostney_memcached_remove_dropin', array( $this, 'handle_remove_dropin' ) );
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
     * AJAX: Flush memcached object cache
     */
    public function ajax_memcached_flush() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $result = $this->memcached->flush();

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => $result['message'] ) );
        } else {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }
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

        $force = isset( $_POST['force'] ) && $_POST['force'] === '1';
        $result = $this->memcached->install_dropin( $force );

        if ( $result['success'] ) {
            $this->memcached->flush();
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

        $result = $this->memcached->remove_dropin();

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
