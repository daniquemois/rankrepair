<?php
/**
 * RankRepair - Uninstall
 * Clean up all plugin data when uninstalled
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete options
$options = [
    'rr_pagespeed_api_key',
    'rr_seranking_api_key',
    'rr_db_version',
];

foreach ($options as $option) {
    delete_option($option);
}

// Drop custom tables
$tables = [
    $wpdb->prefix . 'rr_pagespeed_results',
    $wpdb->prefix . 'rr_meta_data',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

// Clear transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rr_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rr_%'");
