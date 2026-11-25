<?php
declare(strict_types=1);

namespace HTD\AISEO\AI\Providers;

use HTD\AISEO\AI\AIError;
use HTD\AISEO\AI\AIException;
use HTD\AISEO\AI\AIRequest;
use HTD\AISEO\AI\AIResponse;
use HTD\AISEO\AI\AbstractProviderAdapter;
use HTD\AISEO\AI\Capability;
use HTD\AISEO\AI\UsageMetadata;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Official xAI Grok Chat Completions API adapter.
 */
final class GrokProvider extends AbstractProviderAdapter {
	public function getProviderId(): string {
		return 'grok';
	}

	public function supportedCapabilities(): array {
		return [
			Capability::TEXT_GENERATION,
			Capability::JSON_OUTPUT,
			Capability::TOKEN_USAGE,
		];
	}

	public function buildHttpPayload(AIRequest $request, string $apiKey): array {
		$payload = [
			'model'      => $request->model,
			'messages'   => $request->messages,
			'max_tokens' => $request->maxOutputTokens,
		];
		if ($request->temperature !== null) {
			$payload['temperature'] = $request->temperature;
		}
		return [
			'url'     => 'https://api.x.ai/v1/chat/completions',
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
			throw new AIException(AIError::INVALID_RESPONSE, 'Malformed Grok response JSON.', $status);
		}
		$text = (string) ($data['choices'][0]['message']['content'] ?? '');
		$inTok = (int) ($data['usage']['prompt_tokens'] ?? 0);
		$outTok = (int) ($data['usage']['completion_tokens'] ?? 0);
		$totTok = (int) ($data['usage']['total_tokens'] ?? ($inTok + $outTok));
		$cost = $this->models->estimateCostUsd('grok', $request->model, $inTok, $outTok);

		return new AIResponse(
			providerId: 'grok',
			model: $request->model,
			rawText: $text,
			parsedJson: $this->decodeJsonText($text),
			usage: new UsageMetadata($inTok, $outTok, $totTok, 'PROVIDER_REPORTED_TOKENS', $cost, 'ESTIMATED_COST'),
			finishReason: isset($data['choices'][0]['finish_reason']) ? (string) $data['choices'][0]['finish_reason'] : null
		);
	}
}
