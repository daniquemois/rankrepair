# Structured Data — matrix-gedreven suggesties & per-bron toepassen — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** De Structured Data add-on per paginatype add/remove-suggesties laten geven op basis van een ingebouwde best-practice-matrix, die met 1 klik toepasbaar zijn (incl. onderdrukken van Yoast/Rank Math/thema-schema), met instelbare pagina-koppeling.

**Architecture:** Eén nieuwe matrix-klasse als bron van waarheid; de bestaande scanner vergelijkt gescande schema's met de matrix en toont add/remove-suggesties; `inject_schema()` + per-engine filters voeren toepassingen uit op basis van opgeslagen toggles/regels. Bestaande patronen volgen — niet herstructureren.

**Tech Stack:** PHP (WordPress plugin), WooCommerce, JSON-LD; geen PHPUnit-suite → verificatie via `php -l` + handmatige scanner-UI/Rich-Results-checks.

**Spec:** `docs/superpowers/specs/2026-06-26-structured-data-matrix-suggestions-design.md`

**Test-reality:** Deze plugin heeft geen unit-test-harness. Elke taak verifieert met (a) `php -l <bestand>` en (b) een concrete handmatige check in de scanner-UI of front-end. Geen test-framework opzetten (out of scope).

---

## File Structure

- **Create** `addons/structured-data/class-sd-matrix.php` — de matrix (standaard + per-type overrides + `normalize_page_type()` + `compare()`).
- **Modify** `addons/structured-data/class-sd-scanner.php` — `get_page_types()` uitbreiden; `suggested_schemas_for()` vervangen door matrix-`compare()`; remove-suggesties + apply-knoppen renderen.
- **Modify** `addons/structured-data/class-addon-structured-data.php` — settings (per-type toggles, page-pickers, output-buffer-toggle); `inject_schema()` remove-toepassing voor RankRepair; AJAX apply-handlers; Yoast/Rank Math suppress-filters; `normalize_page_type` koppelen aan `detect_context()`.
- **Create** `addons/structured-data/class-sd-suppress.php` — leest `rr_sd_suppress_rules` en hangt de Yoast/Rank Math-filters + (opt-in) output-buffer-strip in.

Elke klasse één verantwoordelijkheid: matrix = beleid, scanner = audit/UI, suppress = 3rd-party-onderdrukking, addon = injectie + settings + AJAX.

---

## FASE 1 — Matrix + add/remove-suggesties (op al-gedetecteerde types)

### Task 1: Matrix-klasse met standaard + compare

**Files:**
- Create: `addons/structured-data/class-sd-matrix.php`

- [ ] **Step 1: Schrijf de matrix-klasse**

