<?php
/**
 * Plugin Name: RankRepair
 * Plugin URI: https://example.com/rankrepair
 * Description: Los veelvoorkomende SEO- en performance-problemen op met één klik. Dashboard met PageSpeed integratie en modulaire add-ons.
 * Version: 1.2.2
 * Author: Danique
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rankrepair
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RR_VERSION', '1.2.2');
define('RR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RR_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('RR_PLUGIN_FILE', __FILE__);

/**
 * Main RankRepair Class
 */
final class RankRepair {

    private static $instance = null;
    private $addons = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->register_addons();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once RR_PLUGIN_DIR . 'includes/class-rr-settings.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-pagespeed.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-dashboard.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-addon-base.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-ajax-handler.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-updater.php';

        $updater = new RR_Updater( RR_PLUGIN_FILE, RR_VERSION );
        $updater->init();
    }

    /**
     * Register add-ons
     * Modulaire structuur: voeg hier nieuwe add-ons toe
     */
    private function register_addons() {
        $addon_files = [
            'meta-manager' => RR_PLUGIN_DIR . 'addons/meta-manager/class-addon-meta-manager.php',
            // Toekomstige add-ons:
            // 'redirects-checker' => RR_PLUGIN_DIR . 'addons/redirects-checker/class-addon-redirects-checker.php',
            'image-optimizer'   => RR_PLUGIN_DIR . 'addons/image-optimizer/class-addon-image-optimizer.php',
            // 'form-tester'       => RR_PLUGIN_DIR . 'addons/form-tester/class-addon-form-tester.php',
        ];

        foreach ($addon_files as $slug => $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }

        $this->addons = apply_filters('rr_registered_addons', $this->addons);
    }

    public function register_addon($slug, $addon_instance) {
        $this->addons[$slug] = $addon_instance;
    }

    public function get_addons() {
        return $this->addons;
    }

    public function get_addon($slug) {
        return isset($this->addons[$slug]) ? $this->addons[$slug] : null;
    }

    private function init_hooks() {
        register_activation_hook(RR_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(RR_PLUGIN_FILE, [$this, 'deactivate']);

        // Zorg dat tabellen altijd bestaan, ook na handmatige plugin-upload
        add_action('plugins_loaded', [$this, 'maybe_create_tables'], 5);

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('init', [$this, 'load_textdomain']);

    }

    public function maybe_create_tables() {
        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $this->create_tables();
        } else {
            // Voer migraties altijd uit, ook voor bestaande installaties
            $this->run_migrations();
        }
    }

    private function run_migrations() {
        global $wpdb;
        $table_meta = $wpdb->prefix . 'rr_meta_data';

        // Migratie: voeg current_h1 toe als die nog niet bestaat
        $col = $wpdb->get_results("SHOW COLUMNS FROM $table_meta LIKE 'current_h1'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE $table_meta ADD COLUMN current_h1 varchar(500) DEFAULT NULL AFTER url");
        }
    }

    public function activate() {
        $this->create_tables();

        $defaults = [
            'rr_pagespeed_api_key' => '',
            'rr_seranking_api_key' => '',
            'rr_gemini_api_key'    => '',
            'rr_gemini_prompt'     => '',
            'rr_ai_provider'       => 'google',
            'rr_ai_model'          => '',
        ];

        foreach ($defaults as $key => $value) {
            if (false === get_option($key)) {
                add_option($key, $value);
            }
        }

        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // PageSpeed results cache
        $table_pagespeed = $wpdb->prefix . 'rr_pagespeed_results';
        $sql_pagespeed = "CREATE TABLE $table_pagespeed (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(500) NOT NULL,
            strategy varchar(10) NOT NULL DEFAULT 'mobile',
            score_performance decimal(5,2) DEFAULT NULL,
            score_accessibility decimal(5,2) DEFAULT NULL,
            score_best_practices decimal(5,2) DEFAULT NULL,
            score_seo decimal(5,2) DEFAULT NULL,
            audits longtext DEFAULT NULL,
            opportunities longtext DEFAULT NULL,
            diagnostics longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY url_strategy (url(191), strategy)
        ) $charset_collate;";

        // Meta titels en beschrijvingen
        $table_meta = $wpdb->prefix . 'rr_meta_data';
        $sql_meta = "CREATE TABLE $table_meta (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) DEFAULT NULL,
            url varchar(500) NOT NULL,
            current_h1 varchar(500) DEFAULT NULL,
            current_title varchar(500) DEFAULT NULL,
            current_description text DEFAULT NULL,
            new_title varchar(500) DEFAULT NULL,
            new_description text DEFAULT NULL,
            title_length int(11) DEFAULT NULL,
            description_length int(11) DEFAULT NULL,
            is_duplicate_title tinyint(1) DEFAULT 0,
            is_duplicate_description tinyint(1) DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            imported_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY url (url(191)),
            KEY status (status),
            KEY post_id (post_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_pagespeed);
        dbDelta($sql_meta);
    }

    public function register_admin_menu() {
        // Hoofdmenu
        add_menu_page(
            __('RankRepair', 'rankrepair'),
            __('RankRepair', 'rankrepair'),
            'manage_options',
            'rankrepair',
            [$this, 'render_dashboard'],
            'dashicons-performance',
            30
        );

        // Dashboard submenu
        add_submenu_page(
            'rankrepair',
            __('Dashboard', 'rankrepair'),
            __('Dashboard', 'rankrepair'),
            'manage_options',
            'rankrepair',
            [$this, 'render_dashboard']
        );

        // Instellingen submenu
        add_submenu_page(
            'rankrepair',
            __('Instellingen', 'rankrepair'),
            __('Instellingen', 'rankrepair'),
            'manage_options',
            'rankrepair-settings',
            [$this, 'render_settings']
        );

        // Add-on submenus
        foreach ($this->addons as $slug => $addon) {
            add_submenu_page(
                'rankrepair',
                $addon->get_name(),
                $addon->get_name(),
                'manage_options',
                'rankrepair-' . $slug,
                [$addon, 'render_page']
            );
        }
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'rankrepair') === false) {
            return;
        }

        $admin_css_ver = filemtime(RR_PLUGIN_DIR . 'admin/css/admin-style.css') ?: RR_VERSION;
        $admin_js_ver  = filemtime(RR_PLUGIN_DIR . 'admin/js/admin-script.js')  ?: RR_VERSION;

        wp_enqueue_style(
            'rr-admin-style',
            RR_PLUGIN_URL . 'admin/css/admin-style.css',
            [],
            $admin_css_ver
        );

        wp_enqueue_script(
            'rr-admin-script',
            RR_PLUGIN_URL . 'admin/js/admin-script.js',
            ['jquery'],
            $admin_js_ver,
            true
        );

        wp_localize_script('rr-admin-script', 'rrAdmin', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('rr_admin_nonce'),
            'pluginUrl' => RR_PLUGIN_URL,
            'strings'   => [
                'loading'   => __('Laden...', 'rankrepair'),
                'error'     => __('Er is een fout opgetreden', 'rankrepair'),
                'success'   => __('Succesvol opgeslagen', 'rankrepair'),
                'confirm'   => __('Weet je het zeker?', 'rankrepair'),
                'analyzing' => __('Analyseren...', 'rankrepair'),
                'noResults' => __('Geen resultaten gevonden', 'rankrepair'),
            ],
        ]);

        if (false !== strpos($hook, 'rankrepair-settings')) {
            wp_localize_script('rr-admin-script', 'rrSettings', [
                'defaultPrompt'      => rr_get_default_prompt_template(),
                'confirmResetPrompt' => __('Prompt terugzetten naar de standaard? Je aanpassingen gaan verloren.', 'rankrepair'),
            ]);
        }

        // Add-on assets
        foreach ($this->addons as $slug => $addon) {
            $addon->enqueue_assets($hook);
        }
    }

    public function render_dashboard() {
        $dashboard = new RR_Dashboard();
        $dashboard->render();
    }

    public function render_settings() {
        $settings = new RR_Settings();
        $settings->render();
    }

    public function load_textdomain() {
        load_plugin_textdomain('rankrepair', false, dirname(RR_PLUGIN_BASENAME) . '/languages');
    }
}

