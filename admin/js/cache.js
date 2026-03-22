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

        // Flush memcached object cache (admin page button)
        $('#hostney-memcached-flush-btn').on('click', function () {
            var $btn = $(this);
            var $feedback = $('#hostney-memcached-feedback');

            $btn.prop('disabled', true).html('Flushing...<span class="hostney-spinner"></span>');
            $feedback.hide();

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_memcached_flush',
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