```php
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

    /** Welke schema's RankRepair zelf kan bouwen (rest → custom-JSON-LD sjabloon). */
    public static function rr_buildable(): array {
        return ['Organization', 'WebSite', 'SearchAction', 'BreadcrumbList', 'Product', 'Offer', 'ProductGroup', 'ItemList', 'FAQPage'];
    }

    /** Matrix-regels voor één paginatype, met per-type settings-overrides erop gemerged. */
    public static function get(string $page_type): array {
        $all = self::default_matrix();
        if (!isset($all[$page_type])) return ['always' => [], 'optional' => [], 'forbidden' => [], 'note' => ''];
        $rules = $all[$page_type];
        // Overrides: opgeslagen als rr_sd_matrix_off_<page_type> = ['Product','Video',...]
        $off = get_option('rr_sd_matrix_off_' . $page_type, []);
        if (is_array($off) && $off) {
            $rules['always']   = array_values(array_diff($rules['always'], $off));
            $rules['optional'] = array_values(array_diff($rules['optional'], $off));
        }
        return $rules;
    }

    /** Detectie-key (detect_context / get_page_types) → matrix page_type. Null = geen matrix. */
    public static function normalize_page_type(string $detect_key): ?string {
        if ($detect_key === 'home') return 'home';
        if ($detect_key === 'product') return 'pdp';
        if (strpos($detect_key, 'product_cat') === 0) return 'plp';
        if (in_array($detect_key, ['post', 'blog_index', 'category'], true)) return 'blog';
        if (in_array($detect_key, ['faq', 'about', 'contact', 'business_reviews', 'search', 'cart', 'checkout', 'account', 'author', 'location', 'brand', 'promo', 'service', 'recipe'], true)) return $detect_key;
        return null; // generieke 'page' o.i.d. → geen specifieke matrix
    }

    /**
     * Vergelijk gedetecteerde schema-types met de matrix.
     * @param string $page_type  matrix page_type
     * @param array  $detected   [['type'=>'Product','source'=>'Yoast'], ...]
     * @return array suggesties: [['action'=>'add|remove','type'=>..,'priority'=>'required|optional','source'=>..,'note'=>..], ...]
     */
    public static function compare(string $page_type, array $detected): array {
        $rules = self::get($page_type);
        $present = []; // type(lc) => source
        foreach ($detected as $d) {
            $t = strtolower((string)($d['type'] ?? ''));
            if ($t !== '') $present[$t] = $d['source'] ?? '';
        }
        $has = fn(string $t) => isset($present[strtolower($t)]);
        $out = [];

        // ADD — ontbrekende verplichte/optionele
        foreach (['always' => 'required', 'optional' => 'optional'] as $key => $prio) {
            foreach ($rules[$key] as $type) {
                // 'Article' staat voor Article OF BlogPosting; tel beide als aanwezig
                if ($type === 'Article' && $has('BlogPosting')) continue;
                if (!$has($type)) {
                    $out[] = ['action' => 'add', 'type' => $type, 'priority' => $prio, 'source' => 'RankRepair', 'note' => $rules['note']];
                }
            }
        }

        // REMOVE — aanwezig maar verboden
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
```

- [ ] **Step 2: Verifieer syntax**

Run: `php -l addons/structured-data/class-sd-matrix.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Laad de klasse mee**

In `rankrepair.php` (of waar de addon-bestanden worden geïnclude — zoek met `grep -rn "class-sd-scanner.php" .`) voeg een `require_once` toe voor `class-sd-matrix.php` direct naast de bestaande `class-sd-scanner.php`-include, zodat de laadvolgorde gelijk is aan de andere SD-klassen.

- [ ] **Step 4: Smoke-test laden**

Run: `php -r "define('ABSPATH',1); require 'addons/structured-data/class-sd-matrix.php'; var_export(RR_SD_Matrix::normalize_page_type('product'));"`
Expected: `'pdp'`

- [ ] **Step 5: Commit**

```bash
git add addons/structured-data/class-sd-matrix.php rankrepair.php
git commit -m "feat(sd): matrix-klasse met standaard, overrides en compare()"
```

### Task 2: Scanner gebruikt de matrix voor add/remove-suggesties

**Files:**
- Modify: `addons/structured-data/class-sd-scanner.php` (`suggested_schemas_for()` → vervangen; aanroep in `render()` rond regel 81)

- [ ] **Step 1: Vervang `suggested_schemas_for()` door een matrix-aanroep**

Vervang de volledige methode `suggested_schemas_for(string $type, array $existing)` door:

```php
    /**
     * Suggesties (add + remove) op basis van de matrix.
     * @param string $type      detectie-key uit get_page_types()
     * @param array  $existing  scan-resultaat: [['data'=>[...], 'source'=>'Yoast'], ...]
     */
    private function suggested_schemas_for(string $type, array $existing): array {
        $page_type = RR_SD_Matrix::normalize_page_type($type);
        if ($page_type === null) return [];

        // Bouw detected-lijst: elk @type met zijn bron
        $detected = [];
        foreach ($existing as $s) {
            $t = $s['data']['@type'] ?? '';
            $src = $s['source'] ?? '';
            foreach ((array) $t as $tt) {
                if ($tt !== '') $detected[] = ['type' => (string) $tt, 'source' => $src];
            }
        }
        return RR_SD_Matrix::compare($page_type, $detected);
    }
