<?php
declare(strict_types=1);

namespace HTD\AISEO\Database;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Plugin data backup & restore service with SHA-256 integrity verification (never touches secrets).
 */
final class BackupRestoreService {
	/** @var string[] */
	private const BACKUP_TABLES = [
		'keywords', 'keyword_mappings', 'content_drafts', 'content_revisions',
		'rewrite_snapshots', 'media_proposals', 'master_products', 'analytics_snapshots',
		'category_audits', 'opportunities', 'competitor_targets', 'ai_search_observations',
		'growth_experiments',
	];

	public function __construct(private readonly object $wpdb) {}

	/**
	 * Exact list of non-secret plugin tables covered by backup & restore.
	 * Credentials, audit logs, queue, and approvals are intentionally excluded.
	 *
	 * @return string[]
	 */
	public static function coveredTables(): array {
		return self::BACKUP_TABLES;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function exportBackup(): array {
		$data = [];
		$counts = [];
		foreach (self::BACKUP_TABLES as $tbl) {
			$full = Schema::table($this->wpdb, $tbl);
			$rows = $this->wpdb->get_results("SELECT * FROM {$full} ORDER BY id ASC", ARRAY_A);
			$data[$tbl] = is_array($rows) ? $rows : [];
			$counts[$tbl] = count($data[$tbl]);
		}
		$payloadJson = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return [
			'schema_version' => Migrator::TARGET_VERSION,
			'exported_at'    => gmdate('Y-m-d H:i:s'),
			'checksum'       => hash('sha256', $payloadJson),
			'row_counts'     => $counts,
			'tables'         => $data,
		];
	}

	/**
	 * @param array<string, mixed> $backup
	 */
	public function restoreBackup(array $backup, bool $skipDuplicates = true): int {
		$tables = $backup['tables'] ?? null;
		$checksum = (string) ($backup['checksum'] ?? '');
		$schemaVersion = (string) ($backup['schema_version'] ?? '');
		if (!is_array($tables) || $checksum === '' || $schemaVersion === '') {
			throw new \InvalidArgumentException('Invalid backup structure or missing schema_version.');
		}
		if (version_compare($schemaVersion, '0.2.0', '<') || version_compare($schemaVersion, Migrator::TARGET_VERSION, '>')) {
			throw new \RuntimeException("Incompatible backup schema version: {$schemaVersion}", 422);
		}
		$computed = hash('sha256', (string) json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		if (!hash_equals($checksum, $computed)) {
			throw new \RuntimeException('Backup checksum verification failed; restore aborted.', 422);
		}

		$txStarted = @$this->wpdb->query('START TRANSACTION') !== false;
		$restoredRows = 0;
		try {
			foreach ($tables as $tbl => $rows) {
				if (!in_array($tbl, self::BACKUP_TABLES, true) || !is_array($rows)) {
					continue;
				}
				$full = Schema::table($this->wpdb, (string) $tbl);
				foreach ($rows as $row) {
					if (!is_array($row)) {
						continue;
					}
					if ($skipDuplicates && isset($row['id'])) {
						$exists = $this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$full} WHERE id = %d LIMIT 1", (int) $row['id']));
						if ($exists !== null) {
							continue;
						}
					}
					$inserted = $this->wpdb->insert($full, $row);
					if ($inserted === false) {
						$err = property_exists($this->wpdb, 'last_error') ? (string) $this->wpdb->last_error : 'unknown db error';
						throw new \RuntimeException("Database insert failed during restore of {$tbl}: {$err}", 500);
					}
					$restoredRows++;
				}
			}
			if ($txStarted) {
				@$this->wpdb->query('COMMIT');
			}
			return $restoredRows;
		} catch (\Throwable $e) {
			if ($txStarted) {
				@$this->wpdb->query('ROLLBACK');
			}
			throw $e;
		}
	}
}
