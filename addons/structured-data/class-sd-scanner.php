<?php
/**
 * Structured Data Scanner — fetcht een sample pagina per type, parseert alle JSON-LD,
 * rendert elk schema als uitklapbaar blok met volledige property-tabel + validatie.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_SD_Scanner {

    /** Schema.org required/recommended velden per type (basis-set). */
    private const VALIDATION_RULES = [
        'Organization'    => ['required' => ['name', 'url'],                'recommended' => ['logo', 'sameAs', 'contactPoint']],
        'LocalBusiness'   => ['required' => ['name', 'address'],            'recommended' => ['telephone', 'openingHours']],
        'WebSite'         => ['required' => ['url', 'name'],                'recommended' => ['potentialAction']],
        'WebPage'         => ['required' => ['@id', 'url'],                 'recommended' => ['name', 'description', 'inLanguage']],
        'Product'         => ['required' => ['name'],                       'recommended' => ['image', 'description', 'offers', 'sku']],
        'Offer'           => ['required' => ['price', 'priceCurrency'],     'recommended' => ['availability', 'url']],
        'BreadcrumbList'  => ['required' => ['itemListElement'],            'recommended' => []],
        'FAQPage'         => ['required' => ['mainEntity'],                 'recommended' => []],
        'Article'         => ['required' => ['headline'],                   'recommended' => ['author', 'datePublished', 'image']],
        'AggregateRating' => ['required' => ['ratingValue', 'reviewCount'], 'recommended' => []],
    ];

    public function render(): void {
        $types    = $this->get_page_types();
        $selected = $_GET['sd_type'] ?? 'home';
        if (!isset($types[$selected])) $selected = array_key_first($types);
        $sample_url = $types[$selected]['url'] ?? home_url('/');
        $schemas    = $this->scan_url($sample_url);
        $totals     = $this->aggregate_totals($schemas);
        ?>
        <div class="wrap rr-wrap">
            <div class="rr-header">
                <div class="rr-header-content">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=rankrepair')); ?>" class="rr-back-link">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        <?php _e('Terug naar Dashboard', 'rankrepair'); ?>
                    </a>
                    <h1><?php _e('Structured Data', 'rankrepair'); ?></h1>
                    <p class="rr-subtitle"><?php _e('Inspecteer alle JSON-LD schemas per pagina-type.', 'rankrepair'); ?></p>
                </div>
            </div>

            <div class="rr-sd-scanner">
                <aside class="rr-sd-typelist">
                    <?php foreach ($types as $key => $info): ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'rankrepair-structured-data', 'sd_type' => $key], admin_url('admin.php'))); ?>"
                       class="rr-sd-type <?php echo $selected === $key ? 'active' : ''; ?>">
                        <span class="rr-sd-type-label"><?php echo esc_html($info['label']); ?></span>
                        <?php if (!empty($info['count'])): ?>
                        <span class="rr-sd-type-count"><?php echo esc_html($info['count']); ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </aside>

                <div class="rr-sd-detail">
                    <div class="rr-sd-detail-head">
                        <h2><?php echo esc_html($types[$selected]['label'] ?? $selected); ?></h2>
                        <p class="rr-sd-sample">
                            <?php _e('Sample URL:', 'rankrepair'); ?>
                            <a href="<?php echo esc_url($sample_url); ?>" target="_blank"><?php echo esc_html($sample_url); ?></a>
                        </p>
                        <div class="rr-sd-totals">
                            <span class="rr-sd-total"><strong><?php echo count($schemas); ?></strong> <?php _e('schemas', 'rankrepair'); ?></span>
                            <span class="rr-sd-total rr-sd-total--danger"><strong><?php echo (int) $totals['errors']; ?></strong> <?php _e('FOUTEN', 'rankrepair'); ?></span>
                            <span class="rr-sd-total rr-sd-total--warning"><strong><?php echo (int) $totals['warnings']; ?></strong> <?php _e('WAARSCHUWINGEN', 'rankrepair'); ?></span>
                            <?php $suggested = $this->suggested_schemas_for($selected, $schemas); ?>
                            <?php if (!empty($suggested)): ?>
                            <span class="rr-sd-total rr-sd-total--add"><strong>+<?php echo count($suggested); ?></strong> <?php _e('aan te vullen', 'rankrepair'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($suggested)): ?>
                    <div class="rr-sd-suggest">
                        <h3><?php _e('Wat RankRepair kan aanvullen', 'rankrepair'); ?></h3>
                        <ul>
                            <?php foreach ($suggested as $s): ?>
                            <li><span class="rr-sd-plus">+</span> <strong><?php echo esc_html($s['type']); ?></strong> <span class="rr-sd-note"><?php echo esc_html($s['note']); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=rankrepair-settings')); ?>" class="button button-secondary">
                            <?php _e('Configureer in Instellingen →', 'rankrepair'); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($schemas)): ?>
                        <p class="rr-sd-empty"><?php _e('Geen JSON-LD gevonden op deze pagina.', 'rankrepair'); ?></p>
                    <?php else: ?>
                        <div class="rr-sd-schemas">
                            <?php foreach ($schemas as $schema): ?>
                                <?php $this->render_schema_block($schema); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render één schema als accordion-blok met volledige property-tabel.
     */
    private function render_schema_block(array $schema): void {
        $type  = $this->get_type_label($schema['data']['@type'] ?? '(Unknown)');
        $id    = $schema['data']['@id'] ?? '';
        $valid = $this->validate_schema($schema['data']);
        ?>
        <details class="rr-sd-schema">
            <summary class="rr-sd-schema-head">
                <span class="rr-sd-schema-chev">›</span>
                <strong class="rr-sd-schema-type"><?php echo esc_html($type); ?></strong>
                <span class="rr-sd-schema-counts">
                    <span class="rr-sd-count rr-sd-count--danger"><strong><?php echo count($valid['errors']); ?></strong> <?php _e('FOUTEN', 'rankrepair'); ?></span>
                    <span class="rr-sd-count rr-sd-count--warning"><strong><?php echo count($valid['warnings']); ?></strong> <?php _e('WAARSCHUWINGEN', 'rankrepair'); ?></span>
                </span>
                <?php if (!empty($schema['source'])): ?>
                <span class="rr-sd-schema-source"><?php echo esc_html($schema['source']); ?></span>
                <?php endif; ?>
            </summary>
            <div class="rr-sd-schema-body">
                <?php if ($id): ?>
                <p class="rr-sd-schema-id">ID: <?php echo esc_html($id); ?></p>
                <?php endif; ?>

                <?php if (!empty($valid['errors']) || !empty($valid['warnings'])): ?>
                <div class="rr-sd-issues">
                    <?php foreach ($valid['errors'] as $msg): ?>
                    <div class="rr-sd-issue rr-sd-issue--error">✗ <?php echo esc_html($msg); ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($valid['warnings'] as $msg): ?>
                    <div class="rr-sd-issue rr-sd-issue--warn">⚠ <?php echo esc_html($msg); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <table class="rr-sd-table">
                    <tbody>
                        <?php $this->render_data_rows($schema['data'], 0); ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php
    }

    /**
     * Recursief data renderen in tabel-rijen. Nested objects krijgen meer indent.
     */
    private function render_data_rows($data, int $depth): void {
        if (!is_array($data)) {
            echo '<tr><td colspan="2">' . esc_html((string) $data) . '</td></tr>';
            return;
        }
        foreach ($data as $key => $value) {
            $indent_style = 'padding-left:' . (12 + $depth * 16) . 'px';
            if (is_array($value)) {
                // Assoc array = nested object
                if ($this->is_assoc($value)) {
                    echo '<tr><td class="rr-sd-key" style="' . esc_attr($indent_style) . '">' . esc_html((string) $key) . '</td><td class="rr-sd-val rr-sd-val--nested"></td></tr>';
                    $this->render_data_rows($value, $depth + 1);
                } else {
                    // Indexed array van primitives of objects
                    $all_primitive = true;
                    foreach ($value as $v) { if (is_array($v)) { $all_primitive = false; break; } }
                    if ($all_primitive) {
                        echo '<tr><td class="rr-sd-key" style="' . esc_attr($indent_style) . '">' . esc_html((string) $key) . '</td><td class="rr-sd-val">' . esc_html(implode(', ', array_map(fn($v) => (string) $v, $value))) . '</td></tr>';
                    } else {
                        echo '<tr><td class="rr-sd-key" style="' . esc_attr($indent_style) . '">' . esc_html((string) $key) . '</td><td class="rr-sd-val rr-sd-val--nested">[' . count($value) . ' items]</td></tr>';
                        foreach ($value as $i => $item) {
                            echo '<tr><td class="rr-sd-key rr-sd-key--idx" style="padding-left:' . (12 + ($depth + 1) * 16) . 'px">' . esc_html('[' . $i . ']') . '</td><td></td></tr>';
                            $this->render_data_rows($item, $depth + 2);
                        }
                    }
                }
            } else {
                $display = (string) $value;
                if (mb_strlen($display) > 300) {
                    $display = mb_substr($display, 0, 300) . '…';
                }
                echo '<tr><td class="rr-sd-key" style="' . esc_attr($indent_style) . '">' . esc_html((string) $key) . '</td><td class="rr-sd-val">' . esc_html($display) . '</td></tr>';
            }
        }
    }

    private function is_assoc(array $arr): bool {
        if ($arr === []) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function get_type_label($type): string {
        if (is_array($type)) return implode(', ', $type);
        return (string) $type;
    }

    /**
     * Valideer een schema tegen basis-rules. Returns ['errors' => [], 'warnings' => []].
     */
    private function validate_schema(array $data): array {
        $errors   = [];
        $warnings = [];

        $type = $data['@type'] ?? null;
        if (!$type) return ['errors' => ['@type ontbreekt'], 'warnings' => []];

        $types = is_array($type) ? $type : [$type];
        foreach ($types as $t) {
            $rules = self::VALIDATION_RULES[$t] ?? null;
            if (!$rules) continue;

            foreach ($rules['required'] as $req) {
                if (!isset($data[$req]) || $data[$req] === '' || $data[$req] === []) {
                    $errors[] = sprintf(__('%s: verplicht veld "%s" ontbreekt', 'rankrepair'), $t, $req);
                }
            }
            foreach ($rules['recommended'] as $rec) {
                if (!isset($data[$rec]) || $data[$rec] === '' || $data[$rec] === []) {
                    $warnings[] = sprintf(__('%s: aanbevolen veld "%s" ontbreekt', 'rankrepair'), $t, $rec);
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function aggregate_totals(array $schemas): array {
        $errors = 0; $warnings = 0;
        foreach ($schemas as $s) {
            $v = $this->validate_schema($s['data']);
            $errors   += count($v['errors']);
            $warnings += count($v['warnings']);
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function get_page_types(): array {
        $types = [
            'home' => [
                'label' => __('Homepage', 'rankrepair'),
                'url'   => home_url('/'),
            ],
        ];

        $recent_post = get_posts(['post_type' => 'post', 'numberposts' => 1, 'post_status' => 'publish']);
        if ($recent_post) {
            $types['post'] = [
                'label' => __('Blog post', 'rankrepair'),
                'url'   => get_permalink($recent_post[0]->ID),
                'count' => (int) wp_count_posts('post')->publish,
            ];
        }

        $recent_page = get_posts(['post_type' => 'page', 'numberposts' => 1, 'post_status' => 'publish']);
        if ($recent_page) {
            $types['page'] = [
                'label' => __('Pagina', 'rankrepair'),
                'url'   => get_permalink($recent_page[0]->ID),
                'count' => (int) wp_count_posts('page')->publish,
            ];
        }

        if (post_type_exists('product')) {
            $recent_prod = get_posts(['post_type' => 'product', 'numberposts' => 1, 'post_status' => 'publish']);
            if ($recent_prod) {
                $types['product'] = [
                    'label' => __('Product', 'rankrepair'),
                    'url'   => get_permalink($recent_prod[0]->ID),
                    'count' => (int) wp_count_posts('product')->publish,
                ];
            }
            $prod_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1]);
            if (!is_wp_error($prod_cats) && !empty($prod_cats)) {
                $types['product_cat'] = [
                    'label' => __('Productcategorie', 'rankrepair'),
                    'url'   => get_term_link($prod_cats[0]),
                    'count' => count(get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids'])),
                ];
            }
        }

        $cats = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'number' => 1]);
        if (!is_wp_error($cats) && !empty($cats)) {
            $types['category'] = [
                'label' => __('Blog categorie', 'rankrepair'),
                'url'   => get_term_link($cats[0]),
                'count' => count(get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids'])),
            ];
        }

        return $types;
    }

    /**
     * Fetch URL en parse alle <script type="application/ld+json"> tags.
     * Returns: [['data' => array, 'source' => 'Yoast|Rank Math|RankRepair|...'], ...]
     */
    private function scan_url(string $url): array {
        $response = wp_remote_get($url, [
            'timeout'    => 15,
            'user-agent' => 'RankRepair/1.0 Structured-Data-Scanner',
            'sslverify'  => apply_filters('https_local_ssl_verify', false),
            'headers'    => ['X-RankRepair-Internal' => '1'],
        ]);
        if (is_wp_error($response)) return [];
        $html = wp_remote_retrieve_body($response);
        if (!$html) return [];

        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches, PREG_OFFSET_CAPTURE);

        $found = [];
        foreach ($matches[0] as $idx => $full_tag) {
            $offset    = $full_tag[1];
            $json_body = trim($matches[1][$idx][0]);
            $decoded   = json_decode($json_body, true);
            if (!is_array($decoded)) continue;

            // Source detection via HTML-context rond de tag
            $source  = $this->detect_source($html, $full_tag[0], $offset);

            // Flatten: als er een @graph is, elk graph-item wordt een los schema
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $entry) {
                    if (is_array($entry)) $found[] = ['data' => $entry, 'source' => $source];
                }
            } else {
                $found[] = ['data' => $decoded, 'source' => $source];
            }
        }
        return $found;
    }

    private function detect_source(string $html, string $tag, int $offset): string {
        // Zoek in ~200 chars vóór de tag naar bekende class-namen / comments
        $before = substr($html, max(0, $offset - 300), 300);
        if (stripos($before, 'yoast-schema-graph') !== false || stripos($before, 'yoast') !== false)    return 'Yoast SEO';
        if (stripos($before, 'rank-math') !== false || stripos($before, 'rankmath') !== false)          return 'Rank Math';
        if (stripos($before, 'RankRepair') !== false)                                                    return 'RankRepair';
        if (stripos($before, 'woocommerce') !== false)                                                   return 'WooCommerce';
        if (stripos($tag,    'yoast') !== false)                                                         return 'Yoast SEO';
        return '';
    }

    private function suggested_schemas_for(string $type, array $existing): array {
        $existing_types = [];
        foreach ($existing as $s) {
            $t = $s['data']['@type'] ?? '';
            if (is_array($t)) { foreach ($t as $tt) $existing_types[strtolower($tt)] = true; }
            else              { $existing_types[strtolower((string)$t)] = true; }
        }
        $has = fn(string $t) => isset($existing_types[strtolower($t)]);

        $out = [];
        if (!$has('Organization') && !$has('LocalBusiness') && get_option('rr_sd_schema_organization', '1') === '1') {
            $out[] = ['type' => 'Organization', 'note' => __('globaal — bedrijfsgegevens uit instellingen', 'rankrepair')];
        }
        if ($type === 'home' && !$has('WebSite') && get_option('rr_sd_schema_website', '1') === '1') {
            $out[] = ['type' => 'WebSite + SearchAction', 'note' => __('sitelink search box', 'rankrepair')];
        }
        if ($type !== 'home' && !$has('BreadcrumbList') && get_option('rr_sd_schema_breadcrumb', '1') === '1') {
            $out[] = ['type' => 'BreadcrumbList', 'note' => __('auto uit URL-hiërarchie', 'rankrepair')];
        }
        if ($type === 'product' && !$has('Product') && get_option('rr_sd_schema_product', '1') === '1') {
            $out[] = ['type' => 'Product + Offer', 'note' => __('uit WooCommerce data', 'rankrepair')];
        }
        if ($type === 'product_cat' && !$has('ProductGroup') && !$has('ItemList') && get_option('rr_sd_schema_productgroup', '1') === '1') {
            $out[] = ['type' => 'ProductGroup', 'note' => __('varianten uit sub-categorieën', 'rankrepair')];
        }
        if (in_array($type, ['post', 'page'], true) && !$has('FAQPage') && get_option('rr_sd_schema_faqpage', '1') === '1') {
            $out[] = ['type' => 'FAQPage', 'note' => __('indien FAQ-block aanwezig', 'rankrepair')];
        }
        return $out;
    }
}