function rankrepair() {
    return RankRepair::get_instance();
}

/**
 * Geeft het standaard AI-prompt template terug.
 * Bevat {{placeholders}} voor dynamische pagina-data.
 * Gebruiker kan dit in Instellingen volledig aanpassen.
 */
function rr_get_default_prompt_template() {
    return "Je bent een ervaren SEO-specialist. Schrijf een meta titel en meta beschrijving voor de onderstaande webpagina. Volg deze regels strikt:\n\nREGELS META TITEL:\n- Maximale breedte: 580px (Google knipt af bij ~580px). Houd de titel op MAXIMAAL 50 tekens om zeker onder deze pixellimiet te blijven — brede letters zoals H, W, M kosten meer pixels dan smalle letters.\n- Zoekintentiegericht: schrijf zoals de gebruiker zoekt en wil zien in de SERP.\n- Hoofdzoekwoord zo vroeg mogelijk in de titel.\n- GEEN bedrijfsnaam in de titel — Google verwijdert deze toch uit de SERP.\n- Gebruik leestekens (vraagteken, uitroepteken, streepje) om de titel dynamisch te maken.\n\nREGELS META BESCHRIJVING:\n- Maximale breedte: 920px (Google knipt af bij ~920px). Houd de beschrijving op MAXIMAAL 145 tekens om zeker onder deze pixellimiet te blijven.\n- Hoofdzoekwoord DIRECT aan het begin (front-loaded).\n- Sluit aan op de H1 en de highlights van de pagina.\n- Noem een concreet voordeel of USP.\n- Eindig met een call-to-action.\n\nTOON & STIJL:\nSchrijf in een vriendelijke en enthousiaste toon. Spreek de bezoeker direct aan met 'jij/je'.\n\nVOORBEELD:\nTitel:        Geld lenen? Vergelijk nu! Goedkoop én verantwoord lenen\nBeschrijving: Verantwoord geld lenen? Het meest complete aanbod leningen vergelijk je 100% onafhankelijk op Geld.nl. Vergelijk, kies en bespaar gemiddeld \u20ac 580!\n\nOUTPUT FORMAT (geef UITSLUITEND dit terug, niets anders):\nTITEL: [hier de titel]\nBESCHRIJVING: [hier de beschrijving]\n\nPAGINA-GEGEVENS:\nURL: {{url}}\n{{h1_line}}{{current_title_line}}{{current_desc_line}}{{content_line}}";
}

/**
 * Encrypt an API key for safe storage in the WordPress options table.
 * Falls back to plain-text if OpenSSL is unavailable.
 */
function rr_encrypt_key($value) {
    if (empty($value)) return '';
    if (!function_exists('openssl_encrypt') || !defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
        return $value;
    }
    $key       = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true);
    $iv        = random_bytes(16);
    $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) return $value;
    return 'rr_enc:' . base64_encode($iv . $encrypted);
}

/**
 * Decrypt a stored API key. Returns the original value unchanged if it was
 * never encrypted (backwards-compatible with plain-text stored keys).
 */
function rr_decrypt_key($stored) {
    if (empty($stored)) return '';
    if (strpos($stored, 'rr_enc:') !== 0) return $stored; // plain-text, legacy
    if (!function_exists('openssl_decrypt') || !defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
        return '';
    }
    $raw = base64_decode(substr($stored, 7));
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true);
    $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return ($dec === false) ? '' : $dec;
}

add_action('plugins_loaded', 'rankrepair');
