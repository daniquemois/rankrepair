(function($) {
    'use strict';

    // ==========================================
    // Two-panel: page selection
    // ==========================================

    // Global: ID of the currently displayed page
    var currentPageId = null;
    var isDirty = false;

    window.rrMMSelectPage = function(el) {
        var $el  = $(el);
        var id   = $el.data('id');
        if (id === currentPageId) return;

        // Highlight active row
        $('.rr-mm-page-row').removeClass('active');
        $el.addClass('active');

        currentPageId = id;
        isDirty = false;
        updateFooter(false, $el.find('.rr-mm-page-name').text());

        // Build the edit panel from data attributes
        var rawH1 = $el.data('h1') || '[]';
        var h1s = [];
        try { h1s = JSON.parse(rawH1); } catch(e) { if (rawH1) h1s = [rawH1]; }

        var dupTitlePeers = $el.data('dup-title-peers') || [];
        var dupDescPeers  = $el.data('dup-desc-peers')  || [];
        if (typeof dupTitlePeers === 'string') { try { dupTitlePeers = JSON.parse(dupTitlePeers); } catch(e) { dupTitlePeers = []; } }
        if (typeof dupDescPeers  === 'string') { try { dupDescPeers  = JSON.parse(dupDescPeers);  } catch(e) { dupDescPeers  = []; } }

        var data = {
            id:             id,
            h1s:            h1s,
            title:          $el.data('title') || '',
            desc:           $el.data('desc')  || '',
            newTitle:       $el.data('new-title') || '',
            newDesc:        $el.data('new-desc')  || '',
            url:            $el.data('url')    || '',
            path:           $el.data('path')   || '/',
            name:           $el.data('name')   || '',
            status:         $el.data('status') || '',
            dupTitlePeers:  dupTitlePeers,
            dupDescPeers:   dupDescPeers
        };

        renderEditPanel(data);
        fetchRealH1s(id);
    };

    window.rrMMNavigate = function(btn, dir) {
        var $rows    = $('.rr-mm-page-row');
        var $current = $('.rr-mm-page-row.active');
        var idx      = $rows.index($current);
        var next     = idx + dir;
        if (next < 0 || next >= $rows.length) return;
        rrMMSelectPage($rows[next][0]);
        $rows[next][0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };

    function renderEditPanel(d) {
        var siteDomain = window.location.hostname;

        var titleLen = d.title.length;
        var descLen  = d.desc.length;

        var titleCharClass = charClass(titleLen, 50, 60);
        var titleCharLabel = charLabel(titleLen, 50, 60);
        var descCharClass  = charClass(descLen, 150, 160);
        var descCharLabel  = charLabel(descLen, 150, 160);

        var previewTitle = d.newTitle || d.title || '(geen meta titel)';
        var previewDesc  = d.newDesc  || d.desc  || '(geen meta omschrijving)';

        var aiBtn = (rrMetaManager && rrMetaManager.hasAI)
            ? '<button type="button" class="rr-mm-ai-btn" onclick="rrMMGenerate(' + d.id + ', \'title\')">✨ AI suggestie</button>'
            : '';
        var aiBtnDesc = (rrMetaManager && rrMetaManager.hasAI)
            ? '<button type="button" class="rr-mm-ai-btn" onclick="rrMMGenerate(' + d.id + ', \'description\')">✨ AI suggestie</button>'
            : '';

        // Toon AI-suggestie alleen als die verschilt van de huidige waarde
        var aiSuggestionTitle = (d.newTitle && d.newTitle !== d.title)
            ? buildAiBlock(d.id, 'title', d.newTitle)
            : '';
        var aiSuggestionDesc  = (d.newDesc && d.newDesc !== d.desc)
            ? buildAiBlock(d.id, 'description', d.newDesc)
            : '';

        // Issue badges
        var issueBadges = '';
        var h1sArr = d.h1s || [];
        var titleLen = d.title.length;
        var descLen  = d.desc.length;
        if (h1sArr.length === 0)        issueBadges += '<span class="rr-mm-issue-badge rr-mm-issue-badge--danger">H1 ontbreekt</span>';
        else if (h1sArr.length > 1)     issueBadges += '<span class="rr-mm-issue-badge rr-mm-issue-badge--warning">Meerdere H1\'s</span>';
        if (titleLen === 0)             issueBadges += '<span class="rr-mm-issue-badge rr-mm-issue-badge--danger">Titel ontbreekt</span>';
        else if (titleLen > 60)         issueBadges += '<span class="rr-mm-issue-badge rr-mm-issue-badge--warning">Titel te lang</span>';
        if (descLen === 0)              issueBadges += '<span class="rr-mm-issue-badge rr-mm-issue-badge--danger">Omschrijving ontbreekt</span>';
        else if (descLen > 160)         issueBadges += '<span class="rr-mm-issue-badge rr-mm-issue-badge--warning">Omschrijving te lang</span>';
        if (d.newTitle && d.status !== 'applied') issueBadges += '<span class="rr-mm-issue-badge rr-mm-issue-badge--indigo">AI suggesties klaar</span>';

        var html =
            '<div class="rr-mm-page-title-bar">' +
                '<div>' +
                    '<h2 class="rr-mm-page-heading">' + escHtml(d.name) + '</h2>' +
                    '<div class="rr-mm-page-url-bar">' +
                        '<input class="rr-mm-slug-input" type="text" value="' + escHtml(siteDomain + d.path) + '" readonly>' +
                        issueBadges +
                    '</div>' +
                '</div>' +
                '<div class="rr-mm-nav-btns">' +
                    '<button type="button" class="rr-btn rr-btn-secondary button" onclick="rrMMNavigate(this, -1)">← Vorige</button>' +
                    '<button type="button" class="rr-btn rr-btn-secondary button" onclick="rrMMNavigate(this, 1)">Volgende →</button>' +
                '</div>' +
            '</div>' +

            // H1 field (read-only)
            (function() {
                var h1s = d.h1s || [];
                var h1Count = h1s.length;
                var dupBadge = h1Count > 1
                    ? '<span class="rr-badge rr-badge-danger">' + h1Count + ' H1\'s gevonden!</span>'
                    : '';
                var charBadge = h1Count === 0
                    ? '<span class="rr-mm-char-badge rr-mm-char-missing" id="rr-char-h1">Niet gevonden</span>'
                    : '<span class="rr-mm-char-badge ' + (h1Count > 1 ? 'rr-mm-char-over' : 'rr-mm-char-ok') + '" id="rr-char-h1">' + h1Count + ' H1' + (h1Count > 1 ? '\'s' : '') + '</span>';
                var inputs = h1s.length === 0
                    ? '<input type="text" class="rr-mm-current-input" value="" placeholder="Geen H1 tag gevonden" readonly>'
                    : h1s.map(function(t, i) {
                        var extra = h1Count > 1 ? ' style="border-left:3px solid ' + (i === 0 ? 'var(--rr-warning)' : 'var(--rr-danger)') + ';margin-bottom:4px" title="H1 #' + (i+1) + '"' : '';
                        return '<input type="text" class="rr-mm-current-input' + (i > 0 ? ' rr-mm-h1-extra' : '') + '" value="' + escHtml(t) + '"' + extra + ' readonly>';
                    }).join('');
                return '<div class="rr-mm-field-card" id="rr-mm-card-h1">' +
                    '<div class="rr-mm-field-header">' +
                        '<div class="rr-mm-field-label-group">' +
                            '<span class="rr-mm-field-label">H1 — Paginatitel</span>' +
                            '<span class="rr-mm-field-type-badge rr-mm-badge-h1">H1</span>' +
                            dupBadge +
                        '</div>' +
                        '<div class="rr-mm-field-actions">' + charBadge + '</div>' +
                    '</div>' +
                    '<div class="rr-mm-field-body">' + inputs + '</div>' +
                '</div>';
            })() +

            // Meta title field
            '<div class="rr-mm-field-card active-card" id="rr-mm-card-title">' +
                '<div class="rr-mm-field-header">' +
                    '<div class="rr-mm-field-label-group">' +
                        '<span class="rr-mm-field-label">Meta titel</span>' +
                        '<span class="rr-mm-field-type-badge rr-mm-badge-title">Title tag</span>' +
                    '</div>' +
                    '<div class="rr-mm-field-actions">' +
                        '<span class="rr-mm-char-badge ' + titleCharClass + '" id="rr-char-title">' + titleCharLabel + '</span>' +
                        aiBtn +
                    '</div>' +
                '</div>' +
                '<div class="rr-mm-field-body">' +
                    '<input type="text" class="rr-mm-current-input" id="rr-title-current" value="' + escHtml(d.title) + '" placeholder="Geen meta titel ingesteld..." data-id="' + d.id + '">' +
                    buildDupNotice(d.dupTitlePeers, 'Zelfde titel ook op:') +
                    aiSuggestionTitle +
                '</div>' +
            '</div>' +

            // Meta description field
            '<div class="rr-mm-field-card" id="rr-mm-card-desc">' +
                '<div class="rr-mm-field-header">' +
                    '<div class="rr-mm-field-label-group">' +
                        '<span class="rr-mm-field-label">Meta omschrijving</span>' +
                        '<span class="rr-mm-field-type-badge rr-mm-badge-desc">Description</span>' +
                    '</div>' +
                    '<div class="rr-mm-field-actions">' +
                        '<span class="rr-mm-char-badge ' + descCharClass + '" id="rr-char-desc">' + descCharLabel + '</span>' +
                        aiBtnDesc +
                    '</div>' +
                '</div>' +
                '<div class="rr-mm-field-body">' +
                    '<textarea class="rr-mm-current-textarea" id="rr-desc-current" placeholder="Geen meta omschrijving ingesteld..." data-id="' + d.id + '" rows="2">' + escHtml(d.desc) + '</textarea>' +
                    buildDupNotice(d.dupDescPeers, 'Zelfde omschrijving ook op:') +
                    aiSuggestionDesc +
                '</div>' +
            '</div>' +

            // Google Preview
            '<div class="rr-mm-preview-card">' +
                '<p class="rr-mm-preview-label">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
                    'GOOGLE ZOEKRESULTAAT VOORVERTONING' +
                '</p>' +
                '<div class="rr-google-preview" id="rr-google-preview">' +
                    '<div class="rr-gp-domain-row">' +
                        '<span class="rr-gp-favicon">G</span>' +
                        '<span class="rr-gp-domain">' + escHtml(siteDomain) + '</span>' +
                        '<span class="rr-gp-breadcrumb">›&nbsp;' + escHtml(d.path.replace(/^\//, '') || 'Home') + '</span>' +
                    '</div>' +
                    '<p class="rr-gp-title" id="rr-gp-title">' + escHtml(previewTitle) + '</p>' +
                    '<p class="rr-gp-snippet" id="rr-gp-snippet">' + escHtml(previewDesc) + '</p>' +
                '</div>' +
            '</div>';

        $('#rr-mm-main').html(html);

        // Track dirty state (ook H1 velden)
        $('#rr-mm-main').on('input', '#rr-title-current, #rr-desc-current, [id^="rr-h1-current-"]', function() {
            isDirty = true;
            updateFooter(true, $('.rr-mm-page-row.active .rr-mm-page-name').text());
            updateGooglePreview();
        });
    }

    function buildDupNotice(peers, label) {
        if (!peers || peers.length === 0) return '';
        var links = peers.map(function(url) {
            return '<a href="' + escHtml(url) + '" target="_blank" class="rr-mm-dup-peer-link">' + escHtml(url) + '</a>';
        }).join('');
        return '<div class="rr-mm-dup-notice"><span class="rr-mm-dup-notice-icon">⚠</span>' + escHtml(label) + ' ' + links + '</div>';
    }

    // textRaw = plain text; used for display (HTML-escaped) and data-suggestion attribute
    function buildAiBlock(id, type, textRaw) {
        var textHtml = escHtml(textRaw);
        var textAttr = escAttr(textRaw);
        var regenBtn = (rrMetaManager && rrMetaManager.hasAI)
            ? '<button type="button" class="rr-btn rr-btn-secondary rr-btn-xs button" style="border-color:#C4B5FD;color:var(--rr-primary)" onclick="rrMMGenerate(' + id + ', \'' + type + '\')">↺ Opnieuw genereren</button>'
            : '';
        return '<div class="rr-mm-ai-suggestion">' +
            '<span class="rr-mm-ai-suggestion-label">✨ AI suggestie</span>' +
            '<div class="rr-mm-ai-suggestion-box">' +
                '<p class="rr-mm-suggestion-text">' + textHtml + '</p>' +
                '<div class="rr-mm-suggestion-actions">' +
                    '<button type="button" class="rr-btn rr-btn-primary rr-btn-xs button" data-suggestion="' + textAttr + '" onclick="rrMMAccept(' + id + ', \'' + type + '\', this)">✓ Overnemen</button>' +
                    '<button type="button" class="rr-btn rr-btn-ghost rr-btn-xs button" onclick="rrMMReject(' + id + ', \'' + type + '\', this)">✕ Afwijzen</button>' +
                    regenBtn +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function fetchRealH1s(postId) {
        var $card = $('#rr-mm-card-h1');
        var $body = $card.find('.rr-mm-field-body');
        var $badge = $card.find('#rr-char-h1');

        // Toon laad-indicator
        $badge.text('Laden…').removeClass('rr-mm-char-ok rr-mm-char-over rr-mm-char-missing').addClass('rr-mm-char-loading');

        function resetBadgeToServerData() {
            var rawH1 = $('.rr-mm-page-row[data-id="' + postId + '"]').attr('data-h1') || '[]';
            var h1s = [];
            try { h1s = JSON.parse(rawH1); } catch(e) {}
            var count = h1s.length;
            $badge.removeClass('rr-mm-char-loading');
            if (count === 0) {
                $badge.text('Niet gevonden').addClass('rr-mm-char-missing');
                $body.html('<input type="text" class="rr-mm-current-input" value="" placeholder="Geen H1 tag gevonden op deze pagina" readonly>');
            } else {
                $badge.text(count + ' H1' + (count > 1 ? '\'s' : '')).addClass(count > 1 ? 'rr-mm-char-over' : 'rr-mm-char-ok');
            }
        }

        $.post(rrAdmin.ajaxUrl, {
            action:  'rr_fetch_h1s',
            nonce:   rrAdmin.nonce,
            post_id: postId
        }, function(response) {
            if (!response.success) { resetBadgeToServerData(); return; }
            var h1s = response.data.h1s || [];
            var count = h1s.length;

            // Badge bijwerken
            $badge.removeClass('rr-mm-char-loading');
            if (count === 0) {
                $badge.text('Niet gevonden').addClass('rr-mm-char-missing');
            } else if (count > 1) {
                $badge.text(count + ' H1\'s').addClass('rr-mm-char-over');
            } else {
                $badge.text('1 H1').addClass('rr-mm-char-ok');
            }

            // Waarschuwing bij meerdere H1s
            var $labelGroup = $card.find('.rr-mm-field-label-group');
            $labelGroup.find('.rr-badge-danger').remove();
            if (count > 1) {
                $labelGroup.append('<span class="rr-badge rr-badge-danger">' + count + ' H1\'s gevonden!</span>');
            }

            // Inputs bijwerken
            if (count === 0) {
                $body.html('<input type="text" class="rr-mm-current-input" value="" placeholder="Geen H1 tag gevonden op deze pagina" readonly>');
            } else {
                var html = '';
                $.each(h1s, function(i, text) {
                    var style = count > 1 ? ' style="border-left:3px solid ' + (i === 0 ? 'var(--rr-warning)' : 'var(--rr-danger)') + ';margin-bottom:4px"' : '';
                    html += '<input type="text" class="rr-mm-current-input' + (i > 0 ? ' rr-mm-h1-extra' : '') + '" value="' + escHtml(text) + '"' + style + ' readonly>';
                });
                $body.html(html);
            }

            // Sla op in data-attribuut voor navigatie
            $('.rr-mm-page-row[data-id="' + postId + '"]').attr('data-h1', JSON.stringify(h1s));
        }).fail(function() {
            resetBadgeToServerData();
        });
    }

    function updateGooglePreview() {
        var title = $('#rr-title-current').val() || '(geen meta titel)';
        var desc  = $('#rr-desc-current').val()  || '(geen meta omschrijving)';
        $('#rr-gp-title').text(title);
        $('#rr-gp-snippet').text(desc);
    }

    function updateFooter(dirty, pageName) {
        var $dot     = $('#rr-mm-footer-status .rr-mm-footer-dot');
        var $text    = $('#rr-mm-footer-text');
        var $save    = $('#rr-mm-save');
        var $discard = $('#rr-mm-discard');

        if (dirty) {
            $dot.css('background', 'var(--rr-warning)');
            $text.text('Niet opgeslagen wijzigingen op ' + (pageName || ''));
            $save.show().text('Opslaan');
            $discard.show();
        } else if (pageName) {
            $dot.css('background', 'var(--rr-success)');
            $text.text('Geselecteerd: ' + pageName);
            $save.show().text('Opslaan');
            $discard.hide();
        } else {
            $dot.css('background', 'var(--rr-gray-300)');
            $text.text('Selecteer een pagina om te bewerken');
            $save.hide();
            $discard.hide();
        }
    }

    // Opslaan — blijft op huidige pagina, toont inline bevestiging
    $('#rr-mm-save').on('click', function(e) {
        e.preventDefault();
        if (!currentPageId) return;
        var $btn  = $(this);
        var title = $('#rr-title-current').val();
        var desc  = $('#rr-desc-current').val();
        var h1    = $('#rr-h1-current-0').val() || '';

        $btn.prop('disabled', true).text('Opslaan...');

        $.post(rrAdmin.ajaxUrl, {
            action:          'rr_save_meta',
            nonce:           rrAdmin.nonce,
            id:              currentPageId,
            new_title:       title,
            new_description: desc,
            current_h1:      h1
        }, function(response) {
            $btn.prop('disabled', false).text('Opslaan');
            if (response.success) {
                isDirty = false;
                var $activeRow = $('.rr-mm-page-row[data-id="' + currentPageId + '"]');
                $activeRow
                    .attr('data-title',     title)
                    .attr('data-desc',      desc)
                    .attr('data-new-title', '')
                    .attr('data-new-desc',  '');
                if (h1) $activeRow.attr('data-h1', JSON.stringify([h1]));
                updateFooter(false, $activeRow.find('.rr-mm-page-name').text());
                rrShowToast('✓ Opgeslagen!');
            } else {
                var msg = (response.data && response.data.message) ? response.data.message : 'Fout bij opslaan.';
                rrShowToast('✗ ' + msg, true);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Opslaan');
            rrShowToast('✗ Verbindingsfout bij opslaan.', true);
        });
    });

    // Discard changes
    $('#rr-mm-discard').on('click', function() {
        var $active = $('.rr-mm-page-row.active');
        if ($active.length) {
            isDirty = false;
            rrMMSelectPage($active[0]);
        }
    });

    // ==========================================
    // AI Generation (two-panel)
    // ==========================================

    window.rrMMGenerate = function(id, type) {
        if (!rrMetaManager.hasAI) {
            alert(rrMetaManager.strings.noKey);
            return;
        }

        // Show spinner on the relevant badge
        var $badge;
        if (type === 'description') $badge = $('#rr-char-desc');
        else if (type === 'h1')     $badge = $('#rr-char-h1');
        else                        $badge = $('#rr-char-title');
        var origBadge = $badge.text();
        $badge.text('Genereren...');

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_gemini_generate',
            nonce:  rrAdmin.nonce,
            id:     id
        }, function(response) {
            $badge.text(origBadge);
            if (response.success) {
                var newTitle = response.data.title;
                var newDesc  = response.data.description;

                // Update data-attributes on the page row so re-click works
                var $row = $('.rr-mm-page-row[data-id="' + id + '"]');
                $row.data('new-title', newTitle).data('new-desc', newDesc);
                $row.attr('data-new-title', newTitle).attr('data-new-desc', newDesc);

                // Update the edit panel
                if (type === 'title') {
                    var $titleBlock = $('#rr-mm-card-title .rr-mm-ai-suggestion');
                    if ($titleBlock.length) {
                        $titleBlock.find('.rr-mm-suggestion-text').text(newTitle);
                        $titleBlock.find('[data-suggestion]').attr('data-suggestion', newTitle);
                    } else {
                        $('#rr-title-current').after(buildAiBlock(id, 'title', newTitle));
                    }
                } else {
                    var $descBlock = $('#rr-mm-card-desc .rr-mm-ai-suggestion, #rr-desc-current ~ .rr-mm-ai-suggestion');
                    if ($descBlock.length) {
                        $descBlock.find('.rr-mm-suggestion-text').text(newDesc);
                        $descBlock.find('[data-suggestion]').attr('data-suggestion', newDesc);
                    } else {
                        $('#rr-desc-current').after(buildAiBlock(id, 'description', newDesc));
                    }
                }
            } else {
                alert('AI fout: ' + response.data.message);
            }
        }).fail(function() {
            $badge.text(origBadge);
            alert('AI: verbinding mislukt.');
        });
    };

    window.rrMMAccept = function(id, type, btn) {
        var suggestion = '';
        if (btn && $(btn).attr('data-suggestion')) {
            // Preferred: read from data attribute on the button (always correct)
            suggestion = $(btn).attr('data-suggestion');
        } else if (btn) {
            // Fallback: find text relative to clicked button
            suggestion = $(btn).closest('.rr-mm-ai-suggestion').find('.rr-mm-suggestion-text').text();
        } else {
            // Last resort: search by container id
            suggestion = type === 'title'
                ? $('#rr-mm-card-title .rr-mm-suggestion-text').text()
                : $('#rr-mm-card-desc .rr-mm-suggestion-text').text();
        }
        if (type === 'title') {
            $('#rr-title-current').val(suggestion);
        } else {
            $('#rr-desc-current').val(suggestion);
        }
        isDirty = true;
        updateFooter(true, $('.rr-mm-page-row.active .rr-mm-page-name').text());
        updateGooglePreview();
    };

    window.rrMMReject = function(id, type, btn) {
        var $block = btn ? $(btn).closest('.rr-mm-ai-suggestion') : null;
        if ($block && $block.length) {
            $block.remove();
        } else if (type === 'title') {
            $('#rr-mm-card-title .rr-mm-ai-suggestion').remove();
        } else {
            $('#rr-mm-card-desc .rr-mm-ai-suggestion').remove();
        }
        var attr = type === 'title' ? 'data-new-title' : 'data-new-desc';
        var dataKey = type === 'title' ? 'new-title' : 'new-desc';
        $('.rr-mm-page-row[data-id="' + id + '"]').data(dataKey, '').attr(attr, '');
    };

    // ==========================================
    // Bulk AI (header button)
    // ==========================================
    $('#rr-gemini-bulk').on('click', function() {
        if (!confirm(rrMetaManager.strings.confirmBulk)) return;

        var $btn = $(this);
        var ids  = [];
        $('.rr-mm-page-row').each(function() { ids.push($(this).data('id')); });
        if (!ids.length) return;

        $btn.prop('disabled', true).text('Bezig met genereren...');

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_gemini_generate_bulk',
            nonce:  rrAdmin.nonce,
            ids:    ids
        }, function(response) {
            $btn.prop('disabled', false).text('Alles herschrijven met AI');
            if (response.success && response.data.results && response.data.results.length) {
                rrShowBulkReview(response.data.results);
            } else {
                var msg = (response.data && response.data.message) ? response.data.message : 'Fout bij genereren.';
                alert('AI fout: ' + msg);
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Alles herschrijven met AI');
            alert('Verbinding mislukt.');
        });
    });

    // Scan verwijderd — data wordt live geladen bij elke paginaload

    // ==========================================
    // SE Ranking API
    // ==========================================
    $('#rr-seranking-load-sites').on('click', function() {
        var $btn    = $(this);
        var $select = $('#rr-seranking-site-select');
        var $status = $('#rr-meta-import-status');

        $btn.prop('disabled', true).text('Laden...');

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_seranking_get_sites',
            nonce:  rrAdmin.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('Laad sites');
            if (response.success) {
                $select.empty().append('<option value="">— Kies een site —</option>');
                $.each(response.data.sites, function(i, site) {
                    $select.append('<option value="' + site.id + '">' + site.name + '</option>');
                });
                $select.show();
                $('#rr-seranking-import').show();
            } else {
                if ($status.length) $status.html('<div class="rr-notice rr-notice-error">✗ ' + response.data.message + '</div>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Laad sites');
        });
    });

    $('#rr-seranking-import').on('click', function() {
        var $btn    = $(this);
        var $status = $('#rr-meta-import-status');
        var site_id = $('#rr-seranking-site-select').val();

        if (!site_id) { alert('Kies eerst een site.'); return; }

        $btn.prop('disabled', true).text('Importeren...');
        if ($status.length) $status.html('<div class="rr-notice rr-notice-info"><span class="rr-spinner-inline"></span> Ophalen uit SE Ranking...</div>');

        $.post(rrAdmin.ajaxUrl, {
            action:  'rr_seranking_import_pages',
            nonce:   rrAdmin.nonce,
            site_id: site_id
        }, function(response) {
            $btn.prop('disabled', false).text('Importeer');
            if (response.success) {
                if ($status.length) $status.html('<div class="rr-notice rr-notice-success">✓ ' + response.data.message + '</div>');
                setTimeout(function() { location.href = 'admin.php?page=rankrepair-meta-manager&filter=all'; }, 1200);
            } else {
                if ($status.length) $status.html('<div class="rr-notice rr-notice-error">✗ ' + response.data.message + '</div>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Importeer');
        });
    });

    // ==========================================
    // Import CSV
    // ==========================================
    $('#rr-meta-import-form').on('submit', function(e) {
        e.preventDefault();

        var fileInput = $('#rr-meta-csv-file')[0];
        if (!fileInput.files.length) { alert('Selecteer eerst een bestand.'); return; }

        var formData = new FormData();
        formData.append('action',   'rr_import_meta_csv');
        formData.append('nonce',    rrAdmin.nonce);
        formData.append('csv_file', fileInput.files[0]);

        var $status = $('#rr-meta-import-status');
        if ($status.length) $status.html('<div class="rr-notice rr-notice-info"><span class="rr-spinner-inline"></span> Importeren...</div>');

        $.ajax({
            url:         rrAdmin.ajaxUrl,
            type:        'POST',
            data:        formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    if ($status.length) $status.html('<div class="rr-notice rr-notice-success">✓ ' + response.data.message + '</div>');
                    setTimeout(function() { location.href = 'admin.php?page=rankrepair-meta-manager&filter=all'; }, 1200);
                } else {
                    if ($status.length) $status.html('<div class="rr-notice rr-notice-error">✗ ' + response.data.message + '</div>');
                }
            },
            error: function() {
                if ($status.length) $status.html('<div class="rr-notice rr-notice-error">AJAX-fout bij importeren.</div>');
            }
        });
    });

    // ==========================================
    // Helpers
    // ==========================================

    function charClass(len, min, max) {
        if (len === 0) return 'rr-mm-char-missing';
        if (len >= min && len <= max) return 'rr-mm-char-ok';
        return 'rr-mm-char-over';
    }

    function charLabel(len, min, max) {
        if (len === 0) return 'Ontbreekt ✗';
        var suffix = (len >= min && len <= max) ? ' ✓' : '';
        return len + ' tekens' + suffix;
    }

    function escHtml(text) {
        if (!text) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(text));
        return d.innerHTML;
    }

    function escAttr(text) {
        if (!text) return '';
        return escHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#x27;');
    }

    // Activate the first page row automatically and fetch real H1s
    var $first = $('.rr-mm-page-row').first();
    if ($first.length && !currentPageId) {
        // Already rendered server-side; just mark it and set currentPageId
        currentPageId = $first.data('id');
        updateFooter(false, $first.find('.rr-mm-page-name').text());
        fetchRealH1s(currentPageId);
    }

    // ==========================================
    // Toast notification
    // ==========================================

    function rrShowToast(msg, isError) {
        var $t = $('<div class="rr-mm-toast' + (isError ? ' rr-mm-toast--error' : '') + '">').text(msg);
        $('body').append($t);
        setTimeout(function() { $t.addClass('rr-mm-toast--out'); }, 2200);
        setTimeout(function() { $t.remove(); }, 2600);
    }

    // ==========================================
    // Bulk review modal
    // ==========================================

    function rrShowBulkReview(results) {
        var $overlay = $('<div id="rr-bulk-overlay">');
        var $modal   = $('<div id="rr-bulk-modal">');

        // Header
        var $header = $('<div class="rr-bulk-header">')
            .append($('<h2 class="rr-bulk-title">').text('AI-suggesties controleren'))
            .append($('<span class="rr-bulk-count">').text(results.length + ' pagina\'s gegenereerd'))
            .append($('<button type="button" class="rr-bulk-close-btn" id="rr-bulk-close">').html('&times;'));

        // Body — one card per result
        var $body = $('<div class="rr-bulk-body">');
        $.each(results, function(i, item) {
            var $row     = $('.rr-mm-page-row[data-id="' + item.id + '"]');
            var name     = $row.find('.rr-mm-page-name').text() || ('Pagina ' + item.id);
            var curTitle = $row.data('title') || '';
            var curDesc  = $row.data('desc')  || '';

            var $card = $('<div class="rr-bulk-card">').attr('data-id', item.id);
            $card.append($('<div class="rr-bulk-card-name">').text(name));

            var $cols = $('<div class="rr-bulk-cols">');

            // Title column
            var $tc = $('<div class="rr-bulk-col">');
            $tc.append($('<label class="rr-bulk-lbl">').text('Huidige titel'));
            $tc.append($('<div class="rr-bulk-cur">').text(curTitle || '(geen)'));
            $tc.append($('<label class="rr-bulk-lbl rr-bulk-lbl--ai">').text('AI-suggestie titel'));
            $tc.append($('<input type="text" class="rr-bulk-field-title">').val(item.title));

            // Description column
            var $dc = $('<div class="rr-bulk-col">');
            $dc.append($('<label class="rr-bulk-lbl">').text('Huidige beschrijving'));
            $dc.append($('<div class="rr-bulk-cur">').text(curDesc || '(geen)'));
            $dc.append($('<label class="rr-bulk-lbl rr-bulk-lbl--ai">').text('AI-suggestie beschrijving'));
            $dc.append($('<textarea class="rr-bulk-field-desc" rows="3">').val(item.description));

            $cols.append($tc, $dc);
            $card.append($cols);
            $body.append($card);
        });

        // Footer
        var $footer = $('<div class="rr-bulk-footer">')
            .append($('<button type="button" class="rr-btn rr-btn-secondary button" id="rr-bulk-cancel">').text('Annuleren'))
            .append($('<button type="button" class="rr-btn rr-btn-primary button" id="rr-bulk-save">').text('Alles opslaan (' + results.length + ')'));

        $modal.append($header, $body, $footer);
        $overlay.append($modal);
        $('body').append($overlay);

        // Close
        $overlay.on('click', '#rr-bulk-close, #rr-bulk-cancel', function() {
            $overlay.remove();
        });

        // Save all
        $overlay.on('click', '#rr-bulk-save', function() {
            var $saveBtn = $(this);
            var items = [];
            $overlay.find('.rr-bulk-card').each(function() {
                items.push({
                    id:    $(this).data('id'),
                    title: $(this).find('.rr-bulk-field-title').val(),
                    desc:  $(this).find('.rr-bulk-field-desc').val()
                });
            });

            $saveBtn.prop('disabled', true).text('Opslaan...');

            $.post(rrAdmin.ajaxUrl, {
                action: 'rr_save_bulk_meta',
                nonce:  rrAdmin.nonce,
                items:  items
            }, function(response) {
                if (response.success) {
                    $overlay.remove();
                    // Update list rows with saved suggestions
                    $.each(items, function(i, item) {
                        var $row = $('.rr-mm-page-row[data-id="' + item.id + '"]');
                        $row.attr('data-new-title', item.title).attr('data-new-desc', item.desc);
                        $row.data('new-title', item.title).data('new-desc', item.desc);
                        $row.find('.rr-mm-status-dot').attr('class', 'rr-mm-status-dot rr-mm-status-dot-indigo');
                        $row.find('.rr-mm-status-icon').css({ background: 'var(--rr-primary-light)', color: '#7C3AED' }).text('✨');
                    });
                    // Refresh active panel
                    if (currentPageId) {
                        var $active = $('.rr-mm-page-row.active');
                        if ($active.length) rrMMSelectPage($active[0]);
                    }
                    rrShowToast('✓ ' + response.data.message);
                } else {
                    $saveBtn.prop('disabled', false).text('Alles opslaan (' + items.length + ')');
                    var msg = (response.data && response.data.message) ? response.data.message : 'Fout bij opslaan.';
                    rrShowToast('✗ ' + msg, true);
                }
            }).fail(function() {
                $saveBtn.prop('disabled', false).text('Alles opslaan (' + items.length + ')');
                rrShowToast('✗ Verbindingsfout bij opslaan.', true);
            });
        });
    }

})(jQuery);
