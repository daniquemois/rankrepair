<?php
/**
 * Pure unit tests for RR_Wordfence classify_issue() + derive_security_block().
 * Run: /opt/homebrew/bin/php tests/wordfence/test-rr-wordfence.php
 */
define('ABSPATH', true);

// Minimal WP shims used by the PURE methods (none needed today, but keep the
// require isolated so loading the class doesn't pull WordPress).
require __DIR__ . '/../../includes/class-rr-wordfence.php';

function ok($cond, $msg) {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "ok: $msg\n";
}

// ---------------------------------------------------------------------------
// classify_issue
// ---------------------------------------------------------------------------

$malware = RR_Wordfence::classify_issue('file', 100, 'This file appears to be malicious: wp-content/uploads/x.php');
ok($malware['is_malware'] === true, 'malware message flagged as malware');
ok($malware['type'] === 'malware_file', 'malware normalized type');
ok($malware['severity'] === 'critical', 'malware is critical severity');

$core = RR_Wordfence::classify_issue('knownfile', 75, 'Modified WordPress core file: wp-includes/load.php');
ok($core['is_core_modified'] === true, 'knownfile flagged as core modified');
ok($core['type'] === 'core_modified', 'core normalized type');
ok($core['severity'] === 'critical', 'core modified is critical');

$vuln = RR_Wordfence::classify_issue('wfPluginVulnerable', 50, 'The Plugin "Foo" has a known vulnerability.');
ok($vuln['is_malware'] === false && $vuln['is_core_modified'] === false, 'vuln not malware/core');
ok($vuln['type'] === 'plugin_vuln', 'vuln normalized type');
ok($vuln['severity'] === 'warning', 'medium vuln is warning');

$susp = RR_Wordfence::classify_issue('file', 25, 'Unknown file in WordPress core directory');
ok($susp['type'] === 'suspicious_file', 'unknown file -> suspicious_file');
ok($susp['severity'] === 'warning', 'low severity is warning');

// Legacy numeric severity: 2 = critical in old Wordfence
$legacy = RR_Wordfence::classify_issue('file', 2, 'Something bad');
ok($legacy['severity'] === 'critical', 'legacy severity 2 => critical');
$legacy1 = RR_Wordfence::classify_issue('file', 1, 'Something minor');
ok($legacy1['severity'] === 'warning', 'legacy severity 1 => warning');

// ---------------------------------------------------------------------------
// derive_security_block
// ---------------------------------------------------------------------------

// Not installed
$ni = RR_Wordfence::derive_security_block(['installed' => false]);
ok($ni === ['scanner' => 'wordfence', 'installed' => false], 'not installed -> minimal block');

// Critical: one malware issue
$crit = RR_Wordfence::derive_security_block([
    'installed'        => true,
    'last_scan_at'     => 1755310440, // 2025-08-16T02:14:00Z
    'last_scan_status' => 'completed',
    'issues'           => [
        ['type' => 'malware_file', 'severity' => 'critical', 'detail' => 'Kwaadaardige redirect in header.php', 'is_malware' => true, 'is_core_modified' => false],
        ['type' => 'suspicious_file', 'severity' => 'warning', 'detail' => 'x', 'is_malware' => false, 'is_core_modified' => false],
    ],
]);
ok($crit['verdict'] === 'critical', 'malware present -> critical verdict');
ok($crit['counts'] === ['critical' => 1, 'warning' => 1], 'counts split by severity');
ok($crit['scanner'] === 'wordfence' && $crit['installed'] === true, 'critical block shape');
ok($crit['last_scan_status'] === 'completed', 'status passthrough');
ok($crit['last_scan_at'] === '2025-08-16T02:14:00Z', 'ISO8601 last_scan_at');
ok(count($crit['issues']) === 2, 'issues included');

// Core modified also -> critical
$coremod = RR_Wordfence::derive_security_block([
    'installed' => true, 'last_scan_at' => null, 'last_scan_status' => 'completed',
    'issues' => [['type' => 'core_modified', 'severity' => 'critical', 'detail' => 'load.php', 'is_malware' => false, 'is_core_modified' => true]],
]);
ok($coremod['verdict'] === 'critical', 'core modified -> critical');
ok($coremod['last_scan_at'] === null, 'null last_scan_at preserved');

// Issues but nothing critical
$iss = RR_Wordfence::derive_security_block([
    'installed' => true, 'last_scan_at' => 1755310440, 'last_scan_status' => 'completed',
    'issues' => [['type' => 'plugin_vuln', 'severity' => 'warning', 'detail' => 'old plugin', 'is_malware' => false, 'is_core_modified' => false]],
]);
ok($iss['verdict'] === 'issues', 'non-critical findings -> issues');
ok($iss['counts'] === ['critical' => 0, 'warning' => 1], 'issues counts');

// Clean: no issues + completed
$clean = RR_Wordfence::derive_security_block([
    'installed' => true, 'last_scan_at' => 1755310440, 'last_scan_status' => 'completed', 'issues' => [],
]);
ok($clean['verdict'] === 'clean', 'no issues + completed -> clean');
ok($clean['counts'] === ['critical' => 0, 'warning' => 0], 'clean counts zero');

// SAFETY: failed scan with no issues must NOT be clean
$failed = RR_Wordfence::derive_security_block([
    'installed' => true, 'last_scan_at' => 1755310440, 'last_scan_status' => 'failed', 'issues' => [],
]);
ok($failed['verdict'] === 'unknown', 'failed scan + no issues -> unknown (never clean)');

// SAFETY: running scan -> unknown when no issues
$running = RR_Wordfence::derive_security_block([
    'installed' => true, 'last_scan_at' => null, 'last_scan_status' => 'running', 'issues' => [],
]);
ok($running['verdict'] === 'unknown', 'running scan + no issues -> unknown');

// never scanned -> unknown
$never = RR_Wordfence::derive_security_block([
    'installed' => true, 'last_scan_at' => null, 'last_scan_status' => 'never', 'issues' => [],
]);
ok($never['verdict'] === 'unknown', 'never scanned -> unknown');

// SAFETY: failed scan but leftover malware from before -> still critical (findings win)
$failed_but_crit = RR_Wordfence::derive_security_block([
    'installed' => true, 'last_scan_at' => 1755310440, 'last_scan_status' => 'failed',
    'issues' => [['type' => 'malware_file', 'severity' => 'critical', 'detail' => 'x', 'is_malware' => true, 'is_core_modified' => false]],
]);
ok($failed_but_crit['verdict'] === 'critical', 'leftover malware on failed scan -> critical');

// issues capping: >25 issues get capped, criticals first
$many = [];
for ($i = 0; $i < 30; $i++) {
    $many[] = ['type' => 'suspicious_file', 'severity' => 'warning', 'detail' => "w$i", 'is_malware' => false, 'is_core_modified' => false];
}
$many[] = ['type' => 'malware_file', 'severity' => 'critical', 'detail' => 'CRIT', 'is_malware' => true, 'is_core_modified' => false];
$capped = RR_Wordfence::derive_security_block([
    'installed' => true, 'last_scan_at' => 1755310440, 'last_scan_status' => 'completed', 'issues' => $many,
]);
ok(count($capped['issues']) === 25, 'issues capped at 25');
ok($capped['issues'][0]['severity'] === 'critical', 'critical issue sorted first when capping');
ok($capped['counts'] === ['critical' => 1, 'warning' => 30], 'counts reflect ALL issues, not capped list');
// Output issues must only carry the contract keys
ok(array_keys($capped['issues'][0]) === ['type', 'severity', 'detail'], 'output issue has only contract keys');

echo "ALL PASS\n";
