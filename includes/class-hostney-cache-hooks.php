<?php
/**
 * Hostney Cache - WordPress Hook Registrations
 *
 * Registers hooks for automatic cache purging when content changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Hooks {

    /** @var Hostney_Cache_Purger */
    private $purger;

    /** @var Hostney_Cache_Warmer */
    private $warmer;

    /** @var bool Whether this request already asked for the after-update run. */
    private $warm_asked = false;

    public function __construct( Hostney_Cache_Purger $purger, Hostney_Cache_Warmer $warmer ) {
        $this->purger = $purger;
        $this->warmer = $warmer;

        // ⚠⚠ CHANGES TO THE CODE, NOT ONLY TO CONTENT (1.3.3). A cached page
        // links the CSS and scripts of the plugin and theme versions it was
        // rendered with. An update, a theme switch or a plugin switched on or
        // off can rename or delete those files (Elementor's post CSS, a
        // minifier's bundles), and every cached page then loads without them -
        // "the site looks broken" until the page expires. Until 1.3.3 nothing
        // purged on any of these.
        add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
        add_action( 'switch_theme', array( $this, 'on_site_update' ), 10, 0 );
        add_action( 'activated_plugin', array( $this, 'on_plugin_switch' ), 10, 0 );
        add_action( 'deactivated_plugin', array( $this, 'on_plugin_switch' ), 10, 0 );
        add_action( 'customize_save_after', array( $this, 'on_plugin_switch' ), 10, 0 );

        // Post status transitions (publish, update, trash, untrash)
        add_action( 'transition_post_status', array( $this, 'on_post_status_change' ), 10, 3 );

        // Permanent deletion
        add_action( 'delete_post', array( $this, 'on_delete_post' ), 10, 1 );

        // Taxonomy changes
        add_action( 'edited_term', array( $this, 'on_term_change' ), 10, 3 );
        add_action( 'created_term', array( $this, 'on_term_change' ), 10, 3 );
        add_action( 'delete_term', array( $this, 'on_delete_term' ), 10, 4 );

        // Comment changes
        add_action( 'transition_comment_status', array( $this, 'on_comment_status_change' ), 10, 3 );
        add_action( 'comment_post', array( $this, 'on_comment_post' ), 10, 2 );
    }

    /**
     * WordPress, a plugin, a theme or a translation was UPDATED.
     *
     * Installs are left alone: a newly installed plugin is not running yet, and
     * switching it on fires activated_plugin.
     *
     * @param WP_Upgrader $upgrader
     * @param array       $hook_extra {action, type, ...}
     */
    public function on_upgrade( $upgrader, $hook_extra ) {
        if ( ! is_array( $hook_extra ) || ( $hook_extra['action'] ?? '' ) !== 'update' ) {
            return;
        }
        if ( ! in_array( $hook_extra['type'] ?? '', array( 'core', 'plugin', 'theme', 'translation' ), true ) ) {
            return;
        }
        $this->on_site_update();
    }

    /**
     * An update or a theme switch: purge every page, then pre-fetch them again.
     *
     * ⚠⚠ NO RUN STARTS UNDER WP-CLI. The platform's update worker updates one
     * item per WP-CLI call and asks for ONE run after the last of them (`wp
     * hostney-cache warm`, Hostney_Cache_CLI); a run asked for here would start
     * after the first. The purge still happens: it is what keeps a cached page
     * from linking files the update removed.
     */
    public function on_site_update() {
        $this->purger->queue_full_purge();

        if ( $this->warm_asked || Hostney_Cache_Purger::is_wp_cli() ) {
            return;
        }
        if ( ! Hostney_Cache_Settings::get( 'warm_after_updates' ) ) {
            return;
        }
        $this->warm_asked = true;
        $this->warmer->schedule_after_change();
    }

    /**
     * A plugin switched on or off, or a Customizer save: purge every page.
     *
     * No pre-fetch: these come in bursts while somebody looks for the plugin
     * that broke something, and each would restart the run.
     */
    public function on_plugin_switch() {
        $this->purger->queue_full_purge();
    }

    /**
     * Handle post status transitions
     */
    public function on_post_status_change( $new_status, $old_status, $post ) {
        // Skip revisions and autosaves
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }

        // Skip non-public post types
        $post_type = get_post_type_object( $post->post_type );
        if ( ! $post_type || ! $post_type->public ) {
            return;
        }

        // Purge when publishing, updating a published post, or unpublishing
        if ( $new_status === 'publish' || $old_status === 'publish' ) {
            // Debounce: Gutenberg fires multiple concurrent requests on save
            $transient_key = 'hostney_purge_' . $post->ID;
            if ( get_transient( $transient_key ) ) {
                return;
            }
            set_transient( $transient_key, 1, 5 );

            $this->purger->queue_post_purge( $post->ID );
        }
    }

    /**
     * Handle permanent post deletion
     */
    public function on_delete_post( $post_id ) {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $post_type = get_post_type_object( $post->post_type );
        if ( ! $post_type || ! $post_type->public ) {
            return;
        }

        $this->purger->queue_post_purge( $post_id );
    }

    /**
     * Handle taxonomy term edits and creations
     */
    public function on_term_change( $term_id, $tt_id, $taxonomy ) {
        $this->purger->queue_term_purge( $term_id, $taxonomy );
    }

    /**
     * Handle taxonomy term deletion — full purge as fallback
     */
    public function on_delete_term( $term_id, $tt_id, $taxonomy, $deleted_term ) {
        $this->purger->queue_full_purge();
    }

    /**
     * Handle comment status transitions (approve, spam, trash)
     */
    public function on_comment_status_change( $new_status, $old_status, $comment ) {
        if ( $new_status === $old_status ) {
            return;
        }

        // Purge when a comment becomes approved or was previously approved
        if ( $new_status === 'approved' || $old_status === 'approved' ) {
            $this->purger->queue_post_purge( $comment->comment_post_ID );
        }
    }

    /**
     * Handle new comment submission
     */
    public function on_comment_post( $comment_id, $approved ) {
        if ( $approved !== 1 ) {
            return;
        }

        $comment = get_comment( $comment_id );
        if ( $comment ) {
            $this->purger->queue_post_purge( $comment->comment_post_ID );
        }
    }
}
