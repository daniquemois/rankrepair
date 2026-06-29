<?php
/**
 * Structured Data beleids-matrix: per paginatype welke schema's altijd/optioneel
 * horen, welke verboden zijn (niet gebruiken), plus een conditie-notitie.
 * Bron van waarheid voor de suggestie-engine.
 */
if (!defined('ABSPATH')) { exit; }

class RR_SD_Matrix {

    /** Standaard-matrix (best-practice tabel). forbidden=['*'] = élk schema verboden. */
    public static function default_matrix(): array {
        return [
            'home'             => ['always' => ['WebSite', 'Organization'], 'optional' => ['SearchAction', 'VideoObject'], 'forbidden' => ['Product', 'Review', 'Offer'], 'note' => 'SearchAction alleen bij echte interne zoekfunctie.'],
            'plp'              => ['always' => ['BreadcrumbList', 'FAQPage'], 'optional' => ['ItemList', 'VideoObject'], 'forbidden' => ['Product', 'Offer', 'AggregateRating'], 'note' => 'ItemList vaak overkill; FAQ enkel als zichtbaar.'],
            'pdp'              => ['always' => ['Product', 'Offer', 'BreadcrumbList', 'FAQPage'], 'optional' => ['AggregateRating', 'VideoObject'], 'forbidden' => [], 'note' => 'Geen dubbele Product-schema\'s; reviews echt+zichtbaar; FAQ enkel als zichtbaar.'],
            'blog'             => ['always' => ['Article', 'BreadcrumbList'], 'optional' => ['VideoObject', 'HowTo', 'FAQPage'], 'forbidden' => ['Product'], 'note' => 'Article of BlogPosting; FAQ alleen als zichtbaar.'],
            'faq'              => ['always' => ['FAQPage'], 'optional' => [], 'forbidden' => ['Product', 'Review'], 'note' => 'Geen marketing in antwoorden.'],
            'about'            => ['always' => ['Organization'], 'optional' => ['LocalBusiness', 'VideoObject'], 'forbidden' => ['Product'], 'note' => 'LocalBusiness alleen bij fysieke locatie.'],
            'contact'          => ['always' => ['Organization'], 'optional' => ['ContactPoint', 'LocalBusiness'], 'forbidden' => ['Product'], 'note' => 'Adres consistent met Google Business Profile.'],
            'business_reviews' => ['always' => ['Organization', 'AggregateRating'], 'optional' => ['Review'], 'forbidden' => ['Product'], 'note' => 'Reviews moeten over het bedrijf gaan.'],
            'search'           => ['always' => [], 'optional' => [], 'forbidden' => ['*'], 'note' => 'Functionele pagina, geen SEO-doel.'],
            'cart'             => ['always' => [], 'optional' => [], 'forbidden' => ['*'], 'note' => 'Noindex aanbevolen.'],
            'checkout'         => ['always' => [], 'optional' => [], 'forbidden' => ['*'], 'note' => 'Noindex, nofollow.'],
            'account'          => ['always' => [], 'optional' => [], 'forbidden' => ['*'], 'note' => 'Noindex.'],
            'author'           => ['always' => ['Person'], 'optional' => ['Organization'], 'forbidden' => ['Product', 'Article', 'BlogPosting'], 'note' => 'Person = echte persoon met bio.'],
            'location'         => ['always' => ['LocalBusiness'], 'optional' => ['OpeningHoursSpecification', 'ContactPoint', 'GeoCoordinates', 'AggregateRating', 'VideoObject'], 'forbidden' => ['Organization', 'Product'], 'note' => 'Elke vestiging = unieke LocalBusiness met eigen NAP.'],
            'brand'            => ['always' => ['BreadcrumbList'], 'optional' => ['Brand', 'ItemList'], 'forbidden' => ['Product', 'Offer', 'AggregateRating'], 'note' => 'Brand alleen als de pagina echt over 1 merk gaat.'],
            'promo'            => ['always' => ['BreadcrumbList'], 'optional' => ['Article'], 'forbidden' => ['Product', 'Offer', 'AggregateRating'], 'note' => 'Overzichtspagina; aanbiedingen markeer je op de PDP.'],
            'service'          => ['always' => ['Organization', 'LocalBusiness', 'Service'], 'optional' => ['SoftwareApplication'], 'forbidden' => [], 'note' => ''],
            'recipe'           => ['always' => ['Recipe'], 'optional' => [], 'forbidden' => [], 'note' => ''],
        ];
    }

