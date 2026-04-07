<?php
/**
 * AJAX Handler Class
 * Handles all AJAX requests for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Ajax_Handler {

    public function __construct() {
        // PageSpeed
        add_action('wp_ajax_rr_run_pagespeed', [$this, 'run_pagespeed']);

        // Meta Manager
        add_action('wp_ajax_rr_fetch_h1s', [$this, 'fetch_h1s']);
        add_action('wp_ajax_rr_import_meta_csv', [$this, 'import_meta_csv']);
        add_action('wp_ajax_rr_scan_wordpress_meta', [$this, 'scan_wordpress_meta']);
        add_action('wp_ajax_rr_save_meta', [$this, 'save_meta']);
        add_action('wp_ajax_rr_apply_meta', [$this, 'apply_meta']);
        add_action('wp_ajax_rr_delete_meta', [$this, 'delete_meta']);
        add_action('wp_ajax_rr_clear_all_meta', [$this, 'clear_all_meta']);

        // SE Ranking API
        add_action('wp_ajax_rr_seranking_get_sites', [$this, 'seranking_get_sites']);
        add_action('wp_ajax_rr_seranking_import_pages', [$this, 'seranking_import_pages']);

        // Gemini AI
        add_action('wp_ajax_rr_gemini_generate',      [$this, 'gemini_generate']);
        add_action('wp_ajax_rr_gemini_generate_bulk', [$this, 'gemini_generate_bulk']);
        add_action('wp_ajax_rr_save_bulk_meta',        [$this, 'save_bulk_meta']);

        // CSV upload & mapping
        add_action('wp_ajax_rr_parse_upload_csv',  [$this, 'parse_upload_csv']);
        add_action('wp_ajax_rr_apply_upload_csv',  [$this, 'apply_upload_csv']);
    }

    private function verify_nonce() {
        if (!check_ajax_referer('rr_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Beveiligingscontrole mislukt.', 'rankrepair')]);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }
    }

    /**
     * Haal H1s op via een echte HTTP-request naar de frontend.
     * Als de loopback request mislukt, valt terug op server-side extractie
     * (post_content blocks + Elementor widgets).
     */
    public function fetch_h1s() {
        $this->verify_nonce();

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => 'Ongeldig post ID']);
        }

        $url = get_permalink($post_id);
        $h1s = [];

        // Probeer frontend HTML op te halen
        if ($url) {
            $response = wp_remote_get($url, [
                'timeout'    => 15,
                'user-agent' => 'RankRepair/1.0 H1-checker',
                'sslverify'  => apply_filters('https_local_ssl_verify', false),
                'blocking'   => true,
                'headers'    => ['X-RankRepair-Internal' => '1'],
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $html = wp_remote_retrieve_body($response);
                if (!empty($html)) {
                    preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m);
                    foreach ($m[1] as $text) {
                        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
                        if ($text !== '' && !in_array($text, $h1s, true)) {
                            $h1s[] = $text;
                        }
                    }
                    wp_send_json_success(['h1s' => $h1s, 'source' => 'frontend']);
                    return;
                }
            }
        }

        // Fallback: server-side extractie uit post_content + Elementor
        $h1s = $this->extract_h1s_from_post($post_id);
        wp_send_json_success(['h1s' => $h1s, 'source' => 'server']);
    }

    /** Server-side H1 extractie als fallback voor mislukte loopback requests */
    private function extract_h1s_from_post(int $post_id): array {
        $h1s    = [];
        $post   = get_post($post_id);
        if (!$post) return $h1s;

        // Gutenberg / Classic Editor
        if (!empty($post->post_content)) {
            $rendered = function_exists('do_blocks') ? do_blocks($post->post_content) : $post->post_content;
            preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $rendered, $m);
            foreach ($m[1] as $text) {
                $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
                if ($text !== '' && !in_array($text, $h1s, true)) $h1s[] = $text;
            }
        }

        // Elementor widgets
        $el_data = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($el_data)) {
            $this->extract_elementor_h1s_recursive(json_decode($el_data, true) ?: [], $h1s);
        }

        // ACF Flexible Content: 'content' layout 'hero' → sub-field 'title'
        // (thema rendert dit als <h1 class="hero__title">)
        if (empty($h1s) && function_exists('have_rows')) {
            $h1s = $this->extract_h1s_from_acf_hero($post_id);
        }

        // Laatste fallback: post_title
        if (empty($h1s) && !empty($post->post_title)) {
            $h1s[] = trim(wp_strip_all_tags($post->post_title));
        }

        return $h1s;
    }

    /** ACF Flexible Content 'hero' layout uitlezen voor het title sub-veld */
    private function extract_h1s_from_acf_hero(int $post_id): array {
        $h1s = [];
        if (!function_exists('have_rows') || !have_rows('content', $post_id)) {
            return $h1s;
        }
        while (have_rows('content', $post_id)) {
            the_row();
            if (get_row_layout() !== 'hero') continue;
            $title = get_sub_field('title');
            if (!empty($title)) {
                $text = trim(wp_strip_all_tags($title));
                if ($text !== '') $h1s[] = $text;
            }
        }
        return $h1s;
    }

    private function extract_elementor_h1s_recursive(array $elements, array &$h1s): void {
        foreach ($elements as $el) {
            if (!is_array($el)) continue;
            if (($el['elType'] ?? '') === 'widget' && ($el['widgetType'] ?? '') === 'heading' && strtolower($el['settings']['header_size'] ?? '') === 'h1') {
                $text = trim(wp_strip_all_tags($el['settings']['title'] ?? ''));
                if ($text !== '' && !in_array($text, $h1s, true)) $h1s[] = $text;
            }
            foreach (['elements', 'children'] as $key) {
                if (!empty($el[$key]) && is_array($el[$key])) {
                    $this->extract_elementor_h1s_recursive($el[$key], $h1s);
                }
            }
        }
    }

    // ==========================================
    // PageSpeed
    // ==========================================

    public function run_pagespeed() {
        $this->verify_nonce();

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : home_url('/');
        $strategy = isset($_POST['strategy']) ? sanitize_text_field($_POST['strategy']) : 'mobile';

        if (empty($url)) {
            wp_send_json_error(['message' => __('Geen URL opgegeven.', 'rankrepair')]);
        }

        $pagespeed = new RR_PageSpeed();
        $result = $pagespeed->analyze($url, $strategy);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'scores'        => $result['scores'],
            'opportunities' => $result['opportunities'],
            'diagnostics'   => $result['diagnostics'],
            'addon_mappings' => $result['addon_mappings'],
            'timestamp'     => $result['timestamp'],
        ]);
    }

    // ==========================================
    // Meta Manager
    // ==========================================

    /**
     * Import CSV/Excel met meta data uit SE Ranking
     */
    public function import_meta_csv() {
        $this->verify_nonce();

        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error(['message' => __('Geen bestand geüpload.', 'rankrepair')]);
        }

        $file = $_FILES['csv_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            wp_send_json_error(['message' => __('Alleen CSV en Excel bestanden zijn toegestaan.', 'rankrepair')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        // Probeer het bestand te openen
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error(['message' => __('Kan bestand niet openen.', 'rankrepair')]);
        }

        // Detecteer scheidingsteken: probeer eerst puntkomma, dan komma
        $first_line = fgets($handle);
        rewind($handle);

        $delimiter = (substr_count($first_line, ';') >= substr_count($first_line, ',')) ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            fclose($handle);
            wp_send_json_error(['message' => __('Kan header niet lezen uit bestand.', 'rankrepair')]);
        }

        // Normaliseer header namen
        $header = array_map(function($h) {
            return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h)));
        }, $header);

        $imported = 0;
        $all_titles = [];
        $all_descriptions = [];
        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) < 2) continue;

            // Zorg dat header en row dezelfde lengte hebben
            $row_count = min(count($header), count($row));
            $data = array_combine(array_slice($header, 0, $row_count), array_slice($row, 0, $row_count));

            // Flexibele kolomnamen herkenning (SE Ranking, Screaming Frog, Ahrefs, etc.)
            $url = $data['url'] ?? $data['page url'] ?? $data['address'] ?? $data['page'] ?? '';
            $title = $data['title'] ?? $data['meta title'] ?? $data['page title'] ?? $data['title 1'] ?? $data['title tag'] ?? '';
            $description = $data['description'] ?? $data['meta description'] ?? $data['description 1'] ?? $data['meta description 1'] ?? '';

            if (empty($url)) continue;

            $title_len = mb_strlen($title);
            $desc_len = mb_strlen($description);

            if (!empty($title)) {
                $all_titles[$title][] = $url;
            }
            if (!empty($description)) {
                $all_descriptions[$description][] = $url;
            }

            $rows[] = [
                'url'                => $url,
                'title'              => $title,
                'description'        => $description,
                'title_length'       => $title_len,
                'description_length' => $desc_len,
            ];
        }
        fclose($handle);

        // Detecteer duplicaten
        $dup_titles = array_filter($all_titles, function($urls) { return count($urls) > 1; });
        $dup_descs = array_filter($all_descriptions, function($urls) { return count($urls) > 1; });

        // Sla op in database
        foreach ($rows as $row_data) {
            $is_dup_title = isset($dup_titles[$row_data['title']]) ? 1 : 0;
            $is_dup_desc = isset($dup_descs[$row_data['description']]) ? 1 : 0;

            $wpdb->insert($table, [
                'url'                      => $row_data['url'],
                'current_title'            => $row_data['title'],
                'current_description'      => $row_data['description'],
                'title_length'             => $row_data['title_length'],
                'description_length'       => $row_data['description_length'],
                'is_duplicate_title'       => $is_dup_title,
                'is_duplicate_description' => $is_dup_desc,
                'status'                   => 'pending',
            ]);
            $imported++;
        }

        wp_send_json_success([
            'message'  => sprintf(
                __('%d pagina\'s geïmporteerd. %d dubbele titels, %d dubbele beschrijvingen gevonden.', 'rankrepair'),
                $imported, count($dup_titles), count($dup_descs)
            ),
            'imported'               => $imported,
            'duplicate_titles'       => count($dup_titles),
            'duplicate_descriptions' => count($dup_descs),
        ]);
    }

    /**
     * Sla nieuwe titel en beschrijving op
     */
    public function save_meta() {
        $this->verify_nonce();

        // data-id in de render is altijd de WordPress post_id (load_live_items zet 'id' => $raw->ID)
        $post_id         = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $new_title       = isset($_POST['new_title'])       ? sanitize_text_field($_POST['new_title'])           : '';
        $new_description = isset($_POST['new_description']) ? sanitize_textarea_field($_POST['new_description']) : '';
        $current_h1      = (isset($_POST['current_h1']) && $_POST['current_h1'] !== '')
                           ? sanitize_text_field($_POST['current_h1'])
                           : null;

        if (!$post_id) {
            wp_send_json_error(['message' => __('Ongeldig ID.', 'rankrepair')]);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        // H1 opslaan via ACF hero sub-field
        if ($current_h1 !== null && function_exists('have_rows') && have_rows('content', $post_id)) {
            while (have_rows('content', $post_id)) {
                the_row();
                if (get_row_layout() === 'hero') {
                    update_sub_field('title', $current_h1, $post_id);
                    break;
                }
            }
        }

        $data = [
            'new_title'       => $new_title,
            'new_description' => $new_description,
            'status'          => 'applied',
        ];
        if ($current_h1 !== null) {
            $data['current_h1'] = json_encode([$current_h1], JSON_UNESCAPED_UNICODE);
        }

        // Upsert op post_id
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE post_id = %d", $post_id));
        if ($existing) {
            $result = $wpdb->update($table, $data, ['post_id' => $post_id]);
        } else {
            $result = $wpdb->insert($table, array_merge($data, [
                'post_id' => $post_id,
                'url'     => get_permalink($post_id),
            ]));
        }

        if ($result === false) {
            wp_send_json_error(['message' => __('Fout bij opslaan: ' . $wpdb->last_error, 'rankrepair')]);
            return;
        }

        // Schrijf direct naar Yoast SEO / Rank Math zodat de live pagina direct bijgewerkt is
        // en de velden op reload de nieuwe waarden tonen (load_live_items leest live Yoast-data)
        if (!empty($new_title)) {
            if (defined('WPSEO_VERSION'))      update_post_meta($post_id, '_yoast_wpseo_title',    $new_title);
            if (defined('RANK_MATH_VERSION'))  update_post_meta($post_id, 'rank_math_title',        $new_title);
        }
        if (!empty($new_description)) {
            if (defined('WPSEO_VERSION'))      update_post_meta($post_id, '_yoast_wpseo_metadesc',  $new_description);
            if (defined('RANK_MATH_VERSION'))  update_post_meta($post_id, 'rank_math_description',  $new_description);
        }

        wp_send_json_success(['message' => __('Opgeslagen!', 'rankrepair')]);
    }

    /**
     * Pas meta data toe op de WordPress pagina
     * Werkt met Yoast SEO, Rank Math, of standaard WordPress
     */
    public function apply_meta() {
        $this->verify_nonce();

        $post_id         = isset($_POST['id'])              ? absint($_POST['id'])                               : 0;
        $new_title       = isset($_POST['new_title'])       ? sanitize_text_field($_POST['new_title'])           : '';
        $new_description = isset($_POST['new_description']) ? sanitize_textarea_field($_POST['new_description']) : '';

        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(['message' => __('Pagina niet gevonden.', 'rankrepair')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        // Sla veldwaarden op (upsert) en markeer als applied
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE post_id = %d", $post_id));
        if ($existing) {
            $wpdb->update($table, [
                'new_title'       => $new_title,
                'new_description' => $new_description,
                'status'          => 'applied',
            ], ['post_id' => $post_id]);
        } else {
            $wpdb->insert($table, [
                'post_id'         => $post_id,
                'url'             => get_permalink($post_id),
                'new_title'       => $new_title,
                'new_description' => $new_description,
                'status'          => 'applied',
            ]);
        }

        // Gebruik $new_title / $new_description direct uit $_POST
        $meta = ['new_title' => $new_title, 'new_description' => $new_description];

        $applied = false;

        // Titel toepassen
        if (!empty($meta['new_title'])) {
            // Yoast SEO
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_title', $meta['new_title']);
                $applied = true;
            }
            // Rank Math
            if (defined('RANK_MATH_VERSION')) {
                update_post_meta($post_id, 'rank_math_title', $meta['new_title']);
                $applied = true;
            }
            // Fallback: WordPress post title
            if (!$applied) {
                wp_update_post(['ID' => $post_id, 'post_title' => $meta['new_title']]);
                $applied = true;
            }
        }

        // Beschrijving toepassen
        if (!empty($meta['new_description'])) {
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta['new_description']);
            }
            if (defined('RANK_MATH_VERSION')) {
                update_post_meta($post_id, 'rank_math_description', $meta['new_description']);
            }
            // Altijd ook als custom field opslaan
            update_post_meta($post_id, '_rr_meta_description', $meta['new_description']);
        }

        wp_send_json_success(['message' => __('Meta data toegepast op de pagina!', 'rankrepair')]);
    }

    /**
     * Verwijder opgeslagen AI-data voor een post
     */
    public function delete_meta() {
        $this->verify_nonce();

        $post_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => __('Ongeldig ID.', 'rankrepair')]);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';
        $wpdb->delete($table, ['post_id' => $post_id]);

        wp_send_json_success(['message' => __('Verwijderd.', 'rankrepair')]);
    }

    /**
     * Wis alle meta data
     */
    public function clear_all_meta() {
        $this->verify_nonce();

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';
        $wpdb->query("TRUNCATE TABLE $table");

        wp_send_json_success(['message' => __('Alle meta data gewist.', 'rankrepair')]);
    }

    // ==========================================
    // WordPress Scan
    // ==========================================

    /**
     * Scan WordPress direct: leest Yoast meta uit postmeta
     * Geen API nodig — werkt altijd, altijd actueel
     */
    public function scan_wordpress_meta() {
        $this->verify_nonce();

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        // Haal alle gepubliceerde posts/pagina's/producten op met hun Yoast meta
        $post_types = apply_filters('rr_scan_post_types', ['post', 'page', 'product']);
        $post_types = array_filter(array_map('sanitize_key', $post_types));
        if (empty($post_types)) {
            $post_types = ['post', 'page', 'product'];
        }
        $post_type_in = "'" . implode("','", $post_types) . "'";

        $posts = $wpdb->get_results("
            SELECT
                p.ID,
                p.post_type,
                MAX(CASE WHEN pm.meta_key = '_yoast_wpseo_title'   THEN pm.meta_value END) as yoast_title,
                MAX(CASE WHEN pm.meta_key = '_yoast_wpseo_metadesc' THEN pm.meta_value END) as yoast_desc
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON p.ID = pm.post_id
                AND pm.meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc')
            WHERE p.post_status = 'publish'
              AND p.post_type IN ($post_type_in)
            GROUP BY p.ID
            ORDER BY p.post_type, p.ID ASC
        ");

        if (empty($posts)) {
            wp_send_json_error(['message' => __('Geen gepubliceerde pagina\'s gevonden.', 'rankrepair')]);
        }

        $rows        = [];
        $all_titles  = [];
        $all_descs   = [];

        foreach ($posts as $post) {
            $url     = get_permalink($post->ID);
            $title   = $post->yoast_title ?? '';
            $desc    = $post->yoast_desc  ?? '';
            $wp_post = get_post($post->ID);

            $h1s = $this->extract_h1s_from_post($post->ID);
            $h1  = json_encode($h1s, JSON_UNESCAPED_UNICODE);

            // Yoast slaat soms templates op zoals %%title%% — probeer die te resolven
            if (defined('WPSEO_VERSION') && class_exists('WPSEO_Replace_Vars')) {
                $replacer = new WPSEO_Replace_Vars();
                if ($wp_post) {
                    if (!empty($title)) {
                        $title = $replacer->replace($title, $wp_post);
                    }
                    if (!empty($desc)) {
                        $desc = $replacer->replace($desc, $wp_post);
                    }
                }
            }

            // Fallback: als Yoast geen expliciete beschrijving heeft, gebruik wat Yoast zelf zou genereren
            if (empty($desc) && $wp_post) {
                // Probeer eerst Yoast's eigen meta surface (Yoast v14+)
                if (function_exists('YoastSEO')) {
                    try {
                        $meta_surface = YoastSEO()->meta->for_post($post->ID);
                        if ($meta_surface && !empty($meta_surface->description)) {
                            $desc = $meta_surface->description;
                        }
                    } catch (Exception $e) {
                        // Yoast meta surface niet beschikbaar, ga door naar fallback
                    }
                }
                // Tweede fallback: gebruik het excerpt van de post
                if (empty($desc)) {
                    $excerpt = get_the_excerpt($post->ID);
                    if (!empty($excerpt)) {
                        $desc = $excerpt;
                    }
                }
            }

            if (!empty($title)) $all_titles[$title][] = $url;
            if (!empty($desc))  $all_descs[$desc][]   = $url;

            $rows[] = [
                'post_id'   => $post->ID,
                'url'       => $url,
                'h1'        => $h1,
                'title'     => $title,
                'desc'      => $desc,
                'title_len' => mb_strlen($title),
                'desc_len'  => mb_strlen($desc),
            ];
        }

        // Detecteer duplicaten
        $dup_titles = array_filter($all_titles, fn($u) => count($u) > 1);
        $dup_descs  = array_filter($all_descs,  fn($u) => count($u) > 1);

        // Leeg de tabel en vul opnieuw (transactioneel zodat geen data verloren gaat bij fouten)
        $wpdb->query('START TRANSACTION');
        $wpdb->query("DELETE FROM $table");

        $insert_ok = true;
        foreach ($rows as $row) {
            $res = $wpdb->insert($table, [
                'post_id'                  => $row['post_id'],
                'url'                      => $row['url'],
                'current_h1'               => $row['h1'],
                'current_title'            => $row['title'],
                'current_description'      => $row['desc'],
                'title_length'             => $row['title_len'],
                'description_length'       => $row['desc_len'],
                'is_duplicate_title'       => isset($dup_titles[$row['title']]) && !empty($row['title']) ? 1 : 0,
                'is_duplicate_description' => isset($dup_descs[$row['desc']])   && !empty($row['desc'])  ? 1 : 0,
                'status'                   => 'pending',
            ]);
            if ($res === false) {
                $insert_ok = false;
                break;
            }
        }

        if (!$insert_ok) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => __('Databasefout bij opslaan van scan resultaten.', 'rankrepair')]);
            return;
        }
        $wpdb->query('COMMIT');

        wp_send_json_success([
            'message' => sprintf(
                __('%d pagina\'s gescand uit WordPress. %d dubbele titels, %d dubbele beschrijvingen gevonden.', 'rankrepair'),
                count($rows), count($dup_titles), count($dup_descs)
            ),
            'scanned'                => count($rows),
            'duplicate_titles'       => count($dup_titles),
            'duplicate_descriptions' => count($dup_descs),
        ]);
    }

    // ==========================================
    // SE Ranking API
    // ==========================================

    private function seranking_request($endpoint) {
        $api_key = rr_decrypt_key(get_option('rr_seranking_api_key', ''));
        if (empty($api_key)) {
            return new WP_Error('no_key', __('Geen SE Ranking API key ingesteld. Ga naar Instellingen.', 'rankrepair'));
        }

        $response = wp_remote_get('https://api4.seranking.com/' . ltrim($endpoint, '/'), [
            'headers' => [
                'Authorization' => 'Token ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            return new WP_Error('unauthorized', __('Ongeldige SE Ranking API key.', 'rankrepair'));
        }
        if ($code !== 200) {
            $msg = $body['message'] ?? $body['detail'] ?? "HTTP $code";
            return new WP_Error('api_error', "SE Ranking API: $msg");
        }

        return $body;
    }

    /**
     * Haal lijst van SE Ranking sites/projecten op
     */
    public function seranking_get_sites() {
        $this->verify_nonce();

        $result = $this->seranking_request('/sites/');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Normaliseer: verwacht array van sites
        $sites = [];
        $data  = isset($result['results']) ? $result['results'] : (array) $result;
        foreach ($data as $site) {
            if (!isset($site['id'])) continue;
            $sites[] = [
                'id'  => $site['id'],
                'name' => $site['title'] ?? $site['domain'] ?? $site['url'] ?? 'Site ' . $site['id'],
            ];
        }

        if (empty($sites)) {
            wp_send_json_error(['message' => __('Geen sites gevonden in je SE Ranking account.', 'rankrepair')]);
        }

        wp_send_json_success(['sites' => $sites]);
    }

    /**
     * Importeer pagina-meta van een SE Ranking site audit
     */
    public function seranking_import_pages() {
        $this->verify_nonce();

        $site_id = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        if (!$site_id) {
            wp_send_json_error(['message' => __('Geen site geselecteerd.', 'rankrepair')]);
        }

        // SE Ranking on-page / audit pages endpoint
        $result = $this->seranking_request("/sites/{$site_id}/pages/");

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $data = isset($result['results']) ? $result['results'] : (array) $result;

        if (empty($data)) {
            wp_send_json_error(['message' => __('Geen pagina\'s gevonden. Zorg dat je een site audit hebt gedraaid in SE Ranking.', 'rankrepair')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        $rows       = [];
        $all_titles = [];
        $all_descs  = [];

        foreach ($data as $page) {
            $url   = $page['url']              ?? $page['page']        ?? '';
            $title = $page['title']            ?? $page['meta_title']  ?? '';
            $desc  = $page['description']      ?? $page['meta_description'] ?? '';

            if (empty($url)) continue;

            if (!empty($title)) $all_titles[$title][] = $url;
            if (!empty($desc))  $all_descs[$desc][]   = $url;

            $rows[] = [
                'url'       => $url,
                'title'     => $title,
                'desc'      => $desc,
                'title_len' => mb_strlen($title),
                'desc_len'  => mb_strlen($desc),
            ];
        }

        $dup_titles = array_filter($all_titles, fn($u) => count($u) > 1);
        $dup_descs  = array_filter($all_descs,  fn($u) => count($u) > 1);

        $wpdb->query('START TRANSACTION');
        $wpdb->query("DELETE FROM $table");

        $insert_ok = true;
        foreach ($rows as $row) {
            $res = $wpdb->insert($table, [
                'url'                      => $row['url'],
                'current_title'            => $row['title'],
                'current_description'      => $row['desc'],
                'title_length'             => $row['title_len'],
                'description_length'       => $row['desc_len'],
                'is_duplicate_title'       => isset($dup_titles[$row['title']]) && !empty($row['title']) ? 1 : 0,
                'is_duplicate_description' => isset($dup_descs[$row['desc']])   && !empty($row['desc'])  ? 1 : 0,
                'status'                   => 'pending',
            ]);
            if ($res === false) {
                $insert_ok = false;
                break;
            }
        }

        if (!$insert_ok) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => __('Databasefout bij opslaan van geïmporteerde pagina\'s.', 'rankrepair')]);
            return;
        }
        $wpdb->query('COMMIT');

        wp_send_json_success([
            'message' => sprintf(
                __('%d pagina\'s geïmporteerd via SE Ranking. %d dubbele titels, %d dubbele beschrijvingen gevonden.', 'rankrepair'),
                count($rows), count($dup_titles), count($dup_descs)
            ),
            'imported'               => count($rows),
            'duplicate_titles'       => count($dup_titles),
            'duplicate_descriptions' => count($dup_descs),
        ]);
    }
    // ==========================================
    // Gemini AI
    // ==========================================

    /**
     * Genereer meta titel en beschrijving via Gemini voor één rij
     */
    public function gemini_generate() {
        $this->verify_nonce();

        $post_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => __('Ongeldig ID.', 'rankrepair')]);
        }

        $api_key = rr_decrypt_key(get_option('rr_gemini_api_key', ''));
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('Geen Gemini API key ingesteld. Ga naar Instellingen.', 'rankrepair')]);
        }

        // Bouw meta-array live op uit WordPress — geen DB-afhankelijkheid
        $meta = $this->build_meta_from_post($post_id);
        if (!$meta) {
            wp_send_json_error(['message' => __('Pagina niet gevonden.', 'rankrepair')]);
        }

        $result = $this->call_gemini_for_meta($meta, $api_key);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'title'       => $result['title'],
            'description' => $result['description'],
        ]);
    }

    /**
     * Genereer meta voor meerdere rijen tegelijk (bulk).
     * Slaat NIETS op — retourneert alleen de AI-suggesties zodat de gebruiker
     * ze eerst kan controleren via het review-overzicht in de frontend.
     */
    public function gemini_generate_bulk() {
        $this->verify_nonce();

        $post_ids = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : [];
        if (empty($post_ids)) {
            wp_send_json_error(['message' => __('Geen rijen opgegeven.', 'rankrepair')]);
        }

        $api_key = rr_decrypt_key(get_option('rr_gemini_api_key', ''));
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('Geen Gemini API key ingesteld. Ga naar Instellingen.', 'rankrepair')]);
        }

        $results     = [];
        $errors      = [];
        $first_error = null;

        foreach ($post_ids as $post_id) {
            $meta = $this->build_meta_from_post($post_id);
            if (!$meta) {
                $errors[] = $post_id;
                continue;
            }
            $result = $this->call_gemini_for_meta($meta, $api_key);
            if (is_wp_error($result)) {
                if ($first_error === null) {
                    $first_error = $result->get_error_message();
                }
                $errors[] = $post_id;
            } else {
                $results[] = [
                    'id'            => $post_id,
                    'title'         => $result['title'],
                    'description'   => $result['description'],
                    'current_title' => $meta['current_title'] ?? '',
                    'current_desc'  => $meta['current_description'] ?? '',
                    'name'          => get_the_title($post_id) ?: $meta['url'] ?? ('Pagina ' . $post_id),
                ];
            }
            usleep(300000); // 300 ms throttle tussen API-calls
        }

        $message = sprintf(__('%d gegenereerd, %d mislukt.', 'rankrepair'), count($results), count($errors));
        if ($first_error) {
            $message .= ' Fout: ' . $first_error;
        }

        wp_send_json_success([
            'results'     => $results,
            'errors'      => $errors,
            'message'     => $message,
            'first_error' => $first_error,
        ]);
    }

    /**
     * Sla een bulk-set van bewerkte AI-suggesties op na de review-stap.
     * Verwacht $_POST['items'] = [ ['id'=>…,'title'=>…,'desc'=>…], … ]
     */
    public function save_bulk_meta() {
        $this->verify_nonce();

        $items = isset($_POST['items']) ? (array) $_POST['items'] : [];
        if (empty($items)) {
            wp_send_json_error(['message' => __('Geen items opgegeven.', 'rankrepair')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';
        $saved = 0;

        foreach ($items as $item) {
            $row_id = absint($item['id']    ?? 0);
            $title  = sanitize_text_field($item['title'] ?? '');
            $desc   = sanitize_textarea_field($item['desc']  ?? '');
            if (!$row_id) continue;

            $wpdb->update($table, [
                'new_title'       => $title,
                'new_description' => $desc,
                'status'          => 'updated',
            ], ['id' => $row_id]);
            $saved++;
        }

        wp_send_json_success([
            'saved'   => $saved,
            'message' => sprintf(_n('%d pagina opgeslagen.', '%d pagina\'s opgeslagen.', $saved, 'rankrepair'), $saved),
        ]);
    }

    /**
     * Bouw een meta-array op vanuit WordPress live data (vervangt DB-lookup)
     */
    private function build_meta_from_post(int $post_id): ?array {
        $wp_post = get_post($post_id);
        if (!$wp_post) return null;

        $url   = get_permalink($post_id);
        $title = get_post_meta($post_id, '_yoast_wpseo_title', true) ?? '';
        $desc  = get_post_meta($post_id, '_yoast_wpseo_metadesc', true) ?? '';

        if (defined('WPSEO_VERSION') && class_exists('WPSEO_Replace_Vars')) {
            $replacer = new WPSEO_Replace_Vars();
            if (!empty($title)) $title = $replacer->replace($title, $wp_post);
            if (!empty($desc))  $desc  = $replacer->replace($desc,  $wp_post);
        }

        if (empty($desc)) {
            if (function_exists('YoastSEO')) {
                try {
                    $ms = YoastSEO()->meta->for_post($post_id);
                    if ($ms && !empty($ms->description)) $desc = $ms->description;
                } catch (Exception $e) {}
            }
            if (empty($desc)) {
                $excerpt = get_the_excerpt($post_id);
                if (!empty($excerpt)) $desc = $excerpt;
            }
        }

        return [
            'post_id'             => $post_id,
            'url'                 => $url,
            'current_title'       => $title,
            'current_description' => $desc,
        ];
    }

    /**
     * Roep Gemini API aan voor één meta record
     */
    private function call_gemini_for_meta(array $meta, string $api_key) {
        $post_id      = (int) ($meta['post_id'] ?? 0);
        $post_content = '';
        $post_title   = '';
        $post_h1      = '';

        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                $post_title   = $post->post_title;
                $post_content = wp_strip_all_tags($post->post_content);
                $post_content = preg_replace('/\s+/', ' ', $post_content);
                $post_content = trim(mb_substr($post_content, 0, 800));

                if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $post->post_content, $h1_match)) {
                    $post_h1 = wp_strip_all_tags($h1_match[1]);
                }
            }
        }

        $url        = $meta['url'] ?? '';
        $brand_rule = 'GEEN bedrijfsnaam in de titel (Google verwijdert deze toch uit de SERP).';

        // Haal opgeslagen template op, of gebruik de standaard
        $stored = trim(get_option('rr_gemini_prompt', ''));
        $template = !empty($stored) ? $stored : rr_get_default_prompt_template();

        // Vervang {{placeholders}} met werkelijke waarden
        $h1_line           = !empty($post_h1)                    ? "H1: " . $post_h1 . "\n"                                            : (!empty($post_title) ? "Paginatitel: " . $post_title . "\n" : '');
        $current_desc_line  = !empty($meta['current_description']) ? "Huidige meta beschrijving: " . $meta['current_description'] . "\n" : '';
        $content_line       = !empty($post_content)               ? "Pagina-inhoud (fragment): " . $post_content . "\n"                 : '';

        // Strip de merknaam/separator uit de current_title zodat de AI de stijl niet kopieert.
        // Yoast lost %%title%% _ Merknaam op voordat het hier aankomt; zonder strippen bootst
        // de AI het " _ Merknaam"-suffix na ook al staat er in de prompt dat het niet mag.
        $current_title_context = $meta['current_title'] ?? '';
        if (!$allow_brand && !empty($current_title_context)) {
            $site_name = get_bloginfo('name');
            if (!empty($site_name)) {
                $current_title_context = preg_replace(
                    '/\s*[\|\-_–—]\s*' . preg_quote($site_name, '/') . '\s*$/iu',
                    '',
                    $current_title_context
                );
            }
        }
        $current_title_line = !empty($current_title_context) ? "Huidige meta titel: " . $current_title_context . "\n" : '';

        $prompt = str_replace(
            ['{{url}}', '{{brand_rule}}', '{{h1_line}}', '{{current_title_line}}', '{{current_desc_line}}', '{{content_line}}'],
            [$url, $brand_rule, $h1_line, $current_title_line, $current_desc_line, $content_line],
            $template
        );

        $provider = get_option('rr_ai_provider', 'google');
        $model    = trim(get_option('rr_ai_model', ''));

        if ($provider === 'openrouter') {
            // ── OpenRouter (OpenAI-compatibel) ──────────────────────────────────
            if (empty($model)) {
                $model = 'google/gemini-2.0-flash-001';
            }

            $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => get_bloginfo('name'),
                ],
                'body' => wp_json_encode([
                    'model'    => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.7,
                    'max_tokens'  => 300,
                    'plugins'     => [
                        ['id' => 'web', 'enabled' => false],
                    ],
                ]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('ai_request', $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $msg = $body['error']['message'] ?? "HTTP $code";
                return new WP_Error('ai_api', 'OpenRouter: ' . $msg);
            }

            $text = $body['choices'][0]['message']['content'] ?? '';

        } else {
            // ── Google AI Studio ────────────────────────────────────────────────
            if (empty($model)) {
                $model = 'gemini-1.5-flash';
            }

            $endpoint = add_query_arg('key', $api_key, 'https://generativelanguage.googleapis.com/v1/models/' . rawurlencode($model) . ':generateContent');

            $response = wp_remote_post($endpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode([
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.7,
                        'maxOutputTokens' => 300,
                    ],
                ]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('ai_request', $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $msg = $body['error']['message'] ?? "HTTP $code";
                return new WP_Error('ai_api', 'Gemini API: ' . $msg);
            }

            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        if (empty($text)) {
            return new WP_Error('gemini_empty', __('Gemini gaf geen resultaat terug.', 'rankrepair'));
        }

        // Parseer TITEL: en BESCHRIJVING: uit de response
        $title       = '';
        $description = '';

        // Titel: eerste regel na "TITEL:"
        if (preg_match('/TITEL:\s*(.+)/i', $text, $m)) {
            $title = trim(explode("\n", $m[1])[0]);
        }
        // Beschrijving: alles na "BESCHRIJVING:" t/m einde of volgende lege regel
        if (preg_match('/BESCHRIJVING:\s*(.+?)(?:\n\s*\n|$)/si', $text, $m)) {
            $description = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        if (empty($title) && empty($description)) {
            return new WP_Error('gemini_parse', __('Gemini response kon niet worden gelezen. Probeer het opnieuw.', 'rankrepair'));
        }

        return [
            'title'       => $title,
            'description' => $description,
        ];
    }
    /**
     * Stap 1: ontvang CSV-upload, parse kolommen + rijen terug naar JS
     */
    public function parse_upload_csv() {
        $this->verify_nonce();

        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('Bestand niet ontvangen of upload-fout.', 'rankrepair')]);
            return;
        }

        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        if (!$handle) {
            wp_send_json_error(['message' => __('Kan bestand niet lezen.', 'rankrepair')]);
            return;
        }

        // Lees eerste regel om delimiter te detecteren
        $first_line = fgets($handle);
        rewind($handle);

        // Strip UTF-8 BOM
        $first_line = ltrim($first_line, "\xEF\xBB\xBF");
        $delimiter  = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';

        // Lees header
        $raw_header = fgetcsv($handle, 0, $delimiter);
        if (!$raw_header) {
            fclose($handle);
            wp_send_json_error(['message' => __('Kan kolomnamen niet lezen.', 'rankrepair')]);
            return;
        }
        // Verwijder BOM en spaties
        $columns = array_map(fn($c) => trim(ltrim($c, "\xEF\xBB\xBF")), $raw_header);

        // Lees max 2000 rijen
        $rows = [];
        while (count($rows) < 2000 && ($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) === count($columns)) {
                $rows[] = array_combine($columns, $row);
            }
        }
        fclose($handle);

        // Auto-detect: welke kolom is waarschijnlijk de URL / nieuwe titel / nieuwe beschrijving?
        $guess_url   = '';
        $guess_title = '';
        $guess_desc  = '';
        foreach ($columns as $col) {
            $l = strtolower($col);
            if (!$guess_url   && preg_match('/(^url$|page url|address|pagina url|slug)/i', $col))  $guess_url = $col;
            if (!$guess_title && preg_match('/nieuwe.*(titel|title)|new.*(title|meta)/i', $col))     $guess_title = $col;
            if (!$guess_desc  && preg_match('/nieuwe.*(omschrijving|description)|new.*(desc)/i', $col)) $guess_desc = $col;
        }
        // Fallback voor URL
        if (!$guess_url) {
            foreach ($columns as $col) {
                if (preg_match('/(url|link|adres)/i', $col)) { $guess_url = $col; break; }
            }
        }

        wp_send_json_success([
            'columns'     => $columns,
            'rows'        => $rows,
            'guess_url'   => $guess_url,
            'guess_title' => $guess_title,
            'guess_desc'  => $guess_desc,
            'total'       => count($rows),
        ]);
    }

    /**
     * Stap 2: ontvang de mapping + rijen, pas toe op Yoast en DB
     */
    public function apply_upload_csv() {
        $this->verify_nonce();

        $col_url   = sanitize_text_field($_POST['col_url']   ?? '');
        $col_title = sanitize_text_field($_POST['col_title'] ?? '');
        $col_desc  = sanitize_text_field($_POST['col_desc']  ?? '');
        $rows_json = wp_unslash($_POST['rows'] ?? '[]');
        $rows      = json_decode($rows_json, true);

        if (empty($col_url) || !is_array($rows)) {
            wp_send_json_error(['message' => __('Ongeldige invoer.', 'rankrepair')]);
            return;
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'rr_meta_data';
        $applied = 0;
        $skipped = [];

        foreach ($rows as $row) {
            $raw_url   = trim($row[$col_url]   ?? '');
            $new_title = !empty($col_title) ? sanitize_text_field(trim($row[$col_title] ?? ''))        : '';
            $new_desc  = !empty($col_desc)  ? sanitize_textarea_field(trim($row[$col_desc]  ?? ''))   : '';

            if (empty($raw_url) || (empty($new_title) && empty($new_desc))) continue;

            // Probeer post te vinden via url_to_postid (werkt met volledige URL en pad)
            $post_id = url_to_postid($raw_url);

            // Fallback: voeg home_url toe als het een pad is
            if (!$post_id && str_starts_with($raw_url, '/')) {
                $post_id = url_to_postid(home_url($raw_url));
            }

            // Fallback: zoek op slug
            if (!$post_id) {
                $slug    = trim(basename(rtrim($raw_url, '/')));
                $post    = get_page_by_path($slug, OBJECT, ['post', 'page', 'product']);
                $post_id = $post ? $post->ID : 0;
            }

            if (!$post_id) {
                $skipped[] = $raw_url;
                continue;
            }

            // Schrijf naar Yoast SEO
            if (!empty($new_title)) update_post_meta($post_id, '_yoast_wpseo_title',   $new_title);
            if (!empty($new_desc))  update_post_meta($post_id, '_yoast_wpseo_metadesc', $new_desc);

            // Sla ook op in RR tabel (upsert)
            $data = array_filter([
                'new_title'       => $new_title ?: null,
                'new_description' => $new_desc  ?: null,
                'status'          => 'applied',
            ], fn($v) => $v !== null);

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE post_id = %d", $post_id));
            if ($existing) {
                $wpdb->update($table, $data, ['post_id' => $post_id]);
            } else {
                $wpdb->insert($table, array_merge($data, [
                    'post_id' => $post_id,
                    'url'     => get_permalink($post_id),
                ]));
            }

            $applied++;
        }

        wp_send_json_success([
            'applied' => $applied,
            'skipped' => count($skipped),
            'message' => sprintf(
                __('%d pagina\'s bijgewerkt, %d niet gevonden.', 'rankrepair'),
                $applied, count($skipped)
            ),
            'not_found' => array_slice($skipped, 0, 10), // max 10 tonen
        ]);
    }
}

// Initialiseer de AJAX handler
new RR_Ajax_Handler();
