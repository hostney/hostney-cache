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
     * @return string 'installed' | 'foreign' | 'not_installed'
     */
    public function get_status() {
        if ( ! $this->is_installed() ) {
            return 'not_installed';
        }
        return $this->is_ours() ? 'installed' : 'foreign';
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

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing wp-content drop-in
        $written = file_put_contents( $dest, $contents );
        if ( $written === false ) {
            return array(
                'success' => false,
                'message' => 'Could not write object-cache.php. Check file permissions on wp-content/.',
            );
        }

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

        return array(
            'success' => true,
            'message' => 'Object cache drop-in removed.',
        );
    }
}
