<?php
/**
 * Template-substitutie voor custom JSON-LD per pagina-type.
 * Vervangt {{placeholders}} in pasted JSON met live data van de huidige pagina.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_SD_Template {

    /**
     * Apply placeholder substitution to a JSON string.
     *
     * Scalar placeholders worden simpel vervangen: {{home_url}} → string.
     * Array placeholders worden vervangen door JSON-encoded array, alleen
     * als ze als string staan (bv "itemListElement": "{{breadcrumb_items}}").
     */
    public function apply(string $json, array $context): string {
        if (trim($json) === '') return $json;

        // Stap 1: array tokens (vervang quoted token met JSON-array)
        foreach ($this->build_array_vars($context) as $key => $value) {
            $needle      = '"{{' . $key . '}}"';
            $replacement = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($replacement === false) continue;
            $json = str_replace($needle, $replacement, $json);
        }

        // Stap 2: scalar tokens (gewone string-replace, ook binnen langere strings)
        foreach ($this->build_scalar_vars($context) as $key => $value) {
            $json = str_replace('{{' . $key . '}}', (string) $value, $json);
        }

        return $json;
    }

    /**
     * Lijst met beschikbare placeholders voor de inspector hint.
     */
    public static function available_placeholders(): array {
        return [
            'site_name'         => 'Site naam (bv. "Sabé Verpakkingen")',
            'home_url'          => 'Homepage URL (bv. "https://sabe-verpakkingen.nl/")',
            'org_id'            => 'Organization @id (bv. "https://sabe-verpakkingen.nl/#organization")',
            'url'               => 'Huidige pagina URL',
            'name'              => 'Naam van huidige categorie/term/post',
            'slug'              => 'Slug van huidige term',
            'description'       => 'Omschrijving van huidige term',
            'group_id'          => 'ProductGroup @id (URL + #productgroup)',
            'breadcrumb_id'     => 'BreadcrumbList @id (URL + #breadcrumbs)',
            'breadcrumb_items'  => '[ARRAY] BreadcrumbList itemListElement — auto opgebouwd uit hiërarchie',
            'children_variants'      => '[ARRAY] sub-categorieën — basic (name/url/sku/image)',
            'children_variants_rich' => '[ARRAY] sub-categorieën met volledige Offer + Shipping + Return policy',
            'product_variants'       => '[ARRAY] WooCommerce producten met prijs (voor L3 leaf-categorieën)',
        ];
    }

    private function build_scalar_vars(array $context): array {
        $term = $context['term'] ?? null;
        $url  = $context['url'] ?? home_url('/');

        return [
            'site_name'     => get_bloginfo('name'),
            'home_url'      => trailingslashit(home_url('/')),
            'org_id'        => trailingslashit(home_url('/')) . '#organization',
            'url'           => $url,
            'name'          => $term ? $term->name : '',
            'slug'          => $term ? $term->slug : '',
            'description'   => $term ? wp_strip_all_tags($term->description ?: $term->name) : '',
            'group_id'      => trailingslashit($url) . '#productgroup',
            'breadcrumb_id' => trailingslashit($url) . '#breadcrumbs',
        ];
    }

    /**
     * Bouw alle array-vars op basis van context.
     */
    private function build_array_vars(array $context): array {
        $vars = [
            'breadcrumb_items'       => $this->build_breadcrumb_items($context),
            'children_variants'      => $this->build_children_variants($context),
            'children_variants_rich' => $this->build_children_variants_rich($context),
            'product_variants'       => $this->build_product_variants($context),
        ];
        return $vars;
    }

    private function build_breadcrumb_items(array $context): array {
        $items = [[
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => __('Home', 'rankrepair'),
            'item'     => trailingslashit(home_url('/')),
        ]];
        $pos = 2;

        $term = $context['term'] ?? null;
        if ($term && !empty($term->term_id)) {
            $ancestors = array_reverse(get_ancestors($term->term_id, $term->taxonomy));
            foreach ($ancestors as $anc_id) {
                $anc = get_term($anc_id, $term->taxonomy);
                if ($anc && !is_wp_error($anc)) {
                    $items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos++,
                        'name'     => $anc->name,
                        'item'     => get_term_link($anc),
                    ];
                }
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $term->name,
                'item'     => get_term_link($term),
            ];
        }
        return $items;
    }

    /**
     * Bouw hasVariant array van sub-categorieën (basic versie — alleen name/url/sku/image).
     */
    private function build_children_variants(array $context): array {
        return $this->build_children_variants_internal($context, false);
    }

    /**
     * Bouw hasVariant array met volledige Offer + ShippingDetails + ReturnPolicy.
     * Gebruik {{children_variants_rich}} in templates voor uitgebreide variant-data.
     */
    private function build_children_variants_rich(array $context): array {
        return $this->build_children_variants_internal($context, true);
    }

    private function build_children_variants_internal(array $context, bool $rich): array {
        $term = $context['term'] ?? null;
        if (!$term || empty($term->term_id)) return [];

        $children = get_terms([
            'taxonomy'   => $term->taxonomy,
            'parent'     => $term->term_id,
            'hide_empty' => false,
        ]);
        if (is_wp_error($children) || empty($children)) return [];

        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
        $country  = trim(get_option('rr_sd_org_country', 'NL')) ?: 'NL';
        $valid_until = date('Y-12-31', strtotime('+2 years'));

        $variants = [];
        foreach ($children as $child) {
            $thumb_id = (int) get_term_meta($child->term_id, 'thumbnail_id', true);
            $img      = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium') : '';
            $url      = get_term_link($child);

            $v = [
                '@type'       => 'Product',
                'name'        => $child->name,
                'description' => wp_strip_all_tags($child->description ?: $child->name),
                'url'         => $url,
                'sku'         => $child->slug,
            ];
            if ($img) $v['image'] = $img;

            if ($rich) {
                $v['offers'] = [
                    '@type'           => 'Offer',
                    'url'             => $url,
                    'priceCurrency'   => $currency,
                    'price'           => '0.00',
                    'availability'    => 'https://schema.org/InStock',
                    'priceValidUntil' => $valid_until,
                    'shippingDetails' => [
                        '@type' => 'OfferShippingDetails',
                        'shippingDestination' => [
                            '@type'          => 'DefinedRegion',
                            'addressCountry' => $country,
                        ],
                    ],
                    'hasMerchantReturnPolicy' => [
                        '@type'                 => 'MerchantReturnPolicy',
                        'applicableCountry'     => $country,
                        'returnPolicyCategory'  => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                        'merchantReturnDays'    => 14,
                    ],
                ];
            }
            $variants[] = $v;
        }
        return $variants;
    }

    /**
     * Bouw hasVariant array van WooCommerce producten (voor L3 leaf-categorieën).
     */
    private function build_product_variants(array $context): array {
        $term = $context['term'] ?? null;
        if (!$term || empty($term->term_id)) return [];
        if (!function_exists('wc_get_products')) return [];

        $group_id = trailingslashit($context['url'] ?? '') . '#productgroup';

        $products = wc_get_products([
            'category' => [$term->slug],
            'status'   => 'publish',
            'limit'    => 100,
            'orderby'  => 'menu_order',
            'order'    => 'ASC',
        ]);
        if (empty($products)) return [];

        $variants = [];
        foreach ($products as $product) {
            $url    = $product->get_permalink();
            $img_id = $product->get_image_id();
            $image  = $img_id ? wp_get_attachment_image_url($img_id, 'medium') : '';

            $v = [
                '@type'       => 'Product',
                'name'        => $product->get_name(),
                'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_name()),
                'sku'         => $product->get_sku() ?: 'product-' . $product->get_id(),
                'url'         => $url,
            ];
            if ($image) $v['image'] = $image;

            $v['isVariantOf'] = [
                '@type'          => 'ProductGroup',
                '@id'            => $group_id,
                'name'           => $term->name,
                'productGroupID' => $term->slug,
            ];

            $price = $product->get_price();
            if ($price !== '' && $price !== null) {
                $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
                $v['offers'] = [
                    '@type'           => 'Offer',
                    'url'             => $url,
                    'priceCurrency'   => $currency,
                    'price'           => number_format((float) $price, 2, '.', ''),
                    'availability'    => $product->is_in_stock()
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'priceValidUntil' => date('Y-12-31', strtotime('+2 years')),
                ];
            }

            $variants[] = $v;
        }
        return $variants;
    }

    /**
     * Voorbeeld-templates per pagina-type. Gebruikt placeholders die op
     * render-tijd worden vervangen met live data.
     */
    public static function get_example_template(string $type): string {
        switch ($type) {
            case 'product_cat_l1':
                return self::example_l1();
            case 'product_cat_l2':
                return self::example_l2();
            case 'product_cat_l3':
                return self::example_l3();
            case 'home':
                return self::example_home();
            default:
                return '';
        }
    }

    private static function example_home(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                ],
            ],
        ]);
    }

    private static function example_l1(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
            ],
        ]);
    }

    private static function example_l2(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'          => 'ProductGroup',
                    '@id'            => '{{group_id}}',
                    'name'           => '{{name}}',
                    'productGroupID' => '{{slug}}',
                    'url'            => '{{url}}',
                    'description'    => '{{description}}',
                    'brand'          => ['@id' => '{{org_id}}'],
                    'hasVariant'     => '{{children_variants}}',
                ],
            ],
        ]);
    }

    private static function example_l3(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'          => 'ProductGroup',
                    '@id'            => '{{group_id}}',
                    'name'           => '{{name}}',
                    'productGroupID' => '{{slug}}',
                    'url'            => '{{url}}',
                    'description'    => '{{description}}',
                    'brand'          => ['@id' => '{{org_id}}'],
                    'hasVariant'     => '{{product_variants}}',
                ],
            ],
        ]);
    }

    private static function pretty_json(array $data): string {
        return wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
