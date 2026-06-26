# Structured Data — matrix-gedreven suggesties & per-bron toepassen

**Datum:** 2026-06-26
**Add-on:** `addons/structured-data/`
**Doel:** De Structured Data add-on uitbreiden zodat hij per paginatype, op basis van een ingebouwde best-practice-matrix, **add/remove-suggesties** geeft en die met **1 klik** kan toepassen — inclusief het onderdrukken van schema's van andere bronnen (Yoast / Rank Math / thema). De add-on moet bovendien per paginatype **de juiste pagina ophalen**, instelbaar in settings.

## Achtergrond / huidige staat

De add-on injecteert JSON-LD in `wp_head` (na Yoast, priority 100) en heeft een **scanner** (`class-sd-scanner.php`) die per paginatype een sample-pagina ophaalt (`wp_remote_get`), alle JSON-LD parseert, de **bron** detecteert (`detect_source`: Yoast / Rank Math / RankRepair / thema), per schema een property-tabel + veldvalidatie toont, en al "aan te vullen"-suggesties geeft via `suggested_schemas_for()`.

Beperkingen die deze feature wegneemt:
- `suggested_schemas_for()` is hardcoded per type en geeft **alleen add**, geen remove.
- Slechts ~10 paginatypes worden herkend (home, blog, post, contact, page, product, product_cat L1/L2/L3, category).
- Geen instelbare koppeling paginatype → echte pagina voor types die niet auto-detecteerbaar zijn.
- Suggesties kunnen niet worden toegepast.

De 6 schema's die de add-on zelf bouwt: **Organization, WebSite+SearchAction, BreadcrumbList, Product+Offer, ProductGroup/ItemList, FAQPage**.

## Beslissingen (vastgesteld in brainstorm)

1. **Suggesties tonen + 1-klik toepassen.**
2. **Matrix ingebouwd als standaard, per paginatype aanpasbaar** in settings (toggles overschrijven de standaard).
3. **Pagina-koppeling: auto waar mogelijk + handmatige page-picker** voor de rest.
4. **3rd-party schema's worden ook aangepast** (niet alleen gesignaleerd): per bron via het juiste mechanisme; thema/onbekend via een optionele, fragiele output-buffer-strip (standaard uit).

## De matrix (ingebouwde standaard)

Bron: de aangeleverde best-practice-tabel. Per paginatype: `always` (altijd), `optional` (optioneel), `forbidden` (niet gebruiken), `note` (conditie/opmerking).

| Paginatype | always | optional | forbidden | note |
|---|---|---|---|---|
| homepage | WebSite, Organization | SearchAction, Video | Product, Review, Offer | SearchAction alleen bij echte interne zoekfunctie |
| plp (productcategorie) | BreadcrumbList, FAQPage | ItemList, Video | Product, Offer, AggregateRating | ItemList vaak overkill; FAQ enkel als zichtbaar |
| pdp (product) | Product, Offer, BreadcrumbList, FAQPage | AggregateRating, Video | extra/dubbele Product-schema's | reviews echt+zichtbaar; FAQ enkel als zichtbaar; Shipping via OfferShippingDetails |
| blog (post/advies) | Article (of BlogPosting), BreadcrumbList | Video, HowTo, FAQPage | Product | FAQ alleen als zichtbaar |
| faq | FAQPage | — | Product, Review | geen marketing in antwoorden |
| over-ons | Organization | LocalBusiness, VideoObject | Product | LocalBusiness alleen bij fysieke locatie |
| contact | Organization | ContactPoint, LocalBusiness | Product | adres consistent met Google Business Profile |
| bedrijfsreviews | Organization, AggregateRating | Review | Product (-reviews) | reviews moeten over het bedrijf gaan |
| search | — | — | alle | functioneel, geen SEO-doel |
| cart | — | — | alle | noindex aanbevolen |
| checkout | — | — | alle | noindex, nofollow |
| account | — | — | alle | noindex |
| author | Person | Organization | Product, Article, BlogPosting | Person = echte persoon met bio |
| vestiging | LocalBusiness | OpeningHoursSpecification, ContactPoint, GeoCoordinates, AggregateRating, Video | Organization, Product | elke vestiging = unieke LocalBusiness met eigen NAP |
| merken | BreadcrumbList | Brand, ItemList | Product, Offer, AggregateRating | Brand alleen als de pagina echt over 1 merk gaat |
| actie | BreadcrumbList | Article | Product, Offer, AggregateRating | overzichtspagina; aanbiedingen markeer je op de PDP |
| dienst | Organization, LocalBusiness, Service | SoftwareApplication | — | — |
| recepten | Recipe | — | — | — |

`forbidden = ['*']` ("alle schema's") betekent: élk gedetecteerd schema is een remove-suggestie.

## Architectuur — bouwstenen

### 1. `class-sd-matrix.php` (nieuw) — één bron van waarheid
- `default_matrix(): array` — bovenstaande tabel als data (`page_type => ['always'=>[], 'optional'=>[], 'forbidden'=>[], 'note'=>'']`).
- `get(string $page_type): array` — standaard, met per-type settings-overrides erin gemerged (toggles kunnen een schema uit `always`/`optional` zetten of een `forbidden` negeren).
- Vervangt de hardcoded logica in `suggested_schemas_for()`.