```

- [ ] **Step 2: Verifieer syntax**

Run: `php -l addons/structured-data/class-sd-scanner.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Handmatige check (scanner-UI)**

Open in wp-admin: RankRepair → Structured Data (Inspector). Selecteer paginatype "Homepage".
Expected: suggesties tonen nu zowel ontbrekende (`add`) als aanwezige-maar-verboden (`remove`) types volgens de matrix (bv. op een categoriepagina met Product-schema verschijnt een remove-suggestie voor Product).

- [ ] **Step 4: Commit**

```bash
git add addons/structured-data/class-sd-scanner.php
git commit -m "feat(sd): scanner-suggesties matrix-gedreven (add + remove)"
```

### Task 3: Render remove-suggesties in de scanner-UI

**Files:**
- Modify: `addons/structured-data/class-sd-scanner.php` (`render()`, het `$suggested`-blok rond regels 81-100)

- [ ] **Step 1: Toon add én remove gescheiden**

In `render()`, waar nu `$suggested` wordt opgehaald en als "aan te vullen" getoond, splits op `action`. Vervang het bestaande suggestie-blok door markup die twee lijsten toont: groene "toevoegen"-suggesties en rode "weghalen"-suggesties, met per item `type`, `priority`-badge, `source`-label en (bij remove) de bron. Volg de bestaande klassenaamstijl (`rr-sd-suggest`, `rr-sd-total--add`); voeg een variant `rr-sd-total--remove` toe.

```php
<?php
$suggested = $this->suggested_schemas_for($selected, $schemas);
$to_add    = array_values(array_filter($suggested, fn($s) => $s['action'] === 'add'));
$to_remove = array_values(array_filter($suggested, fn($s) => $s['action'] === 'remove'));
?>
<?php if ($to_add): ?>
  <span class="rr-sd-total rr-sd-total--add"><strong>+<?php echo count($to_add); ?></strong> <?php _e('toevoegen', 'rankrepair'); ?></span>
<?php endif; ?>
<?php if ($to_remove): ?>
  <span class="rr-sd-total rr-sd-total--remove"><strong>−<?php echo count($to_remove); ?></strong> <?php _e('weghalen', 'rankrepair'); ?></span>
<?php endif; ?>
```

En in het uitklap-detailblok (waar nu `foreach ($suggested as $s)` staat): render `$to_add` met label "voeg toe (vereist/optioneel)" en `$to_remove` met label "haal weg — bron: {source}". Toepassen-knoppen komen in Fase 3 (Task 7/8); render hier voorlopig de tekstuele suggestie.

- [ ] **Step 2: CSS voor de remove-variant**

In `addons/structured-data/structured-data.css` voeg een regel toe analoog aan `.rr-sd-total--add`, met een rode tint voor `.rr-sd-total--remove` (volg bestaande kleur-tokens in dat bestand).

- [ ] **Step 3: Verifieer syntax + handmatige check**

Run: `php -l addons/structured-data/class-sd-scanner.php`
Expected: `No syntax errors detected`
Scanner-UI: op een categoriepagina zie je een rode "−N weghalen" badge + de remove-lijst met bronlabel.

- [ ] **Step 4: Commit**

```bash
git add addons/structured-data/class-sd-scanner.php addons/structured-data/structured-data.css
git commit -m "feat(sd): remove-suggesties tonen in scanner-UI"
```

---

## FASE 2 — Alle paginatypes + handmatige pagina-koppeling

### Task 4: Paginatype-resolver uitbreiden (auto-detect)

