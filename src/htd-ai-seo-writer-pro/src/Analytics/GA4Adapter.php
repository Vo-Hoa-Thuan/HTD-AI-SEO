<?php
declare(strict_types=1);

namespace HTD\AISEO\Analytics;

use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Security\ExternalRequestGuard;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Official Google Analytics 4 Data API (v1beta) adapter.
 * Never conflates GA4 sessions with GSC clicks or fabricates revenue/causality.
 */
final class GA4Adapter {
	public function __construct(
		private readonly ExternalRequestGuard $httpGuard,
		private readonly CredentialVault $vault,
		private readonly SEODataRepository $repo
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function buildRunReportPayload(
		string $propertyId,
		string $startDate,
		string $endDate,
		string $accessToken = '',
		string $channelFilter = 'Organic Search'
	): array {
		$prop = preg_replace('/[^0-9]/', '', $propertyId) ?? '';
		if ($prop === '') {
			throw new \InvalidArgumentException('Valid numeric GA4 property ID is required.');
		}
		$payload = [
			'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
			'dimensions' => [['name' => 'landingPagePlusQueryString'], ['name' => 'sessionDefaultChannelGroup']],
			'metrics'    => [
				['name' => 'totalUsers'],
				['name' => 'sessions'],
				['name' => 'engagedSessions'],
				['name' => 'engagementRate'],
				['name' => 'screenPageViews'],
				['name' => 'averageSessionDuration'],
				['name' => 'keyEvents'],
			],
		];
		if ($channelFilter !== '') {
			$payload['dimensionFilter'] = [
				'filter' => [
					'fieldName'    => 'sessionDefaultChannelGroup',
					'stringFilter' => ['matchType' => 'EXACT', 'value' => $channelFilter],
				],
			];
		}

		return [
			'url'     => "https://analyticsdata.googleapis.com/v1beta/properties/{$prop}:runReport",
			'method'  => 'POST',
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $accessToken,
			],
			'body'    => (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function parseRunReportResponse(int $status, string $body): array {
		if ($status < 200 || $status >= 300) {
			throw new \RuntimeException("GA4 API error (HTTP {$status}).", $status);
		}
		$data = json_decode($body, true);
		if (!is_array($data)) {
			throw new \RuntimeException('Malformed GA4 JSON response.', 422);
		}
		$out = [];
		foreach ((array) ($data['rows'] ?? []) as $row) {
			$dims = (array) ($row['dimensionValues'] ?? []);
			$mets = (array) ($row['metricValues'] ?? []);
			$out[] = [
				'landing_page'         => (string) ($dims[0]['value'] ?? ''),
				'channel'              => (string) ($dims[1]['value'] ?? ''),
				'users'                => (int) ($mets[0]['value'] ?? 0),
				'sessions'             => (int) ($mets[1]['value'] ?? 0),
				'engaged_sessions'     => (int) ($mets[2]['value'] ?? 0),
				'engagement_rate'      => round((float) ($mets[3]['value'] ?? 0.0), 4),
				'page_views'           => (int) ($mets[4]['value'] ?? 0),
				'avg_engagement_sec'   => round((float) ($mets[5]['value'] ?? 0.0), 2),
				'key_events'           => (int) ($mets[6]['value'] ?? 0),
			];
		}
		return $out;
	}
}
