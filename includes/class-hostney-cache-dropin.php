<?php
/**
 * Hostney Cache - object-cache.php drop-in management
 *
 * ⚠ ENGINE-AGNOSTIC ON PURPOSE. There is exactly ONE object-cache.php slot in
 * WordPress, and the file we install into it detects at runtime which engine is
 * actually running. So installing, checking and removing it are not per-engine
 * operations, and in 1.1.0 - where this code lived inside the Memcached class -
 * Memcached nominally owned Redis's drop-in.
 *
 * @package Hostney_Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_Cache_Dropin {

    /**
     * Marker comment used to recognise a drop-in as ours.
     *
     * ⚠⚠ DO NOT CHANGE THIS STRING, EVER. It is matched against files already
     * on disk at every existing installation. Change it and is_dropin_ours()
     * stops recognising drop-ins this plugin wrote, remove_dropin() starts
     * refusing to remove them, and install_dropin() starts reporting a foreign
     * drop-in on sites whose drop-in we installed ourselves.
     */
    const DROPIN_MARKER = 'Hostney Cache Drop-in';

    /**
     * Replaced with HOSTNEY_CACHE_VERSION as the template is written.
     *
     * A placeholder rather than a literal version in the template, so the plugin
     * constant stays the ONLY place a version is bumped. A hardcoded @version in
     * the .tpl is a second list that has to be kept in step by hand, and the
     * failure is silent: the drop-in claims a version it is not.
     */
    const VERSION_PLACEHOLDER = '{{HOSTNEY_CACHE_VERSION}}';

    /**
     * Version of the drop-in currently on disk.
     *
     * ⚠ THIS OPTION IS THE CHEAP GUARD, NOT THE TRUTH. maybe_upgrade() runs on
     * every request, and it must not stat and read wp-content/object-cache.php
     * to decide it has nothing to do. One autoloaded option read answers that in
     * the overwhelmingly common case; the file is only opened when this says the
     * versions differ, which is once per plugin upgrade.
     */
    const VERSION_OPTION = 'hostney_cache_dropin_version';

    /** Back-off after a failed rewrite, so a read-only wp-content is not retried every request. */
    const RETRY_TRANSIENT = 'hostney_cache_dropin_retry';

    /** Absolute path to the drop-in slot. */
    public function get_path() {
        return WP_CONTENT_DIR . '/object-cache.php';
    }

    public function is_installed() {
        return file_exists( $this->get_path() );
    }

    /**
     * Whether the installed drop-in is ours.
     *
     * Only the first 512 bytes are read: the marker is in the header, and the
     * file is ~20 KB that there is no reason to pull into memory on every admin
     * page load.
     */
    public function is_ours() {
        $path = $this->get_path();
        if ( ! file_exists( $path ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file
        $contents = file_get_contents( $path, false, null, 0, 512 );
        return $contents !== false && strpos( $contents, self::DROPIN_MARKER ) !== false;
    }

    /**
     * Version stamped into the installed drop-in, or '' if there is none.
     *
     * ⚠ 2048 BYTES, NOT THE 512 is_ours() USES. is_ours() is called on every
     * admin page load and its marker is on the second line, so a tight window is
     * free there. This stamp sits further down a header that people edit, and
     * coupling it to a byte budget is how it silently stops being found: adding
     * four lines of comment to the template pushed @version past 512 during this
     * very change, which would have shown a permanent, false "update pending"
     * on every site. get_version() runs on the admin page and once per upgrade,
     * never in the hot path, so the extra 1.5 KB costs nothing worth counting.
     *
     * An UNSUBSTITUTED placeholder deliberately fails the numeric match and
     * returns '', which reads as "older than anything" and triggers a rewrite.
     * That is the right direction to fail in - a drop-in whose version cannot be
     * established is exactly the one worth replacing.
     */
    public function get_version() {
        $path = $this->get_path();
        if ( ! file_exists( $path ) ) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file
        $contents = file_get_contents( $path, false, null, 0, 2048 );
        if ( $contents === false ) {
            return '';
        }

        if ( ! preg_match( '/@version\s+([0-9]+\.[0-9]+\.[0-9]+)/', $contents, $matches ) ) {
            return '';
        }

        return $matches[1];
    }

    /** Ours AND written by this version of the plugin. */
    public function is_current() {
        return $this->is_ours() && $this->get_version() === HOSTNEY_CACHE_VERSION;
    }

    /**
     * @return string 'installed' | 'outdated' | 'foreign' | 'not_installed'
     */
    public function get_status() {
        if ( ! $this->is_installed() ) {
            return 'not_installed';
        }
        if ( ! $this->is_ours() ) {
            return 'foreign';
        }
        // Not is_current(): it re-runs is_ours(), which we have already answered,
        // and each of these opens the file.
        return $this->get_version() === HOSTNEY_CACHE_VERSION ? 'installed' : 'outdated';
    }

    /**
     * Rewrite a stale drop-in. Runs on every request; does nothing on almost all
     * of them.
     *
     * ⚠⚠ WHY THIS EXISTS. Updating the PLUGIN does not update the DROP-IN, and
     * before 1.2.1 nothing ever did. object-cache.php is written by exactly one
     * code path - the button on the admin page - and none of the automated
     * routes touch it: the Hostney discovery worker `rm -rf`s the plugin
     * directory (so deactivate(), which removes the drop-in, never runs) and
     * then installs and activates, while activate() only checks the PHP version.
     *
     * The result was a site upgraded from 1.1.0 keeping a MEMCACHED-ONLY
     * drop-in. Harmless while the account stayed on Memcached, and silent data
     * loss of the useful kind the moment it switched to Redis: no socket found,
     * fall back to a per-request array, no error, no notice, and a control panel
     * still reporting Redis as on. Raising HOSTNEY_CACHE_MIN_VERSION in the
     * worker upgrades the plugin and does nothing about this.
     *
     * NEVER TOUCHES A FOREIGN DROP-IN. A site running Object Cache Pro or W3
     * Total Cache owns that slot, and replacing it uninvited is worse than
     * anything this function is fixing.
     *
     * NEVER INSTALLS ONE THAT IS NOT THERE - that is activate()'s job, not a
     * per-request one. Creating a file is a deliberate, one-time event and
     * belongs on an explicit hook; this path runs on every request and exists
     * only to repair. Keeping the two apart is what stops a bug here from
     * writing wp-content on a loop.
     *
     * Two concurrent requests can both decide to rewrite. They write identical
     * bytes from the same template, so the race is harmless and not worth a lock.
     *
     * The rewrite does NOT affect the request that performs it - object-cache.php
     * was loaded during bootstrap, long before plugins_loaded. install()
     * invalidates opcache, so the next request gets the new file.
     */
    public function maybe_upgrade() {
        if ( get_option( self::VERSION_OPTION ) === HOSTNEY_CACHE_VERSION ) {
            return;
        }

        if ( get_transient( self::RETRY_TRANSIENT ) ) {
            return;
        }

        // 'outdated' is exactly "ours, and stale" - the only state to repair.
        // Anything else means there is nothing of ours to fix: record the version
        // so this stops looking until the next plugin upgrade. install() sets it
        // too, so a drop-in added later still lands on the right value.
        if ( $this->get_status() !== 'outdated' ) {
            // ⚠ AUTOLOAD MUST STAY ON (the default). maybe_upgrade() reads this
            // option on EVERY request, so writing it with autoload=false turns
            // the cheap guard this option exists to be into a database query per
            // request on any site without a warm object cache - which includes
            // every site this code is trying to repair.
            update_option( self::VERSION_OPTION, HOSTNEY_CACHE_VERSION );
            return;
        }

        $result = $this->install();

        if ( ! $result['success'] ) {
            // Back off rather than retrying on every request. wp-content being
            // unwritable is a real state on hardened installs and it does not
            // resolve itself within the hour.
            set_transient( self::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );
        }
    }

    /**
     * Install the drop-in.
     *
     * @param bool                        $force   Overwrite a foreign drop-in.
     * @param Hostney_Cache_Backend|null  $backend The engine currently running, for the error message only.
     * @return array{success:bool,message:string}
     */
    public function install( $force = false, $backend = null ) {
        // ⚠ NO PER-ENGINE EXTENSION CHECK HERE, unlike 1.1.0, which refused to
        // install unless ext-memcached was loaded. The drop-in probes for BOTH
        // engines at runtime and falls back to a non-persistent in-memory cache
        // if neither answers, so installing it is never harmful - and refusing
        // on the wrong engine's extension is exactly how a Redis account would
        // have been blocked from installing a drop-in that supports Redis.
        if ( $backend === null ) {
            // Not fatal, just worth saying plainly: the file will install and do
            // nothing useful until an object cache is enabled in the panel.
            $warning = ' No object cache is running yet - enable one in your Hostney control panel.';
        } else {
            $warning = '';
        }

        $dest   = $this->get_path();
        $source = HOSTNEY_CACHE_PLUGIN_DIR . 'includes/object-cache-dropin.tpl';

        if ( ! file_exists( $source ) ) {
            return array(
                'success' => false,
                'message' => 'Drop-in template not found.',
            );
        }

        if ( $this->is_installed() && ! $this->is_ours() && ! $force ) {
            return array(
                'success' => false,
                'message' => 'Another object cache drop-in is already installed. Use the replace option to overwrite it.',
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file
        $contents = file_get_contents( $source );
        if ( $contents === false ) {
            return array(
                'success' => false,
                'message' => 'Could not read drop-in template.',
            );
        }

        // Stamp the version. This is what lets a later plugin update recognise
        // this file as stale - without it, a drop-in from any version is
        // indistinguishable from a current one, which is the bug 1.2.1 fixes.
        $contents = str_replace( self::VERSION_PLACEHOLDER, HOSTNEY_CACHE_VERSION, $contents );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing wp-content drop-in
        $written = file_put_contents( $dest, $contents );
        if ( $written === false ) {
            return array(
                'success' => false,
                'message' => 'Could not write object-cache.php. Check file permissions on wp-content/.',
            );
        }

        // AFTER a confirmed write, never before. Recording the version on a
        // failed write would tell maybe_upgrade() the repair had happened and
        // leave the stale drop-in in place permanently.
        // Autoload left at the default on purpose - see maybe_upgrade().
        update_option( self::VERSION_OPTION, HOSTNEY_CACHE_VERSION );
        delete_transient( self::RETRY_TRANSIENT );

        // ⚠ LOAD-BEARING. object-cache.php is loaded during WordPress bootstrap,
        // so opcache has the OLD file compiled and would keep serving it for the
        // life of the process. Without this, installing appears to do nothing
        // until the pool recycles.
        if ( function_exists( 'opcache_invalidate' ) ) {
            opcache_invalidate( $dest, true );
        }

        return array(
            'success' => true,
            'message' => 'Object cache drop-in installed.' . $warning,
        );
    }

    /**
     * Remove the drop-in, but only if we wrote it.
     *
     * @return array{success:bool,message:string}
     */
    public function remove() {
        if ( ! $this->is_installed() ) {
            return array(
                'success' => false,
                'message' => 'No object cache drop-in is installed.',
            );
        }

        if ( ! $this->is_ours() ) {
            return array(
                'success' => false,
                'message' => 'The installed drop-in was not created by Hostney Cache.',
            );
        }

        $path = $this->get_path();

        // Invalidate BEFORE unlinking: after the file is gone opcache_invalidate
        // has nothing to act on, and the compiled copy would keep serving.
        if ( function_exists( 'opcache_invalidate' ) ) {
            opcache_invalidate( $path, true );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing wp-content drop-in
        if ( ! unlink( $path ) ) {
            return array(
                'success' => false,
                'message' => 'Could not remove object-cache.php. Check file permissions.',
            );
        }

        // The recorded version described a file that no longer exists. Leaving
        // it set is harmless today - maybe_upgrade() checks is_installed()
        // first - but it would be a lie on disk waiting for someone to trust it.
        delete_option( self::VERSION_OPTION );

        return array(
            'success' => true,
            'message' => 'Object cache drop-in removed.',
        );
    }
}
