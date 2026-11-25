<?php
declare(strict_types=1);

namespace HTD\AISEO\Growth;

use HTD\AISEO\Database\Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Competitor Intelligence & AI Search / AI Overview Observation Engine.
 * Never fabricates rankings, AI Overview appearances, or E-E-A-T credentials.
 */
final class CompetitorAndAISearchEngine {
	public function __construct(private readonly object $wpdb) {}

	/**
	 * @param array<string, mixed> $htdSnapshot {title, headings[], faq_count}
	 * @param array<string, mixed> $compSnapshot {title, headings[], faq_count}
	 * @return array<string, mixed>
	 */
	public function recordCompetitorObservation(
		string $keyword,
		string $htdUrl,
		string $competitorUrl,
		array $htdSnapshot,
		array $compSnapshot,
		string $source = 'USER_PROVIDED'
	): array {
		if (!filter_var($competitorUrl, FILTER_VALIDATE_URL)) {
			throw new \InvalidArgumentException('Valid competitor URL is required.');
		}
		$htdHeadings = array_map('strtolower', (array) ($htdSnapshot['headings'] ?? []));
		$compHeadings = array_map('strtolower', (array) ($compSnapshot['headings'] ?? []));
		$topicGaps = array_values(array_diff($compHeadings, $htdHeadings));

		$obs = [
			'title_htd'       => (string) ($htdSnapshot['title'] ?? ''),
			'title_comp'      => (string) ($compSnapshot['title'] ?? ''),
			'faq_diff'        => (int) ($compSnapshot['faq_count'] ?? 0) - (int) ($htdSnapshot['faq_count'] ?? 0),
			'topic_gaps'      => $topicGaps,
		];

		$table = Schema::table($this->wpdb, 'competitor_targets');
		$this->wpdb->insert($table, [
			'keyword'            => $keyword,
			'htd_url'            => $htdUrl,
			'competitor_url'     => $competitorUrl,
			'observation_source' => $source,
			'observation_json'   => (string) json_encode($obs, JSON_UNESCAPED_UNICODE),
			'observed_at_utc'    => gmdate('Y-m-d H:i:s'),
		]);
		return ['id' => (int) $this->wpdb->insert_id, 'observation' => $obs];
	}

	/**
	 * Record factual AI Overview / Citation observation (never fabricated).
	 *
	 * @param string[] $citedUrls
	 */
	public function recordAISearchObservation(
		string $keyword,
		string $query,
		string $status,
		array $citedUrls,
		string $htdDomain = 'htd.net.vn',
		string $evidenceRef = '',
		string $country = 'VN',
		string $device = 'desktop'
	): int {
		$allowed = ['OBSERVED', 'NOT_OBSERVED', 'NOT_SUPPORTED', 'NOT_VERIFIED', 'INSUFFICIENT_DATA'];
		$cleanStatus = in_array($status, $allowed, true) ? $status : 'INSUFFICIENT_DATA';
		$htdCited = 0;
		foreach ($citedUrls as $u) {
			if (str_contains(strtolower((string) $u), strtolower($htdDomain))) {
				$htdCited = 1;
				break;
			}
		}

		$table = Schema::table($this->wpdb, 'ai_search_observations');
		$this->wpdb->insert($table, [
			'keyword'            => $keyword,
			'search_query'       => $query,
			'country'            => $country,
			'device'             => $device,
			'ai_overview_status' => $cleanStatus,
			'htd_cited'          => $htdCited,
			'cited_urls_json'    => (string) json_encode(array_values($citedUrls), JSON_UNESCAPED_SLASHES),
			'evidence_ref'       => $evidenceRef,
			'observed_at_utc'    => gmdate('Y-m-d H:i:s'),
		]);
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Rule-based AI Content Readiness audit (transparent scoring method, no fake Google scores).
	 *
	 * @param array<string, mixed> $content
	 * @return array{method: string, checks: array<string, bool>, readiness_ratio: float}
	 */
	public function auditReadiness(array $content): array {
		$html = (string) ($content['article_html'] ?? '');
		$checks = [
			'clear_headings'       => preg_match('/<h2\b/i', $html) === 1,
			'verified_facts_only'  => !str_contains($html, 'NEEDS_VERIFICATION'),
			'has_faq'              => !empty($content['faq']),
			'has_internal_links'   => !empty($content['internal_link_proposals']),
			'has_source_refs'      => !empty($content['source_references']),
		];
		$passed = count(array_filter($checks));
		return [
			'method'          => 'HTD_RULE_BASED_READINESS_V1',
			'checks'          => $checks,
			'readiness_ratio' => round($passed / count($checks), 2),
		];
	}
}
