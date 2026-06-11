<?php
/**
 * RankRepair – Display-Size Scanner
 *
 * Detecteert afbeeldingen waarvan de natuurlijke (bestands-)resolutie veel
 * groter is dan de gerenderde grootte op de pagina. Lighthouse flags deze
 * onder "Improve image delivery" / "properly sized images".
 *
 * Server-side parsing — geen browser nodig. Werkt via wp_remote_get + DOM-
 * parsing van de HTML van publieke pagina's. Voor displayed width heuristics
 * gebruiken we (in volgorde): width-attribuut, inline style, sizes-hint.
 */

if (!defined('ABSPATH')) exit;

class RR_Display_Scanner {

    /**
     * Minimum ratio (natural / displayed) waarboven we een image flaggen.
     * 2.0 = retina-marge, daarboven echt te groot.
     */
    const FLAG_RATIO = 2.5;

    /**
     * Scan één URL, retourneer array van image-records met natural vs displayed.
     */
    public function scan_url($url) {
        $url = esc_url_raw($url);
        if (empty($url)) return [];

        $resp = wp_remote_get($url, [
            'timeout'     => 30,
            'redirection' => 3,
            'user-agent'  => 'RankRepair Display Scanner/1.0 (+WordPress)',
        ]);
        if (is_wp_error($resp)) return [];
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) return [];

        $html = wp_remote_retrieve_body($resp);
        if (empty($html)) return [];

