<?php
declare(strict_types=1);

namespace HTD\AISEO\Content;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Determines whether a keyword should create a NEW post or UPDATE an existing URL.
 * Never auto-creates redirects, never modifies slugs, never deletes existing posts.
 */
final class NewUpdateDecisionEngine {
	public const DECISION_NEW                    = 'NEW';
	public const DECISION_UPDATE                 = 'UPDATE';
	public const DECISION_REVIEW_REQUIRED        = 'REVIEW_REQUIRED';
	public const DECISION_POSSIBLE_CANNIBALIZATION = 'POSSIBLE_CANNIBALIZATION';
	public const DECISION_INSUFFICIENT_DATA      = 'INSUFFICIENT_DATA';

	/**
	 * @param array<int, array<string, mixed>> $existingPosts Fixture or WP_Post summaries [{id, title, slug, url, intent}]
	 * @return array{decision: string, matched_post_id: int, reason: string}
	 */
	public function decide(string $keyword, string $targetUrl, string $intent, array $existingPosts): array {
		$kw = trim($keyword);
		if ($kw === '') {
			return ['decision' => self::DECISION_INSUFFICIENT_DATA, 'matched_post_id' => 0, 'reason' => 'Empty keyword.'];
		}

		$kwLow = function_exists('mb_strtolower') ? mb_strtolower($kw, 'UTF-8') : strtolower($kw);
		$matches = [];

		foreach ($existingPosts as $post) {
			$pUrl = trim((string) ($post['url'] ?? ''));
			$pTitle = function_exists('mb_strtolower') ? mb_strtolower((string) ($post['title'] ?? ''), 'UTF-8') : strtolower((string) ($post['title'] ?? ''));
			$pIntent = (string) ($post['intent'] ?? 'UNKNOWN');
			$pId = (int) ($post['id'] ?? 0);

			if ($targetUrl !== '' && $pUrl !== '' && rtrim($targetUrl, '/') === rtrim($pUrl, '/')) {
				if ($pIntent !== 'UNKNOWN' && $intent !== 'UNKNOWN' && $pIntent !== $intent) {
					return ['decision' => self::DECISION_REVIEW_REQUIRED, 'matched_post_id' => $pId, 'reason' => 'Target URL exists but search intent differs.'];
				}
				return ['decision' => self::DECISION_UPDATE, 'matched_post_id' => $pId, 'reason' => 'Exact target URL match with compatible intent.'];
			}

			if ($pTitle !== '' && (str_contains($pTitle, $kwLow) || similar_text($kwLow, $pTitle) / max(1, strlen($kwLow)) > 0.75)) {
				$matches[] = $pId;
			}
		}

		if (count($matches) > 1) {
			return ['decision' => self::DECISION_POSSIBLE_CANNIBALIZATION, 'matched_post_id' => $matches[0], 'reason' => 'Multiple existing posts target similar topic.'];
		}
		if (count($matches) === 1) {
			return ['decision' => self::DECISION_UPDATE, 'matched_post_id' => $matches[0], 'reason' => 'Single existing post matches keyword topic; prefer UPDATE.'];
		}

		return ['decision' => self::DECISION_NEW, 'matched_post_id' => 0, 'reason' => 'No conflicting existing post found.'];
	}
}
