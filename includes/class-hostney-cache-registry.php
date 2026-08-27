<?php
/**
 * Hostney Cache - account keyspace registry
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 *
 * There is ONE Redis (and one Memcached) per Linux account, not per site. Both
 * socket paths are built from the account username, so every domain on an
 * account shares one instance and one database, and the key prefix is the only
 * thing keeping their keyspaces apart.
 *
 * That prefix is substr(md5(...), 0, 12), which is ONE-WAY. A site can compute
 * its own prefix and can never recognise a neighbour's. So "which keys belong
 * to which site" is not a question the keyspace can answer about itself - it
 * has to be recorded. This class is that record.
 *
 * Without it, the only honest flush is "delete everything on the account",
 * which is what the plugin used to do to its neighbours on every drop-in
 * install and every press of the flush button.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Registry {

    /**
     * The account-wide hash mapping key prefix -> site identity.
     *
     * ⚠⚠ DELIBERATELY OUTSIDE EVERY SITE'S NAMESPACE. This is account-scoped
     * metadata, not one site's cache entry. Prefixing it would put it inside
     * whichever site wrote it, so "flush this site" would delete the map that
     * every other site on the account uses to describe itself - and the account
     * would silently lose the ability to tell its keyspaces apart.
     *
     * The `hostney:` namespace is reserved for exactly this reason: a site
     * prefix is 12 hex characters and can never collide with it.
     */
    const HASH_KEY = 'hostney:cache:sites';

    /** How often a site re-announces itself, in seconds. */
    const REFRESH_INTERVAL = 43200; // 12 hours

    /** Guard so the refresh costs one option read on all the other requests. */
    const REFRESH_TRANSIENT = 'hostney_cache_registry_announced';

    /** @var Hostney_Cache_Object_Cache */
    private $object_cache;

    public function __construct( Hostney_Cache_Object_Cache $object_cache ) {
        $this->object_cache = $object_cache;
    }

    /**
     * This site's key prefix, or '' when we do not own the object-cache.php slot.
     *
     * ⚠⚠ READ FROM THE DROP-IN, NEVER RECOMPUTED. The salt is defined in
     * object-cache-dropin.tpl::site_salt() and must have exactly one
     * implementation - see the note on hostney_key_prefix() there for what a
     * second copy silently breaks.
     *
     * An empty string is the correct answer for a foreign drop-in (Object Cache
     * Pro, W3 Total Cache): we do not know its key format, so we must not claim
     * a prefix, register one, or offer to scope a flush by it.
     */
    public function local_prefix() {
        if ( ! isset( $GLOBALS['wp_object_cache'] ) || ! is_object( $GLOBALS['wp_object_cache'] ) ) {
            return '';
        }

        if ( ! method_exists( $GLOBALS['wp_object_cache'], 'hostney_key_prefix' ) ) {
            return '';
        }

        $prefix = $GLOBALS['wp_object_cache']->hostney_key_prefix();
        return is_string( $prefix ) ? $prefix : '';
    }

    /**
     * How this site describes itself in the registry.
     *
     * ⚠ ABSPATH IS THE IDENTIFIER; `home` IS ONLY A LABEL. Hostney defines
     * WP_HOME/WP_SITEURL per request from the Host header, so home_url() answers
     * with whatever hostname the current request arrived on - an alias, a
     * preview domain, the apex or the www form. It is the right thing to SHOW a
     * human and the wrong thing to MATCH on. ABSPATH does not vary by request,
     * and on Hostney it is /home/<user>/<domain>/public_html, so the CLI cleanup
     * keys off it. Anything matching on `home` will eventually delete the wrong
     * site's keys.
     */
    public function local_identity() {
        return array(
            'prefix'  => $this->local_prefix(),
            'abspath' => defined( 'ABSPATH' ) ? ABSPATH : '',
            'home'    => function_exists( 'home_url' ) ? home_url() : '',
            'db'      => defined( 'DB_NAME' ) ? DB_NAME : '',
            'updated' => time(),
        );
    }

    /**
     * Announce this site, at most once every REFRESH_INTERVAL.
     *
     * Runs on `plugins_loaded`, so on the front end too. That is deliberate: a
     * site whose owner never opens wp-admin still owns keys in the shared
     * instance, and an unregistered site is exactly the one a neighbour's
     * "flush everything" button cannot warn about.
     *
     * The transient makes the common path one autoloaded option read. Only the
     * request that finds it expired opens a socket.
     */
    public function maybe_announce() {
        if ( get_transient( self::REFRESH_TRANSIENT ) ) {
            return;
        }

        // Set the guard BEFORE the write, not after. If Redis is down, every
        // request would otherwise retry the connection - turning a stopped
        // service into a 0.5s socket timeout on every page load of the site.
        set_transient( self::REFRESH_TRANSIENT, 1, self::REFRESH_INTERVAL );

        $this->announce();
    }

    /**
     * Record this site in the account registry.
     *
     * @return bool
     */
    public function announce() {
        $prefix = $this->local_prefix();
        if ( $prefix === '' ) {
            return false;
        }

        $backend = $this->object_cache->active_backend();
        if ( ! $backend || ! $backend->supports_keyspace_registry() ) {
            return false;
        }

        $redis = $backend->get_connection();
        if ( ! $redis ) {
            return false;
        }

        try {
            // ⚠ JSON, NOT PHP SERIALIZATION. The hostney-cli reads this hash to
            // clean up after a deleted domain, and it is Go. The plugin's admin
            // connection sets no serializer (unlike the drop-in's), so what goes
            // in is what comes out - keep it that way.
            $redis->hSet( self::HASH_KEY, $prefix, wp_json_encode( $this->local_identity() ) );
        } catch ( Exception $e ) {
            return false;
        }

        return true;
    }

    /**
     * Every site the account knows about, newest announcement first.
     *
     * @return array[] Each entry: prefix, abspath, home, db, updated, is_current
     */
    public function all_sites() {
        $backend = $this->object_cache->active_backend();
        if ( ! $backend || ! $backend->supports_keyspace_registry() ) {
            return array();
        }

        $redis = $backend->get_connection();
        if ( ! $redis ) {
            return array();
        }

        try {
            $raw = $redis->hGetAll( self::HASH_KEY );
        } catch ( Exception $e ) {
            return array();
        }

        if ( ! is_array( $raw ) ) {
            return array();
        }

        $local = $this->local_prefix();
        $sites = array();

        foreach ( $raw as $prefix => $json ) {
            $entry = json_decode( (string) $json, true );
            if ( ! is_array( $entry ) ) {
                // A field we cannot read still describes a real keyspace, and
                // dropping it here would hide those keys from the breakdown and
                // from the "who else is affected" warning. Show what we know.
                $entry = array();
            }

            $sites[] = array(
                'prefix'     => (string) $prefix,
                'abspath'    => isset( $entry['abspath'] ) ? (string) $entry['abspath'] : '',
                'home'       => isset( $entry['home'] ) ? (string) $entry['home'] : '',
                'db'         => isset( $entry['db'] ) ? (string) $entry['db'] : '',
                'updated'    => isset( $entry['updated'] ) ? (int) $entry['updated'] : 0,
                'is_current' => ( $local !== '' && (string) $prefix === $local ),
            );
        }

        usort(
            $sites,
            function ( $a, $b ) {
                return $b['updated'] - $a['updated'];
            }
        );

        return $sites;
    }

    /**
     * The other sites sharing this account's instance.
     *
     * This is what the account-wide flush confirmation names, so somebody about
     * to clear everything sees whose cache they are clearing.
     *
     * @return array[]
     */
    public function other_sites() {
        return array_values(
            array_filter(
                $this->all_sites(),
                function ( $site ) {
                    return ! $site['is_current'];
                }
            )
        );
    }

    /**
     * Drop a registry entry. Does NOT delete the keys it describes.
     *
     * Separated on purpose: forgetting an entry is cheap and safe, deleting a
     * keyspace is neither. The caller decides whether it wanted both.
     *
     * @return bool
     */
    public function forget( $prefix ) {
        if ( ! is_string( $prefix ) || $prefix === '' ) {
            return false;
        }

        $backend = $this->object_cache->active_backend();
        if ( ! $backend || ! $backend->supports_keyspace_registry() ) {
            return false;
        }

        $redis = $backend->get_connection();
        if ( ! $redis ) {
            return false;
        }

        try {
            $redis->hDel( self::HASH_KEY, $prefix );
        } catch ( Exception $e ) {
            return false;
        }

        return true;
    }

    /**
     * A human label for a registry entry.
     *
     * Falls back through home -> abspath -> prefix, because an entry with none
     * of them is still worth listing: it is keys somebody is paying to store.
     */
    public static function label( $site ) {
        if ( ! empty( $site['home'] ) ) {
            return $site['home'];
        }
        if ( ! empty( $site['abspath'] ) ) {
            return $site['abspath'];
        }
        return $site['prefix'] . ' (unidentified)';
    }
}
