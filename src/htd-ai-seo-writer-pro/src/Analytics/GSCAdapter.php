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
	public const STATE_OPTION   = 'htd_ai_seo_gsc_state';
	public const CRED_TOKENS    = 'gsc_oauth_tokens';

	public function __construct(
		private readonly ExternalRequestGuard $httpGuard,
		private readonly CredentialVault $vault,
		private readonly SEODataRepository $repo
	) {}

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
			throw new \RuntimeException('GSC OAuth token exchange failed.', 401);
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

	public function refreshAccessToken(string $clientId, string $clientSecret): string {
		$raw = $this->vault->getSecret(self::CRED_TOKENS);
		$tokens = is_string($raw) ? json_decode($raw, true) : null;
		$refresh = is_array($tokens) ? (string) ($tokens['refresh_token'] ?? '') : '';
		if ($refresh === '') {
			throw new \RuntimeException('GSC refresh_token is missing; reconnect required.', 401);
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
			$this->updateState(['last_error' => 'Token refresh failed']);
			throw new \RuntimeException('GSC OAuth token refresh failed.', 401);
		}
		$tokens['access_token'] = (string) $data['access_token'];
		$tokens['expires_at'] = time() + max(60, (int) ($data['expires_in'] ?? 3600));
		$this->vault->storeSecret(self::CRED_TOKENS, (string) json_encode($tokens));
		return $tokens['access_token'];
	}

	public function selectProperty(string $siteUrl): void {
		$clean = trim($siteUrl);
		if ($clean === '') {
			throw new \InvalidArgumentException('GSC property URL is required.');
		}
		$this->updateState(['property' => $clean]);
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
			'property'      => is_array($state) ? (string) ($state['property'] ?? '') : '',
			'last_sync_utc' => is_array($state) ? ($state['last_sync_utc'] ?? null) : null,
			'last_end_date' => is_array($state) ? ($state['last_end_date'] ?? null) : null,
			'last_error'    => is_array($state) ? ($state['last_error'] ?? null) : null,
			'scope'         => self::SCOPE_READONLY,
		];
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

	/**
	 * Execute paginated, incremental GSC Search Analytics sync via guarded HTTP API.
	 *
	 * @return array{snapshot_id: int, row_count: int, pages_fetched: int, provenance: string}
	 */
	public function syncSearchAnalytics(
		string $siteUrl = '',
		string $startDate = '',
		string $endDate = '',
		int $rowLimit = 1000,
		int $maxPages = 5
	): array {
		$status = $this->connectionStatus();
		$property = $siteUrl !== '' ? $siteUrl : (string) $status['property'];
		if ($property === '') {
			throw new \RuntimeException('BLOCKED_BY_CONFIGURATION: No GSC property selected.', 400);
		}
		$raw = $this->vault->getSecret(self::CRED_TOKENS);
		$tokens = is_string($raw) ? json_decode($raw, true) : null;
		$accessToken = is_array($tokens) ? (string) ($tokens['access_token'] ?? '') : '';
		if ($accessToken === '') {
			throw new \RuntimeException('BLOCKED_BY_CONFIGURATION: GSC OAuth access token missing.', 401);
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

		$end = $endDate !== '' ? $endDate : gmdate('Y-m-d', strtotime('-2 days'));
		$start = $startDate !== '' ? $startDate : ((string) ($status['last_end_date'] ?? '') ?: gmdate('Y-m-d', strtotime('-28 days')));

		$allRows = [];
		$pages = 0;
		$rowLimit = max(1, min(25000, $rowLimit));
		for ($p = 0; $p < max(1, $maxPages); $p++) {
			$req = $this->buildSearchAnalyticsPayload($property, $start, $end, ['query', 'page'], $rowLimit, $p * $rowLimit, $accessToken);
			$res = $this->httpGuard->request($req['url'], [
				'method'  => $req['method'],
				'headers' => $req['headers'],
				'body'    => $req['body'],
			]);
			$batch = $this->parseSearchAnalyticsResponse((int) $res['status'], (string) $res['body']);
			$pages++;
			$allRows = array_merge($allRows, $batch);
			if (count($batch) < $rowLimit) {
				break;
			}
		}

		$snapshotId = $this->repo->saveSnapshot('gsc_api', $property, 'search_performance', "{$start}..{$end}", SEODataRepository::PROVENANCE_LIVE_VERIFIED, $allRows);
		$this->updateState([
			'connected'     => true,
			'property'      => $property,
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
		$requiredByType = [
			'queries'                => ['query', 'clicks', 'impressions'],
			'search_performance'     => ['query', 'clicks', 'impressions'],
			'pages'                  => ['page', 'clicks', 'impressions'],
			'search_appearance'      => ['search appearance', 'clicks', 'impressions'],
			'generative_ai_features' => ['query', 'impressions'],
		];
		$reqCols = $requiredByType[$reportType] ?? ['clicks', 'impressions'];
		foreach ($reqCols as $col) {
			if (!in_array($col, $headers, true)) {
				throw new \InvalidArgumentException("GSC CSV missing required column '{$col}' for report type '{$reportType}'.");
			}
		}

		$rows = [];
		for ($i = 1; $i < count($lines); $i++) {
			$cols = str_getcsv($lines[$i]);
			if (count($cols) !== count($headers)) {
				throw new \InvalidArgumentException("Malformed GSC CSV row at line " . ($i + 1) . ".");
			}
			$item = [];
			foreach ($headers as $idx => $h) {
				$item[$h] = trim((string) $cols[$idx]);
			}
			if (isset($item['clicks']) && !is_numeric($item['clicks'])) {
				throw new \InvalidArgumentException("Invalid numeric clicks value at line " . ($i + 1) . ".");
			}
			if (isset($item['impressions']) && !is_numeric($item['impressions'])) {
				throw new \InvalidArgumentException("Invalid numeric impressions value at line " . ($i + 1) . ".");
			}
			if (isset($item['position']) && (!is_numeric($item['position']) || (float) $item['position'] < 0)) {
				throw new \InvalidArgumentException("Invalid position unit at line " . ($i + 1) . ".");
			}
			$rows[] = $item;
		}
		if ($rows === []) {
			throw new \InvalidArgumentException('No valid data rows found in GSC CSV.');
		}
		$id = $this->repo->saveSnapshot('gsc_import', $property, $reportType, $dateRange, SEODataRepository::PROVENANCE_IMPORTED_VERIFIED, $rows);
		return ['snapshot_id' => $id, 'row_count' => count($rows), 'report_type' => $reportType, 'provenance' => SEODataRepository::PROVENANCE_IMPORTED_VERIFIED];
	}
}
