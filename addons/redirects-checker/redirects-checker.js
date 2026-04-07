(function($) {
    'use strict';

    // Import redirects CSV
    $('#rr-redirects-import-form').on('submit', function(e) {
        e.preventDefault();

        var fileInput = $('#rr-redirects-csv-file')[0];
        if (!fileInput.files.length) {
            alert('Selecteer eerst een bestand.');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'rr_import_redirects');
        formData.append('nonce', rrAdmin.nonce);
        formData.append('csv_file', fileInput.files[0]);

        var $status = $('#rr-redirects-status');
        $status.html('<div class="rr-notice rr-notice-info"><span class="rr-spinner-inline"></span> Importeren...</div>');

        $.ajax({
            url: rrAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $status.html('<div class="rr-notice rr-notice-success"><span class="dashicons dashicons-yes"></span> ' + response.data.message + '</div>');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $status.html('<div class="rr-notice rr-notice-error"><span class="dashicons dashicons-no"></span> ' + response.data.message + '</div>');
                }
            },
            error: function() {
                $status.html('<div class="rr-notice rr-notice-error"><span class="dashicons dashicons-no"></span> Er is een fout opgetreden.</div>');
            }
        });
    });

    // Check all redirects
    $('#rr-check-all-redirects').on('click', function() {
        var $btn = $(this);
        var $status = $('#rr-redirects-status');

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update rr-spin"></span> Controleren...');
        $status.html('<div class="rr-notice rr-notice-info"><span class="rr-spinner-inline"></span> Alle redirects worden gecontroleerd. Dit kan even duren...</div>');

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_check_redirects',
            nonce: rrAdmin.nonce
        }, function(response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Controleer Alle Redirects');
            if (response.success) {
                $status.html('<div class="rr-notice rr-notice-success"><span class="dashicons dashicons-yes"></span> ' + response.data.message + '</div>');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                $status.html('<div class="rr-notice rr-notice-error"><span class="dashicons dashicons-no"></span> ' + response.data.message + '</div>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Controleer Alle Redirects');
            $status.html('<div class="rr-notice rr-notice-error"><span class="dashicons dashicons-no"></span> Timeout of fout. Probeer het opnieuw.</div>');
        });
    });

    // Fix redirect
    $(document).on('click', '.rr-btn-fix-redirect', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        var $row = $btn.closest('tr');

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update rr-spin"></span>');

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_fix_redirect',
            nonce: rrAdmin.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                $row.removeClass('rr-row-error').addClass('rr-row-ok rr-row-saved');
                $row.find('.rr-status-icon').html('<span class="dashicons dashicons-yes"></span>').removeClass('rr-status-error').addClass('rr-status-ok');
                $row.find('.rr-error-message').text('Redirect geactiveerd via RankRepair');
                $btn.replaceWith('<span class="rr-badge rr-badge-success">Gefixt!</span>');
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-tools"></span> Fix');
            }
        });
    });

    // Delete redirect
    $(document).on('click', '.rr-btn-delete-redirect', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        var $row = $btn.closest('tr');

        if (!confirm('Weet je zeker dat je deze redirect wilt verwijderen?')) return;

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_delete_redirect',
            nonce: rrAdmin.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                $row.fadeOut(300, function() { $(this).remove(); });
            } else {
                alert(response.data.message);
            }
        });
    });

    // Add redirect manually
    $('#rr-add-redirect-form').on('submit', function(e) {
        e.preventDefault();

        var source = $('#rr-redirect-source').val();
        var target = $('#rr-redirect-target').val();
        var type = $('#rr-redirect-type').val();

        if (!source || !target) {
            alert('Vul beide URL\'s in.');
            return;
        }

        // Create a temporary CSV and import
        var csvContent = 'source,target,type\n' + source + ',' + target + ',' + type;
        var blob = new Blob([csvContent], { type: 'text/csv' });
        var formData = new FormData();
        formData.append('action', 'rr_import_redirects');
        formData.append('nonce', rrAdmin.nonce);
        formData.append('csv_file', blob, 'redirect.csv');

        $.ajax({
            url: rrAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message);
                }
            }
        });
    });

})(jQuery);
