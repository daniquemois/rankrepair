<?php
/**
 * Dashboard Class
 * Renders the main dashboard with overview and PageSpeed integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Dashboard {

    public function render() {
        $pagespeed   = new RR_PageSpeed();
        $latest      = $pagespeed->get_latest_results();
        $site_url    = home_url('/');
        $site_domain = wp_parse_url($site_url, PHP_URL_HOST);
        $addons      = RankRepair::get_instance()->get_addons();
        ?>
        <div class="wrap rr-wrap">

            <!-- ── Page Header ───────────────────────────── -->
            <div class="rr-page-header">
                <div class="rr-page-header-left">
                    <img src="<?php echo esc_url(RR_PLUGIN_URL . 'assets/images/logoRankrepair.svg'); ?>" class="rr-logo-img" alt="RankRepair" height="38">
                    <span class="rr-header-divider">—</span>
                    <div class="rr-plugin-meta">
                        <div class="rr-plugin-name-row">
                            <span class="rr-version-badge">v<?php echo esc_html(RR_VERSION); ?></span>
                        </div>
                        <p class="rr-plugin-domain"><?php echo esc_html($site_domain); ?></p>
                    </div>
                </div>
                <div class="rr-page-header-actions">
                    <a href="<?php echo admin_url('admin.php?page=rankrepair-settings'); ?>" class="rr-btn rr-btn-secondary button">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93A10 10 0 0 1 21 12a10 10 0 0 1-10 10A10 10 0 0 1 2 12 10 10 0 0 1 4.93 4.93"/></svg>
                        <?php _e('Instellingen', 'rankrepair'); ?>
                    </a>
                    <button id="rr-run-pagespeed" class="rr-btn rr-btn-primary button" data-url="<?php echo esc_attr($site_url); ?>">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.46-7.66L23 10"/></svg>
                        <?php _e('Scan uitvoeren', 'rankrepair'); ?>
                    </button>
                </div>
            </div>

            <?php if (!get_option('rr_pagespeed_api_key')): ?>
            <div class="rr-notice rr-notice-warning">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?php printf(
                    __('Voeg je Google PageSpeed API key toe in de %sinstellingen%s voor volledige functionaliteit.', 'rankrepair'),
                    '<a href="' . admin_url('admin.php?page=rankrepair-settings') . '">',
                    '</a>'
                ); ?>
            </div>
            <?php endif; ?>

            <!-- Loading state -->
            <div id="rr-pagespeed-loading" class="rr-loading-overlay" style="display:none;">
                <div class="rr-spinner"></div>
                <p style="color:var(--rr-gray-500);font-size:13px;margin:0"><?php _e('Website wordt geanalyseerd...', 'rankrepair'); ?></p>
            </div>

            <!-- ── Stats Row ─────────────────────────────── -->
            <div id="rr-stats-area">
                <?php if ($latest): ?>
                    <?php $this->render_stats_row($latest); ?>
                <?php else: ?>
                    <div class="rr-stats-row">
                        <div class="rr-health-card" style="justify-content:center;align-items:center;min-height:200px">
                            <p style="color:rgba(255,255,255,0.7);margin:0;font-size:13px;text-align:center"><?php _e('Klik op "Scan uitvoeren" om je eerste analyse te starten.', 'rankrepair'); ?></p>
                        </div>
                        <?php foreach (['SEO Score','Core Web Vitals','Paginasnelheid'] as $lbl): ?>
                        <div class="rr-score-card" style="min-height:160px;display:flex;align-items:center;justify-content:center">
                            <p style="color:var(--rr-gray-400);font-size:12px;margin:0"><?php echo esc_html($lbl); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Strategy / URL controls -->
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap">
                <div class="rr-strategy-toggle">
                    <label class="rr-toggle-label">
                        <input type="radio" name="rr_strategy" value="mobile" checked>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                        Mobile
                    </label>
                    <label class="rr-toggle-label">
                        <input type="radio" name="rr_strategy" value="desktop">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                        Desktop
                    </label>
                </div>
                <div class="rr-url-input" style="flex:1;max-width:480px">
                    <input type="url" id="rr-custom-url" class="regular-text"
                           placeholder="<?php echo esc_attr($site_url); ?>"
                           value="<?php echo esc_attr($site_url); ?>"
                           style="width:100%">
                </div>
            </div>

            <!-- ── Two-Column: Problems + Addons ─────────── -->
            <div class="rr-two-col">

                <!-- Left: Gevonden problemen -->
                <div class="rr-card" style="margin-bottom:0">
                    <div class="rr-card-header">
                        <div class="rr-card-header-left">
                            <h2 class="rr-card-title"><?php _e('Gevonden problemen', 'rankrepair'); ?></h2>
                            <p class="rr-card-subtitle"><?php _e('Gesorteerd op prioriteit', 'rankrepair'); ?></p>
                        </div>
                    </div>
                    <div id="rr-problems-list" class="rr-problems-list">
                        <?php if ($latest && !empty($latest['opportunities'])): ?>
                            <?php $this->render_problems($latest, $addons); ?>
                        <?php else: ?>
                            <div class="rr-empty-state rr-empty-state-small" style="padding:40px 20px;text-align:center;color:var(--rr-gray-400);font-size:13px">
                                <?php _e('Voer een scan uit om problemen te zien.', 'rankrepair'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Actieve add-ons -->
                <div class="rr-card" style="margin-bottom:0">
                    <div class="rr-card-header">
                        <div class="rr-card-header-left">
                            <h2 class="rr-card-title"><?php _e('Actieve add-ons', 'rankrepair'); ?></h2>
                            <p class="rr-card-subtitle"><?php echo count($addons); ?> <?php _e('geactiveerd', 'rankrepair'); ?></p>
                        </div>
                        <a href="<?php echo admin_url('admin.php?page=rankrepair&tab=addons'); ?>" class="rr-btn rr-btn-secondary button rr-btn-sm">
                            <?php _e('Beheer', 'rankrepair'); ?>
                        </a>
                    </div>
                    <div class="rr-addon-list">
                        <?php
                        $addon_icons = [
                            'meta-manager'      => ['icon' => '🤖', 'bg' => '#EDE9FE'],
                            'image-optimizer'   => ['icon' => '🖼️', 'bg' => '#D1FAE5'],
                            'redirects-checker' => ['icon' => '↪️', 'bg' => '#DBEAFE'],
                            'form-tester'       => ['icon' => '📋', 'bg' => '#FEF3C7'],
                        ];
                        foreach ($addons as $slug => $addon):
                            $stats   = $addon->get_stats();
                            $meta    = $addon_icons[$slug] ?? ['icon' => '⚙️', 'bg' => '#F3F4F6'];
                            if (!empty($stats['issues'])) {
                                $sub = $stats['issues'] . ' ' . __('problemen gevonden', 'rankrepair');
                            } elseif (!empty($stats['total'])) {
                                $sub = $stats['total'] . ' ' . __('pagina\'s', 'rankrepair');
                            } else {
                                $sub = __('Actief', 'rankrepair');
                            }
                        ?>
                        <div class="rr-addon-row">
                            <div class="rr-addon-row-icon" style="background:<?php echo esc_attr($meta['bg']); ?>"><?php echo $meta['icon']; ?></div>
                            <div class="rr-addon-row-info">
                                <p class="rr-addon-row-name"><?php echo esc_html($addon->get_name()); ?></p>
                                <p class="rr-addon-row-sub"><?php echo esc_html($sub); ?></p>
                            </div>
                            <label class="rr-toggle" title="<?php _e('Ingeschakeld', 'rankrepair'); ?>">
                                <input type="checkbox" checked disabled>
                                <span class="rr-toggle-slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>

                        <!-- Inactive placeholders -->
                        <?php
                        $coming = [
                            ['name' => __('Internal Linking', 'rankrepair'), 'icon' => '🔗', 'bg' => '#F3F4F6'],
                            ['name' => __('Rank Tracker', 'rankrepair'),     'icon' => '📊', 'bg' => '#F3F4F6'],
                        ];
                        foreach ($coming as $c):
                        ?>
                        <div class="rr-addon-row" style="opacity:0.55">
                            <div class="rr-addon-row-icon" style="background:<?php echo esc_attr($c['bg']); ?>"><?php echo $c['icon']; ?></div>
                            <div class="rr-addon-row-info">
                                <p class="rr-addon-row-name"><?php echo esc_html($c['name']); ?></p>
                                <p class="rr-addon-row-sub"><?php _e('Niet geactiveerd', 'rankrepair'); ?></p>
                            </div>
                            <label class="rr-toggle">
                                <input type="checkbox" disabled>
                                <span class="rr-toggle-slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div><!-- /.rr-two-col -->

            <!-- ── Recent History ─────────────────────────── -->
            <?php
            $history = $pagespeed->get_results_history(5);
            if (!empty($history)):
            ?>
            <div class="rr-card">
                <div class="rr-card-header">
                    <h2 class="rr-card-title"><?php _e('Recente Analyses', 'rankrepair'); ?></h2>
                </div>
                <div class="rr-card-body rr-table-responsive" style="padding:0">
                    <table class="rr-table">
                        <thead>
                            <tr>
                                <th><?php _e('URL', 'rankrepair'); ?></th>
                                <th><?php _e('Strategie', 'rankrepair'); ?></th>
                                <th><?php _e('Performance', 'rankrepair'); ?></th>
                                <th><?php _e('SEO', 'rankrepair'); ?></th>
                                <th><?php _e('Toegankelijkheid', 'rankrepair'); ?></th>
                                <th><?php _e('Best Practices', 'rankrepair'); ?></th>
                                <th><?php _e('Datum', 'rankrepair'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): ?>
                            <tr>
                                <td><a href="<?php echo esc_url($row['url']); ?>" target="_blank"><?php echo esc_html(wp_trim_words($row['url'], 5)); ?></a></td>
                                <td><?php echo esc_html(ucfirst($row['strategy'])); ?></td>
                                <td><?php echo $this->score_badge($row['score_performance']); ?></td>
                                <td><?php echo $this->score_badge($row['score_seo']); ?></td>
                                <td><?php echo $this->score_badge($row['score_accessibility']); ?></td>
                                <td><?php echo $this->score_badge($row['score_best_practices']); ?></td>
                                <td style="color:var(--rr-gray-500);font-size:12px"><?php echo esc_html(date('d-m-Y H:i', strtotime($row['created_at']))); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    private function render_stats_row($result) {
        $perf  = $result['score_performance']    ?? null;
        $acc   = $result['score_accessibility']  ?? null;
        $bp    = $result['score_best_practices'] ?? null;
        $seo   = $result['score_seo']            ?? null;

        // Compute overall health score
        $scores_valid = array_filter([$perf, $acc, $bp, $seo], fn($s) => $s !== null);
        $health = count($scores_valid) ? round(array_sum($scores_valid) / count($scores_valid)) : 0;

        if ($health >= 90)      { $status = __('Uitstekend', 'rankrepair'); $desc = __('Je website presteert uitstekend op alle vlakken!', 'rankrepair'); }
        elseif ($health >= 70)  { $status = __('Verbetering nodig', 'rankrepair'); $desc = __('Je site heeft enkele problemen die je SEO en snelheid beïnvloeden.', 'rankrepair'); }
        elseif ($health >= 50)  { $status = __('Matige score', 'rankrepair'); $desc = __('Er zijn significante problemen die aandacht vereisen.', 'rankrepair'); }
        else                    { $status = __('Kritieke problemen', 'rankrepair'); $desc = __('Je website heeft kritieke problemen die dringend opgelost moeten worden.', 'rankrepair'); }

        $opps = $result['opportunities'] ?? [];
        $critical  = count(array_filter($opps, fn($o) => (($o['score'] ?? 1) * 100) < 50));
        $warnings  = count(array_filter($opps, fn($o) => (($o['score'] ?? 1) * 100) >= 50 && (($o['score'] ?? 1) * 100) < 90));

        $cards = [
            ['label' => __('SEO Score', 'rankrepair'),         'score' => $seo,   'icon_class' => 'rr-score-icon-green', 'icon' => '<path d="M11 20A7 7 0 1 1 9.8 6.8"/><polyline points="21 3 9 15 6 12"/>'],
            ['label' => __('Core Web Vitals', 'rankrepair'),   'score' => $acc,   'icon_class' => 'rr-score-icon-amber', 'icon' => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>'],
            ['label' => __('Paginasnelheid', 'rankrepair'),    'score' => $perf,  'icon_class' => 'rr-score-icon-red',   'icon' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'],
        ];

        ?>
        <div class="rr-stats-row">

            <!-- Health Card -->
            <div class="rr-health-card">
                <p class="rr-health-label"><?php _e('SITE GEZONDHEID', 'rankrepair'); ?></p>
                <div class="rr-health-score-row">
                    <div class="rr-health-circle">
                        <span class="rr-health-circle-score"><?php echo esc_html($health); ?></span>
                        <span class="rr-health-circle-denom">/100</span>
                    </div>
                    <div class="rr-health-text">
                        <p class="rr-health-status"><?php echo esc_html($status); ?></p>
                        <p class="rr-health-desc"><?php echo esc_html($desc); ?></p>
                    </div>
                </div>
                <div class="rr-health-badges">
                    <?php if ($critical > 0): ?>
                    <span class="rr-health-badge">
                        <span class="rr-health-dot" style="background:#F87171"></span>
                        <?php echo esc_html($critical); ?> <?php _e('kritiek', 'rankrepair'); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($warnings > 0): ?>
                    <span class="rr-health-badge">
                        <span class="rr-health-dot" style="background:#FBBF24"></span>
                        <?php echo esc_html($warnings); ?> <?php _e('waarschuwing', 'rankrepair'); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($critical === 0 && $warnings === 0): ?>
                    <span class="rr-health-badge">
                        <span class="rr-health-dot" style="background:#34D399"></span>
                        <?php _e('Alles OK', 'rankrepair'); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php foreach ($cards as $card):
                $s     = $card['score'];
                $class = $this->progress_class($s);
                $badge = $this->score_badge_class($s);
                $label = $this->score_label($s);
                $pct   = min(100, max(0, (int)($s ?? 0)));
            ?>
            <div class="rr-score-card">
                <div class="rr-score-card-top">
                    <div class="rr-score-icon <?php echo esc_attr($card['icon_class']); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $card['icon']; ?></svg>
                    </div>
                    <span class="rr-score-badge <?php echo esc_attr($badge); ?>"><?php echo esc_html($label); ?></span>
                </div>
                <div>
                    <p class="rr-score-number"><?php echo esc_html($s ?? '—'); ?></p>
                    <p class="rr-score-metric-label"><?php echo esc_html($card['label']); ?></p>
                </div>
                <div class="rr-progress-bar">
                    <div class="rr-progress-fill <?php echo esc_attr($class); ?>" style="width:<?php echo $pct; ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
        <?php
    }

    private function render_problems($result, $addons) {
        $opps = $result['opportunities'] ?? [];
        if (empty($opps)) {
            echo '<div style="padding:40px 20px;text-align:center;color:var(--rr-gray-400);font-size:13px">' . __('Geen problemen gevonden!', 'rankrepair') . '</div>';
            return;
        }

        $addon_map = [
            'document-title'   => 'meta-manager',
            'meta-description' => 'meta-manager',
            'hreflang'         => 'meta-manager',
            'canonical'        => 'meta-manager',
        ];

        $cat_map = [
            'meta-description' => ['label' => 'SEO',       'class' => 'rr-cat-seo'],
            'document-title'   => ['label' => 'SEO',       'class' => 'rr-cat-seo'],
            'hreflang'         => ['label' => 'SEO',       'class' => 'rr-cat-seo'],
            'canonical'        => ['label' => 'SEO',       'class' => 'rr-cat-seo'],
            'uses-optimized-images' => ['label' => 'Afbeelding', 'class' => 'rr-cat-image'],
            'uses-webp-images'      => ['label' => 'Afbeelding', 'class' => 'rr-cat-image'],
            'redirects'             => ['label' => 'Redirect',   'class' => 'rr-cat-redirect'],
        ];

        foreach ($opps as $opp) {
            $score_val  = ($opp['score'] ?? 1) * 100;
            $addon_slug = $addon_map[$opp['id']] ?? null;
            $cat        = $cat_map[$opp['id']]  ?? ['label' => 'Technisch', 'class' => 'rr-cat-neutral'];

            if ($score_val < 50)       $bar_class = 'rr-problem-bar-red';
            elseif ($score_val < 90)   $bar_class = 'rr-problem-bar-amber';
            else                       $bar_class = 'rr-problem-bar-indigo';

            $sub = '';
            if (!empty($opp['displayValue'])) $sub = $opp['displayValue'];
            elseif (!empty($opp['savings']))   $sub = sprintf(__('%s ms besparing mogelijk', 'rankrepair'), number_format($opp['savings']));
            ?>
            <div class="rr-problem-row">
                <div class="rr-problem-bar <?php echo esc_attr($bar_class); ?>"></div>
                <div class="rr-problem-content">
                    <p class="rr-problem-title"><?php echo esc_html($opp['title']); ?></p>
                    <?php if ($sub): ?>
                        <p class="rr-problem-sub"><?php echo esc_html($sub); ?></p>
                    <?php endif; ?>
                </div>
                <div class="rr-problem-meta">
                    <span class="rr-category-badge <?php echo esc_attr($cat['class']); ?>"><?php echo esc_html($cat['label']); ?></span>
                    <?php if ($addon_slug && isset($addons[$addon_slug])): ?>
                        <a href="<?php echo admin_url('admin.php?page=rankrepair-' . $addon_slug); ?>" class="rr-problem-link"><?php _e('Herstel →', 'rankrepair'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
    }

    private function progress_class($score) {
        if ($score === null) return 'rr-progress-poor';
        if ($score >= 90) return 'rr-progress-good';
        if ($score >= 50) return 'rr-progress-avg';
        return 'rr-progress-poor';
    }

    private function score_badge_class($score) {
        if ($score === null) return 'rr-score-badge-poor';
        if ($score >= 90) return 'rr-score-badge-good';
        if ($score >= 50) return 'rr-score-badge-avg';
        return 'rr-score-badge-poor';
    }

    private function score_label($score) {
        if ($score === null) return __('Onbekend', 'rankrepair');
        if ($score >= 90) return __('Goed', 'rankrepair');
        if ($score >= 50) return __('Matig', 'rankrepair');
        return __('Slecht', 'rankrepair');
    }

    private function score_badge($score) {
        $class = $this->score_badge_class($score);
        return '<span class="rr-badge ' . esc_attr($class) . '">' . esc_html($score ?? '—') . '</span>';
    }
}