        return $this->parse_html_images($html, $url);
    }

    /**
     * Scan meerdere URLs, aggregeer per attachment.
     */
    public function scan_urls(array $urls) {
        $all = [];
        foreach ($urls as $url) {
            $all = array_merge($all, $this->scan_url($url));
        }
        return $this->dedupe_and_aggregate($all);
    }

    /**
     * Parseer HTML, extraheer alle <img>-tags + bepaal displayed vs natural.
     */
    private function parse_html_images($html, $page_url) {
        $images = [];
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $img_nodes = $dom->getElementsByTagName('img');
        foreach ($img_nodes as $img) {
            // WP Rocket / Lazy-load plugins zetten een SVG-placeholder in `src`
            // en de echte URL in `data-lazy-src` / `data-src`. Fallback erop.
            $src = $img->getAttribute('src');
            if (empty($src) || strpos($src, 'data:') === 0) {
                $fallback = $img->getAttribute('data-lazy-src');
                if (empty($fallback)) $fallback = $img->getAttribute('data-src');
                if (empty($fallback)) continue;
                $src = $fallback;
            }

            $displayed_w = $this->extract_displayed_width($img);
            if ($displayed_w <= 0) continue;

            $attachment_id = $this->url_to_attachment_id($src);
            if (!$attachment_id) continue;

            $meta = wp_get_attachment_metadata($attachment_id);
            $natural_w = isset($meta['width']) ? (int) $meta['width'] : 0;
            $natural_h = isset($meta['height']) ? (int) $meta['height'] : 0;
            if ($natural_w <= 0) continue;

            $ratio = $natural_w / $displayed_w;
            if ($ratio < self::FLAG_RATIO) continue;

            $file_path = get_attached_file($attachment_id);
            $natural_bytes = (file_exists($file_path)) ? filesize($file_path) : 0;

            $images[] = [
                'attachment_id' => $attachment_id,
                'page_url'      => $page_url,
                'src'           => $src,
                'file_name'     => basename($src),
                'natural_w'     => $natural_w,
                'natural_h'     => $natural_h,
                'displayed_w'   => $displayed_w,
                'displayed_h'   => $natural_w > 0 ? (int) round($displayed_w * ($natural_h / $natural_w)) : 0,
                'ratio'         => round($ratio, 1),
                'natural_bytes' => $natural_bytes,
                'natural_size_text' => size_format($natural_bytes),
                // Aanbevolen target width: displayed * 2 voor retina, afgerond op 100px-stappen
                'target_w'      => $this->suggest_target_width($displayed_w),
            ];
        }
        return $images;
    }

    /**
     * Bepaal de gerenderde breedte uit HTML-attributen.
     * Probeer in volgorde: width="", inline style, sizes-hint.
     */
    private function extract_displayed_width(DOMElement $img) {
        // 1. inline CSS width / max-width — CSS overschrijft het HTML width-attribuut,
        //    dus dit MOET als eerste gechecked worden. Een img met width="1182" en
        //    style="width:95px" wordt op 95px gerenderd, niet 1182.
        $style = $img->getAttribute('style');
        if ($style) {
            if (preg_match('/(?<![-_a-z])width\s*:\s*(\d+(?:\.\d+)?)\s*px/i', $style, $m)) {
                return (int) round((float) $m[1]);
            }
            if (preg_match('/max-width\s*:\s*(\d+(?:\.\d+)?)\s*px/i', $style, $m)) {
                return (int) round((float) $m[1]);
            }
        }

        // 2. expliciete width attribuut (alleen als geen inline override is)
        $w = $img->getAttribute('width');
        if (is_numeric($w) && (int) $w > 0) {
            return (int) $w;
        }

        // 3. sizes-hint: pak de kleinste media-query waarde (vaak realistischer)
        //    NB: ook data-lazy-sizes als fallback voor lazy-load plugins.
        $sizes = $img->getAttribute('sizes');
        if (empty($sizes)) $sizes = $img->getAttribute('data-lazy-sizes');
        if ($sizes && preg_match_all('/(\d+)px/', $sizes, $m)) {
            $values = array_map('intval', $m[1]);
            return $values ? min($values) : 0;
        }

        return 0;
    }

    /**
     * Suggestie target-breedte: displayed × 2 (retina) afgerond op 100px stappen,
     * met minimum 200px.
     */
    private function suggest_target_width($displayed_w) {
        $target = max(200, (int) ceil(($displayed_w * 2) / 100) * 100);
        return $target;
    }

    /**
     * Map URL terug naar attachment-ID.
     * Strip eerst eventuele -300x150 size-suffix en query-params.
     */
    private function url_to_attachment_id($url) {
        $url = strtok($url, '?');
        $url = strtok($url, '#');

        // Strip resized-suffix (bv. ranking-masters-logo-768x370.png → ranking-masters-logo.png)
        $stripped = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url);

        $upload_dir = wp_upload_dir();
        $base_url = trailingslashit($upload_dir['baseurl']);
        $base_url_https = str_replace('http://', 'https://', $base_url);

        $relative = null;
        foreach ([$base_url, $base_url_https] as $b) {
            if (strpos($stripped, $b) === 0) {
                $relative = substr($stripped, strlen($b));
                break;
            }
        }
        if (!$relative) return 0;

        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
            $relative
        ));
        return $id;
    }

    /**
     * Dedupe per attachment_id, bewaar de grootste displayed (= meest forgiving),
     * verzamel alle pages waar de image voorkomt.
     */
    private function dedupe_and_aggregate(array $results) {
        $grouped = [];
        foreach ($results as $r) {
            $id = $r['attachment_id'];
            if (!isset($grouped[$id])) {
                $r['pages'] = [$r['page_url']];
                unset($r['page_url']);
                $grouped[$id] = $r;
                continue;
            }
            $grouped[$id]['pages'][] = $r['page_url'];
            if ($r['displayed_w'] > $grouped[$id]['displayed_w']) {
                $grouped[$id]['displayed_w'] = $r['displayed_w'];
                $grouped[$id]['displayed_h'] = $r['displayed_h'];
                $grouped[$id]['ratio']       = $r['ratio'];
                $grouped[$id]['target_w']    = $r['target_w'];
            }
        }
        // Sort: hoogste ratio eerst (= meeste winst potentieel)
        usort($grouped, function ($a, $b) {
            if ($b['natural_bytes'] === $a['natural_bytes']) return 0;
            return $b['natural_bytes'] <=> $a['natural_bytes'];
        });
        return array_values($grouped);
    }

    /**
     * Resize een attachment naar de gegeven target-breedte (height proportioneel).
     * Schrijft over het origineel en genereert subsizes opnieuw.
     */
    public function resize_attachment($attachment_id, $target_width) {
        $attachment_id = (int) $attachment_id;
        $target_width  = max(50, (int) $target_width);

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('not_found', __('Bestand niet gevonden.', 'rankrepair'));
        }

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) return $editor;

        $size = $editor->get_size();
        if ($size['width'] <= $target_width) {
            return ['status' => 'skipped', 'message' => __('Al kleiner dan target.', 'rankrepair')];
        }

        // Backup before destructive resize (via image-optimizer's backup dir)
        $backup_dir = dirname($file_path) . '/jic-backups/';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
            @file_put_contents($backup_dir . '.htaccess', 'Deny from all');
        }
        $backup_path = $backup_dir . basename($file_path);
        if (!file_exists($backup_path)) {
            @copy($file_path, $backup_path);
            update_post_meta($attachment_id, '_jic_backup_path', $backup_path);
        }

        $original_size = filesize($file_path);

        $target_height = (int) round(($size['height'] / $size['width']) * $target_width);
        $editor->resize($target_width, $target_height, false);
        $saved = $editor->save($file_path);
        if (is_wp_error($saved)) return $saved;

        // Regenereer attachment-metadata + subsizes
        if (function_exists('wp_create_image_subsizes')) {
            $new_meta = wp_create_image_subsizes($file_path, $attachment_id);
            wp_update_attachment_metadata($attachment_id, $new_meta);
        } else {
            $new_meta = wp_generate_attachment_metadata($attachment_id, $file_path);
            wp_update_attachment_metadata($attachment_id, $new_meta);
        }

        $new_size = filesize($file_path);
        return [
            'status'        => 'resized',
            'original_size' => $original_size,
            'new_size'      => $new_size,
            'saved'         => max(0, $original_size - $new_size),
            'new_width'     => $target_width,
            'new_height'    => $target_height,
            'message'       => sprintf(
                __('Verkleind: %s → %dx%dpx (%s)', 'rankrepair'),
                size_format($original_size),
                $target_width,
                $target_height,
                size_format($new_size)
            ),
        ];
    }
}
