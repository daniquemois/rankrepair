/**
 * RankRepair - Main Admin Script
 */
(function($) {
    'use strict';

    // ==========================================
    // PageSpeed Analysis (Dashboard)
    // ==========================================

    $('#rr-run-pagespeed').on('click', function() {
        var $btn     = $(this);
        var url      = $('#rr-custom-url').val() || $btn.data('url');
        var strategy = $('input[name="rr_strategy"]:checked').val() || 'mobile';

        if (!url) {
            alert('Voer een URL in om te analyseren.');
            return;
        }

        $btn.prop('disabled', true).html(
            '<span class="rr-spin" style="display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,0.4);border-top-color:#fff;border-radius:50%;margin-right:6px"></span> ' +
            rrAdmin.strings.analyzing
        );
        $('#rr-pagespeed-loading').show();
        $('#rr-stats-area').hide();

        $.post(rrAdmin.ajaxUrl, {
            action:   'rr_run_pagespeed',
            nonce:    rrAdmin.nonce,
            url:      url,
            strategy: strategy
        }, function(response) {
            $btn.prop('disabled', false).html(
                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.46-7.66L23 10"/></svg> Scan uitvoeren'
            );
            $('#rr-pagespeed-loading').hide();
            $('#rr-stats-area').show();

            if (response.success) {
                var data = response.data;
                renderStatsRow(data.scores);
                renderProblems(data.opportunities, data.addon_mappings);
            } else {
                $('#rr-stats-area').html(
                    '<div class="rr-notice rr-notice-error">' +
                    escapeHtml(response.data.message) + '</div>'
                );
            }
        }).fail(function() {
            $btn.prop('disabled', false).html(
                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.46-7.66L23 10"/></svg> Scan uitvoeren'
            );
            $('#rr-pagespeed-loading').hide();
            $('#rr-stats-area').show().html(
                '<div class="rr-notice rr-notice-error">Analyse mislukt. Controleer je internetverbinding en API key.</div>'
            );
        });
    });

    function renderStatsRow(scores) {
        var perf  = scores.performance    || null;
        var acc   = scores.accessibility  || null;
        var bp    = scores.best_practices || null;
        var seo   = scores.seo            || null;

        var allScores = [perf, acc, bp, seo].filter(function(s) { return s !== null; });
        var health = allScores.length
            ? Math.round(allScores.reduce(function(a, b) { return a + b; }, 0) / allScores.length)
            : 0;

        var status, desc;
        if (health >= 90)     { status = 'Uitstekend';       desc = 'Je website presteert uitstekend op alle vlakken!'; }
        else if (health >= 70){ status = 'Verbetering nodig'; desc = 'Je site heeft enkele problemen die je SEO en snelheid beïnvloeden.'; }
        else if (health >= 50){ status = 'Matige score';      desc = 'Er zijn significante problemen die aandacht vereisen.'; }
        else                  { status = 'Kritieke problemen'; desc = 'Je website heeft kritieke problemen die dringend opgelost moeten worden.'; }

        var cards = [
            { label: 'SEO Score',       score: seo,  iconClass: 'rr-score-icon-green', icon: '<path d="M11 20A7 7 0 1 1 9.8 6.8"/><polyline points="21 3 9 15 6 12"/>' },
            { label: 'Core Web Vitals', score: acc,  iconClass: 'rr-score-icon-amber', icon: '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>' },
            { label: 'Paginasnelheid',  score: perf, iconClass: 'rr-score-icon-red',   icon: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>' }
        ];

        var html = '<div class="rr-stats-row">';

        // Health card
        html += '<div class="rr-health-card">';
        html += '<p class="rr-health-label">SITE GEZONDHEID</p>';
        html += '<div class="rr-health-score-row">';
        html += '<div class="rr-health-circle"><span class="rr-health-circle-score">' + health + '</span><span class="rr-health-circle-denom">/100</span></div>';
        html += '<div class="rr-health-text"><p class="rr-health-status">' + escapeHtml(status) + '</p><p class="rr-health-desc">' + escapeHtml(desc) + '</p></div>';
        html += '</div></div>';

        cards.forEach(function(c) {
            var s      = c.score;
            var pClass = progressClass(s);
            var bClass = scoreBadgeClass(s);
            var label  = scoreLabel(s);
            var pct    = Math.min(100, Math.max(0, s || 0));

            html += '<div class="rr-score-card">';
            html += '<div class="rr-score-card-top">';
            html += '<div class="rr-score-icon ' + c.iconClass + '"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + c.icon + '</svg></div>';
            html += '<span class="rr-score-badge ' + bClass + '">' + label + '</span>';
            html += '</div>';
            html += '<div><p class="rr-score-number">' + (s !== null ? s : '—') + '</p><p class="rr-score-metric-label">' + c.label + '</p></div>';
            html += '<div class="rr-progress-bar"><div class="rr-progress-fill ' + pClass + '" style="width:' + pct + '%"></div></div>';
            html += '</div>';
        });

        html += '</div>';
        $('#rr-stats-area').html(html);
    }

    function renderProblems(opportunities, addonMappings) {
        var $container = $('#rr-problems-list');
        $container.empty();

        if (!opportunities || opportunities.length === 0) {
            $container.html('<div style="padding:40px 20px;text-align:center;color:var(--rr-gray-400);font-size:13px">Geen problemen gevonden!</div>');
            return;
        }

        var addonMap = {};
        if (addonMappings) {
            addonMappings.forEach(function(m) { addonMap[m.issue_id] = m.addon; });
        }

        var catMap = {
            'meta-description':   { label: 'SEO',        cssClass: 'rr-cat-seo' },
            'document-title':     { label: 'SEO',        cssClass: 'rr-cat-seo' },
            'hreflang':           { label: 'SEO',        cssClass: 'rr-cat-seo' },
            'canonical':          { label: 'SEO',        cssClass: 'rr-cat-seo' },
            'uses-optimized-images': { label: 'Afbeelding', cssClass: 'rr-cat-image' },
            'uses-webp-images':   { label: 'Afbeelding', cssClass: 'rr-cat-image' },
            'redirects':          { label: 'Redirect',   cssClass: 'rr-cat-redirect' }
        };

        var adminUrl = rrAdmin.ajaxUrl.replace('/admin-ajax.php', '/admin.php');

        opportunities.forEach(function(opp) {
            var scoreVal  = (opp.score || 0) * 100;
            var addonSlug = addonMap[opp.id] || null;
            var cat       = catMap[opp.id] || { label: 'Technisch', cssClass: 'rr-cat-neutral' };

            var barClass;
            if (scoreVal < 50)      barClass = 'rr-problem-bar-red';
            else if (scoreVal < 90) barClass = 'rr-problem-bar-amber';
            else                    barClass = 'rr-problem-bar-indigo';

            var sub = '';
            if (opp.displayValue) sub = opp.displayValue;
            else if (opp.savings)  sub = Math.round(opp.savings) + ' ms besparing mogelijk';

            var actionHtml = '';
            if (addonSlug) {
                actionHtml = '<a href="' + adminUrl + '?page=rankrepair-' + addonSlug + '" class="rr-problem-link">Herstel →</a>';
            }

            var row = '<div class="rr-problem-row">' +
                '<div class="rr-problem-bar ' + barClass + '"></div>' +
                '<div class="rr-problem-content">' +
                    '<p class="rr-problem-title">' + escapeHtml(opp.title) + '</p>' +
                    (sub ? '<p class="rr-problem-sub">' + escapeHtml(sub) + '</p>' : '') +
                '</div>' +
                '<div class="rr-problem-meta">' +
                    '<span class="rr-category-badge ' + cat.cssClass + '">' + cat.label + '</span>' +
                    actionHtml +
                '</div>' +
            '</div>';

            $container.append(row);
        });
    }

    // Helpers
    function progressClass(score) {
        if (score === null || score === undefined) return 'rr-progress-poor';
        if (score >= 90) return 'rr-progress-good';
        if (score >= 50) return 'rr-progress-avg';
        return 'rr-progress-poor';
    }

    function scoreBadgeClass(score) {
        if (score === null || score === undefined) return 'rr-score-badge-poor';
        if (score >= 90) return 'rr-score-badge-good';
        if (score >= 50) return 'rr-score-badge-avg';
        return 'rr-score-badge-poor';
    }

    function scoreLabel(score) {
        if (score === null || score === undefined) return 'Onbekend';
        if (score >= 90) return 'Goed';
        if (score >= 50) return 'Matig';
        return 'Slecht';
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // ==========================================
    // General UI
    // ==========================================

    $('a[href^="#"]').on('click', function(e) {
        var target = $(this.getAttribute('href'));
        if (target.length) {
            e.preventDefault();
            $('html, body').animate({ scrollTop: target.offset().top - 50 }, 400);
        }
    });

    setTimeout(function() {
        $('.rr-notice-success').not('.rr-notice-persistent').fadeOut(500);
    }, 5000);

    // Add-on aan/uit toggle (Dashboard)
    $(document).on('change', '.rr-addon-toggle', function() {
        var $toggle  = $(this);
        var slug     = $toggle.data('slug');
        var enabled  = $toggle.is(':checked');
        var $row     = $toggle.closest('.rr-addon-row');
        var $sub     = $row.find('.rr-addon-row-sub');
        var wasText  = $sub.text();

        $toggle.prop('disabled', true);
        $sub.text(enabled ? 'Inschakelen...' : 'Uitschakelen...');

        $.post(rrAdmin.ajaxUrl, {
            action:  'rr_toggle_addon',
            nonce:   rrAdmin.nonce,
            slug:    slug,
            enabled: enabled ? '1' : '0'
        }, function(response) {
            $toggle.prop('disabled', false);
            if (response.success) {
                $row.toggleClass('rr-addon-row--off', !enabled);
                // Reload page om menu-items bij te werken
                window.location.reload();
            } else {
                $toggle.prop('checked', !enabled);
                $sub.text(wasText);
                alert((response.data && response.data.message) || 'Fout bij wisselen.');
            }
        }).fail(function() {
            $toggle.prop('disabled', false).prop('checked', !enabled);
            $sub.text(wasText);
            alert('Verbindingsfout.');
        });
    });

    // Settings: Reset AI prompt to default
    if (typeof rrSettings !== 'undefined') {
        var $resetBtn = document.getElementById('rr-reset-prompt');
        if ($resetBtn) {
            $resetBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm(rrSettings.confirmResetPrompt)) {
                    document.getElementById('rr_gemini_prompt').value = rrSettings.defaultPrompt;
                }
            });
        }
    }

    // Settings: Live preview paginatie suffix
    var $preview = document.getElementById('rr-pagination-preview');
    if ($preview) {
        function updatePaginationPreview() {
            var sep   = (document.querySelector('input[name="rr_pagination_sep"]:checked') || {}).value || '-';
            var label = (document.getElementById('rr_pagination_label') || {}).value || 'Pagina';
            $preview.textContent = 'Blog titel ' + sep + ' ' + label + ' 2';
        }
        document.querySelectorAll('input[name="rr_pagination_sep"]').forEach(function(el) {
            el.addEventListener('change', updatePaginationPreview);
        });
        var $labelInput = document.getElementById('rr_pagination_label');
        if ($labelInput) $labelInput.addEventListener('input', updatePaginationPreview);
    }

})(jQuery);
