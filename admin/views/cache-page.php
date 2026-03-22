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

// Memcached status
$hostney_mc = new Hostney_Cache_Memcached();
$hostney_mc_extension    = $hostney_mc->is_extension_loaded();
$hostney_mc_available    = $hostney_mc_extension ? $hostney_mc->is_available() : false;
$hostney_mc_stats        = $hostney_mc_available ? $hostney_mc->get_stats() : null;
$hostney_mc_dropin       = $hostney_mc->get_dropin_status();
$hostney_mc_socket       = $hostney_mc->get_socket_path();

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
                        <?php if ( $hostney_mc_available && $hostney_mc_dropin === 'installed' ) : ?>
                            <span class="hostney-check-pass">Active</span>
                        <?php elseif ( $hostney_mc_available ) : ?>
                            <span class="hostney-check-warn">Available (drop-in not installed)</span>
                        <?php elseif ( $hostney_mc_extension ) : ?>
                            <span class="hostney-check-warn">Service not running</span>
                        <?php else : ?>
                            <span class="hostney-check-neutral">Not available</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Card 2: Object cache (Memcached) -->
        <div class="hostney-card">
            <?php if ( $hostney_mc_available ) : ?>
                <span class="hostney-status-badge hostney-status-badge-active">Active</span>
                <h2>Object cache</h2>
                <p>Object caching is active. Database queries and options are cached in memory for faster page generation.</p>

                <table class="hostney-checks-table">
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
                        <td><strong><?php echo esc_html( $hostney_mc_socket ); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Drop-in</td>
                        <td>
                            <?php if ( $hostney_mc_dropin === 'installed' ) : ?>
                                <span class="hostney-check-pass">Installed</span>
                            <?php elseif ( $hostney_mc_dropin === 'foreign' ) : ?>
                                <span class="hostney-check-warn">Foreign (not managed by Hostney)</span>
                            <?php else : ?>
                                <span class="hostney-check-warn">Not installed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $hostney_mc_stats ) : ?>
                        <tr>
                            <td>Hit ratio</td>
                            <td><strong><?php echo esc_html( $hostney_mc_stats['hit_ratio'] ); ?>%</strong></td>
                        </tr>
                        <tr>
                            <td>Memory</td>
                            <td>
                                <strong>
                                    <?php echo esc_html( $hostney_mc->format_bytes( $hostney_mc_stats['memory_used'] ) ); ?>
                                </strong>
                                / <?php echo esc_html( $hostney_mc->format_bytes( $hostney_mc_stats['memory_limit'] ) ); ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Cached items</td>
                            <td><strong><?php echo esc_html( number_format_i18n( $hostney_mc_stats['items'] ) ); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Uptime</td>
                            <td><?php echo esc_html( $hostney_mc->format_uptime( $hostney_mc_stats['uptime'] ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>

                <div class="hostney-btn-group">
                    <button id="hostney-memcached-flush-btn" class="hostney-btn hostney-btn-primary">Flush object cache</button>
                    <?php if ( $hostney_mc_dropin === 'not_installed' ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'hostney_dropin_action', '_hostney_nonce' ); ?>
                            <input type="hidden" name="action" value="hostney_memcached_install_dropin">
                            <button type="submit" class="hostney-btn hostney-btn-outline-neutral">Install drop-in</button>
                        </form>
                    <?php elseif ( $hostney_mc_dropin === 'foreign' ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'hostney_dropin_action', '_hostney_nonce' ); ?>
                            <input type="hidden" name="action" value="hostney_memcached_install_dropin">
                            <input type="hidden" name="force" value="1">
                            <button type="submit" class="hostney-btn hostney-btn-outline-neutral">Replace drop-in</button>
                        </form>
                    <?php elseif ( $hostney_mc_dropin === 'installed' ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'hostney_dropin_action', '_hostney_nonce' ); ?>
                            <input type="hidden" name="action" value="hostney_memcached_remove_dropin">
                            <button type="submit" class="hostney-btn hostney-btn-outline-neutral">Remove drop-in</button>
                        </form>
                    <?php endif; ?>
                </div>

            <?php elseif ( $hostney_mc_extension ) : ?>
                <span class="hostney-status-badge hostney-status-badge-warn">Not connected</span>
                <h2>Object cache</h2>
                <p>Memcached service is not running. Enable it from your <strong>Hostney control panel</strong> to activate object caching.</p>

                <table class="hostney-checks-table">
                    <tr>
                        <td>PHP extension</td>
                        <td><span class="hostney-check-pass">Loaded</span></td>
                    </tr>
                    <tr>
                        <td>Service</td>
                        <td><span class="hostney-check-warn">Not running</span></td>
                    </tr>
                    <tr>
                        <td>Socket</td>
                        <td><?php echo esc_html( $hostney_mc_socket ); ?></td>
                    </tr>
                </table>

            <?php else : ?>
                <span class="hostney-status-badge hostney-status-badge-inactive">Not available</span>
                <h2>Object cache</h2>
                <p>The PHP Memcached extension is not available on this server.</p>
            <?php endif; ?>

            <div id="hostney-memcached-feedback" style="display: none;"></div>
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
