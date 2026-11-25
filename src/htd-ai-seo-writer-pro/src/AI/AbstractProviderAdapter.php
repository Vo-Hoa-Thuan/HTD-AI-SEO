<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

use HTD\AISEO\Contracts\AiProviderInterface;
use HTD\AISEO\Security\ExternalRequestGuard;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Base class for official AI provider adapters; routes all HTTP through ExternalRequestGuard.
 */
abstract class AbstractProviderAdapter implements AiProviderInterface {
	public function __construct(
		protected readonly ExternalRequestGuard $httpGuard,
		protected readonly ModelRegistry $models
	) {}

	public function supports(string $capability): bool {
		return in_array($capability, $this->supportedCapabilities(), true);
	}

	/**
	 * @return array<string, mixed>
	 */
	abstract public function buildHttpPayload(AIRequest $request, string $apiKey): array;

	abstract public function parseHttpResponse(int $status, string $body, AIRequest $request): AIResponse;

	public function send(AIRequest $request, string $apiKey): AIResponse {
		if (trim($apiKey) === '') {
			throw new AIException(AIError::INVALID_CREDENTIAL, 'API key is missing.', 401);
		}
		foreach ($request->requiredCapabilities as $cap) {
			if (!$this->supports($cap)) {
				throw new AIException(AIError::UNSUPPORTED_CAPABILITY, "Provider {$this->getProviderId()} does not support {$cap}.", 400);
			}
		}

		$http = $this->buildHttpPayload($request, $apiKey);
		try {
			$res = $this->httpGuard->request($http['url'], [
				'method'  => $http['method'] ?? 'POST',
				'headers' => $http['headers'] ?? [],
				'body'    => $http['body'] ?? '',
				'timeout' => $request->timeoutSeconds,
			]);
		} catch (\Throwable $e) {
			$msg = $e->getMessage();
			if (str_contains(strtolower($msg), 'timed out') || str_contains(strtolower($msg), 'timeout')) {
				throw new AIException(AIError::TIMEOUT, $msg, 408);
			}
			if ((int) $e->getCode() === 403 || (int) $e->getCode() === 423) {
				throw new AIException(AIError::REQUEST_BLOCKED, $msg, (int) $e->getCode());
			}
			throw new AIException(AIError::NETWORK_ERROR, $msg, (int) $e->getCode() ?: 502);
		}

		return $this->parseHttpResponse((int) $res['status'], (string) $res['body'], $request);
	}

	public function completeJson(array $messages, array $options = []): array {
		$req = new AIRequest(
			providerId: $this->getProviderId(),
			model: (string) ($options['model'] ?? ''),
			messages: $messages,
			taskType: (string) ($options['task_type'] ?? 'general'),
			maxOutputTokens: (int) ($options['max_tokens'] ?? 2048),
			temperature: isset($options['temperature']) ? (float) $options['temperature'] : null
		);
		$res = $this->send($req, (string) ($options['api_key'] ?? ''));
		return $res->parsedJson ?? [];
	}

	protected function mapHttpError(int $status, string $body): AIException {
		$lower = strtolower($body);
		if ($status === 401 || $status === 403) {
			return new AIException(AIError::INVALID_CREDENTIAL, "Authentication failed (HTTP {$status}).", $status);
		}
		if ($status === 402 || str_contains($lower, 'billing') || str_contains($lower, 'insufficient_quota') || str_contains($lower, 'credit balance')) {
			$code = str_contains($lower, 'insufficient_quota') ? AIError::QUOTA_EXCEEDED : AIError::BILLING_REQUIRED;
			return new AIException($code, "Billing or quota error (HTTP {$status}).", $status);
		}
		if ($status === 429) {
			return new AIException(AIError::RATE_LIMITED, 'Rate limit exceeded (HTTP 429).', 429, 30);
		}
		if ($status === 404 || ($status === 400 && str_contains($lower, 'model'))) {
			return new AIException(AIError::INVALID_MODEL, "Invalid or unavailable model (HTTP {$status}).", $status);
		}
		if ($status >= 500) {
			return new AIException(AIError::PROVIDER_UNAVAILABLE, "Provider server error (HTTP {$status}).", $status);
		}
		return new AIException(AIError::INVALID_RESPONSE, "Unexpected HTTP {$status} response.", $status);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	protected function decodeJsonText(string $text): ?array {
		$trimmed = trim($text);
		if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/is', $trimmed, $m)) {
			$trimmed = trim($m[1]);
		}
		$decoded = json_decode($trimmed, true);
		return is_array($decoded) ? $decoded : null;
	}
}
