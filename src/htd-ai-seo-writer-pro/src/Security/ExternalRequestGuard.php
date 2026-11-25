<?php
declare(strict_types=1);

namespace HTD\AISEO\Security;

use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Settings\SettingsRegistry;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Default-deny outbound HTTP guard with strict provider allowlist, HTTPS, and SSRF protection.
 */
final class ExternalRequestGuard {
	/** @var string[] */
	private const DEFAULT_ALLOWLIST = [
		'api.openai.com',
		'generativelanguage.googleapis.com',
		'api.anthropic.com',
		'api.deepseek.com',
		'api.x.ai',
		'auth.openai.com',
		'chatgpt.com',
	];

	/** @var string[] */
	private const BLOCKED_DOMAINS = [
		'softviet.vn',
		'ai.softviet.vn',
	];

	private const MAX_TIMEOUT_SECONDS = 60;
	private const MAX_RESPONSE_BYTES  = 5242880; // 5 MB

	/** @var string[] */
	private array $allowlist;

	public function __construct(
		private readonly SettingsRegistry $settings,
		private readonly Logger $logger,
		?array $allowlist = null
	) {
		$this->allowlist = $allowlist ?? self::DEFAULT_ALLOWLIST;
	}

	/**
	 * Validate URL against HTTPS, SSRF, and allowlist rules without sending a network request.
	 */
	public function validateUrl(string $url): void {
		if ((bool) $this->settings->get('emergency_stop', false)) {
			throw new \RuntimeException('Outbound requests blocked: EMERGENCY_STOP is active.', 423);
		}
		if (!(bool) $this->settings->get('allow_external_ai_requests', false)) {
			throw new \RuntimeException('Outbound requests blocked: ALLOW_EXTERNAL_AI_REQUESTS is false.', 403);
		}

		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			throw new \InvalidArgumentException('Invalid outbound URL.');
		}

		if (strtolower((string) $parts['scheme']) !== 'https') {
			throw new \RuntimeException('Only HTTPS outbound requests are permitted.', 403);
		}

		$host = strtolower((string) $parts['host']);
		foreach (self::BLOCKED_DOMAINS as $blocked) {
			if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
				throw new \RuntimeException("Blocked vendor domain: {$host}", 403);
			}
		}

		if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
			throw new \RuntimeException("SSRF blocked: local hostname {$host}", 403);
		}

		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			if ($this->isPrivateOrReservedIp($host)) {
				throw new \RuntimeException("SSRF blocked: private/reserved IP {$host}", 403);
			}
			// Direct IP literals are also rejected by domain allowlist
			throw new \RuntimeException("Direct IP URLs are not allowlisted: {$host}", 403);
		}

		if (!in_array($host, $this->allowlist, true)) {
			throw new \RuntimeException("Domain not in HTD allowlist: {$host}", 403);
		}
	}

	private function isPrivateOrReservedIp(string $ip): bool {
		return filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) === false;
	}

	/**
	 * Execute a guarded HTTP request via WordPress HTTP API.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function request(string $url, array $args = []): array {
		$start = microtime(true);
		try {
			$this->validateUrl($url);

			$timeout = min(self::MAX_TIMEOUT_SECONDS, max(1, (int) ($args['timeout'] ?? 30)));
			$wpArgs = [
				'method'      => strtoupper((string) ($args['method'] ?? 'POST')),
				'timeout'     => $timeout,
				'redirection' => 0, // Never forward credentials on redirect
				'sslverify'   => true,
				'headers'     => is_array($args['headers'] ?? null) ? $args['headers'] : [],
				'body'        => $args['body'] ?? null,
			];

			if (!function_exists('wp_remote_request')) {
				throw new \RuntimeException('WordPress HTTP API unavailable.');
			}

			$response = wp_remote_request($url, $wpArgs);
			if (is_wp_error($response)) {
				throw new \RuntimeException($response->get_error_message(), 502);
			}

			$body = (string) wp_remote_retrieve_body($response);
			if (strlen($body) > self::MAX_RESPONSE_BYTES) {
				throw new \RuntimeException('Outbound response exceeded maximum size limit.', 502);
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$duration = (int) round((microtime(true) - $start) * 1000);
			$this->logger->log('http_guard', 'outbound_request', 'INFO', 'OK', [
				'host'        => parse_url($url, PHP_URL_HOST),
				'status_code' => $code,
			], $duration);

			return [
				'status' => $code,
				'body'   => $body,
			];
		} catch (\Throwable $e) {
			$duration = (int) round((microtime(true) - $start) * 1000);
			$this->logger->log('http_guard', 'outbound_blocked', 'SECURITY', 'DENIED', [
				'host'  => parse_url($url, PHP_URL_HOST) ?: 'invalid',
				'error' => $e->getMessage(),
			], $duration);
			throw $e;
		}
	}
}