### 2. Paginatype-resolver (uitbreiding van `get_page_types()`)
Levert per paginatype een **representatieve URL** + label + (optioneel) count. Bron van de URL:
- **Auto-detect:** homepage (`home_url`), plp (`product_cat`), pdp (`product`), blog (`page_for_posts` + recente post), faq (pagina met Gutenberg-FAQ-block of ACF `faqs`), contact (bestaande slug-detect), search/cart/checkout/account (`wc_get_page_id`), author (auteur met gepubliceerde posts).
- **Handmatig (page-picker in settings):** over-ons, bedrijfsreviews, vestiging, merken, actie, dienst, recepten.
- Paginatype zonder bruikbare URL (niet ingevuld én niet auto) → **overgeslagen** in de scanner.

### 3. Suggestie-engine (uitbreiding scanner)
Per geselecteerd paginatype: scan-resultaat (gedetecteerde schema's + bron) vergelijken met `matrix->get($type)`:
- mist `always`-type → suggestie **add (vereist)**
- mist `optional`-type → suggestie **add (optioneel)**
- aanwezig type ∈ `forbidden` (of `forbidden=['*']`) → suggestie **remove** (met bron-label)
- aanwezig en in `always`/`optional` → **ok**

Output per suggestie: `{ action: add|remove, schema_type, priority: required|optional, source, applicable: bool, note }`.

### 4. 1-klik toepassen

**Toevoegen (add):**
- Schema dat RankRepair zelf bouwt → de bijbehorende **builder-toggle aanzetten** voor dat paginatype.
- Overig schema (Recipe, Service, Brand, Person, HowTo, Video, …) → een **JSON-LD-sjabloon** vooringevuld in het bestaande **custom-JSON-LD-veld** van dat type; de gebruiker maakt het af.

**Weghalen (remove) — per bron:**
- **RankRepair** → builder-toggle uit (per paginatype).
- **Yoast** → officiële filters: `wpseo_schema_graph_pieces` en per-stuk `wpseo_schema_<piece>` → `false` om het type uit de graph te halen.
- **Rank Math** → `rank_math/json_ld`-filter → de schema-entry uit de array verwijderen.
- **Thema / onbekende bron** (hardcoded in template) → **optionele** output-buffer-strip op `wp_head`/`wp_footer` die het matchende JSON-LD-blok eruit knipt. **Standaard uit**, aparte "forceer verwijderen"-toggle met waarschuwing (fragiel: kan breken bij theme-updates, risico op te veel weghalen).

**Opslag:** een "weghalen"-klik schrijft een **onderdrukkingsregel** weg (`page_type` + `schema_type` + `source`) in een option (bijv. `rr_sd_suppress_rules`). De injector/filters lezen die regels en passen ze toe. Herstelbaar: regel verwijderen = schema komt terug.

### 5. Settings (uitbreiding `register_settings_section`)
- **Per paginatype:** schema-toggles die de matrix-standaard overschrijven.
- **Per handmatig type:** een pagina/URL-keuze (page-picker).
- **Geavanceerd:** "forceer verwijderen via output-buffer" aan/uit (met waarschuwing).

## Datastromen
- **Settings → matrix:** overrides mergen op de standaard.
- **Resolver → scanner:** URL per paginatype.
- **Scanner (scan) + matrix → suggestie-engine:** add/remove-lijst.
- **Toepassen → options:** builder-toggles, custom-JSON-LD-templates, `rr_sd_suppress_rules`.
- **Injectie/filters → front-end:** add via builders/custom JSON-LD; remove via RR-toggle, Yoast/Rank Math-filters, of (opt-in) output-buffer.

## Grenzen / aannames
- Suggesties zijn per **representatieve sample-pagina** per type, niet per individuele URL.
- Remove voor thema-bron is best-effort en standaard uit (fragiel).
- Sjablonen voor niet-gebouwde schema's zijn invul-skeletten, geen automatisch ingevulde data.
- Paginatypes die niet op de site bestaan worden overgeslagen.

## Implementatie in fasen
1. **Matrix + suggestie-engine (add/remove)** op de al-gedetecteerde paginatypes; `suggested_schemas_for()` vervangen door matrix-vergelijking, remove-suggesties tonen.
2. **Paginatype-resolver uitbreiden** (alle ~18 types) + handmatige page-picker in settings.
3. **1-klik toepassen:** add (builder-toggle / custom-template) + remove (RR-toggle, Yoast/Rank Math-filters, opt-in output-buffer).

## Verificatie
Geen PHPUnit-suite aanwezig → verificatie via:
- `php -l` op gewijzigde bestanden.
- Handmatig in de scanner-UI per paginatype: suggesties kloppen met de matrix; toepassen schrijft de juiste option/regel; front-end JSON-LD verandert zoals verwacht (controle met Rich Results Test / scanner-herscan).
- Yoast- en Rank Math-onderdrukking testen op een pagina waar die plugin het schema levert.
