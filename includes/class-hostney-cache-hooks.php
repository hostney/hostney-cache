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

    public function __construct( Hostney_Cache_Purger $purger ) {
        $this->purger = $purger;

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
