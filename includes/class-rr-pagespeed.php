<?php
/**
 * PageSpeed Integration Class
 * Handles Google PageSpeed Insights API communication
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_PageSpeed {

    const API_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo'];

    public function analyze($url, $strategy = 'mobile') {
        $api_key = get_option('rr_pagespeed_api_key', '');

        $params = [
            'url'      => $url,
            'strategy' => $strategy,
        ];

        foreach (self::CATEGORIES as $category) {
            $params['category'][] = $category;
        }

        if (!empty($api_key)) {
            $params['key'] = $api_key;
        }

        $request_url = add_query_arg($params, self::API_ENDPOINT);

        $response = wp_remote_get($request_url, [
            'timeout'   => 120,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || isset($data['error'])) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : __('Onbekende fout bij PageSpeed analyse', 'rankrepair');
            return new WP_Error('pagespeed_error', $error_msg);
        }

        $result = $this->parse_results($data, $url, $strategy);
        $this->store_results($result);

        return $result;
    }

    private function parse_results($data, $url, $strategy) {
        $lighthouse = isset($data['lighthouseResult']) ? $data['lighthouseResult'] : [];
        $categories = isset($lighthouse['categories']) ? $lighthouse['categories'] : [];
        $audits = isset($lighthouse['audits']) ? $lighthouse['audits'] : [];

        $scores = [];
        foreach (self::CATEGORIES as $cat) {
            $key = str_replace('-', '_', $cat);
            $scores[$key] = isset($categories[$cat]['score']) ? round($categories[$cat]['score'] * 100) : null;
        }

        // Opportunities (verbeterpunten)
        $opportunities = [];
        foreach ($audits as $audit_id => $audit) {
            if (isset($audit['details']['type']) && $audit['details']['type'] === 'opportunity' && isset($audit['score']) && $audit['score'] < 1) {
                $opportunities[] = [
                    'id'           => $audit_id,
                    'title'        => $audit['title'] ?? '',
                    'description'  => $audit['description'] ?? '',
                    'score'        => $audit['score'] ?? 0,
                    'savings'      => $audit['details']['overallSavingsMs'] ?? 0,
                    'displayValue' => $audit['displayValue'] ?? '',
                ];
            }
        }

        usort($opportunities, function($a, $b) {
            return $b['savings'] - $a['savings'];
        });

        // Diagnostics
        $diagnostics = [];
        foreach ($audits as $audit_id => $audit) {
            if (isset($audit['details']['type']) && $audit['details']['type'] === 'table' && isset($audit['score']) && $audit['score'] < 1) {
                $diagnostics[] = [
                    'id'           => $audit_id,
                    'title'        => $audit['title'] ?? '',
                    'description'  => $audit['description'] ?? '',
                    'score'        => $audit['score'] ?? 0,
                    'displayValue' => $audit['displayValue'] ?? '',
                ];
            }
        }

        $addon_mappings = $this->map_to_addons($opportunities, $diagnostics);

        return [
            'url'            => $url,
            'strategy'       => $strategy,
            'scores'         => $scores,
            'opportunities'  => $opportunities,
            'diagnostics'    => $diagnostics,
            'addon_mappings' => $addon_mappings,
            'raw_audits'     => $audits,
            'timestamp'      => current_time('mysql'),
        ];
    }

    private function map_to_addons($opportunities, $diagnostics) {
        $mappings = [];

        // Koppeling van audit IDs naar add-on slugs
        // Momenteel alleen meta-manager actief, later uitbreidbaar
        $addon_keywords = [
            'meta-manager' => [
                'document-title', 'meta-description', 'hreflang',
                'canonical', 'robots-txt', 'structured-data',
            ],
            // Toekomstige add-ons:
            // 'image-optimizer' => ['uses-optimized-images', 'offscreen-images', 'uses-webp-images', ...],
            // 'redirects-checker' => ['redirects', 'http-status-code'],
        ];

        $all_issues = array_merge($opportunities, $diagnostics);

        foreach ($all_issues as $issue) {
            foreach ($addon_keywords as $addon_slug => $keywords) {
                if (in_array($issue['id'], $keywords)) {
                    $mappings[] = [
                        'addon'    => $addon_slug,
                        'issue'    => $issue['title'],
                        'issue_id' => $issue['id'],
                        'score'    => $issue['score'],
                    ];
                }
            }
        }

        return $mappings;
    }

    private function store_results($result) {
        global $wpdb;
        $table = $wpdb->prefix . 'rr_pagespeed_results';

        $wpdb->insert($table, [
            'url'                  => $result['url'],
            'strategy'             => $result['strategy'],
            'score_performance'    => $result['scores']['performance'],
            'score_accessibility'  => $result['scores']['accessibility'],
            'score_best_practices' => $result['scores']['best_practices'],
            'score_seo'            => $result['scores']['seo'],
            'audits'               => wp_json_encode($result['raw_audits']),
            'opportunities'        => wp_json_encode($result['opportunities']),
            'diagnostics'          => wp_json_encode($result['diagnostics']),
            'created_at'           => $result['timestamp'],
        ]);
    }

    public function get_latest_results($url = null, $strategy = 'mobile') {
        global $wpdb;
        $table = $wpdb->prefix . 'rr_pagespeed_results';

        if ($url) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE url = %s AND strategy = %s ORDER BY created_at DESC LIMIT 1",
                $url, $strategy
            ), ARRAY_A);
        } else {
            $row = $wpdb->get_row(
                "SELECT * FROM $table ORDER BY created_at DESC LIMIT 1",
                ARRAY_A
            );
        }

        if ($row) {
            $row['opportunities'] = json_decode($row['opportunities'], true);
            $row['diagnostics'] = json_decode($row['diagnostics'], true);
        }

        return $row;
    }

    public function get_results_history($limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'rr_pagespeed_results';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, url, strategy, score_performance, score_accessibility, score_best_practices, score_seo, created_at
             FROM $table ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    public static function get_score_class($score) {
        if ($score === null) return 'rr-score-unknown';
        if ($score >= 90) return 'rr-score-good';
        if ($score >= 50) return 'rr-score-average';
        return 'rr-score-poor';
    }

    public static function get_score_label($score) {
        if ($score === null) return __('Onbekend', 'rankrepair');
        if ($score >= 90) return __('Goed', 'rankrepair');
        if ($score >= 50) return __('Gemiddeld', 'rankrepair');
        return __('Slecht', 'rankrepair');
    }
}
