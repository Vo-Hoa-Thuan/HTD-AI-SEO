<?php
declare(strict_types=1);

namespace HTD\AISEO\SEO;

use HTD\AISEO\Brand\BrandProfile;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Internal link proposal engine: prevents self-links, duplicate links, and fabricated URLs.
 */
final class InternalLinkProposalEngine {
	public function __construct(private readonly BrandProfile $brand) {}

	/**
	 * @param array<int, array{url: string, title: string, type?: string}> $candidateTargets
	 * @return array<int, array{url: string, anchor_text: string, score: float, placement: string}>
	 */
	public function propose(string $topic, string $currentUrl, array $candidateTargets, int $maxLinks = 5): array {
		$brandCfg = $this->brand->get();
		foreach ((array) ($brandCfg['priority_urls'] ?? []) as $pUrl) {
			$candidateTargets[] = [
				'url'   => (string) $pUrl,
				'title' => str_contains((string) $pUrl, 'camera-hanh-trinh') ? 'Camera hành trình ô tô HTD' : 'Android Box ô tô HTD',
				'type'  => 'priority_category',
			];
		}

		$topicLow = function_exists('mb_strtolower') ? mb_strtolower($topic, 'UTF-8') : strtolower($topic);
		$topicTokens = array_filter(preg_split('/\s+/', $topicLow) ?: []);
		$seenUrls = [];
		$proposals = [];

		foreach ($candidateTargets as $cand) {
			$url = trim((string) ($cand['url'] ?? ''));
			$title = trim((string) ($cand['title'] ?? ''));
			if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
				continue;
			}
			// Self-link prevention
			if ($currentUrl !== '' && rtrim($url, '/') === rtrim($currentUrl, '/')) {
				continue;
			}
			// Duplicate link prevention
			if (isset($seenUrls[$url])) {
				continue;
			}
			$seenUrls[$url] = true;

			$titleLow = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
			$overlap = 0;
			foreach ($topicTokens as $tok) {
				if (strlen($tok) > 2 && str_contains($titleLow, $tok)) {
					$overlap++;
				}
			}
			$score = round($overlap / max(1, count($topicTokens)), 2);
			if (($cand['type'] ?? '') === 'priority_category') {
				$score = max($score, 0.5);
			}
			if ($score <= 0.0) {
				continue;
			}

			$proposals[] = [
				'url'         => $url,
				'anchor_text' => $title !== '' ? $title : $topic,
				'score'       => $score,
				'placement'   => ($cand['type'] ?? '') === 'priority_category' ? 'intro_or_cta' : 'body',
			];
		}

		usort($proposals, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
		return array_slice($proposals, 0, max(1, $maxLinks));
	}
}
