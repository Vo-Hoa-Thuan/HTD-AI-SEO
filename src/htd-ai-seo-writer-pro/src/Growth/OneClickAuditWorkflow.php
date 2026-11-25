<?php
declare(strict_types=1);

namespace HTD\AISEO\Growth;

use HTD\AISEO\Analytics\SEODataRepository;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Queue\QueueEngine;
use HTD\AISEO\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Checkpointed "RUN HTD SEO AUDIT" orchestrator.
 * Reports BLOCKED_BY_CONFIGURATION / SKIPPED_WITH_REASON when external sources are not configured.
 */
final class OneClickAuditWorkflow {
	public function __construct(
		private readonly SettingsRegistry $settings,
		private readonly QueueEngine $queue,
		private readonly SEODataRepository $seoData,
		private readonly CategorySEOEngine $categorySeo,
		private readonly OpportunityAndRoadmapEngine $opportunities,
		private readonly TechnicalAuditAndTrafficGuard $techGuard,
		private readonly Logger $logger
	) {}

	/**
	 * @param array<int, array<string, mixed>> $categorySnapshots
	 * @param array<int, array<string, mixed>> $pageSnapshots
	 * @return array<string, mixed>
	 */
	public function runAudit(array $categorySnapshots = [], array $pageSnapshots = []): array {
		if ((bool) $this->settings->get('emergency_stop', false)) {
			return ['status' => 'BLOCKED_BY_EMERGENCY_STOP', 'steps' => []];
		}

		$steps = [];
		$gscLatest = $this->seoData->latestBySource('gsc_import', 1);
		$ga4Latest = $this->seoData->latestBySource('ga4', 1);

		$steps['gsc'] = $gscLatest !== [] ? 'DATA_AVAILABLE' : 'SKIPPED_WITH_REASON: No GSC snapshot imported/synced';
		$steps['ga4'] = $ga4Latest !== [] ? 'DATA_AVAILABLE' : 'BLOCKED_BY_CONFIGURATION: No GA4 property synced';

		$catResults = [];
		foreach ($categorySnapshots as $cat) {
			$catResults[] = $this->categorySeo->auditAndPropose($cat);
		}
		$steps['category_seo'] = count($catResults) . ' categories audited';

		$gscRows = [];
		if ($gscLatest !== []) {
			$gscRows = json_decode((string) ($gscLatest[0]['metrics_json'] ?? '[]'), true) ?: [];
		}
		$opps = $this->opportunities->detectFromGsc($gscRows, 'IMPORTED_VERIFIED');
		$steps['opportunities'] = count($opps) . ' opportunities evaluated';

		$tech = $this->techGuard->auditPages($pageSnapshots);
		$steps['technical_audit'] = count($tech['issues']) . ' issues found (read-only)';

		$this->logger->log('growth_audit', 'one_click_audit_completed', 'AUDIT', 'OK', [
			'categories'    => count($catResults),
			'opportunities' => count($opps),
			'tech_issues'   => count($tech['issues']),
		]);

		return [
			'status'        => 'COMPLETED_READ_ONLY',
			'steps'         => $steps,
			'categories'    => $catResults,
			'opportunities' => $opps,
			'technical'     => $tech,
		];
	}

	public function enqueueAuditJob(string $idempotencyKey = ''): int {
		$key = $idempotencyKey !== '' ? $idempotencyKey : ('htd-seo-audit-' . gmdate('Ymd-Hi'));
		return $this->queue->enqueue('HTD_SEO_AUDIT', ['triggered_at' => gmdate('Y-m-d H:i:s')], $key);
	}
}
