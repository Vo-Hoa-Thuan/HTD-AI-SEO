<?php
declare(strict_types=1);

namespace HTD\AISEO\Analytics;

use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Unified SEO Data Repository enforcing strict source provenance and dataset deduplication.
 */
final class SEODataRepository {
	public const PROVENANCE_LIVE_VERIFIED     = 'LIVE_VERIFIED';
	public const PROVENANCE_IMPORTED_VERIFIED = 'IMPORTED_VERIFIED';
	public const PROVENANCE_LOCAL_OBSERVED    = 'LOCAL_OBSERVED';
	public const PROVENANCE_AI_SUGGESTED      = 'AI_SUGGESTED';
	public const PROVENANCE_MOCK              = 'MOCK';
	public const PROVENANCE_UNKNOWN           = 'UNKNOWN';

	public function __construct(
		private readonly object $wpdb,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	public function saveSnapshot(
		string $source,
		string $property,
		string $reportType,
		string $dateRange,
		string $provenance,
		array $rows
	): int {
		$json = (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$hash = hash('sha256', $json);
		$table = Schema::table($this->wpdb, 'analytics_snapshots');

		$existingId = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$table} WHERE source = %s AND report_type = %s AND date_range = %s AND dataset_hash = %s LIMIT 1",
				$source,
				$reportType,
				$dateRange,
				$hash
			)
		);
		if ($existingId !== null) {
			return (int) $existingId;
		}

		$this->wpdb->insert($table, [
			'source'          => $source,
			'property'        => $property,
			'report_type'     => $reportType,
			'date_range'      => $dateRange,
			'provenance'      => $provenance,
			'dataset_hash'    => $hash,
			'row_count'       => count($rows),
			'metrics_json'    => $json,
			'imported_at_utc' => gmdate('Y-m-d H:i:s'),
		]);
		$id = (int) $this->wpdb->insert_id;

		$this->logger->log('seo_data', 'snapshot_saved', 'INFO', 'OK', [
			'snapshot_id' => $id,
			'source'      => $source,
			'provenance'  => $provenance,
			'rows'        => count($rows),
		]);
		return $id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function latestBySource(string $source, int $limit = 10): array {
		$table = Schema::table($this->wpdb, 'analytics_snapshots');
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare("SELECT * FROM {$table} WHERE source = %s ORDER BY id DESC LIMIT %d", $source, max(1, min(100, $limit))),
			ARRAY_A
		);
		return is_array($rows) ? $rows : [];
	}
}
