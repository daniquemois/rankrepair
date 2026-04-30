(function ($) {
    'use strict';

    // ── Form list selection ───────────────────────────────────────────────────

    $('#rr-ft-form-list').on('click', '.rr-ft-form-item', function () {
        var formId = $(this).data('id');

        $('#rr-ft-form-list .rr-ft-form-item').removeClass('is-active');
        $(this).addClass('is-active');

        var $detail = $('#rr-ft-detail');
        $detail.html('<div class="rr-ft-empty-detail"><span class="rr-ft-spinner"></span></div>');

        $.post(rrFT.ajaxUrl, {
            action:  'rr_ft_get_detail',
            nonce:   rrFT.nonce,
            form_id: formId,
        }, function (res) {
            if (res.success) {
                $detail.html(res.data.html);
            } else {
                $detail.html('<div class="rr-ft-empty-detail">Kon formulier niet laden.</div>');
            }
        }).fail(function () {
            $detail.html('<div class="rr-ft-empty-detail">Fout bij laden.</div>');
        });
    });

    // ── Search filter ─────────────────────────────────────────────────────────

    $('#rr-ft-search').on('input', function () {
        var q = $(this).val().toLowerCase();
        $('#rr-ft-form-list .rr-ft-form-item').each(function () {
            var name = $(this).find('.rr-ft-form-name').text().toLowerCase();
            $(this).toggleClass('is-hidden', q.length > 0 && name.indexOf(q) === -1);
        });
    });

    // ── Run test (single form) ────────────────────────────────────────────────

    $(document).on('click', '.rr-ft-run-test', function () {
        var $btn   = $(this);
        var formId = $btn.data('id');
        if (!formId) return;

        $btn.addClass('is-loading').html('<span class="rr-ft-spinner"></span> Testen…');

        var $area = $('#rr-ft-results-' + formId);
        $area.html('<div style="padding:16px;color:#9ca3af;font-size:12px;"><span class="rr-ft-spinner"></span> Test uitvoeren…</div>');

        $.post(rrFT.ajaxUrl, {
            action:  'rr_ft_run_test',
            nonce:   rrFT.nonce,
            form_id: formId,
        }, function (res) {
            $btn.removeClass('is-loading').html('<span class="dashicons dashicons-controls-play"></span> Test uitvoeren');

            if (res.success) {
                $area.html(res.data.html);

                // Update sidebar dot for this form
                var status = res.data.status;
                var $item  = $('#rr-ft-form-list .rr-ft-form-item[data-id="' + formId + '"]');
                $item.find('.rr-ft-status-dot')
                    .removeClass('rr-dot-status-ok rr-dot-status-warning rr-dot-status-error rr-dot-status-pending')
                    .addClass('rr-dot-status-' + status);

                var labels = { ok: 'OK', warning: 'Waarsch.', error: 'Fout', pending: 'Wacht' };
                $item.find('.rr-ft-status-tag')
                    .removeClass('rr-st-ok rr-st-warning rr-st-error rr-st-pending')
                    .addClass('rr-st-' + status)
                    .text(labels[status] || 'Wacht');
            } else {
                $area.html('<div style="padding:16px;color:#ef4444;font-size:12px;">Fout: ' + (res.data || 'onbekende fout') + '</div>');
            }
        }).fail(function () {
            $btn.removeClass('is-loading').html('<span class="dashicons dashicons-controls-play"></span> Test uitvoeren');
            $area.html('<div style="padding:16px;color:#ef4444;font-size:12px;">Verbindingsfout.</div>');
        });
    });

    // ── Rescan ────────────────────────────────────────────────────────────────

    $('#rr-ft-rescan').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="rr-ft-spinner"></span> Scannen…');

        $.post(rrFT.ajaxUrl, {
            action: 'rr_ft_scan',
            nonce:  rrFT.nonce,
        }, function () {
            window.location.reload();
        }).fail(function () {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Herscansen');
        });
    });

    // ── Test all ──────────────────────────────────────────────────────────────

    function testAll() {
        var ids = [];
        $('#rr-ft-form-list .rr-ft-form-item').each(function () {
            ids.push($(this).data('id'));
        });
        if (!ids.length) return;

        var $btn        = $('#rr-ft-test-all, #rr-ft-test-all-footer');
        var $rescanBtn  = $('#rr-ft-rescan');
        $btn.prop('disabled', true).find('.dashicons').replaceWith('<span class="rr-ft-spinner"></span>');
        $rescanBtn.prop('disabled', true);

        var queue = ids.slice();

        function next() {
            if (!queue.length) {
                $btn.prop('disabled', false);
                $rescanBtn.prop('disabled', false);
                $btn.html($btn.data('original-html'));
                return;
            }
            var id = queue.shift();

            // Activate sidebar item and load its detail
            var $item = $('#rr-ft-form-list .rr-ft-form-item[data-id="' + id + '"]');
            $item.trigger('click');

            $.post(rrFT.ajaxUrl, {
                action:  'rr_ft_run_test',
                nonce:   rrFT.nonce,
                form_id: id,
            }, function (res) {
                if (res.success) {
                    var status = res.data.status;
                    $item.find('.rr-ft-status-dot')
                        .removeClass('rr-dot-status-ok rr-dot-status-warning rr-dot-status-error rr-dot-status-pending')
                        .addClass('rr-dot-status-' + status);

                    var labels = { ok: 'OK', warning: 'Waarsch.', error: 'Fout', pending: 'Wacht' };
                    $item.find('.rr-ft-status-tag')
                        .removeClass('rr-st-ok rr-st-warning rr-st-error rr-st-pending')
                        .addClass('rr-st-' + status)
                        .text(labels[status] || 'Wacht');
                }
                next();
            }).fail(function () {
                next();
            });
        }

        // Store original button html for restoring
        $btn.each(function () {
            $(this).data('original-html', $(this).html());
        });

        next();
    }

    $('#rr-ft-test-all, #rr-ft-test-all-footer').on('click', testAll);

    // ── Save test email ───────────────────────────────────────────────────────

    $('#rr-ft-save-email').on('click', function () {
        var email = $('#rr-ft-email-input').val().trim();
        if (!email) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Opslaan…');

        $.post(rrFT.ajaxUrl, {
            action: 'rr_ft_save_email',
            nonce:  rrFT.nonce,
            email:  email,
        }, function (res) {
            $btn.prop('disabled', false).text('Opslaan');
            if (res.success) {
                var $saved = $('#rr-ft-email-saved');
                $saved.fadeIn(150);
                setTimeout(function () { $saved.fadeOut(400); }, 2000);
                rrFT.testEmail = email;
            } else {
                alert(res.data || 'Kon e-mailadres niet opslaan.');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Opslaan');
        });
    });

    // Allow Enter key in email input
    $('#rr-ft-email-input').on('keydown', function (e) {
        if (e.key === 'Enter') $('#rr-ft-save-email').trigger('click');
    });

    // ── Export rapport ────────────────────────────────────────────────────────

    $('#rr-ft-export').on('click', function () {
        var rows = [['Formulier', 'Plugin', 'URL', 'Status', 'Getest op', 'Responstijd']];

        $('#rr-ft-form-list .rr-ft-form-item').each(function () {
            var name   = $(this).find('.rr-ft-form-name').text();
            var plugin = $(this).data('plugin') || '';
            var url    = $(this).find('.rr-ft-form-url').text() || '';
            var status = $(this).find('.rr-ft-status-tag').text() || 'Wacht';
            rows.push([name, plugin, url, status, '', '']);
        });

        var csv = rows.map(function (row) {
            return row.map(function (cell) {
                return '"' + String(cell).replace(/"/g, '""') + '"';
            }).join(',');
        }).join('\r\n');

        var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href     = url;
        a.download = 'formulieren-rapport.csv';
        a.click();
        URL.revokeObjectURL(url);
    });

})(jQuery);
