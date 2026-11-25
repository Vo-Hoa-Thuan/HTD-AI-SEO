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
	 * @return array<string, mixed>
	 */
	public function exportBackup(): array {
		$data = [];
		foreach (self::BACKUP_TABLES as $tbl) {
			$full = Schema::table($this->wpdb, $tbl);
			$rows = $this->wpdb->get_results("SELECT * FROM {$full} ORDER BY id ASC", ARRAY_A);
			$data[$tbl] = is_array($rows) ? $rows : [];
		}
		$payloadJson = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return [
			'schema_version' => Migrator::TARGET_VERSION,
			'exported_at'    => gmdate('Y-m-d H:i:s'),
			'checksum'       => hash('sha256', $payloadJson),
			'tables'         => $data,
		];
	}

	/**
	 * @param array<string, mixed> $backup
	 */
	public function restoreBackup(array $backup): int {
		$tables = $backup['tables'] ?? null;
		$checksum = (string) ($backup['checksum'] ?? '');
		if (!is_array($tables) || $checksum === '') {
			throw new \InvalidArgumentException('Invalid backup structure.');
		}
		$computed = hash('sha256', (string) json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		if (!hash_equals($checksum, $computed)) {
			throw new \RuntimeException('Backup checksum verification failed; restore aborted.', 422);
		}

		$restoredRows = 0;
		foreach ($tables as $tbl => $rows) {
			if (!in_array($tbl, self::BACKUP_TABLES, true) || !is_array($rows)) {
				continue;
			}
			$full = Schema::table($this->wpdb, (string) $tbl);
			foreach ($rows as $row) {
				if (is_array($row)) {
					$this->wpdb->insert($full, $row);
					$restoredRows++;
				}
			}
		}
		return $restoredRows;
	}
}
