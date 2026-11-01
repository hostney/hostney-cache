<?php
/**
 * Hostney Cache e2e rig (tools/e2e_cli_warm.sh) - TEST ONLY, never in the zip.
 *
 * Plays this server's nginx for every request the plugin sends to 127.0.0.1 and
 * logs each one (wp-content/hny-rig.log, one JSON line) so the script can check
 * scheme, Host, method and whether redirects were followed:
 *   - http from WP-CLI gets a 301 to https, as a Hostney port-80 block answers;
 *   - the purge endpoint answers success;
 *   - a page carries x-cache-status per the hny_rig_cache option: 'on' (every
 *     page), 'off' (none), 'probe_only' (only the plugin's page-cache check).
 * Also stands in for the platform's hostney-platform mu-plugin
 * (HOSTNEY_SITE_FQDN), and ?hny_rig_fire=<update|install|activate> on a web
 * request does what an update or a plugin switch in wp-admin does.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'HOSTNEY_SITE_FQDN' ) ) {
    define( 'HOSTNEY_SITE_FQDN', 'www.e2e.test' );
}

function hny_rig_response( $code, $headers = array(), $body = '' ) {
    return array(
        'headers'  => $headers,
        'body'     => $body,
        'response' => array( 'code' => $code, 'message' => 'rig' ),
        'cookies'  => array(),
        'filename' => null,
    );
}

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    $parts = wp_parse_url( $url );
    if ( ( $parts['host'] ?? '' ) !== '127.0.0.1' ) {
        return $pre;
    }

    $headers = array_change_key_case( (array) ( $args['headers'] ?? array() ), CASE_LOWER );
    $entry   = array(
        'cli'         => defined( 'WP_CLI' ) && WP_CLI,
        'method'      => (string) ( $args['method'] ?? 'GET' ),
        'scheme'      => (string) ( $parts['scheme'] ?? '' ),
        'path'        => (string) ( $parts['path'] ?? '/' ),
        'host'        => (string) ( $headers['host'] ?? '' ),
        'ua'          => (string) ( $headers['user-agent'] ?? '' ),
        'redirection' => $args['redirection'] ?? null,
        'body'        => is_string( $args['body'] ?? null ) ? $args['body'] : '',
    );
    file_put_contents( WP_CONTENT_DIR . '/hny-rig.log', wp_json_encode( $entry, JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND );

    if ( $entry['scheme'] === 'http' && $entry['cli'] ) {
        return hny_rig_response( 301, array( 'location' => 'https://' . $entry['host'] . $entry['path'] ) );
    }
    if ( $entry['path'] === '/.well-known/hostney-cache-purge' ) {
        return hny_rig_response( 200, array(), '{"success":true,"message":"rig purge"}' );
    }

    $mode  = get_option( 'hny_rig_cache', 'on' );
    $probe = strpos( $entry['ua'], 'Hostney-Cache/' ) === 0;
    $out   = array();
    if ( $mode === 'on' || ( $mode === 'probe_only' && $probe ) ) {
        $out['x-cache-status'] = 'MISS';
    }
    return hny_rig_response( 200, $out, 'page' );
}, 10, 3 );

/**
 * What an update / install / plugin switch fires. The upgrader classes are
 * loaded first, as a real update has them: core hooks
 * Language_Pack_Upgrader::async_upgrade on the same action.
 */
function hny_rig_fire( $fire ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    if ( $fire === 'update' || $fire === 'install' ) {
        do_action( 'upgrader_process_complete', null, array( 'action' => $fire, 'type' => 'plugin', 'plugins' => array( 'x/x.php' ) ) );
    } elseif ( $fire === 'activate' ) {
        do_action( 'activated_plugin', 'x/x.php', false );
    }
}

add_action( 'init', function () {
    if ( defined( 'WP_CLI' ) || ! isset( $_GET['hny_rig_fire'] ) ) {
        return;
    }
    $fire = (string) $_GET['hny_rig_fire'];
    hny_rig_fire( $fire );
    echo 'fired ' . esc_html( $fire );
    exit; // the shutdown hooks (the purge) still run
} );
