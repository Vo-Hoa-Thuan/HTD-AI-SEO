<?php
declare(strict_types=1);

namespace HTD\AISEO\Growth;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read-only Technical SEO Audit & High-Traffic URL Protection Engine.
 */
final class TechnicalAuditAndTrafficGuard {
	/** @var string[] */
	private const DEFAULT_PROTECTED_URLS = [
		'https://htd.net.vn/huong-dan-xem-youtube-tren-android-auto-cap-nhat-2024/',
	];

	/**
	 * Read-only audit across a list of site page snapshots.
	 *
	 * @param array<int, array<string, mixed>> $pages
	 * @return array{issues: array<int, array<string, string>>, cwv_status: string}
	 */
	public function auditPages(array $pages, ?array $measuredCwv = null): array {
		$issues = [];
		$titlesSeen = [];

		foreach ($pages as $p) {
			$url = (string) ($p['url'] ?? '');
			$status = (int) ($p['http_status'] ?? 200);
			$title = trim((string) ($p['title'] ?? ''));
			$metaDesc = trim((string) ($p['meta_description'] ?? ''));
			$canonical = trim((string) ($p['canonical'] ?? ''));

			if ($status >= 400) {
				$issues[] = ['priority' => 'P0', 'url' => $url, 'issue' => "HTTP {$status} error detected"];
			}
			if ($title !== '') {
				if (isset($titlesSeen[$title])) {
					$issues[] = ['priority' => 'P1', 'url' => $url, 'issue' => "Duplicate title with {$titlesSeen[$title]}"];
				}
				$titlesSeen[$title] = $url;
			}
			if ($metaDesc === '') {
				$issues[] = ['priority' => 'P2', 'url' => $url, 'issue' => 'Missing meta description'];
			}
			if ($canonical === '') {
				$issues[] = ['priority' => 'P2', 'url' => $url, 'issue' => 'Missing canonical tag (read-only observation)'];
			}
		}

		return [
			'issues'     => $issues,
			'cwv_status' => $measuredCwv !== null ? 'MEASURED_PROVIDED' : 'NOT_MEASURED_NO_DATA',
		];
	}

	/**
	 * Evaluate whether a URL is protected by Traffic Protection Engine.
	 *
	 * @param array<string, mixed> $gscMetrics {clicks, impressions}
	 * @return array{protected: bool, policy: string, reason: string}
	 */
	public function checkTrafficProtection(string $url, array $gscMetrics = [], int $clickThreshold = 100): array {
		$norm = rtrim(strtolower(trim($url)), '/');
		foreach (self::DEFAULT_PROTECTED_URLS as $pUrl) {
			if (rtrim(strtolower($pUrl), '/') === $norm) {
				return [
					'protected' => true,
					'policy'    => 'MINIMAL_CHANGE_ONLY_REQUIRE_APPROVAL',
					'reason'    => 'Explicitly protected high-traffic HTD guide URL (YouTube on Android Auto).',
				];
			}
		}
		$clicks = (int) ($gscMetrics['clicks'] ?? 0);
		if ($clicks >= $clickThreshold) {
			return [
				'protected' => true,
				'policy'    => 'MINIMAL_CHANGE_ONLY_REQUIRE_APPROVAL',
				'reason'    => "High organic clicks ({$clicks} >= {$clickThreshold}); full rewrite blocked without explicit approval.",
			];
		}
		return ['protected' => false, 'policy' => 'STANDARD_APPROVAL', 'reason' => 'Below high-traffic threshold.'];
	}
}