**Files:**
- Modify: `addons/structured-data/class-sd-scanner.php` (`get_page_types()`)
- Modify: `addons/structured-data/class-addon-structured-data.php` (`detect_context()` → nieuwe types herkennen)

- [ ] **Step 1: Voeg auto-detecteerbare WC/WP-types toe aan `get_page_types()`**

Voeg na de bestaande types toe (alleen als de pagina bestaat):

```php
// WooCommerce functionele pagina's
if (function_exists('wc_get_page_id')) {
    foreach (['cart' => __('Winkelmand', 'rankrepair'), 'checkout' => __('Checkout', 'rankrepair'), 'myaccount' => __('Account / Login', 'rankrepair')] as $wc_key => $wc_label) {
        $pid = wc_get_page_id($wc_key);
        if ($pid > 0 && get_post($pid)) {
            $key = $wc_key === 'myaccount' ? 'account' : $wc_key;
            $types[$key] = ['label' => $wc_label, 'url' => get_permalink($pid)];
        }
    }
}
// Zoekresultaten
$types['search'] = ['label' => __('Zoekresultaten', 'rankrepair'), 'url' => home_url('/?s=test')];
// Auteurspagina — eerste auteur met gepubliceerde posts
$authors = get_users(['who' => 'authors', 'number' => 1, 'has_published_posts' => ['post']]);
if (!empty($authors)) {
    $types['author'] = ['label' => __('Auteurspagina', 'rankrepair'), 'url' => get_author_posts_url($authors[0]->ID)];
}
```

- [ ] **Step 2: Laat `detect_context()` deze types teruggeven**

In `class-addon-structured-data.php::detect_context()` voeg detectie toe vóór de generieke `is_page()`-tak:

```php
if (function_exists('is_search') && is_search()) return ['type' => 'search', 'url' => ''];
if (function_exists('is_cart') && is_cart()) return ['type' => 'cart', 'url' => ''];
if (function_exists('is_checkout') && is_checkout()) return ['type' => 'checkout', 'url' => ''];
if (function_exists('is_account_page') && is_account_page()) return ['type' => 'account', 'url' => ''];
if (is_author()) return ['type' => 'author', 'url' => ''];
```

- [ ] **Step 3: Verifieer syntax + scanner-UI**

Run: `php -l addons/structured-data/class-sd-scanner.php && php -l addons/structured-data/class-addon-structured-data.php`
Expected: beide `No syntax errors detected`
Scanner-UI: de dropdown toont nu ook Winkelmand/Checkout/Account/Zoekresultaten/Auteur (indien aanwezig), en op die types tonen de `forbidden=['*']`-regels remove-suggesties voor élk gevonden schema.

- [ ] **Step 4: Commit**

```bash
git add addons/structured-data/class-sd-scanner.php addons/structured-data/class-addon-structured-data.php
git commit -m "feat(sd): auto-detect search/cart/checkout/account/author paginatypes"
```

### Task 5: Handmatige pagina-koppeling in settings

**Files:**
- Modify: `addons/structured-data/class-addon-structured-data.php` (`register_settings_section()`)
- Modify: `addons/structured-data/class-sd-scanner.php` (`get_page_types()`)

- [ ] **Step 1: Settings-velden voor handmatige types**

In `register_settings_section()` voeg per handmatig type een veld toe waarmee een pagina/term wordt gekozen. Volg het bestaande veld-formaat. Gebruik opties `rr_sd_page_<type>` met de gekozen post-ID/URL. Types: `about`, `business_reviews`, `location`, `brand`, `promo`, `service`, `recipe`, `faq`. Voorbeeldveld (herhaal per type met eigen `name`/`label`):

```php
[
    'name'        => 'rr_sd_page_about',
    'label'       => __('Over-ons pagina', 'rankrepair'),
    'type'        => 'text',
    'description' => __('Volledige URL of pagina-ID van de Over-ons pagina (voor de scanner + matrix).', 'rankrepair'),
],
```

