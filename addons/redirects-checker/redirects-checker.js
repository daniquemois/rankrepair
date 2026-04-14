(function ($) {
    'use strict';

    // ── Drag & drop + file name display ──────────────────────────────────────

    var $dropzone = $('#rr-dropzone');
    var $fileInput = $('#rr-csv-file');

    if ($dropzone.length) {
        $dropzone.on('click', function (e) {
            if (!$(e.target).is('label, .rr-rc-browse-link')) {
                $fileInput.trigger('click');
            }
        });

        $dropzone.on('dragover dragenter', function (e) {
            e.preventDefault();
            $(this).addClass('is-over');
        }).on('dragleave drop', function (e) {
            e.preventDefault();
            $(this).removeClass('is-over');
            if (e.type === 'drop') {
                var files = e.originalEvent.dataTransfer.files;
                if (files.length) {
                    $fileInput[0].files = files;
                    showFilename(files[0].name);
                }
            }
        });

        $fileInput.on('change', function () {
            if (this.files.length) showFilename(this.files[0].name);
        });

        function showFilename(name) {
            $('#rr-filename-display').text('📎 ' + name);
        }
    }

    // ── Select all checkbox ───────────────────────────────────────────────────

    $('#rr-check-all').on('change', function () {
        $('.rr-result-row:not(.is-hidden) .rr-row-cb').prop('checked', $(this).is(':checked'));
    });

    // ── Filter tabs ───────────────────────────────────────────────────────────

    $('#rr-filter-tabs').on('click', '.rr-rc-filter', function () {
        var filter = $(this).data('filter');

        $('#rr-filter-tabs .rr-rc-filter').removeClass('active');
        $(this).addClass('active');

        $('.rr-result-row').each(function () {
            var status = $(this).data('status');
            if (filter === 'all' || filter === status) {
                $(this).removeClass('is-hidden');
            } else {
                $(this).addClass('is-hidden');
            }
        });

        // Uncheck select-all when filtering
        $('#rr-check-all').prop('checked', false);
    });

    // ── Export rapport ────────────────────────────────────────────────────────

    $('#rr-export-btn').on('click', function () {
        var raw = $(this).data('results');
        if (!raw) return;

        var results;
        try {
            results = typeof raw === 'string' ? JSON.parse(raw) : raw;
        } catch (e) {
            alert('Kon rapport niet exporteren.');
            return;
        }

        var rows = [['Van URL', 'Naar URL', 'Type', 'Status', 'Reden', 'HTTP code']];
        results.forEach(function (r) {
            rows.push([
                r.source        || '',
                r.target        || '',
                r.type          || '301',
                r.status        || '',
                r.reason        || '',
                r.http_code     || ''
            ]);
        });

        var csv = rows.map(function (row) {
            return row.map(function (cell) {
                var str = String(cell).replace(/"/g, '""');
                return '"' + str + '"';
            }).join(',');
        }).join('\r\n');

        var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href     = url;
        a.download = 'redirect-rapport.csv';
        a.click();
        URL.revokeObjectURL(url);
    });

})(jQuery);
