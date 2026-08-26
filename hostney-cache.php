<?php
/**
 * Plugin Name: Hostney Cache
 * Plugin URI: https://www.hostney.com
 * Description: Automatic nginx page cache and Redis or Memcached object cache management for Hostney hosting.
 * Version: 1.2.2
 * Author: Hostney
 * Author URI: https://www.hostney.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hostney-cache
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HOSTNEY_CACHE_VERSION', '1.2.2' );
define( 'HOSTNEY_CACHE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOSTNEY_CACHE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load includes.
// ⚠ ORDER MATTERS: the backend base class must be defined before the two
// engines that extend it, and both engines before the resolver that instantiates
// them. There is no autoloader here.
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-purger.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-warmer.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-hooks.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-admin.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-backend.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-memcached.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-redis.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-dropin.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-object-cache.php';

/**
 * Main plugin class
 */
class Hostney_Cache {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $purger       = new Hostney_Cache_Purger();
        $object_cache = new Hostney_Cache_Object_Cache();
        $warmer       = new Hostney_Cache_Warmer( $purger );

        new Hostney_Cache_Hooks( $purger );

        // ⚠ OUTSIDE the is_admin() branch below, deliberately. The warm-up runs
        // on WP-Cron, and cron fires on the FRONT END — registering its hook
        // only for admin requests would mean the one request type that never
        // runs it is the only one that knows about it.
        $warmer->register();

        if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            new Hostney_Cache_Admin( $purger, $object_cache, $warmer );
        }

        // Admin bar is available on both admin and frontend
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_button' ), 100 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
        add_action( 'wp_ajax_hostney_cache_admin_bar_purge', array( $this, 'ajax_admin_bar_purge' ) );

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // ⚠ NOT register_activation_hook. Updating this plugin does not run
        // activation: the Hostney discovery worker `rm -rf`s the plugin
        // directory and then installs and activates, so deactivate() never runs
        // either, and activate() only checks the PHP version. Before 1.2.1 that
        // meant a plugin update NEVER replaced wp-content/object-cache.php - a
        // site upgraded from 1.1.0 kept a Memcached-only drop-in and lost its
        // object cache silently the moment the account switched to Redis.
        //
        // Hooked on every request, not admin_init, because a site whose owner
        // never opens wp-admin is exactly the one that would stay broken.
        // maybe_upgrade() is one autoloaded option read when there is nothing
        // to do, which is every request but the first after an upgrade.
        add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_dropin' ) );
    }

    /**
     * Repair a drop-in left behind by an older version. See
     * Hostney_Cache_Dropin::maybe_upgrade() for what it will and will not touch.
     */
    public function maybe_upgrade_dropin() {
        $dropin = new Hostney_Cache_Dropin();
        $dropin->maybe_upgrade();
    }

    /**
     * Plugin activation
     */
    public function activate() {
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 'Hostney Cache requires PHP 7.4 or later.' );
        }

        // Install the drop-in if the slot is free.
        //
        // ⚠⚠ THIS IS THE OTHER HALF OF THE 1.2.1 FIX, and it was the more
        // embarrassing half. deactivate() has always REMOVED the drop-in, while
        // activate() never installed one - so the plugin could take the file
        // away but never put it back, and the only thing that ever created it
        // was a button in wp-admin. A customer who enabled Redis in the control
        // panel got a plugin that was installed, activated, reporting healthy,
        // and not actually caching anything, with no way to know that from the
        // panel. The two hooks are symmetric now, which is what they always
        // should have been.
        //
        // Installing before an engine is running is FINE and is why there is no
        // check for one here: the drop-in probes for a socket per request and
        // falls back to a non-persistent array when it finds none. So a site can
        // be activated today and have Redis switched on next week with nothing
        // to do on the site side - which is the whole promise of the feature.
        //
        // NEVER OVER A FOREIGN DROP-IN. install() refuses without $force, and
        // nothing here passes it. A site running Object Cache Pro keeps it.
        $dropin = new Hostney_Cache_Dropin();
        if ( $dropin->get_status() === 'not_installed' ) {
            $dropin->install();
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        delete_option( 'hostney_cache_log' );

        // A queued warm-up outlives the plugin otherwise: the cron event stays
        // in the schedule, fires with no handler registered, and the admin page
        // comes back next activation still claiming a run is in progress.
        wp_clear_scheduled_hook( Hostney_Cache_Warmer::CRON_HOOK );
        delete_option( Hostney_Cache_Warmer::STATE_OPTION );
        delete_option( Hostney_Cache_Warmer::QUEUE_OPTION );

        // Remove our object cache drop-in, and only ours. Leaving it behind
        // would be worse than removing it: object-cache.php keeps loading after
        // the plugin is gone, so a deactivated plugin would still be caching.
        $dropin = new Hostney_Cache_Dropin();
        $dropin->remove();
    }

    /**
     * Enqueue admin bar script on the frontend
     */
    public function enqueue_frontend_scripts() {
        if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
            return;
        }

        wp_enqueue_script(
            'hostney-cache-adminbar-js',
            HOSTNEY_CACHE_PLUGIN_URL . 'admin/js/cache.js',
            array( 'jquery' ),
            HOSTNEY_CACHE_VERSION,
            true
        );

        wp_localize_script( 'hostney-cache-adminbar-js', 'hostneyCache', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'hostney_cache_nonce' ),
        ) );
    }

    /**
     * Add "Purge cache" button to admin bar
     */
    public function add_admin_bar_button( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $wp_admin_bar->add_node( array(
            'id'    => 'hostney-cache-purge',
            'title' => 'Hostney: Purge cache',
            'href'  => '#',
            'meta'  => array(
                'onclick' => 'hostneyAdminBarPurge(event);return false;',
            ),
        ) );
    }

    /**
     * AJAX handler for admin bar purge button
     */
    public function ajax_admin_bar_purge() {
        check_ajax_referer( 'hostney_cache_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $purger = new Hostney_Cache_Purger();
        $result = $purger->purge_all();

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => 'Cache purged successfully.' ) );
        } else {
            wp_send_json_error( array( 'message' => $result['message'] ?? 'Failed to purge cache.' ) );
        }
    }
}

// Initialize
Hostney_Cache::get_instance();
