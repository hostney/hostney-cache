<?php
/**
 * Admin page template for Hostney Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hostney_cache_purger = new Hostney_Cache_Purger();
$hostney_cache_domain = $hostney_cache_purger->get_domain();
$hostney_cache_endpoint_reachable = $hostney_cache_purger->check_endpoint();
$hostney_cache_log = get_option( 'hostney_cache_log', array() );
if ( ! is_array( $hostney_cache_log ) ) {
    $hostney_cache_log = array();
}
$hostney_cache_log = array_slice( $hostney_cache_log, 0, 20 );

// Object cache status.
//
// THREE DISTINCT STATES, and they are three different messages to a customer:
//   $hostney_oc_active     an engine is running and answering
//   $hostney_oc_installable an engine's PHP extension is present but the service
//                          is off  -> "switch it on in your control panel"
//   neither                no extension at all -> "not available on this server"
// Collapsing the last two would tell someone to enable a service their plan
// does not include, or tell someone whose plan includes it that it is
// unavailable.
$hostney_oc             = new Hostney_Cache_Object_Cache();
$hostney_oc_active      = $hostney_oc->active_backend();
$hostney_oc_installable = $hostney_oc_active ? $hostney_oc_active : $hostney_oc->installable_backend();
$hostney_oc_stats       = $hostney_oc_active ? $hostney_oc_active->get_stats() : null;
$hostney_oc_dropin      = $hostney_oc->dropin()->get_status();

// ⚠ 'outdated' WAS ADDED IN 1.2.1 AND IS STILL A WORKING DROP-IN. Every check
// that used to ask "=== 'installed'" to mean "we have a drop-in" must ask this
// instead, or a stale one is reported as "not installed" - which is both wrong
// and the opposite of reassuring, since the site IS caching.
$hostney_oc_dropin_ours = in_array( $hostney_oc_dropin, array( 'installed', 'outdated' ), true );
$hostney_oc_label       = $hostney_oc_installable ? $hostney_oc_installable->get_label() : 'Object cache';
$hostney_oc_socket      = $hostney_oc_installable ? $hostney_oc_installable->get_socket_path() : '';

// Check for redirect notices from drop-in install/remove
$hostney_notice_type = isset( $_GET['hostney-notice'] ) ? sanitize_key( $_GET['hostney-notice'] ) : '';
$hostney_notice_msg  = isset( $_GET['hostney-message'] ) ? sanitize_text_field( rawurldecode( $_GET['hostney-message'] ) ) : '';
?>

<div class="wrap">
    <div class="hostney-page-heading">
        <h1><span class="hostney-brand">HOSTNEY</span> <span class="hostney-brand-subtitle">&ndash; Cache</span></h1>
    </div>

    <?php if ( $hostney_notice_type === 'dropin-installed' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $hostney_notice_msg ); ?></p></div>
    <?php elseif ( $hostney_notice_type === 'dropin-removed' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $hostney_notice_msg ); ?></p></div>
    <?php elseif ( $hostney_notice_type === 'dropin-error' ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $hostney_notice_msg ); ?></p></div>
    <?php endif; ?>

    <?php
    // Normally unreachable: the drop-in rewrites itself on the first request
    // after a plugin update. Seeing this means the rewrite FAILED, and the only
    // realistic cause is wp-content not being writable - so the message names
    // that rather than saying "out of date" and leaving the reader to guess.
    // Worth stating the consequence: a stale drop-in keeps working on the engine
    // it knows and goes quiet on the other one.
    ?>
    <?php if ( $hostney_oc_dropin === 'outdated' ) : ?>
        <div class="notice notice-warning">
            <p>
                <strong>The object cache drop-in is out of date.</strong>
                It should have updated itself, so <code>wp-content/</code> is most likely not writable.
                Until it is updated this site keeps using the older drop-in, which may not support the
                object cache engine your account is running.
            </p>
        </div>
    <?php endif; ?>

    <div id="hostney-cache-container">

        <!-- Card 1: Status -->
        <div class="hostney-card hostney-card-accent">
            <span class="hostney-status-badge hostney-status-badge-active">Active</span>
            <h2>Cache management</h2>
            <p>Automatic cache purging is enabled for this site.</p>

            <table class="hostney-checks-table">
                <tr>
                    <td>Detected website</td>
                    <td><strong><?php echo esc_html( $hostney_cache_domain ); ?></strong></td>
                </tr>
                <tr>
                    <td>Page caching</td>
                    <td>
                        <?php if ( $hostney_cache_endpoint_reachable ) : ?>
                            <span class="hostney-check-pass">Enabled</span>
                        <?php else : ?>
                            <span class="hostney-check-warn">Not detected</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Purge endpoint</td>
                    <td>
                        <?php if ( $hostney_cache_endpoint_reachable ) : ?>
                            <span class="hostney-check-pass">Available</span>
                        <?php else : ?>
                            <span class="hostney-check-fail">Not reachable</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td>Auto-purge</td>
                    <td><span class="hostney-check-pass">Active</span></td>
                </tr>
                <tr>
                    <td>Object caching</td>
                    <td>
                        <?php if ( $hostney_oc_active && $hostney_oc_dropin_ours ) : ?>
                            <span class="hostney-check-pass">Active (<?php echo esc_html( $hostney_oc_active->get_label() ); ?>)</span>
                        <?php elseif ( $hostney_oc_active ) : ?>
                            <span class="hostney-check-warn">Available (drop-in not installed)</span>
                        <?php elseif ( $hostney_oc_installable ) : ?>
                            <span class="hostney-check-warn">Service not running</span>
                        <?php else : ?>
                            <span class="hostney-check-neutral">Not available</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Card 2: Object cache (Redis or Memcached, whichever is running) -->
        <div class="hostney-card">
            <?php if ( $hostney_oc_active ) : ?>
                <span class="hostney-status-badge hostney-status-badge-active">Active</span>
                <h2>Object cache</h2>
                <p>
                    Object caching is active via <strong><?php echo esc_html( $hostney_oc_active->get_label() ); ?></strong>.
                    Database queries and options are cached in memory for faster page generation.
                </p>

                <table class="hostney-checks-table">
                    <tr>
                        <td>Engine</td>
                        <td><strong><?php echo esc_html( $hostney_oc_active->get_label() ); ?></strong></td>
                    </tr>
                    <tr>
                        <td>PHP extension</td>
                        <td><span class="hostney-check-pass">Loaded</span></td>
                    </tr>
                    <tr>
                        <td>Service</td>
                        <td><span class="hostney-check-pass">Running</span></td>
                    </tr>
                    <tr>
                        <td>Socket</td>
                        <td><strong><?php echo esc_html( $hostney_oc_active->get_socket_path() ); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Drop-in</td>
                        <td>
                            <?php if ( $hostney_oc_dropin === 'installed' ) : ?>
                                <span class="hostney-check-pass">Installed</span>
                            <?php elseif ( $hostney_oc_dropin === 'outdated' ) : ?>
                                <span class="hostney-check-warn">Installed, update pending</span>
                            <?php elseif ( $hostney_oc_dropin === 'foreign' ) : ?>
                                <span class="hostney-check-warn">Foreign (not managed by Hostney)</span>
                            <?php else : ?>
                                <span class="hostney-check-warn">Not installed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $hostney_oc_stats ) : ?>
                        <tr>
                            <td>Hit ratio</td>
                            <td><strong><?php echo esc_html( $hostney_oc_stats['hit_ratio'] ); ?>%</strong></td>
                        </tr>
                        <tr>
                            <td>Memory</td>
                            <td>
                                <strong>
                                    <?php echo esc_html( $hostney_oc_active->format_bytes( $hostney_oc_stats['memory_used'] ) ); ?>
                                </strong>
                                <?php
                                // A limit of 0 means "unlimited" to both engines, so rendering it
                                // as "/ 0 B" would read as a full cache rather than an unbounded one.
                                if ( $hostney_oc_stats['memory_limit'] > 0 ) {
                                    echo ' / ' . esc_html( $hostney_oc_active->format_bytes( $hostney_oc_stats['memory_limit'] ) );
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Cached items</td>
                            <td><strong><?php echo esc_html( number_format_i18n( $hostney_oc_stats['items'] ) ); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Uptime</td>
                            <td><?php echo esc_html( $hostney_oc_active->format_uptime( $hostney_oc_stats['uptime'] ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>

                <div class="hostney-btn-group">
                    <button id="hostney-object-cache-flush-btn" class="hostney-btn hostney-btn-primary">Flush object cache</button>
                    <?php if ( $hostney_oc_dropin === 'not_installed' ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'hostney_dropin_action', '_hostney_nonce' ); ?>
                            <input type="hidden" name="action" value="hostney_object_cache_install_dropin">
                            <button type="submit" class="hostney-btn hostney-btn-outline-neutral">Install drop-in</button>
                        </form>
                    <?php elseif ( $hostney_oc_dropin === 'foreign' ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'hostney_dropin_action', '_hostney_nonce' ); ?>
                            <input type="hidden" name="action" value="hostney_object_cache_install_dropin">
                            <input type="hidden" name="force" value="1">
                            <button type="submit" class="hostney-btn hostney-btn-outline-neutral">Replace drop-in</button>
                        </form>
                    <?php elseif ( $hostney_oc_dropin === 'outdated' ) : ?>
                        <?php
                        // No force flag: the file is already ours, so install()
                        // overwrites it without one. force exists to overwrite
                        // ANOTHER plugin's drop-in, which this is not.
                        ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'hostney_dropin_action', '_hostney_nonce' ); ?>
                            <input type="hidden" name="action" value="hostney_object_cache_install_dropin">
                            <button type="submit" class="hostney-btn hostney-btn-primary">Update drop-in</button>
                        </form>
                    <?php elseif ( $hostney_oc_dropin === 'installed' ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'hostney_dropin_action', '_hostney_nonce' ); ?>
                            <input type="hidden" name="action" value="hostney_object_cache_remove_dropin">
                            <button type="submit" class="hostney-btn hostney-btn-outline-neutral">Remove drop-in</button>
                        </form>
                    <?php endif; ?>
                </div>

            <?php elseif ( $hostney_oc_installable ) : ?>
                <span class="hostney-status-badge hostney-status-badge-warn">Not connected</span>
                <h2>Object cache</h2>
                <p>
                    No object cache is running for this account. Enable
                    <strong>Redis</strong> or <strong>Memcached</strong> from your
                    <strong>Hostney control panel</strong> to activate object caching.
                    Your account runs one or the other, not both.
                </p>

                <table class="hostney-checks-table">
                    <tr>
                        <td>PHP extension</td>
                        <td><span class="hostney-check-pass"><?php echo esc_html( $hostney_oc_label ); ?> loaded</span></td>
                    </tr>
                    <tr>
                        <td>Service</td>
                        <td><span class="hostney-check-warn">Not running</span></td>
                    </tr>
                    <tr>
                        <td>Socket</td>
                        <td><?php echo esc_html( $hostney_oc_socket ); ?></td>
                    </tr>
                </table>

            <?php else : ?>
                <span class="hostney-status-badge hostney-status-badge-inactive">Not available</span>
                <h2>Object cache</h2>
                <p>Neither the Redis nor the Memcached PHP extension is available on this server.</p>
            <?php endif; ?>

            <div id="hostney-object-cache-feedback" style="display: none;"></div>
        </div>

        <!-- Card 3: Purge cache -->
        <div class="hostney-card">
            <h2>Purge cache</h2>
            <p>Clear all cached pages for this site. Use this if content changes are not reflecting.</p>

            <button id="hostney-purge-all-btn" class="hostney-btn hostney-btn-primary">Purge all cache</button>

            <div id="hostney-purge-feedback" style="display: none;"></div>
        </div>

        <!-- Card 4: Recent activity -->
        <div class="hostney-card">
            <h2>Recent activity</h2>

            <?php if ( empty( $hostney_cache_log ) ) : ?>
                <p>No purge activity recorded yet.</p>
            <?php else : ?>
                <table class="hostney-log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Action</th>
                            <th>URLs</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $hostney_cache_log as $hostney_cache_entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( $hostney_cache_entry['time'] ); ?></td>
                                <td><?php echo esc_html( $hostney_cache_entry['action'] ); ?></td>
                                <td>
                                    <?php
                                    $hostney_cache_count = $hostney_cache_entry['count'] ?? count( $hostney_cache_entry['urls'] ?? array() );
                                    if ( $hostney_cache_count <= 1 && ! empty( $hostney_cache_entry['urls'][0] ) ) {
                                        echo esc_html( $hostney_cache_entry['urls'][0] );
                                    } else {
                                        echo esc_html( $hostney_cache_count . ' item' . ( $hostney_cache_count !== 1 ? 's' : '' ) );
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ( $hostney_cache_entry['success'] ) : ?>
                                        <span class="hostney-check-pass">OK</span>
                                    <?php else : ?>
                                        <span class="hostney-check-fail"><?php echo esc_html( $hostney_cache_entry['message'] ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 16px;">
                    <button id="hostney-clear-log-btn" class="hostney-btn hostney-btn-outline-neutral">Clear log</button>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>
