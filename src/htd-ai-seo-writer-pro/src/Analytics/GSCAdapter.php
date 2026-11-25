<?php
declare(strict_types=1);

namespace HTD\AISEO\Analytics;

use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Security\ExternalRequestGuard;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Official Google Search Console API adapter & CSV/JSON Report Importer.
 * Read-only scope: https://www.googleapis.com/auth/webmasters.readonly
 */
final class GSCAdapter {
	public const SCOPE_READONLY = 'https://www.googleapis.com/auth/webmasters.readonly';

	public function __construct(
		private readonly ExternalRequestGuard $httpGuard,
		private readonly CredentialVault $vault,
		private readonly SEODataRepository $repo
	) {}

	/**
	 * @param string[] $dimensions e.g. ['query', 'page']
	 * @return array<string, mixed>
	 */
	public function buildSearchAnalyticsPayload(
		string $siteUrl,
		string $startDate,
		string $endDate,
		array $dimensions = ['query', 'page'],
		int $rowLimit = 1000,
		int $startRow = 0,
		string $accessToken = ''
	): array {
		$encodedSite = rawurlencode($siteUrl);
		return [
			'url'     => "https://searchconsole.googleapis.com/webmasters/v3/sites/{$encodedSite}/searchAnalytics/query",
			'method'  => 'POST',
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $accessToken,
			],
			'body'    => (string) json_encode([
				'startDate'  => $startDate,
				'endDate'    => $endDate,
				'dimensions' => $dimensions,
				'rowLimit'   => max(1, min(25000, $rowLimit)),
				'startRow'   => max(0, $startRow),
			], JSON_UNESCAPED_SLASHES),
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function parseSearchAnalyticsResponse(int $status, string $body): array {
		if ($status === 429) {
			throw new \RuntimeException('GSC API quota/rate limit exceeded.', 429);
		}
		if ($status < 200 || $status >= 300) {
			throw new \RuntimeException("GSC API error (HTTP {$status}).", $status);
		}
		$data = json_decode($body, true);
		if (!is_array($data)) {
			throw new \RuntimeException('Malformed GSC JSON response.', 422);
		}
		$out = [];
		foreach ((array) ($data['rows'] ?? []) as $row) {
			$keys = (array) ($row['keys'] ?? []);
			$out[] = [
				'query'       => (string) ($keys[0] ?? ''),
				'page'        => (string) ($keys[1] ?? ''),
				'clicks'      => (int) ($row['clicks'] ?? 0),
				'impressions' => (int) ($row['impressions'] ?? 0),
				'ctr'         => round((float) ($row['ctr'] ?? 0.0), 4),
				'position'    => round((float) ($row['position'] ?? 0.0), 2),
			];
		}
		return $out;
	}

	/**
	 * Import manual GSC CSV export (Queries/Pages/Search Appearance/Generative AI Features).
	 *
	 * @return array{snapshot_id: int, row_count: int, report_type: string}
	 */
	public function importCsvReport(string $csvContent, string $property, string $reportType, string $dateRange): array {
		$allowedTypes = ['search_performance', 'queries', 'pages', 'search_appearance', 'links', 'indexing', 'generative_ai_features'];
		if (!in_array($reportType, $allowedTypes, true)) {
			throw new \InvalidArgumentException("Unsupported GSC report type: {$reportType}");
		}
		$lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
		if (count($lines) < 2) {
			throw new \InvalidArgumentException('GSC CSV report must include header and at least one row.');
		}
		$headers = array_map(static fn($h): string => strtolower(trim((string) $h)), str_getcsv($lines[0]));
		$rows = [];
		for ($i = 1; $i < count($lines); $i++) {
			$cols = str_getcsv($lines[$i]);
			if (count($cols) !== count($headers)) {
				continue;
			}
			$item = [];
			foreach ($headers as $idx => $h) {
				$item[$h] = trim((string) $cols[$idx]);
			}
			$rows[] = $item;
		}
		if ($rows === []) {
			throw new \InvalidArgumentException('No valid data rows found in GSC CSV.');
		}
		$id = $this->repo->saveSnapshot('gsc_import', $property, $reportType, $dateRange, SEODataRepository::PROVENANCE_IMPORTED_VERIFIED, $rows);
		return ['snapshot_id' => $id, 'row_count' => count($rows), 'report_type' => $reportType];
	}
}