(Als er al een select/post-picker veldtype bestaat in de settings-renderer — controleer met `grep -rn "'type' => 'post'" addons/`, anders houd `text` met URL/ID aan.)

- [ ] **Step 2: Resolver leest de handmatige koppeling**

In `get_page_types()` voeg per handmatig type toe (alleen als gevuld):

```php
$manual = [
    'about'            => __('Over ons', 'rankrepair'),
    'business_reviews' => __('Bedrijfsreviews', 'rankrepair'),
    'location'         => __('Vestigingspagina', 'rankrepair'),
    'brand'            => __('Merkenpagina', 'rankrepair'),
    'promo'            => __('Actiepagina', 'rankrepair'),
    'service'          => __('Dienstenpagina', 'rankrepair'),
    'recipe'           => __('Recepten', 'rankrepair'),
    'faq'              => __('FAQ-pagina', 'rankrepair'),
];
foreach ($manual as $mkey => $mlabel) {
    $val = trim((string) get_option('rr_sd_page_' . $mkey, ''));
    if ($val === '') continue;
    $url = is_numeric($val) ? get_permalink((int) $val) : esc_url_raw($val);
    if ($url) $types[$mkey] = ['label' => $mlabel, 'url' => $url];
}
```

- [ ] **Step 3: Verifieer syntax + handmatige check**

Run: `php -l addons/structured-data/class-addon-structured-data.php`
Expected: `No syntax errors detected`
Vul in settings bv. de Over-ons URL in → scanner-dropdown toont "Over ons" en vergelijkt met de `about`-matrix.

- [ ] **Step 4: Commit**

```bash
git add addons/structured-data/class-addon-structured-data.php addons/structured-data/class-sd-scanner.php
git commit -m "feat(sd): handmatige pagina-koppeling voor niet-auto paginatypes"
```

---

## FASE 3 — 1-klik toepassen + per-type matrix-overrides

### Task 6: Per-type matrix-overrides in settings

**Files:**
- Modify: `addons/structured-data/class-addon-structured-data.php` (`register_settings_section()`)

- [ ] **Step 1: Per-type uitschakel-veld**

Voeg per matrix-paginatype een `checkbox_group`-veld toe waarmee schema's uit `always`/`optional` kunnen worden uitgezet; sla op als `rr_sd_matrix_off_<page_type>` (array van schema-types). De checkbox-opties per type komen uit `RR_SD_Matrix::default_matrix()[$type]['always'] + ['optional']`. Genereer deze velden in een lus over `RR_SD_Matrix::default_matrix()`.

```php
foreach (RR_SD_Matrix::default_matrix() as $pt => $rules) {
    $opts = [];
    foreach (array_merge($rules['always'], $rules['optional']) as $schema) {
        $opts['rr_sd_matrix_off_' . $pt . '_' . $schema] = [$schema, ''];
    }
    if (!$opts) continue;
    $sections[$idx]['fields'][] = [
        'name'    => 'rr_sd_matrix_off_' . $pt,
        'label'   => sprintf(__('Uitschakelen — %s', 'rankrepair'), $pt),
        'type'    => 'checkbox_group',
        'options' => $opts,
    ];
}
```

(Pas de opslag in `RR_SD_Matrix::get()` aan als het checkbox_group-formaat per-key-options i.p.v. een array opslaat — controleer hoe `rr_sd_schemas` wordt opgeslagen en spiegel dat in `get()`.)

- [ ] **Step 2: Verifieer syntax + check**

Run: `php -l addons/structured-data/class-addon-structured-data.php`
Expected: `No syntax errors detected`
Zet in settings een `optional`-schema voor `home` uit → de scanner toont dat type niet langer als add-suggestie.

- [ ] **Step 3: Commit**

```bash
git add addons/structured-data/class-addon-structured-data.php addons/structured-data/class-sd-matrix.php
git commit -m "feat(sd): per-type matrix-overrides in settings"
```

