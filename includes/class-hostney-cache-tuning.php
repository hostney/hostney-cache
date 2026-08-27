<?php
/**
 * Hostney Cache - front-end tuning
 *
 * The "stop doing unnecessary work" half of the plugin: Heartbeat throttling
 * and a few front-end dequeues.
 *
 * ⚠⚠ WHY THIS IS WORTH HAVING AT ALL, given how small each toggle is: a Hostney
 * site gets FIVE PHP-FPM children. Heartbeat in particular is not a
 * micro-optimisation here - an idle wp-admin tab spends one of those five on an
 * admin-ajax request every 15 to 60 seconds, indefinitely. Three administrators
 * with a tab open is a standing share of the site's entire PHP capacity spent
 * on nothing, and it is capacity taken from visitors.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Tuning {

    /** Heartbeat interval, in seconds, for the 'slow' mode. WordPress clamps this to 15-120. */
    const SLOW_INTERVAL = 120;

    /** @var array */
    private $settings;

    public function __construct( $settings = null ) {
        $this->settings = is_array( $settings ) ? $settings : Hostney_Cache_Settings::all();
    }

    /**
     * Hook everything the current settings ask for.
     *
     * Called on every request. Each branch is a settings lookup against an
     * array already in memory, so the cost when everything is off - which is
     * the default and the common case - is a handful of array reads.
     */
    public function register() {
        $this->register_heartbeat();

        if ( ! empty( $this->settings['disable_emoji'] ) ) {
            $this->disable_emoji();
        }

        if ( ! empty( $this->settings['disable_embeds'] ) ) {
            $this->disable_embeds();
        }

        if ( ! empty( $this->settings['disable_dashicons'] ) ) {
            add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_dashicons' ), 100 );
        }

        if ( ! empty( $this->settings['remove_head_links'] ) ) {
            $this->remove_head_links();
        }
    }

    /* ── Heartbeat ────────────────────────────────────────────────────── */

    private function register_heartbeat() {
        $mode = isset( $this->settings['heartbeat_mode'] ) ? $this->settings['heartbeat_mode'] : 'default';

        if ( $mode === 'slow' ) {
            add_filter( 'heartbeat_settings', array( $this, 'slow_heartbeat' ) );
            return;
        }

        if ( $mode === 'minimal' ) {
            add_action( 'init', array( $this, 'maybe_deregister_heartbeat' ), 1 );
            // Still slow it down wherever it survives, rather than leaving the
            // editor on the 15s default while claiming to be minimal.
            add_filter( 'heartbeat_settings', array( $this, 'slow_heartbeat' ) );
        }
    }

    public function slow_heartbeat( $settings ) {
        $settings['interval'] = self::SLOW_INTERVAL;
        return $settings;
    }

    /**
     * Drop Heartbeat entirely, except where it does real work.
     *
     * ⚠⚠ NEVER ON THE POST EDITOR. Heartbeat is what drives autosave and post
     * locking. Killing it there means two people editing the same post get no
     * "someone else is editing this" warning and silently overwrite each other,
     * and a browser crash loses everything since the last manual save. That is
     * data loss traded for a request every two minutes, which is not a trade
     * anybody would make knowingly - so the toggle cannot offer it.
     */
    public function maybe_deregister_heartbeat() {
        global $pagenow;

        $editor_screens = array( 'post.php', 'post-new.php' );
        if ( is_admin() && isset( $pagenow ) && in_array( $pagenow, $editor_screens, true ) ) {
            return;
        }

        wp_deregister_script( 'heartbeat' );
    }

    /* ── Front-end dequeues ───────────────────────────────────────────── */

    /**
     * ⚠ FRONT END ONLY, all of these. The emoji script in wp-admin is what
     * renders emoji in the editor and in comment moderation; removing it there
     * changes how the admin behaves for a saving that only applies to visitors.
     */
    private function disable_emoji() {
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );

        // The DNS prefetch hint for s.w.org that core adds for emoji. Left
        // behind, it is a preconnect to a host nothing then talks to.
        add_filter( 'emoji_svg_url', '__return_false' );
    }

    private function disable_embeds() {
        // The script that makes THIS site's embeds resize inside OTHER sites.
        add_action( 'wp_footer', array( $this, 'deregister_embed_script' ) );

        // Discovery links in <head>. Removing these stops other sites being
        // able to oEmbed this one - which is the actual trade, and is why this
        // is a separate toggle from the emoji one rather than bundled with it.
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );
    }

    public function deregister_embed_script() {
        wp_deregister_script( 'wp-embed' );
    }

    /**
     * Dashicons on the front end.
     *
     * ⚠ NOT WHEN THE ADMIN BAR IS SHOWING. The toolbar is built out of
     * Dashicons, so dequeuing them for a logged-in administrator replaces every
     * toolbar icon with an empty box - on the front end, where they are most
     * likely to notice and least likely to connect it to a caching plugin.
     */
    public function dequeue_dashicons() {
        if ( is_admin_bar_showing() || is_user_logged_in() ) {
            return;
        }
        wp_deregister_style( 'dashicons' );
    }

    /**
     * Head links that describe editing interfaces almost nothing still uses.
     *
     * ⚠ wp_shortlink_wp_head IS THE ONE TO THINK ABOUT. Removing the <link> does
     * not disable shortlinks - existing ?p=123 URLs keep resolving - it only
     * stops advertising them. Anything that has already saved one is fine.
     */
    private function remove_head_links() {
        remove_action( 'wp_head', 'rsd_link' );
        remove_action( 'wp_head', 'wlwmanifest_link' );
        remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
        remove_action( 'wp_head', 'wp_generator' );
    }
}
