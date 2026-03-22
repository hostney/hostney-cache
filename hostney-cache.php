<?php
/**
 * Plugin Name: Hostney Cache
 * Plugin URI: https://www.hostney.com
 * Description: Automatic nginx cache and memcached object cache management for Hostney hosting.
 * Version: 1.1.0
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

define( 'HOSTNEY_CACHE_VERSION', '1.1.0' );
define( 'HOSTNEY_CACHE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOSTNEY_CACHE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load includes
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-purger.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-hooks.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-admin.php';
require_once HOSTNEY_CACHE_PLUGIN_DIR . 'includes/class-hostney-cache-memcached.php';

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
        $purger    = new Hostney_Cache_Purger();
        $memcached = new Hostney_Cache_Memcached();

        new Hostney_Cache_Hooks( $purger );

        if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            new Hostney_Cache_Admin( $purger, $memcached );
        }

        // Admin bar is available on both admin and frontend
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_button' ), 100 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
        add_action( 'wp_ajax_hostney_cache_admin_bar_purge', array( $this, 'ajax_admin_bar_purge' ) );

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 'Hostney Cache requires PHP 7.4 or later.' );
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        delete_option( 'hostney_cache_log' );

        // Remove our object cache drop-in (only if it's ours)
        $memcached = new Hostney_Cache_Memcached();
        $memcached->remove_dropin();
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