### Task 7: 1-klik toevoegen (apply add)

**Files:**
- Modify: `addons/structured-data/class-addon-structured-data.php` (AJAX-handler + hook-registratie in `init()`)
- Modify: `addons/structured-data/class-sd-scanner.php` (apply-knop bij elke add-suggestie)

- [ ] **Step 1: AJAX-handler voor "apply add"**

Registreer in `init()` een AJAX-actie en schrijf de handler. Voor een RankRepair-bouwbaar type (`RR_SD_Matrix::rr_buildable()`): zet de bijbehorende `rr_sd_schema_*`-optie op `'1'`. Voor niet-bouwbare types: prefill `rr_sd_custom_jsonld_<page_type>` met een JSON-LD-skelet voor dat type (alleen als nog leeg).

```php
add_action('wp_ajax_rr_sd_apply_add', [$this, 'ajax_apply_add']);

public function ajax_apply_add(): void {
    check_ajax_referer('rr_sd_apply', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    $page_type = sanitize_key($_POST['page_type'] ?? '');
    $schema    = sanitize_text_field($_POST['schema'] ?? '');
    $map = ['Organization' => 'rr_sd_schema_organization', 'WebSite' => 'rr_sd_schema_website', 'BreadcrumbList' => 'rr_sd_schema_breadcrumb', 'Product' => 'rr_sd_schema_product', 'Offer' => 'rr_sd_schema_product', 'ProductGroup' => 'rr_sd_schema_productgroup', 'ItemList' => 'rr_sd_schema_productgroup', 'FAQPage' => 'rr_sd_schema_faqpage'];
    if (isset($map[$schema])) {
        update_option($map[$schema], '1');
        wp_send_json_success(['mode' => 'builder']);
    }
    // Niet-bouwbaar → custom JSON-LD skelet
    $opt = 'rr_sd_custom_jsonld_' . $page_type;
    if (trim((string) get_option($opt, '')) === '') {
        update_option($opt, wp_json_encode(['@context' => 'https://schema.org', '@type' => $schema], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
    wp_send_json_success(['mode' => 'template']);
}
```

- [ ] **Step 2: Apply-knop in de scanner naast elke add-suggestie**

Bij elke add-suggestie render een knop `data-rr-sd-apply="add"` met `data-page-type` en `data-schema`. Voeg een klein inline-script of een regel in het bestaande scanner-JS toe dat de AJAX-call doet (nonce via `wp_create_nonce('rr_sd_apply')` in een `data-nonce` of gelokaliseerde var) en bij succes de pagina herlaadt of de suggestie als "toegepast" markeert.

- [ ] **Step 3: Verifieer syntax + handmatige check**

Run: `php -l addons/structured-data/class-addon-structured-data.php && php -l addons/structured-data/class-sd-scanner.php`
Expected: beide `No syntax errors detected`
Klik "toevoegen" bij een bouwbaar schema → de bijbehorende toggle staat op aan; herscan toont het schema nu aanwezig. Bij een niet-bouwbaar schema → het custom-JSON-LD-veld van dat type bevat het skelet.

- [ ] **Step 4: Commit**

```bash
git add addons/structured-data/
git commit -m "feat(sd): 1-klik toevoegen (builder-toggle of custom-JSON-LD skelet)"
```

### Task 8: 1-klik weghalen + suppress-engine (RankRepair / Yoast / Rank Math)

**Files:**
- Create: `addons/structured-data/class-sd-suppress.php`
- Modify: `addons/structured-data/class-addon-structured-data.php` (AJAX-handler "apply remove"; `inject_schema()` slaat RR-types over die onderdrukt zijn; suppress-klasse laden in `init()`)

- [ ] **Step 1: Suppress-regel-opslag + AJAX-handler**

Een "weghalen"-klik schrijft een regel weg in `rr_sd_suppress_rules` (`[ ['page_type'=>..,'schema'=>..,'source'=>..], ... ]`).

