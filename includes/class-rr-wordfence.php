<?php
/**
 * RR_Wordfence — leest de laatste Wordfence-scanuitslag (READ-ONLY) en leidt er
 * een "security"-blok uit af voor de Level4-heartbeat. Versie-robuust: probeert
 * eerst Wordfence's eigen klassen/opties, valt terug op de database, en degradeert
 * netjes (installed:false) als Wordfence afwezig is of nog nooit gescand heeft.
 * Mag de heartbeat NOOIT laten falen — alle publieke entrypoints vangen Throwable.
 *
 * Bevat daarnaast een best-effort installer/configurator voor de bulk-uitrol.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Wordfence {

    /** Max aantal issues dat in de heartbeat wordt meegestuurd (criticals eerst). */
    const MAX_ISSUES = 25;

    // =====================================================================
    // Public entrypoint (defensief) — gebruikt door de heartbeat
    // =====================================================================

    /**
     * Retourneert het security-blok volgens het Level4-contract.
     * Vangt élke fout af zodat de heartbeat nooit breekt.
     */
    public static function get_security_block(): array {
        try {
            $normalized = self::read_wordfence();
            return self::derive_security_block($normalized);
        } catch (\Throwable $e) {
            // Nooit de heartbeat laten sneuvelen; onbekende staat = niet-geïnstalleerd tonen.
            return ['scanner' => 'wordfence', 'installed' => false];
        }
    }

    // =====================================================================
    // PURE: verdict-afleiding (unit-getest, geen WordPress nodig)
    // =====================================================================

    /**
     * @param array $n Genormaliseerd: [
     *   'installed' => bool,
     *   'last_scan_at' => int|null (unix ts),
     *   'last_scan_status' => 'completed'|'failed'|'running'|'never',
     *   'issues' => array<array{type:string,severity:string,detail:string,is_malware:bool,is_core_modified:bool}>,
     * ]
     */
    public static function derive_security_block(array $n, string $scanner = 'wordfence'): array {
        if (empty($n['installed'])) {
            return ['scanner' => $scanner, 'installed' => false];
        }

        $issues = isset($n['issues']) && is_array($n['issues']) ? $n['issues'] : [];
        $status = isset($n['last_scan_status']) ? (string) $n['last_scan_status'] : 'never';
        if (!in_array($status, ['completed', 'failed', 'running', 'never'], true)) {
            $status = 'never';
        }

        $critical_count = 0;
        $warning_count  = 0;
        $has_critical_finding = false;
        foreach ($issues as $iss) {
            if (!empty($iss['is_malware']) || !empty($iss['is_core_modified'])) {
                $has_critical_finding = true;
            }
            if (isset($iss['severity']) && $iss['severity'] === 'critical') {
                $critical_count++;
            } else {
                $warning_count++;
            }
        }

        // Verdict-regels.
        if ($has_critical_finding) {
            $verdict = 'critical';
        } elseif (!empty($issues)) {
            $verdict = 'issues';
        } elseif ($status === 'completed') {
            $verdict = 'clean';
        } else {
            // never / failed / running met geen bevindingen -> nooit 'clean'.
            $verdict = 'unknown';
        }

        // Issues sorteren (criticals eerst) en cappen; alleen contract-keys teruggeven.
        $sorted = $issues;
        usort($sorted, function ($a, $b) {
            $sa = (isset($a['severity']) && $a['severity'] === 'critical') ? 0 : 1;
            $sb = (isset($b['severity']) && $b['severity'] === 'critical') ? 0 : 1;
            return $sa <=> $sb;
        });
        $capped = array_slice($sorted, 0, self::MAX_ISSUES);
        $out_issues = array_map(function ($iss) {
            return [
                'type'     => isset($iss['type']) ? (string) $iss['type'] : 'other',
                'severity' => (isset($iss['severity']) && $iss['severity'] === 'critical') ? 'critical' : 'warning',
                'detail'   => isset($iss['detail']) ? (string) $iss['detail'] : '',
            ];
        }, $capped);

        $ts = isset($n['last_scan_at']) && $n['last_scan_at'] ? (int) $n['last_scan_at'] : null;

        return [
            'scanner'          => $scanner,
            'installed'        => true,
            'last_scan_at'     => $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null,
            'last_scan_status' => $status,
            'verdict'          => $verdict,
            'counts'           => ['critical' => $critical_count, 'warning' => $warning_count],
            'issues'           => $out_issues,
        ];
    }

    /**
     * PURE: mapt een rauwe Wordfence-bevinding naar een genormaliseerde issue.
     * Versie-robuust: leunt op keywords in type/bericht en zowel moderne (0-100)
     * als legacy (1/2) severity-schalen.
     *
     * @param string     $type     Wordfence issue-type (bv. 'file', 'knownfile', 'wfPluginVulnerable').
     * @param int|string $severity Numerieke severity (modern 0-100, legacy 1/2) of string.
     * @param string     $message  Korte/lange melding.
     * @return array{type:string,severity:string,detail:string,is_malware:bool,is_core_modified:bool}
     */
    public static function classify_issue($type, $severity, string $message): array {
        $type_l = strtolower((string) $type);
        $msg_l  = strtolower($message);

        $is_malware = self::contains_any($msg_l, [
            'malicious', 'malware', 'backdoor', 'trojan', 'suspected', 'infected',
            'known bad', 'signature match', 'this file may contain malicious',
        ]) || self::contains_any($type_l, ['knownmalware', 'malware']);

        $is_core_modified = self::contains_any($type_l, ['knownfile', 'coremodified', 'coreunknown'])
            || (self::contains_any($msg_l, ['core file', 'wordpress core', 'core, theme']) &&
                self::contains_any($msg_l, ['modified', 'changed', 'unexpected', 'not match', 'differs', 'altered']));

        // Genormaliseerd type.
        if ($is_malware) {
            $ntype = 'malware_file';
        } elseif ($is_core_modified) {
            $ntype = 'core_modified';
        } elseif (self::contains_any($msg_l . ' ' . $type_l, ['vulnerab', 'out of date', 'abandoned', 'end of life', 'removed from'])) {
            $ntype = 'plugin_vuln';
        } elseif (self::contains_any($msg_l, ['unknown file', 'suspicious', 'unusual', 'unexpected file', 'contains suspicious'])) {
            $ntype = 'suspicious_file';
        } else {
            $ntype = 'other';
        }

        $sev = self::normalize_severity($severity);
        // Malware / gewijzigd core is per definitie kritiek.
        if ($is_malware || $is_core_modified) {
            $sev = 'critical';
        }

        return [
            'type'             => $ntype,
            'severity'         => $sev,
            'detail'           => self::sanitize_detail($message),
            'is_malware'       => $is_malware,
            'is_core_modified' => $is_core_modified,
        ];
    }

    // =====================================================================
    // Reading Wordfence (WordPress-afhankelijk, read-only)
    // =====================================================================

    /**
     * Leest de Wordfence-staat uit. Probeert eigen klassen/opties, valt terug op DB.
     * @return array Genormaliseerd, zie derive_security_block().
     */
    public static function read_wordfence(): array {
        if (!self::is_installed()) {
            return ['installed' => false];
        }

        $last_scan_at = self::read_scan_time();
        $status       = self::read_scan_status($last_scan_at);
        $issues       = self::read_issues();

        // Konden we de bevindingen niet betrouwbaar lezen (leesfout / onbekend schema)?
        // Dan NOOIT als 'clean' rapporteren: forceer een niet-voltooide status zodat het
        // verdict 'unknown' wordt. Beter een vals "controleren" dan een vals "schoon".
        if ($issues === null) {
            return [
                'installed'        => true,
                'last_scan_at'     => $last_scan_at,
                'last_scan_status' => ($status === 'running') ? 'running' : 'failed',
                'issues'           => [],
            ];
        }

        return [
            'installed'        => true,
            'last_scan_at'     => $last_scan_at,
            'last_scan_status' => $status,
            'issues'           => $issues,
        ];
    }

    private static function is_installed(): bool {
        if (class_exists('wfConfig') || defined('WORDFENCE_VERSION')) {
            return true;
        }
        // DB-fallback: bestaat de wfConfig-tabel?
        global $wpdb;
        if (!isset($wpdb)) {
            return false;
        }
        $table = self::wf_table('wfConfig');
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }

    /** Wordfence-tabellen staan op het base-prefix (netwerk-breed). */
    private static function wf_table(string $name): string {
        global $wpdb;
        $prefix = isset($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix;
        return $prefix . $name;
    }

    /** wfConfig-waarde via klasse of DB-fallback. */
    private static function wf_config($key, $default = null) {
        if (class_exists('wfConfig') && method_exists('wfConfig', 'get')) {
            try {
                $val = wfConfig::get($key, $default);
                if ($val !== null && $val !== false) {
                    return $val;
                }
            } catch (\Throwable $e) {
                // val terug naar DB
            }
        }
        global $wpdb;
        if (!isset($wpdb)) {
            return $default;
        }
        $table = self::wf_table('wfConfig');
        $val = $wpdb->get_var($wpdb->prepare("SELECT val FROM {$table} WHERE name = %s", $key));
        return $val === null ? $default : $val;
    }

    private static function read_scan_time(): ?int {
        $t = self::wf_config('scanTime', null);
        if ($t === null || $t === '' ) {
            return null;
        }
        $ts = (int) $t;
        return $ts > 0 ? $ts : null;
    }

    /**
     * Bepaal scanstatus. Wordfence houdt 'lastScanCompleted' bij: 'ok' bij succes,
     * anders een foutstring; leeg = nog nooit. 'scanRunning' geeft een lopende scan aan.
     */
    private static function read_scan_status(?int $scan_time): string {
        // Loopt er een scan?
        if (self::is_scan_running()) {
            return 'running';
        }
        $completed = self::wf_config('lastScanCompleted', '');
        if ($completed === 'ok') {
            return 'completed';
        }
        if (is_string($completed) && $completed !== '') {
            // Niet-lege, niet-'ok' waarde = mislukte/afgebroken scan.
            return 'failed';
        }
        // Geen positief 'ok'-signaal. NOOIT naar 'completed' defaulten — dat zou tot
        // een vals 'clean'-verdict kunnen leiden op een besmette site. Is er wél ooit
        // een scan geweest (scanTime) maar kunnen we voltooiing niet bevestigen, dan
        // 'failed' (levert 'unknown' op zonder bevindingen); anders nog nooit gescand.
        return $scan_time ? 'failed' : 'never';
    }

    private static function is_scan_running(): bool {
        // Moderne API.
        if (class_exists('wfScanner') && method_exists('wfScanner', 'shared')) {
            try {
                $scanner = wfScanner::shared();
                if ($scanner && method_exists($scanner, 'isRunning')) {
                    return (bool) $scanner->isRunning();
                }
            } catch (\Throwable $e) {
                // val terug op config
            }
        }
        $running = self::wf_config('wf_scanRunning', self::wf_config('scanRunning', 0));
        return !empty($running);
    }

    /**
     * Leest open issues uit de wfIssues-tabel (de stabielste interface over versies).
     * Alleen 'new' (openstaande) issues tellen mee.
     *
     * BELANGRIJK: retourneert null wanneer de bevindingen NIET betrouwbaar te lezen zijn
     * (geen $wpdb, tabel onvindbaar, of een DB-leesfout). Een lege array betekent
     * uitsluitend "met zekerheid geen open bevindingen". De aanroeper mag null nooit als
     * 'geen bevindingen' behandelen — dat zou een besmette site vals 'clean' maken.
     *
     * @return array|null Lijst genormaliseerde issues (zie classify_issue()), of null bij leesfout.
     */
    private static function read_issues(): ?array {
        global $wpdb;
        if (!isset($wpdb)) {
            return null;
        }
        $table  = self::wf_table('wfIssues');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            // Wordfence aanwezig maar issues-tabel onvindbaar: staat onbekend, niet "schoon".
            return null;
        }

        $rows = $wpdb->get_results(
            "SELECT type, severity, shortMsg, longMsg FROM {$table} WHERE status = 'new'",
            ARRAY_A
        );
        // Een DB-fout (bv. afwijkend schema/kolomnaam) mag niet als "geen bevindingen" gelden.
        if (!empty($wpdb->last_error) || !is_array($rows)) {
            return null;
        }

        $out = [];
        foreach ($rows as $row) {
            $msg = trim((string) ($row['shortMsg'] ?? ''));
            if ($msg === '') {
                $msg = (string) ($row['longMsg'] ?? '');
            }
            $out[] = self::classify_issue(
                (string) ($row['type'] ?? ''),
                $row['severity'] ?? 0,
                $msg
            );
        }
        return $out;
    }

    // =====================================================================
    // Best-effort installer + configurator (taak #3, bulk-uitrol)
    // =====================================================================

    /**
     * Installeert + activeert + configureert Wordfence uniform. Best-effort en
     * versie-robuust: elke stap is guarded, en het resultaat vermeldt wat lukte.
     * @return array{ok:bool,from:?string,to:?string,note:?string,error:?string,steps:array}
     */
    public static function install_and_configure(string $slug = 'wordfence'): array {
        $result = ['ok' => false, 'from' => null, 'to' => null, 'note' => null, 'error' => null, 'steps' => []];

        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            $plugin_file = 'wordfence/wordfence.php';
            $result['from'] = self::plugin_version($plugin_file);

            // 1) Installeren (indien nog niet aanwezig).
            if ($result['from'] === null) {
                $zip = 'https://downloads.wordpress.org/plugin/wordfence.latest-stable.zip';
                $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
                $install  = $upgrader->install($zip);
                if (is_wp_error($install)) {
                    $result['error'] = 'install_failed: ' . $install->get_error_message();
                    return $result;
                }
                if ($install !== true) {
                    $result['error'] = 'install_failed';
                    return $result;
                }
                $result['steps']['installed'] = true;
                wp_clean_plugins_cache(true);
            } else {
                $result['steps']['installed'] = 'already_present';
            }

            // 2) Activeren.
            if (!is_plugin_active($plugin_file)) {
                $act = activate_plugin($plugin_file, '', false, true);
                if (is_wp_error($act)) {
                    $result['error'] = 'activate_failed: ' . $act->get_error_message();
                    $result['to'] = self::plugin_version($plugin_file);
                    return $result;
                }
            }
            $result['steps']['active'] = is_plugin_active($plugin_file);
            $result['to'] = self::plugin_version($plugin_file);

            // 3) Uniform configureren (best-effort; alleen als wfConfig geladen is).
            $result['steps']['configured'] = self::configure_defaults();

            $result['ok'] = is_plugin_active($plugin_file);
            if ($result['from'] !== null && $result['from'] === $result['to']) {
                $result['note'] = 'already_present';
            }
        } catch (\Throwable $e) {
            $result['error'] = 'exception: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Zet een uniforme scan-config: geplande scan aan met volledige scope,
     * en zet Wordfence's EIGEN e-mailalerts uit (Level4 is de centrale melder).
     * @return array<string,bool> per instelling of het lukte.
     */
    private static function configure_defaults(): array {
        $done = [];
        if (!class_exists('wfConfig') || !method_exists('wfConfig', 'set')) {
            return ['available' => false];
        }

        // Geplande scans aan.
        $set = [
            'scheduledScansEnabled'        => 1,
            // Scope: signatures, gewijzigde core/thema/plugin-bestanden, verdachte bestanden, kwaadaardige URLs.
            'scansEnabled_malware'         => 1,
            'scansEnabled_core'            => 1,
            'scansEnabled_themes'          => 1,
            'scansEnabled_plugins'         => 1,
            'scansEnabled_coreUnknown'     => 1,
            'scansEnabled_fileContents'    => 1,
            'scansEnabled_suspectedFiles'  => 1,
            'scansEnabled_malwareScanIgnores' => 0,
            'scansEnabled_checkGSB'        => 1,  // Google Safe Browsing: kwaadaardige URLs/redirects.
            'scansEnabled_checkHowGetIPs'  => 1,
            'scansEnabled_highSense'       => 1,
            'scansEnabled_scanImages'      => 1,
            // Wordfence's eigen e-mailalerts UIT (geen dubbele meldingen).
            'alertEmails'                  => '',
            'alertOn_scanIssues'           => 0,
            'alertOn_critical'             => 0,
            'alertOn_warnings'             => 0,
            'scanAlertOn'                  => 0,
        ];
        foreach ($set as $key => $val) {
            try {
                wfConfig::set($key, $val);
                $done[$key] = true;
            } catch (\Throwable $e) {
                $done[$key] = false;
            }
        }

        // Gratis threat-feed registreren als er nog geen API-key is.
        try {
            $existing = self::wf_config('apiKey', '');
            if (empty($existing) && class_exists('wfAPI') && class_exists('wfConfig')) {
                // Best-effort: probeer een gratis key te registreren via de publieke API.
                if (method_exists('wfAPI', 'call')) {
                    $api = new wfAPI('', wfConfig::get('apiKey', ''));
                    if (method_exists($api, 'call')) {
                        $keyData = $api->call('get_anon_api_key');
                        if (is_array($keyData) && !empty($keyData['apiKey'])) {
                            wfConfig::set('apiKey', $keyData['apiKey']);
                            $done['apiKey'] = true;
                        }
                    }
                }
            } else {
                $done['apiKey'] = 'already_set';
            }
        } catch (\Throwable $e) {
            $done['apiKey'] = false;
        }

        return $done;
    }

    private static function plugin_version(string $plugin_file): ?string {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        return isset($all[$plugin_file]['Version']) ? (string) $all[$plugin_file]['Version'] : null;
    }

    // =====================================================================
    // Kleine helpers (puur)
    // =====================================================================

    private static function contains_any(string $haystack, array $needles): bool {
        foreach ($needles as $n) {
            if ($n !== '' && strpos($haystack, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Normaliseert diverse severity-schalen naar 'critical'|'warning'. */
    private static function normalize_severity($severity): string {
        if (is_string($severity)) {
            $s = strtolower(trim($severity));
            if ($s === 'critical' || $s === 'high') {
                return 'critical';
            }
            if (is_numeric($s)) {
                $severity = (float) $s;
            } else {
                return 'warning';
            }
        }
        if (is_numeric($severity)) {
            $v = (float) $severity;
            // Legacy Wordfence: 2 = critical, 1 = warning.
            if ($v === 2.0 || $v === 1.0) {
                return $v >= 2.0 ? 'critical' : 'warning';
            }
            // Modern: 0-100 (CRITICAL=100, HIGH=75, MEDIUM=50, LOW=25).
            return $v >= 75.0 ? 'critical' : 'warning';
        }
        return 'warning';
    }

    /** Kort, veilig detail-veld voor de heartbeat. */
    private static function sanitize_detail(string $message): string {
        $msg = trim(wp_strip_all_tags_safe($message));
        if (function_exists('mb_substr')) {
            return mb_substr($msg, 0, 300, 'UTF-8');
        }
        return substr($msg, 0, 300);
    }
}

/**
 * wp_strip_all_tags-wrapper die ook zonder WordPress werkt (voor unit-tests).
 */
if (!function_exists('wp_strip_all_tags_safe')) {
    function wp_strip_all_tags_safe($text) {
        if (function_exists('wp_strip_all_tags')) {
            return wp_strip_all_tags($text);
        }
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        return trim(strip_tags($text));
    }
}
