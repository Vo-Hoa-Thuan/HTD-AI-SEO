<?php
declare(strict_types=1);

namespace HTD\AISEO\SEO;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * On-page SEO structure & readability enhancer (advisory only; never guarantees rankings).
 */
final class SeoEnhancer {
	/**
	 * @param array<string, mixed> $contentContract
	 * @return array{checks: array<string, bool>, suggestions: string[]}
	 */
	public function analyze(array $contentContract): array {
		$kw = trim((string) ($contentContract['primary_keyword'] ?? ''));
		$title = trim((string) ($contentContract['meta_title'] ?? ($contentContract['suggested_title'] ?? '')));
		$desc = trim((string) ($contentContract['meta_description'] ?? ''));
		$html = (string) ($contentContract['article_html'] ?? '');

		$suggestions = [];
		$hasKwInTitle = $kw !== '' && stripos($title, $kw) !== false;
		if (!$hasKwInTitle) {
			$suggestions[] = 'Include primary keyword in SEO title.';
		}

		$hasH2 = preg_match('/<h2\b/i', $html) === 1;
		if (!$hasH2) {
			$suggestions[] = 'Add H2 subheadings to improve content structure.';
		}

		$descLen = function_exists('mb_strlen') ? mb_strlen($desc, 'UTF-8') : strlen($desc);
		$descOk = $descLen >= 120 && $descLen <= 165;
		if (!$descOk) {
			$suggestions[] = 'Adjust meta description toward 140-160 characters.';
		}

		$wordCount = str_word_count(strip_tags($html));
		if ($wordCount < 300) {
			$suggestions[] = 'Content is short; consider expanding sections with verified information.';
		}

		return [
			'checks' => [
				'keyword_in_title' => $hasKwInTitle,
				'has_h2_headings'  => $hasH2,
				'meta_desc_range'  => $descOk,
				'sufficient_words' => $wordCount >= 300,
			],
			'suggestions' => $suggestions,
		];
	}
}