    /** Welke schema's RankRepair zelf kan bouwen (rest -> custom-JSON-LD sjabloon). */
    public static function rr_buildable(): array {
        return ['Organization', 'WebSite', 'SearchAction', 'BreadcrumbList', 'Product', 'Offer', 'ProductGroup', 'ItemList', 'FAQPage'];
    }

    /** Matrix-regels voor een paginatype, met per-type settings-overrides erop gemerged. */
    public static function get(string $page_type): array {
        $all = self::default_matrix();
        if (!isset($all[$page_type])) return ['always' => [], 'optional' => [], 'forbidden' => [], 'note' => ''];
        $rules = $all[$page_type];
        $off = get_option('rr_sd_matrix_off_' . $page_type, []);
        if (is_array($off) && $off) {
            $rules['always']   = array_values(array_diff($rules['always'], $off));
            $rules['optional'] = array_values(array_diff($rules['optional'], $off));
        }
        return $rules;
    }

    /** Detectie-key -> matrix page_type. Null = geen matrix. */
    public static function normalize_page_type(string $detect_key): ?string {
        if ($detect_key === 'home') return 'home';
        if ($detect_key === 'product') return 'pdp';
        if (strpos($detect_key, 'product_cat') === 0) return 'plp';
        if (in_array($detect_key, ['post', 'blog_index', 'category'], true)) return 'blog';
        if (in_array($detect_key, ['faq', 'about', 'contact', 'business_reviews', 'search', 'cart', 'checkout', 'account', 'author', 'location', 'brand', 'promo', 'service', 'recipe'], true)) return $detect_key;
        return null;
    }

    /**
     * Vergelijk gedetecteerde schema-types met de matrix.
     * @param string $page_type matrix page_type
     * @param array  $detected  [['type'=>'Product','source'=>'Yoast'], ...]
     * @return array [['action'=>'add|remove','type'=>..,'priority'=>'required|optional','source'=>..,'note'=>..], ...]
     */
    public static function compare(string $page_type, array $detected): array {
        $rules = self::get($page_type);
        $present = [];
        foreach ($detected as $d) {
            $t = strtolower((string)($d['type'] ?? ''));
            if ($t !== '') $present[$t] = $d['source'] ?? '';
        }
        $has = fn(string $t) => isset($present[strtolower($t)]);
        $out = [];

        foreach (['always' => 'required', 'optional' => 'optional'] as $key => $prio) {
            foreach ($rules[$key] as $type) {
                if ($type === 'Article' && $has('BlogPosting')) continue;
                if (!$has($type)) {
                    $out[] = ['action' => 'add', 'type' => $type, 'priority' => $prio, 'source' => 'RankRepair', 'note' => $rules['note']];
                }
            }
        }

        $allowed = array_map('strtolower', array_merge($rules['always'], $rules['optional']));
        foreach ($present as $type_lc => $source) {
            $forbidden_all = in_array('*', $rules['forbidden'], true);
            $forbidden_explicit = in_array($type_lc, array_map('strtolower', $rules['forbidden']), true);
            $not_allowed = $forbidden_all && !in_array($type_lc, $allowed, true);
            if ($forbidden_explicit || $not_allowed) {
                $out[] = ['action' => 'remove', 'type' => ucfirst($type_lc), 'priority' => 'required', 'source' => $source ?: 'onbekend', 'note' => $rules['note']];
            }
        }
        return $out;
    }
}
