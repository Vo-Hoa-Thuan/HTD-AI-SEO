<?php
declare(strict_types=1);

namespace HTD\AISEO\Database;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Versioned, idempotent database migrator with failure tracking and rollback support.
 */
final class Migrator {
	public const VERSION_OPTION = 'htd_ai_seo_db_version';
	public const TARGET_VERSION = '0.5.0';

	public function __construct(private readonly object $wpdb) {}

	public function currentVersion(): string {
		if (function_exists('get_option')) {
			return (string) get_option(self::VERSION_OPTION, '0.0.0');
		}
		return '0.0.0';
	}

	public function migrateIfNeeded(): void {
		if (version_compare($this->currentVersion(), self::TARGET_VERSION, '<')) {
			$this->migrate();
		}
	}

	/**
	 * Run idempotent migrations up to TARGET_VERSION.
	 */
	public function migrate(): bool {
		$charsetCollate = method_exists($this->wpdb, 'get_charset_collate')
			? (string) $this->wpdb->get_charset_collate()
			: '';

		$tables = array_merge(
			Schema::v020Tables($this->wpdb, $charsetCollate),
			Schema::v040Tables($this->wpdb, $charsetCollate),
			Schema::v050Tables($this->wpdb, $charsetCollate)
		);
		$migTable = Schema::table($this->wpdb, 'migrations');
		$now = gmdate('Y-m-d H:i:s');

		try {
			foreach ($tables as $name => $ddl) {
				$res = $this->wpdb->query($ddl);
				if ($res === false) {
					$err = property_exists($this->wpdb, 'last_error') ? (string) $this->wpdb->last_error : "Failed creating {$name}";
					throw new \RuntimeException($err !== '' ? $err : "Failed creating table {$name}");
				}
			}

			$checksum = hash('sha256', implode("\n", $tables));
			$existing = $this->wpdb->get_var(
				$this->wpdb->prepare("SELECT version FROM {$migTable} WHERE version = %s", self::TARGET_VERSION)
			);

			if ($existing === null) {
				$this->wpdb->insert($migTable, [
					'version'        => self::TARGET_VERSION,
					'checksum'       => $checksum,
					'status'         => 'applied',
					'applied_at_utc' => $now,
					'error_message'  => null,
				]);
			} else {
				$this->wpdb->update(
					$migTable,
					[
						'checksum'           => $checksum,
						'status'             => 'applied',
						'applied_at_utc'     => $now,
						'rolled_back_at_utc' => null,
						'error_message'      => null,
					],
					['version' => self::TARGET_VERSION]
				);
			}

			if (function_exists('update_option')) {
				update_option(self::VERSION_OPTION, self::TARGET_VERSION, false);
			}
			return true;
		} catch (\Throwable $e) {
			// Record migration failure if migrations table exists
			@$this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$migTable} SET status = %s, error_message = %s WHERE version = %s",
					'failed',
					substr($e->getMessage(), 0, 500),
					self::TARGET_VERSION
				)
			);
			throw $e;
		}
	}

	/**
	 * Safe rollback for a specific version (only drops tables when explicitly invoked in test/rollback).
	 */
	public function rollbackVersion(string $version): bool {
		$p = Schema::prefix($this->wpdb);
		$migTable = Schema::table($this->wpdb, 'migrations');
		if ($version === '0.5.0') {
			foreach (['master_products', 'link_proposals', 'link_index', 'publishing_receipts', 'product_proposals'] as $tbl) {
				$this->wpdb->query("DROP TABLE IF EXISTS {$p}{$tbl}");
			}
			$this->wpdb->update($migTable, ['status' => 'rolled_back', 'rolled_back_at_utc' => gmdate('Y-m-d H:i:s')], ['version' => $version]);
			if (function_exists('update_option')) {
				update_option(self::VERSION_OPTION, '0.4.0', false);
			}
			return true;
		}
		if ($version === '0.4.0') {
			foreach (['media_proposals', 'rewrite_snapshots', 'content_revisions', 'content_drafts', 'keyword_mappings', 'keywords'] as $tbl) {
				$this->wpdb->query("DROP TABLE IF EXISTS {$p}{$tbl}");
			}
			$this->wpdb->update($migTable, ['status' => 'rolled_back', 'rolled_back_at_utc' => gmdate('Y-m-d H:i:s')], ['version' => $version]);
			if (function_exists('update_option')) {
				update_option(self::VERSION_OPTION, '0.2.0', false);
			}
			return true;
		}
		if ($version !== '0.2.0') {
			return false;
		}
		$dropOrder = ['job_attempts', 'queue', 'approvals', 'security_events', 'logs'];
		foreach ($dropOrder as $tbl) {
			$this->wpdb->query("DROP TABLE IF EXISTS {$p}{$tbl}");
		}
		$this->wpdb->update(
			$migTable,
			[
				'status'             => 'rolled_back',
				'rolled_back_at_utc' => gmdate('Y-m-d H:i:s'),
			],
			['version' => $version]
		);
		if (function_exists('update_option')) {
			update_option(self::VERSION_OPTION, '0.0.0', false);
		}
		return true;
	}
}
