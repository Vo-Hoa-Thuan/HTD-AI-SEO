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
		'www.googleapis.com',
		'searchconsole.googleapis.com',
		'analyticsdata.googleapis.com',
		'oauth2.googleapis.com',
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
	/** @var callable|null */
	private $dnsResolver;

	public function __construct(
		private readonly SettingsRegistry $settings,
		private readonly Logger $logger,
		?array $allowlist = null,
		?callable $dnsResolver = null
	) {
		$this->allowlist = $allowlist ?? self::DEFAULT_ALLOWLIST;
		$this->dnsResolver = $dnsResolver;
	}

	public function setDnsResolver(?callable $resolver): void {
		$this->dnsResolver = $resolver;
	}

	public function addAllowlistedHost(string $host): void {
		$h = strtolower(trim($host));
		if ($h !== '' && !in_array($h, self::BLOCKED_DOMAINS, true) && !in_array($h, $this->allowlist, true)) {
			$this->allowlist[] = $h;
		}
	}

	/**
	 * Validate URL against HTTPS, userinfo, port, SSRF, DNS resolution, and allowlist rules.
	 *
	 * @return string[] Resolved public IP addresses (when DNS resolution runs)
	 */
	public function validateUrl(string $url, bool $resolveDns = false): array {
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
		if (!empty($parts['user']) || !empty($parts['pass'])) {
			throw new \RuntimeException('URL userinfo (user:pass@) is forbidden.', 403);
		}
		if (isset($parts['port']) && (int) $parts['port'] !== 443) {
			throw new \RuntimeException('Unsafe outbound port; only HTTPS port 443 is permitted.', 403);
		}

		if (strtolower((string) $parts['scheme']) !== 'https') {
			throw new \RuntimeException('Only HTTPS outbound requests are permitted.', 403);
		}

		$host = strtolower((string) $parts['host']);
		$host = trim($host, '[]');
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

		if ($resolveDns || $this->dnsResolver !== null) {
			return $this->resolveAndValidateHost($host);
		}
		return [];
	}

	/**
	 * Resolve DNS A/AAAA records and block any private, loopback, link-local, or reserved IP (DNS rebinding mitigation).
	 *
	 * @return string[]
	 */
	public function resolveAndValidateHost(string $host): array {
		$ips = [];
		if ($this->dnsResolver !== null) {
			$ips = (array) ($this->dnsResolver)($host);
		} elseif (function_exists('dns_get_record')) {
			$records = @dns_get_record($host, DNS_A | DNS_AAAA);
			if (is_array($records)) {
				foreach ($records as $rec) {
					if (!empty($rec['ip'])) {
						$ips[] = (string) $rec['ip'];
					} elseif (!empty($rec['ipv6'])) {
						$ips[] = (string) $rec['ipv6'];
					}
				}
			}
		}
		if ($ips === [] && function_exists('gethostbynamel')) {
			$v4 = @gethostbynamel($host);
			if (is_array($v4)) {
				$ips = $v4;
			}
		}
		if ($ips === []) {
			throw new \RuntimeException("DNS resolution failed for host: {$host}", 502);
		}
		foreach ($ips as $ip) {
			if (filter_var((string) $ip, FILTER_VALIDATE_IP) === false || $this->isPrivateOrReservedIp((string) $ip)) {
				throw new \RuntimeException("SSRF/DNS-rebinding blocked: {$host} resolved to unsafe IP {$ip}", 403);
			}
		}
		return $ips;
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
			$this->validateUrl($url, true);

			$timeout = min(self::MAX_TIMEOUT_SECONDS, max(1, (int) ($args['timeout'] ?? 30)));
			$wpArgs = [
				'method'              => strtoupper((string) ($args['method'] ?? 'POST')),
				'timeout'             => $timeout,
				'redirection'         => 0, // Never forward credentials on redirect
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => is_array($args['headers'] ?? null) ? $args['headers'] : [],
				'body'                => $args['body'] ?? null,
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
