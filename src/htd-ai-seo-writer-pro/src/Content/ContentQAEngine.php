<?php
declare(strict_types=1);

namespace HTD\AISEO\Content;

use HTD\AISEO\Brand\BrandProfile;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Rule-based Content QA engine (distinct from software QA15).
 * Never guarantees Google rankings or auto-invents missing product specs.
 */
final class ContentQAEngine {
	public function __construct(private readonly BrandProfile $brand) {}

	/**
	 * @param array<string, mixed> $contract
	 * @return array{status: string, checks: array<string, string>, warnings: string[], errors: string[]}
	 */
	public function evaluate(array $contract): array {
		$warnings = [];
		$errors = [];
		$checks = [];

		$keyword = trim((string) ($contract['primary_keyword'] ?? ''));
		$html = (string) ($contract['article_html'] ?? '');
		$metaDesc = (string) ($contract['meta_description'] ?? '');
		$title = (string) ($contract['suggested_title'] ?? '');

		// 1. Non-empty content & HTML Safety & Prompt Injection Residual
		if (trim(strip_tags($html)) === '') {
			$errors[] = 'Article HTML content cannot be empty.';
			$checks['content_non_empty'] = 'FAIL';
		} else {
			$checks['content_non_empty'] = 'PASS';
		}
		if (preg_match('/<script\b|<iframe\b|<\?php|on[a-z]+\s*=/i', $html)) {
			$errors[] = 'Unsafe HTML or executable tag detected.';
			$checks['html_safety'] = 'FAIL';
		} else {
			$checks['html_safety'] = 'PASS';
		}

		if (str_contains($html, '<untrusted_data') || str_contains($title, '<untrusted_data')) {
			$errors[] = 'Prompt injection delimiter leaked into output.';
			$checks['prompt_injection'] = 'FAIL';
		} else {
			$checks['prompt_injection'] = 'PASS';
		}

		// 2. Heading structure (at most 1 H1 in body, prefer H2/H3)
		preg_match_all('/<h1\b/i', $html, $h1Matches);
		if (count($h1Matches[0]) > 1) {
			$warnings[] = 'Multiple H1 tags detected in article HTML.';
			$checks['heading_structure'] = 'WARNING';
		} else {
			$checks['heading_structure'] = 'PASS';
		}

		// 3. Keyword relevance
		$plain = strip_tags($html . ' ' . $title);
		if ($keyword !== '' && stripos($plain, $keyword) === false) {
			$warnings[] = "Primary keyword '{$keyword}' missing from title/body.";
			$checks['keyword_relevance'] = 'WARNING';
		} else {
			$checks['keyword_relevance'] = 'PASS';
		}

		// 4. Meta description advisory (140-160 chars)
		$metaLen = function_exists('mb_strlen') ? mb_strlen($metaDesc, 'UTF-8') : strlen($metaDesc);
		if ($metaLen < 120 || $metaLen > 170) {
			$warnings[] = "Meta description length ({$metaLen} chars) outside 120-170 advisory range.";
			$checks['meta_quality'] = 'WARNING';
		} else {
			$checks['meta_quality'] = 'PASS';
		}

		// 5. Unsupported / Forbidden claims
		$brandCfg = $this->brand->get();
		$forbidden = array_merge((array) ($brandCfg['forbidden_claims'] ?? []), ['Cam kết Top 1', 'Đảm bảo Top 3']);
		$claimHit = false;
		foreach ($forbidden as $claim) {
			if ($claim !== '' && stripos($plain, (string) $claim) !== false) {
				$errors[] = "Forbidden/unsupported claim detected: {$claim}";
				$claimHit = true;
			}
		}
		$checks['brand_compliance'] = $claimHit ? 'FAIL' : 'PASS';

		// 6. Missing product data / NEEDS_VERIFICATION
		$needsVerif = str_contains($html, 'NEEDS_VERIFICATION') || !empty($contract['verification_warnings']);
		$checks['product_facts'] = $needsVerif ? 'NEEDS_VERIFICATION' : 'PASS';

		$status = 'PASS';
		if ($errors !== []) {
			$status = 'FAIL';
		} elseif ($needsVerif) {
			$status = 'NEEDS_VERIFICATION';
		} elseif ($warnings !== []) {
			$status = 'WARNING';
		}

		return [
			'status'   => $status,
			'checks'   => $checks,
			'warnings' => $warnings,
			'errors'   => $errors,
		];
	}
}
