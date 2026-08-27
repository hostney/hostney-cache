/**
 * Hostney Cache Plugin - Admin JS
 *
 * Handles purge buttons on admin page, admin bar, and post editor meta box.
 */
(function ($) {
    'use strict';

    /**
     * A confirm dialog that looks like the rest of Hostney.
     *
     * ⚠⚠ REPLACES window.confirm(), AND THAT IS THE POINT. A browser prompt
     * cannot be styled, cannot show a list, is rendered by the OS in a typeface
     * and position we do not control, and on some browsers is suppressed
     * entirely after a previous dialog - so the "this clears every site on the
     * account" warning could simply not appear, and the flush would proceed.
     * A dialog that can silently not render is not a confirmation.
     *
     * Mirrors components/layout/Modal.jsx in the control panel: backdrop click,
     * Escape, and a header close, all resolving to the same cancel.
     *
     * @param {object}   opts
     * @param {string}   opts.title
     * @param {jQuery}   opts.body          Built by the caller, never an HTML string.
     * @param {string}   opts.confirmText
     * @param {string}   opts.confirmClass  A hostney-btn-* modifier.
     * @param {Function} opts.onConfirm     Receives a done() to close the modal.
     */
    function hostneyConfirm(opts) {
        var $backdrop = $('<div>').addClass('hostney-modal-backdrop');
        var $modal = $('<div>').addClass('hostney-modal').attr({
            role: 'dialog',
            'aria-modal': 'true',
            'aria-label': opts.title
        });

        var $close = $('<button>')
            .addClass('hostney-modal-close')
            .attr({ type: 'button', 'aria-label': 'Close' })
            .html('&times;');

        var $head = $('<div>').addClass('hostney-modal-head')
            .append($('<h2>').text(opts.title))
            .append($close);

        var $body = $('<div>').addClass('hostney-modal-body').append(opts.body);

        var $cancel = $('<button>')
            .addClass('hostney-btn hostney-btn-outline-neutral')
            .attr('type', 'button')
            .text('Cancel');

        var $confirm = $('<button>')
            .addClass('hostney-btn ' + (opts.confirmClass || 'hostney-btn-primary'))
            .attr('type', 'button')
            .text(opts.confirmText || 'Confirm');

        var $foot = $('<div>').addClass('hostney-modal-foot').append($cancel).append($confirm);

        $modal.append($head).append($body).append($foot);
        $backdrop.append($modal);

        // Restore focus to whatever opened the dialog. Without this, closing it
        // drops focus to the top of the document and a keyboard user has to tab
        // back through the whole page to reach the button they just pressed.
        var $opener = $(document.activeElement);

        function close() {
            $backdrop.remove();
            $(document).off('keydown.hostneyModal');
            if ($opener && $opener.length) {
                $opener.trigger('focus');
            }
        }

        $close.on('click', close);
        $cancel.on('click', close);

        // Only a click that both started AND ended on the backdrop closes it.
        // A drag that begins inside the dialog and releases outside is a text
        // selection, not a dismissal, and closing on it loses whatever the
        // person was doing.
        var downOnBackdrop = false;
        $backdrop.on('mousedown', function (e) { downOnBackdrop = (e.target === $backdrop[0]); });
        $backdrop.on('mouseup', function (e) {
            if (downOnBackdrop && e.target === $backdrop[0]) { close(); }
            downOnBackdrop = false;
        });

        $(document).on('keydown.hostneyModal', function (e) {
            if (e.key === 'Escape') { close(); }
        });

        $confirm.on('click', function () {
            $confirm.prop('disabled', true).html('Working...<span class="hostney-spinner"></span>');
            $cancel.prop('disabled', true);
            opts.onConfirm(close);
        });

        $('body').append($backdrop);
        $confirm.trigger('focus');
    }

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

        // ── Object cache flushing ───────────────────────────────────────
        // There is ONE Redis per ACCOUNT, so these two buttons differ by blast
        // radius, not by wording. Keep them visibly different.
        //
        // ⚠ Ids and the AJAX action were renamed from hostney-memcached-* in
        // 1.2.0, and the action hostney_object_cache_flush was re-pointed at the
        // SCOPED flush in 1.2.3. admin/views/cache-page.php and
        // class-hostney-cache-admin.php move with this file; all three are in
        // this plugin.

        // Flush this site's entries only.
        $('#hostney-object-cache-flush-btn').on('click', function () {
            var $btn = $(this);
            var $feedback = $('#hostney-object-cache-feedback');
            // ⚠ Captured, not hardcoded. The old handler restored the literal
            // "Flush object cache", so renaming the button in the view would
            // silently rename it back on the first click.
            var label = $btn.text();

            $btn.prop('disabled', true).html('Clearing...<span class="hostney-spinner"></span>');
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
                    $btn.prop('disabled', false).text(label);
                },
                error: function () {
                    $feedback.attr('class', 'hostney-error').text('Network error. Please try again.').show();
                    $btn.prop('disabled', false).text(label);
                }
            });
        });

        // Flush every site on the account.
        $('#hostney-object-cache-flush-account-btn').on('click', function () {
            var $btn = $(this);
            var $feedback = $('#hostney-object-cache-feedback');
            var label = $btn.text();
            var others = [];

            try {
                others = JSON.parse($btn.attr('data-others') || '[]');
            } catch (e) {
                // A malformed attribute must not degrade into a SILENT
                // account-wide flush. Fall back to the vaguer warning rather
                // than to no warning.
                others = [];
            }

            // ⚠ NAME THE SITES. "Are you sure?" gives somebody nothing to be
            // sure ABOUT, and the whole reason this dialog exists is that the
            // person pressing it may not know anyone else is on the instance.
            // The list is what a browser prompt could never render properly,
            // and is why this is a real modal now.
            var $body = $('<div>');
            if (others.length) {
                $body.append($('<p>').text(
                    'This clears the object cache for every site on this account. Those sites will ' +
                    'run slower until their caches rebuild.'
                ));
                var $inset = $('<div>').addClass('hostney-modal-inset');
                $inset.append($('<div>').css({ fontWeight: 600, marginBottom: '6px' }).text('Also cleared'));
                var $list = $('<ul>').css({ margin: 0, paddingLeft: '18px' });
                others.forEach(function (name) {
                    // .text(), because these are other sites' home URLs, read
                    // from a database this page does not own.
                    $list.append($('<li>').text(name));
                });
                $inset.append($list);
                $body.append($inset);
            } else {
                $body.append($('<p>').text('This clears the object cache for every site on this account.'));
            }

            hostneyConfirm({
                title: 'Flush all sites on this account',
                body: $body,
                confirmText: 'Flush all sites',
                confirmClass: 'hostney-btn-danger',
                onConfirm: function (done) {
                    $btn.prop('disabled', true).html('Clearing...<span class="hostney-spinner"></span>');
                    $feedback.hide();

                    $.ajax({
                        url: hostneyCache.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'hostney_object_cache_flush_account',
                            nonce: hostneyCache.nonce,
                            confirmed: '1'
                        },
                        success: function (response) {
                            if (response.success) {
                                $feedback.attr('class', 'hostney-success').text(response.data.message).show();
                            } else {
                                var msg = response.data && response.data.message ? response.data.message : 'Flush failed.';
                                $feedback.attr('class', 'hostney-error').text(msg).show();
                            }
                            $btn.prop('disabled', false).text(label);
                            done();
                        },
                        error: function () {
                            $feedback.attr('class', 'hostney-error').text('Network error. Please try again.').show();
                            $btn.prop('disabled', false).text(label);
                            done();
                        }
                    });
                }
            });
        });

        // Per-site key breakdown for the account instance.
        $('#hostney-keyspace-scan-btn').on('click', function () {
            var $btn = $(this);
            var $out = $('#hostney-keyspace-result');
            var label = $btn.text();

            $btn.prop('disabled', true).html('Scanning...<span class="hostney-spinner"></span>');
            $out.hide().empty();

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_object_cache_keyspace',
                    nonce: hostneyCache.nonce
                },
                success: function (response) {
                    if (response.success) {
                        keyspaceRender($out, response.data);
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : 'Could not read the keyspace.';
                        $out.attr('class', 'hostney-error').text(msg).show();
                    }
                    $btn.prop('disabled', false).text(label);
                },
                error: function () {
                    $out.attr('class', 'hostney-error').text('Network error. Please try again.').show();
                    $btn.prop('disabled', false).text(label);
                }
            });
        });

        /**
         * Render the keyspace breakdown.
         *
         * ⚠ BUILT WITH .text(), NEVER CONCATENATED HTML. Site labels come from
         * other sites' home_url on the same account, i.e. from a database this
         * page does not own. Doing it properly costs nothing here, and the
         * alternative is stored XSS reachable by any neighbour on the account.
         */
        function keyspaceRender($out, data) {
            var $table = $('<table>').addClass('hostney-keyspace-table');
            var $head = $('<tr>');
            $head.append($('<th>').attr('scope', 'col').text('Site'));
            $head.append($('<th>').attr('scope', 'col').text('Prefix'));
            $head.append($('<th>').attr('scope', 'col').text('Entries'));
            $table.append($('<thead>').append($head));

            var $body = $('<tbody>');
            var sites = data.sites || [];
            var counts = data.counts || {};
            var i;

            for (i = 0; i < sites.length; i++) {
                var site = sites[i];
                var name = site.home || site.abspath || 'Unidentified site';
                var $row = $('<tr>');

                if (site.is_current) {
                    $row.addClass('hostney-keyspace-current');
                    name = name + ' (this site)';
                }

                $row.append($('<td>').text(name));
                $row.append($('<td>').append($('<code>').text(site.prefix)));
                $row.append($('<td>').text(formatCount(counts[site.prefix])));
                $body.append($row);
            }

            // Keys matching no registered site. This is what a removed domain
            // leaves behind, so the row is shown even at zero - an absent row
            // reads as "not measured" rather than "none".
            var $orphan = $('<tr>').addClass('hostney-keyspace-orphan');
            $orphan.append($('<td>').text('Not matched to a site'));
            $orphan.append($('<td>').text('—'));
            $orphan.append($('<td>').text(formatCount(data.unknown)));
            $body.append($orphan);

            $table.append($body);
            $out.attr('class', 'hostney-keyspace-result').empty().append($table);

            if (data.partial) {
                // A capped scan must never read as a complete one.
                $out.append(
                    $('<p>').addClass('hostney-error').text(
                        'Stopped at ' + formatCount(data.scanned) +
                        ' keys. These counts are a partial view, not a total.'
                    )
                );
            }

            if (data.unknown > 0) {
                $out.append(
                    $('<p>').addClass('hostney-muted').text(
                        'Unmatched entries are usually left behind by a site that was removed. ' +
                        'They are cleared by the account-wide flush.'
                    )
                );
            }

            $out.show();
        }

        function formatCount(n) {
            if (typeof n !== 'number' || !isFinite(n)) {
                return '0';
            }
            // toLocaleString, not a thousands-separator regex. The regex form
            // needs escapes that do not survive being pasted between tools, and
            // this is a count in an admin table.
            return Number(n).toLocaleString();
        }

        // ── Tuning settings and database cleanup (1.3.0) ────────────────

        // Collect every control marked as a setting. One form, sent whole.
        //
        // ⚠ SENT WHOLE ON PURPOSE. An unchecked checkbox posts nothing, so a
        // partial payload could never turn a toggle back OFF - the server would
        // see the key missing and keep the old true. The PHP side rebuilds from
        // defaults for the same reason.
        function collectSettings() {
            var out = {};
            $('.hostney-setting').each(function () {
                var $el = $(this);
                var key = $el.data('setting');
                if (!key) {
                    return;
                }
                if ($el.is(':checkbox')) {
                    out[key] = $el.is(':checked') ? '1' : '';
                } else {
                    out[key] = $el.val();
                }
            });
            return out;
        }

        // Bound by CLASS, because there is a Save button on each settings card.
        // Both submit the WHOLE form - see collectSettings.
        $('.hostney-save-settings-btn').on('click', function () {
            var $btn = $(this);
            // Feedback goes next to the button that was pressed, not to a fixed
            // element two cards away where it would scroll out of sight.
            var $feedback = $btn.closest('.hostney-card').find('.hostney-settings-feedback');
            var label = $btn.text();

            $btn.prop('disabled', true).html('Saving...<span class="hostney-spinner"></span>');
            $feedback.hide();

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_cache_save_settings',
                    nonce: hostneyCache.nonce,
                    settings: collectSettings()
                },
                success: function (response) {
                    if (response.success) {
                        $feedback.attr('class', 'hostney-success').text(response.data.message).show();
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : 'Could not save.';
                        $feedback.attr('class', 'hostney-error').text(msg).show();
                    }
                    $btn.prop('disabled', false).text(label);
                },
                error: function () {
                    $feedback.attr('class', 'hostney-error').text('Network error. Please try again.').show();
                    $btn.prop('disabled', false).text(label);
                }
            });
        });

        $('#hostney-cleanup-scan-btn').on('click', function () {
            var $btn = $(this);
            var label = $btn.text();

            $btn.prop('disabled', true).html('Checking...<span class="hostney-spinner"></span>');

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_cache_cleanup_counts',
                    nonce: hostneyCache.nonce
                },
                success: function (response) {
                    var $out = $('#hostney-cleanup-result');
                    if (response.success) {
                        cleanupRender($out, response.data);
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : 'Could not read the database.';
                        $out.attr('class', 'hostney-error').text(msg).show();
                    }
                    $btn.prop('disabled', false).text(label);
                },
                error: function () {
                    $('#hostney-cleanup-result').attr('class', 'hostney-error').text('Network error. Please try again.').show();
                    $btn.prop('disabled', false).text(label);
                }
            });
        });

        /**
         * Render the cleanup table.
         *
         * ⚠ .text() throughout. The labels are ours, but the counts come back
         * from a database this page does not own, and building HTML by
         * concatenation here would be the one place in this file where that
         * habit could bite later.
         */
        function cleanupRender($out, data) {
            var counts = data.counts || {};
            var categories = data.categories || {};
            var total = 0;

            var $table = $('<table>').addClass('hostney-keyspace-table');
            var $head = $('<tr>');
            $head.append($('<th>').attr('scope', 'col').text('What'));
            $head.append($('<th>').attr('scope', 'col').text('Rows'));
            $head.append($('<th>').attr('scope', 'col').text(''));
            $table.append($('<thead>').append($head));

            var $body = $('<tbody>');

            Object.keys(categories).forEach(function (key) {
                var count = typeof counts[key] === 'number' ? counts[key] : 0;
                total += count;

                var $row = $('<tr>');
                $row.append($('<td>').text(categories[key]));
                $row.append($('<td>').text(formatCount(count)));

                var $action = $('<td>');
                if (count > 0) {
                    $action.append(
                        $('<button>')
                            .addClass('hostney-btn hostney-btn-outline-neutral hostney-btn-small hostney-cleanup-run')
                            .attr('type', 'button')
                            .attr('data-category', key)
                            .text('Remove')
                    );
                } else {
                    $action.append($('<span>').addClass('hostney-muted').text('Nothing to remove'));
                }
                $row.append($action);
                $body.append($row);
            });

            $table.append($body);
            $out.attr('class', 'hostney-keyspace-result').empty().append($table);

            if (total === 0) {
                $out.append($('<p>').addClass('hostney-muted').text('Nothing to clean up. This database is already tidy.'));
            } else {
                $out.append(
                    $('<p>').addClass('hostney-muted').text(
                        'Rows are removed in batches, so a large backlog takes a few presses. ' +
                        'That is deliberate — it keeps the site responsive while it works.'
                    )
                );
            }

            $out.show();
        }

        // Delegated, because the buttons are built after this file runs.
        $(document).on('click', '.hostney-cleanup-run', function () {
            var $btn = $(this);
            var category = $btn.data('category');
            var $cell = $btn.closest('tr').children('td').eq(1);

            $btn.prop('disabled', true).text('Removing...');

            $.ajax({
                url: hostneyCache.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hostney_cache_cleanup_run',
                    nonce: hostneyCache.nonce,
                    category: category
                },
                success: function (response) {
                    if (response.success) {
                        $cell.text(formatCount(response.data.remaining));
                        if (response.data.remaining > 0) {
                            // Still work to do, so the button stays. The server
                            // caps each call; pressing again is the intended way
                            // to clear a backlog.
                            $btn.prop('disabled', false).text('Remove more');
                        } else {
                            $btn.replaceWith($('<span>').addClass('hostney-muted').text('Nothing to remove'));
                        }
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : 'Could not remove those rows.';
                        $btn.prop('disabled', false).text('Remove');
                        $('#hostney-cleanup-result').append($('<p>').addClass('hostney-error').text(msg));
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).text('Remove');
                }
            });
        });

        // Gate the drop-in removal behind the same modal as everything else.
        //
        // It stays a real form POST - removing object-cache.php changes the
        // WordPress bootstrap, so the page must reload for what it reports
        // afterwards to be true. The modal only decides whether to submit.
        $('form[data-hostney-confirm="remove-dropin"]').on('submit', function (e) {
            var form = this;
            if ($(form).data('hostneyConfirmed')) {
                return;
            }
            e.preventDefault();

            var $body = $('<div>');
            $body.append($('<p>').text(
                'This site will stop using the account object cache. Pages will still be ' +
                'cached by the server, but database queries and options will be looked up ' +
                'again on every request until the drop-in is reinstalled.'
            ));
            $body.append($('<p>').text('This site’s cached entries are cleared at the same time. Other sites on the account are not affected.'));

            hostneyConfirm({
                title: 'Remove object cache drop-in',
                body: $body,
                confirmText: 'Remove drop-in',
                confirmClass: 'hostney-btn-danger',
                onConfirm: function () {
                    // Flagged before re-submitting, or this handler intercepts
                    // its own submit and the dialog reopens forever.
                    $(form).data('hostneyConfirmed', true);
                    form.submit();
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
 * Swap an admin bar item's label while something is happening to it, then put
 * it back. Shared by the two admin bar actions.
 *
 * ⚠ The node id here is the one passed to add_node(); WordPress prefixes it
 * with 'wp-admin-bar-' in the DOM. Rename a node without renaming it here and
 * the action still fires - it just goes silent, which reads as a broken button.
 *
 * @param {string} nodeId
 * @returns {{set: function(string), restore: function()}|null}
 */
function hostneyAdminBarLabel(nodeId) {
    var item = document.getElementById('wp-admin-bar-' + nodeId);
    if (!item) return null;

    var titleEl = item.querySelector('.ab-item');
    if (!titleEl) return null;

    var original = titleEl.textContent;
    return {
        set: function (text) { titleEl.textContent = text; },
        restore: function () { titleEl.textContent = original; }
    };
}

/**
 * Admin bar "Flush and pre-fetch" handler (called from onclick attribute).
 *
 * Starts the run and gets out of the way. There is no progress bar out here -
 * the label says it started, and "Cache settings" in the same menu is where
 * the progress lives. The parent label also carries a percentage while a run
 * is in flight, so the next page load shows how far along it is.
 */
function hostneyAdminBarWarm(e) {
    e.preventDefault();

    var label = hostneyAdminBarLabel('hostney-cache-warm');
    if (label) label.set('Starting...');

    jQuery.ajax({
        url: hostneyCache.ajaxUrl,
        type: 'POST',
        data: {
            action: 'hostney_cache_warm_start',
            nonce: hostneyCache.nonce
        },
        success: function (response) {
            if (!label) return;
            // A refusal here is almost always "already running", which is
            // information rather than a failure - so it gets its own text
            // instead of a generic error.
            var payload = response.data || {};
            label.set(response.success ? 'Pre-fetch started' : (payload.message || 'Could not start'));
            setTimeout(label.restore, 3000);
        },
        error: function () {
            if (!label) return;
            label.set('Could not start');
            setTimeout(label.restore, 3000);
        }
    });
}

/**
 * Admin bar purge handler (called from onclick attribute)
 */
function hostneyAdminBarPurge(e) {
    e.preventDefault();

    var label = hostneyAdminBarLabel('hostney-cache-purge');
    if (label) label.set('Purging...');

    jQuery.ajax({
        url: hostneyCache.ajaxUrl,
        type: 'POST',
        data: {
            action: 'hostney_cache_admin_bar_purge',
            nonce: hostneyCache.nonce
        },
        success: function (response) {
            if (!label) return;
            label.set(response.success ? 'Cache purged!' : 'Purge failed');
            setTimeout(label.restore, 2000);
        },
        error: function () {
            if (!label) return;
            label.set('Purge failed');
            setTimeout(label.restore, 2000);
        }
    });
}
