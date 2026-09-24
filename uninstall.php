<?php
/**
 * Removes every table, option, transient and scheduled event ClickWarden created.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- removing the plugin's own tables on uninstall.
$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'clickwarden_hits'));
$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'clickwarden_ips'));
// phpcs:enable

foreach (['clickwarden_settings', 'clickwarden_db_version', 'clickwarden_installed_at', 'clickwarden_ads_token', 'clickwarden_ads_sync'] as $clickwarden_option) {
    delete_option($clickwarden_option);
}

foreach (['clickwarden_geo_lock', 'clickwarden_geo_backoff', 'clickwarden_geo_backoff_ipapiis', 'clickwarden_geo_backoff_proxycheck'] as $clickwarden_transient) {
    delete_transient($clickwarden_transient);
}

foreach (['clickwarden_geo_queue', 'clickwarden_rescore', 'clickwarden_purge'] as $clickwarden_hook) {
    wp_clear_scheduled_hook($clickwarden_hook);
}

delete_metadata('user', 0, 'clickwarden_per_page', '', true);
