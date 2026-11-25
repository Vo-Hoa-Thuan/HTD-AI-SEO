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
 * Official Anthropic Claude Messages API adapter.
 */
final class ClaudeProvider extends AbstractProviderAdapter {
	public function getProviderId(): string {
		return 'claude';
	}

	public function supportedCapabilities(): array {
		return [
			Capability::TEXT_GENERATION,
			Capability::JSON_OUTPUT,
			Capability::VISION_INPUT,
			Capability::TOKEN_USAGE,
		];
	}

	public function buildHttpPayload(AIRequest $request, string $apiKey): array {
		$system = [];
		$messages = [];
		foreach ($request->messages as $msg) {
			if ($msg['role'] === 'system') {
				$system[] = $msg['content'];
			} else {
				$messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
			}
		}
		$payload = [
			'model'      => $request->model,
			'max_tokens' => $request->maxOutputTokens,
			'messages'   => $messages,
		];
		if ($system !== []) {
			$payload['system'] = implode("\n\n", $system);
		}
		if ($request->temperature !== null) {
			$payload['temperature'] = $request->temperature;
		}
		return [
			'url'     => 'https://api.anthropic.com/v1/messages',
			'method'  => 'POST',
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $apiKey,
				'anthropic-version' => '2023-06-01',
			],
			'body'    => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public function parseHttpResponse(int $status, string $body, AIRequest $request): AIResponse {
		if ($status < 200 || $status >= 300) {
			throw $this->mapHttpError($status, $body);
		}
		$data = json_decode($body, true);
		if (!is_array($data) || empty($data['content'][0]['text'])) {
			throw new AIException(AIError::INVALID_RESPONSE, 'Malformed Claude response JSON.', $status);
		}
		$text = (string) $data['content'][0]['text'];
		$inTok = (int) ($data['usage']['input_tokens'] ?? 0);
		$outTok = (int) ($data['usage']['output_tokens'] ?? 0);
		$cost = $this->models->estimateCostUsd('claude', $request->model, $inTok, $outTok);

		return new AIResponse(
			providerId: 'claude',
			model: $request->model,
			rawText: $text,
			parsedJson: $this->decodeJsonText($text),
			usage: new UsageMetadata($inTok, $outTok, $inTok + $outTok, 'PROVIDER_REPORTED_TOKENS', $cost, 'ESTIMATED_COST'),
			finishReason: isset($data['stop_reason']) ? (string) $data['stop_reason'] : null
		);
	}
}
