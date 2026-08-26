/**
 * Hostney Cache Plugin - Admin JS
 *
 * Handles purge buttons on admin page, admin bar, and post editor meta box.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {

        // Purge all cache (admin page button)
        $('#hostney-purge-all-btn').on('click', function () {
            var $btn = $(this);
            var $feedback = $('#hostney-purge-feedback');

            $btn.prop('disabled', true).html('Purging...<span class="hostney-spinner"></span>');
            $feedback.hide();

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_cache_purge_all',
                    nonce: hostneyCache.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $feedback.attr('class', 'hostney-success').text(response.data.message).show();
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : 'Purge failed.';
                        $feedback.attr('class', 'hostney-error').text(msg).show();
                    }
                    $btn.prop('disabled', false).text('Purge all cache');
                },
                error: function () {
                    $feedback.attr('class', 'hostney-error').text('Network error. Please try again.').show();
                    $btn.prop('disabled', false).text('Purge all cache');
                }
            });
        });

        // Clear log (admin page button)
        $('#hostney-clear-log-btn').on('click', function () {
            var $btn = $(this);

            $btn.prop('disabled', true).text('Clearing...');

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_cache_clear_log',
                    nonce: hostneyCache.nonce
                },
                success: function () {
                    window.location.reload();
                },
                error: function () {
                    $btn.prop('disabled', false).text('Clear log');
                }
            });
        });

        // Flush the object cache, whichever engine is running (admin page button).
        // ⚠ Ids and the AJAX action were renamed from hostney-memcached-* in
        // 1.2.0; admin/views/cache-page.php and class-hostney-cache-admin.php
        // moved in the same commit. All three are in this plugin.
        $('#hostney-object-cache-flush-btn').on('click', function () {
            var $btn = $(this);
            var $feedback = $('#hostney-object-cache-feedback');

            $btn.prop('disabled', true).html('Flushing...<span class="hostney-spinner"></span>');
            $feedback.hide();

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_object_cache_flush',
                    nonce: hostneyCache.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $feedback.attr('class', 'hostney-success').text(response.data.message).show();
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : 'Flush failed.';
                        $feedback.attr('class', 'hostney-error').text(msg).show();
                    }
                    $btn.prop('disabled', false).text('Flush object cache');
                },
                error: function () {
                    $feedback.attr('class', 'hostney-error').text('Network error. Please try again.').show();
                    $btn.prop('disabled', false).text('Flush object cache');
                }
            });
        });

        // Purge post cache (meta box button)
        $(document).on('click', '.hostney-purge-post-btn', function () {
            var $btn = $(this);
            var postId = $btn.data('post-id');
            var $feedback = $btn.closest('.inside, .hostney-cache-metabox').find('.hostney-metabox-feedback');

            $btn.prop('disabled', true).text('Purging...');
            $feedback.hide();

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_cache_purge_post',
                    nonce: hostneyCache.nonce,
                    post_id: postId
                },
                success: function (response) {
                    if (response.success) {
                        $feedback.css('color', '#15803d').text(response.data.message).show();
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : 'Purge failed.';
                        $feedback.css('color', '#b91c1c').text(msg).show();
                    }
                    $btn.prop('disabled', false).text('Purge cache for this page');
                },
                error: function () {
                    $feedback.css('color', '#b91c1c').text('Network error.').show();
                    $btn.prop('disabled', false).text('Purge cache for this page');
                }
            });
        });

        // ── Flush and pre-fetch ──────────────────────────────────────
        //
        // The work runs on WP-Cron. This poll shows progress AND, on the
        // server side, nudges cron along - a fully cached site has almost no
        // traffic reaching PHP, so without the nudge the bar would sit still
        // on exactly the sites this feature exists for.
        //
        // Closing the tab only stops the nudging. The run continues on the
        // next request the site serves, and reopening this page picks the
        // progress back up from the stored state.
        var warmPollTimer = null;
        var WARM_POLL_MS = 2000;

        var $warmStart = $('#hostney-warm-start-btn');
        var $warmStop = $('#hostney-warm-stop-btn');
        var $warmProgress = $('#hostney-warm-progress');
        var $warmBar = $('#hostney-warm-bar');
        var $warmLabel = $('#hostney-warm-label');
        var $warmCurrent = $('#hostney-warm-current');
        var $warmFeedback = $('#hostney-warm-feedback');

        function warmRender(state) {
            if (!state) return;

            var running = state.status === 'running';
            var total = parseInt(state.total, 10) || 0;
            var done = parseInt(state.done, 10) || 0;

            $warmStart.prop('disabled', running);
            $warmStart.text(running ? 'Pre-fetching...' : 'Flush and pre-fetch');
            $warmStop.toggle(running);

            if (state.status === 'idle') {
                // Nothing has ever run. Stop polling too, or an untouched
                // settings page left open all day hits admin-ajax every two
                // seconds forever.
                warmStopPolling();
                $warmProgress.hide();
                return;
            }

            $warmProgress.show();

            // Guard the divide: a run that failed before collecting has a
            // total of 0, and 0/0 would render the bar as NaN%.
            var pct = total > 0 ? Math.round((done / total) * 100) : 0;
            $warmBar.css('width', pct + '%');

            if (running) {
                $warmLabel.text('Warming ' + done + ' of ' + total + ' pages (' + pct + '%)');
                $warmCurrent.text(state.current ? 'Now: ' + state.current : '');
            } else {
                $warmLabel.text(state.message || 'Finished.');
                $warmCurrent.text('');
            }

            if (!running) {
                warmStopPolling();
                // 'done' with nothing cached, or an outright failure, is not
                // good news dressed as a green box.
                var bad = state.status === 'failed' || (state.status === 'done' && !state.cache_seen);
                if (state.message) {
                    $warmFeedback
                        .removeClass('hostney-success hostney-error')
                        .addClass(bad ? 'hostney-error' : 'hostney-success')
                        .text(state.message)
                        .show();
                }
            }
        }

        function warmPoll() {
            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_cache_warm_status',
                    nonce: hostneyCache.nonce
                },
                success: function (response) {
                    if (response.success && response.data) {
                        warmRender(response.data.state);
                    }
                }
                // No error branch on purpose: one dropped poll during a
                // multi-minute run is not worth telling anybody about, and
                // the next tick re-reads the authoritative state anyway.
            });
        }

        function warmStartPolling() {
            if (warmPollTimer) return;
            warmPollTimer = setInterval(warmPoll, WARM_POLL_MS);
        }

        function warmStopPolling() {
            if (!warmPollTimer) return;
            clearInterval(warmPollTimer);
            warmPollTimer = null;
        }

        $warmStart.on('click', function () {
            var $btn = $(this);

            $btn.prop('disabled', true).html('Clearing...<span class="hostney-spinner"></span>');
            $warmFeedback.hide();

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_cache_warm_start',
                    nonce: hostneyCache.nonce
                },
                success: function (response) {
                    var payload = response.data || {};
                    if (response.success) {
                        warmRender(payload.state);
                        warmStartPolling();
                    } else {
                        $warmFeedback
                            .removeClass('hostney-success')
                            .addClass('hostney-error')
                            .text(payload.message || 'Could not start the pre-fetch.')
                            .show();
                        warmRender(payload.state);
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).text('Flush and pre-fetch');
                    $warmFeedback
                        .removeClass('hostney-success')
                        .addClass('hostney-error')
                        .text('Request failed. Please try again.')
                        .show();
                }
            });
        });

        $warmStop.on('click', function () {
            $(this).prop('disabled', true).text('Stopping...');

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_cache_warm_stop',
                    nonce: hostneyCache.nonce
                },
                complete: function () {
                    $warmStop.prop('disabled', false).text('Stop');
                    warmPoll();
                }
            });
        });

        // A run started before this page was loaded - or before it was
        // reloaded - has to show up on arrival, or leaving the page looks
        // like it cancelled the job.
        if ($warmStart.length) {
            warmPoll();
            warmStartPolling();
        }
    });

})(jQuery);

/**
 * Admin bar purge handler (called from onclick attribute)
 */
function hostneyAdminBarPurge(e) {
    e.preventDefault();

    var link = document.getElementById('wp-admin-bar-hostney-cache-purge');
    if (!link) return;

    var titleEl = link.querySelector('.ab-item');
    var originalText = titleEl ? titleEl.textContent : 'Purge cache';

    if (titleEl) titleEl.textContent = 'Purging...';

    jQuery.ajax({
        url: hostneyCache.ajaxUrl,
        type: 'POST',
        data: {
            action: 'hostney_cache_admin_bar_purge',
            nonce: hostneyCache.nonce
        },
        success: function (response) {
            if (titleEl) {
                titleEl.textContent = response.success ? 'Cache purged!' : 'Purge failed';
                setTimeout(function () { titleEl.textContent = originalText; }, 2000);
            }
        },
        error: function () {
            if (titleEl) {
                titleEl.textContent = 'Purge failed';
                setTimeout(function () { titleEl.textContent = originalText; }, 2000);
            }
        }
    });
}
