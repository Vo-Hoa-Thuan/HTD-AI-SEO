<?php
declare(strict_types=1);

namespace HTD\AISEO\Growth;

use HTD\AISEO\Database\Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Growth Analytics & Before/After Experiment Tracker.
 * Never claims SEO changes caused traffic growth without sufficient evidence.
 */
final class GrowthAnalyticsEngine {
	public function __construct(private readonly object $wpdb) {}

	/**
	 * @param array<string, mixed> $baseline
	 */
	public function recordExperiment(string $url, string $changeType, string $approvalReceipt, array $baseline): int {
		$table = Schema::table($this->wpdb, 'growth_experiments');
		$this->wpdb->insert($table, [
			'target_url'       => $url,
			'change_type'      => $changeType,
			'approval_receipt' => $approvalReceipt,
			'baseline_json'    => (string) json_encode($baseline, JSON_UNESCAPED_UNICODE),
			'executed_at_utc'  => gmdate('Y-m-d H:i:s'),
		]);
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $before {clicks, impressions, sessions}
	 * @param array<string, mixed> $after {clicks, impressions, sessions}
	 * @return array<string, mixed>
	 */
	public function comparePeriods(array $before, array $after): array {
		return [
			'clicks_delta'      => (int) ($after['clicks'] ?? 0) - (int) ($before['clicks'] ?? 0),
			'impressions_delta' => (int) ($after['impressions'] ?? 0) - (int) ($before['impressions'] ?? 0),
			'sessions_delta'    => (int) ($after['sessions'] ?? 0) - (int) ($before['sessions'] ?? 0),
			'causality_note'    => 'CORRELATION_ONLY: External seasonality and algorithm updates may affect metrics.',
		];
	}
}
