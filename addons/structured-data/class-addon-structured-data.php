<?php
/**
 * Structured Data Add-on
 * Injects JSON-LD schemas on the front-end as a complement to existing SEO plugins (Yoast, Rank Math).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Addon_Structured_Data extends RR_Addon_Base {

    protected function init(): void {
        $this->slug        = 'structured-data';
        $this->name        = __('Structured Data', 'rankrepair');
        $this->description = __('Vul bestaande JSON-LD aan: Organization, BreadcrumbList, Product, FAQPage, ProductGroup.', 'rankrepair');
        $this->icon        = 'structured-data';

        add_filter('rr_settings_sections', [$this, 'register_settings_section']);
        add_action('wp_head',              [$this, 'inject_schema'], 100); // ná Yoast (priority 1)

        // Defensief: alleen requiren als bestanden daadwerkelijk bestaan,
        // zodat een halve sync niet tot een fatal error leidt.
        $builder_file = __DIR__ . '/class-sd-builder.php';
        $scanner_file = __DIR__ . '/class-sd-scanner.php';
        if (file_exists($builder_file)) require_once $builder_file;
        if (file_exists($scanner_file)) require_once $scanner_file;
    }

    public function get_stats(): array {
        return ['total' => 0, 'issues' => 0];
    }

    public function enqueue_assets($hook): void {
        if (strpos($hook, 'rankrepair-structured-data') === false) return;

        $css_path = RR_PLUGIN_DIR . 'addons/structured-data/structured-data.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'rr-structured-data',
                RR_PLUGIN_URL . 'addons/structured-data/structured-data.css',
                ['rr-admin-style'],
                filemtime($css_path) ?: RR_VERSION
            );
        }
    }

    public function register_settings_section(array $sections): array {
        $sections[] = [
            'id'          => 'structured-data',
            'title'       => __('Structured Data', 'rankrepair'),
            'icon'        => 'structured-data',
            'priority'    => 30,
            'description' => __('Organization-info en schema toggles voor JSON-LD injectie', 'rankrepair'),
            'fields'      => [
                [
                    'name'        => 'rr_sd_enabled',
                    'label'       => __('Injectie aan', 'rankrepair'),
                    'type'        => 'select',
                    'default'     => '1',
                    'options'     => ['1' => __('Ja — schemas toevoegen aan wp_head', 'rankrepair'), '0' => __('Nee — uit', 'rankrepair')],
                    'description' => __('Als dit uit staat wordt er niks geïnjecteerd, ongeacht de schema toggles hieronder.', 'rankrepair'),
                ],
                [
                    'name'    => 'rr_sd_org_name',
                    'label'   => __('Bedrijfsnaam', 'rankrepair'),
                    'type'    => 'text',
                    'default' => get_bloginfo('name'),
                ],
                [
                    'name'    => 'rr_sd_org_legal_name',
                    'label'   => __('Juridische naam', 'rankrepair'),
                    'type'    => 'text',
                    'description' => __('Bijv. "Sabé Verpakkingen BV". Laat leeg om bedrijfsnaam te gebruiken.', 'rankrepair'),
                ],
                [
                    'name'  => 'rr_sd_org_logo',
                    'label' => __('Logo URL', 'rankrepair'),
                    'type'  => 'text',
                    'description' => __('Volledige URL naar logo (SVG of PNG).', 'rankrepair'),
                ],
                [
                    'name'  => 'rr_sd_org_phone',
                    'label' => __('Telefoonnummer', 'rankrepair'),
                    'type'  => 'text',
                    'description' => __('Bijv. +31318520298 (internationaal formaat).', 'rankrepair'),
                ],
                [
                    'name'  => 'rr_sd_org_email',
                    'label' => __('E-mailadres', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'  => 'rr_sd_org_street',
                    'label' => __('Straat + nummer', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'  => 'rr_sd_org_postal',
                    'label' => __('Postcode', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'  => 'rr_sd_org_city',
                    'label' => __('Plaats', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'    => 'rr_sd_org_country',
                    'label'   => __('Landcode', 'rankrepair'),
                    'type'    => 'text',
                    'default' => 'NL',
                ],
                [
                    'name'        => 'rr_sd_org_kvk',
                    'label'       => __('KvK nummer', 'rankrepair'),
                    'type'        => 'text',
                    'description' => __('Optioneel. Wordt als identifier in Organization-schema gezet.', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_sd_org_founding',
                    'label'       => __('Opgericht in', 'rankrepair'),
                    'type'        => 'text',
                    'description' => __('Jaar (YYYY).', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_sd_org_social',
                    'label'       => __('Social links (sameAs)', 'rankrepair'),
                    'type'        => 'textarea',
                    'rows'        => 4,
                    'description' => __('Eén URL per regel. Bijv. Facebook, LinkedIn, Instagram profielen.', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_sd_rating_value',
                    'label'       => __('Gemiddelde beoordeling', 'rankrepair'),
                    'type'        => 'text',
                    'description' => __('Bijv. 4.8. Leeg = geen aggregateRating in schema.', 'rankrepair'),
                ],
                [
                    'name'  => 'rr_sd_rating_count',
                    'label' => __('Aantal beoordelingen', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'    => 'rr_sd_schemas',
                    'label'   => __('Schemas aan/uit', 'rankrepair'),
                    'type'    => 'checkbox_group',
                    'options' => [
                        'rr_sd_schema_organization' => [__('Organization', 'rankrepair'),   __('Op elke pagina — bedrijfsgegevens uit bovenstaande velden', 'rankrepair')],
                        'rr_sd_schema_website'      => [__('WebSite + SearchAction', 'rankrepair'), __('Alleen homepage — sitelink searchbox', 'rankrepair')],
                        'rr_sd_schema_breadcrumb'   => [__('BreadcrumbList', 'rankrepair'), __('Auto uit URL-hiërarchie / WC categorie-ouders', 'rankrepair')],
                        'rr_sd_schema_product'      => [__('Product + Offer', 'rankrepair'), __('WooCommerce productpagina\'s — prijs, voorraad, reviews', 'rankrepair')],
                        'rr_sd_schema_productgroup' => [__('ProductGroup', 'rankrepair'),   __('WooCommerce categorie-pagina\'s met sub-categorieën', 'rankrepair')],
                        'rr_sd_schema_faqpage'      => [__('FAQPage', 'rankrepair'),        __('Pagina\'s met Gutenberg FAQ-block of ACF "faqs" repeater', 'rankrepair')],
                    ],
                ],
            ],
        ];
        return $sections;
    }

    /**
     * Inject schemas in wp_head. Draait ná Yoast (priority 100) zodat we niet conflicteren.
     */
    public function inject_schema(): void {
        if (get_option('rr_sd_enabled', '1') !== '1') return;
        if (is_admin()) return;

        $graph = [];

        $context = $this->detect_context();
        $builder = new RR_SD_Builder();

        // Organization — op elke pagina (als aan)
        if (get_option('rr_sd_schema_organization', '1') === '1') {
            $org = $builder->build_organization($context);
            if ($org) $graph[] = $org;
        }

        // WebSite + SearchAction — alleen homepage
        if ($context['type'] === 'home' && get_option('rr_sd_schema_website', '1') === '1') {
            $graph[] = $builder->build_website();
        }

        // BreadcrumbList — overal behalve homepage
        if ($context['type'] !== 'home' && get_option('rr_sd_schema_breadcrumb', '1') === '1') {
            $bc = $builder->build_breadcrumbs($context);
            if ($bc) $graph[] = $bc;
        }

        // Product — WooCommerce single product
        if ($context['type'] === 'product' && get_option('rr_sd_schema_product', '1') === '1') {
            $product = $builder->build_product($context);
            if ($product) $graph[] = $product;
        }

        // ProductGroup — WC productcategorie (heeft children)
        if ($context['type'] === 'product_cat' && get_option('rr_sd_schema_productgroup', '1') === '1') {
            $pg = $builder->build_product_group($context);
            if ($pg) $graph[] = $pg;
        }

        // FAQPage — Gutenberg block of ACF repeater
        if (in_array($context['type'], ['post', 'page'], true) && get_option('rr_sd_schema_faqpage', '1') === '1') {
            $faq = $builder->build_faqpage($context);
            if ($faq) $graph[] = $faq;
        }

        if (empty($graph)) return;

        $output = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];

        echo "\n<!-- RankRepair Structured Data -->\n";
        echo '<script type="application/ld+json">' . wp_json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }

    /**
     * Detect welke pagina-context we nu renderen.
     */
    private function detect_context(): array {
        if (is_front_page() || is_home()) {
            return ['type' => 'home', 'url' => home_url('/')];
        }
        if (function_exists('is_product') && is_product()) {
            global $post;
            return ['type' => 'product', 'post_id' => $post->ID, 'url' => get_permalink($post->ID)];
        }
        if (function_exists('is_product_category') && is_product_category()) {
            $term = get_queried_object();
            return ['type' => 'product_cat', 'term_id' => $term->term_id, 'term' => $term, 'url' => get_term_link($term)];
        }
        if (is_tax() || is_category() || is_tag()) {
            $term = get_queried_object();
            return ['type' => 'tax', 'term_id' => $term->term_id, 'term' => $term, 'taxonomy' => $term->taxonomy, 'url' => get_term_link($term)];
        }
        if (is_singular('post')) {
            global $post;
            return ['type' => 'post', 'post_id' => $post->ID, 'url' => get_permalink($post->ID)];
        }
        if (is_page()) {
            global $post;
            return ['type' => 'page', 'post_id' => $post->ID, 'url' => get_permalink($post->ID)];
        }
        if (is_singular()) {
            global $post;
            return ['type' => 'singular', 'post_id' => $post->ID, 'url' => get_permalink($post->ID)];
        }
        return ['type' => 'other', 'url' => home_url(add_query_arg(null, null))];
    }

    public function render_page(): void {
        $scanner = new RR_SD_Scanner();
        $scanner->render();
    }
}

new RR_Addon_Structured_Data();
