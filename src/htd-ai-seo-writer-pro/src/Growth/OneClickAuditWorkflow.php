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
	public const LAST_AUDIT_OPTION = 'htd_ai_seo_last_audit';

	public function __construct(
		private readonly SettingsRegistry $settings,
		private readonly QueueEngine $queue,
		private readonly SEODataRepository $seoData,
		private readonly CategorySEOEngine $categorySeo,
		private readonly OpportunityAndRoadmapEngine $opportunities,
		private readonly TechnicalAuditAndTrafficGuard $techGuard,
		private readonly Logger $logger,
		private readonly ?\HTD\AISEO\Keyword\KeywordIntelligence $kwIntel = null,
		private readonly ?CompetitorAndAISearchEngine $compAio = null,
		private readonly ?GrowthAnalyticsEngine $growth = null,
		private readonly ?\HTD\AISEO\SEO\InternalLinkProposalEngine $linkProposals = null
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

		// Auto-collect authorized read-only WordPress inventory & priority category snapshots if caller omitted them
		if ($categorySnapshots === [] && function_exists('get_terms')) {
			$categorySnapshots = $this->collectReadOnlyCategories();
		}
		if ($pageSnapshots === [] && function_exists('get_posts')) {
			$pageSnapshots = $this->collectReadOnlyPages();
		}

		$steps = [];
		$errors = [];

		// Select freshest GSC snapshot across gsc_api (LIVE_VERIFIED) and gsc_import (IMPORTED_VERIFIED)
		$gscApi = $this->seoData->latestBySource('gsc_api', 1);
		$gscImport = $this->seoData->latestBySource('gsc_import', 1);
		$gscRecord = null;
		$gscProvenance = SEODataRepository::PROVENANCE_IMPORTED_VERIFIED;
		if ($gscApi !== [] && $gscImport !== []) {
			$apiTs = strtotime((string) ($gscApi[0]['imported_at_utc'] ?? '')) ?: 0;
			$impTs = strtotime((string) ($gscImport[0]['imported_at_utc'] ?? '')) ?: 0;
			if ($apiTs >= $impTs) {
				$gscRecord = $gscApi[0];
				$gscProvenance = (string) ($gscApi[0]['provenance'] ?? SEODataRepository::PROVENANCE_LIVE_VERIFIED);
			} else {
				$gscRecord = $gscImport[0];
				$gscProvenance = (string) ($gscImport[0]['provenance'] ?? SEODataRepository::PROVENANCE_IMPORTED_VERIFIED);
			}
		} elseif ($gscApi !== []) {
			$gscRecord = $gscApi[0];
			$gscProvenance = (string) ($gscApi[0]['provenance'] ?? SEODataRepository::PROVENANCE_LIVE_VERIFIED);
		} elseif ($gscImport !== []) {
			$gscRecord = $gscImport[0];
			$gscProvenance = (string) ($gscImport[0]['provenance'] ?? SEODataRepository::PROVENANCE_IMPORTED_VERIFIED);
		}

		$ga4Latest = $this->seoData->latestBySource('ga4', 1);

		$steps['gsc'] = $gscRecord !== null
			? "DATA_AVAILABLE ({$gscRecord['source']}, {$gscProvenance})"
			: 'BLOCKED_BY_CONFIGURATION: No GSC API sync or CSV snapshot available';
		$steps['ga4'] = $ga4Latest !== []
			? 'DATA_AVAILABLE (' . (string) ($ga4Latest[0]['provenance'] ?? 'LIVE_VERIFIED') . ')'
			: 'BLOCKED_BY_CONFIGURATION: No GA4 property synced';

		if ($categorySnapshots === [] && $pageSnapshots === [] && $gscRecord === null && $ga4Latest === []) {
			$blockedResult = [
				'status'      => 'BLOCKED',
				'reason'      => 'No category, page, GSC, or GA4 snapshots available to audit.',
				'steps'       => $steps,
				'errors'      => ['BLOCKED_BY_CONFIGURATION: Empty audit inputs and no external analytics snapshots.'],
				'executed_at' => gmdate('Y-m-d H:i:s'),
			];
			$this->persistAudit($blockedResult);
			return $blockedResult;
		}

		// Prioritize HTD core categories first
		usort($categorySnapshots, static function (array $a, array $b): int {
			$pUrls = ['camera-hanh-trinh-o-to', 'android-box-o-to'];
			$aPrio = in_array((string) ($a['slug'] ?? ''), $pUrls, true) ? 0 : 1;
			$bPrio = in_array((string) ($b['slug'] ?? ''), $pUrls, true) ? 0 : 1;
			return $aPrio <=> $bPrio;
		});

		$catResults = [];
		foreach ($categorySnapshots as $cat) {
			try {
				$catResults[] = $this->categorySeo->auditAndPropose($cat);
			} catch (\Throwable $e) {
				$errors[] = 'category_seo: ' . $e->getMessage();
			}
		}
		$steps['category_seo'] = count($catResults) . ' categories audited';

		$gscRows = [];
		if ($gscRecord !== null) {
			$gscRows = json_decode((string) ($gscRecord['metrics_json'] ?? '[]'), true) ?: [];
		}
		$opps = $this->opportunities->detectFromGsc($gscRows, $gscProvenance);
		$steps['opportunities'] = count($opps) . ' opportunities evaluated';
		$steps['content_roadmap'] = '6-column roadmap ready (' . count($opps) . ' rows)';

		$kwIntents = [];
		if ($this->kwIntel !== null && $gscRows !== []) {
			foreach (array_slice($gscRows, 0, 20) as $r) {
				$q = trim((string) ($r['query'] ?? ''));
				if ($q !== '') {
					$kwIntents[$q] = $this->kwIntel->classifyIntentHeuristic($q)['intent'];
				}
			}
			$steps['keyword_intelligence'] = count($kwIntents) . ' queries intent-classified';
		} else {
			$steps['keyword_intelligence'] = 'SKIPPED_WITH_REASON: No GSC queries';
		}

		$tech = $this->techGuard->auditPages($pageSnapshots);
		$steps['technical_audit'] = count($tech['issues']) . ' issues found (read-only)';

		$linkProposalsCount = 0;
		if ($this->linkProposals !== null && $pageSnapshots !== []) {
			$candidates = array_map(static fn(array $p): array => [
				'id'    => (int) ($p['id'] ?? 0),
				'url'   => (string) ($p['url'] ?? ''),
				'title' => (string) ($p['title'] ?? ''),
			], $pageSnapshots);
			foreach (array_slice($pageSnapshots, 0, 5) as $pg) {
				$props = $this->linkProposals->propose((string) ($pg['html'] ?? ''), (string) ($pg['title'] ?? ''), $candidates);
				$linkProposalsCount += count($props);
			}
		}
		$steps['internal_links'] = "{$linkProposalsCount} internal link proposal(s) evaluated";

		$steps['competitor_monitor'] = $this->compAio !== null ? 'CONNECTED (observation-only)' : 'UNAVAILABLE';
		$steps['ai_overview'] = $this->compAio !== null ? 'CONNECTED (observation-only)' : 'UNAVAILABLE';
		$steps['growth_analytics'] = ($this->growth !== null && $ga4Latest !== []) ? 'CONNECTED' : 'SKIPPED_WITH_REASON: No GA4 snapshot';

		$isPartial = ($gscRecord === null || $ga4Latest === [] || $errors !== []);
		$result = [
			'status'             => 'COMPLETED_READ_ONLY',
			'audit_completeness' => $isPartial ? 'PARTIAL' : 'COMPLETE',
			'steps'              => $steps,
			'errors'             => $errors,
			'categories'         => $catResults,
			'opportunities'      => $opps,
			'technical'          => $tech,
			'keyword_intents'    => $kwIntents,
			'executed_at'        => gmdate('Y-m-d H:i:s'),
		];
		$this->persistAudit($result);
		$this->logger->log('growth_audit', 'one_click_audit_completed', 'AUDIT', $isPartial ? 'PARTIAL' : 'OK', [
			'categories'    => count($catResults),
			'opportunities' => count($opps),
			'tech_issues'   => count($tech['issues']),
			'completeness'  => $result['audit_completeness'],
		]);

		return $result;
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function persistAudit(array $result): void {
		$this->seoData->saveSnapshot(
			'htd_audit',
			'site_audit',
			'one_click_audit',
			gmdate('Y-m-d'),
			SEODataRepository::PROVENANCE_LOCAL_OBSERVED,
			[$result]
		);
		if (function_exists('update_option')) {
			update_option(self::LAST_AUDIT_OPTION, $result, false);
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function collectReadOnlyCategories(): array {
		$terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 20]);
		if (!is_array($terms)) {
			return [];
		}
		$out = [];
		foreach ($terms as $t) {
			if (!is_object($t)) {
				continue;
			}
			$link = function_exists('get_term_link') ? get_term_link($t) : '';
			$out[] = [
				'slug'             => (string) ($t->slug ?? ''),
				'url'              => is_string($link) ? $link : '',
				'meta_description' => (string) ($t->description ?? ''),
				'intro_html'       => (string) ($t->description ?? ''),
				'faq_count'        => 0,
				'canonical'        => is_string($link) ? $link : '',
			];
		}
		return $out;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function collectReadOnlyPages(): array {
		$posts = get_posts(['post_type' => ['post', 'page'], 'post_status' => 'publish', 'numberposts' => 20]);
		if (!is_array($posts)) {
			return [];
		}
		$out = [];
		foreach ($posts as $p) {
			if (!is_object($p)) {
				continue;
			}
			$out[] = [
				'id'          => (int) ($p->ID ?? 0),
				'url'         => function_exists('get_permalink') ? (string) get_permalink($p->ID) : '',
				'title'       => (string) ($p->post_title ?? ''),
				'html'        => (string) ($p->post_content ?? ''),
				'http_status' => 200,
			];
		}
		return $out;
	}

	public function enqueueAuditJob(string $idempotencyKey = ''): int {
		$key = $idempotencyKey !== '' ? $idempotencyKey : ('htd-seo-audit-' . gmdate('Ymd-Hi'));
		return $this->queue->enqueue('HTD_SEO_AUDIT', ['triggered_at' => gmdate('Y-m-d H:i:s')], $key);
	}
}