```php
add_action('wp_ajax_rr_sd_apply_remove', [$this, 'ajax_apply_remove']);

public function ajax_apply_remove(): void {
    check_ajax_referer('rr_sd_apply', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    $rule = [
        'page_type' => sanitize_key($_POST['page_type'] ?? ''),
        'schema'    => sanitize_text_field($_POST['schema'] ?? ''),
        'source'    => sanitize_text_field($_POST['source'] ?? ''),
    ];
    $rules = get_option('rr_sd_suppress_rules', []);
    if (!is_array($rules)) $rules = [];
    $rules[] = $rule;
    update_option('rr_sd_suppress_rules', $rules);
    wp_send_json_success();
}
```

- [ ] **Step 2: Suppress-klasse — Yoast + Rank Math filters**

```php
<?php
/** Past opgeslagen onderdrukkingsregels toe op schema van andere bronnen. */
if (!defined('ABSPATH')) { exit; }

class RR_SD_Suppress {
    public function __construct() {
        add_filter('wpseo_schema_graph_pieces', [$this, 'yoast_pieces'], 20, 2);
        add_filter('rank_math/json_ld', [$this, 'rankmath'], 99, 1);
    }

    private function suppressed_types_for_current(): array {
        $rules = get_option('rr_sd_suppress_rules', []);
        if (!is_array($rules) || !$rules) return [];
        if (!class_exists('RR_Addon_Structured_Data')) return [];
        // Bepaal huidige matrix page_type via dezelfde normalisatie als de injector
        $pt = RR_Addon_Structured_Data::current_matrix_page_type();
        if ($pt === null) return [];
        $out = [];
        foreach ($rules as $r) {
            if (($r['page_type'] ?? '') === $pt) $out[strtolower($r['schema'])] = true;
        }
        return $out;
    }

    public function yoast_pieces($pieces, $context) {
        $supp = $this->suppressed_types_for_current();
        if (!$supp) return $pieces;
        return array_filter($pieces, function ($piece) use ($supp) {
            $cls = strtolower((new ReflectionClass($piece))->getShortName());
            return !isset($supp[$cls]); // bv. 'product','breadcrumb'
        });
    }

    public function rankmath($data) {
        $supp = $this->suppressed_types_for_current();
        if (!$supp || !is_array($data)) return $data;
        foreach ($data as $key => $entry) {
            $t = strtolower($entry['@type'] ?? '');
            if ($t && isset($supp[$t])) unset($data[$key]);
        }
        return $data;
    }
}
```

In `init()`: `new RR_SD_Suppress();` (en de file requiren). Voeg in `class-addon-structured-data.php` een publieke static `current_matrix_page_type(): ?string` toe die `detect_context()` draait en door `RR_SD_Matrix::normalize_page_type()` haalt.

- [ ] **Step 3: RankRepair eigen schema's onderdrukken in `inject_schema()`**

Aan het begin van `inject_schema()`, na `$context = $this->detect_context();`, bepaal onderdrukte types voor dit page_type en sla de bijbehorende auto-build over. Voeg vóór elke `$graph[] = $builder->build_*` een check toe `&& !$this->is_suppressed($context, '<Type>')`. Implementeer `is_suppressed(array $context, string $schema): bool` die `rr_sd_suppress_rules` leest tegen `normalize_page_type($context['type'])`.

- [ ] **Step 4: Apply-knop "weghalen" in de scanner**

Bij elke remove-suggestie een knop `data-rr-sd-apply="remove"` met `data-page-type`, `data-schema`, `data-source`. Bij bron = thema/onbekend én de output-buffer-toggle staat uit: toon i.p.v. een knop de tekst "verwijder dit in {source}" (niet automatisch te onderdrukken). AJAX → `rr_sd_apply_remove`.

- [ ] **Step 5: Verifieer syntax + handmatige check**

