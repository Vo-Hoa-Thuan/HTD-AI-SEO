<?php
declare(strict_types=1);

namespace HTD\AISEO\AI\Providers;

use HTD\AISEO\AI\AIError;
use HTD\AISEO\AI\AIException;
use HTD\AISEO\AI\AIRequest;
use HTD\AISEO\AI\AIResponse;
use HTD\AISEO\AI\AbstractProviderAdapter;
use HTD\AISEO\AI\Capability;
use HTD\AISEO\AI\ModelRegistry;
use HTD\AISEO\AI\UsageMetadata;
use HTD\AISEO\Security\ExternalRequestGuard;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * FEAT_002: Custom OpenAI-compatible Base URL provider with strict SSRF, HTTPS, and isolated credential enforcement.
 */
final class CustomOpenAICompatibleProvider extends AbstractProviderAdapter {
	private string $baseUrl = '';

	public function __construct(
		ExternalRequestGuard $httpGuard,
		ModelRegistry $models,
		string $baseUrl = ''
	) {
		parent::__construct($httpGuard, $models);
		if ($baseUrl !== '') {
			$this->setBaseUrl($baseUrl);
		}
	}

	public function getProviderId(): string {
		return 'custom';
	}

	public function supportedCapabilities(): array {
		return [
			Capability::TEXT_GENERATION,
			Capability::JSON_OUTPUT,
			Capability::TOKEN_USAGE,
		];
	}

	public function setBaseUrl(string $url): void {
		$trimmed = rtrim(trim($url), '/');
		$parts = parse_url($trimmed);
		if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
			throw new AIException(AIError::REQUEST_BLOCKED, 'Custom Base URL must be a valid HTTPS URL.', 400);
		}
		$host = strtolower((string) $parts['host']);
		if ($host === 'softviet.vn' || str_ends_with($host, '.softviet.vn')) {
			throw new AIException(AIError::REQUEST_BLOCKED, 'SoftViet domains are blocked.', 403);
		}
		$this->baseUrl = $trimmed;
		$this->httpGuard->addAllowlistedHost($host);
	}

	public function buildHttpPayload(AIRequest $request, string $apiKey): array {
		if ($this->baseUrl === '') {
			throw new AIException(AIError::PROVIDER_UNAVAILABLE, 'Custom Base URL is not configured.', 400);
		}
		$endpoint = str_ends_with($this->baseUrl, '/chat/completions') ? $this->baseUrl : ($this->baseUrl . '/chat/completions');
		$payload = [
			'model'      => $request->model,
			'messages'   => $request->messages,
			'max_tokens' => $request->maxOutputTokens,
		];
		if ($request->temperature !== null) {
			$payload['temperature'] = $request->temperature;
		}
		return [
			'url'     => $endpoint,
			'method'  => 'POST',
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $apiKey,
			],
			'body'    => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public function parseHttpResponse(int $status, string $body, AIRequest $request): AIResponse {
		if ($status < 200 || $status >= 300) {
			throw $this->mapHttpError($status, $body);
		}
		$data = json_decode($body, true);
		if (!is_array($data) || empty($data['choices'][0]['message'])) {
			throw new AIException(AIError::INVALID_RESPONSE, 'Malformed custom endpoint response JSON.', $status);
		}
		$text = (string) ($data['choices'][0]['message']['content'] ?? '');
		$inTok = (int) ($data['usage']['prompt_tokens'] ?? 0);
		$outTok = (int) ($data['usage']['completion_tokens'] ?? 0);
		return new AIResponse(
			providerId: 'custom',
			model: $request->model,
			rawText: $text,
			parsedJson: $this->decodeJsonText($text),
			usage: new UsageMetadata($inTok, $outTok, $inTok + $outTok, 'PROVIDER_REPORTED_TOKENS', 0.0, 'UNKNOWN_CUSTOM_PRICING'),
			finishReason: isset($data['choices'][0]['finish_reason']) ? (string) $data['choices'][0]['finish_reason'] : null
		);
	}
}
