<?php
declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Default policy: retain plugin data on uninstall unless explicitly configured.
$settings = get_option('htd_ai_seo_settings', []);
$shouldDelete = is_array($settings) && !empty($settings['delete_data_on_uninstall']);

if (!$shouldDelete) {
	return;
}

global $wpdb;
$prefix = $wpdb->prefix . 'htd_ai_seo_';
$tables = [
	$prefix . 'job_attempts',
	$prefix . 'queue',
	$prefix . 'approvals',
	$prefix . 'security_events',
	$prefix . 'logs',
	$prefix . 'migrations',
];

foreach ($tables as $table) {
	$wpdb->query("DROP TABLE IF EXISTS {$table}");
}

delete_option('htd_ai_seo_settings');
delete_option('htd_ai_seo_db_version');
delete_option('htd_ai_seo_credentials');
delete_option('htd_ai_seo_credentials_key_version');
delete_option('htd_ai_seo_emergency_stop');