Run: `php -l addons/structured-data/class-sd-suppress.php && php -l addons/structured-data/class-addon-structured-data.php`
Expected: beide `No syntax errors detected`
Op een pagina waar Yoast een verboden schema levert: klik "weghalen" → herscan toont dat schema verdwenen. Regel verwijderen uit `rr_sd_suppress_rules` → schema komt terug.

- [ ] **Step 6: Commit**

```bash
git add addons/structured-data/
git commit -m "feat(sd): 1-klik weghalen via suppress-regels (RR + Yoast + Rank Math)"
```

### Task 9: Optionele output-buffer-strip voor thema-bron

**Files:**
- Modify: `addons/structured-data/class-addon-structured-data.php` (settings-toggle `rr_sd_force_remove`)
- Modify: `addons/structured-data/class-sd-suppress.php` (output-buffer-strip)

- [ ] **Step 1: Settings-toggle**

Voeg veld `rr_sd_force_remove` toe (`select`, default `'0'`) met waarschuwingstekst dat dit fragiel is en JSON-LD uit thema-templates probeert te knippen.

- [ ] **Step 2: Output-buffer-strip (opt-in)**

In `RR_SD_Suppress::__construct()`, alleen als `get_option('rr_sd_force_remove','0') === '1'`: start op `template_redirect` een `ob_start()` met een callback die JSON-LD-`<script>`-blokken verwijdert wiens `@type` in de onderdrukte types voor dit page_type zit. Werk alleen op niet-admin, niet-AJAX requests.

```php
if (get_option('rr_sd_force_remove', '0') === '1' && !is_admin()) {
    add_action('template_redirect', function () {
        ob_start([$this, 'strip_buffer']);
    });
}

public function strip_buffer(string $html): string {
    $supp = $this->suppressed_types_for_current();
    if (!$supp) return $html;
    return preg_replace_callback('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#is', function ($m) use ($supp) {
        $data = json_decode(trim($m[1]), true);
        $type = is_array($data) ? strtolower($data['@type'] ?? '') : '';
        return ($type && isset($supp[$type])) ? '' : $m[0];
    }, $html);
}
```

- [ ] **Step 3: Verifieer syntax + handmatige check**

Run: `php -l addons/structured-data/class-sd-suppress.php`
Expected: `No syntax errors detected`
Zet de toggle aan op een testsite met thema-geïnjecteerd schema → herscan toont het verboden type verdwenen. Toggle uit → het komt terug.

- [ ] **Step 4: Versie bumpen + readme + commit**

Bump `Version:` in `rankrepair.php` (bv. 1.7.0) en voeg een changelog-regel toe in `readme.txt`.

```bash
git add addons/structured-data/ rankrepair.php readme.txt
git commit -m "feat(sd): optionele output-buffer-strip voor thema-schema + v1.7.0"
```

---

## Self-Review (uitgevoerd)

- **Spec-dekking:** matrix (Task 1) ✓, resolver/auto-detect (Task 4) ✓, handmatige koppeling (Task 5) ✓, add-suggesties (Task 2/3) ✓, remove-suggesties (Task 2/3) ✓, per-type overrides (Task 6) ✓, 1-klik add (Task 7) ✓, 1-klik remove per bron (Task 8) ✓, output-buffer opt-in (Task 9) ✓, settings ✓.
- **Type-consistentie:** `normalize_page_type`, `compare`, `rr_buildable`, `current_matrix_page_type`, `rr_sd_suppress_rules`, `rr_sd_matrix_off_<type>`, `rr_sd_page_<type>` consistent gebruikt over taken.
- **Aandachtspunt voor uitvoering:** de exacte opslag/uitlezing van `checkbox_group` (Task 6) en het settings-veldtype voor de page-picker (Task 5) moeten gespiegeld worden aan de bestaande settings-renderer — daarom verwijzen die stappen naar het bestaande `rr_sd_schemas`-patroon i.p.v. een aanname.
