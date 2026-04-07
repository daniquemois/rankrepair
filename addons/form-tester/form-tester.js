(function($) {
    'use strict';

    // Quick scan links
    $(document).on('click', '.rr-quick-scan-link', function(e) {
        e.preventDefault();
        var url = $(this).data('url');
        $('#rr-form-scan-url').val(url);
        $('#rr-scan-forms-btn').click();
    });

    // Scan forms
    $('#rr-scan-forms-btn').on('click', function() {
        var $btn = $(this);
        var url = $('#rr-form-scan-url').val();

        if (!url) {
            alert('Voer een URL in.');
            return;
        }

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update rr-spin"></span> Scannen...');
        var $status = $('#rr-form-scan-status');
        $status.html('<div class="rr-notice rr-notice-info"><span class="rr-spinner-inline"></span> Pagina wordt gescand op formulieren...</div>');

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_scan_forms',
            nonce: rrAdmin.nonce,
            url: url
        }, function(response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Formulieren');

            if (response.success) {
                $status.html('<div class="rr-notice rr-notice-success"><span class="dashicons dashicons-yes"></span> ' + response.data.message + '</div>');

                if (response.data.forms.length > 0) {
                    renderForms(response.data.forms, response.data.url);
                    $('#rr-detected-forms').show();
                } else {
                    $('#rr-detected-forms').hide();
                    $status.html('<div class="rr-notice rr-notice-warning"><span class="dashicons dashicons-warning"></span> Geen formulieren gevonden op deze pagina.</div>');
                }
            } else {
                $status.html('<div class="rr-notice rr-notice-error"><span class="dashicons dashicons-no"></span> ' + response.data.message + '</div>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Scan Formulieren');
            $status.html('<div class="rr-notice rr-notice-error"><span class="dashicons dashicons-no"></span> Er is een fout opgetreden.</div>');
        });
    });

    // Render detected forms
    function renderForms(forms, pageUrl) {
        var $list = $('#rr-forms-list');
        $list.empty();

        forms.forEach(function(form) {
            var fieldsHtml = '';
            if (form.fields && form.fields.length > 0) {
                fieldsHtml = '<div class="rr-form-fields"><strong>Velden:</strong> ';
                form.fields.forEach(function(field) {
                    fieldsHtml += '<span class="rr-field-tag">' + escapeHtml(field.name) + ' <small>(' + field.type + ')</small></span> ';
                });
                fieldsHtml += '</div>';
            }

            var card = '<div class="rr-form-card" data-form-index="' + form.index + '">' +
                '<div class="rr-form-card-header">' +
                    '<span class="rr-form-type-badge">' + escapeHtml(form.type) + '</span>' +
                    (form.id ? '<span class="rr-form-id">#' + escapeHtml(form.id) + '</span>' : '') +
                '</div>' +
                '<div class="rr-form-card-body">' +
                    '<p><strong>Methode:</strong> ' + form.method + '</p>' +
                    (form.action ? '<p><strong>Actie:</strong> <code>' + escapeHtml(form.action) + '</code></p>' : '') +
                    '<p><strong>Velden:</strong> ' + form.field_count + '</p>' +
                    fieldsHtml +
                '</div>' +
                '<div class="rr-form-card-footer">' +
                    '<button class="button rr-btn-primary rr-btn-test-form" ' +
                        'data-url="' + escapeHtml(pageUrl) + '" ' +
                        'data-form-index="' + form.index + '" ' +
                        'data-form-id="' + escapeHtml(form.id || '') + '" ' +
                        'data-form-name="' + escapeHtml(form.type) + '" ' +
                        'data-form-action="' + escapeHtml(form.action || '') + '">' +
                        '<span class="dashicons dashicons-yes-alt"></span> Test Formulier' +
                    '</button>' +
                '</div>' +
            '</div>';

            $list.append(card);
        });
    }

    // Test a form
    $(document).on('click', '.rr-btn-test-form', function() {
        var $btn = $(this);
        var url = $btn.data('url');
        var formIndex = $btn.data('form-index');
        var formId = $btn.data('form-id');
        var formName = $btn.data('form-name');
        var formAction = $btn.data('form-action');

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update rr-spin"></span> Testen...');

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_test_form',
            nonce: rrAdmin.nonce,
            url: url,
            form_index: formIndex,
            form_id: formId,
            form_name: formName,
            form_action: formAction
        }, function(response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Test Formulier');

            if (response.success) {
                showTestResults(response.data, formName);
            } else {
                alert(response.data.message);
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Test Formulier');
        });
    });

    // Show test results
    function showTestResults(data, formName) {
        var $results = $('#rr-test-results');
        var $content = $('#rr-test-results-content');

        var statusClass = data.success ? 'rr-result-success' : 'rr-result-error';
        var statusIcon = data.success ? 'dashicons-yes-alt' : 'dashicons-warning';
        var statusText = data.success ? 'Test Geslaagd' : 'Problemen Gevonden';

        var diagnosisHtml = '';
        if (data.diagnosis && data.diagnosis.length > 0) {
            diagnosisHtml = '<ul class="rr-diagnosis-list">';
            data.diagnosis.forEach(function(item) {
                diagnosisHtml += '<li>' + escapeHtml(item) + '</li>';
            });
            diagnosisHtml += '</ul>';
        }

        var html = '<div class="rr-test-result ' + statusClass + '">' +
            '<div class="rr-test-result-header">' +
                '<span class="dashicons ' + statusIcon + '"></span>' +
                '<h3>' + statusText + ' - ' + escapeHtml(formName) + '</h3>' +
            '</div>' +
            '<div class="rr-test-result-body">' +
                '<h4>Diagnose:</h4>' +
                diagnosisHtml +
            '</div>' +
            '<div class="rr-test-result-footer">' +
                '<button class="button rr-btn-primary rr-btn-send-diagnosis" data-test-id="' + data.test_id + '">' +
                    '<span class="dashicons dashicons-email"></span> Verstuur Diagnose per E-mail' +
                '</button>' +
            '</div>' +
        '</div>';

        $content.html(html);
        $results.show();

        // Scroll to results
        $('html, body').animate({ scrollTop: $results.offset().top - 50 }, 500);
    }

    // Send diagnosis email
    $(document).on('click', '.rr-btn-send-diagnosis', function() {
        var $btn = $(this);
        var testId = $btn.data('test-id');

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update rr-spin"></span> Verzenden...');

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_send_diagnosis',
            nonce: rrAdmin.nonce,
            test_id: testId
        }, function(response) {
            if (response.success) {
                $btn.replaceWith('<span class="rr-badge rr-badge-success"><span class="dashicons dashicons-yes"></span> ' + response.data.message + '</span>');
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-email"></span> Verstuur Diagnose per E-mail');
            }
        });
    });

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

})(jQuery);
