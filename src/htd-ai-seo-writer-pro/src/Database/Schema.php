<?php
declare(strict_types=1);

namespace HTD\AISEO\Database;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Defines versioned database table schemas using $wpdb->prefix.
 */
final class Schema {
	public static function prefix(object $wpdb): string {
		$wpPrefix = property_exists($wpdb, 'prefix') ? (string) $wpdb->prefix : 'wp_';
		return $wpPrefix . 'htd_ai_seo_';
	}

	public static function table(object $wpdb, string $name): string {
		return self::prefix($wpdb) . $name;
	}

	/**
	 * @return array<string, string>
	 */
	public static function v020Tables(object $wpdb, string $charsetCollate = ''): array {
		$p = self::prefix($wpdb);
		return [
			'migrations' => "CREATE TABLE IF NOT EXISTS {$p}migrations (
				version VARCHAR(32) NOT NULL,
				checksum VARCHAR(64) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'applied',
				applied_at_utc DATETIME NOT NULL,
				rolled_back_at_utc DATETIME NULL,
				error_message TEXT NULL,
				PRIMARY KEY (version)
			) {$charsetCollate};",

			'logs' => "CREATE TABLE IF NOT EXISTS {$p}logs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				correlation_id VARCHAR(64) NOT NULL,
				event_id VARCHAR(64) NOT NULL,
				module VARCHAR(64) NOT NULL,
				action VARCHAR(80) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				severity VARCHAR(20) NOT NULL DEFAULT 'INFO',
				result VARCHAR(32) NOT NULL DEFAULT 'OK',
				context_json LONGTEXT NULL,
				duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
				created_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_module_action (module, action),
				KEY idx_severity (severity),
				KEY idx_created (created_at_utc),
				KEY idx_correlation (correlation_id)
			) {$charsetCollate};",

			'queue' => "CREATE TABLE IF NOT EXISTS {$p}queue (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				idempotency_key VARCHAR(128) NOT NULL,
				job_type VARCHAR(64) NOT NULL,
				payload_json LONGTEXT NOT NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'PENDING',
				priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
				lock_owner VARCHAR(64) NULL,
				lease_expires_at DATETIME NULL,
				heartbeat_at DATETIME NULL,
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
				next_attempt_at DATETIME NULL,
				last_error TEXT NULL,
				created_at_utc DATETIME NOT NULL,
				updated_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_idempotency (idempotency_key),
				KEY idx_status_next (status, next_attempt_at, priority),
				KEY idx_lease (status, lease_expires_at)
			) {$charsetCollate};",

			'job_attempts' => "CREATE TABLE IF NOT EXISTS {$p}job_attempts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id BIGINT UNSIGNED NOT NULL,
				attempt_no INT UNSIGNED NOT NULL,
				lock_owner VARCHAR(64) NOT NULL,
				status VARCHAR(24) NOT NULL,
				error_message TEXT NULL,
				started_at_utc DATETIME NOT NULL,
				finished_at_utc DATETIME NULL,
				PRIMARY KEY (id),
				KEY idx_job_id (job_id)
			) {$charsetCollate};",

			'approvals' => "CREATE TABLE IF NOT EXISTS {$p}approvals (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				action_type VARCHAR(64) NOT NULL,
				target_type VARCHAR(64) NOT NULL,
				target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				payload_json LONGTEXT NOT NULL,
				payload_hash VARCHAR(64) NOT NULL,
				previous_snapshot_json LONGTEXT NULL,
				status VARCHAR(24) NOT NULL DEFAULT 'DRAFT',
				requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				approved_by BIGINT UNSIGNED NULL,
				requested_at_utc DATETIME NOT NULL,
				approved_at_utc DATETIME NULL,
				expires_at_utc DATETIME NULL,
				executed_at_utc DATETIME NULL,
				execution_receipt VARCHAR(128) NULL,
				decision_note TEXT NULL,
				PRIMARY KEY (id),
				KEY idx_status (status),
				KEY idx_target (target_type, target_id),
				KEY idx_hash (payload_hash)
			) {$charsetCollate};",

			'security_events' => "CREATE TABLE IF NOT EXISTS {$p}security_events (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_type VARCHAR(64) NOT NULL,
				severity VARCHAR(20) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				ip_hash VARCHAR(64) NULL,
				details_json LONGTEXT NULL,
				created_at_utc DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_event_type (event_type),
				KEY idx_created (created_at_utc)
			) {$charsetCollate};",
		];
	}
}
