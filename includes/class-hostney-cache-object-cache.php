<?php
/**
 * Hostney Cache - object cache resolver
 *
 * Answers one question for the whole plugin: which engine is this account
 * running? Everything above this point deals in "the object cache" and never
 * branches on Redis vs Memcached.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Object_Cache {

    /** @var Hostney_Cache_Backend[] */
    private $backends;

    /** @var Hostney_Cache_Dropin */
    private $dropin;

    /** @var Hostney_Cache_Backend|false|null false = resolved to nothing, null = not resolved yet */
    private $active = null;

    /** @var Hostney_Cache_Registry|null Built on demand - see registry(). */
    private $registry = null;

    public function __construct() {
        // ⚠ REDIS FIRST, AND THE ORDER IS THE SAME IN THE DROP-IN
        // (object-cache-dropin.tpl detect_backend). The two must agree, or the
        // admin page reports one engine while the site caches into the other.
        //
        // Accounts are supposed to run exactly one, so the order should never
        // decide anything. It exists for the window during an engine switch,
        // where both sockets can briefly be present, and Redis is the engine we
        // steer people to.
        $this->backends = array(
            new Hostney_Cache_Redis(),
            new Hostney_Cache_Memcached(),
        );

        $this->dropin = new Hostney_Cache_Dropin();
    }

    /** @return Hostney_Cache_Dropin */
    public function dropin() {
        return $this->dropin;
    }

    /** @return Hostney_Cache_Backend[] */
    public function backends() {
        return $this->backends;
    }

    /**
     * The engine that is actually running, or null.
     *
     * Memoised per request. is_available() already caches its answer in a
     * transient, but this also avoids re-running the loop several times on one
     * admin page render.
     *
     * @return Hostney_Cache_Backend|null
     */
    public function active_backend() {
        if ( $this->active !== null ) {
            return $this->active === false ? null : $this->active;
        }

        foreach ( $this->backends as $backend ) {
            if ( $backend->is_available() ) {
                $this->active = $backend;
                return $backend;
            }
        }

        $this->active = false;
        return null;
    }

    /**
     * An engine whose PHP extension is loaded but whose service is not running.
     *
     * This is what tells "your plan has no object cache" apart from "the
     * service is switched off", which are very different messages to show a
     * customer: one is a billing question and one is a toggle in the panel.
     *
     * @return Hostney_Cache_Backend|null
     */
    public function installable_backend() {
        foreach ( $this->backends as $backend ) {
            if ( $backend->is_extension_loaded() ) {
                return $backend;
            }
        }
        return null;
    }

    /**
     * Empty the WHOLE instance - every site on the account.
     *
     * ⚠⚠ NOT THE DEFAULT ANY MORE. Kept because "clear everything" is a real
     * thing to want, but callers must have named the affected sites first;
     * other_sites() on the registry is what the confirmation dialog reads.
     * Anything that just wants its own cache gone wants flush_site().
     */
    public function flush() {
        $backend = $this->active_backend();
        if ( ! $backend ) {
            return array(
                'success' => false,
                'message' => 'No object cache is running for this account.',
            );
        }
        return $backend->flush();
    }

    /**
     * Empty THIS site's keys and nothing else.
     *
     * ⚠⚠ NEVER FALLS BACK TO flush(). Quietly widening a scoped flush into an
     * account-wide one is the precise bug this whole seam exists to remove: the
     * customer asked to clear one site, and would be told it worked while their
     * neighbour's cache was dropped. When it cannot be done, say so.
     *
     * @return array{success:bool,message:string,deleted:int}
     */
    public function flush_site() {
        $backend = $this->active_backend();
        if ( ! $backend ) {
            return array(
                'success' => false,
                'deleted' => 0,
                'message' => 'No object cache is running for this account.',
            );
        }

        if ( ! $backend->supports_scoped_flush() ) {
            return $backend->flush_prefix( '' );
        }

        $prefix = $this->registry()->local_prefix();
        if ( $prefix === '' ) {
            return array(
                'success' => false,
                'deleted' => 0,
                'message' => 'This site is not using the Hostney object cache drop-in, so its cache entries cannot be identified separately from the rest of the account.',
            );
        }

        return $backend->flush_prefix( $prefix );
    }

    /**
     * Key counts grouped by site, or null when the engine cannot enumerate.
     */
    public function keyspace() {
        $backend = $this->active_backend();
        if ( ! $backend ) {
            return null;
        }

        $sites    = $this->registry()->all_sites();
        $prefixes = array();
        foreach ( $sites as $site ) {
            $prefixes[] = $site['prefix'];
        }

        $scan = $backend->scan_keyspace( $prefixes );
        if ( $scan === null ) {
            return null;
        }

        $scan['sites'] = $sites;
        return $scan;
    }

    /**
     * The account keyspace registry.
     *
     * Lazy: it takes this object, so it cannot be built in the constructor, and
     * most requests never need it.
     *
     * @return Hostney_Cache_Registry
     */
    public function registry() {
        if ( $this->registry === null ) {
            $this->registry = new Hostney_Cache_Registry( $this );
        }
        return $this->registry;
    }

    /**
     * Forget every engine's cached availability answer.
     *
     * ⚠ ALL of them, not just the active one. After an engine switch the
     * previously-active backend's transient still says "yes" for up to five
     * minutes, and clearing only the current one would leave the admin page
     * claiming both are running.
     */
    public function clear_availability_cache() {
        foreach ( $this->backends as $backend ) {
            $backend->clear_availability_cache();
        }
        $this->active = null;
    }
}
