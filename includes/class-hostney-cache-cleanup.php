<?php
/**
 * Hostney Cache - database cleanup
 *
 * ⚠⚠ THIS IS THE MOST DESTRUCTIVE CODE IN THE PLUGIN. Everything else here
 * deletes cache entries, which regenerate. This deletes rows from a customer's
 * database, which do not. Two rules follow from that and neither is negotiable:
 *
 *   1. NOTHING RUNS WITHOUT BEING ASKED. Counting is free and always available;
 *      deleting happens on an explicit press, or on a schedule the customer
 *      turned on themselves.
 *   2. THE COUNT IS SHOWN BEFORE THE DELETE. "Remove 12,904 revisions" is a
 *      decision. "Optimise database" is a gamble with someone else's content.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Cleanup {

    /** Cron hook for the optional scheduled run. */
    const CRON_HOOK = 'hostney_cache_cleanup_run';

    /**
     * Revisions always kept per post, whatever the setting says.
     *
     * ⚠ NOT ZERO, AND NOT CONFIGURABLE DOWN TO ZERO. "Delete all revisions" is
     * the setting people regret: revisions are the only undo a writer has for
     * an edit made a week ago. One is enough to make that recoverable; zero
     * makes every past edit unrecoverable and looks identical in the UI.
     */
    const REVISION_FLOOR = 1;

    /**
     * Rows removed per category per run.
     *
     * ⚠⚠ CAPPED BECAUSE THIS RUNS IN A PHP WORKER AND THERE ARE FIVE. A site
     * with 40,000 revisions would otherwise hold one of the five for minutes,
     * through admin-ajax, while visitors queue behind it. Batching turns "the
     * site hung when I pressed the button" into "press it again", and the
     * remaining count is reported so the customer knows to.
     */
    const BATCH = 400;

    /**
     * The categories, in the order they are shown.
     *
     * Order is deliberate: the two with the largest counts and the least
     * consequence come first, so the obvious press is also the safe one.
     */
    public static function categories() {
        return array(
            'revisions'          => 'Post revisions',
            'auto_drafts'        => 'Auto-drafts',
            'trashed_posts'      => 'Trashed posts',
            'spam_comments'      => 'Spam comments',
            'trashed_comments'   => 'Trashed comments',
            'expired_transients' => 'Expired transients',
            'orphaned_postmeta'  => 'Orphaned post meta',
        );
    }

    /**
     * How many rows each category would remove right now.
     *
     * SELECT only. Safe to call on every page render of the admin screen.
     *
     * ⚠ THE REVISION QUERY USES A WINDOW FUNCTION (ROW_NUMBER OVER), which
     * needs MySQL 8.0+ or MariaDB 10.2+. That is guaranteed on Hostney - the
     * playbooks install mysql-server on RHEL 9 (8.0) and mysql8.4-server on
     * RHEL 10 - and it is the reason keeping the newest N per post can be one
     * query instead of one per post. Anyone porting this plugin off Hostney has
     * to revisit that, and it will present as a database error rather than a
     * wrong count.
     *
     * @return array<string,int>
     */
    public function counts() {
        global $wpdb;

        $keep = (int) Hostney_Cache_Settings::get( 'keep_revisions' );
        $keep = max( self::REVISION_FLOOR, $keep );

        $counts = array();

        // Revisions BEYOND the keep-newest-N window, not all revisions. The
        // naive COUNT(*) here would promise to delete everything and then the
        // cleanup would keep some, which reads as a failure.
        $counts['revisions'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT ROW_NUMBER() OVER (PARTITION BY post_parent ORDER BY post_date DESC) AS rn
                    FROM {$wpdb->posts}
                    WHERE post_type = 'revision'
                 ) ranked WHERE ranked.rn > %d",
                $keep
            )
        );

        $counts['auto_drafts'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
        );

        $counts['trashed_posts'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
        );

        $counts['spam_comments'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
        );

        $counts['trashed_comments'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
        );

        // Only transients whose timeout has actually passed. An unexpired
        // transient is live cache, and deleting it is a stampede, not a tidy-up.
        $counts['expired_transients'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name LIKE %s AND option_value < %d",
                $wpdb->esc_like( '_transient_timeout_' ) . '%',
                time()
            )
        );

        $counts['orphaned_postmeta'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.ID IS NULL"
        );

        return $counts;
    }

    /**
     * Remove up to BATCH rows from one category.
     *
     * ⚠ THE WORDPRESS API IS USED FOR ANYTHING WITH DEPENDENT ROWS, and raw SQL
     * only where there are none. wp_delete_post_revision() and wp_delete_comment()
     * clear the meta, the term relationships and the caches that a bare DELETE
     * would strand - and stranded rows are how a "cleanup" leaves a database
     * dirtier than it found it. The API is slower, which is what BATCH is for.
     *
     * @return array{removed:int,remaining:int,label:string}
     */
    public function clean( $category ) {
        global $wpdb;

        $labels = self::categories();
        $label  = isset( $labels[ $category ] ) ? $labels[ $category ] : $category;
        $removed = 0;

        switch ( $category ) {
            case 'revisions':
                $keep = max( self::REVISION_FLOOR, (int) Hostney_Cache_Settings::get( 'keep_revisions' ) );
                $ids  = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM (
                            SELECT ID, ROW_NUMBER() OVER (PARTITION BY post_parent ORDER BY post_date DESC) AS rn
                            FROM {$wpdb->posts}
                            WHERE post_type = 'revision'
                         ) ranked WHERE ranked.rn > %d LIMIT %d",
                        $keep,
                        self::BATCH
                    )
                );
                foreach ( $ids as $id ) {
                    if ( wp_delete_post_revision( (int) $id ) ) {
                        $removed++;
                    }
                }
                break;

            case 'auto_drafts':
            case 'trashed_posts':
                $status = $category === 'auto_drafts' ? 'auto-draft' : 'trash';
                $ids    = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s LIMIT %d",
                        $status,
                        self::BATCH
                    )
                );
                foreach ( $ids as $id ) {
                    // force_delete, because a trashed post is already in the bin
                    // and "delete" that moves it to the bin it is in does nothing.
                    if ( wp_delete_post( (int) $id, true ) ) {
                        $removed++;
                    }
                }
                break;

            case 'spam_comments':
            case 'trashed_comments':
                $status = $category === 'spam_comments' ? 'spam' : 'trash';
                $ids    = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT %d",
                        $status,
                        self::BATCH
                    )
                );
                foreach ( $ids as $id ) {
                    if ( wp_delete_comment( (int) $id, true ) ) {
                        $removed++;
                    }
                }
                break;

            case 'expired_transients':
                // delete_transient() rather than a DELETE, so the value AND its
                // timeout row go together and any external object cache is told.
                $names = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options}
                         WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
                        $wpdb->esc_like( '_transient_timeout_' ) . '%',
                        time(),
                        self::BATCH
                    )
                );
                foreach ( $names as $name ) {
                    $key = substr( $name, strlen( '_transient_timeout_' ) );
                    if ( $key !== '' && delete_transient( $key ) ) {
                        $removed++;
                    }
                }
                break;

            case 'orphaned_postmeta':
                // The one raw DELETE, and the only one that is safe: by
                // definition these rows point at a post that no longer exists,
                // so nothing depends on them and no hook wants to hear about it.
                //
                // ⚠ SELECT THE IDS FIRST, THEN DELETE BY ID. MySQL does NOT
                // accept LIMIT on a multi-table DELETE, so the obvious
                // `DELETE pm FROM postmeta pm LEFT JOIN posts ... LIMIT n` is a
                // syntax error - and dropping the LIMIT to make it parse would
                // delete every orphaned row in one unbounded statement, which
                // is exactly the site-stalling behaviour BATCH exists to
                // prevent. Two statements is the price of keeping the cap.
                $meta_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT pm.meta_id FROM {$wpdb->postmeta} pm
                         LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE p.ID IS NULL LIMIT %d",
                        self::BATCH
                    )
                );

                if ( ! empty( $meta_ids ) ) {
                    $meta_ids     = array_map( 'intval', $meta_ids );
                    $placeholders = implode( ',', array_fill( 0, count( $meta_ids ), '%d' ) );
                    $removed      = (int) $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ({$placeholders})",
                            $meta_ids
                        )
                    );
                }
                break;

            default:
                return array(
                    'removed'   => 0,
                    'remaining' => 0,
                    'label'     => $label,
                );
        }

        $after = $this->counts();

        return array(
            'removed'   => $removed,
            'remaining' => isset( $after[ $category ] ) ? (int) $after[ $category ] : 0,
            'label'     => $label,
        );
    }

    /* ── Optional schedule ────────────────────────────────────────────── */

    public function register() {
        add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
    }

    /**
     * Line the WP-Cron event up with the stored setting.
     *
     * Called after a settings save, so turning the schedule off actually stops
     * it rather than leaving an event that fires forever.
     */
    public static function sync_schedule() {
        $schedule = Hostney_Cache_Settings::get( 'cleanup_schedule' );
        $existing = wp_next_scheduled( self::CRON_HOOK );

        if ( $schedule === 'off' ) {
            if ( $existing ) {
                wp_unschedule_event( $existing, self::CRON_HOOK );
            }
            return;
        }

        // Reschedule only when the recurrence actually changed, or every save
        // of an unrelated setting would push the next run further away and a
        // weekly cleanup on a site whose settings are edited weekly would never
        // fire at all.
        $current = wp_get_schedule( self::CRON_HOOK );
        if ( $current === $schedule ) {
            return;
        }

        if ( $existing ) {
            wp_unschedule_event( $existing, self::CRON_HOOK );
        }

        wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::CRON_HOOK );
    }

    /**
     * The scheduled pass.
     *
     * ⚠ ONE BATCH PER CATEGORY, NOT A LOOP UNTIL EMPTY. This runs on WP-Cron,
     * which rides a real visitor's request and holds one of the site's five PHP
     * workers for its whole duration. A backlog is cleared over several runs,
     * which is slower and cannot take the site down.
     */
    public function run_scheduled() {
        foreach ( array_keys( self::categories() ) as $category ) {
            $this->clean( $category );
        }
    }
}
