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
	public const SCOPE_READONLY = 'https://www.googleapis.com/auth/analytics.readonly';
	public const STATE_OPTION   = 'htd_ai_seo_ga4_state';
	public const CRED_TOKENS    = 'ga4_oauth_tokens';

	public function __construct(
		private readonly ExternalRequestGuard $httpGuard,
		private readonly CredentialVault $vault,
		private readonly SEODataRepository $repo
	) {}

	public function selectProperty(string $propertyId): void {
		$prop = preg_replace('/[^0-9]/', '', $propertyId) ?? '';
		if ($prop === '') {
			throw new \InvalidArgumentException('Valid numeric GA4 property ID is required.');
		}
		$this->updateState(['property_id' => $prop]);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function connectionStatus(): array {
		$state = function_exists('get_option') ? get_option(self::STATE_OPTION, []) : [];
		$raw = $this->vault->getSecret(self::CRED_TOKENS);
		$tokens = is_string($raw) ? json_decode($raw, true) : null;
		return [
			'connected'     => is_array($tokens) && !empty($tokens['access_token']),
			'property_id'   => is_array($state) ? (string) ($state['property_id'] ?? '') : '',
			'last_sync_utc' => is_array($state) ? ($state['last_sync_utc'] ?? null) : null,
			'last_end_date' => is_array($state) ? ($state['last_end_date'] ?? null) : null,
			'last_error'    => is_array($state) ? ($state['last_error'] ?? null) : null,
			'scope'         => self::SCOPE_READONLY,
		];
	}

	public function buildAuthUrl(string $clientId, string $redirectUri, string $state): string {
		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
			'client_id'     => $clientId,
			'redirect_uri'  => $redirectUri,
			'response_type' => 'code',
			'scope'         => self::SCOPE_READONLY,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		], '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function exchangeAuthCode(string $code, string $clientId, string $clientSecret, string $redirectUri): array {
		$res = $this->httpGuard->request('https://oauth2.googleapis.com/token', [
			'method'  => 'POST',
			'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
			'body'    => http_build_query([
				'code'          => $code,
				'client_id'     => $clientId,
				'client_secret' => $clientSecret,
				'redirect_uri'  => $redirectUri,
				'grant_type'    => 'authorization_code',
			]),
		]);
		$data = json_decode((string) ($res['body'] ?? ''), true);
		if ((int) ($res['status'] ?? 0) !== 200 || !is_array($data) || empty($data['access_token'])) {
			throw new \RuntimeException('GA4 OAuth token exchange failed.', 401);
		}
		$tokens = [
			'access_token'  => (string) $data['access_token'],
			'refresh_token' => (string) ($data['refresh_token'] ?? ''),
			'expires_at'    => time() + max(60, (int) ($data['expires_in'] ?? 3600)),
			'scope'         => self::SCOPE_READONLY,
		];
		$this->vault->storeSecret(self::CRED_TOKENS, (string) json_encode($tokens));
		$this->updateState(['connected' => true, 'last_error' => null]);
		return $tokens;
	}

	public function disconnect(): void {
		$raw = $this->vault->getSecret(self::CRED_TOKENS);
		$tokens = is_string($raw) ? json_decode($raw, true) : null;
		$tok = is_array($tokens) ? (string) ($tokens['refresh_token'] ?: ($tokens['access_token'] ?? '')) : '';
		if ($tok !== '') {
			try {
				$this->httpGuard->request('https://oauth2.googleapis.com/revoke', [
					'method'  => 'POST',
					'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
					'body'    => http_build_query(['token' => $tok]),
				]);
			} catch (\Throwable) {
				// Local disconnect still proceeds even if remote revocation is disabled/offline
			}
		}
		$this->vault->deleteSecret(self::CRED_TOKENS);
		if (function_exists('delete_option')) {
			delete_option(self::STATE_OPTION);
		}
	}

	public function refreshAccessToken(string $clientId, string $clientSecret): string {
		$raw = $this->vault->getSecret(self::CRED_TOKENS);
		$tokens = is_string($raw) ? json_decode($raw, true) : null;
		$refresh = is_array($tokens) ? (string) ($tokens['refresh_token'] ?? '') : '';
		if ($refresh === '') {
			throw new \RuntimeException('GA4 refresh_token is missing; reconnect required.', 401);
		}
		$res = $this->httpGuard->request('https://oauth2.googleapis.com/token', [
			'method'  => 'POST',
			'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
			'body'    => http_build_query([
				'refresh_token' => $refresh,
				'client_id'     => $clientId,
				'client_secret' => $clientSecret,
				'grant_type'    => 'refresh_token',
			]),
		]);
		$data = json_decode((string) ($res['body'] ?? ''), true);
		if ((int) ($res['status'] ?? 0) !== 200 || !is_array($data) || empty($data['access_token'])) {
			$this->updateState(['last_error' => 'GA4 token refresh failed']);
			throw new \RuntimeException('GA4 OAuth token refresh failed.', 401);
		}
		$tokens['access_token'] = (string) $data['access_token'];
		$tokens['expires_at'] = time() + max(60, (int) ($data['expires_in'] ?? 3600));
		$this->vault->storeSecret(self::CRED_TOKENS, (string) json_encode($tokens));
		return $tokens['access_token'];
	}

	/**
	 * Execute paginated, incremental GA4 Data API sync via guarded HTTP API.
	 *
	 * @return array{snapshot_id: int, row_count: int, pages_fetched: int, provenance: string}
	 */
	public function syncReport(
		string $propertyId = '',
		string $startDate = '',
		string $endDate = '',
		string $channelFilter = 'Organic Search',
		int $limit = 1000,
		int $maxPages = 5
	): array {
		$status = $this->connectionStatus();
		$prop = $propertyId !== '' ? (preg_replace('/[^0-9]/', '', $propertyId) ?? '') : (string) $status['property_id'];
		if ($prop === '') {
			throw new \RuntimeException('BLOCKED_BY_CONFIGURATION: No GA4 property selected.', 400);
		}
		$raw = $this->vault->getSecret(self::CRED_TOKENS);
		$tokens = is_string($raw) ? json_decode($raw, true) : null;
		$accessToken = is_array($tokens) ? (string) ($tokens['access_token'] ?? '') : '';
		if ($accessToken === '') {
			throw new \RuntimeException('BLOCKED_BY_CONFIGURATION: GA4 OAuth access token missing.', 401);
		}
		$expiresAt = is_array($tokens) ? (int) ($tokens['expires_at'] ?? 0) : 0;
		if ($expiresAt > 0 && $expiresAt <= (time() + 60)) {
			$oauthCfg = function_exists('get_option') ? get_option('htd_ai_seo_google_oauth_cfg', []) : [];
			$cid = is_array($oauthCfg) ? (string) ($oauthCfg['client_id'] ?? '') : '';
			$csec = (string) ($this->vault->getSecret('google_oauth_client_secret') ?? '');
			if ($cid !== '' && $csec !== '') {
				$accessToken = $this->refreshAccessToken($cid, $csec);
			}
		}

		$end = $endDate !== '' ? $endDate : gmdate('Y-m-d', strtotime('-1 day'));
		$start = $startDate !== '' ? $startDate : ((string) ($status['last_end_date'] ?? '') ?: gmdate('Y-m-d', strtotime('-28 days')));

		$allRows = [];
		$pages = 0;
		$limit = max(1, min(10000, $limit));
		for ($p = 0; $p < max(1, $maxPages); $p++) {
			$req = $this->buildRunReportPayload($prop, $start, $end, $accessToken, $channelFilter, $limit, $p * $limit);
			$res = $this->httpGuard->request($req['url'], [
				'method'  => $req['method'],
				'headers' => $req['headers'],
				'body'    => $req['body'],
			]);
			$batch = $this->parseRunReportResponse((int) $res['status'], (string) $res['body']);
			$pages++;
			$allRows = array_merge($allRows, $batch);
			if (count($batch) < $limit) {
				break;
			}
		}

		$snapshotId = $this->repo->saveSnapshot('ga4', $prop, 'organic_landing_pages', "{$start}..{$end}", SEODataRepository::PROVENANCE_LIVE_VERIFIED, $allRows);
		$this->updateState([
			'connected'     => true,
			'property_id'   => $prop,
			'last_sync_utc' => gmdate('Y-m-d H:i:s'),
			'last_end_date' => $end,
			'last_error'    => null,
		]);
		return [
			'snapshot_id'   => $snapshotId,
			'row_count'     => count($allRows),
			'pages_fetched' => $pages,
			'provenance'    => SEODataRepository::PROVENANCE_LIVE_VERIFIED,
		];
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	private function updateState(array $patch): void {
		$cur = function_exists('get_option') ? get_option(self::STATE_OPTION, []) : [];
		if (!is_array($cur)) {
			$cur = [];
		}
		if (function_exists('update_option')) {
			update_option(self::STATE_OPTION, array_merge($cur, $patch), false);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function buildRunReportPayload(
		string $propertyId,
		string $startDate,
		string $endDate,
		string $accessToken = '',
		string $channelFilter = 'Organic Search',
		int $limit = 1000,
		int $offset = 0
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
			'limit'      => max(1, min(10000, $limit)),
			'offset'     => max(0, $offset),
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
