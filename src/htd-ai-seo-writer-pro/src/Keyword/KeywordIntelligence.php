<?php
declare(strict_types=1);

namespace HTD\AISEO\Keyword;

use HTD\AISEO\Database\Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Keyword intelligence: intent classification, clustering, mapping, and cannibalization detection.
 * Never fabricates search volume, CPC, keyword difficulty, or rankings.
 */
final class KeywordIntelligence {
	public const SOURCE_USER_PROVIDED     = 'USER_PROVIDED';
	public const SOURCE_WP_OBSERVED       = 'WORDPRESS_OBSERVED';
	public const SOURCE_AI_SUGGESTED      = 'AI_SUGGESTED';
	public const SOURCE_GSC_VERIFIED      = 'GSC_VERIFIED';
	public const SOURCE_UNKNOWN           = 'UNKNOWN';

	public function __construct(private readonly object $wpdb) {}

	/**
	 * Deterministic rule-based search intent pre-classification (tagged UNKNOWN/AI_SUGGESTED, never GSC_VERIFIED).
	 *
	 * @return array{intent: string, source: string, metrics: array<string, string>}
	 */
	public function classifyIntentHeuristic(string $keyword): array {
		$low = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
		$intent = 'informational';
		if (preg_match('/\b(mua|giá|báo giá|chính hãng|khuyến mãi|đặt hàng|android box|camera hành trình)\b/u', $low)) {
			$intent = 'commercial_investigation';
		}
		if (preg_match('/\b(so sánh|review|đánh giá|nên mua|top )\b/u', $low)) {
			$intent = 'commercial_investigation';
		}
		if (preg_match('/\b(cách|hướng dẫn|lắp đặt|sửa lỗi|là gì|tại sao)\b/u', $low)) {
			$intent = 'informational';
		}

		return [
			'intent'  => $intent,
			'source'  => self::SOURCE_UNKNOWN,
			'metrics' => [
				'search_volume'      => 'NOT_AVAILABLE',
				'cpc'                => 'NOT_AVAILABLE',
				'keyword_difficulty' => 'NOT_AVAILABLE',
			],
		];
	}

	/**
	 * Group keywords by normalized root tokens without fabricating external metrics.
	 *
	 * @param string[] $keywords
	 * @return array<string, string[]>
	 */
	public function clusterByTopic(array $keywords): array {
		$clusters = [];
		foreach ($keywords as $kw) {
			$clean = trim($kw);
			if ($clean === '') {
				continue;
			}
			$low = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
			$topic = 'general';
			if (str_contains($low, 'camera hành trình')) {
				$topic = 'camera-hanh-trinh-o-to';
			} elseif (str_contains($low, 'android box')) {
				$topic = 'android-box-o-to';
			} else {
				$words = preg_split('/\s+/', $low) ?: [];
				$topic = implode('-', array_slice($words, 0, 2)) ?: 'general';
			}
			$clusters[$topic][] = $clean;
		}
		return $clusters;
	}

	public function saveMapping(
		int $keywordId,
		string $targetUrl,
		int $postId = 0,
		string $slug = '',
		string $intent = 'UNKNOWN',
		string $dataSource = self::SOURCE_USER_PROVIDED
	): int {
		$table = Schema::table($this->wpdb, 'keyword_mappings');
		$now = gmdate('Y-m-d H:i:s');
		$cannibal = $this->detectCannibalization($targetUrl, $keywordId);

		$this->wpdb->insert($table, [
			'keyword_id'             => $keywordId,
			'target_url'             => $targetUrl,
			'post_id'                => $postId,
			'slug'                   => $slug,
			'search_intent'          => $intent,
			'cannibalization_status' => $cannibal,
			'data_source'            => $dataSource,
			'created_at_utc'         => $now,
			'updated_at_utc'         => $now,
		]);
		return (int) $this->wpdb->insert_id;
	}

	public function detectCannibalization(string $targetUrl, int $excludeKeywordId = 0): string {
		if (trim($targetUrl) === '') {
			return 'INSUFFICIENT_DATA';
		}
		$table = Schema::table($this->wpdb, 'keyword_mappings');
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare("SELECT keyword_id FROM {$table} WHERE target_url = %s", $targetUrl),
			ARRAY_A
		);
		if (!is_array($rows)) {
			return 'CLEAR';
		}
		foreach ($rows as $r) {
			if ((int) ($r['keyword_id'] ?? 0) !== $excludeKeywordId) {
				return 'POSSIBLE_CANNIBALIZATION';
			}
		}
		return 'CLEAR';
	}
}
