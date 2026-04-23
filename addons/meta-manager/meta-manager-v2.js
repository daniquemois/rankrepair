(function($) {
    'use strict';

    // ==========================================
    // Two-panel: page selection
    // ==========================================

    // Global: ID of the currently displayed page
    var currentPageId = null;

    window.rrMMSelectPage = function(el) {
        var $el  = $(el);
        var id   = $el.data('id');
        if (id === currentPageId) return;

        // Highlight active row
        $('.rr-mm-page-row').removeClass('active');
        $el.addClass('active');

        currentPageId = id;
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

    window.rrMMNavigate = function(_btn, dir) {
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

        var tPx = titlePx(d.title);
        var dPx = descPx(d.desc);

        var titleCharClass = pxClass(tPx, TITLE_MAX);
        var titleCharLabel = pxLabel(tPx, TITLE_MAX);
        var descCharClass  = pxClass(dPx, DESC_MAX);
        var descCharLabel  = pxLabel(dPx, DESC_MAX);

        var previewTitle = d.newTitle || d.title || '(geen meta titel)';
        var previewDesc  = d.newDesc  || d.desc  || '(geen meta omschrijving)';

        var aiBtn = (rrMetaManager && rrMetaManager.hasAI)
            ? '<button type="button" class="rr-mm-ai-btn" onclick="rrMMGenerate(\'' + d.id + '\', \'title\')">✨ AI suggestie</button>'
            : '';
        var aiBtnDesc = (rrMetaManager && rrMetaManager.hasAI)
            ? '<button type="button" class="rr-mm-ai-btn" onclick="rrMMGenerate(\'' + d.id + '\', \'description\')">✨ AI suggestie</button>'
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
                    '<input type="text" class="rr-mm-current-input" id="rr-title-current" value="' + escHtml(d.title) + '" placeholder="Geen meta titel ingesteld..." data-id="' + d.id + '" data-original="' + escAttr(d.title) + '">' +
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
                    '<textarea class="rr-mm-current-textarea" id="rr-desc-current" placeholder="Geen meta omschrijving ingesteld..." data-id="' + d.id + '" data-original="' + escAttr(d.desc) + '" rows="2">' + escHtml(d.desc) + '</textarea>' +
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
            ? '<button type="button" class="rr-btn rr-btn-secondary rr-btn-xs button" style="border-color:#C4B5FD;color:var(--rr-primary)" onclick="rrMMGenerate(\'' + id + '\', \'' + type + '\')">↺ Opnieuw genereren</button>'
            : '';
        return '<div class="rr-mm-ai-suggestion">' +
            '<span class="rr-mm-ai-suggestion-label">✨ AI suggestie</span>' +
            '<div class="rr-mm-ai-suggestion-box">' +
                '<p class="rr-mm-suggestion-text">' + textHtml + '</p>' +
                '<div class="rr-mm-suggestion-actions">' +
                    '<button type="button" class="rr-btn rr-btn-primary rr-btn-xs button" data-suggestion="' + textAttr + '" onclick="rrMMAccept(\'' + id + '\', \'' + type + '\', this)">✓ Overnemen</button>' +
                    '<button type="button" class="rr-btn rr-btn-ghost rr-btn-xs button" onclick="rrMMReject(\'' + id + '\', \'' + type + '\', this)">✕ Afwijzen</button>' +
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

    // Update alle stat counters (top bar + pills + sidebar tabs) op basis van verse server data
    function updateAllStats(stats) {
        if (!stats || typeof stats !== 'object') return;
        $('[data-stat]').each(function() {
            var key = $(this).data('stat');
            if (typeof stats[key] !== 'undefined') {
                $(this).text(stats[key]);
            }
        });
        $('.rr-mm-pill').each(function() {
            var key = $(this).data('pill');
            if (!key) return;
            var num = parseInt($(this).find('strong').text(), 10) || 0;
            var isLink = $(this).is('a');
            if (num <= 0 && isLink) {
                // Replace anchor with span when it becomes 0
                var $span = $('<span>').attr('class', $(this).attr('class') + ' rr-mm-pill--disabled')
                                        .attr('data-pill', key)
                                        .html($(this).html());
                $(this).replaceWith($span);
            } else if (num > 0 && !isLink) {
                var $a = $('<a>').attr('href', '?page=rankrepair-meta-manager&filter=' + key)
                                 .attr('class', $(this).attr('class').replace(/\brr-mm-pill--disabled\b/, '').trim())
                                 .attr('data-pill', key)
                                 .html($(this).html());
                $(this).replaceWith($a);
            }
        });
    }

    // Pas huidige lijst-row aan op basis van verse server-data + verwijder als hij niet meer in actieve filter hoort
    function applyRowUpdate(itemData, activeFilter) {
        var $row = $('.rr-mm-page-row[data-id="' + itemData.id + '"]');
        if (!$row.length) return;

        // Badges (title + desc) op basis van nieuwe current_title/description
        updateRowBadgesAfterSave($row, itemData.current_title || '', itemData.current_description || '');

        // Check of de row nog in het actieve filter hoort
        var titlePxVal = titlePx(itemData.current_title || '');
        var descPxVal  = descPx(itemData.current_description || '');
        var belongs = true;

        if (activeFilter === 'issues') {
            belongs = !itemData.current_title || !itemData.current_description ||
                      itemData.is_duplicate_title || itemData.is_duplicate_description ||
                      (itemData.title_length > 0 && itemData.title_length < 30) ||
                      (itemData.description_length > 0 && itemData.description_length < 70) ||
                      itemData.title_length > 60 || itemData.description_length > 160;
        } else if (activeFilter === 'missing_title')    belongs = !itemData.current_title;
        else if (activeFilter === 'title_too_short')    belongs = itemData.title_length > 0 && itemData.title_length < 30;
        else if (activeFilter === 'title_too_long')     belongs = itemData.title_length > 60;
        else if (activeFilter === 'duplicate_title')    belongs = !!itemData.is_duplicate_title;
        else if (activeFilter === 'missing_desc')       belongs = !itemData.current_description;
        else if (activeFilter === 'desc_too_short')     belongs = itemData.description_length > 0 && itemData.description_length < 70;
        else if (activeFilter === 'desc_too_long')      belongs = itemData.description_length > 160;
        else if (activeFilter === 'duplicate_desc')     belongs = !!itemData.is_duplicate_description;
        else if (activeFilter === 'ai_ready')           belongs = false;
        else if (activeFilter === 'ok')                 belongs = true;

        if (!belongs) {
            $row.fadeOut(200, function() { $(this).remove(); });
        }
    }

    function getActiveFilter() {
        var m = window.location.search.match(/[?&]filter=([^&]+)/);
        return m ? decodeURIComponent(m[1]) : 'all';
    }

    // Update row badges in the sidebar after a save
    function updateRowBadgesAfterSave($row, title, desc) {
        var $badges = $row.find('.rr-mm-field-badges .rr-mm-fbadge');

        var titleLen = (title || '').length;
        var descLen  = (desc  || '').length;
        var tPx      = titlePx(title);
        var dPx      = descPx(desc);

        var tType = tPx === 0 ? 'danger'
                  : (tPx > TITLE_MAX || titleLen < 30 ? 'warning' : 'ok');
        var tText = tPx === 0 ? '✗' : (tPx > TITLE_MAX || titleLen < 30 ? '≈' : '✓');

        var dType = dPx === 0 ? 'danger'
                  : (dPx > DESC_MAX || descLen < 70 ? 'warning' : 'ok');
        var dText = dPx === 0 ? '✗' : (dPx > DESC_MAX || descLen < 70 ? '≈' : '✓');

        $badges.eq(1).attr('class', 'rr-mm-fbadge rr-mm-fbadge--' + tType).text(tText);
        $badges.eq(2).attr('class', 'rr-mm-fbadge rr-mm-fbadge--' + dType).text(dText);
    }

    function updateGooglePreview() {
        var title = $('#rr-title-current').val() || '(geen meta titel)';
        var desc  = $('#rr-desc-current').val()  || '(geen meta omschrijving)';
        $('#rr-gp-title').text(title);
        $('#rr-gp-snippet').text(desc);

        // Live pixel badge update + duplicate notice hide/show
        var $titleInput = $('#rr-title-current');
        var rawTitle = $titleInput.val();
        var tPx = titlePx(rawTitle);
        $('#rr-char-title').text(pxLabel(tPx, TITLE_MAX))
                           .attr('class', 'rr-mm-char-badge ' + pxClass(tPx, TITLE_MAX));
        $titleInput.closest('.rr-mm-field-body').find('.rr-mm-dup-notice')
                   .toggle(rawTitle === ($titleInput.data('original') || ''));

        var $descInput = $('#rr-desc-current');
        var rawDesc = $descInput.val();
        var dPx = descPx(rawDesc);
        $('#rr-char-desc').text(pxLabel(dPx, DESC_MAX))
                          .attr('class', 'rr-mm-char-badge ' + pxClass(dPx, DESC_MAX));
        $descInput.closest('.rr-mm-field-body').find('.rr-mm-dup-notice')
                  .toggle(rawDesc === ($descInput.data('original') || ''));
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

    // Live pixel-badge update + Google preview + duplicaatmelding bij typen
    // Gebonden op document-niveau zodat het werkt voor zowel PHP- als JS-renders
    $(document).on('input', '#rr-title-current, #rr-desc-current, [id^="rr-h1-current-"]', function() {
        updateFooter(true, $('.rr-mm-page-row.active .rr-mm-page-name').text());
        updateGooglePreview();
    });

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
                var $activeRow = $('.rr-mm-page-row[data-id="' + currentPageId + '"]');
                $activeRow
                    .attr('data-title',     title)
                    .attr('data-desc',      desc)
                    .attr('data-new-title', '')
                    .attr('data-new-desc',  '');
                if (h1) $activeRow.attr('data-h1', JSON.stringify([h1]));
                updateRowBadgesAfterSave($activeRow, title, desc);
                updateFooter(false, $activeRow.find('.rr-mm-page-name').text());

                // Realtime stats + eventuele row-verwijdering
                if (response.data.stats) updateAllStats(response.data.stats);
                if (response.data.item)  applyRowUpdate(response.data.item, getActiveFilter());

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

    window.rrMMAccept = function(_id, type, btn) {
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
    function rrShowLoadingModal(total) {
        var $overlay = $('<div id="rr-loading-overlay">');
        var $modal   = $('<div id="rr-loading-modal">');

        var $spin    = $('<div class="rr-loading-spinner">');
        var $title   = $('<p class="rr-loading-title">').text('AI is aan het genereren...');
        var $sub     = $('<p class="rr-loading-sub">').text('Even geduld, klik niet weg. Dit duurt ~4s per pagina.');
        var $bar     = $('<div class="rr-loading-bar-wrap">').append($('<div class="rr-loading-bar">').css('width', '0%'));
        var $prog    = $('<p class="rr-loading-prog">').text('0 / ' + total + ' pagina\'s verwerkt');
        var $stats   = $('<p class="rr-loading-stats">').text('');

        $modal.append($spin, $title, $sub, $bar, $prog, $stats);
        $overlay.append($modal).appendTo('body');
        $overlay.data('total', total);
    }

    function rrUpdateLoadingProgress(done, total, okCount, errCount) {
        var pct = Math.round((done / total) * 100);
        $('#rr-loading-overlay .rr-loading-bar').css('width', pct + '%');
        $('#rr-loading-overlay .rr-loading-prog').text(done + ' / ' + total + ' pagina\'s verwerkt');
        if (typeof okCount === 'number') {
            $('#rr-loading-overlay .rr-loading-stats').text('✓ ' + okCount + ' gelukt · ✗ ' + errCount + ' mislukt');
        }
    }

    function rrCloseLoadingModal() {
        $('#rr-loading-overlay').remove();
    }

    function rrRunBulkAI(ids, $btn, fields) {
        fields = (fields && fields.length) ? fields : ['title','desc'];
        var CHUNK   = 5;
        var total   = ids.length;
        var chunks  = [];
        for (var i = 0; i < ids.length; i += CHUNK) { chunks.push(ids.slice(i, i + CHUNK)); }

        var allResults = [];
        var allErrors  = [];
        var processed  = 0;

        $btn.prop('disabled', true).text('Bezig met genereren...');
        rrShowLoadingModal(total);

        function nextChunk(idx) {
            if (idx >= chunks.length) {
                rrCloseLoadingModal();
                $btn.prop('disabled', false);
                updateBulkBtn();
                if (allResults.length) {
                    rrShowBulkReview(allResults, allErrors, fields);
                } else {
                    var msg = 'Geen resultaten gegenereerd.';
                    if (allErrors.length && allErrors[0].reason) {
                        msg += '\n\nReden: ' + allErrors[0].reason;
                    }
                    alert(msg);
                }
                return;
            }
            var chunk = chunks[idx];
            $.post(rrAdmin.ajaxUrl, {
                action: 'rr_gemini_generate_bulk',
                nonce:  rrAdmin.nonce,
                ids:    chunk,
                fields: fields
            }, function(response) {
                if (response.success && response.data) {
                    if (response.data.results)       allResults = allResults.concat(response.data.results);
                    if (response.data.errors_detail) allErrors  = allErrors.concat(response.data.errors_detail);
                }
                processed += chunk.length;
                rrUpdateLoadingProgress(processed, total, allResults.length, allErrors.length);
                nextChunk(idx + 1);
            }).fail(function() {
                processed += chunk.length;
                $.each(chunk, function(_, id) {
                    allErrors.push({ id: id, name: 'Pagina ' + id, reason: 'Verbindingsfout' });
                });
                rrUpdateLoadingProgress(processed, total, allResults.length, allErrors.length);
                nextChunk(idx + 1);
            });
        }

        nextChunk(0);
    }

    function rrShowBatchModal($btn) {
        var issueIds = rrMetaManager.issueIds || {};
        var sets = {
            missingTitle: issueIds.missingTitle || [],
            shortTitle:   issueIds.shortTitle   || [],
            longTitle:    issueIds.longTitle    || [],
            dupTitle:     issueIds.dupTitle     || [],
            missingDesc:  issueIds.missingDesc  || [],
            shortDesc:    issueIds.shortDesc    || [],
            longDesc:     issueIds.longDesc     || [],
            dupDesc:      issueIds.dupDesc      || [],
        };

        var labels = {
            missingTitle: 'Ontbrekende titel',
            shortTitle:   'Titel te kort',
            longTitle:    'Titel te lang',
            dupTitle:     'Dubbele titel',
            missingDesc:  'Ontbrekende beschrijving',
            shortDesc:    'Beschrijving te kort',
            longDesc:     'Beschrijving te lang',
            dupDesc:      'Dubbele beschrijving',
        };

        // Standaard-selectie op basis van actief filter
        var filterToKeys = {
            missing_title:   ['missingTitle'],
            title_too_short: ['shortTitle'],
            title_too_long:  ['longTitle'],
            duplicate_title: ['dupTitle'],
            missing_desc:    ['missingDesc'],
            desc_too_short:  ['shortDesc'],
            desc_too_long:   ['longDesc'],
            duplicate_desc:  ['dupDesc'],
        };
        var activeFilter = rrMetaManager.activeFilter || 'all';
        var defaultKeys  = filterToKeys[activeFilter] || Object.keys(sets);

        var $overlay = $('<div id="rr-batch-overlay">');
        var $modal   = $('<div id="rr-batch-modal">');

        var $checks = $('<div class="rr-batch-checks">');
        $.each(sets, function(key, arr) {
            if (!arr.length) return;
            var isDefault = defaultKeys.indexOf(key) !== -1;
            $checks.append(
                $('<label class="rr-batch-check-row">').append(
                    $('<input type="checkbox" class="rr-batch-cb">').attr('data-key', key).prop('checked', isDefault),
                    $('<span class="rr-batch-cb-label">').text(labels[key]),
                    $('<span class="rr-batch-cb-count">').text(arr.length)
                )
            );
        });

        var $total = $('<p class="rr-batch-info">');
        var $field = $('<div class="rr-batch-field">').append(
            $('<label for="rr-batch-input">').text('Maximum per keer'),
            $('<input type="number" id="rr-batch-input" min="1">').val(100)
        );
        var $confirmBtn = $('<button class="rr-batch-confirm">').text('Doorgaan');

        $modal.append(
            $('<p class="rr-batch-title">').text('Welke fouten herschrijven met AI?'),
            $checks,
            $total,
            $field,
            $('<div class="rr-batch-actions">').append(
                $('<button class="rr-batch-cancel">').text('Annuleren'),
                $confirmBtn
            )
        );
        $overlay.append($modal).appendTo('body');

        function getSelectedIds() {
            var merged = {};
            $modal.find('.rr-batch-cb:checked').each(function() {
                var key = $(this).data('key');
                $.each(sets[key], function(_, id) { merged[String(id)] = true; });
            });
            return Object.keys(merged);
        }

        function getSelectedFields() {
            var fields = {};
            $modal.find('.rr-batch-cb:checked').each(function() {
                var key = $(this).data('key');
                if (key.indexOf('Title') !== -1) fields.title = true;
                if (key.indexOf('Desc')  !== -1) fields.desc  = true;
            });
            return Object.keys(fields);
        }

        function updateTotal() {
            var ids   = getSelectedIds();
            var limit = Math.min(parseInt($('#rr-batch-input').val(), 10) || 100, ids.length);
            $('#rr-batch-input').attr('max', ids.length);
            $total.text(ids.length + ' pagina\'s geselecteerd — er worden er maximaal ' + limit + ' verwerkt.');
            $confirmBtn.prop('disabled', !ids.length).text(ids.length ? 'Doorgaan' : 'Geen selectie');
        }

        $modal.on('change', '.rr-batch-cb, #rr-batch-input', updateTotal);
        updateTotal();
        $('#rr-batch-input').focus().select();

        function closeModal() { $overlay.remove(); }
        $overlay.on('click', function(e) { if ($(e.target).is('#rr-batch-overlay')) closeModal(); });
        $modal.find('.rr-batch-cancel').on('click', closeModal);

        $confirmBtn.on('click', function() {
            var ids    = getSelectedIds();
            var fields = getSelectedFields();
            var limit  = parseInt($('#rr-batch-input').val(), 10) || 100;
            if (limit < 1 || isNaN(limit)) limit = 100;
            if (limit > ids.length) limit = ids.length;
            closeModal();
            var batch = ids.slice(0, limit);
            if (!confirm('AI wordt aangeroepen voor ' + batch.length + ' pagina\'s. Doorgaan?')) return;
            rrRunBulkAI(batch, $btn, fields);
        });

        $modal.find('#rr-batch-input').on('keydown', function(e) {
            if (e.key === 'Enter') $confirmBtn.trigger('click');
            if (e.key === 'Escape') closeModal();
        });
    }

    $('#rr-gemini-bulk').on('click', function() {
        var $btn     = $(this);
        var issueIds = rrMetaManager.issueIds || {};
        var hasAny   = (issueIds.missingTitle || []).length || (issueIds.missingDesc || []).length ||
                       (issueIds.dupTitle || []).length     || (issueIds.dupDesc || []).length ||
                       (issueIds.longTitle || []).length    || (issueIds.longDesc || []).length ||
                       (issueIds.shortTitle || []).length   || (issueIds.shortDesc || []).length;
        if (!hasAny) { alert('Geen pagina\'s met fouten gevonden.'); return; }
        rrShowBatchModal($btn);
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
                $.each(response.data.sites, function(_, site) {
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

    // Pixel-based text measurement (approximates Google SERP rendering)
    var _pxCanvas = null;
    function measureTextPx(text, font) {
        if (!text) return 0;
        if (!_pxCanvas) _pxCanvas = document.createElement('canvas');
        var ctx = _pxCanvas.getContext('2d');
        ctx.font = font;
        return Math.round(ctx.measureText(text).width);
    }
    var TITLE_FONT = 'bold 20px Arial,sans-serif';
    var DESC_FONT  = 'normal 14px Arial,sans-serif';
    var TITLE_MAX  = 580; // px — Google truncates ~580px
    var DESC_MAX   = 920; // px — Google truncates ~920px

    function titlePx(text)  { return measureTextPx(text, TITLE_FONT); }
    function descPx(text)   { return measureTextPx(text, DESC_FONT); }

    function pxClass(px, max) {
        if (px === 0) return 'rr-mm-char-missing';
        if (px <= max) return 'rr-mm-char-ok';
        return 'rr-mm-char-over';
    }
    function pxLabel(px, max) {
        if (px === 0) return 'Ontbreekt ✗';
        return px + 'px' + (px <= max ? ' ✓' : ' — te lang');
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

    // Bulk button: toon totaal gefilterd aantal (alle pagina's, niet alleen zichtbare)
    function updateBulkBtn() {
        var n = (rrMetaManager.allFilteredIds && rrMetaManager.allFilteredIds.length)
            ? rrMetaManager.allFilteredIds.length
            : $('.rr-mm-page-row').length;
        $('#rr-gemini-bulk').find('.rr-bulk-count').text(n);
    }
    updateBulkBtn();

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

    function rrShowBulkReview(results, errors, fields) {
        errors = errors || [];
        fields = (fields && fields.length) ? fields : ['title','desc'];
        var wantTitle = fields.indexOf('title') !== -1;
        var wantDesc  = fields.indexOf('desc')  !== -1;
        var $overlay = $('<div id="rr-bulk-overlay">');
        var $modal   = $('<div id="rr-bulk-modal">');

        // Header
        var headerCount = results.length + ' gelukt' + (errors.length ? ' · ' + errors.length + ' mislukt' : '');
        var $header = $('<div class="rr-bulk-header">')
            .append($('<h2 class="rr-bulk-title">').text('AI-suggesties controleren'))
            .append($('<span class="rr-bulk-count">').text(headerCount))
            .append($('<button type="button" class="rr-bulk-close-btn" id="rr-bulk-close">').html('&times;'));

        // Body — one card per result
        var $body = $('<div class="rr-bulk-body">');

        // Errors banner
        if (errors.length) {
            var $errWrap = $('<details class="rr-bulk-errors">');
            $errWrap.append(
                $('<summary>').text('⚠ ' + errors.length + ' pagina\'s mislukt — klik voor details')
            );
            var $errList = $('<ul class="rr-bulk-err-list">');
            $.each(errors, function(_, err) {
                $errList.append(
                    $('<li>').append(
                        $('<span class="rr-bulk-err-name">').text(err.name || ('Pagina ' + err.id)),
                        $('<span class="rr-bulk-err-reason">').text(err.reason || 'Onbekende fout')
                    )
                );
            });
            $errWrap.append($errList);
            $body.append($errWrap);
        }

        // Tel dubbele AI-suggesties binnen deze batch — deze leveren nieuwe duplicaten op
        var titleCounts = {};
        var descCounts  = {};
        $.each(results, function(_, item) {
            if (wantTitle && item.title) titleCounts[item.title] = (titleCounts[item.title] || 0) + 1;
            if (wantDesc  && item.description) descCounts[item.description] = (descCounts[item.description] || 0) + 1;
        });
        var dupTitleCount = 0, dupDescCount = 0;
        $.each(titleCounts, function(_, c) { if (c > 1) dupTitleCount += c; });
        $.each(descCounts,  function(_, c) { if (c > 1) dupDescCount  += c; });

        if (dupTitleCount || dupDescCount) {
            var dupMsg = '⚠ Identieke AI-suggesties gevonden';
            var parts = [];
            if (dupTitleCount) parts.push(dupTitleCount + ' pagina\'s met zelfde titel');
            if (dupDescCount)  parts.push(dupDescCount + ' pagina\'s met zelfde beschrijving');
            var $dupWarn = $('<div class="rr-bulk-dup-warn">')
                .append($('<strong>').text(dupMsg))
                .append($('<p>').text('Deze leveren nieuwe duplicaten op als je ze opslaat. ' + parts.join(' · ') + '. Pas ze aan of regenereer.'))
                .append($('<button type="button" class="rr-btn rr-btn-secondary rr-btn-sm button" id="rr-bulk-regen-dups">').text('↺ Alle dubbele suggesties opnieuw genereren'));
            $body.append($dupWarn);
        }

        $.each(results, function(_, item) {
            var name     = item.name         || ('Pagina ' + item.id);
            var curTitle = item.current_title || '';
            var curDesc  = item.current_desc  || '';

            var isDupTitle = wantTitle && item.title && titleCounts[item.title] > 1;
            var isDupDesc  = wantDesc  && item.description && descCounts[item.description] > 1;
            var cardClass  = 'rr-bulk-card' + ((isDupTitle || isDupDesc) ? ' rr-bulk-card--dup' : '');

            var $card = $('<div class="' + cardClass + '">').attr('data-id', item.id);
            $card.append($('<div class="rr-bulk-card-name">').text(name));
            if (isDupTitle || isDupDesc) {
                var dupLabels = [];
                if (isDupTitle) dupLabels.push('titel identiek aan ' + (titleCounts[item.title] - 1) + ' andere');
                if (isDupDesc)  dupLabels.push('beschrijving identiek aan ' + (descCounts[item.description] - 1) + ' andere');
                $card.append($('<div class="rr-bulk-card-dup">').text('⚠ ' + dupLabels.join(' · ')));
            }

            var $cols = $('<div class="rr-bulk-cols">');

            // Title column
            var curTitlePx = titlePx(curTitle);
            var $tc = $('<div class="rr-bulk-col">');
            $tc.append(
                $('<div class="rr-bulk-lbl-row">').append(
                    $('<label class="rr-bulk-lbl">').text('Huidige titel'),
                    $('<span class="rr-bulk-px ' + pxClass(curTitlePx, TITLE_MAX) + '">').text(pxLabel(curTitlePx, TITLE_MAX))
                )
            );
            $tc.append($('<div class="rr-bulk-cur">').text(curTitle || '(geen)'));

            if (wantTitle) {
                var aiTitlePx       = titlePx(item.title);
                var $aiTitlePxBadge = $('<span class="rr-bulk-px ' + pxClass(aiTitlePx, TITLE_MAX) + '">').text(pxLabel(aiTitlePx, TITLE_MAX));
                var $regenTitle     = $('<button type="button" class="rr-bulk-regen" title="Opnieuw genereren">').html('&#x21bb;');
                $tc.append(
                    $('<div class="rr-bulk-lbl-row">').append(
                        $('<label class="rr-bulk-lbl rr-bulk-lbl--ai">').text('AI-suggestie titel'),
                        $('<span class="rr-bulk-lbl-right">').append($aiTitlePxBadge, $regenTitle)
                    )
                );
                var $titleInput = $('<input type="text" class="rr-bulk-field-title">').val(item.title);
                $titleInput.on('input', function() {
                    var px = titlePx($(this).val());
                    $aiTitlePxBadge.text(pxLabel(px, TITLE_MAX)).attr('class', 'rr-bulk-px ' + pxClass(px, TITLE_MAX));
                });
                $tc.append($titleInput);

                $regenTitle.on('click', function() {
                    rrRegenerateField(item.id, 'title', $titleInput, $aiTitlePxBadge, $(this));
                });
            }

            // Description column
            var curDescPx = descPx(curDesc);
            var $dc = $('<div class="rr-bulk-col">');
            $dc.append(
                $('<div class="rr-bulk-lbl-row">').append(
                    $('<label class="rr-bulk-lbl">').text('Huidige beschrijving'),
                    $('<span class="rr-bulk-px ' + pxClass(curDescPx, DESC_MAX) + '">').text(pxLabel(curDescPx, DESC_MAX))
                )
            );
            $dc.append($('<div class="rr-bulk-cur">').text(curDesc || '(geen)'));

            if (wantDesc) {
                var aiDescPx       = descPx(item.description);
                var $aiDescPxBadge = $('<span class="rr-bulk-px ' + pxClass(aiDescPx, DESC_MAX) + '">').text(pxLabel(aiDescPx, DESC_MAX));
                var $regenDesc     = $('<button type="button" class="rr-bulk-regen" title="Opnieuw genereren">').html('&#x21bb;');
                $dc.append(
                    $('<div class="rr-bulk-lbl-row">').append(
                        $('<label class="rr-bulk-lbl rr-bulk-lbl--ai">').text('AI-suggestie beschrijving'),
                        $('<span class="rr-bulk-lbl-right">').append($aiDescPxBadge, $regenDesc)
                    )
                );
                var $descArea = $('<textarea class="rr-bulk-field-desc" rows="3">').val(item.description);
                $descArea.on('input', function() {
                    var px = descPx($(this).val());
                    $aiDescPxBadge.text(pxLabel(px, DESC_MAX)).attr('class', 'rr-bulk-px ' + pxClass(px, DESC_MAX));
                });
                $dc.append($descArea);

                $regenDesc.on('click', function() {
                    rrRegenerateField(item.id, 'desc', $descArea, $aiDescPxBadge, $(this));
                });
            }

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

        // Regenereer alle cards met dubbele AI-suggesties
        $overlay.on('click', '#rr-bulk-regen-dups', function() {
            var $dupBtn = $(this);
            var $dupCards = $overlay.find('.rr-bulk-card--dup');
            if (!$dupCards.length) return;
            $dupBtn.prop('disabled', true).text('Bezig...');
            var total = $dupCards.length;
            var done  = 0;

            $dupCards.each(function() {
                var $card = $(this);
                var id    = $card.data('id');
                var $titleInput = $card.find('.rr-bulk-field-title');
                var $descArea   = $card.find('.rr-bulk-field-desc');
                var $titleBadge = $card.find('.rr-bulk-col').eq(0).find('.rr-bulk-lbl-right .rr-bulk-px');
                var $descBadge  = $card.find('.rr-bulk-col').eq(1).find('.rr-bulk-lbl-right .rr-bulk-px');

                $.post(rrAdmin.ajaxUrl, {
                    action: 'rr_gemini_generate',
                    nonce:  rrAdmin.nonce,
                    id:     id
                }, function(response) {
                    if (response.success) {
                        if ($titleInput.length && response.data.title) {
                            $titleInput.val(response.data.title).trigger('input');
                            var tPx = titlePx(response.data.title);
                            $titleBadge.text(pxLabel(tPx, TITLE_MAX)).attr('class', 'rr-bulk-px ' + pxClass(tPx, TITLE_MAX));
                        }
                        if ($descArea.length && response.data.description) {
                            $descArea.val(response.data.description).trigger('input');
                            var dPx = descPx(response.data.description);
                            $descBadge.text(pxLabel(dPx, DESC_MAX)).attr('class', 'rr-bulk-px ' + pxClass(dPx, DESC_MAX));
                        }
                        $card.removeClass('rr-bulk-card--dup');
                        $card.find('.rr-bulk-card-dup').remove();
                    }
                }).always(function() {
                    done++;
                    $dupBtn.text('Bezig... (' + done + ' / ' + total + ')');
                    if (done === total) {
                        $dupBtn.prop('disabled', false).text('↺ Alle dubbele suggesties opnieuw genereren');
                        // Herbereken dup counts en verberg banner als geen dupes meer
                        if ($overlay.find('.rr-bulk-card--dup').length === 0) {
                            $overlay.find('.rr-bulk-dup-warn').fadeOut(300);
                        }
                    }
                });
            });
        });

        // Save all
        $overlay.on('click', '#rr-bulk-save', function() {
            var $saveBtn = $(this);
            var items = [];
            $overlay.find('.rr-bulk-card').each(function() {
                var $titleEl = $(this).find('.rr-bulk-field-title');
                var $descEl  = $(this).find('.rr-bulk-field-desc');
                var entry    = { id: $(this).data('id') };
                if ($titleEl.length) entry.title = $titleEl.val();
                if ($descEl.length)  entry.desc  = $descEl.val();
                items.push(entry);
            });

            $saveBtn.prop('disabled', true).text('Opslaan...');

            $.post(rrAdmin.ajaxUrl, {
                action: 'rr_save_bulk_meta',
                nonce:  rrAdmin.nonce,
                items:  items
            }, function(response) {
                if (response.success) {
                    $overlay.remove();
                    var activeFilter = getActiveFilter();

                    // Realtime: update stats + rows met verse server-data
                    if (response.data.stats) updateAllStats(response.data.stats);
                    if (response.data.items && response.data.items.length) {
                        $.each(response.data.items, function(_, itemData) {
                            applyRowUpdate(itemData, activeFilter);
                        });
                    }

                    // Update lokale row-data voor toekomstig gebruik
                    $.each(items, function(_, item) {
                        var $row = $('.rr-mm-page-row[data-id="' + item.id + '"]');
                        if (typeof item.title !== 'undefined') {
                            $row.attr('data-new-title', item.title).data('new-title', item.title);
                        }
                        if (typeof item.desc !== 'undefined') {
                            $row.attr('data-new-desc', item.desc).data('new-desc', item.desc);
                        }
                    });

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

    function rrRegenerateField(id, field, $input, $badge, $btn) {
        var max   = field === 'title' ? TITLE_MAX : DESC_MAX;
        var measure = field === 'title' ? titlePx : descPx;
        $btn.prop('disabled', true).addClass('rr-bulk-regen--loading');

        $.post(rrAdmin.ajaxUrl, {
            action: 'rr_gemini_generate',
            nonce:  rrAdmin.nonce,
            id:     id
        }, function(response) {
            $btn.prop('disabled', false).removeClass('rr-bulk-regen--loading');
            if (response.success) {
                var val = (field === 'title') ? response.data.title : response.data.description;
                $input.val(val).trigger('input');
                var px = measure(val);
                $badge.text(pxLabel(px, max)).attr('class', 'rr-bulk-px ' + pxClass(px, max));
            } else {
                var msg = (response.data && response.data.message) ? response.data.message : 'Fout bij genereren.';
                alert('AI fout: ' + msg);
            }
        }).fail(function() {
            $btn.prop('disabled', false).removeClass('rr-bulk-regen--loading');
            alert('Verbindingsfout.');
        });
    }

    // ==========================================
    // CSV upload + column mapping modal
    // ==========================================

    $('#rr-csv-upload-btn').on('click', function() {
        $('#rr-csv-upload-input').val('').trigger('click');
    });

    $('#rr-csv-upload-input').on('change', function() {
        var file = this.files[0];
        if (!file) return;

        var formData = new FormData();
        formData.append('action',   'rr_parse_upload_csv');
        formData.append('nonce',    rrAdmin.nonce);
        formData.append('csv_file', file);

        var $btn = $('#rr-csv-upload-btn');
        $btn.prop('disabled', true).text('Laden...');

        $.ajax({
            url:         rrAdmin.ajaxUrl,
            type:        'POST',
            data:        formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $btn.prop('disabled', false).html('<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> CSV uploaden');
                if (response.success) {
                    rrShowCsvMappingModal(response.data);
                } else {
                    rrShowToast('✗ ' + (response.data.message || 'Fout bij inlezen CSV.'), true);
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('CSV uploaden');
                rrShowToast('✗ Verbindingsfout.', true);
            }
        });
    });

    function rrShowCsvMappingModal(data) {
        var columns    = data.columns;
        var rows       = data.rows;
        var guessUrl   = data.guess_url   || '';
        var guessTitle = data.guess_title || '';
        var guessDesc  = data.guess_desc  || '';

        function colOptions(selected) {
            var opts = '<option value="">(niet gebruiken)</option>';
            columns.forEach(function(col) {
                opts += '<option value="' + escHtml(col) + '"' + (col === selected ? ' selected' : '') + '>' + escHtml(col) + '</option>';
            });
            return opts;
        }

        var previewRows = rows.slice(0, 5);
        var previewHtml = '<table class="rr-csv-preview-table"><thead><tr>' +
            columns.map(function(c) { return '<th>' + escHtml(c) + '</th>'; }).join('') +
            '</tr></thead><tbody>' +
            previewRows.map(function(row) {
                return '<tr>' + columns.map(function(c) {
                    return '<td>' + escHtml(row[c] || '') + '</td>';
                }).join('') + '</tr>';
            }).join('') +
            '</tbody></table>';

        var html =
            '<div id="rr-csv-overlay" class="rr-bulk-overlay">' +
            '<div id="rr-csv-modal" class="rr-bulk-modal" style="max-width:700px">' +
            '<div class="rr-bulk-header">' +
            '<h2 class="rr-bulk-title">CSV kolomkoppeling</h2>' +
            '<span style="font-size:12px;color:var(--rr-gray-500)">' + escHtml(data.total + ' rijen gevonden') + '</span>' +
            '<button type="button" class="rr-bulk-close-btn" id="rr-csv-close">&times;</button>' +
            '</div>' +
            '<div class="rr-bulk-body" style="padding:20px 24px">' +
            '<p style="font-size:13px;color:var(--rr-gray-600);margin:0 0 16px">Koppel de CSV-kolommen aan de juiste velden. De URL-kolom is verplicht om pagina\'s te matchen.</p>' +
            '<div class="rr-csv-map-grid">' +
            '<div class="rr-csv-map-row">' +
            '<label class="rr-csv-map-label">URL / Slug <span style="color:var(--rr-danger)">*</span></label>' +
            '<select id="rr-csv-col-url" class="rr-csv-map-select">' + colOptions(guessUrl) + '</select>' +
            '</div>' +
            '<div class="rr-csv-map-row">' +
            '<label class="rr-csv-map-label">Nieuwe meta titel</label>' +
            '<select id="rr-csv-col-title" class="rr-csv-map-select">' + colOptions(guessTitle) + '</select>' +
            '</div>' +
            '<div class="rr-csv-map-row">' +
            '<label class="rr-csv-map-label">Nieuwe meta omschrijving</label>' +
            '<select id="rr-csv-col-desc" class="rr-csv-map-select">' + colOptions(guessDesc) + '</select>' +
            '</div>' +
            '</div>' +
            '<div style="margin-top:20px">' +
            '<p style="font-size:11px;font-weight:600;color:var(--rr-gray-400);text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px">Voorbeeld (eerste 5 rijen)</p>' +
            '<div style="overflow-x:auto">' + previewHtml + '</div>' +
            '</div>' +
            '</div>' +
            '<div class="rr-bulk-footer">' +
            '<button type="button" class="rr-btn rr-btn-ghost rr-btn-sm button" id="rr-csv-cancel">Annuleren</button>' +
            '<button type="button" class="rr-btn rr-btn-primary rr-btn-sm button" id="rr-csv-apply">Toepassen op ' + data.total + ' pagina\'s</button>' +
            '</div>' +
            '</div></div>';

        $('body').append(html);

        $('#rr-csv-close, #rr-csv-cancel').on('click', function() {
            $('#rr-csv-overlay').remove();
        });

        $('#rr-csv-apply').on('click', function() {
            var colUrl   = $('#rr-csv-col-url').val();
            var colTitle = $('#rr-csv-col-title').val();
            var colDesc  = $('#rr-csv-col-desc').val();

            if (!colUrl) {
                rrShowToast('✗ Selecteer de URL-kolom.', true);
                return;
            }
            if (!colTitle && !colDesc) {
                rrShowToast('✗ Selecteer minstens één veld (titel of omschrijving).', true);
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Bezig...');

            $.post(rrAdmin.ajaxUrl, {
                action:    'rr_apply_upload_csv',
                nonce:     rrAdmin.nonce,
                col_url:   colUrl,
                col_title: colTitle,
                col_desc:  colDesc,
                rows:      JSON.stringify(rows)
            }, function(response) {
                $('#rr-csv-overlay').remove();
                if (response.success) {
                    var msg = '✓ ' + response.data.message;
                    if (response.data.not_found && response.data.not_found.length) {
                        msg += ' Niet gevonden: ' + response.data.not_found.join(', ');
                    }
                    rrShowToast(msg);
                } else {
                    rrShowToast('✗ ' + (response.data.message || 'Fout bij toepassen.'), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Toepassen');
                rrShowToast('✗ Verbindingsfout.', true);
            });
        });
    }

})(jQuery);
