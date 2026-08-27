<?php
/**
 * Hostney Cache - stored settings
 *
 * One option, read on every front-end request by Hostney_Cache_Tuning. Kept
 * deliberately small and flat for that reason.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Settings {

    /**
     * ⚠ AUTOLOAD MUST STAY ON (the default). Hostney_Cache_Tuning reads this on
     * every front-end request, so storing it with autoload=false turns one
     * cheap array lookup into a database query per page view on any site whose
     * object cache is cold - which is every site immediately after a purge.
     */
    const OPTION = 'hostney_cache_settings';

    /**
     * ⚠⚠ EVERY TOGGLE DEFAULTS TO OFF, AND THAT IS NOT TIMIDITY.
     *
     * This plugin is installed and updated by the platform, not by the site
     * owner - the discovery worker reinstalls it whenever the version floor
     * moves. A default that changed a live site's front end would mean a
     * Hostney release silently altering thousands of sites nobody asked, and
     * the breakage would surface as "my site changed and I did nothing", which
     * is the hardest kind of report to act on.
     *
     * Everything here is opt-in, per site, forever.
     */
    public static function defaults() {
        return array(
            // 'default' | 'slow' | 'minimal'
            'heartbeat_mode'      => 'default',

            'disable_emoji'       => false,
            'disable_embeds'      => false,
            'disable_dashicons'   => false,
            'remove_head_links'   => false,

            // 'off' | 'weekly' | 'monthly'
            'cleanup_schedule'    => 'off',

            // Revisions kept per post when cleaning. Never zero - see
            // Hostney_Cache_Cleanup::REVISION_FLOOR.
            'keep_revisions'      => 5,
        );
    }

    /** @return array Stored settings merged over the defaults. */
    public static function all() {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        // Defaults FIRST so a key added in a later version is present on a site
        // whose stored option predates it. Without this every new toggle would
        // read as null on every existing install until somebody pressed Save.
        return array_merge( self::defaults(), $stored );
    }

    /** @return mixed */
    public static function get( $key ) {
        $all = self::all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : null;
    }

    /**
     * Validate and store.
     *
     * ⚠ WHITELISTED AGAINST defaults(), NEVER STORED AS SUBMITTED. The input is
     * an admin-supplied array; merging it wholesale would let anything with the
     * manage_options capability write arbitrary keys into an autoloaded option
     * that is read on every front-end request.
     *
     * @return array The settings as actually stored.
     */
    public static function save( $input ) {
        if ( ! is_array( $input ) ) {
            $input = array();
        }

        $clean = self::defaults();

        if ( isset( $input['heartbeat_mode'] ) && in_array( $input['heartbeat_mode'], array( 'default', 'slow', 'minimal' ), true ) ) {
            $clean['heartbeat_mode'] = $input['heartbeat_mode'];
        }

        foreach ( array( 'disable_emoji', 'disable_embeds', 'disable_dashicons', 'remove_head_links' ) as $flag ) {
            $clean[ $flag ] = ! empty( $input[ $flag ] );
        }

        if ( isset( $input['cleanup_schedule'] ) && in_array( $input['cleanup_schedule'], array( 'off', 'weekly', 'monthly' ), true ) ) {
            $clean['cleanup_schedule'] = $input['cleanup_schedule'];
        }

        if ( isset( $input['keep_revisions'] ) ) {
            $keep = (int) $input['keep_revisions'];
            // Clamped, not rejected. A nonsensical value should land on
            // something safe rather than refuse the whole save and lose the
            // other changes the customer just made.
            $clean['keep_revisions'] = max( Hostney_Cache_Cleanup::REVISION_FLOOR, min( 50, $keep ) );
        }

        update_option( self::OPTION, $clean );

        return $clean;
    }
}
